<?php 

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

// Check admin permissions
if (!checkPermission('admin', 'view')) {
    header('Location: ../../auth/unauthorized.php');
    exit;
}

if (!checkSessionTimeout()) {
    redirectToLogin('timeout=1');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Reset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 900px;
        }
        .table-list {
            max-height: auto;
            overflow-y: auto;
            max-width: 800px; /* Increased width for two columns */
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(2, 1fr); /* Two columns */
            gap: 10px; /* Gap between columns */
            padding: 10px;
            background-color: #ced4da;
            border-radius: 6px;
        }
        .warning-text {
            color: #dc3545;
            font-weight: bold;
        }
        .table-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            margin: 0;
            border-radius: 10px; /* Added back for individual items */
            background-color: #ffffff;
            border: 1px solid #dee2e6;
        }
        .table-item:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        .table-name {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .count-badge {
            background-color: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8em;
            min-width: 60px;
            text-align: center;
        }
        #tableCount {
            font-size: 1.2em;
        }
    </style>
</head>
<body>

<?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Database Reset Tool</h2>
            <span class="badge bg-secondary" id="tableCount">Total Tables: 0</span>
        </div>
        <div class="alert alert-danger">
            <strong>Warning!</strong> This action cannot be undone. Please be careful when selecting tables to reset.
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="mb-3">
                    <button class="btn btn-sm btn-secondary" id="selectAll">Select All</button>
                    <button class="btn btn-sm btn-secondary" id="deselectAll">Deselect All</button>
                </div>
                
                <div class="table-list mb-3" id="tableList">
                    <!-- Tables will be populated here -->
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="confirmReset">
                    <label class="form-check-label warning-text" for="confirmReset">
                        I understand that this action will permanently delete data from selected tables
                    </label>
                </div>
                
                <button class="btn btn-danger" id="resetBtn" disabled>Reset Selected Tables</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tableList = document.getElementById('tableList');
            const selectAllBtn = document.getElementById('selectAll');
            const deselectAllBtn = document.getElementById('deselectAll');
            const confirmCheckbox = document.getElementById('confirmReset');
            const resetBtn = document.getElementById('resetBtn');

            // Fetch tables
            fetch('../../includes/helpers/reset_db.php')
                .then(async response => {
                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.tables || !Array.isArray(data.tables)) {
                        throw new Error('Invalid response format');
                    }
                    
                    // Update total table count
                    document.getElementById('tableCount').textContent = `Total Tables: ${data.tables.length}`;
                    
                    data.tables.forEach(table => {
                        const div = document.createElement('div');
                        div.className = 'table-item';
                        div.innerHTML = `
                            <div class="table-name">
                                <input class="form-check-input table-checkbox" type="checkbox" value="${table.name}" id="${table.name}">
                                <label class="form-check-label" for="${table.name}">${table.name}</label>
                            </div>
                            <span class="count-badge">${table.count}</span>
                        `;
                        tableList.appendChild(div);
                    });
                })
                .catch(error => {
                    alert('Error loading tables: ' + error.message);
                    console.error('Error:', error);
                });

            // Handle select/deselect all
            selectAllBtn.addEventListener('click', () => {
                document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = true);
            });

            deselectAllBtn.addEventListener('click', () => {
                document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = false);
            });

            // Enable/disable reset button
            confirmCheckbox.addEventListener('change', () => {
                resetBtn.disabled = !confirmCheckbox.checked;
            });

            // Handle reset
            resetBtn.addEventListener('click', async () => {
                try {
                    const selectedTables = Array.from(document.querySelectorAll('.table-checkbox:checked'))
                        .map(cb => cb.value);

                    if (!selectedTables.length) {
                        alert('Please select at least one table');
                        return;
                    }

                    if (!confirm('Are you sure you want to reset the selected tables? This action cannot be undone!')) {
                        return;
                    }

                    const response = await fetch('../../includes/helpers/reset_db.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ tables: selectedTables })
                    });

                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
                    }

                    const result = await response.json();
                    if (result.success) {
                        alert('Tables reset successfully');
                        location.reload();
                    } else {
                        throw new Error(result.error || 'Unknown error occurred');
                    }
                } catch (error) {
                    alert('Error: ' + error.message);
                    console.error('Error:', error);
                }
            });
        });
    </script>
</body>
</html>