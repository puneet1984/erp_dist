<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

requirePermission('inventory', 'create');
$conn = getDbConnection();

// Add this helper function at the top of the file
function getLastDayOfMonth($date)
{
    return date('Y-m-t', strtotime($date));
}

// Handle form submissions
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        $conn->begin_transaction();

        // Common POST variable sanitization for both new and edit actions
        $name = capitalizeWords(sanitizeInput($_POST['name'] ?? ''));
        $category = sanitizeInput($_POST['category'] ?? '');
        $active_ingredient = sanitizeInput($_POST['active_ingredient'] ?? '');
        $dosage_form = sanitizeInput($_POST['dosage_form'] ?? '');
        $manufacturer = capitalizeWords(sanitizeInput($_POST['manufacturer'] ?? ''));
        $reorder_level = sanitizeInteger($_POST['reorder_level'] ?? 1);
        $batch_number = makeUpperCaseMixed(sanitizeInput($_POST['batch_number'] ?? ''));
        $quantity = sanitizeInteger($_POST['quantity'] ?? 0);
        $purchase_rate = floatval($_POST['purchase_rate'] ?? 0);
        $retail_price = floatval($_POST['retail_price'] ?? 0);

        // Common validation for both actions
        if (empty($name) || empty($category) || empty($manufacturer)) {
            throw new Exception('Name, category and manufacturer are required fields');
        }

        // Only validate batch fields if not deleting a batch
        if ('MEDICINE' === $category && !isset($_POST['delete_batch'])
            && (empty($_POST['batch_number']) || empty($_POST['expiry_date']))) {
            throw new Exception('Batch number and expiry date are required for medicines');
        }

        // Handle expiry date
        if (!empty($_POST['expiry_date'])) {
            $expiry_date = getLastDayOfMonth($_POST['expiry_date']);
        } elseif ('MEDICINE' === $category && !isset($_POST['delete_batch'])) {
            throw new Exception('Expiry date is required for medicines');
        } else {
            $expiry_date = getLastDayOfMonth(date('Y-m-d', strtotime('+5 years')));
        }

        if (isset($_POST['action']) && 'edit' === $_POST['action']) {
            $id = sanitizeInteger($_POST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Invalid item ID for edit');
            }

            // Update pharmacy_items
            $stmt = $conn->prepare('UPDATE pharmacy_items SET 
                name = ?, category = ?, active_ingredient = ?, 
                dosage_form = ?, manufacturer = ?, reorder_level = ? 
                WHERE item_id = ?');

            $stmt->bind_param(
                'sssssii',
                $name,
                $category,
                $active_ingredient,
                $dosage_form,
                $manufacturer,
                $reorder_level,
                $id
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to update item');
            }

            // Handle batch deletion if specified
            if (isset($_POST['delete_batch']) && $_POST['delete_batch']) {
                $batch_number = sanitizeInput($_POST['batch_number']);

                // Check if batch has transactions using pharmacy_transaction_items
                $stmt = $conn->prepare('SELECT COUNT(*) as count FROM pharmacy_transaction_items pti 
                    JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id 
                    WHERE pb.item_id = ? AND pb.batch_number = ?');
                $stmt->bind_param('is', $id, $batch_number);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();

                if ($result['count'] > 0) {
                    throw new Exception('Cannot delete batch: Has transaction history');
                }

                // Delete the batch
                $stmt = $conn->prepare('DELETE FROM pharmacy_batches 
                    WHERE item_id = ? AND batch_number = ?');
                $stmt->bind_param('is', $id, $batch_number);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete batch');
                }
            } elseif ($batch_number) { // Only update batch if batch number is provided
                // Handle batch information
                $stmt = $conn->prepare('SELECT batch_id FROM pharmacy_batches 
                    WHERE item_id = ? AND batch_number = ?');
                $stmt->bind_param('is', $id, $batch_number);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    // Update existing batch
                    $stmt = $conn->prepare('UPDATE pharmacy_batches SET 
                        current_quantity = ?, purchase_rate = ?, 
                        retail_price = ?, expiry_date = ? 
                        WHERE item_id = ? AND batch_number = ?');

                    $stmt->bind_param(
                        'iddsss',
                        $quantity,
                        $purchase_rate,
                        $retail_price,
                        $expiry_date,
                        $id,
                        $batch_number
                    );
                }

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update batch information');
                }
            }

            $conn->commit();
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                echo json_encode(['status' => 'success']);
                exit;
            }
            $_SESSION['success'] = 'Item and batch information updated successfully';
            header('Location: '.strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        if (isset($_POST['action']) && 'add_batch' === $_POST['action']) {
            try {
                $item_id = sanitizeInteger($_POST['item_id'] ?? 0);
                $batch_number = makeUpperCaseMixed(sanitizeInput($_POST['batch_number'] ?? ''));
                $quantity = sanitizeInteger($_POST['quantity'] ?? 0);
                $purchase_rate = floatval($_POST['purchase_rate'] ?? 0);
                $retail_price = floatval($_POST['retail_price'] ?? 0);
                $expiry_date = getLastDayOfMonth($_POST['expiry_date'] ?? '');

                if (!$item_id || empty($batch_number) || $quantity <= 0) {
                    throw new Exception('Invalid item ID, batch number, or quantity');
                }

                // Check if the batch already exists
                $stmt = $conn->prepare('SELECT batch_id FROM pharmacy_batches WHERE item_id = ? AND batch_number = ?');
                $stmt->bind_param('is', $item_id, $batch_number);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
					$existing_batch = $result->fetch_assoc();
					$batch_id = (int) $existing_batch['batch_id'];

					$stmt = $conn->prepare('UPDATE pharmacy_batches
						SET initial_quantity = initial_quantity + ?,
							current_quantity = current_quantity + ?,
							purchase_rate = ?,
							retail_price = ?,
							expiry_date = ?
						WHERE batch_id = ?');
					$stmt->bind_param('iiddsi', $quantity, $quantity, $purchase_rate, $retail_price, $expiry_date, $batch_id);

					if (!$stmt->execute()) {
						throw new Exception('Failed to update existing batch');
					}

					$conn->commit();
					echo json_encode(['status' => 'success', 'message' => 'Batch already existed. Quantity updated successfully']);
					exit;
                }

                // Insert the new batch
                $stmt = $conn->prepare('INSERT INTO pharmacy_batches 
                    (item_id, batch_number, initial_quantity, current_quantity, purchase_rate, retail_price, expiry_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param(
                    'isiiids',
                    $item_id,
                    $batch_number,
                    $quantity,
                    $quantity,
                    $purchase_rate,
                    $retail_price,
                    $expiry_date
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to add new batch');
                }

                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Batch added successfully']);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
        }
        
        // Check if item already exists (by name, active ingredient, dosage form, and manufacturer)
        $stmt = $conn->prepare('SELECT item_id FROM pharmacy_items 
                WHERE name = ? AND active_ingredient = ? AND dosage_form = ? AND manufacturer = ?');
        $stmt->bind_param('ssss', $name, $active_ingredient, $dosage_form, $manufacturer);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Item already exists, get its ID
            $row = $result->fetch_assoc();
            $item_id = $row['item_id'];
            
            // Check if batch already exists for this item
            $stmt = $conn->prepare('SELECT batch_id FROM pharmacy_batches WHERE item_id = ? AND batch_number = ?');
            $stmt->bind_param('is', $item_id, $batch_number);
            $stmt->execute();
            $batch_result = $stmt->get_result();
            
            if ($batch_result->num_rows > 0) {
				// If same item + batch already exists, treat this as stock top-up.
				$existing_batch = $batch_result->fetch_assoc();
				$batch_id = (int) $existing_batch['batch_id'];

				$stmt = $conn->prepare('UPDATE pharmacy_batches
						SET initial_quantity = initial_quantity + ?,
							current_quantity = current_quantity + ?,
							purchase_rate = ?,
							retail_price = ?,
							expiry_date = ?
						WHERE batch_id = ?');
				$stmt->bind_param('iiddsi', $quantity, $quantity, $purchase_rate, $retail_price, $expiry_date, $batch_id);

				if (!$stmt->execute()) {
					throw new Exception('Failed to update existing batch quantity: '.$stmt->error);
				}

				$conn->commit();
				if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
					echo json_encode(['status' => 'success', 'message' => 'Batch already existed. Quantity updated successfully.']);
					exit;
				}

				$_SESSION['success'] = 'Batch already existed. Quantity updated successfully.';
				header('Location: '.strtok($_SERVER['REQUEST_URI'], '?'));
				exit;
            }
            
            // Item exists but batch is new, so we'll add the batch below
        } else {
            // New item insertion
            $stmt = $conn->prepare('INSERT INTO pharmacy_items 
                    (name, category, active_ingredient, dosage_form, manufacturer, reorder_level) 
                    VALUES (?, ?, ?, ?, ?, ?)');

            $stmt->bind_param(
                'sssssi',
                $name,
                $category,
                $active_ingredient,
                $dosage_form,
                $manufacturer,
                $reorder_level
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to add item: '.$stmt->error);
            }

            $item_id = $stmt->insert_id;
        }

        // Handle batch information
        $stmt = $conn->prepare('INSERT INTO pharmacy_batches 
                (item_id, batch_number, initial_quantity, current_quantity, 
                purchase_rate, retail_price, expiry_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?)');

        $stmt->bind_param(
            'isiiids',
            $item_id,
            $batch_number,
            $quantity,
            $quantity,
            $purchase_rate,
            $retail_price,
            $expiry_date
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to add batch information: '.$stmt->error);
        }

        $conn->commit();
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            echo json_encode(['status' => 'success']);
            exit;
        }
        $_SESSION['success'] = 'Item added successfully'.
            ('MEDICINE' === $category ? ' with batch information' : '');
        header('Location: '.strtok($_SERVER['REQUEST_URI'], '?'));
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error'] = 'Error: '.$e->getMessage();
        header('Location: '.strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Handle delete via AJAX
if (isset($_GET['action']) && 'delete' === $_GET['action']) {
    try {
        $conn->begin_transaction();

        $id = sanitizeInteger($_GET['id']);

        // Check for existing transactions
        $stmt = $conn->prepare('SELECT COUNT(*) as count FROM pharmacy_transaction_items WHERE item_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            throw new Exception('Cannot delete: Item has transaction history');
        }

        // Delete batches first (due to foreign key constraint)
        $stmt = $conn->prepare('DELETE FROM pharmacy_batches WHERE item_id = ?');
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to delete batches');
        }

        // Then delete the item
        $stmt = $conn->prepare('DELETE FROM pharmacy_items WHERE item_id = ?');
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to delete item');
        }

        $conn->commit();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Item deleted successfully',
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}

// Update query to include batch information
$items = $conn->query('SELECT i.*, 
    COALESCE(SUM(b.current_quantity), 0) as current_stock,
    GROUP_CONCAT(b.batch_id) as batch_ids,
    GROUP_CONCAT(DISTINCT b.batch_number) as batch_numbers,
    GROUP_CONCAT(b.current_quantity) as batch_quantities,
    GROUP_CONCAT(b.purchase_rate) as purchase_rates,
    GROUP_CONCAT(b.retail_price) as retail_prices,
    GROUP_CONCAT(b.expiry_date) as expiry_dates
    FROM pharmacy_items i 
    LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id 
    GROUP BY i.item_id 
    ORDER BY i.category, i.name')->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Manage Pharmacy Items</title>
	<link rel="stylesheet" href="../../assets/css/style.css">

	<!-- Replace CDN links with local assets -->
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css" />
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<style>
		.item-form {
			background: white;
			padding: 20px;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
			margin-bottom: 20px;
		}

		.items-table {
			width: 100%;
			border-collapse: collapse;
			background: white;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}

		.items-table th,
		.items-table td {
			padding: 12px;
			text-align: left;
			border-bottom: 1px solid #ddd;
		}

		.items-table th {
			background-color: #f8f9fa;
			font-weight: 600;
		}

		.items-table tr:hover {
			background-color: #f5f5f5;
		}

		.btn-edit,
		.btn-delete {
			padding: 5px 10px;
			border-radius: 4px;
			text-decoration: none;
			margin-right: 5px;
		}

		.btn-edit {
			background: #4caf50;
			color: white;
		}

		.btn-delete {
			background: #f44336;
			color: white;
		}

		.category-tag {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 12px;
			font-size: 0.8em;
			margin-bottom: 8px;
		}

		.category-tag.medicine {
			background: #e1f5fe;
			color: #0277bd;
		}

		.category-tag.consumable {
			background: #f1f8e9;
			color: #558b2f;
		}

		.message {
			padding: 10px;
			margin-bottom: 20px;
			margin-top: 20px;
			border-radius: 4px;
		}

		.success {
			background-color: #d4edda;
			color: #155724;
		}

		.error {
			background-color: #f8d7da;
			color: #721c24;
		}

		.medicine-fields {
			transition: all 0.3s ease;
		}

		.medicine-fields.hidden {
			display: none;
		}

		.medicine-search-container {
			display: block;
			margin-bottom: 20px;
		}

		.select2-container {
			width: 100% !important;
		}

		/* Modal styles */
		.modal {
			display: none;
			position: fixed;
			z-index: 1000;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.4);
			overflow-y: auto; /* Enable scrolling on modal overlay */
		}

		.modal-content {
			background-color: #fefefe;
			margin: 5% auto; /* Reduced from 15% to 5% */
			padding: 20px;
			border: 1px solid #888;
			width: 90%;
			max-width: 800px;
			border-radius: 8px;
			max-height: 90vh; /* Limit height to 90% of viewport */
			overflow-y: auto; /* Enable scrolling on modal content */
			position: relative; /* For sticky close button */
		}

		.close {
			color: #aaa;
			float: right;
			font-size: 28px;
			font-weight: bold;
			cursor: pointer;
			position: sticky; /* Make close button sticky */
			top: 0;
			right: 0;
			background: #fefefe;
			padding: 0 10px;
			z-index: 1;
		}

		.form-row {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 20px;
			margin-bottom: 20px;
		}

		.form-group {
			margin-bottom: 0;
			/* Override existing margin */
		}

		@media (max-width: 768px) {
			.form-row {
				grid-template-columns: 1fr;
				gap: 10px;
			}
		}

		.btn-edit,
		.btn-delete {
			padding: 5px 10px;
			border-radius: 4px;
			border: none;
			cursor: pointer;
			margin-right: 5px;
			display: inline-block;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Manage Pharmacy Items</h2>
		<a href="inventory.php" class="btn-primary" style="color: white; text-decoration: none;">Back to Inventory</a>

		<?php if (isset($_SESSION['success'])) { ?>
		<div class="message success">
			<?php echo $_SESSION['success'];
		    unset($_SESSION['success']); ?>
		</div>
		<?php } ?>

		<?php if (isset($_SESSION['error'])) { ?>
		<div class="message error">
			<?php echo $_SESSION['error'];
		    unset($_SESSION['error']); ?>
		</div>
		<?php } ?>

		<div class="item-form">
			<h3>Add New Item</h3>
			<form method="POST" id="itemForm">
				<input type="hidden" name="action" value="add_batch">
				<input type="hidden" name="item_id" id="selected_item_id">
				<div class="medicine-search-container">
					<div class="form-group">
						<label>Search Existing Medicine:</label>
						<select class="medicine-select" style="width: 100%"></select>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label>Name:</label>
						<input type="text" name="name" id="name" required>
					</div>

					<div class="form-group">
						<label>Category:</label>
						<select name="category" id="category" required>
							<option value="MEDICINE">Medicine</option>
							<option value="CONSUMABLE">Consumable</option>
						</select>
					</div>

					<div class="form-group">
						<label>Manufacturer:</label>
						<input type="text" name="manufacturer" id="manufacturer" required>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group medicine-fields">
						<label>Active Ingredient:</label>
						<input type="text" name="active_ingredient" id="active_ingredient">
					</div>

					<div class="form-group medicine-fields">
						<label>Dosage Form:</label>
						<input type="text" name="dosage_form" id="dosage_form">
					</div>

					<div class="form-group">
						<label>Reorder Level:</label>
						<input type="number" name="reorder_level" value="1" required>
					</div>
				</div>
				<!-- Remove entire multidose form-row section -->
				<div class="form-row">
					<div class="form-group">
						<label>Batch Number:</label>
						<input type="text" name="batch_number" id="batch_number">
					</div>
					<div class="form-group">
						<label>Quantity:</label>
						<input type="number" name="quantity" id="quantity">
					</div>
					<div class="form-group">
						<label>Purchase Rate:</label>
						<input type="number" step="0.01" name="purchase_rate" id="purchase_rate">
					</div>
					<div class="form-group">
						<label>Retail Price:</label>
						<input type="number" step="0.01" name="retail_price" id="retail_price">
					</div>
					<div class="form-group">
						<label>Expiry Date:</label>
						<input type="date" name="expiry_date" id="expiry_date"
							min="<?php echo date('Y-m-d'); ?>">
					</div>
				</div>

				<button type="submit" class="btn-primary">Add Item</button>
			</form>
		</div>

		<!-- Replace existing table header with new flexbox layout -->
		<div class="table-header" style="margin: 20px 0; display: flex; justify-content: space-between; align-items: center;">
			<h3 style="margin: 0;">Current Stock</h3>
			<div class="search-box">
				<input type="text" id="stockSearch" placeholder="Search items..." 
					style="padding: 8px; width: 300px; border: 1px solid #ddd; border-radius: 4px;">
			</div>
		</div>

		<!-- Fix table structure -->
		<div class="table-responsive">
			<table class="items-table">
				<thead>
					<tr>
						<th>Name</th>
						<th>Category</th>
						<th>Reorder Level</th>
						<th>Current Stock</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($items as $item) { ?>
					<tr>
						<td><?php echo htmlspecialchars($item['name']); ?>
						</td>
						<td>
							<span
								class="category-tag <?php echo strtolower($item['category']); ?>">
								<?php echo $item['category']; ?>
							</span>
						</td>
						<td><?php echo $item['reorder_level']; ?>
						</td>
						<td><?php echo (int) $item['current_stock']; ?>
						</td>
						<td>
							<button type="button" class="btn-edit"
								data-id="<?php echo htmlspecialchars((int) $item['item_id']); ?>"
								data-name="<?php echo htmlspecialchars($item['name'] ?? ''); ?>"
								data-category="<?php echo htmlspecialchars($item['category'] ?? ''); ?>"
								data-active="<?php echo htmlspecialchars($item['active_ingredient'] ?? ''); ?>"
								data-dosage="<?php echo htmlspecialchars($item['dosage_form'] ?? ''); ?>"
								data-manufacturer="<?php echo htmlspecialchars($item['manufacturer'] ?? ''); ?>"
								data-reorder="<?php echo (int) $item['reorder_level']; ?>"
								data-batches="<?php echo htmlspecialchars($item['batch_numbers'] ?? ''); ?>"
								data-quantities="<?php echo htmlspecialchars($item['batch_quantities'] ?? ''); ?>"
								data-purchase="<?php echo htmlspecialchars($item['purchase_rates'] ?? ''); ?>"
								data-retail="<?php echo htmlspecialchars($item['retail_prices'] ?? ''); ?>"
								data-expiry="<?php echo htmlspecialchars($item['expiry_dates'] ?? ''); ?>"
								data-batch-ids="<?php echo htmlspecialchars($item['batch_ids'] ?? ''); ?>">
								Edit
							</button>
							<button type="button" class="btn-delete"
								data-id="<?php echo htmlspecialchars((int) $item['item_id']); ?>">
								Delete
							</button>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Fix edit modal form structure -->
	<div id="editModal" class="modal">
		<div class="modal-content">
			<span class="close">&times;</span>
			<h3>Edit Item</h3>
			<form method="POST" id="editForm">
				<input type="hidden" name="action" value="edit">
				<input type="hidden" name="id" id="edit_id">
				<input type="hidden" name="delete_batch" id="delete_batch" value="0">
				<div class="form-row">
					<div class="form-group">
						<label>Name:</label>
						<input type="text" name="name" id="edit_name" required>
					</div>

					<div class="form-group">
						<label>Category:</label>
						<select name="category" id="edit_category" required>
							<option value="MEDICINE">Medicine</option>
							<option value="CONSUMABLE">Consumable</option>
						</select>
					</div>

					<div class="form-group">
						<label>Manufacturer:</label>
						<input type="text" name="manufacturer" id="edit_manufacturer" required>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group medicine-fields">
						<label>Active Ingredient:</label>
						<input type="text" name="active_ingredient" id="edit_active_ingredient">
					</div>

					<div class="form-group medicine-fields">
						<label>Dosage Form:</label>
						<input type="text" name="dosage_form" id="edit_dosage_form">
					</div>

					<div class="form-group">
						<label>Reorder Level:</label>
						<input type="number" name="reorder_level" id="edit_reorder_level" required>
					</div>
				</div>

				<div class="batch-list">
					<h4>Existing Batches</h4>
					<table class="items-table">
						<thead>
							<tr>
								<th>Batch Number</th>
								<th>Quantity</th>
								<th>Purchase Rate</th>
								<th>Retail Price</th>
								<th>Expiry Date</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody id="batchTableBody">
						</tbody>
					</table>
				</div>

				<div class="form-row" id="batchEditForm" style="display:none;">
					<div class="form-group">
						<label>Batch Number:</label>
						<input type="text" name="batch_number" id="edit_batch_number" readonly>
					</div>
					<div class="form-group">
						<label>Quantity:</label>
						<input type="number" name="quantity" id="edit_quantity">
					</div>
					<div class="form-group">
						<label>Purchase Rate:</label>
						<input type="number" step="0.01" name="purchase_rate" id="edit_purchase_rate">
					</div>
					<div class="form-group">
						<label>Retail Price:</label>
						<input type="number" step="0.01" name="retail_price" id="edit_retail_price">
					</div>
					<div class="form-group">
						<label>Expiry Date:</label>
						<input type="date" name="expiry_date" id="edit_expiry_date">
					</div>
				</div>

				<button type="submit" class="btn-primary">Update Item</button>
			</form>
		</div>
	</div>
</body>

<script>

	$(document).ready(function() {
		const pendingSuccessMessage = sessionStorage.getItem('manageItemsSuccessMessage');
		if (pendingSuccessMessage) {
			$('<div class="message success"></div>').text(pendingSuccessMessage).insertAfter('.container h2');
			sessionStorage.removeItem('manageItemsSuccessMessage');
		}

		// Add this at the beginning of your existing jQuery ready function
		$('#stockSearch').on('keyup', function() {
			const searchText = $(this).val().toLowerCase().trim();
			if (!searchText) {
				$('.items-table tbody tr').show();
				return;
			}
			
			// First pass - search by name
			let found = false;
			$('.items-table tbody tr').each(function() {
				const itemName = $(this).find('td:first').text().toLowerCase();
				if (itemName.includes(searchText)) {
					$(this).show();
					found = true;
				} else {
					$(this).hide();
				}
			});

			// Second pass - search by active ingredient if no names matched
			if (!found) {
				$('.items-table tbody tr').each(function() {
					const data = $(this).find('.btn-edit').data();
					const activeIngredient = (data.active || '').toLowerCase();
					const category = $(this).find('.category-tag').text().toLowerCase();
					$(this).toggle(
						activeIngredient.includes(searchText) || 
						category.includes(searchText)
					);
				});
			}
		});

		const $medicineSelect = $('.medicine-select').select2({
			ajax: {
				url: '../../includes/helpers/medicine_helper.php',
				dataType: 'json',
				delay: 250,
				data: function(params) {
					return {
						q: params.term,
						type: 'purchase'
					};
				},
				processResults: function(data) {
					console.log('Select2 AJAX Response:', data);
					if (!data || !data.results) {
						console.log('No results in response');
						return {
							results: []
						};
					}
					console.log('Processed results:', data.results);
					return data; // Return the data directly since it's already formatted correctly
				},
				cache: true
			},
			minimumInputLength: 2,
			placeholder: 'Search existing medicine...',
			width: '50%' // Ensure full width
		}).on('select2:select', function(e) {
			const data = e.params.data;
			console.log('Selected medicine data:', data);
			console.log('Medicine ID:', data.id);
			console.log('Medicine Text:', data.text);
			console.log('Manufacturer:', data.manufacturer);
			console.log('Active Ingredient:', data.active_ingredient);
			console.log('Dosage Form:', data.dosage_form);
			console.log('Category:', data.category);
			console.log('Batch Details:', data.batch_details);
			console.log('Source:', data.source);
			
			const form = $(this).closest('form');

			// Populate all form fields with medicine data
			form.find('[name="name"]').val(data.text);
			form.find('[name="manufacturer"]').val(data.manufacturer);
			form.find('[name="active_ingredient"]').val(data.active_ingredient);
			form.find('[name="dosage_form"]').val(data.dosage_form);
			form.find('[name="category"]').val(data.category || 'MEDICINE').trigger('change');

			// Check if this is a local item or external item
			// External items (from trumeds, etc.) should be treated as new items
			const isLocalItem = data.id && !isNaN(data.id) && (!data.source || data.source === 'local');
			
			if (isLocalItem) {
				console.log('Existing LOCAL item detected - Setting up for new batch');
				// Existing item - set up for new batch
				form.find('[name="action"]').val('add_batch');
				form.find('[name="item_id"]').val(data.id);

				// If batch details exist, parse them
				if (data.batch_details) {
					const firstBatch = String(data.batch_details).split('|')[0] || '';
					const [batchNum, qty, exp, price] = firstBatch.split(':');
					console.log('Batch details parsed:', {batchNum, qty, exp, price});
					// Don't auto-fill batch number as this is a new batch
					form.find('[name="retail_price"]').val(price || '');
				}

				// Clear and focus batch entry fields
				form.find('[name="batch_number"]').val('').focus();
				form.find('[name="quantity"]').val('');
				form.find('[name="purchase_rate"]').val('');
				form.find('[name="expiry_date"]').val('');
			} else {
				console.log('New item detected (external or no ID) - Setting up for item creation');
				// New item - remove action field to allow default new item insertion
				form.find('[name="action"]').remove();
				form.find('[name="item_id"]').val('');
				// Focus on the first empty required field
				focusNextEmptyField(form);
			}
		});

		function focusNextEmptyField(form) {
			const fields = ['batch_number', 'quantity', 'purchase_rate', 'retail_price', 'expiry_date'];
			for (const field of fields) {
				const input = form.find(`[name="${field}"]`);
				if (!input.val()) {
					input.focus();
					break;
				}
			}
		}

		function validateForm($form) {
			const category = $form.find('[name="category"]').val();
			const isMedicine = category === 'MEDICINE';
			const isDeleting = $form.find('[name="delete_batch"]').val() === '1';

			// Skip validation if deleting a batch
			if (isDeleting) {
				return true;
			}

			if (isMedicine) {
				const required = ['name', 'manufacturer', 'active_ingredient', 'batch_number', 'expiry_date'];
				for (const field of required) {
					const value = $form.find(`[name="${field}"]`).val();
					if (!value) {
						alert(`Please fill in ${field.replace('_', ' ')}`);
						return false;
					}
				}
			}

			const quantity = parseInt($form.find('[name="quantity"]').val());
			const purchase = parseFloat($form.find('[name="purchase_rate"]').val());
			const retail = parseFloat($form.find('[name="retail_price"]').val());

			if (!isDeleting) {
				if (isNaN(quantity) || quantity <= 0) {
					alert('Please enter a valid quantity');
					return false;
				}
				if (isNaN(purchase) || purchase <= 0) {
					alert('Please enter a valid purchase rate');
					return false;
				}
				if (isNaN(retail) || retail <= 0) {
					alert('Please enter a valid retail price');
					return false;
				}
				if (retail < purchase) {
					alert('Retail price cannot be less than purchase rate');
					return false;
				}
			}

			return true;
		}

		$('#category, #edit_category').on('change', function() {
			const form = $(this).closest('form');
			const isMedicine = this.value === 'MEDICINE';
			form.find('.medicine-fields').toggle(isMedicine);
			form.find('.medicine-fields input').prop('required', isMedicine);
			form.find('[name="batch_number"], [name="expiry_date"]').prop('required', isMedicine);
		});

		$('#category').trigger('change');

		$('#itemForm, #editForm').on('submit', function(e) {
			e.preventDefault();
			if (!validateForm($(this))) return;

			const $form = $(this);
			const $submitBtn = $form.find('button[type="submit"]');
			$submitBtn.prop('disabled', true);

			$.ajax({
				url: window.location.href,
				method: 'POST',
				data: $form.serialize(),
				dataType: 'json',
				success: function(response) {
					if (response.status === 'success') {
						sessionStorage.setItem('manageItemsSuccessMessage', response.message || 'Operation completed successfully');
						location.reload();
					} else {
						alert(response.message || 'Operation failed');
					}
				},
				error: function(xhr) {
					alert('Error occurred. Please try again.');
				},
				complete: function() {
					$submitBtn.prop('disabled', false);
				}
			});
		});

		$('.btn-edit').on('click', function() {
			const data = $(this).data();

			// Convert data attributes to string before splitting
			const batchIds = String(data.batchIds || '').split(',');
			const batches = String(data.batches || '').split(',');
			const quantities = String(data.quantities || '').split(',');
			const purchases = String(data.purchase || '').split(',');
			const retail = String(data.retail || '').split(',');
			const expiry = String(data.expiry || '').split(',');

			$('#edit_id').val(data.id);
			$('#edit_name').val(data.name);
			$('#edit_category').val(data.category).trigger('change');
			$('#edit_manufacturer').val(data.manufacturer);
			$('#edit_reorder_level').val(data.reorder);
			$('#edit_active_ingredient').val(data.active);
			$('#edit_dosage_form').val(data.dosage);

			// Clear and populate batch table
			const $batchTable = $('#batchTableBody').empty();

			batches.forEach((batch, index) => {
				if (batch) {
					const row = `<tr>
						<td>${batch}</td>
						<td>${quantities[index] || ''}</td>
						<td>${purchases[index] || ''}</td>
						<td>${retail[index] || ''}</td>
						<td>${expiry[index]?.split(' ')[0] || ''}</td>
						<td>
							<button type="button" class="btn-edit" onclick="editBatch(this)" 
								data-batch="${batch}" 
								data-quantity="${quantities[index] || ''}"
								data-purchase="${purchases[index] || ''}"
								data-retail="${retail[index] || ''}"
								data-expiry="${expiry[index]?.split(' ')[0] || ''}">
								Edit
							</button>
							<button type="button" class="btn-delete" onclick="deleteBatch('${batch}')">
								Delete
							</button>
						</td>
					</tr>`;
					$batchTable.append(row);
				}
			});

			$('#editModal').show();
		});

		$('.btn-delete').on('click', function() {
			const id = $(this).data('id');
			const name = $(this).closest('tr').find('td:first').text().trim();

			if (confirm(
					`Are you sure you want to delete "${name}"?\nThis cannot be undone and will fail if:\n- Item has transaction history\n- Item has active batches`
				)) {
				$.ajax({
					url: `?action=delete&id=${encodeURIComponent(id)}`,
					method: 'GET',
					dataType: 'json',
					success: function(response) {
						if (response.success) {
							location.reload();
						} else {
							alert(response.message || 'Failed to delete item');
						}
					},
					error: function(xhr) {
						let message = 'Error occurred while deleting';
						try {
							const response = JSON.parse(xhr.responseText);
							message = response.message || message;
						} catch (e) {}
						alert(message);
					}
				});
			}
		});

		$('.close, .modal').on('click', function(e) {
			if (e.target === this) {
				closeModal();
			}
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});

		function closeModal() {
			$('.modal').hide();
			$('#editForm')[0].reset();
		}

		function setToLastDayOfMonth(input) {
			if (input.value) {
				const date = new Date(input.value);
				date.setMonth(date.getMonth() + 1, 0);
				input.value = date.toISOString().split('T')[0];
			}
		}
	});

	function editBatch(button) {
		const $btn = $(button);
		const data = $btn.data();

		$('#batchEditForm').show();
		$('#edit_batch_number').val(data.batch);
		$('#edit_quantity').val(data.quantity);
		$('#edit_purchase_rate').val(data.purchase);
		$('#edit_retail_price').val(data.retail);
		
		// Convert expiry date to YYYY-MM-DD format for date input
		let expiryDate = data.expiry;
		if (expiryDate) {
			// If format is DD-MM-YYYY or similar, convert to YYYY-MM-DD
			const parts = expiryDate.split('-');
			if (parts.length === 3) {
				// Assume DD-MM-YYYY format
				if (parts[0].length === 4) {
					// Already YYYY-MM-DD
					$('#edit_expiry_date').val(expiryDate);
				} else {
					// DD-MM-YYYY, convert to YYYY-MM-DD
					$('#edit_expiry_date').val(`${parts[2]}-${parts[1]}-${parts[0]}`);
				}
			} else {
				$('#edit_expiry_date').val(expiryDate);
			}
		}
	}

	function deleteBatch(batchNumber) {
		if (confirm(`Are you sure you want to delete batch ${batchNumber}?`)) {
			$('#delete_batch').val(1);
			$('#edit_batch_number').val(batchNumber);
			$('#editForm').submit();
		}
	}
</script>

</html>