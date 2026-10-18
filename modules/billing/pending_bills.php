<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!canListBills()) {
    exit('Access Denied');
}

$conn = getDbConnection();
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

$query = "SELECT b.bill_id, b.transaction_id, b.bill_date, b.patient_id, b.total_amount, 
          b.discount, b.final_amount, b.payment_status, b.payment_type_id, 
          b.doctor_id, b.source, 
          p.name as patient_name, pt.type_name as payment_type, 
          d.name as doctor_name,
          GROUP_CONCAT(DISTINCT s.category) as service_categories,
          ca.company_name,
          CASE 
            WHEN b.source = 'pharmacy' THEN 'Pharmacy'
            WHEN b.source = 'hospital' THEN 'Hospital'
          END as bill_type,
          COALESCE(
            (SELECT SUM(
                CASE 
                    WHEN pc.payment_type_id = 3 THEN
                        JSON_EXTRACT(pc.payment_details, '$.fee_amount')
                    WHEN pc.payment_type_id = 8 THEN
                        JSON_EXTRACT(pc.payment_details, '$.payments.card.fee_amount')
                    ELSE 0
                END
            )
            FROM payment_collections pc
            WHERE pc.bill_id = b.bill_id), 0
          ) as card_fee
          FROM (
            SELECT b.bill_id, b.transaction_id, b.bill_date, b.FY, b.patient_id, b.total_amount, 
                   b.discount, b.final_amount, pl.payment_status, 
                   b.payment_type_id, b.doctor_id, 'hospital' as source
            FROM bills b
            LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id
            WHERE pl.payment_status = 'PENDING'
            UNION ALL 
            SELECT ps.bill_id, ps.transaction_id, ps.sale_date as bill_date,
                   ps.FY, ps.patient_id, ps.subtotal as total_amount,
                   ps.discount_amount as discount, ps.final_amount,
                   ps.payment_status,
                   ps.payment_type_id,
                   ps.doctor_id,
                   'pharmacy' as source
            FROM pharmacy_sales ps
            WHERE ps.payment_status = 'PENDING'
          ) b
          JOIN patients p ON b.patient_id = p.patient_id
          JOIN payment_types pt ON b.payment_type_id = pt.payment_type_id
          LEFT JOIN doctors d ON b.doctor_id = d.doctor_id
          LEFT JOIN bill_items bi ON b.bill_id = bi.bill_id
          LEFT JOIN services s ON bi.service_id = s.service_id
          LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id
          LEFT JOIN company_accounts ca ON (pl.company_id = ca.company_id AND b.payment_type_id = 2)
          WHERE (p.name LIKE ? OR b.patient_id LIKE ?)";

$params = ["%{$search}%", "%{$search}%"];
$types = 'ss';

if ('all' !== $type) {
    $query .= ' AND b.bill_id LIKE ?';
    $params[] = ('pharmacy' === $type ? '%/P/%' : '%/H/%');
    $types .= 's';
}

if ($from_date && $to_date) {
    $query .= ' AND b.bill_date BETWEEN ? AND ?';
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= 'ss';
}

if (!empty($_GET['fy'])) {
    $query .= ' AND b.FY = ?';
    $params[] = $_GET['fy'];
    $types .= 's';
}

if (2 === $_SESSION['role']) {
    $query .= ' AND EXISTS (
        SELECT 1 FROM doctor_patients dp 
        WHERE dp.patient_id = b.patient_id 
        AND dp.doctor_id = ?
    )';
    $params[] = $_SESSION['user_id'];
    $types .= 'i';
}

$query .= ' GROUP BY b.bill_id, b.transaction_id, b.bill_date, b.patient_id, b.total_amount, 
            b.discount, b.final_amount, b.payment_status, b.payment_type_id, 
            b.doctor_id, p.name, pt.type_name, d.name, b.source, ca.company_name 
            ORDER BY b.bill_date DESC';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_pending = 0;
foreach ($bills as $bill) {
    $total_pending += $bill['final_amount'] + $bill['card_fee'];
}

