<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check permission
if (!checkPermission('pharmacy', 'view')) {
    header('Location: ../../unauthorized.php');
    exit;
}

$log_file = '../../logs/merge_transactions.json';
$logs = [];

if (file_exists($log_file)) {
    $logs = json_decode(file_get_contents($log_file), true) ?: [];
    // Show newest first
    array_multisort(array_column($logs, 'timestamp'), SORT_DESC, $logs);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Merge Transaction History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .transaction-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .transaction-row:hover {
            background-color: #f5f5f5;
        }
        .status-success { color: #28a745; }
        .status-failed { color: #dc3545; }
        .status-rolled-back { color: #6c757d; }
    </style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col">
                <h2><i class="fas fa-history"></i> Merge Transaction History</h2>
                <p class="text-muted">View all pharmacy item merge operations and their details.</p>
            </div>
            <div class="col-auto">
                <a href="merge_duplicate_items.php" class="btn btn-primary">
                    <i class="fas fa-compress-alt"></i> Merge Items
                </a>
            </div>
        </div>

        <?php if (empty($logs)) { ?>
        <div class="card">
            <div class="card-body text-center">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5>No Transactions Found</h5>
                <p class="text-muted">No merge operations have been performed yet.</p>
            </div>
        </div>
        <?php } else { ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Total Transactions: <?php echo count($logs); ?></h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Item Name</th>
                            <th>Master Item ID</th>
                            <th>Items Merged</th>
                            <th>Batches</th>
                            <th>Status</th>
                            <th>User</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) { ?>
                        <tr class="transaction-row" data-bs-toggle="modal" data-bs-target="#details_<?php echo htmlspecialchars($log['log_id']); ?>">
                            <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                            <td><?php echo htmlspecialchars($log['master_item_name'] ?? 'Unknown'); ?></td>
                            <td><strong><?php echo htmlspecialchars($log['master_item_id']); ?></strong></td>
                            <td><?php echo count($log['duplicate_ids']); ?></td>
                            <td><?php echo count($log['batch_transfers']); ?></td>
                            <td>
                                <?php if (isset($log['rolled_back']) && $log['rolled_back']) { ?>
                                    <span class="badge bg-secondary status-rolled-back">Rolled Back</span>
                                <?php } elseif ($log['status'] === 'success') { ?>
                                    <span class="badge bg-success status-success">Success</span>
                                <?php } else { ?>
                                    <span class="badge bg-danger status-failed">Failed</span>
                                <?php } ?>
                            </td>
                            <td><small><?php echo htmlspecialchars($log['user_id']); ?></small></td>
                            <td>
                                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#details_<?php echo htmlspecialchars($log['log_id']); ?>" onclick="event.stopPropagation();">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($log['status'] === 'success' && !(isset($log['rolled_back']) && $log['rolled_back']) && isAdmin()) { ?>
                                <a href="rollback_merge.php?log_id=<?php echo urlencode($log['log_id']); ?>" class="btn btn-sm btn-warning" onclick="event.stopPropagation();">
                                    <i class="fas fa-undo"></i>
                                </a>
                                <?php } ?>
                            </td>
                        </tr>

                        <!-- Details Modal -->
                        <div class="modal fade" id="details_<?php echo htmlspecialchars($log['log_id']); ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Transaction Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <h6>Summary</h6>
                                        <dl class="row">
                                            <dt class="col-sm-4">Item Name:</dt>
                                            <dd class="col-sm-8"><strong><?php echo htmlspecialchars($log['master_item_name'] ?? 'Unknown'); ?></strong></dd>
                                            <dt class="col-sm-4">Log ID:</dt>
                                            <dd class="col-sm-8"><code><?php echo htmlspecialchars($log['log_id']); ?></code></dd>
                                            <dt class="col-sm-4">Timestamp:</dt>
                                            <dd class="col-sm-8"><?php echo htmlspecialchars($log['timestamp']); ?></dd>
                                            <dt class="col-sm-4">Status:</dt>
                                            <dd class="col-sm-8">
                                                <?php if (isset($log['rolled_back']) && $log['rolled_back']) { ?>
                                                    <span class="badge bg-secondary">Rolled Back</span> on <?php echo htmlspecialchars($log['rolled_back_at']); ?>
                                                <?php } elseif ($log['status'] === 'success') { ?>
                                                    <span class="badge bg-success">Success</span>
                                                <?php } else { ?>
                                                    <span class="badge bg-danger">Failed</span>
                                                <?php } ?>
                                            </dd>
                                            <dt class="col-sm-4">Master Item:</dt>
                                            <dd class="col-sm-8"><?php echo htmlspecialchars($log['master_item_id']); ?></dd>
                                        </dl>

                                        <h6 class="mt-3">Items Merged</h6>
                                        <p><?php echo implode(', ', array_map('htmlspecialchars', $log['duplicate_ids'])); ?></p>

                                        <h6 class="mt-3">Batches Transferred (<?php echo count($log['batch_transfers']); ?>)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Batch ID</th>
                                                        <th>Batch Number</th>
                                                        <th>From Item</th>
                                                        <th>To Item</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($log['batch_transfers'] as $transfer) { ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($transfer['batch_id']); ?></td>
                                                        <td><?php echo htmlspecialchars($transfer['batch_number']); ?></td>
                                                        <td><?php echo htmlspecialchars($transfer['from_item_id']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($transfer['to_item_id']); ?></strong></td>
                                                    </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <h6 class="mt-3">Transaction Items Updated (<?php echo count($log['transaction_updates']); ?>)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Transaction ID</th>
                                                        <th>Batch Number</th>
                                                        <th>From Item</th>
                                                        <th>To Item</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($log['transaction_updates'] as $update) { ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($update['transaction_id']); ?></td>
                                                        <td><?php echo htmlspecialchars($update['batch_number']); ?></td>
                                                        <td><?php echo htmlspecialchars($update['from_item_id']); ?></td>
                                                        <td><strong><?php echo htmlspecialchars($update['to_item_id']); ?></strong></td>
                                                    </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <?php if ($log['status'] === 'failed') { ?>
                                        <div class="alert alert-danger mt-3">
                                            <strong>Error:</strong> <?php echo htmlspecialchars($log['error']); ?>
                                        </div>
                                        <?php } ?>
                                    </div>
                                    <div class="modal-footer">
                                        <?php if ($log['status'] === 'success' && !(isset($log['rolled_back']) && $log['rolled_back']) && isAdmin()) { ?>
                                        <a href="rollback_merge.php?log_id=<?php echo urlencode($log['log_id']); ?>" class="btn btn-warning">
                                            <i class="fas fa-undo"></i> Rollback
                                        </a>
                                        <?php } ?>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
