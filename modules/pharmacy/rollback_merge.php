<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check permission - only admins can rollback
if (!isAdmin()) {
    header('Location: ../../unauthorized.php');
    exit;
}

$conn = getDbConnection();
$log_id = isset($_GET['log_id']) ? $_GET['log_id'] : null;

// Get transaction log
$log_file = '../../logs/merge_transactions.json';
$logs = [];
$target_log = null;

if (file_exists($log_file)) {
    $logs = json_decode(file_get_contents($log_file), true) ?: [];
    
    foreach ($logs as $log) {
        if ($log['log_id'] === $log_id) {
            $target_log = $log;
            break;
        }
    }
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $target_log && isset($_POST['confirm_rollback'])) {
    try {
        $conn->begin_transaction();
        
        // Restore duplicate items
        $master_id = $target_log['master_item_id'];
        $dup_ids = $target_log['duplicate_ids'];
        
        // Get the original item details from batch transfers log
        $restored_items = [];
        foreach ($target_log['batch_transfers'] as $transfer) {
            $from_id = $transfer['from_item_id'];
            
            if (!in_array($from_id, $restored_items)) {
                // Get master item details
                $master_query = "SELECT * FROM pharmacy_items WHERE item_id = ?";
                $stmt = $conn->prepare($master_query);
                $stmt->bind_param('i', $master_id);
                $stmt->execute();
                $master_item = $stmt->get_result()->fetch_assoc();
                
                // Recreate the duplicate item with same details as master
                $insert_query = "INSERT INTO pharmacy_items (item_id, name, category, active_ingredient, dosage_form, manufacturer, reorder_level) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param(
                    'isssssi',
                    $from_id,
                    $master_item['name'],
                    $master_item['category'],
                    $master_item['active_ingredient'],
                    $master_item['dosage_form'],
                    $master_item['manufacturer'],
                    $master_item['reorder_level']
                );
                $insert_stmt->execute();
                $restored_items[] = $from_id;
            }
        }
        
        // Restore batch assignments
        foreach ($target_log['batch_transfers'] as $transfer) {
            $update_query = "UPDATE pharmacy_batches SET item_id = ? WHERE batch_id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param('ii', $transfer['from_item_id'], $transfer['batch_id']);
            $stmt->execute();
        }
        
        // Restore stock_alert_logs assignments
        foreach ($target_log['duplicate_ids'] as $dup_id) {
            $master_id = $target_log['master_item_id'];
            $restore_query = "UPDATE stock_alert_logs SET item_id = ? WHERE item_id = ? AND item_id NOT IN (SELECT item_id FROM pharmacy_items WHERE item_id = ?)";
            $stmt = $conn->prepare($restore_query);
            $stmt->bind_param('iii', $dup_id, $master_id, $master_id);
            $stmt->execute();
        }
        
        $conn->commit();
        
        // Mark in log as rolled back
        $target_log['rolled_back'] = true;
        $target_log['rolled_back_at'] = date('Y-m-d H:i:s');
        $target_log['rolled_back_by'] = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'unknown';
        
        // Update log file
        foreach ($logs as &$log) {
            if ($log['log_id'] === $log_id) {
                $log = $target_log;
                break;
            }
        }
        file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        
        $success_message = "Merge rollback completed successfully! Restored " . count($restored_items) . " items.";
        $target_log = null; // Clear to show success page
        
    } catch (Exception $e) {
        $error_message = "Error rolling back merge: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Rollback Merge Transaction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col">
                <h2><i class="fas fa-undo"></i> Rollback Merge Transaction</h2>
                <p class="text-muted">Undo a previous pharmacy item merge operation.</p>
            </div>
        </div>

        <?php if ($success_message) { ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <i class="fas fa-undo fa-3x text-success mb-3"></i>
                <h5>Rollback Complete</h5>
                <p>The merge operation has been successfully rolled back.</p>
                <a href="merge_duplicate_items.php" class="btn btn-primary">← Back to Merge Tool</a>
            </div>
        </div>
        <?php } elseif ($error_message) { ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php } elseif (!$log_id) { ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i> No transaction log ID provided.
        </div>
        <?php } elseif (!$target_log) { ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i> Transaction log not found.
        </div>
        <?php } else { ?>
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Confirm Rollback</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <strong>Warning!</strong> This will undo the merge operation and restore all duplicate items. 
                    This action cannot be undone.
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6>Transaction Details</h6>
                        <dl class="row">
                            <dt class="col-sm-4">Log ID:</dt>
                            <dd class="col-sm-8"><code><?php echo htmlspecialchars($target_log['log_id']); ?></code></dd>
                            
                            <dt class="col-sm-4">Timestamp:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($target_log['timestamp']); ?></dd>
                            
                            <dt class="col-sm-4">Status:</dt>
                            <dd class="col-sm-8">
                                <?php if (isset($target_log['rolled_back']) && $target_log['rolled_back']) { ?>
                                    <span class="badge bg-secondary">Already Rolled Back</span>
                                <?php } else { ?>
                                    <span class="badge bg-success">Success</span>
                                <?php } ?>
                            </dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <h6>Merge Details</h6>
                        <p><strong>Master Item ID:</strong> <?php echo htmlspecialchars($target_log['master_item_id']); ?></p>
                        <p><strong>Duplicate IDs Merged:</strong> <?php echo implode(', ', $target_log['duplicate_ids']); ?></p>
                        <p><strong>Batches Transferred:</strong> <?php echo count($target_log['batch_transfers']); ?></p>
                        <p><strong>Transaction Items Updated:</strong> <?php echo count($target_log['transaction_updates']); ?></p>
                    </div>
                </div>
                
                <?php if (isset($target_log['rolled_back']) && $target_log['rolled_back']) { ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> This transaction has already been rolled back on <?php echo htmlspecialchars($target_log['rolled_back_at']); ?>.
                </div>
                <a href="merge_duplicate_items.php" class="btn btn-secondary">← Back to Merge Tool</a>
                <?php } else { ?>
                <h6 class="mt-4">Batches to be Restored:</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Batch Number</th>
                                <th>To Item ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($target_log['batch_transfers'] as $transfer) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transfer['batch_id']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['batch_number']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['to_item_id']); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                
                <form method="POST" class="mt-4">
                    <button type="submit" name="confirm_rollback" class="btn btn-danger" 
                            onclick="return confirm('Are you absolutely sure? This will restore all merged items.')">
                        <i class="fas fa-undo"></i> Confirm Rollback
                    </button>
                    <a href="merge_duplicate_items.php" class="btn btn-secondary">Cancel</a>
                </form>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
