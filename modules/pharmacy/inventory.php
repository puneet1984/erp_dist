<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

requirePermission('pharmacy', 'view');
$conn = getDbConnection();

// Handle add stock form submission
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action'])) {
    try {
        $conn->begin_transaction();

        // Common POST variable sanitization
        $item_id = $_POST['item_id'] ?? '';
        $name = capitalizeWords(sanitizeInput($_POST['name'] ?? ''));
        $category = sanitizeInput($_POST['category'] ?? '');
        $active_ingredient = sanitizeInput($_POST['active_ingredient'] ?? '');
        $dosage_form = sanitizeInput($_POST['dosage_form'] ?? '');
        $manufacturer = capitalizeWords(sanitizeInput($_POST['manufacturer'] ?? ''));
        $batch_number = makeUpperCaseMixed(sanitizeInput($_POST['batch_number'] ?? ''));
        $quantity = sanitizeInteger($_POST['quantity'] ?? 0);
        $unit_price = sanitizeNumeric($_POST['unit_price'] ?? 0);
        $sale_price = sanitizeNumeric($_POST['sale_price'] ?? 0);
        $expiry_date = sanitizeDate($_POST['expiry_date'] ?? '');

        // Common validations
        if ('MEDICINE' === $category && (empty($active_ingredient) || empty($batch_number) || empty($expiry_date))) {
            throw new Exception('Active ingredient, batch number and expiry date are required for medicines');
        }

        if ('add_stock' === $_POST['action']) {
            // If new item, check if it already exists first
            if ('new' === $item_id) {
                // Check for existing item with same name, active ingredient, and manufacturer
                $stmt = $conn->prepare('SELECT item_id FROM pharmacy_items 
                    WHERE name = ? AND active_ingredient = ? AND manufacturer = ?');
                $stmt->bind_param('sss', $name, $active_ingredient, $manufacturer);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    // Item already exists, use existing ID
                    $row = $result->fetch_assoc();
                    $item_id = $row['item_id'];
                } else {
                    // Item doesn't exist, create new one
                    $stmt = $conn->prepare('INSERT INTO pharmacy_items 
                        (name, category, active_ingredient, dosage_form, manufacturer, reorder_level) 
                        VALUES (?, ?, ?, ?, ?, 10)');

                    $stmt->bind_param(
                        'sssss',
                        $name,
                        $category,
                        $active_ingredient,
                        $dosage_form,
                        $manufacturer
                    );
                    $stmt->execute();
                    $item_id = $conn->insert_id;
                }
            }

            // Insert into pharmacy_batches
            $stmt = $conn->prepare('INSERT INTO pharmacy_batches 
                (item_id, batch_number, initial_quantity, current_quantity, 
                purchase_rate, retail_price, expiry_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?)');

            $stmt->bind_param(
                'isiidds',
                $item_id,
                $batch_number,
                $quantity,
                $quantity,
                $unit_price,
                $sale_price,
                $expiry_date
            );

            if ($stmt->execute()) {
                $batch_id = $conn->insert_id;

                // Record the transaction with new schema
                $txn_id = generateTransactionId('PUR');

                // First create purchase record
                $stmt = $conn->prepare("INSERT INTO pharmacy_purchases 
                    (transaction_id, invoice_number, supplier_name, 
                    invoice_date, purchase_date, subtotal, final_amount,
                    payment_status, created_by) 
                    VALUES (?, 'NA', 'DIRECT', CURRENT_DATE, CURRENT_DATE, ?, ?,
                    'PAID', ?)");

                $total_amount = $quantity * $unit_price;
                $stmt->bind_param(
                    'sddi',
                    $txn_id,
                    $total_amount,
                    $total_amount,
                    $_SESSION['user_id']
                );

                if ($stmt->execute()) {
                    // Then record transaction items
                    $stmt = $conn->prepare('INSERT INTO pharmacy_transaction_items 
                        (transaction_id, batch_id, quantity, unit_price, final_amount) 
                        VALUES (?, ?, ?, ?, ?)');

                    $stmt->bind_param(
                        'siidi',
                        $txn_id,
                        $batch_id,
                        $quantity,
                        $unit_price,
                        $total_amount
                    );

                    if ($stmt->execute()) {
                        $conn->commit();
                        echo json_encode(['status' => 'success']);
                    } else {
                        throw new Exception('Failed to record transaction items');
                    }
                } else {
                    throw new Exception('Failed to record purchase');
                }
            } else {
                throw new Exception('Failed to add batch');
            }
        } elseif ('edit' === $_POST['action']) {
            try {
                // First update pharmacy_items
                $stmt = $conn->prepare('UPDATE pharmacy_items SET 
                    name = ?, category = ?, active_ingredient = ?, 
                    dosage_form = ?, manufacturer = ?, reorder_level = ? 
                    WHERE item_id = ?');

                $reorder_level = sanitizeInteger($_POST['reorder_level'] ?? 10);
                $id = sanitizeInteger($_POST['id'] ?? 0);

                // Validate required fields
                if (empty($name) || empty($category) || empty($manufacturer)) {
                    throw new Exception('Name, category and manufacturer are required fields');
                }

                $stmt->bind_param(
                    'ssssssi',
                    $name,
                    $category,
                    $active_ingredient,
                    $dosage_form,
                    $manufacturer,
                    $reorder_level,
                    $id
                );

                if ($stmt->execute()) {
                    $conn->commit();
                    echo json_encode(['status' => 'success']);
                } else {
                    throw new Exception('Failed to update item');
                }
            } catch (Exception $e) {
                $conn->rollback();
                error_log('Edit item error: '.$e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Add stock error: '.$e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch medicines and consumables separately

// Pagination parameters
$items_per_page = 10;
$current_page_medicines = max(1, intval($_GET['med_page'] ?? 1));
$current_page_consumables = max(1, intval($_GET['cons_page'] ?? 1));
$offset_medicines = ($current_page_medicines - 1) * $items_per_page;
$offset_consumables = ($current_page_consumables - 1) * $items_per_page;

// Modify medicine query without pagination for searchability
$sql_medicines = "SELECT 
    i.item_id,
    i.name,
    i.category,
    i.active_ingredient,
    i.dosage_form,
    i.manufacturer,
    i.reorder_level,
    COALESCE(SUM(b.current_quantity), 0) as total_stock
FROM pharmacy_items i
LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
WHERE i.category = 'MEDICINE'
GROUP BY i.item_id
ORDER BY i.name";

// Get all medicines without pagination
$stmt = $conn->prepare($sql_medicines);
$stmt->execute();
$medicines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Similar for consumables - without pagination
$sql_consumables = "SELECT 
    i.item_id,
    i.name,
    i.category,
    i.active_ingredient,
    i.dosage_form,
    i.manufacturer,
    i.reorder_level,
    COALESCE(SUM(b.current_quantity), 0) as total_stock
FROM pharmacy_items i
LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
WHERE i.category = 'CONSUMABLE'
GROUP BY i.item_id
ORDER BY i.name";

// Get all consumables without pagination
$stmt = $conn->prepare($sql_consumables);
$stmt->execute();
$consumables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch analytics data
$sql_fast_moving = 'SELECT i.*, 
                    COUNT(DISTINCT pti.transaction_id) as transaction_count,
                    SUM(pti.quantity) as total_sales
                    FROM pharmacy_items i
                    LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
                    LEFT JOIN pharmacy_transaction_items pti ON b.batch_id = pti.batch_id
                    LEFT JOIN pharmacy_sales ps ON pti.transaction_id = ps.transaction_id
                    WHERE ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY i.item_id
                    HAVING total_sales > 0
                    ORDER BY total_sales DESC
                    LIMIT 10';

$sql_slow_moving = 'SELECT i.*, 
                    MAX(ps.sale_date) as last_transaction,
                    SUM(b.current_quantity) as current_stock
                    FROM pharmacy_items i
                    LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
                    LEFT JOIN pharmacy_transaction_items pti ON b.batch_id = pti.batch_id
                    LEFT JOIN pharmacy_sales ps ON pti.transaction_id = ps.transaction_id
                    GROUP BY i.item_id
                    HAVING MAX(ps.sale_date) < DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                        OR MAX(ps.sale_date) IS NULL
                    ORDER BY last_transaction ASC, current_stock DESC
                    LIMIT 10';

$sql_low_stock = 'SELECT i.*, 
                  SUM(b.current_quantity) as total_stock
                  FROM pharmacy_items i
                  LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
                  GROUP BY i.item_id
                  HAVING total_stock <= i.reorder_level
                  ORDER BY total_stock ASC';

// Execute queries
$fast_moving = $conn->query($sql_fast_moving)->fetch_all(MYSQLI_ASSOC);
$slow_moving = $conn->query($sql_slow_moving)->fetch_all(MYSQLI_ASSOC);
$low_stock = $conn->query($sql_low_stock)->fetch_all(MYSQLI_ASSOC);


function formatBatchDetails($batchString)
{
    if (empty($batchString)) {
        return 'No batches';
    }

    $batches = explode('|', $batchString);
    $output = '';
    foreach ($batches as $batch) {
        [$number, $qty, $expiry, $price] = explode(':', $batch);
        $output .= sprintf(
            'Batch: %s, Qty: %d, Exp: %s, Price: ₹%.2f<br>',
            htmlspecialchars($number),
            $qty,
            date('Y-m-d', strtotime($expiry)),
            $price
        );
    }

    return $output;
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Pharmacy Inventory</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/inventory.css">

	<!-- Replace CDN links with local assets -->
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css" />
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<style>
		.inventory-section {
			margin-bottom: 30px;
			padding: 20px;
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}

		.stock-columns {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
			margin-bottom: 30px;
		}

		.analytics-columns {
			display: grid;
			grid-template-columns: 1fr 1fr 1fr;
			gap: 10px;
		}

		.column {
			background: #f8f9fa;
			border-radius: 6px;
			padding: 5px;
			max-height: 600px;
			overflow-y: auto;
		}

		.column h4 {
			margin-top: 0;
			padding: 10px;
			position: sticky;
			top: 0;
			background: #f8f9fa;
			z-index: 10;
			border-bottom: 1px solid #ddd;
		}

		.scroll-content {
			height: 400px;
			overflow-y: auto;
			padding: 10px;
		}

		.stock-item {
			background: white;
			padding: 10px;
			margin-bottom: 10px;
			border-radius: 4px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}

		/* Modal styles consolidated */
		.modal {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background-color: rgba(0, 0, 0, 0.5);
			z-index: 1000;
			overflow-y: auto;
			padding: 20px;
		}

		.modal-content {
			position: relative;
			background-color: #fefefe;
			margin: 30px auto;
			padding: 20px;
			border: 1px solid #888;
			width: 90%;
			max-width: 600px;
			border-radius: 5px;
			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
		}

		.close {
			position: absolute;
			right: 20px;
			top: 10px;
			font-size: 28px;
			font-weight: bold;
			cursor: pointer;
			color: #666;
			line-height: 1;
		}

		.close:hover {
			color: #000;
		}

		.modal.show {
			display: block;
			animation: fadeIn 0.3s;
		}

		@keyframes fadeIn {
			from {
				opacity: 0;
			}

			to {
				opacity: 1;
			}
		}

		h3 {
			margin-top: 0;
			padding-bottom: 10px;
			border-bottom: 2px solid #eee;
		}

		.medicine-specific-fields {
			transition: all 0.3s ease;
		}

		.medicine-specific-fields.hidden {
			display: none;
		}

		.stock-tables {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
			margin-bottom: 30px;
		}

		.table-responsive {
			overflow-x: auto;
		}

		.table {
			width: 100%;
			border-collapse: collapse;
			background: white;
		}

		.table th,
		.table td {
			padding: 10px;
			text-align: left;
			border-bottom: 1px solid #eee;
		}

		.table th {
			background: #f8f9fa;
			font-weight: 600;
		}

		.btn-small {
			padding: 4px 8px;
			font-size: 0.9em;
			background: #007bff;
			color: white;
			border: none;
			border-radius: 4px;
			cursor: pointer;
		}

		.btn-small:hover {
			background: #0056b3;
		}

		.tabs {
			border-bottom: 1px solid #ddd;
			margin-bottom: 20px;
		}

		.tab-btn {
			padding: 10px 20px;
			border: none;
			background: none;
			cursor: pointer;
		}

		.tab-btn.active {
			border-bottom: 2px solid #007bff;
			color: #007bff;
		}

		.tab-content {
			display: none;
		}

		.tab-content.active {
			display: block;
		}

		.pagination {
			display: flex;
			justify-content: center;
			gap: 5px;
			margin-top: 15px;
		}

		.pagination a {
			padding: 5px 10px;
			border: 1px solid #ddd;
			text-decoration: none;
			color: #333;
			border-radius: 3px;
		}

		.pagination a.active {
			background-color: #007bff;
			color: white;
			border-color: #007bff;
		}

		.pagination a:hover:not(.active) {
			background-color: #f0f0f0;
		}

		.analytics-table {
			width: 100%;
			margin-bottom: 10px;
			border-collapse: collapse;
			background: white;
		}

		.analytics-table th,
		.analytics-table td {
			padding: 8px;
			text-align: left;
			border-bottom: 1px solid #eee;
		}

		.analytics-table th {
			background: #f8f9fa;
			font-weight: 600;
		}

		.analytics-table tr:hover {
			background-color: #f5f5f5;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="header">
			<h2>Pharmacy Inventory Management</h2>
			<?php if (checkPermission('inventory', 'create')) { ?>
			<a href="stock_reconciliation.php" class="btn-primary" style="text-decoration: none; color:white">Stock
				Reconciliation</a>
			<!-- <button class="btn-primary" onclick="openAddStockModal()">Add New Stock</button> -->
			<!-- <a href="manage_items.php" class="btn-primary" style="text-decoration: none; color:white">Manage Stock</a> -->
			<!-- <a href="add_items.php" class="btn-primary" style="text-decoration: none; color:white">Add Items</a> -->
			<a href="edit_items.php" class="btn-primary" style="text-decoration: none; color:white">Edit Items</a>

			<?php } ?>
		</div>

		<!-- Current Stock Section -->
		<div class="inventory-section">
			<h3>Current Stock</h3>
			<div style="display: flex; justify-content: space-between; gap: 20px; margin-bottom: 20px;">
				<div style="flex: 1;">
					<input type="text" id="medicineSearch" placeholder="Search medicines..."
						style="padding: 8px; width: 100%; border: 1px solid #ddd; border-radius: 4px;">
				</div>
				<div style="flex: 1;">
					<input type="text" id="consumableSearch" placeholder="Search consumables..."
						style="padding: 8px; width: 100%; border: 1px solid #ddd; border-radius: 4px;">
				</div>
			</div>
			<div class="stock-tables">
				<div class="column">
					<h4>Medicines</h4>
					<div class="table-responsive">
						<table class="table medicines-table">
							<thead>
								<tr>
									<th>Name</th>
									<th>Current Stock</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($medicines as $item) { ?>
								<tr
									data-active-ingredient="<?php echo htmlspecialchars($item['active_ingredient']); ?>">
									<td><?php echo htmlspecialchars($item['name']); ?>
									</td>
									<td><?php echo (int) $item['total_stock']; ?>
									</td>
									<td>
										<button class="btn-small"
											onclick="viewItem(<?php echo $item['item_id']; ?>); return false;">View</button>
									</td>
								</tr>
								<?php } ?>
							</tbody>
						</table>

					</div>
				</div>

				<div class="column">
					<h4>Consumables</h4>
					<div class="table-responsive">
						<table class="table consumables-table">
							<thead>
								<tr>
									<th>Name</th>
									<th>Current Stock</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($consumables as $item) { ?>
								<tr
									data-active-ingredient="<?php echo htmlspecialchars($item['active_ingredient']); ?>">
									<td><?php echo htmlspecialchars($item['name']); ?>
									</td>
									<td><?php echo $item['total_stock'] ?? 0; ?>
									</td>
									<td>
										<button class="btn-small"
											onclick="viewItem(<?php echo $item['item_id']; ?>); return false;">View</button>
									</td>
								</tr>
								<?php } ?>
							</tbody>
						</table>

					</div>
				</div>
			</div>
		</div>

		<!-- Analytics Section -->
		<div class="inventory-section">
			<h3>Inventory Analytics</h3>
			<div class="analytics-columns">
				<div class="column">
					<h4>Fast Moving Items</h4>
					<div class="table-responsive">
						<table class="analytics-table">
							<thead>
								<tr>
									<th>Name</th>
									<th>Sales</th>
									<th>Transactions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($fast_moving as $item) { ?>
								<tr>
									<td><?php echo htmlspecialchars($item['name']); ?>
									</td>
									<td><?php echo $item['total_sales']; ?>
									</td>
									<td><?php echo $item['transaction_count']; ?>
									</td>
								</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="column">
					<h4>Slow Moving Items</h4>
					<div class="table-responsive">
						<table class="analytics-table">
							<thead>
								<tr>
									<th>Name</th>
									<th>Last Transaction</th>
									<th>Current Stock</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($slow_moving as $item) { ?>
								<tr>
									<td><?php echo htmlspecialchars($item['name']); ?>
									</td>
									<td><?php echo $item['last_transaction'] ? date('Y-m-d', strtotime($item['last_transaction'])) : 'Never'; ?>
									</td>
									<td><?php echo $item['current_stock']; ?>
									</td>
								</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="column">
					<h4>Low Stock Items</h4>
					<div class="table-responsive">
						<table class="analytics-table">
							<thead>
								<tr>
									<th>Name</th>
									<th>Current Stock</th>
									<th>Reorder Level</th>
									<!-- <th>Action</th> -->
								</tr>
							</thead>
							<tbody>
								<?php foreach ($low_stock as $item) { ?>
								<tr>
									<td><?php echo htmlspecialchars($item['name']); ?>
									</td>
									<td><?php echo $item['total_stock']; ?>
									</td>
									<td><?php echo $item['reorder_level']; ?>
									</td>
									<!-- <td>
										<button class="btn-small" onclick="addStock(<?php echo $item['item_id']; ?>)">Add
									Stock</button>
									</td> -->
								</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>

		<!-- Add Stock Modal -->
		<div id="addStockModal" class="modal">
			<div class="modal-content">
				<span class="close">&times;</span>
				<h3>Add New Stock</h3>
				<form id="addStockForm" method="POST">
					<input type="hidden" name="action" value="add_stock">
					<input type="hidden" name="item_id" id="selected_item_id">

					<div class="form-group">
						<label>Category:</label>
						<select name="category" id="modal_category" required>
							<option value="">Select Category</option>
							<option value="MEDICINE">Medicine</option>
							<option value="CONSUMABLE">Consumable</option>
						</select>
					</div>

					<div class="form-group">
						<label>Item Name:</label>
						<select class="medicine-select" style="width: 100%" required></select>
					</div>

					<div class="medicine-specific-fields">
						<div class="form-group">
							<label>Active Ingredient:</label>
							<input type="text" name="active_ingredient" id="active_ingredient">
						</div>

						<div class="form-group">
							<label>Dosage Form:</label>
							<input type="text" name="dosage_form" id="dosage_form">
						</div>
					</div>

					<div class="form-group">
						<label>Manufacturer:</label>
						<input type="text" name="manufacturer" id="manufacturer" required>
					</div>

					<div class="form-group">
						<label>Batch Number:</label>
						<input type="text" name="batch_number" required>
					</div>

					<div class="form-group">
						<label>Quantity:</label>
						<input type="number" name="quantity" min="1" required>
					</div>

					<div class="form-group">
						<label>Unit Price:</label>
						<input type="number" name="unit_price" step="0.01" required>
					</div>

					<div class="form-group">
						<label>Sale Price:</label>
						<input type="number" name="sale_price" step="0.01" required>
					</div>

					<div class="form-group">
						<label>Expiry Date:</label>
						<input type="date" name="expiry_date" required>
					</div>

					<button type="submit" class="btn-primary">Add Stock</button>
				</form>
			</div>
		</div>

	</div>

	<script>
		$(document).ready(function() {
			// Search functionality for Current Stock with name and active ingredient fallback
			$('#medicineSearch').on('keyup', function() {
				const searchText = $(this).val().toLowerCase().trim();
				const table = $('.medicines-table');

				if (!searchText) {
					table.find('tbody tr').show();
					return;
				}

				// First pass - search by name
				let found = false;
				table.find('tbody tr').each(function() {
					const name = $(this).find('td').eq(0).text().toLowerCase();
					const match = name.includes(searchText);
					$(this).toggle(match);
					if (match) found = true;
				});

				// Second pass - search by active ingredient if no names matched
				if (!found) {
					table.find('tbody tr').each(function() {
						const ingredient = $(this).data('active-ingredient') ? $(this).data(
							'active-ingredient').toLowerCase() : '';
						const name = $(this).find('td').eq(0).text().toLowerCase();
						const match = ingredient.includes(searchText) || name.includes(searchText);
						$(this).toggle(match);
					});
				}
			});

			$('#consumableSearch').on('keyup', function() {
				const searchText = $(this).val().toLowerCase().trim();
				const table = $('.consumables-table');

				if (!searchText) {
					table.find('tbody tr').show();
					return;
				}

				// First pass - search by name
				let found = false;
				table.find('tbody tr').each(function() {
					const name = $(this).find('td').eq(0).text().toLowerCase();
					const match = name.includes(searchText);
					$(this).toggle(match);
					if (match) found = true;
				});

				// Second pass - search by active ingredient if no names matched
				if (!found) {
					table.find('tbody tr').each(function() {
						const ingredient = $(this).data('active-ingredient') ? $(this).data(
							'active-ingredient').toLowerCase() : '';
						const name = $(this).find('td').eq(0).text().toLowerCase();
						const match = ingredient.includes(searchText) || name.includes(searchText);
						$(this).toggle(match);
					});
				}
			});

			// Category change handler
			$('#modal_category').on('change', function() {
				const medicineFields = $('.medicine-specific-fields');
				const inputs = medicineFields.find('input');

				if (this.value === 'CONSUMABLE') {
					medicineFields.hide();
					inputs.prop('required', false).val('');
				} else {
					medicineFields.show();
					$('#active_ingredient').prop('required', true);
				}
			});

			// Initial category check
			$('#modal_category').trigger('change');

			// Validate sale price against unit price
			$('input[name="sale_price"]').on('change', function() {
				const unitPrice = parseFloat($('input[name="unit_price"]').val()) || 0;
				const salePrice = parseFloat(this.value) || 0;

				if (salePrice < unitPrice) {
					alert('Sale price cannot be less than unit price');
					this.value = unitPrice;
				}
			});

			// Keep Select2 and modal related JavaScript
			$('.medicine-select').select2({
				ajax: {
					url: '/erp/includes/helpers/medicine_helper.php',
					dataType: 'json',
					delay: 250,
					data: function(params) {
						return {
							q: params.term,
							type: 'purchase' // This will trigger both DB and JSON search
						};
					},
					processResults: function(data) {
						console.log('Medicine search results:', data);
						return data;
					},
					cache: true
				},
				minimumInputLength: 2,
				placeholder: 'Search for a medicine...'
			}).on('select2:opening', function() {
				// Use setTimeout to ensure DOM is ready after dropdown opens
				setTimeout(() => {
					const searchField = document.querySelector('.select2-search__field');
					if (searchField) {
						searchField.focus();
					}
				}, 0);
			}).on('select2:select', function(e) {
				var data = e.params.data;
				console.log('Selected medicine data:', data);

				// Handle selection directly
				if (data.id && !isNaN(data.id)) {
					// Existing item from DB
					$('#selected_item_id').val(data.id);
					$('#active_ingredient').val(data.active_ingredient);
					$('#dosage_form').val(data.dosage_form);
					$('#manufacturer').val(data.manufacturer);
				} else {
					// New item from JSON
					$('#selected_item_id').val('new');
					$('#active_ingredient').val(data.active_ingredient);
					$('#dosage_form').val(data.dosage_form);
					$('#manufacturer').val(data.manufacturer);
				}
			});

			// Form submission handling
			$('#addStockForm').on('submit', function(e) {
				e.preventDefault();
				$.ajax({
					url: 'inventory.php',
					method: 'POST',
					data: $(this).serialize(),
					success: function(response) {
						const data = JSON.parse(response);
						if (data.status === 'success') {
							closeModal();
							location.reload();
						} else {
							alert('Error: ' + data.message);
						}
					}
				});
			});
		});

		// Modal Functions
		window.openAddStockModal = function() {
			const modal = document.getElementById('addStockModal');
			modal.classList.add('show');
			document.body.style.overflow = 'hidden'; // Prevent background scrolling
		};

		// Close modal when clicking X button or outside
		$(document).on('click', '.close, .modal', function(e) {
			if (e.target === this) {
				closeModal();
			}
		});

		// Close modal function
		function closeModal() {
			const modal = document.getElementById('addStockModal');
			modal.classList.remove('show');
			document.body.style.overflow = ''; // Restore scrolling
		}

		// Prevent modal from closing when clicking inside modal content
		$('.modal-content').on('click', function(e) {
			e.stopPropagation();
		});

		// Close modal on ESC key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});

		// Update addStock function
		function addStock(itemId) {
			$('#selected_item_id').val(itemId);
			openAddStockModal();
		}

		// View item function
		function viewItem(itemId) {
			window.location.href = 'view_item.php?id=' + itemId;
		}
	</script>
</body>

</html>