<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check billing permissions
if (!checkPermission('bills', 'create')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}
function recordSplitPayment($conn, $collection_id, $mode, $amount, $reference = null)
{
    if ($amount <= 0) {
        return; // Skip if amount is zero or negative
    }

    $payment_details = null;

    // Prepare payment details based on mode
    if ('CARD' === $mode) {
        $payment_details = json_encode([
            'mode' => 'CARD',
            'amount' => $amount,
            'reference_no' => $reference,
            'fee_percentage' => isset($_POST['split_card_type']) && 'credit' === $_POST['split_card_type'] ? 3.5 : 1.5,
            'fee_amount' => $amount * (isset($_POST['split_card_type']) && 'credit' === $_POST['split_card_type'] ? 0.035 : 0.015),
        ]);
    } else {
        $payment_details = json_encode([
            'mode' => $mode,
            'amount' => $amount,
            'reference_no' => $reference,
        ]);
    }

    $stmt = $conn->prepare('INSERT INTO split_payments 
                           (collection_id, payment_type_id, amount, payment_details, reference_no)
                           VALUES (?, 
                                  (SELECT payment_type_id FROM payment_types WHERE type_name = ?),
                                  ?, ?, ?)');

    if (!$stmt) {
        throw new Exception('Failed to prepare split payment statement: '.$conn->error);
    }

    $stmt->bind_param(
        'isdss',
        $collection_id,
        $mode,
        $amount,
        $payment_details,
        $reference
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to record split payment: '.$stmt->error);
    }

    return $stmt->insert_id;
}


$conn = getDbConnection();
$bill_id = sanitizeInput($_GET['id'] ?? $_POST['bill_id'] ?? '');

// Handle POST request for payment processing
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        if (!$bill_id || !isset($_POST['payment_mode'])) {
            throw new Exception('Invalid request parameters');
        }

        $payment_mode = sanitizeInput($_POST['payment_mode']);
        $conn->begin_transaction();

        // Get current bill and payment status
        $stmt = $conn->prepare('SELECT b.*, pl.amount_paid, pl.ledger_id 
                               FROM bills b 
                               LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id 
                               WHERE b.bill_id = ?');
        $stmt->bind_param('s', $bill_id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();

        if (!$bill) {
            throw new Exception('Bill not found');
        }

        $balance = $bill['final_amount'] - ($bill['amount_paid'] ?? 0);
        $transaction_id = generateTransactionId('pmt');
        $collected_by = $_SESSION['user_id'];

        // Process payment based on mode
        switch ($payment_mode) {
            case 'CASH':
                $amount = floatval($_POST['cash_amount']);
                $payment_details = json_encode([
                    'mode' => 'CASH',
                    'amount' => $amount,
                ]);
                break;

            case 'CARD':
                $amount = floatval($_POST['card_amount']);
                $card_type = $_POST['card_type'];
                $fee_percentage = ('credit' === $card_type) ? 0.035 : 0.015;
                $fee = $amount * $fee_percentage;

                if (empty($_POST['card_reference'])) {
                    throw new Exception('Card reference number is required');
                }

                $payment_details = json_encode([
                    'mode' => 'CARD',
                    'card_type' => $card_type,
                    'reference_no' => $_POST['card_reference'],
                    'original_amount' => $amount,
                    'fee_percentage' => ('credit' === $card_type) ? 3.5 : 1.5,
                    'fee_amount' => $fee,
                    'final_amount' => $amount + $fee,
                    'received_amount' => floatval($_POST['received_amount']),
                ]);
                break;

            case 'UPI':
                $amount = floatval($_POST['upi_amount']);
                $payment_details = json_encode([
                    'mode' => 'UPI',
                    'reference_no' => $_POST['upi_reference'],
                    'amount' => $amount,
                ]);
                break;

            case 'SPLIT':
                $cash_amount = floatval($_POST['split_cash']);
                $card_amount = floatval($_POST['split_card']);
                $upi_amount = floatval($_POST['split_upi']);

                // Calculate card fee if card payment exists
                $card_fee = 0;
                if ($card_amount > 0) {
                    $card_type = $_POST['split_card_type'] ?? 'debit';
                    $fee_percentage = ('credit' === $card_type) ? 0.035 : 0.015;
                    $card_fee = $card_amount * $fee_percentage;
                }

                $amount = $cash_amount + $card_amount + $upi_amount;
                $payment_details = json_encode([
                    'mode' => 'SPLIT',
                    'payments' => [
                        'cash' => [
                            'amount' => $cash_amount,
                        ],
                        'card' => [
                            'amount' => $card_amount,
                            'type' => $card_type ?? 'debit',
                            'reference_no' => $_POST['split_card_reference'],
                            'fee_percentage' => ('credit' === ($card_type ?? 'debit')) ? 3.5 : 1.5,
                            'fee_amount' => $card_fee,
                        ],
                        'upi' => [
                            'amount' => $upi_amount,
                            'reference_no' => $_POST['split_upi_reference'],
                        ],
                    ],
                    'total_amount' => $amount,
                    'total_with_fees' => $amount + $card_fee,
                ]);
                break;

            default:
                throw new Exception('Invalid payment mode');
        }

        if ($amount <= 0 || $amount > $balance) {
            throw new Exception('Invalid payment amount');
        }

        // Update payment ledger
        if ($bill['ledger_id']) {
            $stmt = $conn->prepare('UPDATE payment_ledger 
                                   SET amount_paid = amount_paid + ?,
                                       payment_status = CASE 
                                           WHEN amount_paid + ? >= amount_due THEN "PAID"
                                           ELSE "PARTIAL"
                                       END
                                   WHERE ledger_id = ?');
            $stmt->bind_param('ddi', $amount, $amount, $bill['ledger_id']);
        } else {
            $stmt = $conn->prepare('INSERT INTO payment_ledger 
                                   (bill_id, transaction_id, amount_due, amount_paid, payment_status, 
                                    payment_type_id, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)');
            $status = ($amount >= $bill['final_amount']) ? 'PAID' : 'PARTIAL';
            $stmt->bind_param(
                'ssddsii',
                $bill_id,
                $transaction_id,
                $bill['final_amount'],
                $amount,
                $status,
                $bill['payment_type_id'],
                $collected_by
            );
        }
        $stmt->execute();

        // Record payment collection
        $stmt = $conn->prepare('INSERT INTO payment_collections 
                               (ledger_id, bill_id, amount, payment_type_id, payment_date,
                                payment_details, reference_no, collected_by, notes)
                               VALUES (?, ?, ?, 
                                   (SELECT payment_type_id FROM payment_types WHERE type_name = ?),
                                   NOW(), ?, ?, ?, ?)');

        $stmt->bind_param(
            'isdsssss',
            $bill['ledger_id'],
            $bill_id,
            $amount,
            $payment_mode,
            $payment_details,
            $reference_no,
            $collected_by,
            $_POST['notes']
        );
        $stmt->execute();

        // Handle split payments if applicable
        if ('SPLIT' === $payment_mode) {
            $collection_id = $conn->insert_id;

            if ($cash_amount > 0) {
                recordSplitPayment($conn, $collection_id, 'CASH', $cash_amount);
            }
            if ($card_amount > 0) {
                recordSplitPayment($conn, $collection_id, 'CARD', $card_amount, $_POST['split_card_reference']);
            }
            if ($upi_amount > 0) {
                recordSplitPayment($conn, $collection_id, 'UPI', $upi_amount, $_POST['split_upi_reference']);
            }
        }

        // Update bill status
        $stmt = $conn->prepare('UPDATE bills 
                               SET payment_status = CASE 
                                   WHEN ? >= final_amount THEN "PAID"
                                   ELSE "PARTIAL"
                               END
                               WHERE bill_id = ?');
        $total_paid = ($bill['amount_paid'] ?? 0) + $amount;
        $stmt->bind_param('ds', $total_paid, $bill_id);
        $stmt->execute();

        $conn->commit();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'bill_id' => $bill_id]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Get bill details for display
$stmt = $conn->prepare('
    SELECT b.*, p.name as patient_name, p.contact as patient_contact,
           pl.amount_paid, pl.payment_due_date, pl.payment_status,
           ca.company_name, ca.credit_limit, ca.current_balance
    FROM bills b
    JOIN patients p ON b.patient_id = p.patient_id
    LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id
    LEFT JOIN company_accounts ca ON (pl.company_id = ca.company_id AND b.payment_type_id = 2)
    WHERE b.bill_id = ?');

$stmt->bind_param('s', $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

if (!$bill || 'PAID' === $bill['payment_status']) {
    exit('Invalid bill or payment already completed');
}

$balance = $bill['final_amount'] - ($bill['amount_paid'] ?? 0);
?>
<!DOCTYPE html>
<html>

<head>
	<title>Collect Payment</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
	<style>
		.payment-mode-fields {
			display: none;
		}

		.payment-mode-fields.active {
			display: block;
		}

		.card {
			margin-bottom: 1rem;
		}

		.form-group {
			margin-bottom: 1rem;
		}

		.payment-summary {
			background-color: #f8f9fa;
			padding: 1rem;
			border-radius: 0.25rem;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container mt-3">
		<div class="row">
			<div class="col-md-8">
				<div class="card">
					<div class="card-header">
						<h5 class="card-title mb-0">Payment Collection</h5>
					</div>
					<div class="card-body">
						<!-- Bill Details -->
						<div class="row mb-3">
							<div class="col-md-6">
								<p><strong>Bill No:</strong>
									<?php echo $bill_id; ?>
								</p>
								<p><strong>Patient:</strong>
									<?php echo htmlspecialchars($bill['patient_name']); ?>
								</p>
								<p><strong>Contact:</strong>
									<?php echo htmlspecialchars($bill['patient_contact']); ?>
								</p>
							</div>
							<div class="col-md-6">
								<p><strong>Bill Amount:</strong>
									₹<?php echo number_format($bill['final_amount'], 2); ?>
								</p>
								<p><strong>Paid Amount:</strong>
									₹<?php echo number_format($bill['amount_paid'] ?? 0, 2); ?>
								</p>
								<p><strong>Balance:</strong>
									₹<?php echo number_format($balance, 2); ?>
								</p>
							</div>
						</div>

						<!-- Payment Form -->
						<form id="paymentForm" class="needs-validation" novalidate>
							<input type="hidden" name="bill_id"
								value="<?php echo $bill_id; ?>">

							<div class="form-group">
								<label>Payment Mode</label>
								<div class="btn-group w-100" role="group">
									<input type="radio" class="btn-check" name="payment_mode" id="cash" value="CASH"
										checked>
									<label class="btn btn-outline-primary" for="cash">Cash</label>

									<input type="radio" class="btn-check" name="payment_mode" id="card" value="CARD">
									<label class="btn btn-outline-primary" for="card">Card</label>

									<input type="radio" class="btn-check" name="payment_mode" id="upi" value="UPI">
									<label class="btn btn-outline-primary" for="upi">UPI</label>

									<input type="radio" class="btn-check" name="payment_mode" id="split" value="SPLIT">
									<label class="btn btn-outline-primary" for="split">Split</label>
								</div>
							</div>

							<!-- Cash Payment Fields -->
							<div id="cashFields" class="payment-mode-fields active">
								<div class="form-group">
									<label>Amount Received</label>
									<input type="number" class="form-control amount-input" name="cash_amount"
										step="0.01"
										max="<?php echo $balance; ?>"
										required>
								</div>
							</div>

							<!-- Card Payment Fields -->
							<div id="cardFields" class="payment-mode-fields">
								<div class="form-group">
									<label>Card Type</label>
									<select class="form-select" name="card_type" onchange="calculateCardFee()">
										<option value="debit">Debit Card (1.5% fee)</option>
										<option value="credit">Credit Card (3.5% fee)</option>
									</select>
								</div>
								<div class="form-group">
									<label>Amount</label>
									<input type="number" class="form-control amount-input" name="card_amount"
										step="0.01"
										max="<?php echo $balance; ?>"
										onchange="calculateCardFee()" required>
									<div class="card-fee-info mt-1">
										<span>Convenience Fee: ₹<span id="card_fee">0.00</span></span><br>
										<span>Final Amount: ₹<span id="final_amount">0.00</span></span>
									</div>
								</div>
								<div class="form-group">
									<label>Reference Number</label>
									<input type="text" class="form-control" name="card_reference" pattern="[A-Za-z0-9]+"
										required>
								</div>
								<div class="verification-section">
									<label>Amount Received</label>
									<input type="number" class="form-control" name="received_amount" step="0.01"
										onchange="verifyAmount()" required>
									<div id="amount_verification_status"></div>
								</div>
							</div>

							<!-- UPI Payment Fields -->
							<div id="upiFields" class="payment-mode-fields">
								<div class="form-group">
									<label>UPI Transaction ID</label>
									<input type="text" class="form-control" name="upi_reference" required>
								</div>
								<div class="form-group">
									<label>Amount</label>
									<input type="number" class="form-control amount-input" name="upi_amount" step="0.01"
										max="<?php echo $balance; ?>"
										required>
								</div>
							</div>

							<!-- Split Payment Fields -->
							<div id="splitFields" class="payment-mode-fields">
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label>Cash Amount</label>
											<input type="number" class="form-control split-amount" name="split_cash"
												value="0" step="0.01">
										</div>
									</div>
									<div class="col-md-6">
										<div class="form-group">
											<label>Card Amount</label>
											<input type="number" class="form-control split-amount" name="split_card"
												value="0" step="0.01">
											<div class="form-text">Convenience fee applies based on card type</div>
										</div>
										<div class="form-group">
											<label>Card Type</label>
											<select class="form-select" name="split_card_type"
												onchange="updateSplitTotal()">
												<option value="credit">Credit Card (3.5% fee)</option>
												<option value="debit">Debit Card (1.5% fee)</option>
											</select>
										</div>
										<div class="form-group">
											<label>Card Reference</label>
											<input type="text" class="form-control" name="split_card_reference">
										</div>
									</div>
								</div>
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label>UPI Amount</label>
											<input type="number" class="form-control split-amount" name="split_upi"
												value="0" step="0.01">
										</div>
										<div class="form-group">
											<label>UPI Reference</label>
											<input type="text" class="form-control" name="split_upi_reference">
										</div>
									</div>
								</div>
							</div>

							<div class="form-group">
								<label>Notes</label>
								<textarea class="form-control" name="notes" rows="2"></textarea>
							</div>

							<div class="text-end mt-3">
								<button type="button" class="btn btn-secondary" onclick="history.back()">Cancel</button>
								<button type="submit" class="btn btn-primary">Collect Payment</button>
							</div>
						</form>
					</div>
				</div>
			</div>

			<div class="col-md-4">
				<div class="card payment-summary">
					<div class="card-body">
						<h5 class="card-title">Payment Summary</h5>
						<div id="paymentSummary">
							<p><strong>Amount to Pay:</strong> ₹<span
									id="amountToPay"><?php echo number_format($balance, 2); ?></span>
							</p>
							<p><strong>Convenience Fee:</strong> ₹<span id="convenienceFee">0.00</span></p>
							<p><strong>Total Amount:</strong> ₹<span
									id="totalAmount"><?php echo number_format($balance, 2); ?></span>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		document.querySelectorAll('input[name="payment_mode"]').forEach(radio => {
			radio.addEventListener('change', function() {
				document.querySelectorAll('.payment-mode-fields').forEach(div => {
					div.classList.remove('active');
				});
				document.getElementById(this.value.toLowerCase() + 'Fields').classList.add('active');
				updateSummary();
			});
		});

		document.querySelectorAll('.amount-input, .split-amount').forEach(input => {
			input.addEventListener('input', updateSummary);
		});

		function calculateCardFee() {
			const amount = parseFloat(document.querySelector('[name="card_amount"]').value) || 0;
			const cardType = document.querySelector('[name="card_type"]').value;
			const feePercentage = cardType === 'credit' ? 0.035 : 0.015;
			const fee = amount * feePercentage;
			const finalAmount = amount + fee;

			document.getElementById('card_fee').textContent = fee.toFixed(2);
			document.getElementById('final_amount').textContent = finalAmount.toFixed(2);

			// Update payment summary
			document.getElementById('amountToPay').textContent = amount.toFixed(2);
			document.getElementById('convenienceFee').textContent = fee.toFixed(2);
			document.getElementById('totalAmount').textContent = finalAmount.toFixed(2);
		}

		function verifyAmount() {
			const finalAmount = parseFloat(document.getElementById('final_amount').textContent);
			const receivedAmount = parseFloat(document.querySelector('[name="received_amount"]').value) || 0;
			const status = document.getElementById('amount_verification_status');

			const difference = Math.abs(receivedAmount - finalAmount);
			if (difference < 0.01) {
				status.textContent = '✓ Amount matches';
				status.className = 'text-success';
			} else {
				status.textContent =
					`⚠ Amount ${receivedAmount > finalAmount ? 'excess' : 'short'} by ₹${difference.toFixed(2)}`;
				status.className = 'text-danger';
			}
		}

		function updateSplitTotal() {
			const cash = parseFloat(document.querySelector('[name="split_cash"]').value) || 0;
			const card = parseFloat(document.querySelector('[name="split_card"]').value) || 0;
			const upi = parseFloat(document.querySelector('[name="split_upi"]').value) || 0;
			const cardType = document.querySelector('[name="split_card_type"]').value;

			const cardFeePercentage = cardType === 'credit' ? 0.035 : 0.015;
			const cardFee = card * cardFeePercentage;

			const subtotal = cash + card + upi;
			const totalWithFees = subtotal + cardFee;

			// Update display
			document.getElementById('amountToPay').textContent = subtotal.toFixed(2);
			document.getElementById('convenienceFee').textContent = cardFee.toFixed(2);
			document.getElementById('totalAmount').textContent = totalWithFees.toFixed(2);

			// Enable/disable reference fields based on amounts
			document.querySelector('[name="split_card_reference"]').required = card > 0;
			document.querySelector('[name="split_upi_reference"]').required = upi > 0;
		}

		function updateSummary() {
			const mode = document.querySelector('input[name="payment_mode"]:checked').value;
			let amount = 0;
			let fee = 0;

			switch (mode) {
				case 'SPLIT':
					const cashAmount = parseFloat(document.querySelector('[name="split_cash"]').value) || 0;
					const cardAmount = parseFloat(document.querySelector('[name="split_card"]').value) || 0;
					const upiAmount = parseFloat(document.querySelector('[name="split_upi"]').value) || 0;
					const cardType = document.querySelector('[name="split_card_type"]')?.value;

					amount = cashAmount + cardAmount + upiAmount;
					if (cardAmount > 0) {
						fee = cardAmount * (cardType === 'credit' ? 0.035 : 0.015);
					}
					break;

				case 'CARD':
					amount = parseFloat(document.querySelector('[name="card_amount"]').value) || 0;
					const type = document.querySelector('[name="card_type"]').value;
					fee = amount * (type === 'credit' ? 0.035 : 0.015);
					break;

				default:
					const inputName = `${mode.toLowerCase()}_amount`;
					amount = parseFloat(document.querySelector(`[name="${inputName}"]`)?.value) || 0;
					break;
			}

			// Update summary display
			document.getElementById('amountToPay').textContent = amount.toFixed(2);
			document.getElementById('convenienceFee').textContent = fee.toFixed(2);
			document.getElementById('totalAmount').textContent = (amount + fee).toFixed(2);
		}

		// Update form validation
		document.getElementById('paymentForm').addEventListener('submit', function(e) {
			e.preventDefault();
			const formData = new FormData(this);
			const mode = formData.get('payment_mode');

			try {
				switch (mode) {
					case 'CARD':
						const cardAmount = parseFloat(formData.get('card_amount')) || 0;
						const receivedAmount = parseFloat(formData.get('received_amount')) || 0;
						const finalAmount = parseFloat(document.getElementById('final_amount').textContent);

						if (Math.abs(receivedAmount - finalAmount) >= 0.01) {
							throw new Error('Received amount does not match final amount');
						}
						break;

					case 'SPLIT':
						const cash = parseFloat(formData.get('split_cash')) || 0;
						const card = parseFloat(formData.get('split_card')) || 0;
						const upi = parseFloat(formData.get('split_upi')) || 0;

						if ((cash + card + upi) <= 0) {
							throw new Error('Total payment amount must be greater than 0');
						}

						if (card > 0 && !formData.get('split_card_reference')) {
							throw new Error('Card reference is required for card payment');
						}
						if (upi > 0 && !formData.get('split_upi_reference')) {
							throw new Error('UPI reference is required for UPI payment');
						}
						break;
				}

				// If validation passes, submit the form
				submitPayment(formData);

			} catch (error) {
				alert(error.message);
				return;
			}
		});

		function submitPayment(formData) {
			fetch('', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						alert('Payment collected successfully');
						window.location.href = 'view_bill.php?id=' + data.bill_id;
					} else {
						alert('Error: ' + data.message);
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('An error occurred while processing the payment');
				});
		}
	</script>
</body>

</html>