<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();

// Check if doctor_id is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Doctor ID is required.");
}

$doctor_id = intval($_GET['id']);

// Fetch doctor details from the database
$query = "SELECT * FROM doctors WHERE doctor_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Doctor not found.");
}

$doctor = $result->fetch_assoc();

// Fetch department list
$dept_query = "SELECT * FROM departments";
$dept_result = $conn->query($dept_query);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Format and validate required fields
    $name = ucwords(strtolower(sanitizeInput($_POST['name'])));
    $degree = sanitizeInput($_POST['degree']);
    $registration_no = strtoupper(sanitizeInput($_POST['registration_no']));
    $dept_id = filter_var($_POST['dept_id'], FILTER_VALIDATE_INT);
    $charge_per_visit = filter_var($_POST['charge_per_visit'], FILTER_VALIDATE_FLOAT);

    // Validate required fields
    if (empty($name) || empty($degree) || empty($registration_no) || !$dept_id || $charge_per_visit === false) {
        echo "<script>alert('Please fill all required fields correctly.');</script>";
        exit;
    }

    // Optional fields - use NULL for empty values
    $specialization = !empty($_POST['specialization']) ? sanitizeInput($_POST['specialization']) : null;
    $contact = !empty($_POST['contact']) ? sanitizeInput($_POST['contact']) : null;
    $email = !empty($_POST['email']) ? filter_var(sanitizeInput($_POST['email']), FILTER_VALIDATE_EMAIL) : null;
    $status = $_POST['status'];

    // Validate contact if provided
    if ($contact !== null && !preg_match('/^[6-9]\d{9}$/', $contact)) {
        echo "<script>alert('Invalid contact number format.');</script>";
        exit;
    }

    // Check email uniqueness if provided
    if ($email !== null) {
        $check_stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE email = ? AND doctor_id != ?");
        $check_stmt->bind_param("si", $email, $doctor_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            echo "<script>alert('Email address already exists.');</script>";
            exit;
        }
    }

    // Update query with NULL handling
    $update_query = "UPDATE doctors 
                     SET name=?, degree=?, registration_no=?, charge_per_visit=?, 
                         dept_id=?, specialization=?, contact=?, email=?, status=? 
                     WHERE doctor_id=?";

    $stmt = $conn->prepare($update_query);
    $stmt->bind_param(
        "ssssissssi",
        $name,
        $degree,
        $registration_no,
        $charge_per_visit,
        $dept_id,
        $specialization,
        $contact,
        $email,
        $status,
        $doctor_id
    );

    if ($stmt->execute()) {
        echo "<script>alert('Doctor details updated successfully!'); window.location.href='manage_doctors.php';</script>";
    } else {
        echo "<script>alert('Error updating doctor: " . $conn->error . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Edit Doctor</title>
	<style>
		body {
			font-family: Arial, sans-serif;
			margin: 20px;
			padding: 0;
			background-color: #f4f4f4;
		}

		.container {
			max-width: 600px;
			margin: 50px auto;
			background: white;
			padding: 20px;
			border-radius: 8px;
			box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
		}

		h2 {
			text-align: center;
		}

		.form-group {
			margin-bottom: 15px;
		}

		label {
			font-weight: bold;
			display: block;
		}

		input,
		select {
			width: 95%;
			padding: 8px;
			margin-top: 5px;
			border: 1px solid #ccc;
			border-radius: 5px;
		}

		button {
			display: block;
			width: 100%;
			padding: 10px;
			margin-top: 10px;
			border: none;
			background: #28a745;
			color: white;
			font-size: 16px;
			border-radius: 5px;
			cursor: pointer;
		}

		button:hover {
			background: #218838;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Edit Doctor</h2>
		<form method="POST">
			<div class="form-group">
				<label>Name:</label>
				<input type="text" name="name"
					value="<?= htmlspecialchars($doctor['name'] ?? '') ?>"
					required>
			</div>
			<div class="form-group">
				<label>Degree:</label>
				<input type="text" name="degree"
					value="<?= htmlspecialchars($doctor['degree'] ?? '') ?>"
					required>
			</div>
			<div class="form-group">
				<label>Registration No:</label>
				<input type="text" name="registration_no"
					value="<?= htmlspecialchars($doctor['registration_no'] ?? '') ?>"
					required>
			</div>
			<div class="form-group">
				<label>Consultation Fee:</label>
				<input type="number" name="charge_per_visit"
					value="<?= htmlspecialchars($doctor['charge_per_visit'] ?? '0') ?>"
					required min="0" step="0.01">
			</div>
			<div class="form-group">
				<label>Department:</label>
				<select name="dept_id" required>
					<?php while ($dept = $dept_result->fetch_assoc()): ?>
					<option
						value="<?= $dept['dept_id'] ?>"
						<?= ($doctor['dept_id'] == $dept['dept_id']) ? 'selected' : '' ?>>
						<?= htmlspecialchars($dept['name']) ?>
					</option>
					<?php endwhile; ?>
				</select>
			</div>
			<div class="form-group">
				<label>Specialization:</label>
				<input type="text" name="specialization" pattern="[A-Za-z ,\-]+"
					title="Only letters, spaces, and commas allowed"
					value="<?= htmlspecialchars($doctor['specialization'] ?? '') ?>">
			</div>
			<div class="form-group">
				<label>Contact:</label>
				<input type="tel" name="contact" pattern="[6-9][0-9]{9}" title="Enter a valid 10-digit mobile number"
					value="<?= htmlspecialchars($doctor['contact'] ?? '') ?>">
			</div>
			<div class="form-group">
				<label>Email:</label>
				<input type="email" name="email"
					value="<?= htmlspecialchars($doctor['email'] ?? '') ?>">
			</div>
			<div class="form-group">
				<label>Status:</label>
				<select name="status" required>
					<option value="ACTIVE" <?= ($doctor['status'] === 'ACTIVE') ? 'selected' : '' ?>>Active
					</option>
					<option value="INACTIVE" <?= ($doctor['status'] === 'INACTIVE') ? 'selected' : '' ?>>Inactive
					</option>
				</select>
			</div>
			<button type="submit">Save Changes</button>
		</form>
	</div>
</body>

</html>

<script>
	document.querySelector('form').onsubmit = function(e) {
		// Format inputs
		const nameInput = this.elements['name'];
		const registrationInput = this.elements['registration_no'];

		// Capitalize first letter of each word in name
		nameInput.value = nameInput.value
			.toLowerCase()
			.replace(/\b\w/g, c => c.toUpperCase())
			.trim();

		// Uppercase registration number
		registrationInput.value = registrationInput.value.toUpperCase().trim();

		// Validate required fields
		const requiredFields = ['name', 'degree', 'registration_no', 'dept_id', 'charge_per_visit'];
		let isValid = true;

		requiredFields.forEach(field => {
			const input = this.elements[field];
			if (!input.value.trim()) {
				isValid = false;
				input.classList.add('invalid');
				if (!firstInvalidField) firstInvalidField = input;
			} else {
				input.classList.remove('invalid');
			}
		});

		if (!isValid) {
			alert('Please fill all required fields.');
			e.preventDefault();
			return false;
		}
	};
</script>