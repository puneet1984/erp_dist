<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
// require_once '../../includes/helpers/ipd_functions.php';

// Check billing permissions
if (!checkPermission('bills', 'create')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

$fy = getCurrentFinancialYear();

// Get admission details if ID is provided
$admission = null;
if (isset($_GET['admission_id'])) {
    $admission = getAdmissionDetails($_GET['admission_id']);
    if (!$admission) {
        exit('Invalid admission ID');
    }

    // Add this code to get pharmacy bill total
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(final_amount), 0) as pharmacy_total 
        FROM pharmacy_sales 
        WHERE admission_id = ? AND patient_id = ? AND payment_status != 'CANCELLED'
    ");
    $stmt->bind_param('ii', $_GET['admission_id'], $admission['patient_id']);
    $stmt->execute();
    $pharmacy_result = $stmt->get_result();
    $pharmacy_total = $pharmacy_result->fetch_assoc()['pharmacy_total'];
    $admission['pharmacy_total'] = $pharmacy_total;

    // Get existing bills for this admission
    $stmt = $conn->prepare("
        SELECT b.bill_id, b.bill_date, b.total_amount 
        FROM bills b
        WHERE b.bill_id LIKE CONCAT('MNH/%/IPD/', ?)
        ORDER BY b.bill_date DESC
    ");
    $stmt->bind_param('s', $_GET['admission_id']);
    $stmt->execute();
    $admission['bills'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Calculate days of stay using the function
    $admission['days_stayed'] = calculateAdmissionDays(
        $admission['admission_date'],
        $admission['discharge_date']
    );
    $admission['ward_charges'] = $admission['days_stayed'] * $admission['charge_per_day'];

    // Get the bill type (interim/final) based on admission status
    $billType = ('Discharged' === $admission['status']) ? 'FINAL' : 'INTERIM';

    // Add bill date constraints
    $maxBillDate = ('Discharged' === $admission['status'])
        ? date('Y-m-d', strtotime($admission['discharge_date']))
        : date('Y-m-d');
}

// Handle POST request for bill generation
if ('POST' === $_SERVER['REQUEST_METHOD'] && !isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        // Validate admission
        if (!isset($_POST['admission_id'])) {
            throw new Exception('Admission ID is required');
        }

        // Fetch admission details for validation
        $admission = getAdmissionDetails($_POST['admission_id']);
        if (!$admission) {
            throw new Exception('Invalid admission ID');
        }

        // Validate bill date
        $bill_date = $_POST['bill_date'] ?? date('Y-m-d');
        if ('Discharged' === $admission['status'] && strtotime($bill_date) > strtotime($admission['discharge_date'])) {
            throw new Exception('Bill date cannot be after discharge date');
        }

        $services = json_decode($_POST['services'], true);
        $bill_date = date('Y-m-d H:i:s');
        $bill_id = generateIPDBillId($conn);

        // Insert bill header with bill type
        $stmt = $conn->prepare('
            INSERT INTO bills (
                bill_id, bill_date, FY, patient_id, admission_id, doctor_id, dept_id,
                total_amount, discount, final_amount, payment_type_id, payment_status,
                bill_type, company_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $total = array_sum(array_column($services, 'total'));
        $discount = $_POST['discount'] ?? 0;
        $final = $total - $discount;

        // Handle payment processing
        $payment_type_id = $_POST['payment_type_id'];
        $payment_status = 'PENDING';
        $amount_paid = 0;
        $payment_details = []; // Initialize to prevent undefined variable
        $company_id = null;

        // Handle different payment types
        switch ($payment_type_id) {
            case 1: // CASH
                $payment_details = [
                    'mode' => 'CASH',
                    'amount' => $final,
                    'payment_status' => 'PAID',
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
                    'reference_no' => $_POST['upi_reference'],
                    'payment_status' => 'PAID',
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
                            'reference_no' => $_POST['split_upi_reference'] ?? '',
                        ],
                    ],
                    'total_amount' => $split_total,
                    'total_with_fees' => $total_with_fees,
                ];
                $amount_paid = $split_total;
                $payment_status = ($amount_paid >= $final) ? 'PAID' : 'PARTIAL';
                break;

            case 2: // COMPANY_CREDIT
                $company_name = sanitizeInput($_POST['company_name'] ?? '');
                $stmt = $conn->prepare("SELECT * FROM company_accounts WHERE company_name = ? AND status = 'ACTIVE' FOR UPDATE");
                $stmt->bind_param('s', $company_name);
                $stmt->execute();
                $company = $stmt->get_result()->fetch_assoc();
                if (!$company) {
                    throw new Exception('Please select an active company for Company Credit');
                }
                if (($company['current_balance'] + $final) > $company['credit_limit']) {
                    throw new Exception('Credit limit exceeded. Available credit: '
                        .number_format($company['credit_limit'] - $company['current_balance'], 2));
                }
                $company_id = (int) $company['company_id'];
                $payment_details = [
                    'mode' => 'COMPANY_CREDIT',
                    'company_name' => $company_name,
                    'reference_no' => $_POST['company_ref'] ?? '',
                    'credit_limit' => $company['credit_limit'],
                    'current_balance' => $company['current_balance'],
                    'payment_days' => $company['payment_days'],
                ];
                break;

            default:
                throw new Exception('Invalid payment type');
        }

        $payment_details_json = json_encode($payment_details);

        $stmt->bind_param(
            'sssssiiddisssi',
            $bill_id,
            $bill_date,
            $fy,
            $_POST['patient_id'],
            $_POST['admission_id'],
            $_POST['doctor_id'],
            $_POST['dept_id'],
            $total,
            $discount,
            $final,
            $payment_type_id,
            $payment_status,
            $billType,
            $company_id
        );
        $stmt->execute();

        // Create payment ledger entry with standardized payment details
        $transaction_id = generateTransactionId('IPD');
        $stmt = $conn->prepare('
            INSERT INTO payment_ledger (
                bill_id, transaction_id, transaction_date, amount_due, 
                amount_paid, payment_type_id, payment_status, created_by
            ) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?)
        ');

        $stmt->bind_param(
            'ssddisi',
            $bill_id,
            $transaction_id,
            $final,
            $amount_paid,
            $payment_type_id,
            $payment_status,
            $_SESSION['user_id']
        );
        $stmt->execute();
        $ledger_id = $conn->insert_id;

        if ($company_id) {
            $stmt = $conn->prepare('UPDATE payment_ledger
                SET company_id = ?, payment_due_date = DATE_ADD(CURRENT_DATE, INTERVAL ? DAY)
                WHERE ledger_id = ?');
            $payment_days = (int) ($company['payment_days'] ?: 30);
            $stmt->bind_param('iii', $company_id, $payment_days, $ledger_id);
            $stmt->execute();
            if (!updateCompanyCredit($conn, $company_id, $final, 'ADD')) {
                throw new Exception('Failed to update company credit');
            }
        }

        // Process immediate payment with standardized details
        if ($amount_paid > 0) {
            $payment_collection_details = array_merge($payment_details, [
                'notes' => $_POST['payment_notes'] ?? '',
            ]);

            $stmt = $conn->prepare('
                INSERT INTO payment_collections (
                    ledger_id, bill_id, amount, payment_type_id,
                    payment_date, payment_details, collected_by
                ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
            ');

            $payment_details_json = json_encode($payment_collection_details);
            $stmt->bind_param(
                'isidsi',
                $ledger_id,
                $bill_id,
                $amount_paid,
                $payment_type_id,
                $payment_details_json,
                $_SESSION['user_id']
            );
            $stmt->execute();
        }

        // Insert bill items
        $stmt = $conn->prepare('
            INSERT INTO bill_items (bill_id, service_id, quantity, unit_price, total)
            VALUES (?, ?, ?, ?, ?)
        ');

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

        $conn->commit();
        echo json_encode(['status' => 'success', 'bill_id' => $bill_id]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle POST for payment processing
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        if ('payment' === $_POST['action']) {
            $result = processIPDPayment($conn, $_POST);
        } elseif ('refund' === $_POST['action']) {
            $result = processIPDRefund($conn, $_POST);
        } else {
            throw new Exception('Invalid action');
        }

        $conn->commit();
        echo json_encode($result);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

?>
<!DOCTYPE html>
<html>

<head>
	<title>IPD Bill Generation</title>
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
	<link rel="stylesheet" href="../../assets/css/cdn/all.min.css">
	<link rel="stylesheet" href="../../assets/css/generate_bill.css">
	<!-- <link rel="stylesheet" href="../../assets/css/pharmacy-sale.css"> -->
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<script src="../../assets/js/tax_calculator.js"></script>

	<style>
		/* Variables */
		:root {
			--primary-color: #007bff;
			--secondary-color: #6c757d;
			--success-color: #28a745;
			--danger-color: #dc3545;
			--warning-color: #ffc107;
			--info-color: #17a2b8;
			--light-color: #f8f9fa;
			--dark-color: #343a40;
			--border-color: #dee2e6;
		}

		/* Base Styles */
		body {
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			line-height: 1.6;
			color: #333;
			margin: 0;
			padding: 0;
		}

		.container {
			max-width: 800px;
			margin: 0 auto;
			background: white;
			padding: 20px;
			border-radius: 5px;
			box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
		}

		/* Payment Section */
		.payment-section {
			background: var(--light-color);
			padding: 20px;
			border-radius: 5px;
			margin-top: 20px;
		}

		.payment-details {
			background: white;
			margin: 15px 0;
			padding: 15px;
			border: 1px solid var(--border-color);
			border-radius: 4px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}

		/* Card payment details layout */
		.card-payment-row {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 15px;
			margin-bottom: 12px;
		}

		.card-payment-row .form-group {
			margin-bottom: 0;
		}

		.card-payment-row input,
		.card-payment-row select {
			width: 100%;
		}

		/* Card payment amounts layout */
		.card-details-row {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 15px;
			margin-top: 10px;
			align-items: baseline;
		}

		.card-details-row .form-group {
			margin-bottom: 0;
		}

		.card-details-row label {
			font-size: 0.9em;
			color: #666;
			margin-bottom: 4px;
		}

		.card-details-row span {
			display: block;
			font-size: 1.1em;
			font-weight: 500;
			color: #2c3e50;
		}

		/* Split payment layout */
		.split-container {
			display: grid;
			grid-template-columns: 1fr;
			gap: 20px;
		}

		.split-payment-row {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 15px;
		}

		.split-summary-row {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 15px;
			margin-top: 15px;
			padding-top: 15px;
			border-top: 1px solid #dee2e6;
		}

		#convenience_fee,
		#final_with_fee,
		#split_total,
		#split_card_fee,
		#split_final_total {
			font-weight: bold;
			color: #2c3e50;
		}

		/* Responsive adjustments */
		@media (max-width: 768px) {

			.card-payment-row,
			.card-details-row,
			.split-payment-row,
			.split-summary-row {
				grid-template-columns: 1fr;
			}
		}

		.admission-details {
			background: #fff;
			border: 1px solid #dee2e6;
			border-radius: 5px;
			margin-bottom: 20px;
		}

		.admission-details .row {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 0;
			margin: 0;
		}

		.admission-details .col {
			padding: 15px;
			border-right: 1px solid #dee2e6;
		}

		.admission-details .col:last-child {
			border-right: none;
		}

		.admission-details h4 {
			color: #2c3e50;
			font-size: 1rem;
			margin-bottom: 15px;
			padding-bottom: 10px;
			border-bottom: 1px solid #eee;
		}

		.admission-details p {
			margin-bottom: 0.5rem;
			font-size: 0.9rem;
		}

		.ward-charges {
			background: #f8f9fa;
			padding: 10px;
			border-radius: 4px;
			margin-top: 10px;
		}

		.previous-bills {
			margin-top: 10px;
			padding-top: 10px;
			border-top: 1px solid #dee2e6;
		}

		.bill-number {
			background: #f8f9fa;
			padding: 10px;
			border-radius: 4px;
			margin-bottom: 15px;
		}

		.bill-number h5 {
			margin: 0;
			color: #2c3e50;
			font-size: 0.9rem;
		}

		.bill-number p {
			margin: 5px 0 0 0;
			font-size: 1.1rem;
			font-weight: bold;
		}

		.select2-container--default .select2-results__option {
			padding: 8px 12px;
			font-size: 14px;
			background-color: white;
		}

		.select2-results__option {
			border-bottom: 1px solid #eee;
		}

		.select2-container--default .select2-results__option--highlighted[aria-selected] {
			background-color: #5897fb;
			color: white;
		}

		.select2-container--default .select2-search--dropdown .select2-search__field {
			padding: 8px;
			font-size: 14px;
		}

		.select2-dropdown {
			border: 1px solid #ddd;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}

		.bill-header {
			display: flex;
			gap: 20px;
			margin-bottom: 15px;
		}

		.bill-header>div {
			flex: 1;
			background: #f8f9fa;
			padding: 10px;
			border-radius: 4px;
		}

		.bill-date input {
			width: 100%;
			padding: 5px;
			margin-top: 5px;
			border: 1px solid #ddd;
			border-radius: 4px;
		}

		.bill-number,
		.bill-date {
			min-width: 200px;
		}

		.bill-date h5,
		.bill-number h5 {
			margin: 0;
			color: #2c3e50;
			font-size: 0.9rem;
		}

		.bill-date-display {
			margin-top: 5px;
		}

		.bill-date-value {
			padding: 6px 8px;
			background: #f8f9fa;
			border: 1px solid #dee2e6;
			border-radius: 4px;
			font-size: 1rem;
		}

		.btn-outline-secondary {
			border-color: #dee2e6;
		}

		.btn-outline-secondary:hover {
			background-color: #f8f9fa;
			border-color: #c1c9d0;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="d-flex align-items-center mb-4">
			<button onclick="goBack()" class="btn btn-outline-secondary me-3">
				<i class="fas fa-arrow-left"></i> Back
			</button>
			<h2>IPD Bill Generation</h2>
		</div>

		<?php if ($admission) { ?>
		<!-- Bill Number and Date Row -->
		<div class="bill-header">
			<div class="bill-number">
				<h5>Bill Number</h5>
				<p><?php echo generateIPDBillId($conn); ?></p>
			</div>
			<div class="bill-date">
				<h5>Bill Date</h5>
				<div class="bill-date-display">
					<?php if ('Discharged' === $admission['status']) { ?>
					<div class="bill-date-value">
						<?php echo date('d-m-Y', strtotime($admission['discharge_date'])); ?>
					</div>
					<?php } else { ?>
					<input type="date" name="bill_date" id="billDate" required
						value="<?php echo date('Y-m-d'); ?>"
						max="<?php echo date('Y-m-d'); ?>">
					<?php } ?>
				</div>
			</div>
		</div>

		<div class="admission-details">
			<div class="row">
				<div class="col">
					<h4>Patient Details</h4>
					<p>Name:
						<?php echo htmlspecialchars($admission['patient_name'] ?? ''); ?>
					</p>
					<p>Age/Gender:
						<?php echo ($admission['age'] ?? '').' / '.($admission['gender'] ?? ''); ?>
					</p>
					<p>IP Number:
						<?php echo htmlspecialchars('MNH/'.str_pad($admission['admission_id'] ?? '', 4, '0', STR_PAD_LEFT)); ?>
					</p>
				</div>
				<div class="col">
					<h4>Admission Details</h4>
					<p>Ward:
						<?php echo htmlspecialchars($admission['ward_name'] ?? ''); ?>
						(<?php echo $admission['ward_type'] ?? ''; ?>)
					</p>
					<p>Bed Number:
						<?php echo htmlspecialchars($admission['bed_number'] ?? ''); ?>
					</p>
					<p>Doctor: Dr.
						<?php echo htmlspecialchars($admission['doctor_name'] ?? ''); ?>
					</p>
				</div>
				<div class="col">
					<h4>Billing Details</h4>
					<p>Admission Date:
						<?php echo !empty($admission['admission_date']) ? date('d-m-Y', strtotime($admission['admission_date'])) : '-'; ?>
					</p>
					<?php if ('Discharged' === $admission['status']) { ?>
					<p>Discharge Date:
						<?php echo date('d-m-Y', strtotime($admission['discharge_date'])); ?>
					</p>
					<?php } else { ?>
					<p>Discharge Date: Admitted</p>
					<?php } ?>
					<p>Days Stayed:
						<?php echo $admission['days_stayed'] ?? 0; ?>
					</p>
					<div class="ward-charges">
						<p>Ward Charges
							(₹<?php echo number_format($admission['charge_per_day'] ?? 0, 2); ?>/day):
						</p>
					</div>
					<div class="pharmacy-charges mt-2">
						<p>Pharmacy Bill Amount:<span style="font-weight: bold;">₹ <?php echo number_format($admission['pharmacy_total'] ?? 0, 2); ?></span></p>
					</div>
					<?php if (!empty($admission['bills'])) { ?>
					<div class="previous-bills">
						<p class="mb-2 fw-bold">Previous Bills:</p>
						<?php foreach ($admission['bills'] as $bill) { ?>
						<div class="d-flex justify-content-between align-items-center mb-1">
							<small
								class="text-muted"><?php echo $bill['bill_id']; ?></small>
							<small
								class="text-muted">₹<?php echo number_format($bill['total_amount'], 2); ?></small>
						</div>
						<?php } ?>
					</div>
					<?php } ?>
				</div>
			</div>
		</div>

		<form id="billForm">
			<input type="hidden" name="admission_id"
				value="<?php echo $admission['admission_id'] ?? ''; ?>">
			<input type="hidden" name="patient_id"
				value="<?php echo $admission['patient_id'] ?? ''; ?>">
			<input type="hidden" name="doctor_id"
				value="<?php echo $admission['doctor_id'] ?? ''; ?>">
			<input type="hidden" name="dept_id"
				value="<?php echo $admission['dept_id'] ?? ''; ?>">

			<!-- Service Selection -->
			<div class="form-row">
				<div class="form-group">
					<label>Add Service:</label>
					<select id="serviceSelect" style="width: 100%;"></select>
				</div>
			</div>

			<!-- Services List -->
			<div id="services">
				<h3>Services & Charges</h3>
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
					<div class="form-group">
						<label>Discount:</label>
						<input type="number" name="discount" value="0" min="0" step="0.01" onchange="calculateTotal()">
					</div>
				</div>
				<div class="billing-row">
					<div class="form-group">
						<label>Rounding Off:</label>
						<span id="rounding_amount">0.00</span>
					</div>
				</div>
				<div class="total-row">
					<div class="form-group">
						<label>Total Amount:</label>
						<span id="totalAmount" class="total">0.00</span>
					</div>
				</div>

				<!-- Payment Section -->
				<div class="payment-section">
					<h3>Payment Details</h3>
					<div class="form-group">
						<label>Payment Type:</label>
						<select name="payment_type_id" required onchange="handlePaymentType(this.value)">
							<?php
                                $companies = $conn->query("SELECT company_id, company_name FROM company_accounts WHERE status = 'ACTIVE' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);
                                $payment_types = $conn->query('SELECT * FROM payment_types ORDER BY type_name');
		    while ($type = $payment_types->fetch_assoc()) {
		        $selected = ('CASH' === $type['type_name']) ? 'selected' : '';
		        echo "<option value='".$type['payment_type_id']."' {$selected}>"
		            .str_replace('_', ' ', $type['type_name']).'</option>';
		    }
		    ?>
						</select>
					</div>

					<!-- Payment Details Sections -->
					<div id="company_details" class="payment-details" style="display:none;">
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
								<input type="text" name="card_reference" pattern="[a-zA-Z0-9]{1,20}" data-required="true"
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

					<div id="upi_details" class="payment-details" style="display:none;">
						<div class="form-group">
							<label>UPI Transaction ID:</label>
							<input type="text" name="upi_ref" pattern="[a-zA-Z0-9]{12,}"
								title="Enter valid UPI transaction ID (min 12 characters)">
						</div>
					</div>

					<div id="split_details" class="payment-details" style="display:none;">
						<div class="split-container">
							<div class="split-info">
								<div class="amount-due">Amount Due: ₹<span id="amount_due">0.00</span></div>
								<div class="remaining">Remaining: ₹<span id="remaining_amount">0.00</span></div>
							</div>
							<div class="split-payment-row">
								<div class="form-group">
									<label>Cash Amount:</label>
									<input type="number" name="split_cash" value="0" min="0" step="0.01"
										onchange="updateSplitTotal()">
								</div>
								<div class="form-group">
									<label>Card Amount:</label>
									<input type="number" name="split_card" value="0" min="0" step="0.01"
										onchange="updateSplitTotal()">
									<select name="split_card_type" onchange="calculateSplitCardFee()">
										<option value="debit">Debit Card (1.5% fee)</option>
										<option value="credit">Credit Card (3.5% fee)</option>
									</select>
									<input type="text" name="split_card_reference" placeholder="Card Reference">
								</div>
								<div class="form-group">
									<label>UPI Amount:</label>
									<input type="number" name="split_upi" value="0" min="0" step="0.01"
										onchange="updateSplitTotal()">
									<input type="text" name="split_upi_ref" placeholder="UPI Reference">
								</div>
							</div>
							<div class="split-summary-row">
								<div class="form-group">
									<label>Split Total:</label>
									<span id="split_total">0.00</span>
								</div>
								<div class="form-group">
									<label>Card Fee:</label>
									<span id="split_card_fee">0.00</span>
								</div>
								<div class="form-group">
									<label>Final Total:</label>
									<span id="split_final_total">0.00</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<button type="submit" class="btn-submit">Generate Bill</button>
		</form>

		<?php } else { ?>
		<div class="alert alert-warning">
			Please select an admission from the <a href="ipd_list.php">IPD List</a>
		</div>
		<?php } ?>
	</div>

	<script>
		let services = [];

		// Document ready handler with initialization code
		$(document).ready(function() {

			// Update bill date constraints
			const billDate = document.getElementById("billDate");
			const admissionStatus =
				'<?php echo $admission['status']; ?>';
			const dischargeDate =
				'<?php echo $admission['discharge_date'] ?? ''; ?>';
			const maxDate = '<?php echo $maxBillDate; ?>';

			if (billDate) {
				// Set max date based on admission status
				billDate.max = maxDate;

				// Set min date to admission date
				const admissionDate =
					'<?php echo date('Y-m-d', strtotime($admission['admission_date'])); ?>';
				billDate.min = admissionDate;

				// Set default value to appropriate date
				billDate.value = admissionStatus === 'Discharged' ? dischargeDate : maxDate;

				// Add validation on change
				billDate.addEventListener("change", function() {
					const selectedDate = new Date(this.value);
					const maxAllowedDate = new Date(maxDate);
					const minAllowedDate = new Date(admissionDate);

					if (selectedDate > maxAllowedDate) {
						alert(admissionStatus === 'Discharged' ?
							"Bill date cannot be after discharge date" :
							"Bill date cannot be in the future");
						this.value = maxDate;
					} else if (selectedDate < minAllowedDate) {
						alert("Bill date cannot be before admission date");
						this.value = maxDate;
					}
				});
			}

			// Initialize select2
			$("#serviceSelect").select2({
				placeholder: 'Search for services...',
				ajax: {
					url: '../../includes/helpers/get_services.php',
					dataType: 'json',
					delay: 250,
					data: function(params) {
						return {
							term: params.term,
							dept_id: '<?php echo $admission ? $admission['dept_id'] : ''; ?>'
						};
					},
					processResults: function(data) {
						return {
							results: data
						};
					},
					cache: true
				},
				minimumInputLength: 3,
				templateResult: formatService,
				templateSelection: formatServiceSelection
			}).on('select2:select', function(e) {
				const service = e.params.data;
				addServiceToList(service);
				$(this).val(null).trigger('change');
			}).on('select2:opening', function() {
				setTimeout(() => {
					const searchField = document.querySelector('.select2-search__field');
					if (searchField) {
						searchField.focus();
					}
				}, 0);
			});

			// Set up form submission
			$("#billForm").on('submit', function(e) {
				e.preventDefault();
				if (services.length === 0) {
					alert('Please add at least one service!');
					return;
				}

				const formData = new FormData(this);
				formData.append('services', JSON.stringify(services));

				// Add card fee to total amount for card payments
				const paymentType = formData.get('payment_type_id');
				if (paymentType === '6') { // CARD
					const {
						fee,
						finalAmount
					} = calculateCardFee();
					formData.append('card_fee', fee);
					formData.append('final_with_fee', finalAmount);
				}

				// Handle split payment amounts
				if (paymentType === '8') { // SPLIT
					const cash = parseFloat(formData.get('split_cash')) || 0;
					const card = parseFloat(formData.get('split_card')) || 0;
					const upi = parseFloat(formData.get('split_upi')) || 0;
					const cardType = formData.get('split_card_type');

					const cardFeePercentage = cardType === 'credit' ? 0.035 : 0.015;
					const cardFee = card * cardFeePercentage;

					formData.append('split_card_fee', cardFee);
					formData.append('split_total', cash + card + upi);
					formData.append('split_total_with_fees', cash + card + upi + cardFee);
				}

				// Disable submit button
				const submitButton = this.querySelector('button[type="submit"]');
				submitButton.disabled = true;

				fetch('ipd_bill.php', {
						method: 'POST',
						body: formData
					})
					.then(response => response.json())
					.then(data => {
						if (data.status === 'success') {
							alert('Bill generated successfully!');
							window.location.href = 'print_bill.php?id=' + data.bill_id;
						} else {
							throw new Error(data.message);
						}
					})
					.catch(error => {
						alert('Failed to generate bill: ' + error.message);
					})
					.finally(() => {
						// Re-enable submit button after 3 seconds
						setTimeout(() => {
							submitButton.disabled = false;
						}, 3000);
					});
			});

			// Initialize payment type as cash
			handlePaymentType(1);
		});

		function formatService(service) {
			if (service.loading) return service.text;
			return `${service.label} (₹${service.price})`;
		}

		function formatServiceSelection(service) {
			if (!service.id) return service.text;
			return service.label;
		}

		function addServiceToList(service) {
			console.log("Processing service:", service);

			// Get admission details directly from PHP variables
			const days =
			Number( <?php echo json_encode($admission['days_stayed'] ?? 1); ?>
				);
			const wardCharges =
			Number( <?php echo json_encode($admission['charge_per_day'] ?? 0); ?>
				);

			console.log('Days stayed:', days);
			console.log('ward charges', wardCharges);

			// Skip duplicate check for services that can have multiple entries
			if (!['362', '363', '364', '365', '366'].includes(service.id)) {
				if (services.some(s => s.service_id === service.id)) {
					alert('Service already added!');
					return;
				}
			}

			let quantity = 1;
			let price = parseFloat(service.price) || 0;

			// Handle special services
			switch (service.label) {
				case 'Ward Charges':
					console.log('Adding Ward Charges');
					price = wardCharges;
					quantity = days;
					break;
				case 'Doctor Visit Charges':
					console.log('Adding Doctor Visit Charges');
					quantity = days;
					break;
				case 'Service Charges':
					console.log('Adding Service Charges');
					quantity = days;
					break;
				case 'Pollution Charges (bmw)':
					console.log('Adding Pollution Charges');
					quantity = days;
					break;
				case 'Registration Charges':
					console.log('Adding Registration Charges');
					quantity = 1; // One-time charge
					break;
			}


			const newService = {
				service_id: service.id,
				name: service.label,
				category: service.category,
				quantity: quantity,
				price: price,
				total: price * quantity
			};

			services.push(newService);
			updateServicesList();
			calculateTotal();
		}

		function updateServicesList() {
			const list = document.getElementById('servicesList');
			list.innerHTML = services.map((service, index) => `
                    <div class="service-item">
                        <span>${service.name}</span>
                        <input type="number" value="${service.quantity}" min="1" style="width: 60px;"
                               onchange="updateQuantity(${index}, this.value)">
                        <input type="number" value="${service.price.toFixed(2)}" min="0" step="0.01" style="width: 100px;"
                               onchange="updatePrice(${index}, this.value)">
                        <span>₹${service.total.toFixed(2)}</span>
                        <button type="button" class="btn-remove" onclick="removeService(${index})">Remove</button>
                    </div>
                `).join('');
		}

		function updatePrice(index, price) {
			price = parseFloat(price) || 0;
			services[index].price = price;
			services[index].total = price * services[index].quantity;
			updateServicesList();
			calculateTotal();
		}

		function updateQuantity(index, quantity) {
			quantity = parseInt(quantity) || 1;
			services[index].quantity = quantity;
			services[index].total = services[index].price * quantity;
			updateServicesList();
			calculateTotal();
		}

		function removeService(index) {
			services.splice(index, 1);
			updateServicesList();
			calculateTotal();
		}

		function calculateTotal() {
			const subtotal = services.reduce((sum, service) => sum + service.total, 0);
			document.getElementById('subtotal').textContent = subtotal.toFixed(2);

			const discount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
			const total = Math.max(0, subtotal - discount);
			
			// Calculate rounding
			const roundedTotal = Math.round(total);
			const roundingDiff = roundedTotal - total;
			
			document.getElementById('rounding_amount').textContent = roundingDiff.toFixed(2);
			document.getElementById('totalAmount').textContent = roundedTotal.toFixed(2);

			// Update card fee if card payment is selected
			if (document.getElementById('card_details')?.style.display === 'block') {
				calculateCardFee();
			}

			// Update split payment if active
			if (document.getElementById('split_details')?.style.display === 'block') {
				updateSplitTotal();
			}
		}

		function calculateFromAmount(amount) {
			const subtotal = parseFloat(document.getElementById('subtotal').textContent);
			const discount = parseFloat(amount) || 0;
			document.querySelector('input[name="discount_percent"]').value =
				((discount / subtotal) * 100).toFixed(2);
			calculateTotal();
		}

		function calculateFromPercent(percent) {
			const subtotal = parseFloat(document.getElementById('subtotal').textContent);
			const discountPercent = parseFloat(percent) || 0;
			const discount = subtotal * (discountPercent / 100);
			document.querySelector('input[name="discount"]').value = discount.toFixed(2);
			calculateTotal();
		}

		function handlePaymentType(type) {
			const selectedType = String(type);
			const visibleSectionByType = {
				'2': 'company_details',
				'6': 'card_details',
				'7': 'upi_details',
				'8': 'split_details'
			};

			document.querySelectorAll('.payment-details').forEach(section => {
				const isVisible = section.id === visibleSectionByType[selectedType];
				section.style.display = isVisible ? 'block' : 'none';

				section.querySelectorAll('input, select, textarea').forEach(field => {
					field.disabled = !isVisible;
					field.required = isVisible && field.dataset.required === 'true';
				});
			});

			if (selectedType === '8') {
				updateSplitTotal();
			} else {
				updateSplitReferenceValidation();
			}
		}

		function addVerificationSection(parentId) {
			const container = document.getElementById(parentId);
			if (!container) return;

			const verificationDiv = document.createElement('div');
			verificationDiv.className = 'verification-section';
			verificationDiv.innerHTML = `
                    <label>Amount Received:</label>
                    <input type="number" id="received_amount" step="0.01" onchange="verifyReceivedAmount()" placeholder="Enter received amount">
                    <div class="verification-status" id="verification_status"></div>
                `;
			container.appendChild(verificationDiv);
		}

		function verifyReceivedAmount() {
			const receivedAmount = parseFloat(document.getElementById('received_amount')?.value) || 0;
			const paymentType = document.querySelector('select[name="payment_type_id"]')?.value;
			let expectedAmount = parseFloat(document.getElementById('totalAmount')?.textContent) || 0;

			// For card payment, add fee to expected amount
			if (paymentType === '6') {
				const {
					fee
				} = calculateCardFee();
				expectedAmount += fee;
			}
			// For split payment, add card fee if any card payment
			else if (paymentType === '8') {
				const splitCardAmount = parseFloat(document.querySelector('input[name="split_card"]')?.value) || 0;
				if (splitCardAmount > 0) {
					const cardType = document.querySelector('select[name="split_card_type"]')?.value;
					const cardFeePercentage = cardType === 'credit' ? 0.035 : 0.015;
					const cardFee = splitCardAmount * cardFeePercentage;
					expectedAmount += cardFee;
				}
			}

			const status = document.getElementById('verification_status');
			const difference = Math.abs(receivedAmount - expectedAmount);

			if (difference < 0.01) {
				status.textContent = '✓ Amount Matches';
				status.className = 'verification-status match';
			} else {
				const shortOrExtra = receivedAmount > expectedAmount ? 'extra' : 'short';
				status.textContent =
					`⚠ Amount ${shortOrExtra} by ₹${Math.abs(receivedAmount - expectedAmount).toFixed(2)}`;
				status.className = 'verification-status mismatch';
			}
		}

		function calculateCardFee() {
			const totalAmount = parseFloat(document.getElementById('totalAmount')?.textContent) || 0;
			const cardType = document.querySelector('select[name="card_type"]')?.value || 'debit';
			const feePercentage = cardType === 'credit' ? 0.035 : 0.015;

			const fee = totalAmount * feePercentage;
			const finalAmount = totalAmount + fee;

			const feeElement = document.getElementById('convenience_fee');
			const finalElement = document.getElementById('final_with_fee');

			if (feeElement) feeElement.textContent = fee.toFixed(2);
			if (finalElement) finalElement.textContent = finalAmount.toFixed(2);

			return {
				fee,
				finalAmount
			};
		}

		function calculateSplitCardFee() {
			const cardInput = document.querySelector('input[name="split_card"]');
			const cardTypeSelect = document.querySelector('select[name="split_card_type"]');
			const splitCardFeeElement = document.getElementById('split_card_fee');
			const splitFinalTotalElement = document.getElementById('split_final_total');
			const splitTotalElement = document.getElementById('split_total');

			if (!cardInput || !cardTypeSelect || !splitCardFeeElement || !splitFinalTotalElement || !splitTotalElement) {
				console.error('One or more elements required for split card fee calculation are missing.');
				return;
			}

			const cardAmount = parseFloat(cardInput.value) || 0;
			const cardType = cardTypeSelect.value || 'credit';
			const cardFeePercentage = cardType === 'credit' ? 0.035 : 0.015;
			const cardFee = cardAmount * cardFeePercentage;

			const splitTotal = parseFloat(splitTotalElement.textContent) || 0;
			const totalWithFees = splitTotal + cardFee;

			splitCardFeeElement.textContent = cardFee.toFixed(2);
			splitFinalTotalElement.textContent = totalWithFees.toFixed(2);
		}

		function initializeSplitPayments() {
			const splitContainer = document.querySelector('.split-container');
			if (!splitContainer) return;

			splitContainer.innerHTML = `
                    <div class="split-info">
                        <div class="amount-due">Amount Due: ₹<span id="amount_due">0.00</span></div>
                        <div class="remaining">Remaining: ₹<span id="remaining_amount">0.00</span></div>
                    </div>
                    <div class="form-group">
                        <label>Cash Payment</label>
                        <input type="number" name="split_cash" value="0" min="0" step="0.01" onchange="updateSplitTotal()">
                    </div>
                    <div class="form-group">
                        <label>Card Payment</label>
                        <div class="card-section">
                            <select name="split_card_type" onchange="updateSplitTotal()">
                                <option value="debit">Debit Card (1.5% fee)</option>
                                <option value="credit">Credit Card (3.5% fee)</option>
                            </select>
                            <input type="number" name="split_card" value="0" min="0" step="0.01" onchange="updateSplitTotal()">
                            <input type="text" name="split_card_reference" placeholder="Card Reference Number">
                            <div class="fee-info">Card Fee: <span class="split-card-fee">0</span></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>UPI Payment</label>
                        <input type="number" name="split_upi" value="0" min="0" step="0.01" onchange="updateSplitTotal()">
                        <input type="text" name="split_upi_reference" placeholder="UPI Reference">
                    </div>
                    <div class="total-group">
                        <div class="split-totals">
                            <div>Split Total: <span id="split_total">0.00</span></div>
                            <div>With Fees: <span id="split_total_with_fees">0</span></div>
                        </div>
                    </div>
                `;

			// Initialize amount due
			const totalAmount = parseFloat(document.getElementById('totalAmount').textContent) || 0;
			document.getElementById('amount_due').textContent = totalAmount.toFixed(2);
			document.getElementById('remaining_amount').textContent = totalAmount.toFixed(2);
		}

		function updateSplitReferenceValidation() {
			const splitDetails = document.getElementById('split_details');
			const isSplitActive = splitDetails?.style.display === 'block';

			const splitCardInput = document.querySelector('input[name="split_card"]');
			const splitUpiInput = document.querySelector('input[name="split_upi"]');
			const splitCardReferenceInput = document.querySelector('input[name="split_card_reference"]');
			const splitUpiReferenceInput = document.querySelector('input[name="split_upi_ref"], input[name="split_upi_reference"]');

			const splitCardAmount = parseFloat(splitCardInput?.value) || 0;
			const splitUpiAmount = parseFloat(splitUpiInput?.value) || 0;

			if (splitCardReferenceInput) {
				splitCardReferenceInput.required = isSplitActive && splitCardAmount > 0;
				if (!splitCardReferenceInput.required) {
					splitCardReferenceInput.setCustomValidity('');
				}
			}

			if (splitUpiReferenceInput) {
				splitUpiReferenceInput.required = isSplitActive && splitUpiAmount > 0;
				if (!splitUpiReferenceInput.required) {
					splitUpiReferenceInput.setCustomValidity('');
				}
			}
		}

		function updateSplitTotal() {
			updateSplitReferenceValidation();

			const cashInput = document.querySelector('input[name="split_cash"]');
			const cardInput = document.querySelector('input[name="split_card"]');
			const upiInput = document.querySelector('input[name="split_upi"]');
			const cardTypeSelect = document.querySelector('select[name="split_card_type"]');

			const splitTotalElement = document.getElementById('split_total');
			const splitCardFeeElement = document.getElementById('split_card_fee');
			const splitFinalTotalElement = document.getElementById('split_final_total');
			const billTotalElement = document.getElementById('totalAmount');
			const remainingAmountElement = document.getElementById('remaining_amount');
			const amountDueElement = document.getElementById('amount_due');

			if (!cashInput || !cardInput || !upiInput || !cardTypeSelect || !splitTotalElement || !splitCardFeeElement || !
				splitFinalTotalElement || !billTotalElement) {
				console.error('One or more elements required for split payment calculation are missing.');
				return;
			}

			const cash = parseFloat(cashInput.value) || 0;
			const card = parseFloat(cardInput.value) || 0;
			const upi = parseFloat(upiInput.value) || 0;
			const cardType = cardTypeSelect.value || 'credit';

			const billTotal = parseFloat(billTotalElement.textContent) || 0;
			const cardFeePercentage = cardType === 'credit' ? 0.035 : 0.015;
			const cardFee = card * cardFeePercentage;

			const subtotal = cash + card + upi;
			const totalWithFees = subtotal + cardFee;
			const remaining = Math.max(0, billTotal - subtotal);

			splitTotalElement.textContent = subtotal.toFixed(2);
			splitCardFeeElement.textContent = cardFee.toFixed(2);
			splitFinalTotalElement.textContent = totalWithFees.toFixed(2);
			remainingAmountElement.textContent = remaining.toFixed(2);
			amountDueElement.textContent = (billTotal + cardFee).toFixed(2);

			// Update color based on total match
			splitTotalElement.style.color = Math.abs(subtotal - billTotal) > 0.01 ? '#dc3545' : '#28a745';
		}

		function goBack() {
			window.history.back();
		}
	</script>
</body>

</html>