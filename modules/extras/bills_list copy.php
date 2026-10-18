<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Allow both view and list permissions to access this page
if (!canListBills()) {
    exit('Access Denied');
}

$conn = getDbConnection();
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? 'all'; // all, hospital, pharmacy
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Modify query to use services.category column
$query = "SELECT b.bill_id, b.transaction_id, b.bill_date, b.patient_id, b.total_amount, 
          b.discount, b.final_amount, b.payment_status, b.payment_type_id, 
          b.doctor_id, b.source, 
          p.name as patient_name, pt.type_name as payment_type, 
          d.name as doctor_name,
          GROUP_CONCAT(DISTINCT s.category) as service_categories,
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
            /* Hospital bills remain same */
            SELECT b.bill_id, b.transaction_id, b.bill_date, b.FY, b.patient_id, b.total_amount, 
                   b.discount, b.final_amount, pl.payment_status, 
                   b.payment_type_id, b.doctor_id, 'hospital' as source
            FROM bills b
            LEFT JOIN payment_ledger pl ON b.bill_id = pl.bill_id
            UNION ALL 
            /* Updated pharmacy bills query */
            SELECT ps.bill_id, ps.transaction_id, ps.sale_date as bill_date,
                   ps.FY, ps.patient_id, ps.subtotal as total_amount,
                   ps.discount_amount as discount, ps.final_amount,
                   ps.payment_status,
                   ps.payment_type_id,
                   ps.doctor_id,
                   'pharmacy' as source
            FROM pharmacy_sales ps
          ) b
          JOIN patients p ON b.patient_id = p.patient_id
          JOIN payment_types pt ON b.payment_type_id = pt.payment_type_id
          LEFT JOIN doctors d ON b.doctor_id = d.doctor_id
          LEFT JOIN bill_items bi ON b.bill_id = bi.bill_id
          LEFT JOIN services s ON bi.service_id = s.service_id
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
// Modify the query for doctors to see only their patients
if (2 === $_SESSION['role']) { // Doctor role
    $query .= ' AND EXISTS (
        SELECT 1 FROM doctor_patients dp 
        WHERE dp.patient_id = b.patient_id 
        AND dp.doctor_id = ?
    )';
    $params[] = $_SESSION['user_id'];
    $types .= 'i';
}
// Update GROUP BY to include all non-aggregated columns
$query .= ' GROUP BY b.bill_id, b.transaction_id, b.bill_date, b.patient_id, b.total_amount, 
            b.discount, b.final_amount, b.payment_status, b.payment_type_id, 
            b.doctor_id, p.name, pt.type_name, d.name, b.source 
            ORDER BY LPAD(SUBSTRING_INDEX(b.bill_id, "/", -1), 5, "0") DESC LIMIT 200';
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// After fetching bills, calculate total with card fees
$total_amount = 0;
foreach ($bills as $bill) {
    $total_amount += $bill['final_amount'] + $bill['card_fee'];
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Bills List</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>
	<style>
		.container {
			max-width: 1400px !important;
		}

		/* Add text-align left for table headers */
		#billsTable thead th {
			text-align: left !important;
			font-size: 0.95em;
		}

		.btn-edit,
		.btn-danger,
		.btn-view,
		.btn-print,
		.btn-delete {
			margin: 5px;
			padding: 5px !important;
		}

		/* Add table content font size rules */
		#billsTable tbody td {
			font-size: 0.9em !important;
		}

		.status-badge {
			font-size: 0.85em;
		}

		#billsTable td small {
			font-size: 0.8em;
		}

		.dataTables_wrapper .dataTables_length,
		.dataTables_wrapper .dataTables_filter {
			margin-bottom: 15px;
		}

		.dataTables_wrapper .dataTables_length {
			float: left;
		}

		.dataTables_wrapper .dataTables_filter {
			float: right;
		}

		.dataTables_wrapper .dataTables_length label {
			display: inline-flex;
			align-items: center;
			gap: 5px;
		}

		.dataTables_wrapper .dataTables_length select {
			width: 60px;
			padding: 5px;
			margin: 0 5px;
		}

		/* Clear the floats */
		.dataTables_wrapper::after {
			content: '';
			display: table;
			clear: both;
		}

		/* Make the search input match other inputs */
		.dataTables_wrapper .dataTables_filter input {
			padding: 5px;
			margin-left: 5px;
		}

		/* Add new styles for search form */
		.search-form {
			display: flex;
			flex-direction: column;
			gap: 15px;
			margin-bottom: 20px;
		}

		.search-form .search-fields {
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

		.date-range {
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.date-range input[type="date"] {
			width: 150px;
		}

		.table-footer {
			margin-top: 20px;
			padding: 15px;
			background: #f8f9fa;
			border-radius: 4px;
			text-align: right;
			font-size: 1.1em;
		}

		.total-amount strong {
			color: #28a745;
			font-size: 1.2em;
		}
	</style>
</head>

<body>
	<!-- action bar -->
	<?php require_once '../../includes/simple_action_bar.php'; ?>
	<div class="container">
		<h2>Bills List</h2>
		<!-- Modified Search Form -->
		<form method="GET" class="search-form">
			<div class="search-fields">
				<div class="form-group">
					<label>Search Patient:</label>
					<input type="text" name="search" placeholder="Enter name or ID"
						value="<?php echo htmlspecialchars($search); ?>">
				</div>
				<div class="form-group">
					<label>Bill Type:</label>
					<select name="type">
						<option value="all" <?php echo 'all' === $type ? 'selected' : ''; ?>>All
							Bills</option>
						<option value="hospital" <?php echo 'hospital' === $type ? 'selected' : ''; ?>>Hospital
							Bills</option>
						<option value="pharmacy" <?php echo 'pharmacy' === $type ? 'selected' : ''; ?>>Pharmacy
							Bills</option>
					</select>
				</div>
				<div class="form-group">
					<label>Fiscal Year:</label>
					<select name="fy">
						<option value="">All Years</option>
						<?php
                        $fy_query = 'SELECT DISTINCT FY FROM (
							SELECT FY FROM bills 
							UNION 
							SELECT FY FROM pharmacy_sales
						) all_bills ORDER BY FY DESC';
$fy_result = $conn->query($fy_query);
$selected_fy = $_GET['fy'] ?? '';
while ($row = $fy_result->fetch_assoc()) {
    $selected = ($selected_fy === $row['FY']) ? 'selected' : '';
    echo "<option value='{$row['FY']}' {$selected}>{$row['FY']}</option>";
}
?>
					</select>
				</div>
				<div class="form-group">
					<label>Date Range:</label>
					<div class="date-range">
						<input type="date" name="from_date"
							value="<?php echo htmlspecialchars($from_date); ?>">
						<span>to</span>
						<input type="date" name="to_date"
							value="<?php echo htmlspecialchars($to_date); ?>">
					</div>
				</div>
			</div>
			<div class="search-buttons">
				<button type="submit">Search</button>
				<button type="button" onclick="clearSearch()">Clear</button>
			</div>
		</form>
		<div class="alert alert-info"
			style="color: lightcoral; margin-top: 5px; margin-bottom: 5px; text-align: center; font-weight: bold;">
			Showing 200 most recent bills. Use the search filters above for older bills.
		</div>
		<!-- Bills Table -->
		<div class="table-section">
			<table id="billsTable" class="display">
				<thead>
					<tr>
						<th>Bill Date</th>
						<th>Bill No</th>
						<th>Patient ID</th>
						<th>Patient Name</th>
						<th>Doctor</th>
						<th>Type</th>
						<th>Amount</th>
						<th>Status</th>
						<th>Services</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($bills as $bill) { ?>
					<tr>
						<td><?php echo formatDisplayDate($bill['bill_date']); ?>
						</td>
						<td><?php echo htmlspecialchars($bill['bill_id']); ?>
						</td>
						<td><?php echo htmlspecialchars($bill['patient_id']); ?>
						</td>
						<td><?php echo htmlspecialchars($bill['patient_name']); ?>
						</td>
						<td><?php echo htmlspecialchars('Dr. '.($bill['doctor_name'] ?? 'N/A')); ?>
						</td>
						<td><?php echo $bill['bill_type']; ?>
						</td>
						<td>₹<?php echo number_format($bill['final_amount'] + $bill['card_fee'], 2); ?>
						</td>
						<td>
							<span
								class="status-badge <?php echo strtolower($bill['payment_status']); ?>">
								<?php echo $bill['payment_status']; ?>
							</span>
							<br>
							<small><?php echo htmlspecialchars($bill['payment_type']); ?></small>
						</td>
						<td>
							<small><?php echo htmlspecialchars($bill['service_categories'] ?? 'N/A'); ?></small>
						</td>
						<td>
							<?php if (checkPermission('bills', 'view')) { ?>
							<button
								onclick="viewBill('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
								class="btn-view">View</button>
							<?php } ?>
							<?php if (canPrintBill()) { ?>
							<button
								onclick="printBill('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
								class="btn-print">Print</button>
							<?php } ?>
							<?php if (checkPermission('bills', 'edit') && 'PENDING' === $bill['payment_status']) { ?>
							<button
								onclick="editBill('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
								class="btn-edit">Edit</button>
							<?php } ?>
							<?php if (checkPermission('bills', 'delete') && 'PENDING' === $bill['payment_status']) { ?>
							<button
								onclick="confirmDelete('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
								class="btn-delete">Delete</button>
							<?php } ?>
							<?php if (checkPermission('bills', 'cancel') && 'PAID' === $bill['payment_status']) { ?>
							<button
								onclick="cancelBill('<?php echo $bill['bill_id']; ?>', '<?php echo $bill['transaction_id']; ?>', '<?php echo $bill['source']; ?>')"
								class="btn-danger">Cancel</button>
							<?php } ?>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<!-- Add after table -->
		<div class="table-footer">
			<div class="total-amount">
				Total Amount:
				<strong>₹<?php echo number_format($total_amount, 2); ?></strong>
			</div>
		</div>
	</div>
	<script>
		$(document).ready(function() {
			$('#billsTable').DataTable({
				order: [
					[1, 'desc']
				],
				columnDefs: [{
					targets: 1,
					render: function(data, type, row) {
						if (type === 'sort') {
							// Extract the last number and pad with zeros for sorting
							const billNumber = data.split('/').pop();
							return billNumber.padStart(5, '0');
						}
						return data;
					}
				}],
				pageLength: 50,
				lengthMenu: [
					[10, 25, 50, 100],
					[10, 25, 50, 100]
				],
				language: {
					lengthMenu: "Show _MENU_ entries",
					search: "Search:"
				}
			});
		});

		function viewBill(billId, transactionId, source) {
			const viewUrl = source === 'pharmacy' ?
				`../pharmacy/view_pharmacy_bill.php?id=${transactionId}` :
				`view_bill.php?id=${billId}&type=hospital`;
			window.location.href = viewUrl;
		}

		function printBill(billId, transactionId, source) {
			// Determine print URL based on source and use appropriate ID
			const printUrl = (source === 'pharmacy') ?
				`../pharmacy/print_pharmacy_bill.php?id=${transactionId}&trigger_print=1` :
				`print_bill.php?id=${billId}&type=hospital&trigger_print=1`;
			window.open(printUrl, '_blank');
		}

		function confirmDelete(billId, transactionId, source) {
			if (confirm('Are you sure you want to delete this bill? This action cannot be undone.')) {
				const deleteUrl = source === 'pharmacy' ?
					`../pharmacy/delete_pharmacy_bill.php?id=${transactionId}` :
					`delete_bill.php?id=${billId}&type=hospital`;
				window.location.href = deleteUrl;
			}
		}

		function cancelBill(billId, transactionId, source) {
			const cancelUrl = source === 'pharmacy' ?
				`../pharmacy/cancel_pharmacy_bill.php?id=${transactionId}` :
				`cancel_bill.php?id=${billId}&type=hospital`;
			window.location.href = cancelUrl;
		}

		function editBill(billId, transactionId, source) {
			const editUrl = source === 'pharmacy' ?
				`../pharmacy/edit_pharmacy_bill.php?id=${transactionId}` :
				`edit_bill.php?id=${billId}&type=hospital`;
			window.location.href = editUrl;
		}

		function clearSearch() {
			window.location.href = 'bills_list.php';
		}
	</script>
</body>

</html>