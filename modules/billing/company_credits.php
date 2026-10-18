<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/credit_helper.php';

if (!checkPermission('bills', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Handle company credit limit update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id'])) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE company_accounts SET 
            credit_limit = ?, 
            payment_days = ?,
            late_fee_percentage = ?,
            terms_valid_from = ?,
            terms_valid_till = ?,
            status = ?
            WHERE company_id = ?");

        $stmt->bind_param(
            "diisssi",
            $_POST['credit_limit'],
            $_POST['payment_days'],
            $_POST['late_fee'],
            $_POST['valid_from'],
            $_POST['valid_till'],
            $_POST['status'],
            $_POST['company_id']
        );

        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle search
$companies = [];
if (isset($_GET['search'])) {
    $conn = getDbConnection();
    $search = "%" . sanitizeInput($_GET['search']) . "%";
    $stmt = $conn->prepare("
        SELECT ca.*, 
               COUNT(DISTINCT pl.bill_id) as total_bills,
               SUM(pl.amount_due - pl.amount_paid) as total_pending
        FROM company_accounts ca
        LEFT JOIN payment_ledger pl ON ca.company_id = pl.company_id
        WHERE ca.company_name LIKE ?
        GROUP BY ca.company_id");

    $stmt->bind_param("s", $search);
    $stmt->execute();
    $companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get pending bills for a company
if (isset($_GET['company_id']) && !isset($_POST['company_id'])) {
    $pending_bills = getCompanyPendingBills($_GET['company_id']);
    echo json_encode($pending_bills);
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Company Credit Management</title>
	<link rel="stylesheet" href="../../assets/css/admin.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Company Credit Management</h2>

		<!-- Search Form -->
		<div class="search-section">
			<form method="GET" class="search-form">
				<input type="text" name="search" placeholder="Search company..."
					value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
				<button type="submit">Search</button>
			</form>
		</div>

		<!-- Companies List -->
		<div class="companies-list">
			<?php foreach ($companies as $company): ?>
			<div class="company-card">
				<div class="company-header">
					<h3><?= htmlspecialchars($company['company_name']) ?>
					</h3>
					<span
						class="status <?= strtolower($company['status']) ?>">
						<?= $company['status'] ?>
					</span>
				</div>

				<div class="credit-info">
					<div class="info-row">
						<label>Credit Limit:</label>
						<span>₹<?= number_format($company['credit_limit'], 2) ?></span>
					</div>
					<div class="info-row">
						<label>Current Balance:</label>
						<span>₹<?= number_format($company['current_balance'], 2) ?></span>
					</div>
					<div class="info-row">
						<label>Available Credit:</label>
						<span>₹<?= number_format($company['credit_limit'] - $company['current_balance'], 2) ?></span>
					</div>
					<div class="info-row">
						<label>Payment Terms:</label>
						<span><?= $company['payment_days'] ?>
							days</span>
					</div>
					<div class="info-row">
						<label>Late Fee:</label>
						<span><?= $company['late_fee_percentage'] ?>%</span>
					</div>
				</div>

				<div class="pending-bills">
					<h4>Pending Bills
						(<?= $company['total_bills'] ?? 0 ?>)
					</h4>
					<p>Total Pending:
						₹<?= number_format($company['total_pending'] ?? 0, 2) ?>
					</p>
					<button
						onclick="viewPendingBills(<?= $company['company_id'] ?>)">View
						Details</button>
				</div>

				<div class="actions">
					<button
						onclick="editCompany(<?= $company['company_id'] ?>)">Edit
						Terms</button>
					<button
						onclick="viewStatement(<?= $company['company_id'] ?>)">View
						Statement</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Edit Company Modal -->
	<div id="editModal" class="modal" style="display: none;">
		<div class="modal-content"></div>
		<h3>Edit Company Terms</h3>
		<form id="editCompanyForm">
			<input type="hidden" name="company_id">
			<div class="form-group">
				<label>Credit Limit:</label>
				<input type="number" name="credit_limit" step="0.01" required>
			</div>
			<div class="form-group"></div>
			<label>Payment Terms (days):</label>
			<input type="number" name="payment_days" required>
	</div>
	<div class="form-group">
		<label>Late Fee (%):</label>
		<input type="number" name="late_fee" step="0.01" required>
	</div>
	<div class="form-group"></div>
	<label>Valid From:</label>
	<input type="date" name="valid_from" required>
	</div>
	<div class="form-group">
		<label>Valid Till:</label>
		<input type="date" name="valid_till">
	</div>
	<div class="form-group">
		<label>Status:</label>
		<select name="status" required></select>
		<option value="ACTIVE">Active</option>
		<option value="BLOCKED">Blocked</option>
		<option value="INACTIVE">Inactive</option>
		</select>
	</div>
	<button type="submit">Save Changes</button>
	<button type="button" onclick="closeModal()">Cancel</button>
	</form>
	</div>
	</div>

	<script></script>
	function viewPendingBills(companyId) {
	$.get('company_credits.php', { company_id: companyId })
	.done(function(response) {
	const bills = JSON.parse(response);
	// Display bills in a modal or new window
	let billsHtml = '<table>
		<tr>
			<th>Bill ID</th>
			<th>Date</th>
			<th>Amount</th>
			<th>Due Date</th>
		</tr>';
		bills.forEach(bill => {
		billsHtml += `<tr></tr>
		<td>${bill.bill_id}</td>
		<td>${new Date(bill.bill_date).toLocaleDateString()}</td>
		<td>₹${parseFloat(bill.balance_amount).toFixed(2)}</td>
		<td>${new Date(bill.payment_due_date).toLocaleDateString()}</td>
		</tr>`;
		});
		billsHtml += '
	</table>';
	// Show in modal
	showModal('Pending Bills', billsHtml);
	});
	}

	function editCompany(companyId) {
	// Load company data and show edit form
	const company = companies.find(c => c.company_id === companyId);
	if (company) {
	$('#editCompanyForm input[name="company_id"]').val(companyId);
	$('#editCompanyForm input[name="credit_limit"]').val(company.credit_limit);
	$('#editCompanyForm input[name="payment_days"]').val(company.payment_days);
	$('#editCompanyForm input[name="late_fee"]').val(company.late_fee_percentage);
	$('#editCompanyForm select[name="status"]').val(company.status);
	$('#editModal').show();
	}
	}

	$('#editCompanyForm').on('submit', function(e) {
	e.preventDefault();
	$.ajax({
	url: 'company_credits.php',
	method: 'POST',
	data: $(this).serialize(),
	success: function(response) {
	const data = JSON.parse(response);
	if (data.status === 'success') {
	alert('Company terms updated successfully');
	location.reload();
	} else {
	alert('Error: ' + data.message);
	}
	}
	});
	});

	function closeModal() {
	$('.modal').hide();
	}

	function viewStatement(companyId) {
	window.open(`print_company_statement.php?company_id=${companyId}`, '_blank');
	}
	</script>
</body>

</html>