<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Check module permissions
if (!canAccessSupplierReturns()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$canCreateSupplierReturn = canCreateSupplierReturn();
$canDeleteSupplierReturn = canDeleteSupplierReturn();

if (!checkSessionTimeout()) {
    header('Location: ../auth/login.php?timeout=1');
    exit;
}

$conn = getDbConnection();

if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ('get_batches_by_item' === $_POST['action']) {
        try {
            $item_id = sanitizeInteger($_POST['item_id'] ?? null, 1);
            if (!$item_id) {
                throw new Exception('Item is required');
            }

            $stmt = $conn->prepare('SELECT b.batch_id, b.batch_number, b.current_quantity, b.purchase_rate, b.expiry_date,
                                           b.supplier_id, b.item_id, MAX(p.purchase_id) AS purchase_id, MAX(p.invoice_number) AS invoice_number, 
                                           MAX(p.purchase_date) AS purchase_date, s.supplier_name, i.name AS item_name, i.manufacturer
                                    FROM pharmacy_batches b
                                    INNER JOIN pharmacy_items i ON i.item_id = b.item_id
                                    LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
                                    LEFT JOIN pharmacy_transaction_items pti ON pti.batch_id = b.batch_id
                                    LEFT JOIN pharmacy_purchases p ON p.transaction_id = pti.transaction_id
                                    WHERE b.item_id = ?
                                      AND b.current_quantity > 0
                                    GROUP BY b.batch_id, b.batch_number, b.current_quantity, b.purchase_rate, b.expiry_date, 
                                             b.supplier_id, b.item_id, s.supplier_name, i.name, i.manufacturer
                                    ORDER BY b.expiry_date, b.batch_number');
            $stmt->bind_param('i', $item_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Format purchase_rate with decimals
            foreach ($rows as &$row) {
                $row['purchase_rate'] = number_format((float) $row['purchase_rate'], 2, '.', '');
            }

            echo json_encode(['status' => 'success', 'batches' => $rows]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ('save_return' === $_POST['action']) {
        if (!$canCreateSupplierReturn) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $return_date = sanitizeInput($_POST['return_date'] ?? '');
            $notes = sanitizeInput($_POST['notes'] ?? '');
            $rawItems = json_decode($_POST['items'] ?? '[]', true);

            if (empty($rawItems) || !is_array($rawItems)) {
                throw new Exception('Please add at least one return item');
            }
            if (!$return_date || !strtotime($return_date)) {
                throw new Exception('Valid return date is required');
            }
            if (strtotime($return_date) > strtotime(date('Y-m-d'))) {
                throw new Exception('Return date cannot be in the future');
            }

            // Extract supplier and purchase from first batch
            $firstBatchId = sanitizeInteger($rawItems[0]['batch_id'] ?? null, 1);
            $firstBatchStmt = $conn->prepare('SELECT b.supplier_id, pti.transaction_id, p.purchase_id
                                             FROM pharmacy_batches b
                                             LEFT JOIN pharmacy_transaction_items pti ON pti.batch_id = b.batch_id
                                             LEFT JOIN pharmacy_purchases p ON p.transaction_id = pti.transaction_id
                                             WHERE b.batch_id = ?');
            $firstBatchStmt->bind_param('i', $firstBatchId);
            $firstBatchStmt->execute();
            $firstBatch = $firstBatchStmt->get_result()->fetch_assoc();

            if (!$firstBatch || !$firstBatch['supplier_id'] || !$firstBatch['purchase_id']) {
                throw new Exception('Invalid batch or batch has no supplier/purchase info');
            }

            $supplier_id = (int) $firstBatch['supplier_id'];
            $purchase_id = (int) $firstBatch['purchase_id'];

            $return_number = strtoupper(generateTransactionId('RET'));

            $stmt = $conn->prepare('INSERT INTO pharmacy_supplier_returns
                (return_number, supplier_id, purchase_id, return_date, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('siissi', $return_number, $supplier_id, $purchase_id, $return_date, $notes, $_SESSION['user_id']);
            $stmt->execute();
            $return_id = (int) $conn->insert_id;

            foreach ($rawItems as $item) {
                $batch_id = sanitizeInteger($item['batch_id'] ?? null, 1);
                $quantity = sanitizeInteger($item['quantity'] ?? null, 1);
                $deduction_amount = sanitizeNumeric($item['deduction_amount'] ?? 0);
                $tax_percentage = sanitizeNumeric($item['tax_percentage'] ?? 0);
                $notes = sanitizeInput($item['notes'] ?? '');

                if (!$batch_id || !$quantity) {
                    throw new Exception('Invalid item/batch/quantity in return items');
                }
                if ($deduction_amount < 0) {
                    throw new Exception('Deduction amount cannot be negative');
                }
                if ($tax_percentage < 0 || $tax_percentage > 100) {
                    throw new Exception('Tax percentage must be between 0 and 100');
                }

                $lockStmt = $conn->prepare('SELECT batch_id, item_id, supplier_id, current_quantity, purchase_rate
                                           FROM pharmacy_batches
                                           WHERE batch_id = ?
                                           FOR UPDATE');
                $lockStmt->bind_param('i', $batch_id);
                $lockStmt->execute();
                $batch = $lockStmt->get_result()->fetch_assoc();

                if (!$batch) {
                    throw new Exception('Batch not found');
                }
                if ((int) $batch['supplier_id'] !== $supplier_id) {
                    throw new Exception('All batches must be from the same supplier');
                }

                // Verify batch belongs to same purchase
                $batchPurchaseStmt = $conn->prepare('SELECT p.purchase_id
                                                    FROM pharmacy_transaction_items pti
                                                    INNER JOIN pharmacy_purchases p ON p.transaction_id = pti.transaction_id
                                                    WHERE pti.batch_id = ?');
                $batchPurchaseStmt->bind_param('i', $batch_id);
                $batchPurchaseStmt->execute();
                $batchPurchase = $batchPurchaseStmt->get_result()->fetch_assoc();
                if (!$batchPurchase || (int) $batchPurchase['purchase_id'] !== $purchase_id) {
                    throw new Exception('All batches must be from the same purchase voucher');
                }

                if ($quantity > (int) $batch['current_quantity']) {
                    throw new Exception('Return quantity exceeds available stock for batch #'.$batch_id);
                }

                $unit_rate = (float) $batch['purchase_rate'];
                $item_id = (int) $batch['item_id'];

                // Calculate base amount
                $base_amount = round($quantity * $unit_rate, 2);

                if ($deduction_amount > $base_amount) {
                    throw new Exception('Deduction cannot exceed base amount for batch #'.$batch_id);
                }

                // Keep initial_quantity unchanged for audit trail (original purchased qty).
                // Returns only reduce live stock (current_quantity) and are tracked in return tables.
                $updateStmt = $conn->prepare('UPDATE pharmacy_batches
                    SET current_quantity = current_quantity - ?
                    WHERE batch_id = ?');
                $updateStmt->bind_param('ii', $quantity, $batch_id);
                $updateStmt->execute();

                $insertItemStmt = $conn->prepare('INSERT INTO pharmacy_supplier_return_items
                    (item_id, return_id, batch_id, quantity, unit_rate, 
                     deduction_amount, tax_percentage, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $insertItemStmt->bind_param(
                    'iiiiidds',
                    $item_id,
                    $return_id,
                    $batch_id,
                    $quantity,
                    $unit_rate,
                    $deduction_amount,
                    $tax_percentage,
                    $notes
                );
                $insertItemStmt->execute();
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Supplier return saved and stock updated']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ('delete_return' === $_POST['action']) {
        if (!$canDeleteSupplierReturn) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $return_id = sanitizeInteger($_POST['return_id'] ?? null, 1);
            if (!$return_id) {
                throw new Exception('Invalid return id');
            }

            $itemStmt = $conn->prepare('SELECT batch_id, quantity FROM pharmacy_supplier_return_items WHERE return_id = ?');
            $itemStmt->bind_param('i', $return_id);
            $itemStmt->execute();
            $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (empty($items)) {
                throw new Exception('No return items found');
            }

            foreach ($items as $item) {
                $qty = (int) $item['quantity'];
                $batch_id = (int) $item['batch_id'];

                $restoreStmt = $conn->prepare('UPDATE pharmacy_batches
                                               SET current_quantity = current_quantity + ?
                                               WHERE batch_id = ?');
                $restoreStmt->bind_param('ii', $qty, $batch_id);
                $restoreStmt->execute();
            }

            $deleteStmt = $conn->prepare('DELETE FROM pharmacy_supplier_returns WHERE return_id = ?');
            $deleteStmt->bind_param('i', $return_id);
            $deleteStmt->execute();

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Return deleted and stock restored']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$returnsStmt = $conn->prepare('SELECT r.return_id, r.return_number, r.return_date, r.notes,
                                      s.supplier_name, u.username AS created_by_name,
                                      p.invoice_number, p.purchase_date,
                                      COALESCE(SUM(ri.quantity * ri.unit_rate), 0) as base_total,
                                      COALESCE(SUM(ri.deduction_amount), 0) as deduction_total,
                                      COALESCE(SUM(
                                          ((ri.quantity * ri.unit_rate - ri.deduction_amount) * ri.tax_percentage / 100)
                                      ), 0) as tax_total,
                                      COALESCE(SUM(
                                          (ri.quantity * ri.unit_rate - ri.deduction_amount) * (1 + ri.tax_percentage / 100)
                                      ), 0) as total_amount
                               FROM pharmacy_supplier_returns r
                               INNER JOIN suppliers s ON s.supplier_id = r.supplier_id
                               INNER JOIN pharmacy_purchases p ON p.purchase_id = r.purchase_id
                               LEFT JOIN users u ON u.user_id = r.created_by
                               LEFT JOIN pharmacy_supplier_return_items ri ON ri.return_id = r.return_id
                               GROUP BY r.return_id, r.return_number, r.return_date, r.notes,
                                        s.supplier_name, u.username, p.invoice_number, p.purchase_date
                               ORDER BY r.return_date DESC, r.return_id DESC
                               LIMIT 100');
$returnsStmt->execute();
$returns = $returnsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$returnItemsMap = [];
if (!empty($returns)) {
    $returnIds = array_map(static fn ($row) => (int) $row['return_id'], $returns);
    $idPlaceholders = implode(',', array_fill(0, count($returnIds), '?'));
    $idTypes = str_repeat('i', count($returnIds));

    $itemsSql = "SELECT ri.return_id, ri.batch_id, ri.quantity, ri.unit_rate, ri.deduction_amount, ri.tax_percentage, ri.notes,
                        b.batch_number,
                        i.name AS item_name, i.manufacturer
                 FROM pharmacy_supplier_return_items ri
                 INNER JOIN pharmacy_batches b ON b.batch_id = ri.batch_id
                 INNER JOIN pharmacy_items i ON i.item_id = b.item_id
                 WHERE ri.return_id IN ({$idPlaceholders})
                 ORDER BY ri.created_at, ri.batch_id";

    $itemsStmt = $conn->prepare($itemsSql);
    $itemsStmt->bind_param($idTypes, ...$returnIds);
    $itemsStmt->execute();
    $rows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $rid = (int) $row['return_id'];
        if (!isset($returnItemsMap[$rid])) {
            $returnItemsMap[$rid] = [];
        }
        $returnItemsMap[$rid][] = $row;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Supplier Returns</title>
	<link href="../../assets/css/cdn/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<script src="../../assets/js/cdn/bootstrap.min.js"></script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container py-3">
		<div class="d-flex justify-content-between align-items-center mb-3">
			<h3 class="mb-0">Supplier Medicine Return</h3>
			<a href="manage_purchases.php" class="btn btn-outline-secondary btn-sm">Manage Purchases</a>
		</div>

		<div class="card mb-4">
			<div class="card-header">Create Return</div>
			<div class="card-body">
				<?php if (!$canCreateSupplierReturn) { ?>
				<div class="alert alert-warning py-2 mb-3" role="alert">
					You have view-only access. Create permission is required to add supplier returns.
				</div>
				<?php } ?>
				<?php if ($canCreateSupplierReturn) { ?>
				<div class="alert alert-info py-2 mb-3" role="alert">
					Only medicines from the same supplier and the same purchase bill can be added in one return form.
				</div>
				<form id="supplierReturnForm">
					<div class="row g-3 mb-3">
						<div class="col-md-3">
							<label class="form-label">Supplier (Auto)</label>
							<input type="text" class="form-control" id="supplier_display" readonly
								placeholder="Will auto-fill">
							<input type="hidden" id="supplier_id" name="supplier_id">
						</div>
						<div class="col-md-3">
							<label class="form-label">Purchase Invoice (Auto)</label>
							<input type="text" class="form-control" id="invoice_display" readonly
								placeholder="Will auto-fill">
							<input type="hidden" id="purchase_id" name="purchase_id">
						</div>
						<div class="col-md-3">
							<label class="form-label">Return Date</label>
							<input type="date" class="form-control" id="return_date" name="return_date"
								value="<?php echo date('Y-m-d'); ?>"
								max="<?php echo date('Y-m-d'); ?>"
								required>
						</div>
						<div class="col-md-3">
							<label class="form-label">Return Notes</label>
							<input type="text" class="form-control" id="notes" name="notes"
								placeholder="Optional notes for this return">
						</div>
					</div>

					<div class="row g-3 align-items-end mb-3">
						<div class="col-md-3">
							<label class="form-label">Search Item</label>
							<select class="form-control" id="item_id" style="width:100%"></select>
						</div>
						<div class="col-md-3">
							<label class="form-label">Select Batch</label>
							<select class="form-control" id="batch_id" style="width:100%"></select>
						</div>
						<div class="col-md-1">
							<label class="form-label">Available</label>
							<input type="text" class="form-control" id="available_qty" readonly>
						</div>
						<div class="col-md-1">
							<label class="form-label">Return Qty</label>
							<input type="number" class="form-control" id="return_qty" min="1" step="1">
						</div>
						<div class="col-md-1">
							<label class="form-label">Rate</label>
							<input type="text" class="form-control" id="batch_rate" readonly>
						</div>
						<div class="col-md-1">
							<label class="form-label">Deduction ₹</label>
							<input type="number" class="form-control" id="deduction_amount" min="0" step="0.01"
								value="0" placeholder="0.00">
						</div>
						<div class="col-md-1">
							<label class="form-label">Tax %</label>
							<input type="number" class="form-control" id="tax_percentage" min="0" max="100" step="0.01"
								value="0" placeholder="0">
						</div>
						<div class="col-md-3">
							<label class="form-label">Item Notes</label>
							<input type="text" class="form-control" id="return_notes"
								placeholder="Optional note for this item">
						</div>
						<div class="col-md-2">
						</div>

						<div class="d-flex justify-content-end mb-3">
							<button type="button" class="btn btn-primary" id="addReturnItem">Add Item</button>
						</div>

						<div class="table-responsive mb-3">
							<table class="table table-bordered" id="returnItemsTable">
								<thead>
									<tr>
										<th>Medicine</th>
										<th>Batch</th>
										<th>Qty</th>
										<th>Rate</th>
										<th>Base</th>
										<th>Deduction ₹</th>
										<th>Tax %</th>
										<th>Tax ₹</th>
										<th>Final</th>
										<th>Notes</th>
										<th>Action</th>
									</tr>
								</thead>
								<tbody></tbody>
								<tfoot>
									<tr>
										<th colspan="4" class="text-end">Total</th>
										<th id="baseTotal">0.00</th>
										<th id="deductionTotal">0.00</th>
										<th colspan="1"></th>
										<th id="taxTotal">0.00</th>
										<th id="returnTotal">0.00</th>
										<th colspan="2"></th>
									</tr>
								</tfoot>
							</table>
						</div>

						<div class="d-flex justify-content-end">
							<button type="submit" class="btn btn-success">Save Return</button>
						</div>
				</form>
				<?php } ?>
			</div>
		</div>

		<div class="card">
			<div class="card-header">Recent Supplier Returns</div>
			<div class="card-body">
				<div class="table-responsive">
					<table class="table table-striped">
						<thead>
							<tr>
								<th>Date</th>
								<th>Return #</th>
								<th>Invoice #</th>
								<th>Supplier</th>
								<th>Amount</th>
								<th>Created By</th>
								<th>Items</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($returns)) { ?>
							<tr>
								<td colspan="8" class="text-center text-muted">No supplier returns found</td>
							</tr>
							<?php } ?>
							<?php foreach ($returns as $return) {
							    $rid = (int) $return['return_id'];
							    $items = $returnItemsMap[$rid] ?? [];
							    ?>
							<tr>
								<td><?php echo htmlspecialchars(date('d-m-Y', strtotime($return['return_date']))); ?>
								</td>
								<td><?php echo htmlspecialchars($return['return_number']); ?>
								</td>
								<td><?php echo htmlspecialchars($return['invoice_number']); ?>
								</td>
								<td><?php echo htmlspecialchars($return['supplier_name']); ?>
								</td>
								<td>₹<?php echo number_format((float) $return['total_amount'], 2); ?>
								</td>
								<td><?php echo htmlspecialchars($return['created_by_name'] ?? '-'); ?>
								</td>
								<td>
									<?php if (empty($items)) { ?>
									<span class="text-muted">No items</span>
									<?php } else { ?>
									<ul class="mb-0 ps-3">
										<?php foreach ($items as $item) { ?>
										<li>
											<?php echo htmlspecialchars($item['item_name']); ?>
											(Batch:
											<?php echo htmlspecialchars($item['batch_number']); ?>,
											Qty:
											<?php echo (int) $item['quantity']; ?>)
										</li>
										<?php } ?>
									</ul>
									<?php } ?>
								</td>
								<td>
									<?php if ($canDeleteSupplierReturn) { ?>
									<button type="button" class="btn btn-sm btn-danger delete-return"
										data-id="<?php echo $rid; ?>">Delete</button>
									<?php } else { ?>
									<span class="text-muted">No action</span>
									<?php } ?>
								</td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<script src="../../assets/js/pharmacy_return.js"></script>
</body>

</html>