<?php
if (!defined('SECURE_ACCESS')) {
    exit('Direct access not permitted');
}

error_log('Current session data: '.print_r($_SESSION, true));

$isAdmin = isAdmin();
$hasAdminView = checkPermission('admin', 'view');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="../../assets/css/action-bar.css">
<script src="../../assets/js/currenttime.js"></script>
<div class="header">
	<h1>Hospital ERP</h1>
	<div>
		<div id="current-time-container">
			<span id="current-date-time"></span>
		</div>
		Welcome,
		<?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
		|
		<a href="../auth/logout.php">Logout</a>
	</div>
</div>

<div class="action-bar">
	<!-- Quick Actions -->
	<div id="action-bar-links">
		<i class="fas fa-home"></i>
		<a href="../dashboard/index.php">Dashboard</a>
	</div>

	<?php if (checkPermission('patients', 'create')) { ?>
	<div id="action-bar-links">
		<i class="fas fa-user-plus"></i>
		<a href="../patients/add_patient.php">New Patient</a>
	</div>
	<?php } ?>

	<?php if (checkPermission('bills', 'create')) { ?>
	<div id="action-bar-links">
		<i class="fas fa-file-invoice"></i>
		<a href="../billing/generate_bill.php">New Bill</a>
	</div>
	<?php } ?>

	<?php if (checkPermission('pharmacy', 'sell')) { ?>
	<div id="action-bar-links">
		<i class="fas fa-pills"></i>
		<a href="../pharmacy/sale.php">New Sale</a>
	</div>
	<?php } ?>

	<?php if (checkPermission('tokens', 'view') || checkPermission('tokens', 'create')) { ?>
	<div id="action-bar-links">
		<i class="fas fa-ticket-alt"></i>
		<a href="../tokens/index.php">Tokens</a>
	</div>
	<?php } ?>

	<?php if (checkPermission('purchases', 'create')) { ?>
	<div id="action-bar-links">
		<i class="fas fa-pills"></i>
		<a href="../pharmacy/purchase.php">New Purchase</a>
	</div>
	<?php } ?>

	

	<?php if (checkPermission('bills', 'create')) { ?>
	<div id="action-bar-links">
		<i class="fas fa-file-invoice"></i>
		<a href="../insurance/process_claim.php">New Claim</a>
	</div>
	<?php } ?>
	<?php if (isDoctor() || isAdmin()) { ?>
	<div id="action-bar-links">
		<i class="fas fa-certificate"></i>
		<a href="../certificate/index.php">Certificates</a>
	</div>
	<?php } ?>
	<?php if (isAdmin()) { ?>
	<div id="action-bar-links">
		<i class="fas fa-cog"></i>
		<a href="../admin/index.php">Admin</a>
	</div>
	<?php } ?>

	<!-- Dropdown Menu -->
	<?php if (checkPermission('bills', 'create')) { ?>
	<div class="dropdown">
		<button class="dropbtn"><i class="fas fa-procedures"></i> Forms </button>
		<div class="dropdown-content">
			<!-- <a href="../forms/admission_form.php"><i class="fas fa-search"></i> Admit</a> -->
			<a href="../forms/investigation_req.php"><i class="fas fa-users"></i> Investigation</a>
			<a href="../forms/document_handover_form.php"><i class="fas fa-file-signature"></i> Document Handover</a>
			<a href="../forms/etiology_form.php"><i class="fas fa-notes-medical"></i> Etiology Form</a>
			<a href="../forms/high_risk_consent_form.php"><i class="fas fa-exclamation-triangle"></i> High Risk Consent</a>
			<a href="../forms/network_declaration_form.php"><i class="fas fa-hospital"></i> Network Declaration</a>
			<a href="../forms/xray_report_form.php"><i class="fas fa-x-ray"></i> X-Ray Report</a>
		</div>
	</div>
	<?php } ?>

	<!-- Dropdown Menu -->
	<?php if (checkPermission('ipd', 'view')) { ?>
	<div class="dropdown">
		<button class="dropbtn"><i class="fas fa-procedures"></i> IPD </button>
		<div class="dropdown-content">
			<a href="../ipd/admit.php"><i class="fas fa-search"></i> Admit</a>
			<a href="../ipd/discharge.php"><i class="fas fa-users"></i> Discharge</a>
			<a href="../ipd/discharge_summary.php"><i class="fas fa-users"></i> Discharge Summary</a>
			<a href="../ipd/ipd_list.php"><i class="fas fa-users"></i> Admitted Patients</a>
			<a href="../ipd/ipd_bill.php"><i class="fas fa-file-invoice"></i> IPD Bill</a>
			<a href="../ipd/advance_payment.php"><i class="fas fa-file-invoice"></i> Advance Payment</a>
		</div>
	</div>
	<?php } ?>

	<!-- Inventory Dropdown Menu -->
	<?php if (checkPermission('pharmacy', 'view') || checkPermission('pharmacy', 'edit') || checkPermission('inventory', 'create') || canListBills()) { ?>
	<div class="dropdown">
		<button class="dropbtn"><i class="fas fa-boxes"></i> Inventory</button>
		<div class="dropdown-content">
			<?php if (checkPermission('pharmacy', 'view')) { ?>
			<a href="../pharmacy/inventory.php"><i class="fas fa-warehouse"></i> Inventory</a>
			<?php } ?>
			<?php if (checkPermission('inventory', 'create')) { ?>
			<a href="../pharmacy/manage_items.php"><i class="fas fa-tasks"></i> Manage Stock</a>
			<?php } ?>
			<?php if (checkPermission('pharmacy', 'edit')) { ?>
			<a href="../pharmacy/stock_reconciliation.php"><i class="fas fa-clipboard-check"></i> Stock Reconciliation</a>
			<?php } ?>
			<?php if (checkPermission('pharmacy', 'view')) { ?>
			<a href="../pharmacy/manage_purchases.php"><i class="fas fa-shopping-cart"></i> View Purchases</a>
			<?php } ?>
			<?php if (checkPermission('inventory', 'create')) { ?>
			<a href="../pharmacy/add_items.php"><i class="fas fa-plus-circle"></i> Add Items</a>
			<?php } ?>
			<?php if (canListBills()) { ?>
			<a href="../pharmacy/list_pharmacy_bills.php"><i class="fas fa-file-invoice"></i> List Pharmacy Bills</a>
			<?php } ?>
		</div>
	</div>
	<?php } ?>


	<!-- Dropdown Menu -->
	<div class="dropdown">
		<button class="dropbtn"><i class="fas fa-ellipsis-h"></i> More </button>
		<div class="dropdown-content">
			<?php if (checkPermission('patients', 'view')) { ?>
			<a href="../patients/view_patient.php"><i class="fas fa-search"></i> Search Patient</a>
			<!-- <a href="../patients/index.php"><i class="fas fa-users"></i> All Patients</a> -->
			<?php } ?>

			<?php if (checkPermission('pharmacy', 'view')) { ?>
			<!-- <div class="dropdown-divider"></div> -->
			<!-- <a href="../pharmacy/inventory.php"><i class="fas fa-boxes"></i> Inventory</a> -->
			<?php } ?>

			<?php if (checkPermission('reports', 'view')) { ?>
			<div class="dropdown-divider"></div>
			<a href="../reports/financial.php"><i class="fas fa-calendar-day"></i> Reports</a>
			<!-- <a href="../reports/monthly.php"><i class="fas fa-calendar-alt"></i> Monthly Report</a> -->
			<?php } ?>

			<?php if (canListBills()) { ?>
			<div class="dropdown-divider"></div>
			<a href="/erp/modules/billing/bills_list.php"><i class="fas fa-file-invoice"></i> Bills List</a>
			<a href="/erp/modules/billing/company_bills_receipts.php"><i class="fas fa-building"></i> Company Bills &amp; Receipts</a>
			<a href="/erp/modules/billing/company_advances.php"><i class="fas fa-wallet"></i> Company Advances</a>
			<?php } ?>

			<!-- <?php if (checkPermission('patients', 'view')) { ?>
			<div class="dropdown-divider"></div>
			<a href="/erp/modules/certificate/investigation_req.php"><i class="fas fa-microscope"></i> Investigation Request</a>
			<?php } ?> -->

			<!-- Admin Section - Modified checks -->
			<?php if (isAdmin()) { ?>
			<!-- <div class="dropdown-divider"></div> -->
			<!-- <a href="../admin/users.php"><i class="fas fa-users-cog"></i> Manage Users</a> -->
			<!-- <a href="../admin/doctors.php"><i class="fas fa-user-md"></i> Manage Doctors</a> -->
			<!-- <a href="../admin/settings.php"><i class="fas fa-sliders-h"></i> Settings</a> -->
			<?php } ?>

			<div class="dropdown-divider" ></div>
			<a href="../auth/logout.php"><i class="fas fa-sign-out-alt" ></i> Logout</a>
		</div>
	</div>
</div>