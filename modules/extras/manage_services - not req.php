<!-- not required -->
<?php
require_once '../../config/config.php';
require_once '../auth/check_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();

    if ($_POST['action'] === 'add') {
        $stmt = $conn->prepare("INSERT INTO services (name, base_charge, dept_id) VALUES (?, ?, ?)");
        $stmt->bind_param("sdi", $_POST['name'], $_POST['base_charge'], $_POST['dept_id']);
    } else {
        $stmt = $conn->prepare("UPDATE services SET name=?, base_charge=?, dept_id=? WHERE service_id=?");
        $stmt->bind_param("sdii", $_POST['name'], $_POST['base_charge'], $_POST['dept_id'], $_POST['service_id']);
    }

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit;
}

$conn = getDbConnection();
$departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$services = $conn->query("SELECT s.*, d.name as dept_name 
                         FROM services s 
                         JOIN departments d ON s.dept_id = d.dept_id 
                         ORDER BY s.name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Manage Services</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>
	<div class="container">
		<h2>Manage Services</h2>

		<!-- Add/Edit Service Form -->
		<form id="serviceForm">
			<input type="hidden" name="action" value="add">
			<input type="hidden" name="service_id" value="">

			<div class="form-group">
				<label>Service Name:</label>
				<input type="text" name="name" required>
			</div>
			<div class="form-group">
				<label>Department:</label>
				<select name="dept_id" required>
					<?php foreach ($departments as $dept): ?>
					<option
						value="<?= $dept['dept_id'] ?>">
						<?= $dept['name'] ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="form-group">
				<label>Base Charge:</label>
				<input type="number" step="0.01" name="base_charge" required>
			</div>
			<button type="submit">Save Service</button>
		</form>

		<!-- Services List -->
		<div class="services-list">
			<table class="data-table">
				<thead>
					<tr>
						<th>Service Name</th>
						<th>Department</th>
						<th>Base Charge</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($services as $service): ?>
					<tr>
						<td><?= htmlspecialchars($service['name']) ?>
						</td>
						<td><?= htmlspecialchars($service['dept_name']) ?>
						</td>
						<td>₹<?= number_format($service['base_charge'], 2) ?>
						</td>
						<td>
							<button
								onclick="editService(<?= $service['service_id'] ?>)">Edit</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</body>

</html>