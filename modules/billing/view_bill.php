<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check billing permissions
if (!checkPermission('bills', 'view')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

// Validate bill ID parameter - accept both 'id' and 'bill_id' for compatibility
$bill_id_param = $_GET['bill_id'] ?? $_GET['id'] ?? null;
if (empty($bill_id_param)) {
    exit('Bill ID is required');
}

$conn = getDbConnection();
$bill_id = sanitizeInput($bill_id_param);
$bill_type = sanitizeInput($_GET['type'] ?? 'hospital');

// Get bill details with patient and doctor info
$stmt = $conn->prepare('
    SELECT 
        b.*,
        p.name as patient_name,
        p.contact as patient_contact,
        p.address as patient_address,
        p.city as patient_city,
        d.name as doctor_name,
        d.degree as doctor_degree,
        d.registration_no as doctor_reg,
        dept.name as dept_name,
        pt.type_name as payment_type,
        pl.transaction_id,
        pl.amount_paid,
        pl.payment_due_date,
        pl.payment_status as ledger_status,
        ca.company_name,
        ca.credit_limit,
        ca.current_balance,
        ca.payment_days
    FROM bills b
    JOIN patients p ON b.patient_id = p.patient_id
    JOIN doctors d ON b.doctor_id = d.doctor_id
    JOIN departments dept ON b.dept_id = dept.dept_id
    JOIN payment_types pt ON b.payment_type_id = pt.payment_type_id
    LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id
    LEFT JOIN company_accounts ca ON (pl.company_id = ca.company_id AND b.payment_type_id = 2)
    WHERE b.bill_id = ?');

$stmt->bind_param('s', $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

if (!$bill) {
    exit('Bill not found');
}

// Get bill items
$stmt = $conn->prepare('
    SELECT bi.*, s.name as service_name, s.category
    FROM bill_items bi
    JOIN services s ON bi.service_id = s.service_id
    WHERE bi.bill_id = ?
    ORDER BY s.category, s.name');

$stmt->bind_param('s', $bill_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get payment collections with additional payment ledger details
$stmt = $conn->prepare('
    SELECT 
        pc.*,
        pt.type_name as payment_mode,
        u.username as collected_by_name,
        pl.amount_due,
        pl.amount_paid as total_paid,
        pl.payment_status as ledger_status,
        JSON_UNQUOTE(pc.payment_details) as decoded_details
    FROM payment_collections pc
    JOIN payment_types pt ON pc.payment_type_id = pt.payment_type_id
    LEFT JOIN users u ON pc.collected_by = u.user_id
    LEFT JOIN payment_ledger pl ON pc.ledger_id = pl.ledger_id
    WHERE pc.bill_id = ?
    ORDER BY pc.payment_date ASC');

$stmt->bind_param('s', $bill_id);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate total paid and pending amounts
$total_paid = 0;
$total_with_fees = 0;
$pending_amount = $bill['final_amount'];

// Get the latest ledger status
$stmt = $conn->prepare('
    SELECT amount_due, amount_paid, payment_status 
    FROM payment_ledger 
    WHERE bill_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1');
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$ledger = $stmt->get_result()->fetch_assoc();

if ($ledger) {
    $total_paid = $ledger['amount_paid'];
    $pending_amount = $ledger['amount_due'] - $ledger['amount_paid'];
}

// Debug payment results
error_log("Payments for bill {$bill_id}: ".print_r($payments, true));

// For company credit payments, check bill's payment_type
if (2 == $bill['payment_type_id']) { // If it's company credit
    // Create a payment record if none exists
    if (empty($payments)) {
        $payments[] = [
            'payment_date' => $bill['bill_date'],
            'payment_mode' => 'COMPANY_CREDIT',
            'amount' => $bill['final_amount'],
            'decoded_details' => json_encode([
                'mode' => 'COMPANY_CREDIT',
                'company_name' => $bill['company_name'],
                'credit_limit' => $bill['credit_limit'],
                'current_balance' => $bill['current_balance'],
                'payment_due_date' => $bill['payment_due_date'],
            ]),
            'reference_no' => '',
            'collected_by_name' => '',
        ];
    }
}

// For split payments
$split_payments = [];
if (8 == $bill['payment_type_id']) { // SPLIT payment
    $stmt = $conn->prepare('
        SELECT sp.*, pt.type_name as payment_mode
        FROM split_payments sp
        JOIN payment_collections pc ON sp.collection_id = pc.collection_id
        JOIN payment_types pt ON sp.payment_type_id = pt.payment_type_id
        WHERE pc.bill_id = ?');

    $stmt->bind_param('s', $bill_id);
    $stmt->execute();
    $split_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>View Bill</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/bill.css">
	<style>
		.container {
			padding: 0.5rem;
		}

		.bill-actions {
			margin-bottom: 0.5rem;
		}

		.card-body {
			padding: 0.75rem;
		}

		.receipt-title {
			font-size: 1.25rem;
			margin-bottom: 0.5rem;
		}

		.table {
			margin-bottom: 0.5rem;
			font-size: 0.875rem;
		}

		.table>:not(caption)>*>* {
			padding: 0.25rem;
		}

		h3,
		h4 {
			font-size: 1rem;
			margin-bottom: 0.5rem;
		}

		.mb-3 {
			margin-bottom: 0.5rem !important;
		}

		.payment-record {
			margin-bottom: 0.5rem;
		}

		.payment-record .card-header {
			padding: 0.25rem 0.75rem;
		}

		.payment-record .card-body {
			padding: 0.5rem;
		}

		.mt-4 {
			margin-top: 0.75rem !important;
		}

		.bill-footer {
			margin-top: 0.5rem;
			font-size: 0.875rem;
		}

		.text-muted.small {
			font-size: 0.75rem;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="bill-actions">
			<button class="btn btn-sm btn-outline-secondary" onclick="location.href='bills_list.php'">
				<i class="bi bi-arrow-left"></i> Back
			</button>
			<?php if (canPrintBill()) { ?>
			<?php if ('CANCELLED' !== $bill['payment_status']) { ?>
			<button class="btn btn-sm btn-primary"
				onclick="printBill('<?php echo htmlspecialchars($bill_id); ?>', '<?php echo htmlspecialchars($bill_type); ?>')">
				<i class="bi bi-printer"></i> Print</button>
			<?php } else { ?>
			<button class="btn btn-sm btn-secondary" disabled style="opacity: 0.5; cursor: not-allowed;">
				<i class="bi bi-printer"></i> Print</button>
			<?php } ?>
			<?php } ?>
			<?php if (checkPermission('bills', 'edit') && 'CANCELLED' !== $bill['payment_status']) { ?>
			<button class="btn btn-sm btn-secondary"
				onclick="location.href='edit_bill.php?id=<?php echo $bill_id; ?>'">Edit</button>
			<button class="btn btn-sm btn-danger"
				onclick="cancelBill('<?php echo $bill_id; ?>')">Cancel</button>
			<?php } ?>
			<?php if (in_array($bill['payment_status'], ['PENDING', 'PARTIAL'])) { ?>
			<button class="btn btn-sm btn-primary"
				onclick="location.href='collect_payment.php?id=<?php echo $bill_id; ?>'">Collect</button>
			<?php } ?>
		</div>

		<div class="card">
			<div class="card-body">
				<?php
                // Determine receipt title based on payment type
                $receiptTitle = 'Bill Details';
if ('CASH' === $bill['payment_type'] && 'PAID' === $bill['payment_status']) {
    $receiptTitle = 'Cash Receipt Details';
}
?>
				<div class="receipt-title">
					<?php echo $receiptTitle; ?>
				</div>

				<div class="row g-2 mb-3">
					<!-- Left Column - Bill Details -->
					<div class="col-4">
						<div><strong>Bill No:</strong>
							<?php echo htmlspecialchars($bill['bill_id']); ?>
						</div>
						<div><strong>Date:</strong>
							<?php echo date('d-m-Y', strtotime($bill['bill_date'])); ?>
						</div>
					</div>

					<!-- Center Column - Patient Details -->
					<div class="col-4 text-left">
						<div><strong>Patient Name:</strong>
							<?php echo htmlspecialchars($bill['patient_name']); ?>
						</div>
						<div><strong>Contact:</strong>
							<?php echo htmlspecialchars($bill['patient_contact']); ?>
						</div>
						<div><strong>Address:</strong>
							<?php echo htmlspecialchars($bill['patient_address'].', '.$bill['patient_city']); ?>
						</div>
					</div>

					<!-- Right Column - Doctor Details -->
					<div class="col-4 text-start">
						<div><strong>Doctor:</strong> Dr.
							<?php echo htmlspecialchars($bill['doctor_name']); ?>
						</div>
						<div><strong>Reg. No:</strong>
							<?php echo htmlspecialchars($bill['doctor_reg']); ?>
						</div>
						<div><strong>Department:</strong>
							<?php echo htmlspecialchars($bill['dept_name']); ?>
						</div>
					</div>
				</div>

				<div class="services-list">
					<h3>Services</h3>
					<table class="table">
						<thead>
							<tr>
								<th>Service</th>
								<th>Quantity</th>
								<th>Price</th>
								<th>Total</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($items as $item) { ?>
							<tr>
								<td><?php echo htmlspecialchars($item['service_name']); ?>
								</td>
								<td><?php echo $item['quantity']; ?>
								</td>
								<td>₹<?php echo number_format($item['unit_price'], 2); ?>
								</td>
								<td>₹<?php echo number_format($item['total'], 2); ?>
								</td>
							</tr>
							<?php } ?>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="3">Subtotal</td>
								<td>₹<?php echo number_format($bill['total_amount'], 2); ?>
								</td>
							</tr>
							<?php
            $total_after_discount = $bill['total_amount'];
if ($bill['discount'] > 0) {
    $total_after_discount = $bill['total_amount'] - $bill['discount'];
    ?>
							<tr>
								<td colspan="3">Discount</td>
								<td>₹<?php echo number_format($bill['discount'], 2); ?>
								</td>
							</tr>
							<?php }

// Look for card payments and fees
$fee_amount = 0;
$fee_percentage = 0;

foreach ($payments as $payment) {
    $details = json_decode($payment['decoded_details'], true);

    if ('CARD' === $payment['payment_mode']) {
        $fee_amount = $details['fee_amount'] ?? 0;
        $fee_percentage = $details['fee_percentage'] ?? 0;
        break;
    }
    if ('SPLIT' === ($details['mode'] ?? '')
            && isset($details['payments']['card'])) {
        $card_payment = $details['payments']['card'];
        if ($card_payment['amount'] > 0) {
            $fee_amount = $card_payment['fee_amount'] ?? 0;
            $fee_percentage = $card_payment['fee_percentage'] ?? 0;
            break;
        }
    }
}

// Display card processing fee if exists
$final_total = $total_after_discount;
if ($fee_amount > 0) {
    $final_total += $fee_amount;
    ?>
							<tr>
								<td colspan="3">Card Processing Fee
									(<?php echo $fee_percentage; ?>%)
								</td>
								<td>₹<?php echo number_format($fee_amount, 2); ?>
								</td>
							</tr>
							<?php } ?>

							<tr class="total">
								<td colspan="3">Final Amount</td>
								<td>₹<?php echo number_format($final_total, 2); ?>
								</td>
							</tr>
						</tfoot>
					</table>
				</div>

				<?php if (!empty($payments)) { ?>
				<div class="mt-4">
					<h4>Payment Collections</h4>
					<?php
                        echo '<!-- Found '.count($payments).' payments -->'; // Debug comment
				    foreach ($payments as $payment) {
				        $details = json_decode($payment['decoded_details'], true);

				        // Determine payment status badge class
				        $statusBadgeClass = '';
				        switch ($bill['payment_status']) {
				            case 'PAID':
				                $statusBadgeClass = 'bg-success';
				                break;
				            case 'PARTIAL':
				                $statusBadgeClass = 'bg-warning';
				                break;
				            case 'PENDING':
				                $statusBadgeClass = 'bg-danger';
				                break;
				            default:
				                $statusBadgeClass = 'bg-secondary';
				        }
				        ?>
					<div class="payment-record card mb-3">
						<div class="card-header d-flex justify-content-between align-items-center">
							<span>Payment Collection</span>
							<span
								class="badge <?php echo $statusBadgeClass; ?>"><?php echo $bill['payment_status']; ?></span>
						</div>
						<div class="card-body">
							<div class="row">
								<div class="col-md-3">
									<strong>Date:</strong>
									<?php echo date('d-m-Y H:i', strtotime($payment['payment_date'])); ?><br>
									<strong>Mode:</strong>
									<?php echo htmlspecialchars($payment['payment_mode']); ?><br>
									<strong>Amount:</strong>
									₹<?php echo number_format($payment['amount'], 2); ?>
									<?php if (!empty($payment['notes'])) { ?>
									<br><strong>Notes:</strong>
									<?php echo htmlspecialchars($payment['notes']); ?>
									<?php } ?>
								</div>
								<div class="col-md-6">
									<?php
				                        if ('CARD' === $payment['payment_mode']) {
				                            echo '<div class="text-muted small">Card Payment</div>';
				                            echo '<strong>Card Type:</strong> '.ucfirst($details['card_type']).'<br>';
				                            echo '<strong>Amount:</strong> ₹'.number_format($details['original_amount'], 2).'<br>';
				                            echo '<strong>Fee ('.$details['fee_percentage'].'%):</strong> ₹'.number_format($details['fee_amount'], 2).'<br>';
				                            echo '<strong>Final:</strong> ₹'.number_format($details['final_amount'], 2).'<br>';
				                            if (!empty($details['reference_no'])) {
				                                echo '<strong>Ref#:</strong> '.htmlspecialchars($details['reference_no']);
				                            }
				                            $total_with_fees += $details['final_amount'];
				                        } elseif ('UPI' === $payment['payment_mode']) {
				                            echo '<div class="text-muted small">UPI Payment</div>';
				                            echo '<strong>Trans ID:</strong> '.htmlspecialchars($details['reference_no'] ?? '');
				                            $total_with_fees += $payment['amount'];
				                        } elseif ('SPLIT' === $payment['payment_mode']) {
				                            echo '<div class="text-muted small">Split Payment</div>';
				                            foreach ($details['payments'] as $type => $pmt) {
				                                if ($pmt['amount'] > 0) {
				                                    echo '<strong>'.ucfirst($type).':</strong> ₹'.number_format($pmt['amount'], 2);
				                                    if (isset($pmt['reference_no'])) {
				                                        echo ' (Ref: '.htmlspecialchars($pmt['reference_no']).')';
				                                    }
				                                    if (isset($pmt['fee_amount']) && $pmt['fee_amount'] > 0) {
				                                        echo ' + ₹'.number_format($pmt['fee_amount'], 2).' fee';
				                                    }
				                                    echo '<br>';
				                                }
				                            }
				                            // Calculate total with fees for split payments
				                            $payment_total = $payment['amount'];
				                            if (isset($details['payments']['card']) && $details['payments']['card']['fee_amount'] > 0) {
				                                $payment_total += $details['payments']['card']['fee_amount'];
				                            }
				                            $total_with_fees += $payment_total;
				                        } elseif ('COMPANY_CREDIT' === $payment['payment_mode'] || 2 == $bill['payment_type_id']) {
				                            echo '<div class="text-muted small">Company Credit</div>';
				                            echo '<strong>Company:</strong> '.htmlspecialchars($bill['company_name']).'<br>';
				                            if ($bill['payment_due_date']) {
				                                echo '<strong>Due Date:</strong> '.date('d-m-Y', strtotime($bill['payment_due_date'])).'<br>';
				                            }
				                            echo '<strong>Credit Limit:</strong> ₹'.number_format($bill['credit_limit'], 2).'<br>';
				                            echo '<strong>Current Balance:</strong> ₹'.number_format($bill['current_balance'], 2).'<br>';
				                            if (!empty($payment['reference_no'])) {
				                                echo '<strong>Reference:</strong> '.htmlspecialchars($payment['reference_no']);
				                            }
				                            $total_with_fees += $payment['amount'];
				                        } else {
				                            echo '<div class="text-muted small">Cash Payment</div>';
				                            if (!empty($payment['reference_no'])) {
				                                echo '<strong>Ref#:</strong> '.htmlspecialchars($payment['reference_no']);
				                            }
				                            $total_with_fees += $payment['amount'];
				                        }
				        ?>
								</div>
								<div class="col-md-3 text-end">
									<strong>Collected By:</strong><br>
									<?php echo htmlspecialchars($payment['collected_by_name']); ?>
								</div>
							</div>
						</div>
					</div>
					<?php } ?>

					<div class="card bg-light">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<strong>Bill Amount:</strong>
									₹<?php echo number_format($bill['final_amount'], 2); ?><br>
									<strong>Total Paid:</strong>
									₹<?php echo number_format($total_paid, 2); ?><br>
									<strong>Pending Amount:</strong>
									₹<?php echo number_format($pending_amount, 2); ?>
								</div>
								<div class="col-md-6 text-end">
									<?php if ($total_with_fees > $total_paid) { ?>
									<strong>Total with Fees:</strong>
									₹<?php echo number_format($total_with_fees, 2); ?><br>
									<?php } ?>
									<span
										class="badge <?php echo $statusBadgeClass; ?>">
										<?php echo $ledger['payment_status'] ?? $bill['payment_status']; ?>
									</span>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php } ?>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		function printBill(billId, type = 'hospital') {
			const printUrl = `print_bill.php?id=${encodeURIComponent(billId)}&type=${encodeURIComponent(type)}&trigger_print=1`;
			window.open(printUrl, '_blank');
		}

		function cancelBill(billId) {
			if (confirm('Are you sure you want to cancel this bill?')) {
				fetch('cancel_bill.php', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: 'bill_id=' + billId
					})
					.then(response => response.json())
					.then(data => {
						if (data.status === 'success') {
							alert('Bill cancelled successfully');
							location.reload();
						} else {
							alert('Error: ' + data.message);
						}
					});
			}
		}
	</script>
</body>

</html>