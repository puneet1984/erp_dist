<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check session and permissions
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

if (!checkPermission('reports', 'view')) {
    header('Location: ../../auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();

// Get filter parameters
$from_date = sanitizeInput($_GET['from_date'] ?? date('Y-m-01'));
$to_date = sanitizeInput($_GET['to_date'] ?? date('Y-m-d'));
$company_id = sanitizeNumeric($_GET['company_id'] ?? 0);
$status = sanitizeInput($_GET['status'] ?? 'PENDING');

// Handle Excel export
if (isset($_GET['export'])) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="pending_bills_ledger.xls"');
}

// Fetch pending bills
$where_conditions = ["l.payment_status != 'PAID' AND l.payment_status != 'CANCELLED'"];
if ($company_id) {
    $where_conditions[] = "l.company_id = " . $company_id;
}
if ($status !== 'ALL') {
    $where_conditions[] = "l.payment_status = '" . $conn->real_escape_string($status) . "'";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$query = "SELECT 
    l.ledger_id,
    l.bill_id,
    l.transaction_date,
    l.amount_due as debit,
    l.amount_paid as credit,
    l.balance_amount,
    l.payment_status,
    c.company_name,
    COALESCE(p.name, '') as patient_name,
    b.remarks as narration,
    CASE 
        WHEN l.amount_due > 0 THEN 'Invoice'
        WHEN l.amount_paid > 0 THEN 'Payment'
    END as transaction_type
FROM payment_ledger l
LEFT JOIN company_accounts c ON l.company_id = c.company_id
LEFT JOIN bills b ON l.bill_id = b.bill_id
LEFT JOIN patients p ON b.patient_id = p.patient_id
$where_clause
ORDER BY c.company_name, l.transaction_date ASC";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Pending Bills Ledger</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container{
            width: 1200px;
            max-width: 1200px;
        }
        .filters {
            margin: 20px 0;
            padding: 15px;
            background: #f5f5f5;
            border-radius: 4px;
        }
        .filter-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .filter-row:last-child {
            margin-bottom: 0;
        }
        .form-group {
            /* flex: 1; */
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 6px;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .summary {
            margin: 20px 0;
            font-weight: bold;
        }
        .status-PENDING { color: #f0ad4e; }
        .status-OVERDUE { color: #d9534f; }
        .status-PARTIAL { color: #5bc0de; }
        .company-total {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .company-total td {
            border-top: 2px solid #dee2e6;
            border-bottom: 2px solid #dee2e6;
        }
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>
    
    <div class="container">
        <h2>Pending Bills Ledger</h2>
        
        <div class="filters">
            <form method="GET" class="form-inline">
                <div class="filter-row">
                    <div class="form-group">
                        <label>From Date:</label>
                        <input type="date" name="from_date" value="<?php echo $from_date; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date:</label>
                        <input type="date" name="to_date" value="<?php echo $to_date; ?>">
                    </div>
                </div>
                <div class="filter-row">
                    <div class="form-group">
                        <label>Company:</label>
                        <select name="company_id">
                            <option value="">All Companies</option>
                            <?php
                            $companies = $conn->query("SELECT company_id, company_name FROM company_accounts WHERE status = 'ACTIVE' ORDER BY company_name");
                            while ($company = $companies->fetch_assoc()) {
                                $selected = $company_id == $company['company_id'] ? 'selected' : '';
                                echo "<option value='{$company['company_id']}' $selected>{$company['company_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status">
                            <option value="ALL" <?php echo $status === 'ALL' ? 'selected' : ''; ?>>All</option>
                            <option value="PENDING" <?php echo $status === 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                            <option value="OVERDUE" <?php echo $status === 'OVERDUE' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="PARTIAL" <?php echo $status === 'PARTIAL' ? 'selected' : ''; ?>>Partially Paid</option>
                        </select>
                    </div>
                </div>
                <div class="button-group">
                    <button type="submit" class="btn-primary">Filter</button>
                    <button type="submit" name="export" value="1" class="btn-secondary">Export to Excel</button>
                </div>
            </form>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transaction Type</th>
                    <th>Bill No.</th>
                    <th>Company</th>
                    <th>Patient Name</th>
                    <th>Narration</th>
                    <th>Debit (₹)</th>
                    <th>Credit (₹)</th>
                    <th>Balance (₹)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_debit = 0;
                $total_credit = 0;
                $running_balance = 0;
                $current_company = '';

                while ($row = $result->fetch_assoc()) {
                    if ($current_company != $row['company_name']) {
                        if ($current_company != '') {
                            // Print company total
                            echo "<tr class='company-total'>";
                            echo "<td colspan='6'><strong>Company Total</strong></td>";
                            echo "<td class='text-right'><strong>₹" . number_format($total_debit, 2) . "</strong></td>";
                            echo "<td class='text-right'><strong>₹" . number_format($total_credit, 2) . "</strong></td>";
                            echo "<td class='text-right'><strong>₹" . number_format($running_balance, 2) . "</strong></td>";
                            echo "<td></td>";
                            echo "</tr>";
                            echo "<tr><td colspan='10'>&nbsp;</td></tr>";
                        }
                        $current_company = $row['company_name'];
                        $running_balance = 0;
                        $total_debit = 0;
                        $total_credit = 0;
                    }

                    $total_debit += $row['debit'];
                    $total_credit += $row['credit'];
                    $running_balance = $total_debit - $total_credit;

                    $status_class = "status-{$row['payment_status']}";
                    ?>
                    <tr>
                        <td><?php echo date('d-m-Y', strtotime($row['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['transaction_type'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['bill_id'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['company_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['patient_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['narration'] ?? ''); ?></td>
                        <td class="text-right"><?php echo $row['debit'] > 0 ? number_format($row['debit'], 2) : ''; ?></td>
                        <td class="text-right"><?php echo $row['credit'] > 0 ? number_format($row['credit'], 2) : ''; ?></td>
                        <td class="text-right">₹<?php echo number_format($running_balance, 2); ?></td>
                        <td class="<?php echo $status_class; ?>"><?php echo $row['payment_status']; ?></td>
                    </tr>
                <?php } 
                
                // Print last company total
                if ($current_company != '') {
                    echo "<tr class='company-total'>";
                    echo "<td colspan='6'><strong>Company Total</strong></td>";
                    echo "<td class='text-right'><strong>₹" . number_format($total_debit, 2) . "</strong></td>";
                    echo "<td class='text-right'><strong>₹" . number_format($total_credit, 2) . "</strong></td>";
                    echo "<td class='text-right'><strong>₹" . number_format($running_balance, 2) . "</strong></td>";
                    echo "<td></td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <script>
        // Add datepicker if needed
        // Add any client-side functionality here
    </script>
</body>
</html>
