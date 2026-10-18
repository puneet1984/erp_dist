<?php
// Set timezone first
date_default_timezone_set('Asia/Kolkata');

// Move connection code before its usage
$conn = getDbConnection();
if (!$conn) {
    exit(1);
}

$conn->query("SET time_zone = '+05:30'") or die("Failed to set timezone: " . $conn->error);

function loadAlertNumbers() {
    $jsonFile = __DIR__ . '/../assets/json/alert_numbers.json';
    if (file_exists($jsonFile)) {
        $config = json_decode(file_get_contents($jsonFile), true);
        return $config['stock_alerts'] ?? [];
    }
    return [];
}

function getTemplate($conn, $templateName) {
    $stmt = $conn->prepare("SELECT * FROM sms_templates WHERE template_name = ?");
    $stmt->bind_param('s', $templateName);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function logStockAlert($conn, $itemId, $alertType, $message, $mobileNumber) {
    if (!$conn || !$itemId || !$alertType || !$message || !$mobileNumber) {
        return false;
    }

    if ($alertType === 'EXPIRING_SOON') {
        // Delete any existing alerts for this item and mobile number
        $stmt = $conn->prepare(
            "DELETE FROM stock_alert_logs 
             WHERE item_id = ? 
             AND alert_type = ? 
             AND mobile_number = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iss', $itemId, $alertType, $mobileNumber);
        if (!$stmt->execute()) return false;
        
        // Create new alert
        $stmt = $conn->prepare(
            "INSERT INTO stock_alert_logs 
            (item_id, alert_type, message, mobile_number, status, created_at) 
            VALUES (?, ?, ?, ?, 'PENDING', NOW())"
        );
        if (!$stmt) return false;
        $stmt->bind_param('isss', $itemId, $alertType, $message, $mobileNumber);
        return $stmt->execute();
    }
    
    // For non-expiry alerts
    $stmt = $conn->prepare(
        "SELECT id, status, sent_at, next_alert_after 
         FROM stock_alert_logs 
         WHERE item_id = ? 
         AND alert_type = ? 
         AND mobile_number = ?
         FOR UPDATE"
    );
    if (!$stmt) return false;
    $stmt->bind_param('iss', $itemId, $alertType, $mobileNumber);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result) {
        // Update existing alert if it's failed or old enough
        if ($result['status'] === 'FAILED' || 
            ($result['status'] === 'SUCCESS' && $result['next_alert_after'] <= date('Y-m-d H:i:s'))) {
            $stmt = $conn->prepare(
                "UPDATE stock_alert_logs 
                SET status = 'PENDING',
                    message = ?,
                    sent_at = NULL,
                    error_message = NULL
                WHERE id = ?"
            );
            if (!$stmt) return false;
            $stmt->bind_param('si', $message, $result['id']);
            return $stmt->execute();
        }
    } else {
        // Create new alert
        $stmt = $conn->prepare(
            "INSERT INTO stock_alert_logs 
            (item_id, alert_type, message, mobile_number, status, created_at) 
            VALUES (?, ?, ?, ?, 'PENDING', NOW())"
        );
        if (!$stmt) return false;
        $stmt->bind_param('isss', $itemId, $alertType, $message, $mobileNumber);
        return $stmt->execute();
    }
    return false;
}
function getLowStockItems($conn) {
    return $conn->query("
        SELECT i.item_id, i.name, SUM(b.current_quantity) AS total_quantity, i.reorder_level
        FROM pharmacy_items i
        JOIN pharmacy_batches b ON i.item_id = b.item_id
        GROUP BY i.item_id
        HAVING total_quantity <= i.reorder_level
        AND NOT EXISTS (
            SELECT 1 FROM stock_alert_logs sal 
            WHERE sal.item_id = i.item_id 
            AND sal.alert_type = 'LOW_STOCK'
            AND sal.sent_at IS NOT NULL
            AND sal.next_alert_after > NOW()
        )"
    )->fetch_all(MYSQLI_ASSOC);
}

function getExpiringItems($conn) {
    $currentDate = date('Y-m-d');
    $threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
    
    return $conn->query("
        SELECT i.item_id, i.name, b.batch_number, b.current_quantity, b.expiry_date
        FROM pharmacy_items i
        JOIN pharmacy_batches b ON i.item_id = b.item_id
        WHERE b.expiry_date BETWEEN '$currentDate' AND '$threeMonthsFromNow'
        AND b.current_quantity > 0
        AND NOT EXISTS (
            SELECT 1 FROM stock_alert_logs sal 
            WHERE sal.item_id = i.item_id 
            AND sal.alert_type = 'EXPIRING_SOON'
            AND sal.sent_at IS NOT NULL
            AND sal.next_alert_after > NOW()
        )"
    )->fetch_all(MYSQLI_ASSOC);
}

try {
    $conn = getDbConnection();
    if (!$conn) {
        throw new Exception("Failed to establish database connection");
    }
    
    $alertNumbers = loadAlertNumbers();
    if (empty($alertNumbers)) {
        exit(0);
    }

    // Start transaction
    $conn->begin_transaction();

    // Process low stock alerts
    $lowStockItems = getLowStockItems($conn);
    foreach ($lowStockItems as $item) {
        $template = getTemplate($conn, 'stock_low_alert');
        if ($template) {
            $message = str_replace(
                ['{item_name}', '{quantity}'],
                [$item['name'], $item['total_quantity']],
                $template['template_text']
            );

            foreach ($alertNumbers as $number) {
                logStockAlert($conn, $item['item_id'], 'LOW_STOCK', $message, $number);
            }
        }
    }

    // Process expiring stock alerts
    $expiringItems = getExpiringItems($conn);
    foreach ($expiringItems as $item) {
        $template = getTemplate($conn, 'stock_expiry_alert');
        if ($template) {
            $message = str_replace(
                ['{item_name}', '{batch_number}', '{quantity}', '{expiry_date}'],
                [
                    $item['name'],
                    $item['batch_number'],
                    $item['current_quantity'],
                    date('d-m-Y', strtotime($item['expiry_date']))
                ],
                $template['template_text']
            );

            foreach ($alertNumbers as $number) {
                logStockAlert($conn, $item['item_id'], 'EXPIRING_SOON', $message, $number);
            }
        }
    }

    // Commit if everything is successful
    $conn->commit();

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    exit(1);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
