<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check pharmacy permissions
if (!checkPermission('pharmacy', 'create')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

if (!checkSessionTimeout()) {
    redirectToLogin('timeout=1');
}


// Remove the generatePharmacyBillId function as it's now in helpers.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        $patient_id = $_POST['patient_id'];
        $bill_id = generatePharmacyBillId($conn);
        $items = json_decode($_POST['items'], true);
        $total = array_sum(array_column($items, 'total'));
        $discount = $_POST['discount'] ?? 0;
        $final = $total - $discount;

        // Insert pharmacy bill
        $stmt = $conn->prepare("INSERT INTO pharmacy_bills 
            (bill_id, bill_date, patient_id, total_amount, discount, final_amount, payment_method, payment_status) 
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?, 'PAID')");

        $stmt->bind_param("ssddds", $bill_id, $patient_id, $total, $discount, $final, $_POST['payment_method']);
        $stmt->execute();

        // Record items and update inventory
        foreach ($items as $item) {
            // Insert bill item
            $stmt = $conn->prepare("INSERT INTO pharmacy_bill_items 
                (bill_id, item_id, batch_id, doctor_id, quantity, unit_price, total) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                "siiiidd",
                $bill_id,
                $item['item_id'],
                $item['batch_id'],
                $_POST['doctor_id'],
                $item['quantity'],
                $item['price'],
                $item['total']
            );
            $stmt->execute();

            // Update batch quantity
            $stmt = $conn->prepare("UPDATE pharmacy_batches 
                SET quantity = quantity - ? 
                WHERE batch_id = ?");
            $stmt->bind_param("ii", $item['quantity'], $item['batch_id']);
            $stmt->execute();
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'bill_id' => $bill_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
$conn = getDbConnection();
$doctors = $conn->query("SELECT d.*, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        WHERE d.status = 'ACTIVE' 
                        ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Pharmacy Bill</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
	<script>
		$(document).ready(function() {
			$("#medicineSearch").autocomplete({
				source: function(request, response) {
					$.getJSON("get_medicines.php", {
						term: request.term
					}, response);
				},
				minLength: 2,
				select: function(event, ui) {
					addMedicineToList(ui.item);
					$(this).val('');
					return false;
				}
			});
		});

		function addMedicineToList(item) {
			const medicineRow = `
                <div class="medicine-item" data-id="${item.id}" data-batch="${item.batch_id}">
                    <span class="name">${item.value}</span>
                    <span class="batch">Batch: ${item.batch_number}</span>
                    <span class="expiry">Exp: ${item.expiry_date}</span>
                    <input type="number" class="quantity" max="${item.stock}" value="1">
                    <span class="price">₹${item.price}</span>
                    <span class="total">₹${item.price}</span>
                    <button type="button" onclick="removeMedicine(this)">Remove</button>
                    <input type="hidden" name="batches[]" value="${item.batch_id}">
                    <input type="hidden" name="expiry_dates[]" value="${item.expiry_date_raw}">
                </div>`;

			document.querySelector('.medicine-list').insertAdjacentHTML('beforeend', medicineRow);
			calculateTotal();
		}

		function updateDepartment(doctorId) {
			const option = document.querySelector(`select[name="doctor_id"] option[value="${doctorId}"]`);
			document.getElementById('dept_id').value = option.dataset.dept;
		}
	</script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Generate Pharmacy Bill</h2>
		<form id="pharmacyBillForm">
			<div class="form-group"></div>
			<label>Patient ID:</label>
			<input type="text" name="patient_id" required>
			<button type="button" onclick="searchPatient()">Search</button>
	</div>

	<div class="form-group">
		<label>Prescribed By:</label>
		<select name="doctor_id" required onchange="updateDepartment(this.value)">
			<option value="">Select Doctor</option>
			<?php foreach ($doctors as $doctor): ?>
			<option
				value="<?= $doctor['doctor_id'] ?>"
				data-dept="<?= $doctor['dept_id'] ?>">
				Dr.
				<?= htmlspecialchars($doctor['name']) ?>
				(<?= htmlspecialchars($doctor['dept_name']) ?>)
			</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div id="medicineList">
		<h3>Medicines</h3>
		<div class="medicine-search">
			<input type="text" id="medicineSearch" placeholder="Search medicines...">
		</div>
		<div class="medicine-list"></div>
	</div>

	<div class="billing-summary">
		<h3>Billing Summary</h3>
		<div class="summary-details">
			<div class="form-group">
				<label>Total Amount:</label>
				<span id="totalAmount">₹0.00</span>
			</div>
			<div class="form-group">
				<label>Discount:</label>
				<input type="number" name="discount" id="discount" value="0" min="0" step="0.01"
					onchange="updateFinalAmount()">
			</div>
			<div class="form-group">
				<label>Final Amount:</label>
				<span id="finalAmount">₹0.00</span>
			</div>
			<div class="form-group">
				<label>Payment Method:</label>
				<select name="payment_method" required>
					<option value="CASH">Cash</option>
					<option value="CARD">Card</option>
					<option value="UPI">UPI</option>
					<option value="INSURANCE">Insurance</option>
				</select>
			</div>
		</div>
		<button type="submit" class="btn-primary">Generate Bill</button>
	</div>
	</form>
	</div>
</body>

</html>