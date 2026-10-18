<?php

define('SECURE_ACCESS', true);
require_once '../core.php';

header('Content-Type: application/json');



function searchMedicinesfromTables($search_term)
{
    try {
        $conn = getDbConnection();
        $searchParam = "{$search_term}%";

        $stmt = $conn->prepare("SELECT id, name, active_ingredient, dosage_form, manufacturer
            FROM medicines
            WHERE name LIKE ? OR active_ingredient LIKE ?
            ORDER BY name
            LIMIT 10");
        $stmt->bind_param('ss', $searchParam, $searchParam);
        $stmt->execute();

        $result = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $medicine) {
            $result[] = [
                'id' => $medicine['id'],
                'text' => $medicine['name'],
                'active_ingredient' => $medicine['active_ingredient'],
                'dosage_form' => $medicine['dosage_form'],
                'manufacturer' => $medicine['manufacturer'],
                'source' => 'medicines',
            ];
        }

        return ['status' => 'success', 'results' => $result];

    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

function searchMedicinesFromDB($search, $limit = 10)
{
    try {
        $conn = getDbConnection();


        $sql = "SELECT 
                i.item_id, 
                i.name, 
                i.manufacturer, 
                i.active_ingredient, 
                i.dosage_form,
                i.category,
                COALESCE(SUM(b.current_quantity), 0) as total_stock,
                GROUP_CONCAT(
                    CONCAT(b.batch_number, ':', b.current_quantity, ':', 
                          DATE_FORMAT(b.expiry_date, '%Y-%m-%d'), ':', b.retail_price)
                    SEPARATOR '|'
                ) as batch_details
            FROM pharmacy_items i 
            LEFT JOIN pharmacy_batches b ON i.item_id = b.item_id
            WHERE (i.name LIKE ? OR i.active_ingredient LIKE ?)
            GROUP BY i.item_id
            HAVING total_stock >= 0
            ORDER BY i.name
            LIMIT ?";

        $stmt = $conn->prepare($sql);
        $searchParam = "%{$search}%";
        $stmt->bind_param('ssi', $searchParam, $searchParam, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $medicines = [];
        while ($row = $result->fetch_assoc()) {
            $medicines[] = [
                'id' => $row['item_id'],
                'text' => $row['name'],
                'manufacturer' => $row['manufacturer'],
                'active_ingredient' => $row['active_ingredient'],
                'dosage_form' => $row['dosage_form'],
                'category' => $row['category'],
                'stock' => $row['total_stock'],
                'batch_details' => $row['batch_details'],
            ];
        }

        $conn->close();

        return ['status' => 'success', 'results' => $medicines];

    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

function cleanMedicineName($medicine_name)
{
    $last_space_pos = strrpos($medicine_name, ' ');
    if (false === $last_space_pos) {
        return $medicine_name;
    }

    return trim(substr($medicine_name, 0, $last_space_pos));
}

// EMR-style prescription search on the combined medicines table, with names cleaned of a trailing pack size
function searchMedicinesForPrescription($search_term)
{
    $search_term = trim(preg_replace('/\s+/', ' ', preg_replace('/[()]/', '', $search_term)));

    try {
        $conn = getDbConnection();
        $searchParam = "{$search_term}%";
        $rows = [];

        $stmt = $conn->prepare('SELECT id, name, active_ingredient, dosage_form, manufacturer
            FROM medicines
            WHERE name LIKE ? OR active_ingredient LIKE ?
            ORDER BY name
            LIMIT 10');
        $stmt->bind_param('ss', $searchParam, $searchParam);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $name = preg_match('/\s\d+$/', $row['name']) ? cleanMedicineName($row['name']) : $row['name'];
            $rows[] = [
                'id' => $row['id'],
                'text' => $name,
                'active_ingredient' => $row['active_ingredient'],
                'dosage_form' => $row['dosage_form'],
                'manufacturer' => $row['manufacturer'],
                'source' => 'medicines',
            ];
        }

        return ['status' => 'success', 'results' => $rows];
    } catch (Throwable $e) {
        error_log('Prescription medicine search failed: '.$e->getMessage());

        return ['status' => 'error', 'message' => 'Medicine search failed', 'results' => []];
    }
}

// Handle the AJAX request
if (isset($_GET['q'])) {
    $type = $_GET['type'] ?? 'sale'; // Default to sale behavior

    if ('prescription' === $type) {
        echo json_encode(searchMedicinesForPrescription($_GET['q']));
    } elseif ('purchase' === $type) {
        $tablesResults = searchMedicinesfromTables($_GET['q']);
        $dbResults = searchMedicinesFromDB($_GET['q']);

        // Merge results, prioritizing DB results
        $dbMedicineNames = array_column($dbResults['results'], 'text');
        $mergedResults = $dbResults['results'];

        if ('success' === $tablesResults['status']) {
            foreach ($tablesResults['results'] as $medicine) {
                if (!in_array($medicine['text'], $dbMedicineNames)) {
                    $mergedResults[] = $medicine;
                }
            }
        }

        echo json_encode(['results' => array_slice($mergedResults, 0, 10)]);
    } else {
        // For sales: Only search DB
        echo json_encode(searchMedicinesFromDB($_GET['q']));
    }
    exit;
}

echo json_encode([
    'status' => 'error',
    'message' => 'Search term is required',
]);
