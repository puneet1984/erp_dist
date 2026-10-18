<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Check admin permissions
if (!checkPermission('admin', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

if (!checkSessionTimeout()) {
    // Use the correct relative path for redirectToLogin
    header('Location: ../auth/login.php?timeout=1');
    exit;
}
?>


<!DOCTYPE html>
<html>

<head>
	<title>Admin Dashboard</title>
	<link rel="stylesheet" href="../../assets/css/admin.css">
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="admin-dashboard">
		<h2>Admin Dashboard</h2>

		<!-- Services Management -->
		<div class="admin-section">
			<h3>Services Management</h3>
			<div class="admin-menu">
				<a href="manage_services.php" class="menu-item">Manage Services</a>
				<a href="bulk_upload_services.php" class="menu-item">Bulk Upload Services</a>
				<a href="manage_categories.php" class="menu-item">Service Categories</a>
				<a href="manage_departments.php" class="menu-item">Departments</a>
				<a href="manage_doctors.php" class="menu-item">Add / Manage Doctors</a>
				<a href="manage_schedules.php" class="menu-item">Service Schedules</a>
				<a href="manage_wards.php" class="menu-item">Manage Wards</a>
				
			</div>
		</div>

		<!-- Financial Management -->
		<div class="admin-section">
			<h3>Financial Management</h3>
			<div class="admin-menu">
				<a href="manage_billing.php" class="menu-item">Billing Settings</a>
				<a href="manage_companies.php" class="menu-item">Manage Companies</a>
				<a href="manage_mediclaim.php" class="menu-item">Manage TPA</a>				
				<a href="manage_payment_modes.php" class="menu-item">Payment Modes</a>
				<a href="tax_settings.php" class="menu-item">Tax Settings</a>
			</div>
		</div>

		<!-- User Management -->
		<div class="admin-section">
			<h3>User Management</h3>
			<div class="admin-menu">
				<a href="users.php" class="menu-item">Manage Users</a>
				<a href="roles.php" class="menu-item">User Roles & Permissions</a>
			</div>
		</div>

		<!-- System Settings -->
		<div class="admin-section">
			<h3>System Settings</h3>
			<div class="admin-menu">
				<a href="hospital_settings.php" class="menu-item">Hospital Settings</a>
				<a href="email_settings.php" class="menu-item">Email Configuration</a>
				<a href="sms_settings.php" class="menu-item">SMS Configuration</a>
				<a href="sms_templates.php" class="menu-item">Templates Configuration</a>
				<a href="reports_settings.php" class="menu-item">Reports Settings</a>
				<a href="backup.php" class="menu-item">Database Backup</a>
				<a href="reset-database.php" class="menu-item">Reset Database</a>
			</div>
		</div>

		<!-- Pharmacy Management -->
		<div class="admin-section">
			<h3>Pharmacy Management</h3>
			<div class="admin-menu">
				<a href="manage_suppliers.php" class="menu-item">Manage Suppliers</a>
				<a href="fix_pharmacy_sales_fy.php" class="menu-item">Fix Pharmacy Sales FY</a>
				<a href="../pharmacy/supplier_returns.php" class="menu-item">Supplier Returns</a>
				<a href="../pharmacy/purchase_batch_mismatch_report.php" class="menu-item">Purchase Batch Audit Report</a>
				<a href="../pharmacy/correct_bill_batches.php" class="menu-item">Correct Bill Batches</a>
				<a href="../pharmacy/merge_duplicates.php" class="menu-item">Merge Duplicate Items (Exact Match)</a>
				<a href="../pharmacy/merge_duplicate_items.php" class="menu-item">Merge Duplicate Items (Similarity)</a>
			</div>
		</div>
	</div>
</body>

</html>