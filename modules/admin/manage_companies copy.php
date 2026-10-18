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

// Handle AJAX requests
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $conn = getDbConnection();
    $action = sanitizeInput($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'add':
                $company_name = capitalizeWords(sanitizeInput($_POST['company_name']));
                $credit_limit = sanitizeNumeric($_POST['credit_limit']);
                $payment_days = sanitizeNumeric($_POST['payment_days']);
                $late_fee_percentage = sanitizeNumeric($_POST['late_fee_percentage']);

                $stmt = $conn->prepare("INSERT INTO company_accounts 
                    (company_name, credit_limit, payment_days, late_fee_percentage) 
                    VALUES (?, ?, ?, ?)");
                
                $stmt->bind_param('sdid', 
                    $company_name,
                    $credit_limit,
                    $payment_days,
                    $late_fee_percentage
                );
                break;

            case 'update':
                $company_name = capitalizeWords(sanitizeInput($_POST['company_name']));
                $credit_limit = sanitizeNumeric($_POST['credit_limit']);
                $payment_days = sanitizeNumeric($_POST['payment_days']);
                $late_fee_percentage = sanitizeNumeric($_POST['late_fee_percentage']);
                $company_id = sanitizeNumeric($_POST['company_id']);

                $stmt = $conn->prepare("UPDATE company_accounts 
                    SET company_name = ?,
                        credit_limit = ?,
                        payment_days = ?,
                        late_fee_percentage = ?
                    WHERE company_id = ?");
                
                $stmt->bind_param('sdidi', 
                    $company_name,
                    $credit_limit,
                    $payment_days,
                    $late_fee_percentage,
                    $company_id
                );
                break;

            case 'toggle_status':
                $company_id = sanitizeNumeric($_POST['company_id']);
                $stmt = $conn->prepare("UPDATE company_accounts 
                    SET status = IF(status = 'ACTIVE', 'INACTIVE', 'ACTIVE')
                    WHERE company_id = ?");

                $stmt->bind_param('i', $company_id);
                break;

            case 'get':
                $company_id = sanitizeNumeric($_POST['company_id']);
                $stmt = $conn->prepare("SELECT * FROM company_accounts WHERE company_id = ?");
                $stmt->bind_param('i', $company_id);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                echo json_encode(['status' => 'success', 'data' => $result]);
                exit;

            default:
                throw new Exception('Invalid action');
        }

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            throw new Exception($stmt->error);
        }
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Display page
$conn = getDbConnection();
$companies = $conn->query("SELECT * FROM company_accounts ORDER BY company_name");
?>
<!DOCTYPE html>
<html>

<head>
    <title>Manage Companies</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container {
            max-width: 1200px;
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

        button:hover {
            opacity: 0.9;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <div class="header-actions">
            <h2>Manage Companies</h2>
            <button onclick="showAddCompanyForm()" class="btn-primary">Add New Company</button>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Company Name</th>
                    <th>Credit Limit</th>
                    <th>Payment Days</th>
                    <th>Current Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($company = $companies->fetch_assoc()) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($company['company_name']); ?></td>
                        <td>₹<?php echo number_format($company['credit_limit'], 2); ?></td>
                        <td><?php echo $company['payment_days']; ?></td>
                        <td>₹<?php echo number_format($company['current_balance'], 2); ?></td>
                        <td><?php echo $company['status']; ?></td>
                        <td>
                            <button onclick="editCompany(<?php echo $company['company_id']; ?>)">Edit</button>
                            <button onclick="toggleStatus(<?php echo $company['company_id']; ?>)">
                                <?php echo 'ACTIVE' === $company['status'] ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Company Modal -->
    <div id="companyModal" class="modal">
        <div class="modal-content">
            <h3>Company Details</h3>
            <form id="companyForm">
                <input type="hidden" name="company_id">
                <div class="form-group">
                    <label>Company Name:</label>
                    <input type="text" name="company_name" required>
                </div>
                <div class="form-group">
                    <label>Credit Limit:</label>
                    <input type="number" name="credit_limit" min="0">
                </div>
                <div class="form-group">
                    <label>Payment Days:</label>
                    <input type="number" name="payment_days" min="0">
                </div>
                <div class="form-group">
                    <label>Late Fee %:</label>
                    <input type="number" name="late_fee_percentage" step="0.01" min="0" max="100">
                </div>
                <button type="submit">Save</button>
                <button type="button" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function showAddCompanyForm() {
            document.querySelector('#companyForm').reset();
            document.querySelector('input[name="company_id"]').value = '';
            document.querySelector('#companyModal').style.display = 'block';
        }

        function editCompany(id) {
            fetch('manage_companies.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get&company_id=${id}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const form = document.querySelector('#companyForm');
                        const company = data.data;
                        form.company_id.value = company.company_id;
                        form.company_name.value = company.company_name;
                        form.credit_limit.value = company.credit_limit;
                        form.payment_days.value = company.payment_days;
                        form.late_fee_percentage.value = company.late_fee_percentage;
                        document.querySelector('#companyModal').style.display = 'block';
                    }
                });
        }

        function toggleStatus(id) {
            if (!confirm('Are you sure you want to change this company\'s status?')) return;

            fetch('manage_companies.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=toggle_status&company_id=${id}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        }

        document.querySelector('#companyForm').onsubmit = function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', formData.get('company_id') ? 'update' : 'add');

            fetch('manage_companies.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('Company saved successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        };

        function closeModal() {
            document.querySelector('#companyModal').style.display = 'none';
        }
    </script>
</body>

</html>