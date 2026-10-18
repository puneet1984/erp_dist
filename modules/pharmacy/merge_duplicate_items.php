<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check permission
if (!checkPermission('pharmacy', 'edit')) {
    header('Location: ../../unauthorized.php');
    exit;
}

$conn = getDbConnection();

function mergeBatchRecords($conn, $sourceBatch, $targetBatchId)
{
    // Repoint all transaction rows from source batch to target batch first
    $stmt = $conn->prepare('UPDATE pharmacy_transaction_items SET batch_id = ? WHERE batch_id = ?');
    $stmt->bind_param('ii', $targetBatchId, $sourceBatch['batch_id']);
    $stmt->execute();

    // Merge stock figures and preserve useful metadata from either side
    $stmt = $conn->prepare('SELECT batch_id, initial_quantity, current_quantity, purchase_rate, retail_price, expiry_date, supplier_id, invoice_number
                           FROM pharmacy_batches
                           WHERE batch_id = ?
                           FOR UPDATE');
    $stmt->bind_param('i', $targetBatchId);
    $stmt->execute();
    $targetBatch = $stmt->get_result()->fetch_assoc();

    if (!$targetBatch) {
        throw new Exception('Target batch not found while merging duplicates');
    }

    $mergedInitialQty = (int) $targetBatch['initial_quantity'] + (int) $sourceBatch['initial_quantity'];
    $mergedCurrentQty = (int) $targetBatch['current_quantity'] + (int) $sourceBatch['current_quantity'];
    $mergedPurchaseRate = (float) $targetBatch['purchase_rate'] > 0 ? (float) $targetBatch['purchase_rate'] : (float) $sourceBatch['purchase_rate'];
    $mergedRetailPrice = (float) $targetBatch['retail_price'] > 0 ? (float) $targetBatch['retail_price'] : (float) $sourceBatch['retail_price'];
    $mergedExpiryDate = (strtotime((string) $sourceBatch['expiry_date']) > strtotime((string) $targetBatch['expiry_date']))
        ? $sourceBatch['expiry_date']
        : $targetBatch['expiry_date'];
    $mergedSupplierId = !empty($targetBatch['supplier_id']) ? (int) $targetBatch['supplier_id'] : (int) ($sourceBatch['supplier_id'] ?? 0);
    $mergedInvoiceNumber = !empty($targetBatch['invoice_number']) ? $targetBatch['invoice_number'] : $sourceBatch['invoice_number'];

    $stmt = $conn->prepare('UPDATE pharmacy_batches
                           SET initial_quantity = ?,
                               current_quantity = ?,
                               purchase_rate = ?,
                               retail_price = ?,
                               expiry_date = ?,
                               supplier_id = NULLIF(?, 0),
                               invoice_number = ?
                           WHERE batch_id = ?');
    $stmt->bind_param(
        'iiddsisi',
        $mergedInitialQty,
        $mergedCurrentQty,
        $mergedPurchaseRate,
        $mergedRetailPrice,
        $mergedExpiryDate,
        $mergedSupplierId,
        $mergedInvoiceNumber,
        $targetBatchId
    );
    $stmt->execute();

    // Delete source batch after all references are moved
    $stmt = $conn->prepare('DELETE FROM pharmacy_batches WHERE batch_id = ?');
    $stmt->bind_param('i', $sourceBatch['batch_id']);
    $stmt->execute();
}

// Function to log merge transactions
function logMergeTransaction($master_item_id, $duplicate_ids, $batch_transfers, $transaction_updates, $status = 'success', $error = null, $item_name = null)
{
    $log_file = '../../logs/merge_transactions.json';

    // Load existing logs
    $logs = [];
    if (file_exists($log_file)) {
        $content = file_get_contents($log_file);
        $logs = json_decode($content, true) ?: [];
    }

    // Create new log entry
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $_SESSION['user_id'] ?? 'unknown',
        'master_item_id' => $master_item_id,
        'master_item_name' => $item_name,
        'duplicate_ids' => $duplicate_ids,
        'batch_transfers' => $batch_transfers,
        'transaction_updates' => $transaction_updates,
        'status' => $status,
        'error' => $error,
        'log_id' => uniqid('merge_', true),
    ];

    $logs[] = $log_entry;

    // Save updated logs
    $write_result = @file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (!$write_result) {
        error_log('Failed to write pharmacy merge transaction log to: '.$log_file);
    }

    return $log_entry['log_id'];
}

// Handle merge request
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['merge_items'])) {
    $master_item_id = (int) $_POST['master_item_id'];
    $duplicate_ids = array_map('intval', $_POST['duplicate_ids']);

    if ($master_item_id && !empty($duplicate_ids)) {
        try {
            $conn->begin_transaction();

            // Collect batch transfer details for logging
            $batch_transfers = [];
            foreach ($duplicate_ids as $dup_id) {
                $batch_query = 'SELECT batch_id, batch_number, item_id FROM pharmacy_batches WHERE item_id = ?';
                $batch_stmt = $conn->prepare($batch_query);
                $batch_stmt->bind_param('i', $dup_id);
                $batch_stmt->execute();
                $batch_result = $batch_stmt->get_result();

                while ($batch = $batch_result->fetch_assoc()) {
                    $batch_transfers[] = [
                        'batch_id' => $batch['batch_id'],
                        'batch_number' => $batch['batch_number'],
                        'from_item_id' => $batch['item_id'],
                        'to_item_id' => $master_item_id,
                    ];
                }
            }

            // Collect transaction item updates for logging
            $transaction_updates = [];
            $placeholders = str_repeat('?,', count($duplicate_ids) - 1).'?';
            $query = "SELECT pti.transaction_id, pti.batch_id, pb.batch_number, pb.item_id 
                     FROM pharmacy_transaction_items pti 
                     JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id 
                     WHERE pb.item_id IN ({$placeholders})";
            $stmt = $conn->prepare($query);
            $stmt->bind_param(str_repeat('i', count($duplicate_ids)), ...$duplicate_ids);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $transaction_updates[] = [
                    'transaction_id' => $row['transaction_id'],
                    'batch_id' => $row['batch_id'],
                    'batch_number' => $row['batch_number'],
                    'from_item_id' => $row['item_id'],
                    'to_item_id' => $master_item_id,
                ];
            }

            // Move/merge each duplicate batch into master item with collision handling
            foreach ($duplicate_ids as $dup_id) {
                $dupBatchStmt = $conn->prepare('SELECT batch_id, batch_number, item_id, initial_quantity, current_quantity, purchase_rate, retail_price, expiry_date, supplier_id, invoice_number
                                               FROM pharmacy_batches
                                               WHERE item_id = ?
                                               ORDER BY batch_id');
                $dupBatchStmt->bind_param('i', $dup_id);
                $dupBatchStmt->execute();
                $dupBatches = $dupBatchStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                foreach ($dupBatches as $sourceBatch) {
                    $targetStmt = $conn->prepare('SELECT batch_id
                                                 FROM pharmacy_batches
                                                 WHERE item_id = ? AND batch_number = ?
                                                 LIMIT 1');
                    $targetStmt->bind_param('is', $master_item_id, $sourceBatch['batch_number']);
                    $targetStmt->execute();
                    $targetBatch = $targetStmt->get_result()->fetch_assoc();

                    if ($targetBatch) {
                        mergeBatchRecords($conn, $sourceBatch, (int) $targetBatch['batch_id']);

                        $batch_transfers[] = [
                            'batch_id' => $sourceBatch['batch_id'],
                            'batch_number' => $sourceBatch['batch_number'],
                            'from_item_id' => $sourceBatch['item_id'],
                            'to_item_id' => $master_item_id,
                            'action' => 'merged_with_existing_batch',
                            'target_batch_id' => (int) $targetBatch['batch_id'],
                        ];

                        $transaction_updates[] = [
                            'from_batch_id' => $sourceBatch['batch_id'],
                            'to_batch_id' => (int) $targetBatch['batch_id'],
                            'batch_number' => $sourceBatch['batch_number'],
                            'from_item_id' => $sourceBatch['item_id'],
                            'to_item_id' => $master_item_id,
                            'action' => 'transaction_items_repointed',
                        ];
                    } else {
                        $moveStmt = $conn->prepare('UPDATE pharmacy_batches SET item_id = ? WHERE batch_id = ?');
                        $moveStmt->bind_param('ii', $master_item_id, $sourceBatch['batch_id']);
                        $moveStmt->execute();

                        $batch_transfers[] = [
                            'batch_id' => $sourceBatch['batch_id'],
                            'batch_number' => $sourceBatch['batch_number'],
                            'from_item_id' => $sourceBatch['item_id'],
                            'to_item_id' => $master_item_id,
                            'action' => 'moved_batch_to_master',
                        ];
                    }
                }
            }

            // Handle foreign key constraint: Update or delete stock_alert_logs
            $stmt = $conn->prepare("UPDATE stock_alert_logs SET item_id = ? WHERE item_id IN ({$placeholders})");
            $stmt->bind_param(str_repeat('i', count($duplicate_ids) + 1), $master_item_id, ...$duplicate_ids);
            $stmt->execute();

            // Delete duplicate items
            $stmt = $conn->prepare("DELETE FROM pharmacy_items WHERE item_id IN ({$placeholders})");
            $stmt->bind_param(str_repeat('i', count($duplicate_ids)), ...$duplicate_ids);
            $stmt->execute();

            $conn->commit();

            // Get master item name for logging
            $name_query = 'SELECT name FROM pharmacy_items WHERE item_id = ?';
            $name_stmt = $conn->prepare($name_query);
            $name_stmt->bind_param('i', $master_item_id);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            $item_name = 'Unknown';
            if ($name_result && $row = $name_result->fetch_assoc()) {
                $item_name = $row['name'];
            }

            // Log successful merge
            $log_id = logMergeTransaction($master_item_id, $duplicate_ids, $batch_transfers, $transaction_updates, 'success', null, $item_name);
            $success_message = 'Successfully merged '.count($duplicate_ids).' duplicate items of <strong>'.htmlspecialchars($item_name).'</strong>. Transaction Log ID: <code>'.htmlspecialchars($log_id).'</code>';

        } catch (Exception $e) {
            $conn->rollback();
            logMergeTransaction($master_item_id, $duplicate_ids, [], [], 'failed', $e->getMessage());
            $error_message = 'Error merging items: '.$e->getMessage();
        }
    }
}

// Function to calculate string similarity
function calculateSimilarity($str1, $str2, &$percent)
{
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));

    // Remove common variations
    $str1 = preg_replace('/[^a-z0-9]/', '', $str1);
    $str2 = preg_replace('/[^a-z0-9]/', '', $str2);

    return similar_text($str1, $str2, $percent);
}

