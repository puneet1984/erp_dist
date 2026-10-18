<?php

function getAllDashboardStats($conn)
{
    // Set timezone to IST
    $conn->query("SET time_zone = '+05:30'");

    $stats = [];

    // Get all stats in a single function to avoid multiple DB connections
    $queries = [
        'today_patients' => "SELECT COUNT(DISTINCT patient_id) as count 
                            FROM bills 
                            WHERE bill_date = CURDATE() 
                            AND status = 'ACTIVE'",

        'today_revenue' => "SELECT COALESCE(SUM(final_amount), 0) as total 
                           FROM bills 
                           WHERE bill_date = CURDATE() 
                           AND status = 'ACTIVE'
                           AND payment_status = 'PAID'",

        'pending_bills' => "SELECT COUNT(*) as count 
                           FROM bills 
                           WHERE payment_status = 'PENDING' 
                           AND status = 'ACTIVE'",

        'revenue_trend' => "SELECT 
                           bill_date as date,
                           UNIX_TIMESTAMP(bill_date) as timestamp,
                           SUM(final_amount) as total_revenue 
                           FROM bills 
                           WHERE bill_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                           AND status = 'ACTIVE'
                           AND payment_status = 'PAID'
                           GROUP BY bill_date
                           ORDER BY bill_date",
        'low_stock' => 'SELECT 
            i.item_id,
            i.name,
            i.reorder_level,
            COALESCE(SUM(b.current_quantity), 0) as total_stock,
            MIN(b.expiry_date) as earliest_expiry
            FROM pharmacy_items i
            LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
                AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
            GROUP BY i.item_id, i.name, i.reorder_level
            HAVING total_stock > 0 AND total_stock <= i.reorder_level
            ORDER BY total_stock ASC
            LIMIT 15',
    ];

    foreach ($queries as $key => $query) {
        $result = $conn->query($query);
        if ('revenue_trend' === $key || 'low_stock' === $key) {
            $stats[$key] = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $row = $result->fetch_assoc();
            $stats[$key] = 'today_revenue' === $key ? $row['total'] : $row['count'];
        }
    }

    return $stats;
}
