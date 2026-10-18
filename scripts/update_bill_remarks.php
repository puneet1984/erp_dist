<?php
define('SECURE_ACCESS', true);
require_once '../includes/core.php';

try {
    $conn = getDbConnection();
    $conn->begin_transaction();

    // Get bills with empty remarks, including service category
    $stmt = $conn->prepare('SELECT b.bill_id, bi.service_id, s.name as service_name, s.category 
                           FROM bills b 
                           JOIN bill_items bi ON b.bill_id = bi.bill_id 
                           JOIN services s ON bi.service_id = s.service_id 
                           WHERE b.remarks IS NULL OR b.remarks = ""');
    $stmt->execute();
    $result = $stmt->get_result();

    $bills = [];
    while ($row = $result->fetch_assoc()) {
        if (!isset($bills[$row['bill_id']])) {
            $bills[$row['bill_id']] = [
                'services' => [],
                'has_bnp' => false
            ];
        }
        $bills[$row['bill_id']]['services'][] = $row['service_name'];
        if ($row['category'] === 'BNP') {
            $bills[$row['bill_id']]['has_bnp'] = true;
        }
    }

    // Update each bill with service names as remarks
    $update_stmt = $conn->prepare('UPDATE bills SET remarks = ? WHERE bill_id = ?');
    
    $updated = 0;
    foreach ($bills as $bill_id => $bill_data) {
        $remarks = $bill_data['has_bnp'] ? 'BNP' : implode(', ', $bill_data['services']);
        $remarks = substr(trim(strip_tags($remarks)), 0, 200);
        $update_stmt->bind_param('ss', $remarks, $bill_id);
        $update_stmt->execute();
        $updated++;
    }

    $conn->commit();
    echo "Successfully updated remarks for $updated bills\n";

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo "Error updating remarks: " . $e->getMessage() . "\n";
}
