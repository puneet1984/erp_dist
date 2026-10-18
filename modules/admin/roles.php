<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Handle AJAX requests
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $response = ['status' => 'error', 'message' => ''];
    $conn = getDbConnection();

    if ('add' === $_POST['action'] || 'update' === $_POST['action']) {
        // Validate POST data
        if (empty($_POST['role_name'])) {
            $response['message'] = 'Role name is required';
            echo json_encode($response);
            exit;
        }

        // Initialize permissions array
        $permissionsArray = [];

        // Check if permissions were submitted
        if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
            foreach ($_POST['permissions'] as $module => $actions) {
                if (!empty($actions)) {
                    $permissionsArray[$module] = $actions;
                }
            }
        }

        // Ensure at least one permission is set
        if (empty($permissionsArray)) {
            $permissionsArray['dashboard'] = ['view']; // Set minimum required permission
        }

        $permissions = json_encode($permissionsArray);
        $role_name = capitalizeWords(sanitizeInput($_POST['role_name']));

        if ('add' === $_POST['action']) {
            $stmt = $conn->prepare('INSERT INTO roles (role_name, permissions) VALUES (?, ?)');
            $stmt->bind_param('ss', $role_name, $permissions);
        } else {
            $stmt = $conn->prepare('UPDATE roles SET role_name=?, permissions=? WHERE role_id=?');
            $stmt->bind_param('ssi', $role_name, $permissions, $_POST['role_id']);
        }
        $response['status'] = $stmt->execute() ? 'success' : 'error';
        $response['message'] = $stmt->error;
    } elseif ('delete' === $_POST['action'] && isset($_POST['role_id'])) {
        // Don't allow deletion of core roles
        $stmt = $conn->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $stmt->bind_param('i', $_POST['role_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $role = $result->fetch_assoc();

        if (in_array($role['role_name'], ['admin', 'doctor', 'receptionist'])) {
            $response['message'] = 'Cannot delete core system roles';
        } else {
            $stmt = $conn->prepare('DELETE FROM roles WHERE role_id = ?');
            $stmt->bind_param('i', $_POST['role_id']);
            $response['status'] = $stmt->execute() ? 'success' : 'error';
            $response['message'] = $stmt->error;
        }
    }

    echo json_encode($response);
    exit;
}

$conn = getDbConnection();
$roles = $conn->query('SELECT * FROM roles ORDER BY role_name')->fetch_all(MYSQLI_ASSOC);

// Define available modules and their actions
$modules = [
    'dashboard' => ['view'],
    'bills' => ['create', 'view', 'edit', 'delete', 'print'],
    'patients' => ['create', 'view', 'edit', 'delete', 'print'],
    'doctors' => ['create', 'view', 'edit', 'delete'],
    'reports' => ['view', 'generate', 'print'],
    'settings' => ['view', 'edit'],
    'ipd' => ['admit', 'view', 'edit', 'discharge', 'transfer', 'print'],
    'pharmacy' => ['view', 'sell', 'return', 'adjust', 'print'],
    'inventory' => ['view', 'add', 'edit', 'delete'],
    'purchases' => ['create', 'view', 'edit', 'delete', 'print'],
	'supplier_returns' => ['view', 'create', 'delete'],
	'bill_batch_corrections' => ['view', 'edit'],
	'tokens' => ['create', 'view', 'print', 'resend_whatsapp', 'delete'],
];
?>
<!DOCTYPE html>
<html>

<head>
	<title>User Roles Management</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/admin.css">
	<style>
		.container {
			max-width: 1200px;
			margin: 0 auto;
			padding: 20px;
		}

		.permissions-grid {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 15px;
			margin: 20px 0;
		}

		.module-permissions {
			background: #f5f5f5;
			padding: 15px;
			border-radius: 5px;
			border: 1px solid #ddd;
		}

		.module-permissions h4 {
			margin: 0 0 10px 0;
			padding-bottom: 5px;
			border-bottom: 1px solid #ddd;
			color: #333;
		}

		.permissions-list {
			display: grid;
			grid-template-columns: repeat(2, 1fr);
			gap: 8px;
		}

		.module-permissions label {
			display: flex;
			align-items: center;
			margin: 0;
			font-size: 0.9em;
		}

		.module-permissions input[type="checkbox"] {
			margin-right: 5px;
		}

		.roles-section {
			max-width: 1200px;
			border: 1px solid #ddd;
			border-radius: 5px;
			margin: 10px auto;
			padding: 20px;
			background: white;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}

		.permission-tag {
			display: inline-block;
			background: #e9ecef;
			padding: 2px 6px;
			border-radius: 3px;
			margin: 2px;
			font-size: 0.85em;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>User Roles Management</h2>

		<div class="roles-section">
			<form id="roleForm">
				<input type="hidden" name="action" value="add">
				<input type="hidden" name="role_id" value="">

				<div class="form-row">
					<div class="form-group"></div>
					<label>Role Name:</label>
					<input type="text" name="role_name" required>
				</div>

				<h3>Permissions</h3>
				<div class="permissions-grid">
					<?php
            // Group modules by category
            $moduleGroups = [
                'Core' => ['dashboard', 'settings'],
				'Patient Care' => ['patients', 'appointments', 'ipd', 'doctors', 'tokens'],
                'Finance' => ['bills', 'purchases', 'reports'],
				'Inventory' => ['pharmacy', 'inventory', 'supplier_returns', 'bill_batch_corrections'],
            ];

foreach ($moduleGroups as $group => $groupModules) {
    foreach ($groupModules as $module) {
        if (isset($modules[$module])) {
            ?>
					<div class="module-permissions">
						<h4><?php echo ucwords(str_replace('_', ' ', $module)); ?></h4>
						<div class="permissions-list">
							<?php foreach ($modules[$module] as $action) { ?>
							<label>
								<input type="checkbox"
									name="permissions[<?php echo $module; ?>][]"
									value="<?php echo $action; ?>">
								<?php echo ucwords(str_replace('_', ' ', $action)); ?>
							</label>
							<?php } ?>
						</div>
					</div>
					<?php
        }
    }
}
?>
				</div>

				<div class="form-actions">
					<button type="submit" id="submitBtn">Add Role</button>
					<button type="button" onclick="resetForm()" class="hidden" id="cancelBtn">Cancel</button>
				</div>
			</form>
		</div>

		<div class="roles-section">
			<div class="roles-list">
				<h3>Existing Roles</h3>
				<table class="data-table">
					<thead>
						<tr>
							<th>Role Name</th>
							<th>Permissions</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($roles as $role) { ?>
						<tr>
							<td><?php echo htmlspecialchars($role['role_name']); ?>
							</td>
							<td>
								<?php
                                $perms = json_decode($role['permissions'], true);
						    foreach ($perms as $module => $actions) {
						        $moduleLabel = ucwords(str_replace('_', ' ', $module));
						        $actionLabels = array_map(function ($action) {
						            return ucwords(str_replace('_', ' ', $action));
						        }, $actions);
						        echo '<strong>'.$moduleLabel.':</strong> '.
						             implode(', ', $actionLabels).'<br>';
						    }
						    ?>
							</td>
							<td>
								<?php
						    $roleForJs = [
						        'role_id' => $role['role_id'],
						        'role_name' => $role['role_name'],
						        'permissions' => $perms,
						    ];
						    ?>
								<button type="button"
									onclick='editRole(<?php echo json_encode($roleForJs); ?>)'>Edit</button>
								<?php if (!in_array($role['role_name'], ['admin', 'doctor', 'receptionist'])) { ?>
								<button
									onclick="deleteRole(<?php echo $role['role_id']; ?>)">Delete</button>
								<?php } ?>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
		function editRole(role) {
			// console.log('Role data received:', role); // Debug log

			const form = document.getElementById('roleForm');
			form.action.value = 'update';
			form.role_id.value = role.role_id;
			form.role_name.value = role.role_name;

			// Reset checkboxes
			form.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);

			// Set permissions
			if (role.permissions) {
				// console.log('Setting permissions:', role.permissions); // Debug log
				Object.entries(role.permissions).forEach(([module, actions]) => {
					actions.forEach(action => {
						const selector = `input[name="permissions[${module}][]"][value="${action}"]`;
						const checkbox = form.querySelector(selector);
						// console.log(`Looking for ${selector}:`, checkbox); // Debug log
						if (checkbox) {
							checkbox.checked = true;
						}
					});
				});
			}

			document.getElementById('submitBtn').textContent = 'Update Role';
			document.getElementById('cancelBtn').classList.remove('hidden');
		}

		function deleteRole(roleId) {
			if (confirm('Are you sure you want to delete this role?')) {
				const formData = new FormData();
				formData.append('action', 'delete');
				formData.append('role_id', roleId);

				fetch(window.location.href, {
						method: 'POST',
						body: formData
					})
					.then(response => response.json())
					.then(data => {
						if (data.status === 'success') {
							window.location.reload();
						} else {
							alert('Error: ' + data.message);
						}
					});
			}
		}

		function resetForm() {
			const form = document.getElementById('roleForm');
			form.reset();
			form.action.value = 'add';
			form.role_id.value = '';
			document.getElementById('submitBtn').textContent = 'Add Role';
			document.getElementById('cancelBtn').classList.add('hidden');
		}

		document.getElementById('roleForm').onsubmit = function(e) {
			e.preventDefault();
			const formData = new FormData(this);

			fetch(window.location.href, {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						window.location.reload();
					} else {
						alert('Error: ' + data.message);
					}
				});
		};
	</script>
</body>

</html>