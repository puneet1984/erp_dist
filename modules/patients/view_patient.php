<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('patients', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

date_default_timezone_set('Asia/Kolkata');

// Default limit for latest patients
$limit = 100;

$conn = getDbConnection();
$where_conditions = [];
$params = [];
$param_types = '';

// Get total patients count
$total_count = $conn->query('SELECT COUNT(*) as total FROM patients')->fetch_assoc()['total'];

// Handle search parameters
if ('GET' === $_SERVER['REQUEST_METHOD']) {
    if (!empty($_GET['patient_id'])) {
        $search_id = trim($_GET['patient_id']);
        if (is_numeric($search_id) && strlen($search_id) <= 8) {
            $where_conditions[] = 'patient_id = ?';
            $params[] = $search_id;
            $param_types .= 'i';
        }
    }

    if (!empty($_GET['name'])) {
        $name = trim($_GET['name']);
        $where_conditions[] = 'name LIKE ?';
        $params[] = '%'.$name.'%';
        $param_types .= 's';
    }

    if (!empty($_GET['phone'])) {
        $phone = trim($_GET['phone']);
        if (is_numeric($phone) && strlen($phone) <= 10) {
            $where_conditions[] = 'contact = ?';
            $params[] = $phone;
            $param_types .= 's';
        }
    }

    if (!empty($_GET['from_date']) && !empty($_GET['to_date'])) {
        $where_conditions[] = 'DATE(created_at) BETWEEN ? AND ?';
        $params[] = $_GET['from_date'];
        $params[] = $_GET['to_date'];
        $param_types .= 'ss';
    }
}

// Build query
$query = 'SELECT * FROM patients';
if (!empty($where_conditions)) {
    $query .= ' WHERE '.implode(' AND ', $where_conditions);
    $query .= ' ORDER BY patient_id DESC';
} else {
    $query .= ' ORDER BY patient_id DESC LIMIT ?';
    $params[] = $limit;
    $param_types .= 'i';
}

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$patients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>View Patients</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/view_patient.css">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Patient Search</h2>

		<!-- Search Form -->
		<form class="search-form" method="GET">
			<div class="form-row">
				<div class="form-group">
					<label>Patient ID:</label>
					<input type="text" name="patient_id" class="input-xs"
						value="<?php echo htmlspecialchars($_GET['patient_id'] ?? ''); ?>"
						maxlength="8">
				</div>
				<div class="form-group">
					<label>Patient Name:</label>
					<input type="text" name="name" class="input-md"
						value="<?php echo htmlspecialchars($_GET['name'] ?? ''); ?>">
				</div>
				<div class="form-group">
					<label>Phone Number:</label>
					<input type="text" name="phone" class="input-md"
						value="<?php echo htmlspecialchars($_GET['phone'] ?? ''); ?>"
						maxlength="10">
				</div>
				<div class="form-group">
					<label style="text-align: center;">Date Range:</label>
					<div class="date-range">
						<input type="date" name="from_date"
							value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
						<span>to</span>
						<input type="date" name="to_date"
							value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
					</div>
				</div>
				<div class="form-group">
					<button type="submit">Search</button>
					<button type="reset" onclick="window.location.href='view_patient.php'">Clear</button>
				</div>
			</div>
		</form>

		<!-- Results Info -->
		<div class="results-info">
			<?php if (empty($_GET['patient_id']) && empty($_GET['name']) && empty($_GET['phone']) && empty($_GET['from_date'])) { ?>
			Found <?php echo count($patients); ?> of
			<?php echo $total_count; ?> total patients (showing last
			<?php echo $limit; ?> entries)
			<?php } else { ?>
			Found <?php echo count($patients); ?> patients
			<?php } ?>
		</div>

		<!-- Patients List -->
		<div class="patients-list">
			<table class="data-table">
				<thead>
					<tr>
						<th>Patient ID</th>
						<th>Name</th>
						<th>Age</th>
						<th>Gender</th>
						<th>Contact</th>
						<th>Registration Date</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($patients as $patient) { ?>
					<tr>
						<td><?php echo formatPatientId(padPatientId($patient['patient_id'])); ?>
						</td>
						<td><?php echo htmlspecialchars($patient['name']); ?>
						</td>
						<td><?php echo $patient['age']; ?>
						</td>
						<td><?php echo $patient['gender']; ?>
						</td>
						<td><?php echo htmlspecialchars($patient['contact']); ?>
						</td>
						<td>
							<div class="datetime">
								<span
									class="date"><?php echo formatDate($patient['created_at']); ?></span>
								<span
									class="time"><?php echo formatTime($patient['created_at']); ?></span>
							</div>
						</td>
						<td>
							<a href="patient_details.php?id=<?php echo $patient['patient_id']; ?>"
								class="btn-view">View</a>
							<?php if (checkPermission('patients', 'edit')) { ?>
							<a href="edit_patient.php?id=<?php echo $patient['patient_id']; ?>"
								class="btn-edit">Edit</a>
							<?php } ?>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		// Auto-format patient ID input to add MNH prefix
		document.querySelector('input[name="patient_id"]').addEventListener('blur', function() {
			let value = this.value.trim();
			if (value && !value.startsWith('MNH')) {
				this.value = 'MNH' + value.padStart(8, '0');
			}
		});

		// Reset form after successful search
		window.onload = function() {
			const urlParams = new URLSearchParams(window.location.search);
			if (urlParams.toString()) {
				// Store the search results in browser history
				const currentUrl = window.location.pathname;
				history.pushState({}, '', currentUrl);

				// Reset all form inputs
				document.querySelectorAll('.search-form input').forEach(input => {
					input.value = '';
				});
			}
		};
	</script>
</body>

</html>