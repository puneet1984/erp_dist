<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check admin permissions
if (!checkPermission('admin', 'edit')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();

    if ($_POST['action'] === 'add') {
        $stmt = $conn->prepare("INSERT INTO insurance_providers 
            (name, contact_person, phone, email) VALUES (?, ?, ?, ?)");
        $stmt->bind_param(
            "ssss",
            $_POST['name'],
            $_POST['contact_person'],
            $_POST['phone'],
            $_POST['email']
        );
    } elseif ($_POST['action'] === 'update') {
        $stmt = $conn->prepare("UPDATE insurance_providers 
            SET name=?, contact_person=?, phone=?, email=? WHERE provider_id=?");
        $stmt->bind_param(
            "ssssi",
            $_POST['name'],
            $_POST['contact_person'],
            $_POST['phone'],
            $_POST['email'],
            $_POST['provider_id']
        );
    } elseif ($_POST['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM insurance_providers WHERE provider_id=?");
        $stmt->bind_param("i", $_POST['provider_id']);
    }

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit;
}

$conn = getDbConnection();
$providers = $conn->query("SELECT * FROM insurance_providers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Manage Insurance Providers - Admin</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.provider-list {
			margin-top: 20px;
		}

		.action-buttons {
			display: flex;
			gap: 10px;
		}

		.form-section {
			margin-bottom: 30px;
			background: #f9f9f9;
			padding: 20px;
			border-radius: 8px;
		}

		.hidden {
			display: none;
		}

		.form-row {
			display: flex;
			gap: 20px;
			margin-bottom: 15px;
		}

		.form-group {
			flex: 1;
		}
	</style>
</head>

<body>
	<div class="container">
		<h2>Manage Insurance Providers</h2>

		<!-- Add Provider Form -->
		<div class="form-section">
			<h3 id="formTitle">Add New Insurance Provider</h3>
			<form id="providerForm">
				<input type="hidden" name="action" value="add">
				<input type="hidden" name="provider_id">

				<div class="form-row">
					<div class="form-group">
						<label>Provider Name:</label>
						<input type="text" name="name" required maxlength="100">
					</div>
					<div class="form-group">
						<label>Contact Person:</label>
						<input type="text" name="contact_person" required maxlength="100">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>Phone:</label>
						<input type="tel" name="phone" pattern="[0-9]{10,15}" title="Enter valid phone number" required>
					</div>
					<div class="form-group">
						<label>Email:</label>
						<input type="email" name="email" required>
					</div>
				</div>

				<div class="form-row">
					<button type="submit" id="submitBtn">Add Provider</button>
					<button type="button" onclick="resetForm()" class="hidden" id="cancelBtn">Cancel</button>
				</div>
			</form>
		</div>

		<!-- Providers List -->
		<div class="provider-list">
			<table class="data-table">
				<thead>
					<tr>
						<th>Provider Name</th>
						<th>Contact Person</th>
						<th>Phone</th>
						<th>Email</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($providers as $provider): ?>
					<tr>
						<td><?= htmlspecialchars($provider['name']) ?>
						</td>
						<td><?= htmlspecialchars($provider['contact_person']) ?>
						</td>
						<td><?= htmlspecialchars($provider['phone']) ?>
						</td>
						<td><?= htmlspecialchars($provider['email']) ?>
						</td>
						<td class="action-buttons">
							<button
								onclick='editProvider(<?= json_encode($provider) ?>)'>Edit</button>
							<button
								onclick="deleteProvider(<?= $provider['provider_id'] ?>)">Delete</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		function editProvider(provider) {
			const form = document.getElementById('providerForm');
			form.action.value = 'update';
			form.provider_id.value = provider.provider_id;
			form.name.value = provider.name;
			form.contact_person.value = provider.contact_person;
			form.phone.value = provider.phone;
			form.email.value = provider.email;

			document.getElementById('formTitle').textContent = 'Edit Insurance Provider';
			document.getElementById('submitBtn').textContent = 'Update Provider';
			document.getElementById('cancelBtn').classList.remove('hidden');
			form.scrollIntoView({
				behavior: 'smooth'
			});
		}

		function resetForm() {
			const form = document.getElementById('providerForm');
			form.reset();
			form.action.value = 'add';
			form.provider_id.value = '';
			document.getElementById('formTitle').textContent = 'Add New Insurance Provider';
			document.getElementById('submitBtn').textContent = 'Add Provider';
			document.getElementById('cancelBtn').classList.add('hidden');
		}

		function deleteProvider(providerId) {
			if (confirm('Are you sure you want to delete this provider?')) {
				const formData = new FormData();
				formData.append('action', 'delete');
				formData.append('provider_id', providerId);

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

		document.getElementById('providerForm').onsubmit = function(e) {
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