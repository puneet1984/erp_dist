<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
date_default_timezone_set('Asia/Kolkata');

// Check billing permissions
if (!checkPermission('bills', 'create')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    // Set JSON content type header
    header('Content-Type: application/json');

    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        $patient_id = padPatientId($_POST['patient_id']);
        $bill_id = generateHospitalBillId($conn);
        $services = json_decode($_POST['services'], true);
        $bill_date = $_POST['bill_date'].' '.date('H:i:s'); // Add current time to bill date

        // Prepare the statement for inserting bill details
        $stmt = $conn->prepare('INSERT INTO bills (
            bill_id,
            bill_date,
            patient_id,
            doctor_id,
            dept_id,
            total_amount,
            discount,
            final_amount,
            payment_type_id,
            payment_status,
            payment_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $total = array_sum(array_column($services, 'total'));
        $discount = $_POST['discount'] ?? 0;
        $final = $total - $discount;
        $payment_type_id = $_POST['payment_type_id'];
        $payment_status = 'PENDING';
        $amount_paid = 0;
        $payment_details = [];

        // Handle different payment types
        switch ($payment_type_id) {
            case 1: // CASH
                $payment_details = [
                    'mode' => 'CASH',
                    'amount' => $final,
                    'payment_status' => 'PAID'
                ];
                $payment_status = 'PAID';
                $amount_paid = $final;
                break;

            case 6: // CARD
                $card_type = $_POST['card_type'];
                $fee_percentage = ('credit' === $card_type) ? 0.035 : 0.015;
                $fee_amount = $final * $fee_percentage;
                $final_with_fee = $final + $fee_amount;

                $payment_details = [
                    'mode' => 'CARD',
                    'card_type' => $card_type,
                    'fee_percentage' => ('credit' === $card_type) ? 3.5 : 1.5,
                    'fee_amount' => $fee_amount,
                    'reference_no' => $_POST['card_reference'] ?? '',
                    'original_amount' => $final,
                    'final_amount' => $final_with_fee,
                ];
                $final = $final_with_fee;
                $amount_paid = $final;
                $payment_status = 'PAID';
                break;

            case 7: // UPI
                $payment_details = [
                    'mode' => 'UPI',
                    'amount' => $final,
                    'reference_no' => $_POST['upi_ref'],
                    'payment_status' => 'PAID'
                ];
                $amount_paid = $final;
                $payment_status = 'PAID';
                break;

            case 8: // SPLIT
                $split_cash = floatval($_POST['split_cash']);
                $split_card = floatval($_POST['split_card']);
                $split_upi = floatval($_POST['split_upi']);
                $card_type = $_POST['split_card_type'];

                $card_fee = $split_card * (('credit' === $card_type) ? 0.035 : 0.015);
                $split_total = $split_cash + $split_card + $split_upi;
                $total_with_fees = $split_total + $card_fee;

                $payment_details = [
                    'mode' => 'SPLIT',
                    'payments' => [
                        'cash' => [
                            'mode' => 'CASH',
                            'amount' => $split_cash,
                        ],
                        'card' => [
                            'mode' => 'CARD',
                            'amount' => $split_card,
                            'type' => $card_type,
                            'fee_percentage' => ('credit' === $card_type) ? 3.5 : 1.5,
                            'fee_amount' => $card_fee,
                            'reference_no' => $_POST['split_card_reference'] ?? '',
                        ],
                        'upi' => [
                            'mode' => 'UPI',
                            'amount' => $split_upi,
                            'reference_no' => $_POST['split_upi_ref'] ?? '',
                        ],
                    ],
                    'total_amount' => $split_total,
                    'total_with_fees' => $total_with_fees,
                ];
                $amount_paid = $split_total;
                $payment_status = ($amount_paid >= $final) ? 'PAID' : 'PARTIAL';
                break;
        }

        $payment_details_json = json_encode($payment_details);

        $stmt->bind_param(
            'sssiiiddiss',
            $bill_id,
            $bill_date,  // Use bill_date for bills table
            $patient_id,
            $_POST['doctor_id'],
            $_POST['dept_id'],
            $total,
            $discount,
            $final,
            $payment_type_id,
            $payment_status,
            $payment_details_json
        );
        $stmt->execute();

        // Insert bill items
        $stmt = $conn->prepare('INSERT INTO bill_items (bill_id, service_id, quantity, unit_price, total) 
                               VALUES (?, ?, ?, ?, ?)');

        foreach ($services as $service) {
            $stmt->bind_param(
                'siids',
                $bill_id,
                $service['service_id'],
                $service['quantity'],
                $service['price'],
                $service['total']
            );
            $stmt->execute();
        }

        // Create payment ledger entry - transaction_date is bill_date
        $transaction_id = generateTransactionId();
        $stmt = $conn->prepare('INSERT INTO payment_ledger 
            (bill_id, transaction_id, transaction_date, amount_due, amount_paid, payment_type_id, payment_status, payment_due_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL ? DAY), ?)');

        $payment_days = (2 == $payment_type_id || 3 == $payment_type_id) ? 30 : 0; // Credit period for COMPANY_CREDIT and BNP

        $stmt->bind_param(
            'sssddsssii',
            $bill_id,
            $transaction_id,
            $bill_date,  // Use same date for transaction_date
            $final,
            $amount_paid,
            $payment_type_id,
            $payment_status,
            $bill_date,  // Use same date as base for payment_due_date
            $payment_days,
            $_SESSION['user_id']
        );
        $stmt->execute();

        // In the POST handler section, after payment ledger creation:

        if (2 == $payment_type_id) { // COMPANY_CREDIT
            try {
                // Get company details
                $company_name = sanitizeInput($_POST['company_name']);
                $stmt = $conn->prepare('SELECT * FROM company_accounts WHERE company_name = ?');
                $stmt->bind_param('s', $company_name);
                $stmt->execute();
                $company = $stmt->get_result()->fetch_assoc();

                if (!$company) {
                    throw new Exception('Company not found');
                }

                // Check credit limit
                if (($company['current_balance'] + $final) > $company['credit_limit']) {
                    throw new Exception('Credit limit exceeded');
                }

                // Update payment ledger with company info
                $stmt = $conn->prepare('UPDATE payment_ledger 
                    SET company_id = ?, 
                        payment_due_date = DATE_ADD(CURRENT_DATE, INTERVAL ? DAY)
                    WHERE ledger_id = LAST_INSERT_ID()');

                $stmt->bind_param('ii', $company['company_id'], $company['payment_days']);
                $stmt->execute();

                // Update company credit balance
                if (!updateCompanyCredit($conn, $company['company_id'], $final, 'ADD')) {
                    throw new Exception('Failed to update company credit');
                }
            } catch (Exception $e) {
                throw new Exception('Company credit error: '.$e->getMessage());
            }
        }

        // Process payment collection if amount was paid
        if ($amount_paid > 0) {
            $stmt = $conn->prepare('INSERT INTO payment_collections 
                (ledger_id, bill_id, amount, payment_type_id, payment_date, payment_details, collected_by)
                VALUES (LAST_INSERT_ID(), ?, ?, ?, ?, ?, ?)');

            $collection_details = $payment_details; // Use same payment details for collection
            $collection_details_json = json_encode($collection_details);

            $stmt->bind_param(
                'sdissi',
                $bill_id,
                $amount_paid,
                $payment_type_id,
                $bill_date,
                $collection_details_json,
                $_SESSION['user_id']
            );
            $stmt->execute();
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'bill_id' => $bill_id]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Error generating bill: '.$e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to generate bill: '.$e->getMessage(),
        ]);
        exit;
    }
}

$conn = getDbConnection();
// Modify the doctors query to include charge_per_visit
$doctors = $conn->query("SELECT d.*, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        WHERE d.status = 'ACTIVE' 
                        ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);

// Add this query before the HTML template:
$companies = $conn->query("SELECT company_id, company_name FROM company_accounts WHERE status = 'ACTIVE' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html>

<head>
	<title>Generate Bill</title>
	<link rel="stylesheet" href="../../assets/css/generate_bill.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
	<script src="../../assets/js/tax_calculator.js"></script>
	<script src="../../assets/js/generate_bill.js"></script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Generate Bill</h2>
		<form id="billForm">
			<!-- Patient Details Section -->
			<div class="form-row">
				<div class="form-group">
					<label>Patient ID:</label>
					<div class="input-group">
						<input type="text" name="patient_id" required placeholder="Enter Patient ID"
							onblur="searchPatient()" title="Enter valid Patient ID">
					</div>
				</div>
				<div class="form-group">
					<label>Patient Name:</label>
					<input type="text" id="patientName" readonly class="readonly-field"
						placeholder="Will be auto-populated">
				</div>
			</div>

			<!-- Bill Details Section -->
			<div class="form-row">
				<div class="form-group">
					<label>Bill Number:</label>
					<input type="text" id="billNumber" readonly class="readonly-field"
						value="<?php echo HOSPITAL_CODE.'/'.getCurrentFinancialYear().'/H/'.getNextBillNumber('H', $conn); ?>">
				</div>
				<div class="form-group">
					<label>Bill Date:</label>
					<input type="date" name="bill_date" id="billDate"
						value="<?php echo date('Y-m-d'); ?>"
						min="<?php echo date('Y-m-d', strtotime('-7 days')); ?>"
						required>
				</div>
			</div>

			<!-- Doctor and Service Selection -->
			<div class="service-row">
				<div class="form-group">
					<label>Doctor:</label>
					<select name="doctor_id" required
						onchange="updateDepartment(this.value); showConsultationCharge(this)">
						<option value="">Select Doctor</option>
						<?php foreach ($doctors as $doctor) { ?>
						<option
							value="<?php echo $doctor['doctor_id']; ?>"
							data-dept="<?php echo $doctor['dept_id']; ?>"
							data-charge="<?php echo $doctor['charge_per_visit']; ?>">
							Dr.
							<?php echo htmlspecialchars($doctor['name']); ?>
							(<?php echo htmlspecialchars($doctor['dept_name']); ?>)
							- Consultation:
							₹<?php echo number_format($doctor['charge_per_visit'], 2); ?>
						</option>
						<?php } ?>
					</select>
				</div>
				<div class="form-group">
					<label>Select Service:</label>
					<select id="serviceSelect" style="width: 100%;"></select>
				</div>
			</div>
			<input type="hidden" name="dept_id" id="dept_id">

			<!-- Services List -->
			<div id="services">
				<h3>Selected Services</h3>
				<div class="service-list">
					<div class="service-header">
						<span>Service</span>
						<span>Quantity</span>
						<span>Price</span>
						<span>Total</span>
						<span>Action</span>
					</div>
					<div id="servicesList"></div>
				</div>
			</div>

			<!-- Billing Summary -->
			<div class="billing-summary">
				<div class="billing-row">
					<div class="form-group">
						<label>Subtotal:</label>
						<span id="subtotal">0.00</span>
					</div>
					<div id="taxSection" style="display: none;">
						<div class="summary-row">
							<label>Total GST:</label>
							<span id="totalTax">0.00</span>
						</div>
					</div>
					<div class="form-group">
						<label>Discount Amount:</label>
						<input type="number" name="discount" value="0" min="0" style="width: 100px;"
							onchange="calculateFromAmount(this.value)">
					</div>
					<div class="form-group">
						<label>Discount Percentage:</label>
						<div class="percent-input">
							<input type="number" name="discount_percent" value="0" min="0" max="100" step="0.01"
								style="width: 100px;" onchange="calculateFromPercent(this.value)">
						</div>
					</div>
				</div>
				<div class="total-row">
					<div class="form-group">
						<label>Total Amount:</label>
						<span id="totalAmount" class="total">0.00</span>
					</div>
				</div>

				<div class="payment-section">
					<h3>Payment Details</h3>
					<div class="form-group">
						<label>Payment Type:</label>
						<select name="payment_type_id" required onchange="handlePaymentType(this.value)">
							<?php
                            $payment_types = $conn->query('SELECT * FROM payment_types ORDER BY type_name');
while ($type = $payment_types->fetch_assoc()) {
    $selected = ('CASH' === $type['type_name']) ? 'selected' : '';
    echo "<option value='".$type['payment_type_id']."' {$selected}>"
        .str_replace('_', ' ', $type['type_name']).'</option>';
}
?>
						</select>
					</div>

					<!-- Company Credit Details -->
					<div id="company_details" class="payment-details" style="display:none;">
						<div class="company-details-row">
							<div class="form-group">
								<label>Company Name:</label>
								<select class="form-select" name="company_name" id="company_name">
									<option value="">Select Company</option>
									<?php foreach ($companies as $company) { ?>
									<option
										value="<?php echo htmlspecialchars($company['company_name']); ?>">
										<?php echo htmlspecialchars($company['company_name']); ?>
									</option>
									<?php } ?>
								</select>
							</div>
							<div class="form-group">
								<label>Reference:</label>
								<input type="text" name="company_ref">
							</div>
						</div>
					</div>

					<!-- Card Payment Details -->
					<div id="card_details" class="payment-details" style="display:none;">
						<div class="card-payment-row">
							<div class="form-group">
								<label>Card Type:</label>
								<select name="card_type" onchange="calculateCardFee()">
									<option value="debit">Debit Card (1.5% fee)</option>
									<option value="credit">Credit Card (3.5% fee)</option>
								</select>
							</div>
							<div class="form-group">
								<label>Card Reference:</label>
								<input type="text" name="card_reference" required
									placeholder="Enter card reference number">
							</div>
						</div>
						<div class="card-details-row">
							<div class="form-group">
								<label>Convenience Fee:</label>
								<span id="convenience_fee">0.00</span>
							</div>
							<div class="form-group">
								<label>Final Amount (with fee):</label>
								<span id="final_with_fee">0.00</span>
							</div>
						</div>
					</div>

					<!-- UPI Details -->
					<div id="upi_details" class="payment-details" style="display:none;">
						<div class="form-group">
							<label>UPI Transaction ID:</label>
							<input type="text" name="upi_ref" pattern="[a-zA-Z0-9]{12,}"
								title="Enter valid UPI transaction ID">
						</div>
					</div>

					<!-- Insurance/Mediclaim Details -->
					<div id="insurance_details" class="payment-details" style="display:none;">
						<div class="form-group">
							<label>Policy/Card Number:</label>
							<input type="text" class="form-control" name="policy_number" id="policy_number"
								placeholder="Enter Policy Number">
						</div>
						<div class="form-group">
							<label>Authorization Number:</label>
							<input type="text" name="auth_number">
						</div>
					</div>

					<!-- Split Payment Details -->
					<div id="split_details" class="payment-details" style="display:none;">
						<div class="split-container">
							<div class="form-group">
								<label>Cash Amount:</label>
								<input type="number" name="split_cash" value="0" min="0" step="0.01"
									onchange="updateSplitTotal()">
							</div>
							<div class="form-group">
								<label>Card Amount:</label>
								<input type="number" name="split_card" value="0" min="0" step="0.01"
									onchange="updateSplitTotal()">
							</div>
							<div class="form-group">
								<label>UPI Amount:</label>
								<input type="number" name="split_upi" value="0" min="0" step="0.01"
									onchange="updateSplitTotal()">
							</div>
							<div class="form-group">
								<label>Split Total:</label>
								<span id="split_total">0.00</span>
							</div>
						</div>
					</div>
				</div>
			</div>
			<button type="submit" class="btn-submit">Generate Bill</button>
		</form>
	</div>
	<script>
		function showConsultationCharge(select) {
			const selectedOption = select.selectedOptions[0];
			if (selectedOption.value) {
				const charge = parseFloat(selectedOption.dataset.charge);
				console.log(`Selected doctor's consultation charge: ₹${charge}`); // Debug log
			}
		}
	</script>
</body>

</html>