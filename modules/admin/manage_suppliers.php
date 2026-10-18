<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (!checkPermission('admin', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

if (!checkSessionTimeout()) {
    header('Location: ../auth/login.php?timeout=1');
    exit;
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    header('Content-Type: application/json');
    $conn = getDbConnection();
    $action = sanitizeInput($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'add':
                $supplier_name = capitalizeWords(sanitizeInput($_POST['supplier_name'] ?? ''));

                if (empty($supplier_name)) {
                    throw new Exception('Supplier name is required');
                }

                $stmt = $conn->prepare('SELECT supplier_id FROM suppliers WHERE supplier_name = ?');
                $stmt->bind_param('s', $supplier_name);
                $stmt->execute();

                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception('Supplier already exists');
                }

                $stmt = $conn->prepare('INSERT INTO suppliers (supplier_name) VALUES (?)');
                $stmt->bind_param('s', $supplier_name);
                $stmt->execute();
                echo json_encode(['status' => 'success', 'message' => 'Supplier added successfully']);
                exit;

            case 'update':
                $supplier_id = sanitizeNumeric($_POST['supplier_id'] ?? 0);
                $supplier_name = capitalizeWords(sanitizeInput($_POST['supplier_name'] ?? ''));

                if ($supplier_id <= 0 || empty($supplier_name)) {
                    throw new Exception('Supplier ID and name are required');
                }

                $stmt = $conn->prepare('SELECT supplier_id FROM suppliers WHERE supplier_name = ? AND supplier_id != ?');
                $stmt->bind_param('si', $supplier_name, $supplier_id);
                $stmt->execute();

                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception('Supplier name already in use');
                }

                $stmt = $conn->prepare('UPDATE suppliers SET supplier_name = ? WHERE supplier_id = ?');
                $stmt->bind_param('si', $supplier_name, $supplier_id);
                $stmt->execute();
                echo json_encode(['status' => 'success', 'message' => 'Supplier updated successfully']);
                exit;

            case 'get':
                $supplier_id = sanitizeNumeric($_POST['supplier_id'] ?? 0);
                if ($supplier_id <= 0) {
                    throw new Exception('Invalid supplier ID');
                }

                $stmt = $conn->prepare('SELECT supplier_id, supplier_name, created_at FROM suppliers WHERE supplier_id = ?');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $data = $stmt->get_result()->fetch_assoc();

                if (!$data) {
                    throw new Exception('Supplier not found');
                }

                echo json_encode(['status' => 'success', 'data' => $data]);
                exit;

            case 'delete':
                $supplier_id = sanitizeNumeric($_POST['supplier_id'] ?? 0);
                if ($supplier_id <= 0) {
                    throw new Exception('Invalid supplier ID');
                }

                $stmt = $conn->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id = ?');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $supplier = $stmt->get_result()->fetch_assoc();

                if (!$supplier) {
                    throw new Exception('Supplier not found');
                }

                $stmt = $conn->prepare('SELECT COUNT(*) as count FROM pharmacy_purchases WHERE supplier_id = ?');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $purchase_count = (int) $stmt->get_result()->fetch_assoc()['count'];

                $stmt = $conn->prepare('SELECT purchase_id, invoice_number FROM pharmacy_purchases WHERE supplier_id = ? ORDER BY purchase_id DESC LIMIT 10');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $purchase_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $stmt = $conn->prepare('SELECT COUNT(*) as count FROM pharmacy_batches WHERE supplier_id = ?');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $batch_count = (int) $stmt->get_result()->fetch_assoc()['count'];

                $stmt = $conn->prepare('SELECT batch_id, batch_number, invoice_number FROM pharmacy_batches WHERE supplier_id = ? ORDER BY batch_id DESC LIMIT 10');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();
                $batch_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                if ($purchase_count > 0 || $batch_count > 0) {
                    $usage_parts = [];
                    if ($purchase_count > 0) {
                        $purchase_refs = [];
                        foreach ($purchase_rows as $row) {
                            $purchase_refs[] = '#'.$row['purchase_id'].(!empty($row['invoice_number']) ? ' (Inv: '.$row['invoice_number'].')' : '');
                        }
                        $purchase_text = $purchase_count.' purchase(s)';
                        if (!empty($purchase_refs)) {
                            $purchase_text .= ' ['.implode(', ', $purchase_refs).($purchase_count > count($purchase_refs) ? ', ...' : '').']';
                        }
                        $usage_parts[] = $purchase_text;
                    }
                    if ($batch_count > 0) {
                        $batch_refs = [];
                        foreach ($batch_rows as $row) {
                            $label = '#'.$row['batch_id'];
                            if (!empty($row['batch_number'])) {
                                $label .= ' (Batch: '.$row['batch_number'].')';
                            }
                            if (!empty($row['invoice_number'])) {
                                $label .= ' (Inv: '.$row['invoice_number'].')';
                            }
                            $batch_refs[] = $label;
                        }
                        $batch_text = $batch_count.' stock batch(es)';
                        if (!empty($batch_refs)) {
                            $batch_text .= ' ['.implode(', ', $batch_refs).($batch_count > count($batch_refs) ? ', ...' : '').']';
                        }
                        $usage_parts[] = $batch_text;
                    }
                    throw new Exception('Cannot delete supplier because it is used in '.implode(' and ', $usage_parts));
                }

                $stmt = $conn->prepare('DELETE FROM suppliers WHERE supplier_id = ?');
                $stmt->bind_param('i', $supplier_id);
                $stmt->execute();

                echo json_encode(['status' => 'success', 'message' => 'Supplier deleted successfully']);
                exit;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$conn = getDbConnection();
$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name ASC');
?>
<!DOCTYPE html>
<html>

<head>
    <title>Manage Suppliers</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container {
            max-width: 1100px;
            margin: 20px auto;
            padding: 0 15px;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: #fff;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .data-table th {
            background-color: #f5f5f5;
        }

        .data-table tr:hover {
            background-color: #f9f9f9;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 5px;
            width: 90%;
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <div class="header-actions">
            <h2>Manage Suppliers</h2>
            <button onclick="showAddSupplierForm()" class="btn-primary">Add New Supplier</button>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Supplier ID</th>
                    <th>Supplier Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $serial = 1; ?>
                <?php while ($supplier = $suppliers->fetch_assoc()) { ?>
                    <tr>
                        <td><?php echo $serial++; ?></td>
                        <td><?php echo (int) $supplier['supplier_id']; ?></td>
                        <td><?php echo htmlspecialchars($supplier['supplier_name']); ?></td>
                        <td>
                            <button onclick="editSupplier(<?php echo (int) $supplier['supplier_id']; ?>)">Edit</button>
                            <button onclick='deleteSupplier(<?php echo (int) $supplier['supplier_id']; ?>, <?php echo json_encode($supplier['supplier_name']); ?>)'>Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <div id="supplierModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle">Add Supplier</h3>
            <form id="supplierForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="supplier_id" id="supplier_id">

                <div class="form-group">
                    <label for="supplier_name">Supplier Name</label>
                    <input type="text" id="supplier_name" name="supplier_name" maxlength="100" required>
                </div>

                <button type="submit" class="btn-primary">Save</button>
                <button type="button" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function showAddSupplierForm() {
            document.getElementById('modalTitle').textContent = 'Add Supplier';
            document.getElementById('formAction').value = 'add';
            document.getElementById('supplier_id').value = '';
            document.getElementById('supplier_name').value = '';
            document.getElementById('supplierModal').style.display = 'block';
        }

        function editSupplier(supplierId) {
            const formData = new FormData();
            formData.append('action', 'get');
            formData.append('supplier_id', supplierId);

            fetch('manage_suppliers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('modalTitle').textContent = 'Edit Supplier';
                        document.getElementById('formAction').value = 'update';
                        document.getElementById('supplier_id').value = data.data.supplier_id;
                        document.getElementById('supplier_name').value = data.data.supplier_name;
                        document.getElementById('supplierModal').style.display = 'block';
                    } else {
                        alert(data.message || 'Unable to fetch supplier details');
                    }
                })
                .catch(() => alert('Unable to fetch supplier details'));
        }

        function closeModal() {
            document.getElementById('supplierModal').style.display = 'none';
        }

        function deleteSupplier(supplierId, supplierName) {
            if (!confirm(`Delete supplier "${supplierName}"?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('supplier_id', supplierId);

            fetch('manage_suppliers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message || 'Deleted successfully');
                        location.reload();
                    } else {
                        alert(data.message || 'Delete failed');
                    }
                })
                .catch(() => alert('Delete failed'));
        }

        document.getElementById('supplierForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('manage_suppliers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message || 'Saved successfully');
                        location.reload();
                    } else {
                        alert(data.message || 'Operation failed');
                    }
                })
                .catch(() => alert('Operation failed'));
        });

        window.onclick = function(event) {
            const modal = document.getElementById('supplierModal');
            if (event.target === modal) {
                closeModal();
            }
        };
    </script>
</body>

</html>