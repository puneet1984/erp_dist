<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check permission
if (!checkPermission('bills', 'edit')) {
    header('Location: ../../unauthorized.php');
    exit;
}

if (!isset($_GET['id'])) {
    exit('Transaction ID not provided');
}

$transaction_id = sanitizeInput($_GET['id']);
$conn = getDbConnection();

// AJAX: Get available batches for a selected medicine (sale.php parity)
if ('GET' === $_SERVER['REQUEST_METHOD'] && isset($_GET['item_id'])) {
	$stmt = $conn->prepare('SELECT pb.batch_id, pb.batch_number, pb.current_quantity,
								   pb.retail_price, pb.expiry_date, pi.name, pi.manufacturer,
								   pi.active_ingredient, pi.dosage_form
							FROM pharmacy_batches pb
							JOIN pharmacy_items pi ON pb.item_id = pi.item_id
							WHERE pb.item_id = ? AND pb.current_quantity > 0
							ORDER BY pb.expiry_date ASC');
	$itemId = (int) $_GET['item_id'];
	$stmt->bind_param('i', $itemId);
	$stmt->execute();
	header('Content-Type: application/json');
	echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
	exit;
}

// Handle form submission
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        $conn->begin_transaction();

        // Get current item details before updating for stock management
        $current_items = [];
        $stmt = $conn->prepare('
            SELECT item_id, batch_id, quantity
            FROM pharmacy_transaction_items 
            WHERE transaction_id = ?
        ');
        $stmt->bind_param('s', $transaction_id);
        $stmt->execute();
        $current_result = $stmt->get_result();
        while ($row = $current_result->fetch_assoc()) {
            $current_items[$row['item_id']] = $row;
        }

		if (!isset($_POST['items']) || !is_array($_POST['items'])) {
			throw new Exception('No bill items were submitted');
		}

		// Update each item's batch or remove item from bill
        foreach ($_POST['items'] as $item_id => $item_data) {
			$item_id = (int) $item_id;
			if (!isset($current_items[$item_id])) {
				throw new Exception('Invalid item in update request');
			}

			if (!is_array($item_data)) {
				throw new Exception('Invalid item payload');
			}

			$current_item = $current_items[$item_id];
			$old_batch_id = $current_item['batch_id'];
			$old_quantity = $current_item['quantity'];
			$is_deleted = isset($item_data['delete']) && '1' === (string) $item_data['delete'];

			if ($is_deleted) {
				// Restore stock for deleted line item.
				$stmt = $conn->prepare('UPDATE pharmacy_batches
										SET current_quantity = current_quantity + ?
										WHERE batch_id = ?');
				$stmt->bind_param('di', $old_quantity, $old_batch_id);
				$stmt->execute();

				$stmt = $conn->prepare('DELETE FROM pharmacy_transaction_items
										WHERE item_id = ? AND transaction_id = ?');
				$stmt->bind_param('is', $item_id, $transaction_id);
				$stmt->execute();
				continue;
			}

			if (!isset($item_data['batch_id'], $item_data['quantity'], $item_data['unit_price'], $item_data['discount'])) {
				throw new Exception('Missing fields for one or more bill items');
			}

            $new_batch_id = sanitizeInput($item_data['batch_id']);
            $quantity = (float) sanitizeInput($item_data['quantity']);
            $unit_price = (float) sanitizeInput($item_data['unit_price']);
            $discount = (float) sanitizeInput($item_data['discount']);

			if ($quantity <= 0) {
				throw new Exception('Quantity must be greater than 0 for non-deleted items');
			}

			$final_amount = ($quantity * $unit_price) - $discount;

            // If batch or quantity changed, manage stock
            if ($old_batch_id != $new_batch_id || $old_quantity != $quantity) {

                // If batch changed, restore stock to old batch
                if ($old_batch_id != $new_batch_id) {
                    $stmt = $conn->prepare('
                        UPDATE pharmacy_batches 
                        SET current_quantity = current_quantity + ?
                        WHERE batch_id = ?
                    ');
                    $stmt->bind_param('di', $old_quantity, $old_batch_id);
                    $stmt->execute();
                }

                // Check if new batch has sufficient stock
                $stmt = $conn->prepare('
                    SELECT current_quantity, batch_number 
                    FROM pharmacy_batches 
                    WHERE batch_id = ?
                ');
                $stmt->bind_param('i', $new_batch_id);
                $stmt->execute();
                $batch_info = $stmt->get_result()->fetch_assoc();

                $available_stock = $batch_info['current_quantity'];

                // If same batch but quantity changed, adjust available stock calculation
                if ($old_batch_id == $new_batch_id) {
                    $available_stock += $old_quantity; // Add back the old quantity
                }

                if ($available_stock < $quantity) {
                    throw new Exception("Insufficient stock in batch {$batch_info['batch_number']}. Available: {$available_stock}, Required: {$quantity}");
                }

                // Deduct stock from new batch
                $stock_to_deduct = $quantity;
                if ($old_batch_id == $new_batch_id) {
                    $stock_to_deduct = $quantity - $old_quantity; // Net change
                }

                if (0 != $stock_to_deduct) {
                    $stmt = $conn->prepare('
                        UPDATE pharmacy_batches 
                        SET current_quantity = current_quantity - ?
                        WHERE batch_id = ?
                    ');
                    $stmt->bind_param('di', $stock_to_deduct, $new_batch_id);
                    $stmt->execute();
                }
            }

            // Update transaction item
            $stmt = $conn->prepare('
                UPDATE pharmacy_transaction_items 
                SET batch_id = ?, quantity = ?, unit_price = ?, discount_amount = ?, final_amount = ?
                WHERE item_id = ? AND transaction_id = ?
            ');
            $stmt->bind_param('iddddis', $new_batch_id, $quantity, $unit_price, $discount, $final_amount, $item_id, $transaction_id);
            $stmt->execute();
        }

		// Add new items (sale.php-style)
		if (isset($_POST['new_items']) && is_array($_POST['new_items'])) {
			foreach ($_POST['new_items'] as $newItem) {
				if (!is_array($newItem)) {
					continue;
				}

				$batchId = (int) sanitizeInput($newItem['batch_id'] ?? 0);
				$quantity = (float) sanitizeInput($newItem['quantity'] ?? 0);
				$unitPrice = (float) sanitizeInput($newItem['unit_price'] ?? 0);
				$discount = (float) sanitizeInput($newItem['discount'] ?? 0);
				$medicineName = sanitizeInput($newItem['medicine_name'] ?? 'Medicine');

				if ($batchId <= 0) {
					continue;
				}
				if ($quantity <= 0) {
					throw new Exception('Quantity must be greater than 0 for new items');
				}

				$batchStmt = $conn->prepare('SELECT batch_id, current_quantity, batch_number
										 FROM pharmacy_batches
										 WHERE batch_id = ?
										 FOR UPDATE');
				$batchStmt->bind_param('i', $batchId);
				$batchStmt->execute();
				$batch = $batchStmt->get_result()->fetch_assoc();

				if (!$batch) {
					throw new Exception('Selected batch not found for new item');
				}
				if ((float) $batch['current_quantity'] < $quantity) {
					throw new Exception('Insufficient stock in batch '.$batch['batch_number'].' for '.$medicineName);
				}

				$deductStmt = $conn->prepare('UPDATE pharmacy_batches
									   SET current_quantity = current_quantity - ?
									   WHERE batch_id = ? AND current_quantity >= ?');
				$deductStmt->bind_param('dii', $quantity, $batchId, $quantity);
				$deductStmt->execute();
				if (0 === $deductStmt->affected_rows) {
					throw new Exception('Stock update failed while adding new item '.$medicineName);
				}

				$finalAmount = ($quantity * $unitPrice) - $discount;
				$taxPercentage = 0;
				$taxAmount = 0;
				$insertStmt = $conn->prepare('INSERT INTO pharmacy_transaction_items
									   (transaction_id, batch_id, quantity, unit_price,
									    tax_percentage, tax_amount, discount_amount, final_amount)
									   VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
				$insertStmt->bind_param(
					'siiddddd',
					$transaction_id,
					$batchId,
					$quantity,
					$unitPrice,
					$taxPercentage,
					$taxAmount,
					$discount,
					$finalAmount
				);
				if (!$insertStmt->execute()) {
					throw new Exception('Failed to add new item '.$medicineName);
				}
			}
		}

        // Recalculate totals
        $stmt = $conn->prepare('
            SELECT SUM(quantity * unit_price) as subtotal,
                   SUM(discount_amount) as total_discount,
                   SUM(final_amount) as net_total
            FROM pharmacy_transaction_items 
            WHERE transaction_id = ?
        ');
        $stmt->bind_param('s', $transaction_id);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();

		$stmt = $conn->prepare('SELECT COUNT(*) as item_count
								FROM pharmacy_transaction_items
								WHERE transaction_id = ?');
		$stmt->bind_param('s', $transaction_id);
		$stmt->execute();
		$item_count = (int) ($stmt->get_result()->fetch_assoc()['item_count'] ?? 0);

		if ($item_count <= 0) {
			throw new Exception('Bill cannot be empty. Delete the bill instead if needed.');
		}

		$subtotal = (float) ($totals['subtotal'] ?? 0);
		$total_discount = (float) ($totals['total_discount'] ?? 0);
		$net_total = (float) ($totals['net_total'] ?? 0);

        // Update pharmacy_sales totals
        $stmt = $conn->prepare('
            UPDATE pharmacy_sales 
            SET subtotal = ?, discount_amount = ?, final_amount = ?
            WHERE transaction_id = ?
        ');
		$stmt->bind_param('ddds', $subtotal, $total_discount, $net_total, $transaction_id);
        $stmt->execute();

        $conn->commit();
        header('Location: view_pharmacy_bill.php?id='.$transaction_id.'&success=1');
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Error updating bill: '.$e->getMessage();
    }
}

// Get sale details
$stmt = $conn->prepare('
    SELECT ps.*, p.name as patient_name, p.patient_id, p.address as patient_address, 
           p.city as patient_city, d.name as doctor_name
    FROM pharmacy_sales ps
    JOIN patients p ON ps.patient_id = p.patient_id
    LEFT JOIN doctors d ON ps.doctor_id = d.doctor_id
    WHERE ps.transaction_id = ?
');
$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    exit('Transaction not found');
}

// Get current items
$stmt = $conn->prepare('
    SELECT pti.*, pi.name as medicine_name, pi.manufacturer,
           pb.batch_number, pb.expiry_date, pb.item_id as medicine_id
    FROM pharmacy_transaction_items pti
    JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    WHERE pti.transaction_id = ?
');
$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all available batches for each medicine
$batch_options = [];
foreach ($items as $item) {
    $stmt = $conn->prepare('
        SELECT batch_id, batch_number, expiry_date, current_quantity
        FROM pharmacy_batches 
		WHERE item_id = ? AND (current_quantity > 0 OR batch_id = ?)
        ORDER BY expiry_date ASC
    ');
	$stmt->bind_param('ii', $item['medicine_id'], $item['batch_id']);
    $stmt->execute();
    $batch_options[$item['medicine_id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Edit Pharmacy Bill</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="row mb-3">
			<div class="col">
				<h2><i class="fas fa-edit"></i> Edit Pharmacy Bill</h2>
			</div>
			<div class="col text-end">
				<a href="view_pharmacy_bill.php?id=<?php echo $transaction_id; ?>"
					class="btn btn-secondary">
					<i class="fas fa-arrow-left"></i> Back to Bill
				</a>
			</div>
		</div>

		<?php if (isset($error)) { ?>
		<div class="alert alert-danger">
			<i class="fas fa-exclamation-triangle"></i>
			<?php echo htmlspecialchars($error); ?>
		</div>
		<?php } ?>

		<?php if ('PAID' === $sale['payment_status']) { ?>
		<div class="alert alert-warning">
			<i class="fas fa-exclamation-triangle"></i>
			<strong>Warning:</strong> This bill has already been paid. Edit only if there was a data entry mistake.
		</div>
		<?php } ?>

		<div class="card mb-3">
			<div class="card-body">
				<div class="row">
					<div class="col-md-6">
						<h5>Bill Information</h5>
						<p><strong>Bill No:</strong>
							<?php echo htmlspecialchars($sale['bill_id']); ?>
						</p>
						<p><strong>Date:</strong>
							<?php echo formatDisplayDate($sale['sale_date']); ?>
						</p>
						<p><strong>Status:</strong>
							<?php echo $sale['payment_status']; ?>
						</p>
					</div>
					<div class="col-md-6">
						<h5>Patient Information</h5>
						<p><strong>Name:</strong>
							<?php echo htmlspecialchars($sale['patient_name']); ?>
						</p>
						<p><strong>ID:</strong>
							<?php echo htmlspecialchars($sale['patient_id']); ?>
						</p>
						<p><strong>Doctor:</strong>
							<?php echo htmlspecialchars($sale['doctor_name'] ?: 'N/A'); ?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<form method="POST">
			<div class="card">
				<div class="card-body">
					<div class="border rounded p-3 mb-3">
						<h6 class="mb-3">Add Medicine</h6>
						<div class="row g-2 align-items-end">
							<div class="col-md-4">
								<label class="form-label">Medicine</label>
								<select id="new-medicine-search" class="form-select" style="width: 100%;"></select>
							</div>
							<div class="col-md-3">
								<label class="form-label">Batch</label>
								<select id="new-batch-select" class="form-select">
									<option value="">Select batch</option>
								</select>
							</div>
							<div class="col-md-1">
								<label class="form-label">Qty</label>
								<input type="number" min="1" id="new-qty" class="form-control" value="1">
							</div>
							<div class="col-md-2">
								<label class="form-label">Price</label>
								<input type="number" min="0" step="0.01" id="new-price" class="form-control" value="0">
							</div>
							<div class="col-md-1">
								<label class="form-label">Disc</label>
								<input type="number" min="0" step="0.01" id="new-discount" class="form-control" value="0">
							</div>
							<div class="col-md-1 d-grid">
								<button type="button" class="btn btn-success" id="add-new-item-btn">Add</button>
							</div>
						</div>
					</div>

					<h5>Edit Medicines</h5>
					<div class="table-responsive">
						<table class="table">
							<thead class="table-dark">
								<tr>
									<th>Medicine</th>
									<th>Current Batch</th>
									<th>New Batch</th>
									<th>Quantity</th>
									<th>Unit Price</th>
									<th>Discount</th>
									<th>Net Amount</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($items as $index => $item) { ?>
									<tr
										data-original-batch-id="<?php echo (int) $item['batch_id']; ?>"
										data-original-quantity="<?php echo (float) $item['quantity']; ?>">
									<td>
										<strong><?php echo htmlspecialchars(html_entity_decode($item['medicine_name'], ENT_QUOTES, 'UTF-8')); ?></strong>
										<br><small
											class="text-muted"><?php echo htmlspecialchars(html_entity_decode($item['manufacturer'], ENT_QUOTES, 'UTF-8')); ?></small>
									</td>
									<td>
										<?php echo htmlspecialchars($item['batch_number']); ?>
										<br><small class="text-muted">Exp:
											<?php echo formatDisplayDate($item['expiry_date']); ?></small>
									</td>
									<td>
										<select
											name="items[<?php echo $item['item_id']; ?>][batch_id]"
											class="form-select batch-select" required>
											<?php foreach ($batch_options[$item['medicine_id']] as $batch) { ?>
											<option
												value="<?php echo $batch['batch_id']; ?>"
												<?php echo $batch['batch_id'] == $item['batch_id'] ? 'selected' : ''; ?>
												data-expiry="<?php echo formatDisplayDate($batch['expiry_date']); ?>"
												data-stock="<?php echo $batch['current_quantity']; ?>">
												<?php echo htmlspecialchars($batch['batch_number']); ?>
												(Stock:
												<?php echo $batch['current_quantity']; ?>)
											</option>
											<?php } ?>
										</select>
										<small class="text-muted expiry-info">
											Exp:
											<?php echo formatDisplayDate($item['expiry_date']); ?>
										</small>
										<small class="text-muted stock-info d-block">
											Available: <span class="available-stock"><?php
                                                $current_batch = array_filter(
                                                    $batch_options[$item['medicine_id']],
                                                    function ($b) use ($item) { return $b['batch_id'] == $item['batch_id']; }
                                                );
								    echo reset($current_batch)['current_quantity'] ?? 0;
								    ?></span>
										</small>
									</td>
									<td>
										<input type="number"
											name="items[<?php echo $item['item_id']; ?>][quantity]"
											class="form-control quantity-input" step="0.01" min="0.01"
											value="<?php echo $item['quantity']; ?>"
											required>
									</td>
									<td>
										<input type="number"
											name="items[<?php echo $item['item_id']; ?>][unit_price]"
											class="form-control price-input" step="0.01" min="0"
											value="<?php echo $item['unit_price']; ?>"
											required>
									</td>
									<td>
										<input type="number"
											name="items[<?php echo $item['item_id']; ?>][discount]"
											class="form-control discount-input" step="0.01" min="0"
											value="<?php echo $item['discount_amount']; ?>">
									</td>
									<td>
										<span
											class="net-amount">₹<?php echo number_format($item['final_amount'], 2); ?></span>
									</td>
									<td>
										<div class="form-check">
											<input class="form-check-input delete-item-checkbox" type="checkbox"
												name="items[<?php echo $item['item_id']; ?>][delete]" value="1"
												id="delete-item-<?php echo (int) $item['item_id']; ?>">
											<label class="form-check-label text-danger"
												for="delete-item-<?php echo (int) $item['item_id']; ?>">Delete</label>
										</div>
									</td>
								</tr>
								<?php } ?>
							</tbody>
							<tfoot>
								<tr>
									<td colspan="7" class="text-end"><strong>Total:</strong></td>
									<td><strong><span
												id="grand-total">₹<?php echo number_format($sale['final_amount'], 2); ?></span></strong>
									</td>
								</tr>
							</tfoot>
						</table>
					</div>
				</div>
			</div>

			<div class="row mt-3">
				<div class="col">
					<button type="submit" class="btn btn-primary">
						<i class="fas fa-save"></i> Update Bill
					</button>
					<a href="view_pharmacy_bill.php?id=<?php echo $transaction_id; ?>"
						class="btn btn-secondary">
						Cancel
					</a>
				</div>
			</div>
		</form>
	</div>

	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<script>
		let newItemCounter = 0;

		function initMedicineSearch() {
			if (!window.jQuery) {
				return;
			}

			$('#new-medicine-search').select2({
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
							results: data.results
						};
					},
					cache: true
				},
				minimumInputLength: 2,
				placeholder: 'Search medicine...',
				allowClear: true,
				width: 'resolve'
			});

			$('#new-medicine-search').on('select2:select', function(e) {
				fetchNewBatches(String(e.params.data.id || ''));
			});
		}

		function fetchNewBatches(itemId) {
			if (!itemId) {
				return;
			}

			$.ajax({
				url: 'edit_pharmacy_bill.php',
				method: 'GET',
				dataType: 'json',
				data: {
					id: '<?php echo htmlspecialchars($transaction_id); ?>',
					item_id: itemId
				}
			}).done(function(response) {
				const batches = Array.isArray(response) ? response : [];
				const select = $('#new-batch-select');
				select.empty().append('<option value="">Select batch</option>');

				batches.forEach(batch => {
					const retailPrice = Number(batch.retail_price || 0);
					select.append(`<option value="${batch.batch_id}" data-batch-number="${batch.batch_number}" data-price="${retailPrice}" data-expiry="${batch.expiry_date}" data-stock="${batch.current_quantity}">Batch: ${batch.batch_number} | Stock: ${batch.current_quantity} | Exp: ${batch.expiry_date} | ${retailPrice.toFixed(2)}</option>`);
				});

				if (batches.length > 0) {
					const firstPrice = Number(batches[0].retail_price || 0);
					select.val(String(batches[0].batch_id));
					document.getElementById('new-price').value = firstPrice.toFixed(2);
					select.trigger('change');
				} else {
					document.getElementById('new-price').value = '0.00';
				}
			});
		}

		function addNewItemRow() {
			const selectedMedicine = $('#new-medicine-search').select2('data')[0];
			const batchSelect = document.getElementById('new-batch-select');
			const selectedBatch = batchSelect ? batchSelect.options[batchSelect.selectedIndex] : null;
			const qty = parseFloat(document.getElementById('new-qty').value || '0');
			const price = parseFloat(document.getElementById('new-price').value || '0');
			const discount = parseFloat(document.getElementById('new-discount').value || '0');

			if (!selectedMedicine || !selectedBatch || !selectedBatch.value || qty <= 0) {
				alert('Please select medicine, batch and valid quantity');
				return;
			}

			const rowKey = `new_${newItemCounter++}`;
			const batchNumber = selectedBatch.dataset.batchNumber || '';
			const expiry = selectedBatch.dataset.expiry || '';
			const stock = parseFloat(selectedBatch.dataset.stock || '0');

			if (qty > stock) {
				alert(`Insufficient stock. Available: ${stock}`);
				return;
			}

			const tbody = document.querySelector('table tbody');
			const row = document.createElement('tr');
			row.className = 'new-item-row';
			row.innerHTML = `
				<td>
					<strong>${selectedMedicine.text}</strong>
					<input type="hidden" name="new_items[${rowKey}][medicine_name]" value="${selectedMedicine.text}">
				</td>
				<td>New Line</td>
				<td>
					${batchNumber}<br><small class="text-muted">Exp: ${expiry}</small><br><small class="text-muted">Available: ${stock}</small>
					<input type="hidden" name="new_items[${rowKey}][batch_id]" value="${selectedBatch.value}">
				</td>
				<td><input type="number" name="new_items[${rowKey}][quantity]" class="form-control quantity-input" step="0.01" min="0.01" value="${qty}" required></td>
				<td><input type="number" name="new_items[${rowKey}][unit_price]" class="form-control price-input" step="0.01" min="0" value="${price}" required></td>
				<td><input type="number" name="new_items[${rowKey}][discount]" class="form-control discount-input" step="0.01" min="0" value="${discount}"></td>
				<td><span class="net-amount"></span></td>
				<td><button type="button" class="btn btn-sm btn-outline-danger remove-new-row">Remove</button></td>
			`;

			tbody.appendChild(row);
			calculateNetAmount(row);
			attachRowListeners(row);
			calculateGrandTotal();
		}

		function attachRowListeners(row) {
			row.querySelectorAll('.quantity-input, .price-input, .discount-input').forEach(input => {
				input.addEventListener('input', function() {
					calculateNetAmount(row);
				});
			});

			const removeBtn = row.querySelector('.remove-new-row');
			if (removeBtn) {
				removeBtn.addEventListener('click', function() {
					row.remove();
					calculateGrandTotal();
				});
			}
		}

		// Update expiry date and stock info when batch changes
		document.querySelectorAll('.batch-select').forEach(select => {
			select.addEventListener('change', function() {
				const row = this.closest('tr');
				if (row && row.querySelector('.delete-item-checkbox')?.checked) {
					return;
				}

				const selectedOption = this.options[this.selectedIndex];
				const expiryInfo = this.parentNode.querySelector('.expiry-info');
				const stockInfo = this.parentNode.querySelector('.available-stock');

				expiryInfo.textContent = 'Exp: ' + selectedOption.dataset.expiry;
				stockInfo.textContent = selectedOption.dataset.stock;
			});
		});

		// Validate quantity against available stock
		document.querySelectorAll('.quantity-input').forEach(input => {
			input.addEventListener('input', function() {
				const row = this.closest('tr');
				if (row && row.querySelector('.delete-item-checkbox')?.checked) {
					this.classList.remove('is-invalid');
					return;
				}

				const batchSelect = row.querySelector('.batch-select');
				const selectedOption = batchSelect.options[batchSelect.selectedIndex];
				const availableStock = parseFloat(selectedOption.dataset.stock) || 0;
				const originalBatchId = row.dataset.originalBatchId || '';
				const originalQuantity = parseFloat(row.dataset.originalQuantity) || 0;
				const selectedBatchId = selectedOption ? selectedOption.value : '';
				const effectiveAvailableStock = (String(selectedBatchId) === String(originalBatchId))
					? availableStock + originalQuantity
					: availableStock;
				const quantity = parseFloat(this.value) || 0;

				if (quantity > effectiveAvailableStock) {
					this.classList.add('is-invalid');
					if (!this.nextElementSibling || !this.nextElementSibling.classList.contains(
							'invalid-feedback')) {
						const feedback = document.createElement('div');
						feedback.className = 'invalid-feedback';
						feedback.textContent = `Insufficient stock. Available: ${effectiveAvailableStock}`;
						this.parentNode.appendChild(feedback);
					}
				} else {
					this.classList.remove('is-invalid');
					const feedback = this.nextElementSibling;
					if (feedback && feedback.classList.contains('invalid-feedback')) {
						feedback.remove();
					}
				}

				calculateNetAmount(row);
			});
		});

		// Calculate net amount when quantity, price, or discount changes
		function calculateNetAmount(row) {
			if (row.querySelector('.delete-item-checkbox')?.checked) {
				row.querySelector('.net-amount').textContent = '₹0.00';
				calculateGrandTotal();
				return;
			}

			const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
			const price = parseFloat(row.querySelector('.price-input').value) || 0;
			const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
			const netAmount = (quantity * price) - discount;

			row.querySelector('.net-amount').textContent = '₹' + netAmount.toFixed(2);
			calculateGrandTotal();
		}

		function calculateGrandTotal() {
			let total = 0;
			document.querySelectorAll('tbody tr').forEach((row) => {
				if (row.querySelector('.delete-item-checkbox')?.checked) {
					return;
				}
				const span = row.querySelector('.net-amount');
				if (!span) {
					return;
				}
				const amount = parseFloat(span.textContent.replace('₹', '').replace(',', '')) || 0;
				total += amount;
			});
			document.getElementById('grand-total').textContent = '₹' + total.toLocaleString('en-IN', {
				minimumFractionDigits: 2
			});
		}

		// Add event listeners to all input fields
		document.querySelectorAll('.quantity-input, .price-input, .discount-input').forEach(input => {
			input.addEventListener('input', function() {
				calculateNetAmount(this.closest('tr'));
			});
		});

		document.querySelectorAll('.delete-item-checkbox').forEach((checkbox) => {
			checkbox.addEventListener('change', function() {
				const row = this.closest('tr');
				const inputs = row.querySelectorAll('.quantity-input, .price-input, .discount-input, .batch-select');
				inputs.forEach((field) => {
					field.disabled = this.checked;
				});
				row.classList.toggle('table-danger', this.checked);
				calculateNetAmount(row);
			});
		});

		const newBatchSelect = document.getElementById('new-batch-select');
		if (newBatchSelect) {
			newBatchSelect.addEventListener('change', function() {
				const selected = this.options[this.selectedIndex];
				if (selected && selected.dataset.price) {
					document.getElementById('new-price').value = Number(selected.dataset.price).toFixed(2);
				} else {
					document.getElementById('new-price').value = '0.00';
				}
			});
		}

		const addNewBtn = document.getElementById('add-new-item-btn');
		if (addNewBtn) {
			addNewBtn.addEventListener('click', addNewItemRow);
		}

		initMedicineSearch();
		calculateGrandTotal();
	</script>
</body>

</html>