<?php
/**
 * Web interface to merge duplicate pharmacy items
 */

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Ensure only admin can access
if (!isAdmin()) {
    die('Access denied. Admin privileges required.');
}

$conn = getDbConnection();
$action = $_GET['action'] ?? 'view';
$dry_run = !isset($_GET['execute']);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Merge Duplicate Items</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .duplicate-set {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #28a745;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #dc3545;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .batch-info {
            margin-left: 20px;
            font-size: 0.9em;
            color: #666;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }
        .btn-primary { background: #007bff; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        .btn-secondary { background: #6c757d; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>
    
    <div class="container" style="max-width: 1200px; margin: 20px auto; padding: 20px;">
        <h2>Merge Duplicate Pharmacy Items</h2>
        
        <?php
        if ($action === 'merge' && !$dry_run) {
            // Perform actual merge
            try {
                $conn->begin_transaction();
                
                // Find all duplicate items
                $sql = "SELECT 
                    name,
                    active_ingredient,
                    manufacturer,
                    GROUP_CONCAT(item_id ORDER BY item_id) as item_ids,
                    COUNT(*) as duplicate_count
                FROM pharmacy_items
                GROUP BY name, active_ingredient, manufacturer
                HAVING COUNT(*) > 1
                ORDER BY name";
                
                $result = $conn->query($sql);
                $merged_count = 0;
                
                echo '<div class="success">';
                echo '<h3>✓ Merge Completed Successfully!</h3>';
                
                while ($row = $result->fetch_assoc()) {
                    $item_ids = explode(',', $row['item_ids']);
                    $keep_id = $item_ids[0];
                    $merge_ids = array_slice($item_ids, 1);
                    
                    echo "<p><strong>{$row['name']}</strong>";
                    echo " - Merged IDs (" . implode(', ', $merge_ids) . ") into ID {$keep_id}</p>";
                    
                    foreach ($merge_ids as $merge_id) {
                        // Check for batch conflicts
                        $check_stmt = $conn->prepare("
                            SELECT b1.batch_number 
                            FROM pharmacy_batches b1
                            WHERE b1.item_id = ? 
                            AND b1.batch_number IN (
                                SELECT batch_number 
                                FROM pharmacy_batches 
                                WHERE item_id = ?
                            )
                        ");
                        $check_stmt->bind_param('ii', $merge_id, $keep_id);
                        $check_stmt->execute();
                        $conflicts = $check_stmt->get_result();
                        
                        if ($conflicts->num_rows > 0) {
                            echo "<div class='error' style='margin-left: 20px;'>⚠ Skipped ID {$merge_id} - Has conflicting batch numbers</div>";
                            continue;
                        }
                        
                        // Move batches
                        $update_stmt = $conn->prepare("UPDATE pharmacy_batches SET item_id = ? WHERE item_id = ?");
                        $update_stmt->bind_param('ii', $keep_id, $merge_id);
                        $update_stmt->execute();
                        $moved = $update_stmt->affected_rows;
                        
                        // Delete duplicate
                        $delete_stmt = $conn->prepare("DELETE FROM pharmacy_items WHERE item_id = ?");
                        $delete_stmt->bind_param('i', $merge_id);
                        $delete_stmt->execute();
                        
                        echo "<div style='margin-left: 20px; font-size: 0.9em;'>ID {$merge_id}: {$moved} batches moved</div>";
                        $merged_count++;
                    }
                }
                
                $conn->commit();
                echo "<hr><p><strong>Total items merged: {$merged_count}</strong></p>";
                echo '</div>';
                
                echo '<p><a href="merge_duplicates.php" class="btn btn-primary">← Back to Inventory</a></p>';
                
            } catch (Exception $e) {
                $conn->rollback();
                echo '<div class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
        } else {
            // Display duplicates (dry run)
            $sql = "SELECT 
                name,
                active_ingredient,
                manufacturer,
                MAX(dosage_form) as dosage_form,
                GROUP_CONCAT(item_id ORDER BY item_id) as item_ids,
                COUNT(*) as duplicate_count
            FROM pharmacy_items
            GROUP BY name, active_ingredient, manufacturer
            HAVING COUNT(*) > 1
            ORDER BY name";
            
            $result = $conn->query($sql);
            
            if ($result->num_rows === 0) {
                echo '<div class="success">✓ No duplicate items found! All items are unique.</div>';
                echo '<p><a href="inventory.php" class="btn btn-secondary">Back to Inventory</a></p>';
            } else {
                echo '<div class="warning"><strong>Found ' . $result->num_rows . ' sets of duplicate items</strong></div>';
                echo '<p>Review the duplicates below. Clicking "Confirm Merge" will combine all duplicates into the lowest ID while preserving all batch information.</p>';
                
                while ($row = $result->fetch_assoc()) {
                    $item_ids = explode(',', $row['item_ids']);
                    $keep_id = $item_ids[0];
                    $merge_ids = array_slice($item_ids, 1);
                    
                    echo '<div class="duplicate-set">';
                    echo '<h4>' . htmlspecialchars($row['name']) . '</h4>';
                    echo '<p>';
                    echo '<strong>Active Ingredient:</strong> ' . htmlspecialchars($row['active_ingredient'] ?: 'N/A') . '<br>';
                    echo '<strong>Manufacturer:</strong> ' . htmlspecialchars($row['manufacturer']) . '<br>';
                    echo '<strong>Dosage Form:</strong> ' . htmlspecialchars($row['dosage_form'] ?: 'N/A');
                    echo '</p>';
                    
                    echo '<p><strong style="color: #28a745;">✓ Will keep:</strong> Item ID ' . $keep_id . '</p>';
                    echo '<p><strong style="color: #dc3545;">✗ Will merge:</strong> Item IDs ' . implode(', ', $merge_ids) . '</p>';
                    
                    // Show batch details for each item
                    foreach ($item_ids as $item_id) {
                        $batch_sql = "SELECT batch_number, current_quantity, expiry_date 
                                     FROM pharmacy_batches 
                                     WHERE item_id = ? 
                                     ORDER BY batch_number";
                        $batch_stmt = $conn->prepare($batch_sql);
                        $batch_stmt->bind_param('i', $item_id);
                        $batch_stmt->execute();
                        $batches = $batch_stmt->get_result();
                        
                        echo '<div class="batch-info">';
                        echo '<strong>Item ' . $item_id . ' batches:</strong> ';
                        
                        if ($batches->num_rows > 0) {
                            $batch_list = [];
                            while ($batch = $batches->fetch_assoc()) {
                                $batch_list[] = $batch['batch_number'] . ' (Qty: ' . $batch['current_quantity'] . ', Exp: ' . date('M Y', strtotime($batch['expiry_date'])) . ')';
                            }
                            echo implode(', ', $batch_list);
                        } else {
                            echo 'No batches';
                        }
                        echo '</div>';
                    }
                    
                    // Check for conflicts
                    $has_conflict = false;
                    foreach ($merge_ids as $merge_id) {
                        $check_stmt = $conn->prepare("
                            SELECT b1.batch_number 
                            FROM pharmacy_batches b1
                            WHERE b1.item_id = ? 
                            AND b1.batch_number IN (
                                SELECT batch_number 
                                FROM pharmacy_batches 
                                WHERE item_id = ?
                            )
                        ");
                        $check_stmt->bind_param('ii', $merge_id, $keep_id);
                        $check_stmt->execute();
                        $conflicts = $check_stmt->get_result();
                        
                        if ($conflicts->num_rows > 0) {
                            $has_conflict = true;
                            echo '<div class="error">⚠ Cannot auto-merge: Items ' . $merge_id . ' and ' . $keep_id . ' have duplicate batch numbers</div>';
                        }
                    }
                    
                    echo '</div>';
                }
                
                echo '<div style="margin-top: 30px; padding: 20px; background: #f0f0f0; border-radius: 5px;">';
                echo '<h3>Ready to Merge?</h3>';
                echo '<p>Clicking the button below will merge all duplicate items listed above. <strong>This action cannot be undone.</strong></p>';
                echo '<p>';
                echo '<a href="merge_duplicates.php?action=merge&execute=1" class="btn btn-danger" onclick="return confirm(\'Are you absolutely sure? This will merge all duplicates and cannot be undone!\')">✓ Confirm Merge</a>';
                echo '<a href="inventory.php" class="btn btn-secondary">✗ Cancel</a>';
                echo '</p>';
                echo '</div>';
            }
        }
        ?>
        
    </div>
</body>
</html>
