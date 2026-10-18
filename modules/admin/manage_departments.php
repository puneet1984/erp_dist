<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $response = ['status' => 'error', 'message' => ''];

    try {
        // Validate input
        $name = sanitizeInput($_POST['name'] ?? '');

        if (empty($name)) {
            throw new Exception('Department name is required');
        }

        if (strlen($name) > 50) {
            throw new Exception('Department name must be less than 50 characters');
        }

        if ($_POST['action'] === 'add') {
            // Check for duplicate
            $check = $conn->prepare("SELECT COUNT(*) FROM departments WHERE name = ?");
            $check->bind_param("s", $name);
            $check->execute();
            if ($check->get_result()->fetch_row()[0] > 0) {
                throw new Exception('Department already exists');
            }

            $stmt = $conn->prepare("INSERT INTO departments (name) VALUES (?)");
            $stmt->bind_param("s", $name);
        } elseif ($_POST['action'] === 'update') {
            // Check for duplicate excluding current ID
            $check = $conn->prepare("SELECT COUNT(*) FROM departments WHERE name = ? AND dept_id != ?");
            $check->bind_param("si", $name, $_POST['dept_id']);
            $check->execute();
            if ($check->get_result()->fetch_row()[0] > 0) {
                throw new Exception('Department name already exists');
            }

            $stmt = $conn->prepare("UPDATE departments SET name = ? WHERE dept_id = ?");
            $stmt->bind_param("si", $name, $_POST['dept_id']);
        } elseif ($_POST['action'] === 'delete') {
            // Check if department has doctors
            $check = $conn->prepare("SELECT COUNT(*) FROM doctors WHERE dept_id = ?");
            $check->bind_param("i", $_POST['dept_id']);
            $check->execute();
            if ($check->get_result()->fetch_row()[0] > 0) {
                throw new Exception('Cannot delete: Department has associated doctors');
            }

            $stmt = $conn->prepare("DELETE FROM departments WHERE dept_id = ?");
            $stmt->bind_param("i", $_POST['dept_id']);
        }

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $response['status'] = 'success';
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get list of departments
$conn = getDbConnection();
$departments = $conn->query(
    "
    SELECT d.*, COUNT(doc.doctor_id) as doctor_count 
    FROM departments d 
    LEFT JOIN doctors doc ON d.dept_id = doc.dept_id 
    GROUP BY d.dept_id 
    ORDER BY d.dept_id ASC"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>

<head>
	<title>Departments Management</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/admin.css">
	<style>
		.dept-stats {
			font-size: 0.9em;
			color: #666;
		}

		.charge {
			font-family: monospace;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Departments Management</h2>

		<div class="settings-section">
			<form id="deptForm">
				<input type="hidden" name="action" value="add">
				<input type="hidden" name="dept_id" value="">

				<div class="form-row">
					<div class="form-group">
						<label>Department Name:</label>
						<input type="text" name="name" required maxlength="50">
					</div>
				</div>

				<div class="form-actions">
					<button type="submit" id="submitBtn">Add Department</button>
					<button type="button" onclick="resetForm()" class="hidden" id="cancelBtn">Cancel</button>
				</div>
			</form>

			<div class="departments-list">
				<h3>Available Departments</h3>
				<table class="data-table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Department Name</th>
							<th>Doctors</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($departments as $dept): ?>
						<tr>
							<td><?= $dept['dept_id'] ?>
							</td>
							<td><?= htmlspecialchars($dept['name']) ?>
							</td>
							<td class="dept-stats">
								<?= $dept['doctor_count'] ?>
								doctors</td>
							<td>
								<button
									onclick='editDept(<?= json_encode($dept) ?>)'>Edit</button>
								<button
									onclick="deleteDept(<?= $dept['dept_id'] ?>)"
									<?= $dept['doctor_count'] > 0 ? 'disabled' : '' ?>>Delete</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
		function editDept(dept) {
			const form = document.getElementById('deptForm');
			form.action.value = 'update';
			form.dept_id.value = dept.dept_id;
			form.name.value = dept.name;

			document.getElementById('submitBtn').textContent = 'Update Department';
			document.getElementById('cancelBtn').classList.remove('hidden');
		}

		function resetForm() {
			const form = document.getElementById('deptForm');
			form.reset();
			form.action.value = 'add';
			form.dept_id.value = '';
			document.getElementById('submitBtn').textContent = 'Add Department';
			document.getElementById('cancelBtn').classList.add('hidden');
		}

		function deleteDept(id) {
			if (!confirm('Are you sure you want to delete this department?')) return;

			fetch(window.location.href, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded'
					},
					body: `action=delete&dept_id=${id}`
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

		document.getElementById('deptForm').onsubmit = function(e) {
			e.preventDefault();
			fetch(window.location.href, {
					method: 'POST',
					body: new FormData(this)
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