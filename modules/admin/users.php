<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Only admin can manage users
if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        switch ($_POST['action']) {
            case 'add':
                // Validate required fields
                $required_fields = ['username', 'password', 'email', 'role_id'];
                foreach ($required_fields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Field $field is required");
                    }
                }
                
                // Sanitize inputs
                $username = sanitizeInput($_POST['username']);
                $password = trim($_POST['password']); // Password should only be trimmed
                $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
                $role_id = (int)$_POST['role_id'];

                // Validate email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email format");
                }

                // Check if username or email already exists
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
                $stmt->bind_param('ss', $username, $email);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    throw new Exception("Username or email already exists");
                }

                // Validate password strength
                if (strlen($password) < 8 || 
                    !preg_match('/[0-9]/', $password) || 
                    !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
                    throw new Exception("Password does not meet requirements");
                }

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, email, role_id, status) VALUES (?, ?, ?, ?, 'ACTIVE')");
                $stmt->bind_param('sssi', $username, $password_hash, $email, $role_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error creating user: " . $stmt->error);
                }
                
				header('Location: users.php?success=1');
				exit;

			case 'edit':
				$user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
				if ($user_id <= 0) {
					throw new Exception('Invalid user selected for edit');
				}

				$required_fields = ['username', 'email', 'role_id'];
				foreach ($required_fields as $field) {
					if (empty($_POST[$field])) {
						throw new Exception("Field $field is required");
					}
				}

				$username = sanitizeInput($_POST['username']);
				$email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
				$role_id = (int) $_POST['role_id'];
				$new_password = trim((string) ($_POST['password'] ?? ''));

				if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
					throw new Exception('Invalid email format');
				}

				$stmt = $conn->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
				$stmt->bind_param('ssi', $username, $email, $user_id);
				$stmt->execute();
				if ($stmt->get_result()->num_rows > 0) {
					throw new Exception('Username or email already exists');
				}

				if ('' !== $new_password) {
					if (strlen($new_password) < 8 ||
						!preg_match('/[0-9]/', $new_password) ||
						!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
						throw new Exception('Password does not meet requirements');
					}

					$password_hash = password_hash($new_password, PASSWORD_DEFAULT);
					$stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, role_id = ?, password_hash = ? WHERE user_id = ?');
					$stmt->bind_param('ssisi', $username, $email, $role_id, $password_hash, $user_id);
				} else {
					$stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, role_id = ? WHERE user_id = ?');
					$stmt->bind_param('ssii', $username, $email, $role_id, $user_id);
				}

				if (!$stmt->execute()) {
					throw new Exception('Error updating user: ' . $stmt->error);
				}

				header('Location: users.php?updated=1');
				exit;

            case 'toggle_status':
                // Check if trying to deactivate admin user
                $stmt = $conn->prepare('SELECT u.user_id, u.username, r.role_name 
                                      FROM users u 
                                      JOIN roles r ON u.role_id = r.role_id 
                                      WHERE u.user_id = ?');
                $stmt->bind_param('i', $_POST['user_id']);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();

                if ('admin' === $user['role_name']) {
                    throw new Exception('Admin user cannot be deactivated');
                }

                $stmt = $conn->prepare("UPDATE users SET status = IF(status = 'ACTIVE', 'INACTIVE', 'ACTIVE') 
                                      WHERE user_id = ?");
                $stmt->bind_param('i', $_POST['user_id']);
                $stmt->execute();
                break;

            case 'reset_password':
                $stmt = $conn->prepare('SELECT username FROM users WHERE user_id = ?');
                $stmt->bind_param('i', $_POST['user_id']);
                $stmt->execute();
                $target = $stmt->get_result()->fetch_assoc();
                if (!$target) {
                    throw new Exception('User not found');
                }

                $password_hash = password_hash($target['username'].'9#', PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
                $stmt->bind_param('si', $password_hash, $_POST['user_id']);
                if (!$stmt->execute()) {
                    throw new Exception('Error resetting password: '.$stmt->error);
                }
                $success = 'Password reset to default for '.$target['username'];
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$roles = $conn->query('SELECT * FROM roles')->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query('SELECT * FROM departments')->fetch_all(MYSQLI_ASSOC);
// Modified query to get all active doctors and their user status
$existing_doctors = $conn->query('
    SELECT d.doctor_id, d.name, d.email, 
           CASE WHEN u.user_id IS NOT NULL THEN 1 ELSE 0 END as has_user
    FROM doctors d 
    LEFT JOIN users u ON d.email = u.email
    WHERE d.status = "ACTIVE"
    ORDER BY d.name
')->fetch_all(MYSQLI_ASSOC);
$users = $conn->query('
    SELECT u.*, r.role_name, d.name as doctor_name, d.specialization
    FROM users u 
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN doctors d ON u.email = d.email
    ORDER BY u.created_at DESC
')->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>User Management</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.container {
			width: 1200px;
			margin: 0 auto;
		}
.form-group{
	
}
		.password-requirements {
			font-size: 0.85em;
			color: #666;
			margin-top: 5px;
		}

		.password-requirements ul {
			margin: 5px 0;
			padding-left: 20px;
		}

		.invalid {
			color: #dc3545;
		}

		.valid {
			color: #198754;
		}

		.password-field {
			position: relative;
			display: inline-block;

		}

		.toggle-password {
			position: absolute;
			right: 5px;
			top: 50%;
			transform: translateY(-50%);
			cursor: pointer;
			user-select: none;
			width: 20px;
			height: 20px;
			opacity: 0.6;
		}

		.toggle-password img {
			width: 100%;
			height: 100%;
		}

		.toggle-password img.visible {
			opacity: 1;
		}

		.form-row {
			display: flex;
			gap: 20px;
			margin-bottom: 15px;
			align-items: flex-start;
		}

		.form-group {
			flex: 1;
			min-width: 200px;
		}

		.password-requirements {
			margin-top: 5px;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>User Management</h2>
		<?php if (!empty($success)) { ?>
			<div style="background:#d1e7dd;color:#0f5132;padding:10px;margin-bottom:10px;border-radius:4px;"><?php echo htmlspecialchars($success); ?></div>
		<?php } ?>
		<?php if (!empty($error)) { ?>
			<div style="background:#f8d7da;color:#842029;padding:10px;margin-bottom:10px;border-radius:4px;"><?php echo htmlspecialchars($error); ?></div>
		<?php } ?>

		<!-- Add User Form -->
		<div class="form-section">
			<h3>Add New User</h3>
			<form method="POST" id="addUserForm">
				<input type="hidden" name="action" id="formAction" value="add">
				<input type="hidden" name="user_id" id="edit_user_id" value="">

				<div class="form-row">
					<div class="form-group">
						<label>Username:</label>
						<input type="text" name="username" style="width: 130px;" required>
					</div>

					<div class="form-group">
						<label>Password:</label>
						<div class="password-field">
							<input type="password" name="password" id="password" style="width: 130px; padding: 5px;"
								required
								pattern="(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
								autocomplete="new-password"
								oninput="validatePassword(this)">
							<span class="toggle-password" onclick="togglePasswordVisibility()">
								<img src="https://cdn-icons-png.flaticon.com/128/2767/2767194.png" alt="toggle password">
							</span>
						</div>
						<div class="password-requirements">
							Password requirements:
							<ul>
								<li id="length">At least 8 characters</li>
								<li id="number">At least 1 number</li>
								<li id="special">At least 1 special character</li>
							</ul>
						</div>
					</div>

					<div class="form-group">
						<label>Email:</label>
						<input type="email" name="email" style="width: 200px;" required>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>Role:</label>
						<select name="role_id" required onchange="toggleDoctorSelection(this.value)" style="width: 150px;">
							<?php foreach ($roles as $role) { ?>
								<option value="<?php echo $role['role_id']; ?>">
									<?php echo htmlspecialchars($role['role_name']); ?>
								</option>
							<?php } ?>
						</select>
					</div>

					<div class="form-group" id="doctorSelection" style="display:none;">
						<label>Select Doctor:</label>
						<select name="existing_doctor" onchange="handleDoctorSelection(this.value)" required>
							<option value="">-- Select Doctor --</option>
							<?php foreach ($existing_doctors as $doctor) { ?>
								<option value="<?php echo htmlspecialchars(json_encode($doctor)); ?>" <?php echo $doctor['has_user'] ? 'disabled' : ''; ?>>
									<?php echo htmlspecialchars($doctor['name']); ?>
									<?php echo $doctor['has_user'] ? ' (Already has user account)' : ''; ?>
								</option>
							<?php } ?>
						</select>
					</div>
				</div>

				<button type="submit" id="submitBtn" class="btn-primary">Add User</button>
				<button type="button" id="cancelEditBtn" class="btn-secondary" style="display:none;" onclick="resetUserForm()">Cancel Edit</button>
			</form>
		</div>

		<!-- Users List -->
		<div class="table-section">
			<h3>Current Users</h3>
			<table class="data-table">
				<thead>
					<tr>
						<th>Username</th>
						<th>Email</th>
						<th>Role</th>
						<th>Status</th>
						<th>Created</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($users as $user) { ?>
						<tr>
							<td><?php echo htmlspecialchars($user['username']); ?>
							</td>
							<td><?php echo htmlspecialchars($user['email']); ?>
							</td>
							<td><?php echo htmlspecialchars($user['role_name']); ?>
							</td>
							<td><?php echo htmlspecialchars($user['status']); ?>
							</td>
							<td><?php echo formatDisplayDate($user['created_at']); ?>
							</td>
							<td>
								<button onclick="editUser(<?php echo $user['user_id']; ?>)">Edit</button>
								<button onclick="resetPassword(<?php echo $user['user_id']; ?>)">Reset Password</button>
								<?php if ('admin' !== $user['role_name']) { ?>
									<button onclick="toggleStatus(<?php echo $user['user_id']; ?>)">
										<?php echo 'ACTIVE' === $user['status'] ? 'Deactivate' : 'Activate'; ?>
									</button>
								<?php } ?>
							</td>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		const usersData = <?php echo json_encode(array_map(function ($user) {
			return [
				'user_id' => (int) $user['user_id'],
				'username' => (string) $user['username'],
				'email' => (string) $user['email'],
				'role_id' => (int) $user['role_id'],
			];
		}, $users), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

		function editUser(userId) {
			const user = usersData.find(u => Number(u.user_id) === Number(userId));
			if (!user) {
				alert('Unable to load selected user details');
				return;
			}

			const form = document.getElementById('addUserForm');
			const passwordInput = document.getElementById('password');

			document.getElementById('formAction').value = 'edit';
			document.getElementById('edit_user_id').value = user.user_id;
			form.username.value = user.username;
			form.email.value = user.email;
			form.role_id.value = String(user.role_id);
			passwordInput.value = '';
			passwordInput.required = false;
			passwordInput.placeholder = 'Leave blank to keep existing password';

			document.getElementById('submitBtn').textContent = 'Update User';
			document.getElementById('cancelEditBtn').style.display = 'inline-block';

			const doctorSelection = document.getElementById('doctorSelection');
			const doctorSelect = doctorSelection.querySelector('select');
			doctorSelection.style.display = 'none';
			doctorSelect.required = false;

			window.scrollTo({ top: 0, behavior: 'smooth' });
		}

		function resetUserForm() {
			const form = document.getElementById('addUserForm');
			const passwordInput = document.getElementById('password');

			form.reset();
			document.getElementById('formAction').value = 'add';
			document.getElementById('edit_user_id').value = '';
			passwordInput.required = true;
			passwordInput.placeholder = '';
			document.getElementById('submitBtn').textContent = 'Add User';
			document.getElementById('cancelEditBtn').style.display = 'none';

			const doctorSelection = document.getElementById('doctorSelection');
			const doctorSelect = doctorSelection.querySelector('select');
			doctorSelection.style.display = 'none';
			doctorSelect.required = false;
			clearDoctorFields();
		}

		function togglePasswordVisibility() {
			const passwordInput = document.getElementById('password');
			const img = document.querySelector('.toggle-password img');

			if (passwordInput.type === 'password') {
				passwordInput.type = 'text';
				img.classList.add('visible');
			} else {
				passwordInput.type = 'password';
				img.classList.remove('visible');
			}
		}

		function toggleDoctorSelection(roleId) {
			const doctorSelection = document.getElementById('doctorSelection');
			const doctorSelect = doctorSelection.querySelector('select');

			if (roleId == 2) { // If doctor role
				doctorSelection.style.display = 'block';
				doctorSelect.required = true;
			} else {
				doctorSelection.style.display = 'none';
				doctorSelect.required = false;
				clearDoctorFields();
			}
		}

		function handleDoctorSelection(doctorJson) {
			if (!doctorJson) {
				clearDoctorFields();
				return;
			}

			const doctor = JSON.parse(doctorJson);
			const form = document.getElementById('addUserForm');
			form.email.value = doctor.email;
			form.email.readOnly = true;
		}

		function clearDoctorFields() {
			const form = document.getElementById('addUserForm');
			if (form.email.readOnly) {
				form.email.value = '';
			}
			form.email.readOnly = false;
		}

		// Add this function for password validation
		function validatePassword(input) {
			const password = input.value;
			const requirements = {
				length: password.length >= 8,
				number: /[0-9]/.test(password),
				special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
			};

			// Update requirement list styles
			Object.keys(requirements).forEach(req => {
				const element = document.getElementById(req);
				if (requirements[req]) {
					element.classList.remove('invalid');
					element.classList.add('valid');
				} else {
					element.classList.remove('valid');
					element.classList.add('invalid');
				}
			});

			// Update input validity
			input.setCustomValidity(
				Object.values(requirements).every(Boolean) ?
					"" :
					"Please meet all password requirements"
			);
		}

		function resetPassword(userId) {
			const user = usersData.find(u => Number(u.user_id) === Number(userId));
			if (!user || !confirm('Reset password for ' + user.username + ' to default (<username>9#)?')) return;

			const form = document.createElement('form');
			form.method = 'POST';
			[['action', 'reset_password'], ['user_id', userId]].forEach(([n, v]) => {
				const i = document.createElement('input');
				i.type = 'hidden';
				i.name = n;
				i.value = v;
				form.appendChild(i);
			});
			document.body.appendChild(form);
			form.submit();
		}

		function toggleStatus(userId) {
			if (!confirm('Are you sure you want to change this user\'s status?')) return;

			const formData = new FormData();
			formData.append('action', 'toggle_status');
			formData.append('user_id', userId);

			fetch(window.location.href, {
				method: 'POST',
				body: formData
			})
				.then(response => response.text())
				.then(result => {
					if (result.includes('Admin user cannot be deactivated')) {
						alert('Admin user cannot be deactivated');
					} else {
						window.location.reload();
					}
				});
		}
	</script>
</body>

</html>