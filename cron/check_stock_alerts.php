<?php
/**
 * Stock Alert Detector
 * 
 * Checks for low stock and expiring items, then creates/updates alert records
 * in the stock_alert_logs table for the Python script to process.
 * 
 * Does NOT send messages - that's handled by stock_alert_service.py
 * 
 * Can be called from a page load - runs only once per day
 */

require_once __DIR__.'/../includes/helpers/stock_helper.php';

// Check if script has already run today using JSON file
function shouldRunStockAlertCheck() {
    $runsFile = __DIR__ . '/../logs/stock_alert_runs.json';
    
    if (file_exists($runsFile)) {
        $runs = json_decode(@file_get_contents($runsFile), true);
        if (!is_array($runs)) {
            $runs = [];
        }
    } else {
        $runs = [];
    }
    
    $today = date('Y-m-d');
    return !in_array($today, $runs);
}

// Mark script as run for today and rotate old entries
function markStockAlertCheckRun() {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    
    $runsFile = $logDir . '/stock_alert_runs.json';
    
    // Read existing runs
    if (file_exists($runsFile)) {
        $runs = json_decode(@file_get_contents($runsFile), true);
        if (!is_array($runs)) {
            $runs = [];
        }
    } else {
        $runs = [];
    }
    
    // Add today's date
    $today = date('Y-m-d');
    if (!in_array($today, $runs)) {
        $runs[] = $today;
    }
    
    // Keep only last 30 days of records
    $cutoffDate = date('Y-m-d', strtotime('-30 days'));
    $runs = array_filter($runs, function($date) use ($cutoffDate) {
        return $date >= $cutoffDate;
    });
    
    // Write back to file
    file_put_contents($runsFile, json_encode(array_values($runs), JSON_PRETTY_PRINT));
}

// Log to JSON file with rotation
function logStockAlert($message) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . '/stock_alerts.json';
    $timestamp = date('Y-m-d H:i:s');
    
    // Read existing logs
    if (file_exists($logFile)) {
        $logs = json_decode(@file_get_contents($logFile), true);
        if (!is_array($logs)) {
            $logs = [];
        }
    } else {
        $logs = [];
    }
    
    // Add new entry
    $logs[] = [
        'timestamp' => $timestamp,
        'message' => $message
    ];
    
    // Keep only last 100 entries
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    
    // Write back to file
    @file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}

// Exit early if already run today
if (!shouldRunStockAlertCheck()) {
    return; // Silent exit - already ran today
}

logStockAlert('Starting stock alert check');

try {
    $conn = getDbConnection();
    if (!$conn) {
        logStockAlert('ERROR: Database connection failed');
        return;
    }
    
    $conn->query("SET time_zone = '+05:30'") or die("Failed to set timezone: " . $conn->error);
    $conn->begin_transaction();

    // Get current date and time references
    $currentDate = date('Y-m-d');
    $threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
    $twoWeeksAgo = date('Y-m-d H:i:s', strtotime('-2 weeks'));

    // Load alert notification numbers from config
    $alertNumbers = [];
    $jsonFile = __DIR__ . '/../assets/json/alert_numbers.json';
    if (file_exists($jsonFile)) {
        $config = json_decode(file_get_contents($jsonFile), true);
        $alertNumbers = $config['stock_alerts'] ?? [];
    }

    if (empty($alertNumbers)) {
        logStockAlert('WARNING: No alert numbers configured');
        // Creating empty lock like we still ran
        markStockAlertCheckRun();
        return;
    }

    // Process low stock alerts
    $lowStockItems = getLowStockItems($twoWeeksAgo);
    $lowStockCount = 0;
    
    foreach ($lowStockItems as $item) {
        foreach ($alertNumbers as $number) {
            $message = "Low stock alert: {$item['name']} - Current quantity: {$item['current_quantity']}";
            $alertType = 'LOW_STOCK';
            
            // Check for existing alert
            $stmt = $conn->prepare(
                "SELECT id, status, sent_at, next_alert_after 
                 FROM stock_alert_logs 
                 WHERE item_id = ? 
                 AND alert_type = ? 
                 AND mobile_number = ?
                 FOR UPDATE"
            );
            $stmt->bind_param('iss', $item['item_id'], $alertType, $number);
            $stmt->execute();
            $existingAlert = $stmt->get_result()->fetch_assoc();
            
            if ($existingAlert) {
                // Update existing alert if it's failed or old enough
                if ($existingAlert['status'] === 'FAILED' || 
                    ($existingAlert['status'] === 'SENT' && $existingAlert['next_alert_after'] <= date('Y-m-d H:i:s'))) {
                    $updateStmt = $conn->prepare(
                        "UPDATE stock_alert_logs 
                         SET message = ?, status = 'PENDING', updated_at = NOW() 
                         WHERE id = ?"
                    );
                    $updateStmt->bind_param('si', $message, $existingAlert['id']);
                    if ($updateStmt->execute()) {
                        $lowStockCount++;
                    }
                }
            } else {
                // Create new alert
                $insertStmt = $conn->prepare(
                    "INSERT INTO stock_alert_logs 
                    (item_id, alert_type, mobile_number, message, status, created_at) 
                    VALUES (?, ?, ?, ?, 'PENDING', NOW())"
                );
                $insertStmt->bind_param('isss', $item['item_id'], $alertType, $number, $message);
                if ($insertStmt->execute()) {
                    $lowStockCount++;
                }
            }
        }
    }
    
    if ($lowStockCount > 0) {
        logStockAlert("Created/Updated {$lowStockCount} low stock alerts");
    }

    // Process expiring stock alerts
    $expiringItems = getExpiringItems($currentDate, $threeMonthsFromNow, $twoWeeksAgo);
    $expiryCount = 0;
    
    foreach ($expiringItems as $item) {
        foreach ($alertNumbers as $number) {
            $alertType = 'EXPIRING_SOON';
            $expiryDate = date('d-m-Y', strtotime($item['expiry_date']));
            $message = "Expiry alert: {$item['name']} (Batch: {$item['batch_number']}) - Expires: {$expiryDate}";
            
            // For expiring items: delete old alerts and create new
            $deleteStmt = $conn->prepare(
                "DELETE FROM stock_alert_logs 
                 WHERE item_id = ? 
                 AND alert_type = ? 
                 AND mobile_number = ?"
            );
            $deleteStmt->bind_param('iss', $item['item_id'], $alertType, $number);
            if ($deleteStmt->execute()) {
                // Create new alert
                $insertStmt = $conn->prepare(
                    "INSERT INTO stock_alert_logs 
                    (item_id, alert_type, mobile_number, message, status, created_at) 
                    VALUES (?, ?, ?, ?, 'PENDING', NOW())"
                );
                $insertStmt->bind_param('isss', $item['item_id'], $alertType, $number, $message);
                if ($insertStmt->execute()) {
                    $expiryCount++;
                }
            }
        }
    }
    
    if ($expiryCount > 0) {
        logStockAlert("Created {$expiryCount} expiry alerts");
    }

    $conn->commit();
    
    // Mark this run for today
    markStockAlertCheckRun();
    
    logStockAlert("Completed successfully");
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    logStockAlert('ERROR: '.$e->getMessage());
}
// Note: Connection is NOT closed here - it's managed by the caller