// Get all pharmacy items
$query = 'SELECT item_id, name, manufacturer FROM pharmacy_items ORDER BY name';
$result = $conn->query($query);
$all_items = $result->fetch_all(MYSQLI_ASSOC);

// Find potential duplicates
$potential_duplicates = [];
$processed = [];

foreach ($all_items as $i => $item1) {
    if (in_array($item1['item_id'], $processed)) {
        continue;
    }

    $group = [$item1];
    $processed[] = $item1['item_id'];

    foreach ($all_items as $j => $item2) {
        if ($i >= $j || in_array($item2['item_id'], $processed)) {
            continue;
        }

        $percent_name = 0;
        $percent_manufacturer = 0;
        calculateSimilarity($item1['name'], $item2['name'], $percent_name);
        calculateSimilarity($item1['manufacturer'], $item2['manufacturer'], $percent_manufacturer);

        // Both name and manufacturer must be 85%+ similar
        if ($percent_name >= 85 && $percent_manufacturer >= 85) {
            $group[] = $item2;
            $processed[] = $item2['item_id'];
        }
    }

    if (count($group) > 1) {
        $potential_duplicates[] = $group;
    }
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Merge Duplicate Pharmacy Items</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		.item-group {
			border: 1px solid #dee2e6;
			border-radius: 0.375rem;
			margin-bottom: 1rem;
			padding: 1rem;
		}

		.master-item {
			background-color: #d4edda;
			border-color: #c3e6cb;
		}

		.duplicate-item {
			background-color: #f8d7da;
			border-color: #f5c6cb;
		}

		.similarity-score {
			font-size: 0.875rem;
			color: #6c757d;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container-fluid">
		<div class="row mb-3">
			<div class="col">
				<h2><i class="fas fa-compress-alt"></i> Merge Duplicate Pharmacy Items</h2>
				<p class="text-muted">Identify and merge pharmacy items with similar names to maintain data consistency.
				</p>
			</div>
			<div class="col-auto">
				<a href="merge_history.php" class="btn btn-secondary">
					<i class="fas fa-history"></i> View History
				</a>
			</div>
		</div>

		<?php if (isset($success_message)) { ?>
		<div class="alert alert-success alert-dismissible fade show">
			<i class="fas fa-check-circle"></i>
			<?php echo $success_message; ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
		</div>
		<?php } ?>

		<?php if (isset($error_message)) { ?>
		<div class="alert alert-danger alert-dismissible fade show">
			<i class="fas fa-exclamation-triangle"></i>
			<?php echo htmlspecialchars($error_message); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
		</div>
		<?php } ?>

		<div class="card mb-3">
			<div class="card-body">
				<h5>Detection Summary</h5>
				<div class="row">
					<div class="col-md-3">
						<div class="text-center">
							<h3 class="text-primary">
								<?php echo count($all_items); ?></h3>
							<small class="text-muted">Total Items</small>
						</div>
					</div>
					<div class="col-md-3">
						<div class="text-center">
							<h3 class="text-warning">
								<?php echo count($potential_duplicates); ?>
							</h3>
							<small class="text-muted">Duplicate Groups</small>
						</div>
					</div>
					<div class="col-md-3">
						<div class="text-center">
							<h3 class="text-danger">
								<?php
                                $total_duplicates = 0;
foreach ($potential_duplicates as $group) {
    $total_duplicates += count($group) - 1; // Exclude master item
}
echo $total_duplicates;
?>
							</h3>
							<small class="text-muted">Items to Merge</small>
						</div>
					</div>
					<div class="col-md-3">
						<div class="text-center">
							<h3 class="text-success">
								<?php echo count($all_items) - $total_duplicates; ?>
							</h3>
							<small class="text-muted">Final Count</small>
						</div>
					</div>
				</div>
			</div>
		</div>

		<?php if (empty($potential_duplicates)) { ?>
		<div class="card">
			<div class="card-body text-center">
				<i class="fas fa-check-circle fa-3x text-success mb-3"></i>
				<h5>No Duplicate Items Found</h5>
				<p class="text-muted">All pharmacy items appear to have unique names.</p>
			</div>
		</div>
		<?php } else { ?>

		<div class="card">
			<div class="card-header">
				<h5><i class="fas fa-list"></i> Potential Duplicate Groups</h5>
				<small class="text-muted">Review each group and select which item should be the master (kept) and which
					should be merged.</small>
			</div>
			<div class="card-body">
				<?php foreach ($potential_duplicates as $index => $group) { ?>
				<form method="POST" class="item-group">
					<h6>Group <?php echo $index + 1; ?></h6>

					<?php foreach ($group as $item_index => $item) { ?>
					<div
						class="form-check mb-2 p-3 rounded <?php echo 0 === $item_index ? 'master-item' : 'duplicate-item'; ?>">
						<input class="form-check-input" type="radio" name="master_item_id"
							value="<?php echo $item['item_id']; ?>"
							id="master_<?php echo $index; ?>_<?php echo $item_index; ?>"
							<?php echo 0 === $item_index ? 'checked' : ''; ?>>
						<label class="form-check-label w-100"
							for="master_<?php echo $index; ?>_<?php echo $item_index; ?>">
							<div class="row">
								<div class="col-md-6">
									<strong><?php echo htmlspecialchars(html_entity_decode($item['name'], ENT_QUOTES | ENT_HTML401, 'UTF-8')); ?></strong>
									<?php if (0 === $item_index) { ?>
									<span class="badge bg-success ms-2">Master</span>
									<?php } ?>
								</div>
								<div class="col-md-4">
									<small class="text-muted">
										<?php echo htmlspecialchars(html_entity_decode($item['manufacturer'] ?: 'Unknown', ENT_QUOTES | ENT_HTML401, 'UTF-8')); ?>
									</small>
								</div>
								<div class="col-md-2">
									<small class="similarity-score">
										ID:
										<?php echo $item['item_id']; ?>
									</small>
								</div>
							</div>
							<?php if (!empty($item['manufacturer'])) { ?>
							<div class="text-muted small mt-1">
								Manufacturer:
								<?php echo htmlspecialchars(html_entity_decode($item['manufacturer'], ENT_QUOTES | ENT_HTML401, 'UTF-8')); ?>
							</div>
							<?php } ?>

							<?php
                            // Fetch batch details for this item
                            $batch_query = 'SELECT batch_number, current_quantity FROM pharmacy_batches WHERE item_id = ? ORDER BY batch_number';
					    $batch_stmt = $conn->prepare($batch_query);
					    $batch_stmt->bind_param('i', $item['item_id']);
					    $batch_stmt->execute();
					    $batch_result = $batch_stmt->get_result();
					    $batches = $batch_result->fetch_all(MYSQLI_ASSOC);
					    ?>

							<?php if (!empty($batches)) { ?>
							<div class="text-muted small mt-2">
								<strong>Batches:</strong>
								<?php foreach ($batches as $batch) { ?>
								<div style="margin-left: 1rem;">
									•
									<?php echo htmlspecialchars($batch['batch_number']); ?>
									(Qty:
									<?php echo $batch['current_quantity']; ?>)
								</div>
								<?php } ?>
							</div>
							<?php } else { ?>
							<div class="text-muted small mt-2">
								<strong>Batches:</strong> No batches found
							</div>
							<?php } ?>
						</label>

						<?php if ($item_index > 0) { ?>
						<input type="hidden" name="duplicate_ids[]"
							value="<?php echo $item['item_id']; ?>">
						<?php } ?>
					</div>
					<?php } ?>

					<div class="text-end mt-3">
						<button type="submit" name="merge_items" class="btn btn-warning"
							onclick="return confirm('Are you sure you want to merge these items? This action cannot be undone.')">
							<i class="fas fa-compress-alt"></i> Merge This Group
						</button>
					</div>
				</form>
				<?php } ?>
			</div>
		</div>
		<?php } ?>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		// Update duplicate IDs when master selection changes
		document.querySelectorAll('input[type="radio"]').forEach(radio => {
			radio.addEventListener('change', function() {
				const form = this.closest('form');
				const allItems = form.querySelectorAll('.form-check');
				const duplicateInputs = form.querySelectorAll('input[name="duplicate_ids[]"]');

				// Remove all existing duplicate inputs
				duplicateInputs.forEach(input => input.remove());

				// Add duplicate inputs for non-selected items
				allItems.forEach((item, index) => {
					const radioInput = item.querySelector('input[type="radio"]');
					if (!radioInput.checked) {
						const hiddenInput = document.createElement('input');
						hiddenInput.type = 'hidden';
						hiddenInput.name = 'duplicate_ids[]';
						hiddenInput.value = radioInput.value;
						form.appendChild(hiddenInput);
					}
				});
			});
		});
	</script>
</body>

</html>