if (isset($_GET['format']) && 'json' === $_GET['format']) {
    header('Content-Type: application/json');
    echo json_encode([
        'bills' => $bills,
        'total_pending' => $total_pending,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Pending Bills</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>
	<style>
		.container {
			max-width: 1400px !important;
		}

		.pending-alert {
			background-color: #fff3cd;
			border: 1px solid #ffeeba;
			color: #856404;
			padding: 15px;
			margin-bottom: 20px;
			border-radius: 4px;
		}

		.total-pending {
			font-size: 1.2em;
			color: #dc3545;
			font-weight: bold;
		}

		#pendingBillsTable thead th {
			text-align: left !important;
			font-size: 0.95em;
		}

		.search-form {
			margin-bottom: 20px;
			display: flex;
			gap: 15px;
			align-items: flex-end;
		}
        .search-form .search-buttons {
			display: flex;
			gap: 10px;
		}
        .search-form button {
			height: 38px;
			min-width: 100px;
		}
        .search-form .search-fields {
			display: flex;
			gap: 15px;
			align-items: flex-end;
		}
		.form-group {
			display: flex;
			flex-direction: column;
			gap: 5px;
		}

		.action-buttons {
			display: flex;
			gap: 5px;
		}

		.btn-view,
		.btn-edit {
			padding: 5px 10px;
			font-size: 0.9em;
		}
        
.action-bar {
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        align-items: center;
        width: auto;
    }
    
    .action-bar button {
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 8px 15px;
    }
	</style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>
    <div class="container">
        <div class="action-bar">
            <button onclick="history.back()" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </button>
        </div>
        <h2>Pending Bills</h2>
        <div class="pending-alert">
            Total Pending Amount: <span class="total-pending">₹<?php echo number_format($total_pending, 2); ?></span>
        </div>

        <form method="GET" class="search-form">
            <div class="form-group">
                <label>Search Patient:</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or ID">
            </div>
            <div class="form-group">
                <label>Bill Type:</label>
                <select name="type">
                    <option value="all" <?php echo 'all' === $type ? 'selected' : ''; ?>>All Bills</option>
                    <option value="hospital" <?php echo 'hospital' === $type ? 'selected' : ''; ?>>Hospital Bills</option>
                    <option value="pharmacy" <?php echo 'pharmacy' === $type ? 'selected' : ''; ?>>Pharmacy Bills</option>
                </select>
            </div>
            <div class="form-group">
                <label>Date Range:</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
                    <span>to</span>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
            </div>
            <div class="search-buttons">
                <button type="submit" class="btn-primary">Search</button>
                <button type="button" class="btn-secondary" onclick="window.location.href='pending_bills.php'">Clear</button>
            </div>
        </form>

        <table id="pendingBillsTable" class="display">
            <thead>
                <tr>
                    <th>Bill Date</th>
                    <th>Bill No</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bills as $bill) { ?>
                <tr>
                    <td data-order="<?php echo htmlspecialchars((string) $bill['bill_date']); ?>"><?php echo formatDisplayDate($bill['bill_date']); ?></td>
                    <td><?php echo htmlspecialchars($bill['bill_id']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($bill['patient_name']); ?><br>
                        <small><?php echo htmlspecialchars($bill['patient_id']); ?>
                        <?php if (!empty($bill['company_name'])) { 
                            echo ' | '.$bill['company_name']; 
                        } ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($bill['doctor_name'] ? 'Dr. '.$bill['doctor_name'] : 'N/A'); ?></td>
                    <td><?php echo $bill['bill_type']; ?></td>
                    <td>₹<?php echo number_format($bill['final_amount'] + $bill['card_fee'], 2); ?></td>
                    <td>
                        <div class="action-buttons">
                            <?php if (checkPermission('bills', 'view')) { ?>
                            <button
                                onclick="viewBill('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
                                class="btn-view">View</button>
                            <?php } ?>
                            <?php if (checkPermission('bills', 'edit')) { ?>
                            <button
                                onclick="editBill('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
                                class="btn-edit">Edit</button>
                            <?php } ?>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <script>
        $(document).ready(function() {
            $('#pendingBillsTable').DataTable({
                order: [
                    [0, 'desc']
                ],
                pageLength: 50,
                lengthMenu: [
                    [10, 25, 50, 100],
                    [10, 25, 50, 100]
                ]
            });
        });

        function viewBill(billId, transactionId, source) {
            const viewUrl = source === 'pharmacy' ?
                `../pharmacy/view_pharmacy_bill.php?id=${transactionId}` :
                `view_bill.php?id=${billId}&type=hospital`;
            window.location.href = viewUrl;
        }

        function editBill(billId, transactionId, source) {
            const editUrl = source === 'pharmacy' ?
                `../pharmacy/edit_pharmacy_bill.php?id=${transactionId}` :
                `edit_bill.php?id=${billId}&type=hospital`;
            window.location.href = editUrl;
        }
    </script>
</body>

</html>