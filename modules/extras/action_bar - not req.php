<?php
if (!defined('SECURE_ACCESS')) {
    die('Direct access not permitted');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /erp/modules/auth/login.php');
    exit;
}

// Define all available modules and their actions
$all_modules = [
    'dashboard' => [
        'name' => 'Dashboard',
        'icon' => 'tachometer-alt',
        'actions' => [
            ['name' => 'Overview', 'url' => '../dashboard/index.php', 'permission' => 'view']
        ]
    ],
    'patients' => [
        'name' => 'Patients',
        'icon' => 'user',
        'actions' => [
            ['name' => 'Add New Patient', 'url' => '../patients/add_patient.php', 'permission' => 'create'],
            ['name' => 'Search Patient', 'url' => '../patients/view_patient.php', 'permission' => 'view'],
            ['name' => 'View All', 'url' => '../patients/index.php', 'permission' => 'view']
        ]
    ],
    'bills' => [
        'name' => 'Billing',
        'icon' => 'money-bill-alt',
        'actions' => [
            ['name' => 'Generate Bill', 'url' => '../billing/generate_bill.php', 'permission' => 'create'],
            ['name' => 'Search Bills', 'url' => '../billing/bills_list.php', 'permission' => 'view'],
            ['name' => 'View All', 'url' => '../billing/index.php', 'permission' => 'view']
        ]
    ],
    'pharmacy' => [
        'name' => 'Pharmacy',
        'icon' => 'pills',
        'actions' => [
            ['name' => 'New Sale', 'url' => '../pharmacy/generate_bill.php', 'permission' => 'create'],
            ['name' => 'Inventory', 'url' => '../pharmacy/inventory.php', 'permission' => 'view'],
            ['name' => 'View All', 'url' => '../pharmacy/index.php', 'permission' => 'view']
        ]
    ],
    'reports' => [
        'name' => 'Reports',
        'icon' => 'chart-bar',
        'actions' => [
            ['name' => 'Daily Report', 'url' => '../reports/daily.php', 'permission' => 'view'],
            ['name' => 'Monthly Report', 'url' => '../reports/monthly.php', 'permission' => 'view'],
            ['name' => 'Custom Report', 'url' => '../reports/custom.php', 'permission' => 'view']
        ]
    ],
    'admin' => [
        'name' => 'Administration',
        'icon' => 'cog',
        'actions' => [
            ['name' => 'Admin Dashboard', 'url' => '../admin/index.php', 'permission' => 'view'],
        ]
    ]
];

// Get current module from URL
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$current_module = '';
foreach ($all_modules as $module => $data) {
    if (strpos($current_path, "/modules/$module/") !== false) {
        $current_module = $module;
        break;
    }
}

// Filter modules based on permissions
$visible_modules = array_filter($all_modules, function ($module_key) {
    if ($module_key === 'dashboard') {
        return true;
    } // Always show dashboard
    return checkPermission($module_key, 'view');
}, ARRAY_FILTER_USE_KEY);

// Output the action bar HTML and CSS
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
	.action-bar {
		background: #f8f9fa;
		padding: 10px 20px;
		margin-bottom: 20px;
		border-radius: 4px;
		display: flex;
		gap: 15px;
		flex-wrap: wrap;
		position: relative;
		z-index: 100;
	}

	.action-item {
		position: relative;
		display: inline-block;
	}

	.module-icon {
		padding: 8px 15px;
		cursor: pointer;
		border-radius: 4px;
		display: flex;
		align-items: center;
		gap: 8px;
		background:
			<?= $current_module ? '#e9ecef' : 'transparent' ?>
		;
		text-decoration: none;
		color: #333;
	}

	.module-icon:hover {
		background: #e9ecef;
	}

	.module-icon i {
		width: 16px;
		text-align: center;
	}

	.dropdown-content {
		display: none;
		position: absolute;
		left: 0;
		top: 100%;
		background: #fff;
		min-width: 200px;
		box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
		border-radius: 4px;
		z-index: 1000;
	}

	.action-item:hover .dropdown-content {
		display: block;
	}

	.dropdown-content a {
		padding: 10px 15px;
		display: block;
		color: #333;
		text-decoration: none;
		transition: background-color 0.2s;
		white-space: nowrap;
	}

	.dropdown-content a:hover {
		background-color: #f8f9fa;
	}

	.action-link {
		text-decoration: none;
		color: #333;
	}

	.action-link:hover {
		color: #0056b3;
	}
</style>

<div class="action-bar">
	<?php foreach ($visible_modules as $module_key => $module): ?>
	<?php if ($module_key === 'admin' && !isAdmin()) {
	    continue;
	} ?>
	<div class="action-item">
		<?php if ($module_key === 'admin'): ?>
		<a href="../admin/index.php" class="module-icon">
			<i
				class="fas fa-<?= $module['icon'] ?>"></i>
			<span><?= $module['name'] ?></span>
		</a>
		<?php else: ?>
		<div class="module-icon">
			<i
				class="fas fa-<?= $module['icon'] ?>"></i>
			<span><?= $module['name'] ?></span>
		</div>
		<div class="dropdown-content">
			<?php
	        $visible_actions = array_filter($module['actions'], function ($action) use ($module_key) {
	            return checkPermission($module_key, $action['permission']);
	        });
		    if (empty($visible_actions)) {
		        continue;
		    }
		    foreach ($visible_actions as $action): ?>
			<a
				href="<?= $action['url'] ?>"><?= $action['name'] ?></a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
	<?php if (checkPermission('admin', 'view')): ?>
	<div class="action-item">
		<a href="../admin/index.php" class="action-link">
			<i class="fas fa-cog"></i>
			<span>Admin</span>
		</a>
	</div>
	<?php endif; ?>
</div>