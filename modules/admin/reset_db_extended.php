<?php
define('SECURE_ACCESS', true);
require_once __DIR__ . '/../../includes/core.php';

if (!isAdmin()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    header('Location: ../auth/unauthorized.php');
    exit;
}

class DatabaseManager {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function getAutoIncrementValue($table) {
        $result = $this->conn->query("SHOW TABLE STATUS LIKE '$table'");
        if ($result && $row = $result->fetch_assoc()) {
            return $row['Auto_increment'];
        }
        return null;
    }

    private function getMaxId($table, $column) {
        $result = $this->conn->query("SELECT MAX(`$column`) as max_id FROM `$table`");
        if ($result && $row = $result->fetch_assoc()) {
            return $row['max_id'];
        }
        return 0;
    }

    public function getTables() {
        $tables = [];
        $result = $this->conn->query("SHOW TABLES");
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tableName = $row[0];
            $autoIncrementCol = $this->getAutoIncrementColumn($tableName);
            $autoIncrementValue = $this->getAutoIncrementValue($tableName);
            $maxId = $autoIncrementCol ? $this->getMaxId($tableName, $autoIncrementCol) : null;
            $countResult = $this->conn->query("SELECT COUNT(*) as count FROM `$tableName`");
            $count = $countResult->fetch_assoc()['count'];
            
            $tables[] = [
                'name' => $tableName,
                'count' => $count,
                'auto_increment_column' => $autoIncrementCol,
                'auto_increment_value' => $autoIncrementValue,
                'max_id' => $maxId
            ];
        }
        return $tables;
    }

    private function getAutoIncrementColumn($table) {
        $result = $this->conn->query("SHOW COLUMNS FROM `$table` WHERE Extra LIKE '%auto_increment%'");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['Field'];
        }
        return null;
    }

    public function resetTables($tables) {
        try {
            $this->conn->autocommit(false);
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                $table = $this->conn->real_escape_string($table);
                if (!$this->conn->query("TRUNCATE TABLE `$table`")) {
                    throw new Exception($this->conn->error);
                }
                if (!$this->conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1")) {
                    throw new Exception("Error resetting auto-increment for $table");
                }
            }

            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        } finally {
            $this->conn->autocommit(true);
        }
    }

    public function resequenceIds($tables) {
        try {
            $this->conn->autocommit(false);
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                $table = $this->conn->real_escape_string($table);
                $autoIncrementCol = $this->getAutoIncrementColumn($table);
                
                if (!$autoIncrementCol) {
                    continue; // Skip tables without auto increment
                }

                // Create temp table and resequence
                if (!$this->conn->query("CREATE TEMPORARY TABLE temp_table LIKE `$table`")) {
                    throw new Exception("Error creating temp table for $table");
                }

                $query = "INSERT INTO temp_table 
                         SELECT NULL as `$autoIncrementCol`, t.* 
                         FROM (SELECT * FROM `$table` ORDER BY `$autoIncrementCol`) AS t";
                
                if (!$this->conn->query($query)) {
                    throw new Exception("Error copying data for $table");
                }

                if (!$this->conn->query("DROP TABLE `$table`")) {
                    throw new Exception("Error dropping table $table");
                }

                if (!$this->conn->query("RENAME TABLE temp_table TO `$table`")) {
                    throw new Exception("Error renaming table $table");
                }

                if (!$this->conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1")) {
                    throw new Exception("Error resetting auto-increment for $table");
                }
            }

            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->conn->query("DROP TABLE IF EXISTS temp_table");
            throw $e;
        } finally {
            $this->conn->autocommit(true);
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $conn = getDbConnection();
        $dbManager = new DatabaseManager($conn);
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['action']) || !isset($data['tables'])) {
            throw new Exception("Invalid request parameters");
        }

        $result = false;
        switch ($data['action']) {
            case 'reset':
                $result = $dbManager->resetTables($data['tables']);
                break;
            case 'resequence':
                $result = $dbManager->resequenceIds($data['tables']);
                break;
            default:
                throw new Exception("Invalid action");
        }

        echo json_encode([
            'success' => true,
            'message' => $result ? 'Operation completed successfully' : 'No changes made'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-list {
            max-height: 600px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }
        .table-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 15px;
            margin: 5px 0;
            background: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .table-item:hover {
            background: #e9ecef;
        }
        .count-badge {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8em;
        }
        .warning-text {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container my-4">
        <h2>Database Management</h2>
        <div class="row">
            <div class="col-md-8">
                <div class="table-list">
                    <?php
                    $conn = getDbConnection();
                    $dbManager = new DatabaseManager($conn);
                    $tables = $dbManager->getTables();
                    foreach ($tables as $table): ?>
                        <div class="table-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="tables[]" 
                                       value="<?= htmlspecialchars($table['name']) ?>" id="table_<?= htmlspecialchars($table['name']) ?>">
                                <label class="form-check-label" for="table_<?= htmlspecialchars($table['name']) ?>">
                                    <?= htmlspecialchars($table['name']) ?>
                                    <?php if ($table['auto_increment_column']): ?>
                                        <small class="text-muted">
                                            (Auto: <?= htmlspecialchars($table['auto_increment_column']) ?>,
                                            Next: <?= $table['auto_increment_value'] ?? 1 ?>,
                                            Max: <?= $table['max_id'] ?>)
                                        </small>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <span class="count-badge"><?= $table['count'] ?> rows</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-3">
                    <button class="btn btn-danger" onclick="performAction('reset')">Reset Selected Tables</button>
                    <button class="btn btn-warning" onclick="performAction('resequence')">Resequence IDs</button>
                    <button class="btn btn-secondary" onclick="selectAll()">Select All</button>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Actions</h5>
                        <p><strong>Reset Tables:</strong> Deletes all data and resets auto-increment counters</p>
                        <p><strong>Resequence IDs:</strong> Reorders existing IDs sequentially without deleting data</p>
                        <div class="alert alert-warning">
                            Always backup your database before performing these operations!
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function selectAll() {
        const checkboxes = document.querySelectorAll('input[name="tables[]"]');
        const someUnchecked = Array.from(checkboxes).some(cb => !cb.checked);
        checkboxes.forEach(cb => cb.checked = someUnchecked);
    }

    function performAction(action) {
        const selectedTables = Array.from(document.querySelectorAll('input[name="tables[]"]:checked'))
            .map(checkbox => checkbox.value);

        if (!selectedTables.length) {
            alert('Please select at least one table');
            return;
        }

        const message = action === 'reset' 
            ? 'This will DELETE ALL DATA in the selected tables. Are you sure?'
            : 'This will reorder all IDs in the selected tables. Are you sure?';

        if (!confirm(message)) {
            return;
        }

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: action,
                tables: selectedTables
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                throw new Error(data.error);
            }
        })
        .catch(error => alert('Error: ' + error.message));
    }
    </script>
</body>
</html>