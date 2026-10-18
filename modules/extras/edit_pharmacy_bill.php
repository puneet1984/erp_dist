<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'edit')) {
    exit('Access Denied');
}

if (!isset($_GET['id'])) {
    die('Transaction ID not provided');
}

$transaction_id = sanitizeInput($_GET['id']);
$conn = getDbConnection();

// Check if bill is editable
$stmt = $conn->prepare("SELECT payment_status FROM pharmacy_sales WHERE transaction_id = ?");
$stmt->bind_param("s", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();
$sale = $result->fetch_assoc();

// if (!$sale || 'PENDING' !== $sale['payment_status']) {
//     die('Bill cannot be edited');
// }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Get current bill items to compare changes
        $stmt = $conn->prepare("
            SELECT pti.*, pb.current_quantity, pb.batch_id 
            FROM pharmacy_transaction_items pti
            JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
            WHERE pti.transaction_id = ?
        ");
        $stmt->bind_param("s", $transaction_id);
        $stmt->execute();
        $current_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Process updated items
        $updated_items = json_decode($_POST['items'], true);
        $subtotal = 0;

        // Calculate total bill amount for validation
        $total_bill_amount = array_reduce($updated_items, function($sum, $item) {
            return $sum + ($item['quantity'] * $item['unit_price']);
        }, 0);

        // Validate discount amount
        $total_discount = floatval($_POST['discount'] ?? 0);
        if ($total_discount >= $total_bill_amount) {
            throw new Exception('Discount amount cannot be greater than or equal to bill amount');
        }

        // Process items after validation passes
        // First revert all quantities back to stock
        foreach ($current_items as $item) {
            $new_quantity = $item['current_quantity'] + $item['quantity'];
            $stmt = $conn->prepare("UPDATE pharmacy_batches SET current_quantity = ? WHERE batch_id = ?");
            $stmt->bind_param("ii", $new_quantity, $item['batch_id']);
            $stmt->execute();
        }

        // Clear existing transaction items
        $stmt = $conn->prepare("DELETE FROM pharmacy_transaction_items WHERE transaction_id = ?");
        $stmt->bind_param("s", $transaction_id);
        $stmt->execute();

        // Insert updated items and adjust stock
        foreach ($updated_items as $item) {
            // Validate stock availability
            $stmt = $conn->prepare("SELECT current_quantity FROM pharmacy_batches WHERE batch_id = ?");
            $stmt->bind_param("i", $item['batch_id']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result['current_quantity'] < $item['quantity']) {
                throw new Exception("Insufficient stock for batch ID: " . $item['batch_id']);
            }

            // Update batch quantity
            $new_quantity = $result['current_quantity'] - $item['quantity'];
            $stmt = $conn->prepare("UPDATE pharmacy_batches SET current_quantity = ? WHERE batch_id = ?");
            $stmt->bind_param("ii", $new_quantity, $item['batch_id']);
            $stmt->execute();

            // Insert new transaction item
            $stmt = $conn->prepare("
                INSERT INTO pharmacy_transaction_items 
                (transaction_id, batch_id, quantity, unit_price, 
                tax_percentage, tax_amount, discount_amount, final_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $tax_amount = ($item['unit_price'] * $item['quantity'] * $item['tax_percentage']) / 100;
            $final_amount = ($item['unit_price'] * $item['quantity']) + $tax_amount - $item['discount'];

            $stmt->bind_param("siidddddd",
                $transaction_id,
                $item['batch_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['tax_percentage'],
                $tax_amount,
                $item['discount'],
                $final_amount
            );
            $stmt->execute();

            $subtotal += ($item['unit_price'] * $item['quantity']);
        }

        // Update main sale record
        $total_discount = floatval($_POST['discount'] ?? 0);
        $final_amount = $subtotal + $total_tax - $total_discount;

        $stmt = $conn->prepare("
            UPDATE pharmacy_sales SET
            patient_id = ?,
            doctor_id = ?,
            subtotal = ?,
            tax_amount = ?,
            discount_amount = ?,
            final_amount = ?,
            updated_by = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE transaction_id = ?
        ");

        $stmt->bind_param("iidddsis",
            $_POST['patient_id'],
            $_POST['doctor_id'],
            $subtotal,
            $total_tax,
            $total_discount,
            $final_amount,
            $_SESSION['user_id'],
            $transaction_id
        );
        $stmt->execute();

        $conn->commit();
        header('Location: view_pharmacy_bill.php?id=' . $transaction_id);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        die('Error: ' . $e->getMessage());
    }
}

// Get existing bill data
$stmt = $conn->prepare("
    SELECT ps.*, p.name as patient_name,
           d.name as doctor_name,
           pi.name as medicine_name, pi.manufacturer,
           pb.batch_number, pb.expiry_date,
           pti.quantity, pti.unit_price, pti.discount_amount,
           pt.type_name as payment_type
    FROM pharmacy_sales ps
    JOIN patients p ON ps.patient_id = p.patient_id
    LEFT JOIN doctors d ON ps.doctor_id = d.doctor_id
    JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id
    JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    LEFT JOIN payment_types pt ON ps.payment_type_id = pt.payment_type_id
    WHERE ps.transaction_id = ?
");

$stmt->bind_param("s", $transaction_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch doctors for selection
$doctors = $conn->query("SELECT d.doctor_id, d.name as doctor_name, d.dept_id, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        WHERE d.status = 'ACTIVE' 
                        ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Pharmacy Bill</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../../assets/js/medicine_sale.js"></script>
    <script src="../../assets/js/generate_bill.js"></script>
    <style>
        .medicine-entry-row {
            display: grid;
            grid-template-columns: 3fr 2fr 1fr 1fr;
            gap: 10px;
            align-items: end;
        }
    </style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>
    
    <div class="container">
        <div class="row mb-3">
            <div class="col">
                <h2>Edit Pharmacy Bill</h2>
            </div>
            <div class="col text-end">
                <a href="view_pharmacy_bill.php?id=<?php echo $transaction_id; ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" form="editBillForm" class="btn btn-primary">Update Bill</button>
            </div>
        </div>

        <form id="editBillForm" method="POST">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Bill Information</h5>
                            <p><strong>Bill No:</strong> <?php echo htmlspecialchars($items[0]['bill_id']); ?></p>
                            <p><strong>Date:</strong> <?php echo formatDisplayDate($items[0]['sale_date']); ?></p>
                            <p><strong>Payment Status:</strong> <?php echo $items[0]['payment_status']; ?></p>
                            <p><strong>Payment Type:</strong> <?php echo htmlspecialchars($items[0]['payment_type'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h5>Patient & Doctor</h5>
                            <div class="form-group mb-3">
                                <label>Patient ID:</label>
                                <div class="input-group">
                                    <input type="text" id="patient_search" name="patient_id" required 
                                           value="<?php echo $items[0]['patient_id']; ?>"
                                           onblur="searchPatient()" 
                                           placeholder="Enter Patient ID">
                                </div>
                                <div id="patient_details" class="mt-2">
                                    <!-- Patient details will be populated here -->
                                </div>
                            </div>
                            <div class="form-group mb-3">
                                <label>Doctor:</label>
                                <input type="hidden" name="doctor_id" id="doctor_id" value="<?php echo $items[0]['doctor_id']; ?>">
                                <input type="hidden" name="dept_id" id="dept_id">
                                <select name="doctor_name" class="form-control" required onchange="updateDoctorDetails(this)">
                                    <option value="">Select Doctor</option>
                                    <?php foreach ($doctors as $doctor) { ?>
                                        <option value="Dr. <?php echo htmlspecialchars($doctor['doctor_name']); ?>"
                                                data-doctor-id="<?php echo $doctor['doctor_id']; ?>"
                                                data-dept-id="<?php echo $doctor['dept_id']; ?>"
                                                <?php echo ($doctor['doctor_id'] == $items[0]['doctor_id']) ? 'selected' : ''; ?>>
                                            Dr. <?php echo htmlspecialchars($doctor['doctor_name']); ?>
                                            (<?php echo htmlspecialchars($doctor['dept_name']); ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5>Medicines</h5>
                    <!-- Add medicine selection section -->
                    <div class="form-row medicine-entry-row mb-3">
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
                            <button type="button" class="btn btn-primary" onclick="addMedicineToTable()">Add Medicine</button>
                        </div>
                    </div>

                    <table class="table">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Batch</th>
                                <th>Qty</th>
                                <th>Rate</th>
                                <th>Amount</th>
                                <th>Net</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr class="item-row" data-batch-id="<?php echo $item['batch_id']; ?>">
                                <td>
                                    <div class="medicine-info">
                                        <div class="medicine-name"><?php echo htmlspecialchars($item['medicine_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($item['manufacturer']); ?></small>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                                <td>
                                    <input type="number" name="quantity[]" value="<?php echo $item['quantity']; ?>" min="1" class="form-control form-control-sm">
                                </td>
                                <td>
                                    <input type="number" name="unit_price[]" value="<?php echo $item['unit_price']; ?>" step="0.01" class="form-control form-control-sm">
                                </td>
                                <td class="text-end">₹<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></td>
                                <td class="text-end">₹<?php echo number_format($item['final_amount'], 2); ?></td>
                                <td><button type="button" class="btn btn-danger btn-sm remove-item">Remove</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end">Subtotal:</td>
                                <td colspan="2">₹<span id="subtotal"><?php echo number_format($items[0]['subtotal'], 2); ?></span></td>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-end">Discount:</td>
                                <td colspan="2">
                                    <input type="number" name="discount" value="<?php echo $items[0]['discount_amount']; ?>" step="0.01" class="form-control form-control-sm">
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total Amount:</strong></td>
                                <td colspan="2"><strong>₹<span id="final_amount"><?php echo number_format($items[0]['final_amount'], 2); ?></span></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Initialize medicines array
        const selectedMedicines = [
            <?php foreach ($items as $item): ?>
            {
                batch_id: <?php echo json_encode($item['batch_id']); ?>,
                name: <?php echo json_encode($item['medicine_name']); ?>,
                manufacturer: <?php echo json_encode($item['manufacturer']); ?>,
                batch_number: <?php echo json_encode($item['batch_number']); ?>,
                quantity: <?php echo (int)$item['quantity']; ?>,
                price: <?php echo (float)$item['unit_price']; ?>,
                total: <?php echo (float)($item['quantity'] * $item['unit_price']); ?>
            },
            <?php endforeach; ?>
        ];

        // Document ready handler
        $(function() {
            // Initialize medicine select
            initializeSelect2();
            
            // Initialize medicine table
            updateMedicinesTable();
            
            // Calculate initial totals
            calculateTotals();
            
            // Trigger initial patient search
            searchPatient();
            
            // Set initial doctor details
            updateDoctorDetails(document.querySelector('select[name="doctor_name"]'));

            // Add change handlers
            setupEventHandlers();
        });

        function setupEventHandlers() {
            // Discount change handler
            $('input[name="discount"]').on('change', function() {
                validateAndCalculateDiscount(this);
            });

            // Quantity/price change handler
            $(document).on('change', 'input[name="quantity[]"], input[name="unit_price[]"]', function() {
                updateRowCalculations(this);
            });

            // Remove item handler
            $(document).on('click', '.remove-item', function() {
                removeItem(this);
            });

            // Form submit handler
            $('#editBillForm').on('submit', handleFormSubmit);
        }

        // ...existing code for other functions...
    </script>
</body>
</html>
