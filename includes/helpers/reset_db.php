<?php

define('SECURE_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';

error_reporting(0);
header('Content-Type: application/json');

$conn = getDbConnection();

function getTables($conn) {
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $tableName = $row[0];
        $countResult = $conn->query("SELECT COUNT(*) as count FROM `$tableName`");
        $count = $countResult->fetch_assoc()['count'];
        $tables[] = [
            'name' => $tableName,
            'count' => $count
        ];
    }
    return $tables;
}

function resetTables($conn, $tables)
{
    try {
        $conn->autocommit(false);

        // Disable foreign key checks
        $conn->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $table = $conn->real_escape_string($table);
            if (!$conn->query("TRUNCATE TABLE `$table`")) {
                throw new Exception($conn->error);
            }
// new code ----
            // Reset auto-increment value
            if (!$conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1")) {
                throw new Exception("Error resetting auto-increment for table $table: " . $conn->error);
            }
// end new code ----

        }

        // Re-enable foreign key checks
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');

        $conn->commit();
        $conn->autocommit(true);
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        return $e->getMessage();
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tables = getTables($conn);
        if ($tables === false) {
            throw new Exception("Failed to fetch tables");
        }
        echo json_encode(['tables' => $tables]);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception("No input received");
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }

        $tables = $data['tables'] ?? [];
        if (empty($tables)) {
            throw new Exception("No tables selected");
        }

        $result = resetTables($conn, $tables);
        if ($result === true) {
            echo json_encode(['success' => true, 'message' => 'Tables reset successfully']);
        } else {
            throw new Exception($result);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
exit;
