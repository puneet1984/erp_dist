<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check permission
if (!canListBills()) {
    header('Location: ../../unauthorized.php');
    exit;
}

$conn = getDbConnection();

// Get medicines for dropdown
$medicine_query = 'SELECT DISTINCT pi.item_id, pi.name FROM pharmacy_items pi ORDER BY pi.name';
$medicine_result = $conn->query($medicine_query);
$medicines = $medicine_result->fetch_all(MYSQLI_ASSOC);

// Pagination settings
$records_per_page = 50;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$medicine_filter = isset($_GET['medicine']) ? sanitizeInput($_GET['medicine']) : '';
$date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = '(ps.bill_id LIKE ? OR p.name LIKE ? OR p.patient_id LIKE ? OR pi.name LIKE ?)';
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ssss';
}

if (!empty($status_filter)) {
    $where_conditions[] = 'ps.payment_status = ?';
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($medicine_filter)) {
    $where_conditions[] = 'pi.item_id = ?';
    $params[] = $medicine_filter;
    $param_types .= 'i';
}

if (!empty($date_from)) {
    $where_conditions[] = 'ps.sale_date >= ?';
    $params[] = $date_from;
    $param_types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = 'ps.sale_date <= ?';
    $params[] = $date_to;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE '.implode(' AND ', $where_conditions) : '';

// Get total records for pagination
$count_query = "
    SELECT COUNT(DISTINCT ps.transaction_id) as total
    FROM pharmacy_sales ps
    JOIN patients p ON ps.patient_id = p.patient_id
    LEFT JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id
    LEFT JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    LEFT JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    {$where_clause}
";

$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get bills data
$query = "
    SELECT ps.transaction_id, ps.bill_id, ps.sale_date, ps.payment_status,
           ps.final_amount, p.name as patient_name, p.patient_id,
           d.name as doctor_name, pt.type_name as payment_type,
           GROUP_CONCAT(CONCAT(pi.name, ' (', pb.batch_number, ') [', pti.quantity, ']') ORDER BY pi.name SEPARATOR ', ') as medicine_with_batch
    FROM pharmacy_sales ps
    JOIN patients p ON ps.patient_id = p.patient_id
    LEFT JOIN doctors d ON ps.doctor_id = d.doctor_id
    JOIN payment_types pt ON ps.payment_type_id = pt.payment_type_id
    LEFT JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id
    LEFT JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    LEFT JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    {$where_clause}
    GROUP BY ps.transaction_id, ps.bill_id, ps.sale_date, ps.payment_status,
             ps.final_amount, p.name, p.patient_id, d.name, pt.type_name
    ORDER BY ps.sale_date DESC
    LIMIT ? OFFSET ?
";

// Add pagination parameters
$params[] = $records_per_page;
$params[] = $offset;
$param_types .= 'ii';

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Pharmacy Bills</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>


	<div class="main-content">
		<div class="container-fluid">
			<div class="row mb-3">
				<div class="col">
					<h2><i class="fas fa-receipt"></i> Pharmacy Bills</h2>
				</div>
				<div class="col text-end">
					<?php if (checkPermission('bills', 'create')) { ?>
					<a href="new_sale.php" class="btn btn-success">
						<i class="fas fa-plus"></i> New Sale
					</a>
					<?php } ?>
				</div>
			</div>

			<!-- Search and Filter Form -->
			<div class="card mb-3">
				<div class="card-body">
					<form method="GET" class="row g-3">
						<div class="col-md-3">
							<input type="text" class="form-control" name="search"
								placeholder="Search by Bill ID, Patient Name, ID, Medicine..."
								value="<?php echo htmlspecialchars($search); ?>">
						</div>
						<div class="col-md-2">
							<select name="medicine" class="form-select">
								<option value="">All Medicines</option>
								<?php foreach ($medicines as $medicine) { ?>
								<option
									value="<?php echo $medicine['item_id']; ?>"
									<?php echo $medicine_filter == $medicine['item_id'] ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($medicine['name']); ?>
								</option>
								<?php } ?>
							</select>
						</div>
						<div class="col-md-2">
							<select name="status" class="form-select">
								<option value="">All Status</option>
								<option value="PENDING" <?php echo 'PENDING' === $status_filter ? 'selected' : ''; ?>>Pending
								</option>
								<option value="PAID" <?php echo 'PAID' === $status_filter ? 'selected' : ''; ?>>Paid
								</option>
								<option value="CANCELLED" <?php echo 'CANCELLED' === $status_filter ? 'selected' : ''; ?>>Cancelled
								</option>
							</select>
						</div>
						<div class="col-md-2">
							<input type="date" class="form-control" name="date_from" placeholder="From Date"
								value="<?php echo htmlspecialchars($date_from); ?>">
						</div>
						<div class="col-md-1">
							<input type="date" class="form-control" name="date_to" placeholder="To Date"
								value="<?php echo htmlspecialchars($date_to); ?>">
						</div>
						<div class="col-md-2">
							<button type="submit" class="btn btn-primary">
								<i class="fas fa-search"></i> Search
							</button>
							<a href="list_pharmacy_bills.php" class="btn btn-secondary">
								<i class="fas fa-undo"></i> Reset
							</a>
						</div>
					</form>
				</div>
			</div>

			<!-- Results Summary -->
			<div class="row mb-3">
				<div class="col">
					<p class="text-muted">
						Showing <?php echo count($bills); ?> of
						<?php echo $total_records; ?> bills
						<?php if ($page > 1) {
						    echo "(Page {$page} of {$total_pages})";
						} ?>
					</p>
				</div>
			</div>

			<!-- Bills Table -->
			<div class="card">
				<div class="card-body">
					<?php if (empty($bills)) { ?>
					<div class="text-center py-4">
						<i class="fas fa-receipt fa-3x text-muted mb-3"></i>
						<h5>No bills found</h5>
						<p class="text-muted">Try adjusting your search criteria or create a new sale.</p>
					</div>
					<?php } else { ?>
					<div class="table-responsive">
						<table class="table table-striped table-hover">
							<thead class="table-dark">
								<tr>
									<th>Bill ID</th>
									<th>Date</th>
									<th>Patient</th>
									<th>Medicine(s) & Batch</th>
									<th>Payment Type</th>
									<th>Amount</th>
									<th>Status</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($bills as $bill) { ?>
								<tr>
									<td>
										<strong><?php echo htmlspecialchars($bill['bill_id']); ?></strong>
									</td>
									<td><?php echo formatDisplayDate($bill['sale_date']); ?>
									</td>
									<td>
										<?php echo htmlspecialchars($bill['patient_name']); ?>
										<br><small
											class="text-muted"><?php echo htmlspecialchars($bill['patient_id']); ?></small>
									</td>
									<td>
										<small><?php echo htmlspecialchars(html_entity_decode($bill['medicine_with_batch'] ?: 'N/A', ENT_QUOTES | ENT_HTML401, 'UTF-8')); ?></small>
									</td>
									<td><?php echo htmlspecialchars($bill['payment_type']); ?>
									</td>
									<td><strong>₹<?php echo number_format($bill['final_amount'], 2); ?></strong>
									</td>
									<td>
										<?php
						                    $status_class = '';
								    switch ($bill['payment_status']) {
								        case 'PAID': $status_class = 'success';
								            break;
								        case 'PENDING': $status_class = 'warning';
								            break;
								        case 'CANCELLED': $status_class = 'danger';
								            break;
								        default: $status_class = 'secondary';
								    }
								    ?>
										<span
											class="badge bg-<?php echo $status_class; ?>">
											<?php echo $bill['payment_status']; ?>
										</span>
									</td>
									<td>
										<div class="btn-group btn-group-sm">
											<a href="view_pharmacy_bill.php?id=<?php echo $bill['transaction_id']; ?>"
												class="btn btn-outline-primary" title="View">
												<i class="fas fa-eye"></i>
											</a>
											<?php if (checkPermission('bills', 'edit')) { ?>
											<a href="edit_pharmacy_bill.php?id=<?php echo $bill['transaction_id']; ?>"
												class="btn btn-outline-warning" title="Edit"
												onclick="return confirmEditPaidBill('<?php echo $bill['payment_status']; ?>')">
												<i class="fas fa-edit"></i>
											</a>
											<?php } ?>
											<button
												onclick="window.open('print_pharmacy_bill.php?id=<?php echo $bill['transaction_id']; ?>&trigger_print=1', '_blank')"
												class="btn btn-outline-info" title="Print">
												<i class="fas fa-print"></i>
											</button>
										</div>
									</td>
								</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>

					<!-- Pagination -->
					<?php if ($total_pages > 1) { ?>
					<nav aria-label="Bills pagination">
						<ul class="pagination justify-content-center">
							<?php if ($page > 1) { ?>
							<li class="page-item">
								<a class="page-link"
									href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
									Previous
								</a>
							</li>
							<?php } ?>

							<?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); ++$i) { ?>
							<li
								class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
								<a class="page-link"
									href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
									<?php echo $i; ?>
								</a>
							</li>
							<?php } ?>

							<?php if ($page < $total_pages) { ?>
							<li class="page-item">
								<a class="page-link"
									href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
									Next
								</a>
							</li>
							<?php } ?>
						</ul>
					</nav>
					<?php } ?>
					<?php } ?>
				</div>
			</div>
		</div>
	</div>

	<script>
		function confirmEditPaidBill(status) {
			if (status === 'PAID') {
				return confirm(
					'Warning: This bill has already been paid. Edit only if there was a data entry mistake. Do you want to continue?'
				);
			}
			return true;
		}
	</script>
</body>

</html>