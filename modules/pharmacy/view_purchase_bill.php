<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('pharmacy', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$canEditPurchase = checkPermission('pharmacy', 'edit');
$canCreatePurchase = checkPermission('pharmacy', 'create');
$canDeletePurchase = checkPermission('pharmacy', 'delete');

// Helper function for recalculating totals
function recalculatePurchaseTotals($conn, $purchase_id)
{
    $stmt = $conn->prepare(
        '
        UPDATE pharmacy_purchases p 
        SET subtotal = (
            SELECT COALESCE(SUM(quantity * unit_price), 0)
            FROM pharmacy_transaction_items t
            WHERE t.transaction_id = p.transaction_id
        ),
        tax_amount = (
            SELECT COALESCE(SUM(tax_amount), 0)
            FROM pharmacy_transaction_items t
            WHERE t.transaction_id = p.transaction_id
        ),
        final_amount = (
            SELECT COALESCE(SUM(final_amount), 0)
            FROM pharmacy_transaction_items t
            WHERE t.transaction_id = p.transaction_id
        )
        WHERE purchase_id = ?'
    );
    $stmt->bind_param('i', $purchase_id);
    $stmt->execute();
}

// Handle AJAX requests
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    header('Content-Type: application/json');
    $conn = getDbConnection();

    $action = $_POST['action'] ?? '';

    if ('get_details' !== $action && !$canEditPurchase && !$canCreatePurchase && !$canDeletePurchase) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
        exit;
    }

    if ('save' === $action || 'update_item' === $action) {
        if (!$canEditPurchase) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
            exit;
        }
    }

    if ('add_item' === $action) {
        if (!$canCreatePurchase && !$canEditPurchase) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
            exit;
        }
    }

    if ('delete_item' === $action) {
        if (!$canDeletePurchase) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
            exit;
        }
    }

    if ('get_details' === $_POST['action']) {
        try {
            // Get purchase details with supplier info
            $stmt = $conn->prepare('
                SELECT p.*, u.username as created_by_name, s.supplier_name 
                FROM pharmacy_purchases p 
                LEFT JOIN users u ON p.created_by = u.user_id 
                LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
                WHERE p.purchase_id = ?
            ');
            $purchase_id = sanitizeInput($_POST['purchase_id']);
            $stmt->bind_param('i', $purchase_id);
            $stmt->execute();
            $purchase = $stmt->get_result()->fetch_assoc();

            // Get list of suppliers for dropdown
            $suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name')->fetch_all(MYSQLI_ASSOC);

            // Get items with retail price
            $stmt = $conn->prepare('
                SELECT i.*, b.batch_number, b.expiry_date, b.retail_price, 
                       m.name as medicine_name, m.manufacturer, m.active_ingredient
                FROM pharmacy_transaction_items i
                JOIN pharmacy_batches b ON i.batch_id = b.batch_id
                JOIN pharmacy_items m ON b.item_id = m.item_id
                WHERE i.transaction_id = ?
            ');
            $stmt->bind_param('s', $purchase['transaction_id']);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'status' => 'success',
                'purchase' => $purchase,
                'items' => $items,
                'suppliers' => $suppliers,
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ('save' === $_POST['action']) {
        $conn->begin_transaction();
        try {
            $purchase_id = sanitizeInput($_POST['purchase_id']);
            $supplier_id = sanitizeNumeric($_POST['supplier_id']); // Changed from supplier_name
            $invoice_number = sanitizeInput($_POST['invoice_number']);
            $purchase_date = sanitizeInput($_POST['purchase_date']);
            $invoice_date = sanitizeInput($_POST['invoice_date']);
            $payment_status = sanitizeInput($_POST['payment_status']);
            $notes = sanitizeInput($_POST['notes']);

            // Update purchase record
            $stmt = $conn->prepare('
                UPDATE pharmacy_purchases 
                SET supplier_id = ?, invoice_number = ?, 
                    purchase_date = ?, invoice_date = ?,
                    payment_status = ?, notes = ?
                WHERE purchase_id = ?
            ');

            $stmt->bind_param(
                'isssssi',
                $supplier_id,
                $invoice_number,
                $purchase_date,
                $invoice_date,
                $payment_status,
                $notes,
                $purchase_id
            );
            $stmt->execute();

            // Sync supplier for all batches used in this purchase transaction
            $stmt = $conn->prepare('
				UPDATE pharmacy_batches pb
				JOIN pharmacy_transaction_items pti ON pb.batch_id = pti.batch_id
				JOIN pharmacy_purchases pp ON pp.transaction_id = pti.transaction_id
				SET pb.supplier_id = ?
				WHERE pp.purchase_id = ?
			');
            $stmt->bind_param('ii', $supplier_id, $purchase_id);
            $stmt->execute();

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Purchase updated successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ('update_item' === $_POST['action']) {
        $conn->begin_transaction();
        try {
            $item_id = sanitizeNumeric($_POST['item_id']);
            $quantity = sanitizeNumeric($_POST['quantity']);
            $purchase_rate = sanitizeNumeric($_POST['purchase_rate']);
            $retail_price = sanitizeNumeric($_POST['retail_price']);
			$expiry_date = sanitizeDate($_POST['expiry_date'] ?? '');

			if ('' === $expiry_date) {
				throw new Exception('Expiry date is required');
			}

            // Update transaction item - Fixed parameter binding
            $stmt = $conn->prepare(
                '
                UPDATE pharmacy_transaction_items 
                SET quantity = ?, 
                    unit_price = ?,
                    tax_amount = (quantity * unit_price * tax_percentage / 100),
                    final_amount = (quantity * unit_price) + 
                                 (quantity * unit_price * tax_percentage / 100)
                WHERE item_id = ?'
            );
            $stmt->bind_param(
                'ddi',
                $quantity,
                $purchase_rate,
                $item_id
            );
            $stmt->execute();

            // Update batch
            $stmt = $conn->prepare(
                '
                UPDATE pharmacy_batches 
                SET initial_quantity = ?, current_quantity = ?,
					purchase_rate = ?, retail_price = ?, expiry_date = ?
                WHERE batch_id = (
                    SELECT batch_id FROM pharmacy_transaction_items 
                    WHERE item_id = ?
                )'
            );
            $stmt->bind_param(
				'iiddsi',
                $quantity,
                $quantity,
                $purchase_rate,
                $retail_price,
				$expiry_date,
                $item_id
            );
            $stmt->execute();

            // Update purchase totals
            recalculatePurchaseTotals($conn, $_POST['purchase_id']);

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Item updated successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ('delete_item' === $_POST['action']) {
        $conn->begin_transaction();
        try {
            $item_id = sanitizeNumeric($_POST['item_id']);

            // Delete transaction item
            $stmt = $conn->prepare('DELETE FROM pharmacy_transaction_items WHERE item_id = ?');
            $stmt->bind_param('i', $item_id);
            $stmt->execute();

            // Update purchase totals
            recalculatePurchaseTotals($conn, $_POST['purchase_id']);

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Item deleted successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ('add_item' === $_POST['action']) {
        $purchase_id = sanitizeNumeric($_POST['purchase_id']);
        $conn->begin_transaction();
        try {
            // Get invoice number and supplier info from purchase
            $stmt = $conn->prepare('SELECT pp.transaction_id, pp.invoice_number, pp.supplier_id 
                FROM pharmacy_purchases pp 
                WHERE pp.purchase_id = ?');
            $stmt->bind_param('i', $purchase_id);
            $stmt->execute();
            $purchase = $stmt->get_result()->fetch_object();

            $item_id = sanitizeNumeric($_POST['item_id']);
            $batch_number = makeUpperCaseMixed(sanitizeInput($_POST['batch_number']));
            $quantity = sanitizeNumeric($_POST['quantity']);
            $purchase_rate = sanitizeNumeric($_POST['purchase_rate']);
            $retail_price = sanitizeNumeric($_POST['retail_price']);
            $expiry_date = sanitizeInput($_POST['expiry_date']);
            $tax_percentage = sanitizeNumeric($_POST['tax_percentage'] ?? 0);

            // Check if batch already exists for this item
            $stmt = $conn->prepare('SELECT batch_id, current_quantity FROM pharmacy_batches 
                WHERE item_id = ? AND batch_number = ?');
            $stmt->bind_param('is', $item_id, $batch_number);
            $stmt->execute();
            $existing_batch = $stmt->get_result()->fetch_object();

            if ($existing_batch) {
                // Update existing batch quantities
                $batch_id = $existing_batch->batch_id;
                $new_current_quantity = $existing_batch->current_quantity + $quantity;

                $stmt = $conn->prepare('UPDATE pharmacy_batches 
                    SET current_quantity = current_quantity + ?,
                        initial_quantity = initial_quantity + ?,
                        purchase_rate = ?,
                        retail_price = ?,
                        expiry_date = ?
                    WHERE batch_id = ?');
                $stmt->bind_param('iiddsi', $quantity, $quantity, $purchase_rate, $retail_price, $expiry_date, $batch_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update batch quantities: '.$stmt->error);
                }
            } else {
                // Create new batch
                $stmt = $conn->prepare('INSERT INTO pharmacy_batches 
                    (item_id, batch_number, initial_quantity, current_quantity,
                    purchase_rate, retail_price, expiry_date, supplier_id, invoice_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

                $stmt->bind_param(
                    'isiiddsss',
                    $item_id,
                    $batch_number,
                    $quantity,
                    $quantity,
                    $purchase_rate,
                    $retail_price,
                    $expiry_date,
                    $purchase->supplier_id,
                    $purchase->invoice_number
                );
                if (!$stmt->execute()) {
                    throw new Exception('Failed to create batch: '.$stmt->error);
                }
                $batch_id = $conn->insert_id;
            }

            // Calculate amounts
            $subtotal = $quantity * $purchase_rate;
            $tax_amount = ($subtotal * $tax_percentage) / 100;
            $final_amount = $subtotal + $tax_amount;

            // Add transaction item
            $stmt = $conn->prepare('INSERT INTO pharmacy_transaction_items 
                (transaction_id, batch_id, quantity, unit_price,
                tax_percentage, tax_amount, final_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)');

            $stmt->bind_param(
                'siidddd',
                $purchase->transaction_id,
                $batch_id,
                $quantity,
                $purchase_rate,
                $tax_percentage,
                $tax_amount,
                $final_amount
            );
            if (!$stmt->execute()) {
                throw new Exception('Failed to record transaction item: '.$stmt->error);
            }

            // Update purchase totals
            recalculatePurchaseTotals($conn, $purchase_id);

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Medicine added successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>View/Edit Purchase</title>
	<!-- Move scripts to end of body -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/pharmacy-purchase.css">
	<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
	<style>
		.bill-details {
			background: #f8f9fa;
			padding: 20px;
			border-radius: 5px;
			margin-bottom: 20px;
		}

		.editable:hover {
			background: #e9ecef;
			cursor: pointer;
		}

		.readonly {
			background: #e9ecef;
		}

		.medicine-entry-row {
			display: grid;
			grid-template-columns: repeat(2, 1fr);
			gap: 15px;
			margin-bottom: 20px;
		}

		.input-group {
			position: relative;
			display: flex;
			align-items: center;
		}

		.input-symbol {
			position: absolute;
			right: 10px;
			color: #666;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container mt-4">
		<div class="row mb-3">
			<div class="col">
				<a href="javascript:history.back()" class="btn btn-secondary mb-3">
					<i class="bi bi-arrow-left"></i> Back
				</a>
				<h2>View/Edit Purchase</h2>
			</div>
		</div>
		<div id="billContent">Loading...</div>
	</div>

	<!-- Edit Modal -->
	<div class="modal fade" id="editModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Edit Purchase Details</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<form id="editForm">
					<div class="modal-body">
						<input type="hidden" name="purchase_id" id="edit_purchase_id">
						<div class="mb-3">
							<label>Supplier:</label>
							<select class="form-control" name="supplier_id" id="edit_supplier" required>
								<option value="">Select Supplier</option>
							</select>
						</div>
						<div class="mb-3">
							<label>Invoice Number:</label>
							<input type="text" class="form-control" name="invoice_number" id="edit_invoice">
						</div>
						<div class="mb-3">
							<label>Purchase Date:</label>
							<input type="date" class="form-control" name="purchase_date" id="edit_purchase_date">
						</div>
						<div class="mb-3">
							<label>Invoice Date:</label>
							<input type="date" class="form-control" name="invoice_date" id="edit_invoice_date">
						</div>
						<div class="mb-3">
							<label>Payment Status:</label>
							<select class="form-control" name="payment_status" id="edit_payment_status">
								<option value="PENDING">Pending</option>
								<option value="PAID">Paid</option>
								<option value="PARTIALLY_PAID">Partially Paid</option>
							</select>
						</div>
						<div class="mb-3">
							<label>Notes:</label>
							<textarea class="form-control" name="notes" id="edit_notes"></textarea>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn btn-primary">Save changes</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Add this modal for editing item details -->
	<div class="modal fade" id="editItemModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Edit Medicine Details</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<form id="editItemForm">
					<div class="modal-body">
						<input type="hidden" name="item_id" id="edit_item_id">
						<input type="hidden" name="purchase_id" id="edit_item_purchase_id">
						<div class="mb-3">
							<label>Medicine:</label>
							<input type="text" class="form-control" id="edit_item_name" readonly>
						</div>
						<div class="mb-3">
							<label>Quantity:</label>
							<input type="number" class="form-control" name="quantity" id="edit_item_quantity" min="1"
								required>
						</div>
						<div class="mb-3">
							<label>Purchase Rate:</label>
							<input type="number" class="form-control" name="purchase_rate" id="edit_item_purchase_rate"
								step="0.01" required>
						</div>
						<div class="mb-3">
							<label>Retail Price:</label>
							<input type="number" class="form-control" name="retail_price" id="edit_item_retail_price"
								step="0.01" required>
						</div>
						<div class="mb-3">
							<label>Expiry Date:</label>
							<input type="date" class="form-control" name="expiry_date" id="edit_item_expiry_date"
								required>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-danger" onclick="deleteItem()">Delete Item</button>
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn btn-primary">Save changes</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Add Medicine Modal -->
	<div class="modal fade" id="addMedicineModal" tabindex="-1">
		<div class="modal-dialog modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Add Medicine</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<form id="addMedicineForm">
					<div class="modal-body">
						<input type="hidden" name="purchase_id"
							value="<?php echo htmlspecialchars($_GET['id']); ?>">

						<!-- Medicine Entry Row -->
						<div class="form-row medicine-entry-row">
							<div class="form-group medicine-select-group">
								<label>Medicine:</label>
								<div style="display: flex; gap: 10px; align-items: center;">
									<select class="medicine-select" name="item_id" style="width: 100%"
										required></select>
								</div>
							</div>
							<div class="form-group">
								<label>Manufacturer:</label>
								<input type="text" name="manufacturer" id="manufacturer" readonly>
							</div>
							<div class="form-group">
								<label>Active Ingredient:</label>
								<input type="text" name="active_ingredient" id="active_ingredient" readonly>
							</div>
							<div class="form-group">
								<label>Batch:</label>
								<input type="text" name="batch_number" required>
							</div>
							<div class="form-group">
								<label>Quantity:</label>
								<input type="number" name="quantity" min="1" required>
							</div>
							<div class="form-group">
								<label>Purchase Rate:</label>
								<input type="number" name="purchase_rate" step="0.01" required>
							</div>
							<div class="form-group">
								<label>Retail Price:</label>
								<input type="number" name="retail_price" step="0.01" required>
							</div>
							<div class="form-group">
								<label>Expiry:</label>
								<input type="date" name="expiry_date" required>
							</div>
							<div class="form-group">
								<label>Tax %:</label>
								<input type="number" name="tax_percentage" id="tax_percentage" value="5" min="0"
									max="100" step="0.01" style="width: 100%;">
							</div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn-primary">Add Medicine</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Move scripts here -->
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
	<script>
		const purchaseId = new URLSearchParams(window.location.search).get('id');
		const editModal = new bootstrap.Modal(document.getElementById('editModal'));
		const editItemModal = new bootstrap.Modal(document.getElementById('editItemModal'));
		const addMedicineModal = new bootstrap.Modal(document.getElementById('addMedicineModal'));

		function loadPurchaseDetails() {
			$.post('view_purchase_bill.php', {
				action: 'get_details',
				purchase_id: purchaseId
			}, function(response) {
				if (response.status === 'success') {
					displayPurchaseDetails(response.purchase, response.items);
				} else {
					alert('Error loading purchase details');
				}
			}).fail(function() {
				alert('Error loading purchase details');
			});
		}

		function displayPurchaseDetails(purchase, items) {
			const itemsHtml = items.map(item => {
				// Format retail price per unit
				const retailPricePerUnit = parseFloat(item.retail_price).toFixed(2);
				const purchaseRate = parseFloat(item.unit_price).toFixed(2);
				const finalAmount = parseFloat(item.final_amount).toFixed(2);

				return `
                    <tr>
                        <td>${item.medicine_name}</td>
                        <td>${item.batch_number}</td>
                        <td>${item.quantity}</td>
                        <td>₹${purchaseRate}</td>
                        <td>₹${retailPricePerUnit}</td>
                        <td>${item.expiry_date}</td>
                        <td>₹${finalAmount}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" 
                                    onclick="editItem(${JSON.stringify(item).replace(/"/g, '&quot;')})">
                                Edit
                            </button>
                        </td>
                    </tr>
                `;
			}).join('');

			$('#billContent').html(`
                <div class="bill-details">
                    <div class="row">
                        <div class="col-md-6">
                            <h3>Purchase Details</h3>
                            <p><strong>Supplier:</strong> ${purchase.supplier_name || 'Not specified'}</p>
                            <p><strong>Invoice:</strong> ${purchase.invoice_number || 'Not specified'}</p>
                            <p><strong>Date:</strong> ${purchase.purchase_date || 'Not specified'}</p>
                        </div>
                        <div class="col-md-6 text-end">
                            <button class="btn btn-success me-2" onclick="addMedicineModal.show()">
                                Add Medicine
                            </button>
                            <button class="btn btn-primary" onclick="editPurchase(${purchase.purchase_id})">
                                Edit Details
                            </button>
                        </div>
                    </div>
                </div>
                
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Batch</th>
                            <th>Quantity</th>
                            <th>Purchase Rate</th>
                            <th>Retail Price</th>
                            <th>Expiry</th>
                            <th>Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="text-end"><strong>Subtotal:</strong></td>
                            <td>₹${parseFloat(purchase.subtotal).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="7" class="text-end"><strong>Discount Amount:</strong></td>
                            <td>₹${parseFloat(purchase.discount_amount || 0).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="7" class="text-end"><strong>Tax Amount:</strong></td>
                            <td>₹${parseFloat(purchase.tax_amount).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="7" class="text-end"><strong>Total Amount:</strong></td>
                            <td>₹${parseFloat(purchase.final_amount).toFixed(2)}</td>
                        </tr>
                    </tfoot>
                </table>
            `);
		}

		function editPurchase(id) {
			// Load purchase data first then show modal
			$.post('view_purchase_bill.php', {
				action: 'get_details',
				purchase_id: id
			}, function(response) {
				if (response.status === 'success') {
					const purchase = response.purchase;
					$('#edit_purchase_id').val(purchase.purchase_id);

					// Populate supplier dropdown
					const supplierSelect = $('#edit_supplier');
					supplierSelect.empty();
					supplierSelect.append('<option value="">Select Supplier</option>');
					response.suppliers.forEach(supplier => {
						supplierSelect.append(
							$('<option></option>')
							.val(supplier.supplier_id)
							.text(supplier.supplier_name)
							.prop('selected', supplier.supplier_id == purchase.supplier_id)
						);
					});

					$('#edit_invoice').val(purchase.invoice_number);
					$('#edit_purchase_date').val(purchase.purchase_date);
					$('#edit_invoice_date').val(purchase.invoice_date);
					$('#edit_payment_status').val(purchase.payment_status);
					$('#edit_notes').val(purchase.notes || '');
					editModal.show();
				} else {
					alert('Error loading purchase details');
				}
			}).fail(function() {
				alert('Error loading purchase details');
			});
		}

		$('#editForm').on('submit', function(e) {
			e.preventDefault();
			const formData = new FormData(this);
			formData.append('action', 'save');

			$.ajax({
				url: 'view_purchase_bill.php',
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					if (response.status === 'success') {
						alert(response.message);
						editModal.hide();
						loadPurchaseDetails();
					} else {
						alert('Error: ' + response.message);
					}
				},
				error: function() {
					alert('Error saving changes');
				}
			});
		});

		// Load details on page load
		if (purchaseId) {
			loadPurchaseDetails();
		} else {
			$('#billContent').html('<div class="alert alert-danger">No purchase ID specified</div>');
		}

		function editItem(item) {
			$('#edit_item_id').val(item.item_id);
			$('#edit_item_purchase_id').val(purchaseId);
			$('#edit_item_name').val(item.medicine_name);
			$('#edit_item_quantity').val(item.quantity);
			$('#edit_item_purchase_rate').val(item.unit_price);
			$('#edit_item_retail_price').val(item.retail_price);
			$('#edit_item_expiry_date').val(item.expiry_date);
			editItemModal.show();
		}

		$('#editItemForm').on('submit', function(e) {
			e.preventDefault();
			const formData = new FormData(this);
			formData.append('action', 'update_item');

			$.ajax({
				url: 'view_purchase_bill.php',
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					if (response.status === 'success') {
						alert(response.message);
						editItemModal.hide();
						loadPurchaseDetails();
					} else {
						alert('Error: ' + response.message);
					}
				},
				error: function() {
					alert('Error updating item');
				}
			});
		});

		function deleteItem() {
			if (!confirm('Are you sure you want to delete this item?')) return;

			$.post('view_purchase_bill.php', {
				action: 'delete_item',
				item_id: $('#edit_item_id').val(),
				purchase_id: $('#edit_item_purchase_id').val()
			}, function(response) {
				if (response.status === 'success') {
					alert(response.message);
					editItemModal.hide();
					loadPurchaseDetails();
				} else {
					alert('Error: ' + response.message);
				}
			}).fail(function() {
				alert('Error deleting item');
			});
		}

		// Add form submission handler
		$('#addMedicineForm').on('submit', function(e) {
			e.preventDefault();

			const requiredFields = [{
					id: 'batch_number',
					message: 'Enter batch number'
				},
				{
					id: 'quantity',
					message: 'Enter quantity'
				},
				{
					id: 'purchase_rate',
					message: 'Enter purchase rate'
				},
				{
					id: 'retail_price',
					message: 'Enter retail price'
				},
				{
					id: 'expiry_date',
					message: 'Select expiry date'
				}
			];

			// Find first empty required field
			const emptyField = requiredFields.find(field =>
				!$(this).find(`[name="${field.id}"]`).val()
			);

			if (emptyField) {
				showTooltip($(this).find(`[name="${emptyField.id}"]`), emptyField.message);
				return;
			}

			const formData = new FormData(this);
			formData.append('action', 'add_item');

			$.ajax({
				url: window.location.href,
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					if (response.status === 'success') {
						addMedicineModal.hide();
						loadPurchaseDetails();
						$('#addMedicineForm').trigger('reset');
					}
					alert(response.message);
				},
				error: function() {
					alert('Error adding medicine');
				}
			});
		});

		// Initialize medicine select2
		$('.medicine-select').select2({
			dropdownParent: $('#addMedicineModal'),
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
					return {
						results: data.results || []
					};
				},
				cache: true
			},
			minimumInputLength: 3,
			placeholder: 'Search medicine...'
		}).on('select2:select', function(e) {
			const data = e.params.data;
			// Auto-fill fields
			$(this).closest('form').find('[name="manufacturer"]').val(data.manufacturer || '');
			$(this).closest('form').find('[name="active_ingredient"]').val(data.active_ingredient || '');
		});
	</script>
</body>

</html>