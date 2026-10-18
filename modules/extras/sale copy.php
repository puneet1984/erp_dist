<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

date_default_timezone_set('Asia/Kolkata');


$fy = getCurrentFinancialYear();

// Get active doctors and companies
$conn = getDbConnection();
$doctors = $conn->query("SELECT d.doctor_id, d.name as doctor_name, d.dept_id, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        WHERE d.status = 'ACTIVE' 
                        ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);

$companies = $conn->query("SELECT company_id, company_name 
                          FROM company_accounts 
                          WHERE status = 'ACTIVE' 
                          ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    // Sanitize inputs
    $patient_id = sanitizeInput($_POST['patient_id']);
    $doctor_name = sanitizeInput($_POST['doctor_name']);
    $doctor_id = (int) $_POST['doctor_id'];
    $dept_id = (int) $_POST['dept_id'];
    $medicines = json_decode($_POST['medicines'], true);
    $payment_type_id = (int) $_POST['payment_type_id'];
    $discount_type = sanitizeInput($_POST['discount_type']);
    $discount_value = (float) $_POST['discount_value'];

    // Format sale_date as date
    $sale_date = $_POST['sale_date']; // Remove time component

    $conn = getDbConnection();

    // Validate doctor and department
    $stmt = $conn->prepare("SELECT d.dept_id FROM doctors d 
                           WHERE d.doctor_id = ? AND d.status = 'ACTIVE'");
    $stmt->bind_param('i', $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $doctor = $result->fetch_assoc();

    if (!$doctor) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid doctor selected']);
        exit;
    }

    if ($doctor['dept_id'] != $dept_id) {
        echo json_encode(['status' => 'error', 'message' => 'Department mismatch for selected doctor']);
        exit;
    }

    // Validate items exist and check stock in one query
    foreach ($medicines as $medicine) {
        $stmt = $conn->prepare('SELECT pi.item_id, pb.current_quantity, pb.batch_id 
                               FROM pharmacy_items pi 
                               JOIN pharmacy_batches pb ON pi.item_id = pb.item_id 
                               WHERE pi.item_id = ? AND pb.batch_number = ?');
        $stmt->bind_param('is', $medicine['item_id'], $medicine['batch_number']);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        if (!$item) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid medicine ID or batch: '.$medicine['name']]);
            exit;
        }
        if ($item['current_quantity'] < $medicine['quantity']) {
            echo json_encode(['status' => 'error', 'message' => 'Insufficient stock for '.$medicine['name'].'. Available: '.$item['current_quantity']]);
            exit;
        }
    }

    // Calculate total bill amount and validate discount
    $total_bill_amount = array_reduce($medicines, function ($sum, $medicine) {
        return $sum + $medicine['total'];
    }, 0);

    // Validate discount amount
    if ('percentage' === $discount_type) {
        if ($discount_value > 100) {
            echo json_encode(['status' => 'error', 'message' => 'Discount percentage cannot exceed 100%']);
            exit;
        }
        $total_discount = ($total_bill_amount * $discount_value / 100);
    } else {
        $total_discount = $discount_value;
    }

    if ($total_discount >= $total_bill_amount) {
        echo json_encode(['status' => 'error', 'message' => 'Discount amount cannot be greater than or equal to bill amount']);
        exit;
    }

    // Start transaction after all validations pass
    $conn->begin_transaction();
    try {
        // Generate transaction ID and pharmacy bill ID
        $bill_id = generatePharmacyBillId($conn);
        $transaction_id = generateTransactionId('PH');

        // Process each medicine with a transaction record
        $subtotal = 0;
        foreach ($medicines as $medicine) {
            // Calculate discounts
            $med_discount = ('percentage' === $discount_type)
                ? ($medicine['total'] * $discount_value / 100)
                : ($discount_value / count($medicines));
            $net_amount = $medicine['total'] - $med_discount;
            $subtotal += $medicine['total']; // Use original total for subtotal

            // Insert into pharmacy_transaction_items
            $stmt = $conn->prepare('INSERT INTO pharmacy_transaction_items 
                (transaction_id, batch_id, quantity, unit_price,
                 tax_percentage, tax_amount, discount_amount, final_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $tax_percentage = 0;
            $tax_amount = 0;
            $stmt->bind_param(
                'siiddddd',
                $transaction_id,
                $medicine['batch_id'],
                $medicine['quantity'],
                $medicine['price'],
                $tax_percentage,
                $tax_amount,
                $med_discount,
                $net_amount
            );
            if (!$stmt->execute()) {
                throw new Exception('Failed to record transaction item for '.$medicine['name']);
            }

            // Update pharmacy_batches with new quantity
            $stmt = $conn->prepare('UPDATE pharmacy_batches 
                SET current_quantity = current_quantity - ?
                WHERE batch_id = ? AND current_quantity >= ?');
            $stmt->bind_param(
                'iii',
                $medicine['quantity'],
                $medicine['batch_id'],
                $medicine['quantity']
            );
            if (!$stmt->execute() || 0 === $stmt->affected_rows) {
                throw new Exception('Stock update failed for '.$medicine['name']);
            }
        }

        // Calculate final amount before payment processing
        $final_amount = $subtotal - $discount_value;

        // Determine payment status and handle payments
        if (in_array($payment_type_id, [1, 6, 7])) { // CASH, CARD, UPI
            $payment_status = 'PAID';
            $amount_paid = $final_amount;

            if (1 == $payment_type_id) { // CASH
                $payment_details = [
                    'mode' => 'CASH',
                    'amount' => $final_amount,
                    'payment_status' => 'PAID',
                ];
            } elseif (6 == $payment_type_id) { // CARD
                $card_type = sanitizeInput($_POST['card_type']);
                $fee_percentage = ('credit' === $card_type) ? 0.035 : 0.015;
                $fee_amount = $final_amount * $fee_percentage;
                $final_with_fee = $final_amount + $fee_amount;

                $payment_details = [
                    'mode' => 'CARD',
                    'card_type' => $card_type,
                    'fee_percentage' => ('credit' === $card_type) ? 3.5 : 1.5,
                    'fee_amount' => $fee_amount,
                    'reference_no' => $_POST['card_reference'] ?? '',
                    'original_amount' => $final_amount,
                    'final_amount' => $final_with_fee,
                ];
                $final_amount = $final_with_fee; // Update total with fee
            } elseif (7 == $payment_type_id) { // UPI
                $payment_details = [
                    'mode' => 'UPI',
                    'amount' => $final_amount,
                    'reference_no' => $_POST['upi_ref'],
                    'payment_status' => 'PAID',
                ];
            }
        } elseif (2 == $payment_type_id) { // COMPANY_CREDIT
            $company_name = sanitizeInput($_POST['company_name']);
            $stmt = $conn->prepare("SELECT * FROM company_accounts WHERE company_name = ? AND status = 'ACTIVE'");
            $stmt->bind_param('s', $company_name);
            $stmt->execute();
            $company = $stmt->get_result()->fetch_assoc();

            if (!$company) {
                throw new Exception('Company not found or inactive');
            }

            $new_balance = $company['current_balance'] + $final_amount;
            if ($new_balance > $company['credit_limit']) {
                throw new Exception('Credit limit exceeded. Available credit: '.($company['credit_limit'] - $company['current_balance']));
            }

            $payment_status = 'PENDING';
            $payment_details = [
                'mode' => 'COMPANY_CREDIT',
                'company_name' => $company_name,
                'reference_no' => $_POST['company_ref'] ?? '',
                'credit_limit' => $company['credit_limit'],
                'current_balance' => $company['current_balance'],
                'payment_days' => $company['payment_days'],
            ];
        } elseif (8 == $payment_type_id) { // SPLIT
            $split_cash = sanitizeNumeric($_POST['split_cash']);
            $split_card = sanitizeNumeric($_POST['split_card']);
            $split_upi = sanitizeNumeric($_POST['split_upi']);
            $card_type = $_POST['split_card_type'];

            $card_fee = $split_card * (('credit' === $card_type) ? 0.035 : 0.015);
            $split_total = $split_cash + $split_card + $split_upi;

            $payment_status = ($split_total >= $final_amount) ? 'PAID' : 'PARTIAL';
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
                'total_with_fees' => $split_total + $card_fee,
            ];
            $final_amount = $split_total + $card_fee;
        } elseif (9 == $payment_type_id) { // IPD
            // Get active admission for the patient
            $stmt = $conn->prepare('SELECT a.admission_id, a.admission_date, p.name as patient_name 
                                  FROM ipd_admissions a
                                  JOIN patients p ON a.patient_id = p.patient_id 
                                  WHERE a.patient_id = ? 
                                  ORDER BY a.admission_date DESC ');
            $stmt->bind_param('s', $patient_id);
            $stmt->execute();
            $admission = $stmt->get_result()->fetch_assoc();

            if (!$admission) {
                throw new Exception('No active admission found for this patient');
            }

            $payment_status = 'PENDING';
            $payment_details = [
                'mode' => 'IPD',
                'admission_id' => $admission['admission_id'],
                'amount' => $final_amount,
                'bill_date' => $sale_date,
                'patient_id' => $patient_id,
                'status' => 'PENDING',
            ];
            $admission_id = $admission['admission_id'];
        }

        $payment_details_json = json_encode($payment_details);

        // Insert main pharmacy_sales record
        $stmt = $conn->prepare('INSERT INTO pharmacy_sales 
            (transaction_id, bill_id, FY, patient_id, doctor_id, sale_date,
             subtotal, discount_amount, discount_type, tax_amount, 
             final_amount, payment_type_id, payment_details, payment_status,
             admission_id, created_by)  /* 16 columns */
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');  /* 16 placeholders */

        $admission_id = (9 == $payment_type_id) ? $admission['admission_id'] : null;

        $stmt->bind_param(
            'sssiisddsddsssii',  /* 16 types */
            $transaction_id,      /* s */
            $bill_id,            /* s */
            $fy,                 /* s */
            $patient_id,         /* i */
            $doctor_id,          /* i */
            $sale_date,          /* s */
            $subtotal,           /* d */
            $discount_value,     /* d */
            $discount_type,      /* s */
            $tax_amount,         /* d */
            $final_amount,       /* d */
            $payment_type_id,    /* s */
            $payment_details_json, /* s */
            $payment_status,     /* s */
            $admission_id,       /* i */
            $_SESSION['user_id'] /* i */
        );
        $stmt->execute();

        $conn->commit();
        echo json_encode([
            'status' => 'success',
            'transaction_id' => $transaction_id, // Changed from bill_id to transaction_id
            'total' => $final_amount,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Calculate card fee based on amount and card type
function calculateCardFee($amount, $cardType = 'debit')
{
    $feePercentage = ('credit' === strtolower($cardType)) ? 3.5 : 1.5;

    return round(($amount * $feePercentage) / 100, 2);
}

// Add helper function for split payments
function insertSplitPayment($conn, $collection_id, $payment_type_id, $amount, $reference = null)
{
    $stmt = $conn->prepare('INSERT INTO split_payments 
        (collection_id, payment_type_id, amount, payment_details, reference_no)
        VALUES (?, ?, ?, ?, ?)');
    $payment_details = json_encode([
        'type' => 6 == $payment_type_id ? $reference : null, // card_type for card payments
        'reference' => 7 == $payment_type_id ? $reference : null, // upi_ref for UPI payments
    ]);
    $stmt->bind_param('iidss', $collection_id, $payment_type_id, $amount, $payment_details, $reference);
    $stmt->execute();
}

// Get available batches for the selected medicine
if (isset($_GET['item_id'])) {
    $conn = getDbConnection();
    $stmt = $conn->prepare('SELECT batch_id, batch_number, current_quantity, 
                           retail_price, expiry_date, pi.name, pi.manufacturer, 
                           pi.active_ingredient, pi.dosage_form
                           FROM pharmacy_batches pb 
                           JOIN pharmacy_items pi ON pb.item_id = pi.item_id
                           WHERE pb.item_id = ? AND pb.current_quantity > 0
                           ORDER BY pb.expiry_date ASC');
    $stmt->bind_param('i', $_GET['item_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Medicine Sale</title>
	<link rel="stylesheet" href="../../assets/css/pharmacy-sale.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js" crossorigin="anonymous"></script>
	<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
	<script src="../../assets/js/tax_calculator.js"></script>
	<script src="../../assets/js/generate_bill.js"></script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Medicine Sale Entry</h2>
		<form id="saleForm" method="POST">
			<!-- Patient and Doctor Info section -->
			<div class="form-row patient-doctor-row">
				<div class="form-group sale-date">
					<label>Sale Date:</label>
					<input type="date" name="sale_date" required
						value="<?php echo date('Y-m-d'); ?>"
						max="<?php echo date('Y-m-d'); ?>">
				</div>
				<div class="form-group patient-id">
					<label>Patient ID:</label>
					<div class="input-group">
						<input type="text" id="patient_search" name="patient_id" required placeholder="Enter Patient ID"
							onblur="searchPatient()" title="Enter valid Patient ID">
					</div>
				</div>
				<div class="form-group patient-name">
					<label>Patient Name:</label>
					<input type="text" id="patientName" readonly class="readonly-field"
						placeholder="Will be auto-populated">
				</div>
				<div class="form-group doctor-name">
					<label>Doctor:</label>
					<input type="hidden" name="doctor_id" id="doctor_id">
					<input type="hidden" name="dept_id" id="dept_id">
					<select name="doctor_name" class="doctor-select" required onchange="updateDoctorDetails(this)">
						<option value="">Select Doctor</option>
						<?php foreach ($doctors as $doctor) { ?>
						<option
							value="Dr. <?php echo htmlspecialchars($doctor['doctor_name']); ?>"
							data-doctor-id="<?php echo htmlspecialchars($doctor['doctor_id']); ?>"
							data-dept-id="<?php echo htmlspecialchars($doctor['dept_id']); ?>">
							Dr.
							<?php echo htmlspecialchars($doctor['doctor_name']); ?>
							(<?php echo htmlspecialchars($doctor['dept_name']); ?>)
						</option>
						<?php } ?>
					</select>
				</div>
			</div>

			<!-- Medicine Selection section -->
			<div class="form-row medicine-entry-row">
				<div class="form-group medicine-select-group">
					<label>Medicine:</label>
					<select class="medicine-select" style="width: 100%"></select>
				</div>
				<div class="form-group batch-select-group">
					<label>Batch:</label>
					<select id="batch_select">
						<option value="">Select batch</option>
					</select>
				</div>
				<div class="form-group quantity-group">
					<label>Quantity:</label>
					<input type="number" id="quantity" min="1">
				</div>
				<div class="form-group add-btn-group">
					<label>&nbsp;</label>
					<button type="button" class="btn-add" onclick="addMedicineToTable()">Add Medicine</button>
				</div>
			</div>

			<!-- Selected Medicines List -->
			<div id="medicines">
				<h3>Selected Medicines</h3>
				<div class="medicine-list">
					<div class="medicine-header">
						<span>Medicine</span>
						<span>Batch</span>
						<span>Expiry</span>
						<span>Quantity</span>
						<span>Price</span>
						<span>Total</span>
						<span>Action</span>
					</div>
					<div id="medicinesList"></div>
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
						<label>Discount Amount:</label>
						<div class="input-group">
							<input type="number" name="discount" value="0" min="0" step="0.01" style="width: 100px;"
								onchange="calculateFromAmount(this.value)">
						</div>
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
			</div>

			<!-- Payment Section -->
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

				<!-- Payment Details Sections -->
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
							<input type="text" name="card_reference" pattern="[a-zA-Z0-9]{1,20}"
								placeholder="Enter card reference number" data-required="true">
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

				<div id="split_details" class="payment-details" style="display:none;">
					<div class="split-container">
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

				<div id="ipd_details" class="payment-details" style="display:none;">
					<div class="form-group">
						<label>Select Admission:</label>
						<select id="admission_select" name="admission_id" class="form-select">
							<option value="">Select admission</option>
						</select>
						<div class="admission-details">
							<div class="detail-row">
								<span class="detail-label">Patient Name:</span>
								<span class="detail-value" id="admitted_patient_name_display">-</span>
							</div>
							<div class="detail-row">
								<span class="detail-label">Admission Date:</span>
								<span class="detail-value" id="admission_date_display">-</span>
							</div>
							<p class="info-text" id="ipd_status_message">Please enter patient ID first</p>
						</div>
					</div>
				</div>
			</div>
			<button type="submit" class="btn-submit">Complete Sale</button>
		</form>
	</div>

	<script>
		let selectedMedicines = [];

		function initializeSelect2() {
			$('.medicine-select').select2({
				ajax: {
					url: '../../includes/helpers/medicine_helper.php',
					dataType: 'json',
					delay: 250,
					data: function(params) {
						return {
							q: params.term,
							type: 'sale'
						};
					},
					processResults: function(data) {
						if (!data || !data.results) {
							return {
								results: []
							};
						}
						return {
							results: data.results.map(item => ({
								id: item.id || item.name,
								text: item.text || item.name,
								data: item
							}))
						};
					},
					cache: true
				},
				minimumInputLength: 2,
				placeholder: 'Search medicine...'
			}).on('select2:select', function(e) {
				const data = e.params.data;
				fetchBatches(data.id);
			});
		}

		function addMedicineToTable() {
			const medicine = $('.medicine-select').select2('data')[0];
			const batch = $('#batch_select option:selected');
			const quantity = parseInt($('#quantity').val());

			if (!medicine || !batch.val() || !quantity) {
				alert('Please select medicine, batch and quantity');
				return;
			}

			// Check if medicine with same batch already exists
			const existingIndex = selectedMedicines.findIndex(med =>
				med.item_id === medicine.id && med.batch_number === batch.val()
			);

			if (existingIndex !== -1) {
				// Update quantity of existing entry
				selectedMedicines[existingIndex].quantity += quantity;
				selectedMedicines[existingIndex].total = selectedMedicines[existingIndex].quantity * selectedMedicines[
					existingIndex].price;
			} else {
				// Add new entry
				const medicineData = {
					item_id: medicine.id,
					name: medicine.text,
					batch_number: batch.val(),
					batch_id: batch.data('batch-id'),
					expiry_date: batch.data('expiry'),
					quantity: quantity,
					price: parseFloat(batch.data('price')),
					total: quantity * parseFloat(batch.data('price'))
				};
				selectedMedicines.push(medicineData);
			}

			updateMedicinesTable();
			resetMedicineSelection();
			calculateTotals();
		}

		function updateMedicinesTable() {
			const list = document.getElementById('medicinesList');
			list.innerHTML = selectedMedicines.map((med, index) => `
                <div class="medicine-item">
                    <span>${med.name}</span>
                    <span>${med.batch_number}</span>
                    <span>${formatDate(med.expiry_date)}</span>
                    <span><input type="number" value="${med.quantity}" min="1" onchange="updateQuantity(${index}, this.value)"></span>
                    <span>₹${med.price.toFixed(2)}</span>
                    <span>₹${med.total.toFixed(2)}</span>
                    <span><button type="button" class="btn-remove" onclick="removeMedicine(${index})">Remove</button></span>
                </div>
            `).join('');
		}

		function removeMedicine(index) {
			selectedMedicines.splice(index, 1);
			updateMedicinesTable();
			calculateTotals();
		}

		function calculateTotals() {
			const subtotal = selectedMedicines.reduce((sum, med) => sum + med.total, 0);
			$('#subtotal, #final_subtotal').text(subtotal.toFixed(2));
			updateAmounts();
		}

		function updateAmounts() {
			const subtotal = parseFloat(document.getElementById('subtotal').textContent);
			const discountAmount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
			const discountPercent = parseFloat(document.querySelector('input[name="discount_percent"]').value) || 0;

			// Calculate discount
			let finalDiscount = 0;
			if (discountAmount > 0) {
				finalDiscount = discountAmount;
			} else if (discountPercent > 0) {
				finalDiscount = (subtotal * discountPercent) / 100;
			}

			// Calculate net amount after discount
			const net = Math.max(0, subtotal - finalDiscount);
			document.getElementById('totalAmount').textContent = net.toFixed(2);

			// Update card fee if card payment is selected
			if (document.getElementById('card_details').style.display === 'block') {
				calculateCardFee(net); // Pass net amount to calculate fee
			}
			if (document.getElementById('split_details').style.display === 'block') {
				updateSplitTotal();
			}
		}

		function calculateFromAmount(value) {
			// Reset percentage input
			document.querySelector('input[name="discount_percent"]').value = '0';
			updateAmounts();
		}

		function calculateFromPercent(value) {
			// Reset amount input
			document.querySelector('input[name="discount"]').value = '0';
			updateAmounts();
		}

		function updateQuantity(index, newQuantity) {
			const quantity = parseInt(newQuantity) || 1;
			selectedMedicines[index].quantity = quantity;
			selectedMedicines[index].total = quantity * selectedMedicines[index].price;
			updateMedicinesTable();
			calculateTotals();
		}

		function resetMedicineSelection() {
			$('.medicine-select').val(null).trigger('change');
			$('#batch_select').val('');
			$('#quantity').val('');
		}

		function formatDate(dateString) {
			if (!dateString) return '-';
			const date = new Date(dateString);
			return date.toLocaleDateString('en-IN', {
				day: '2-digit',
				month: 'short',
				year: 'numeric'
			});
		}

		function fetchBatches(itemId) {
			$.get('sale.php', {
					item_id: itemId
				})
				.done(function(response) {
					const batches = JSON.parse(response);
					const select = $('#batch_select');
					select.empty().append('<option value="">Select batch</option>');

					batches.forEach(batch => {
						select.append(`<option value="${batch.batch_number}" 
                                            data-batch-id="${batch.batch_id}"
                                            data-price="${batch.retail_price}"
                                            data-expiry="${batch.expiry_date}"
                                            data-stock="${batch.current_quantity}">
                                            Batch: ${batch.batch_number} | Stock: ${batch.current_quantity} | 
                                            Exp: ${formatDate(batch.expiry_date)} | ₹${batch.retail_price}
                                        </option>`);
					});
					// Auto-select the first batch (earliest expiry) if available
					if (batches.length > 0) {
						select.val(batches[0].batch_number);
						// Focus on quantity field for faster data entry
						$('#quantity').focus();
					}
				});
		}

		function handlePaymentType(type) {
			document.querySelectorAll('.payment-details').forEach(div => div.style.display = 'none');

			// Remove required from all payment inputs
			document.querySelectorAll('[data-required="true"]').forEach(input => {
				input.removeAttribute('required');
			});

			// Reset admission select required state
			const admissionSelect = document.getElementById('admission_select');
			if (admissionSelect) {
				admissionSelect.removeAttribute('required');
			}

			switch (type) {
				case '1': // CASH
					break;
				case '2': // COMPANY_CREDIT
					document.getElementById('company_details').style.display = 'block';
					document.querySelector('select[name="company_name"]').setAttribute('required', 'required');
					break;
				case '6': // CARD
					document.getElementById('card_details').style.display = 'block';
					document.querySelector('input[name="card_reference"]').setAttribute('required', 'required');
					break;
				case '7': // UPI
					document.getElementById('upi_details').style.display = 'block';
					document.querySelector('input[name="upi_ref"]').setAttribute('required', 'required');
					break;
				case '8': // SPLIT
					document.getElementById('split_details').style.display = 'block';
					break;
				case '9': // IPD
					document.getElementById('ipd_details').style.display = 'block';
					admissionSelect.setAttribute('required', 'required');
					checkIpdAdmission();
					break;
			}
			if (type === '9') {
				checkIpdAdmission();
			}
		}

		function checkIpdAdmission() {
			const patientId = document.getElementById('patient_search').value;

			if (!patientId) {
				document.getElementById('ipd_status_message').textContent = 'Please enter patient ID first';
				clearAdmissionDetails();
				return;
			}
			$.ajax({
				url: '../../includes/helpers/patient_helper.php',
				data: {
					action: 'check_admission',
					patient_id: patientId
				},
				method: 'GET',
				success: function(response) {
					const data = JSON.parse(response);
					const admissionSelect = document.getElementById('admission_select');
					admissionSelect.innerHTML = '<option value="">Select admission</option>';

					if (data.status === 'success' && data.admissions && data.admissions.length > 0) {
						data.admissions.forEach(admission => {
							const option = document.createElement('option');
							option.value = admission.admission_id;
							option.dataset.name = admission.patient_name;
							option.dataset.date = admission.admission_date;
							option.dataset.discharge = admission.discharge_date || '';
							option.dataset.status = admission.status;

							const status = admission.status === 'Admitted' ? '(Active)' : '(Discharged)';
							const dischargeDateStr = admission.discharge_date ?
								` - Discharged: ${formatDate(admission.discharge_date)}` : '';

							option.textContent =
								`ID: ${admission.admission_id} - ${formatDate(admission.admission_date)}${dischargeDateStr} ${status}`;
							admissionSelect.appendChild(option);
						});
						document.getElementById('ipd_status_message').textContent = 'Please select an admission';
					} else {
						clearAdmissionDetails();
						document.getElementById('ipd_status_message').textContent =
							'No admissions found for this patient';
					}
				}
			});
		}

		// Add event listener for admission selection
		document.getElementById('admission_select').addEventListener('change', function() {
			const selected = this.options[this.selectedIndex];
			if (selected.value) {
				const dischargeDate = selected.dataset.discharge;
				const admissionDate = selected.dataset.date;
				const saleDateInput = document.querySelector('input[name="sale_date"]');
				const status = selected.dataset.status;

				// Set appropriate date based on admission status
				if (status === 'Admitted') {
					// For active admissions, set current date in IST
					const today = new Date();
					today.setMinutes(today.getMinutes() + 330); // Add IST offset
					// Parse the formatted date back to YYYY-MM-DD
					const formattedDate = formatDate(today);
					const [day, month, year] = formattedDate.split(' ');
					const monthNum = new Date(Date.parse(month + " 1, " + year)).getMonth() + 1;
					saleDateInput.value = `${year}-${String(monthNum).padStart(2, '0')}-${day}`;
				} else if (dischargeDate) {
					// For discharged patients, convert discharge date
					const localDate = new Date(dischargeDate);
					localDate.setMinutes(localDate.getMinutes() + 330); // Add IST offset
					const formattedDate = formatDate(localDate);
					const [day, month, year] = formattedDate.split(' ');
					const monthNum = new Date(Date.parse(month + " 1, " + year)).getMonth() + 1;
					saleDateInput.value = `${year}-${String(monthNum).padStart(2, '0')}-${day}`;
				}

				document.getElementById('admitted_patient_name_display').textContent = selected.dataset.name;
				document.getElementById('admission_date_display').textContent = formatDate(selected.dataset.date);

				// Show appropriate message based on status
				const statusMessage = status === 'Admitted' ?
					'Bill will be linked to this active admission' :
					'Bill will be linked to this past admission';
				document.getElementById('ipd_status_message').textContent = statusMessage;
			} else {
				document.getElementById('admitted_patient_name_display').textContent = '-';
				document.getElementById('admission_date_display').textContent = '-';
				document.getElementById('ipd_status_message').textContent = 'Please select an admission';
			}
		});

		function searchPatient() {
			const patientId = document.getElementById('patient_search').value;
			if (!patientId) {
				document.getElementById('patientName').value = '';
				clearAdmissionDetails();
				return;
			}
			$.ajax({
				url: '../../includes/helpers/patient_helper.php',
				data: {
					action: 'search_patient',
					patient_id: patientId
				},
				method: 'GET',
				success: function(response) {
					const data = JSON.parse(response);
					if (data.status === 'success') {
						document.getElementById('patientName').value = data.patient.name;
						if (document.getElementById('ipd_details').style.display === 'block') {
							checkIpdAdmission();
						}
					} else {
						document.getElementById('patientName').value = '';
						clearAdmissionDetails();
						alert('Error: ' + data.message);
					}
				},
				error: function() {
					document.getElementById('patientName').value = '';
					clearAdmissionDetails();
					alert('Error searching for patient');
				}
			});
		}


		function clearAdmissionDetails() {
			// Only clear elements that exist in the DOM
			const elements = {
				'admitted_patient_name_display': '-',
				'admission_date_display': '-',
				'ipd_status_message': 'Please enter patient ID first'
			};

			for (const [id, value] of Object.entries(elements)) {
				const element = document.getElementById(id);
				if (element) {
					element.textContent = value;
				}
			}
		}


		function updateDoctorDetails(selectElement) {
			const selectedOption = selectElement.options[selectElement.selectedIndex];
			document.getElementById('doctor_id').value = selectedOption.getAttribute('data-doctor-id');
			document.getElementById('dept_id').value = selectedOption.getAttribute('data-dept-id');
		}

		$(document).ready(function() {
			initializeSelect2();
			handlePaymentType(1);
		});

		$('#saleForm').on('submit', function(e) {
			e.preventDefault();
			if (!validateForm()) return;

			const discountAmount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
			const discountPercent = parseFloat(document.querySelector('input[name="discount_percent"]')
				.value) || 0;

			// Create FormData and add medicines data
			const formData = new FormData(this);
			formData.append('medicines', JSON.stringify(selectedMedicines));
			formData.append('discount_type', discountAmount > 0 ? 'amount' : 'percentage');
			formData.append('discount_value', discountAmount > 0 ? discountAmount : discountPercent);

			if (confirm(`Confirm sale for ₹${$('#totalAmount').text()}?`)) {
				$.ajax({
					url: 'sale.php',
					method: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						try {
							const data = JSON.parse(response);
							if (data.status === 'success') {
								if (confirm(
										'Sale recorded successfully. Do you want to print the bill?'
									)) {
									window.open(`print_pharmacy_bill.php?id=${data.transaction_id}`,
										'_blank');
								}
								location.reload();
							} else {
								alert('Error: ' + data.message);
							}
						} catch (e) {
							console.error('Response parsing failed:', response);
							alert(
								'Error: Invalid server response. Please check the console for details.'
							);
						}
					},
					error: function(xhr, status, error) {
						console.error('Ajax request failed:', {
							xhr,
							status,
							error
						});
						alert('Error: Failed to process the sale. Please try again.');
					}
				});
			}
		});

		function validateForm() {
			if (selectedMedicines.length === 0) {
				alert('Please add at least one medicine to the bill');
				return false;
			}

			const saleDate = document.querySelector('input[name="sale_date"]').value;
			if (!saleDate) {
				alert('Please select a sale date');
				return false;
			}

			// Create Date objects and set to midnight for comparison
			const today = new Date();
			today.setHours(0, 0, 0, 0);
			const billDate = new Date(saleDate + 'T00:00:00'); // Force midnight

			if (billDate.getTime() > today.getTime()) {
				alert('Sale date cannot be in the future');
				return false;
			}

			const paymentType = document.querySelector('select[name="payment_type_id"]').value;

			// Validate IPD admission dates
			if (paymentType === '9') {
				const selectedAdmission = document.getElementById('admission_select').selectedOptions[0];
				if (!selectedAdmission || !selectedAdmission.value) {
					alert('Please select an admission');
					return false;
				}

				const dischargeDate = selectedAdmission.dataset.discharge;
				if (dischargeDate) {
					const dischargeDateObj = new Date(dischargeDate);
					if (billDate > dischargeDateObj) {
						alert('Sale date cannot be after discharge date');
						return false;
					}
				}
			}

			// Validate split payment
			if (paymentType === '8') {
				const total = parseFloat(document.getElementById('totalAmount').textContent);
				const splitTotal = parseFloat(document.getElementById('split_total').textContent);
				if (Math.abs(total - splitTotal) > 0.01) {
					alert('Split payment amounts must equal the total bill amount');
					return false;
				}
			}

			return true;
		}
	</script>
</body>

</html>