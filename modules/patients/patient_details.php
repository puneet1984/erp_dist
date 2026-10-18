<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('patients', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Check session
if (!isset($_SESSION['user_id'])) {
    header('Location: /erp/modules/auth/login.php');
    exit;
}

// Get patient ID from URL
$patient_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$patient_id) {
    exit('Invalid patient ID');
}

$conn = getDbConnection();

// Fetch patient details with payment type name
$stmt = $conn->prepare('
    SELECT p.*, pt.type_name as payment_type 
    FROM patients p 
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.payment_type_id 
    WHERE p.patient_id = ?
');
$stmt->bind_param('i', $patient_id);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();

if (!$patient) {
    exit('Patient not found');
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Patient Details</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.details-container {
			max-width: 1000px;
			margin: 20px auto;
			padding: 20px;
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}

		.detail-section {
			margin-bottom: 25px;
			border-bottom: 1px solid #eee;
			padding-bottom: 15px;
		}

		.detail-section:last-child {
			border-bottom: none;
		}

		.section-title {
			font-size: 1.2em;
			color: #333;
			margin-bottom: 15px;
			padding-bottom: 5px;
			border-bottom: 2px solid #4a90e2;
		}

		.detail-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
			gap: 15px;
		}

		.detail-item {
			margin-bottom: 10px;
		}

		.detail-label {
			font-weight: 600;
			color: #666;
			font-size: 0.9em;
		}

		.detail-value {
			color: #333;
			margin-top: 3px;
		}

		.action-bar {
			background: #f8f9fa;
			padding: 10px 20px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 20px;
			border-radius: 4px;
		}

		.action-buttons {
			display: flex;
			gap: 10px;
		}

		.action-button {
			padding: 8px 15px;
			border: none;
			border-radius: 4px;
			cursor: pointer;
			color: white;
			font-size: 0.9em;
		}

		.edit-btn {
			background-color: #4CAF50;
		}

		.bill-btn {
			background-color: #2196F3;
		}

		.back-btn {
			background-color: #607d8b;
		}

		.patient-id {
			font-size: 1.1em;
			font-weight: 600;
			color: #333;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="details-container">
		<!-- Action Bar -->
		<div class="action-bar">
			<div class="patient-id">
				Patient ID:
				MNH<?php echo str_pad($patient['patient_id'], 8, '0', STR_PAD_LEFT); ?>
			</div>
			<div class="action-buttons">
				<button
					onclick="location.href='edit_patient.php?id=<?php echo $patient_id; ?>'"
					class="action-button edit-btn">Edit Patient</button>
				<button
					onclick="location.href='../billing/generate_bill.php?patient_id=<?php echo $patient_id; ?>'"
					class="action-button bill-btn">Generate Bill</button>
				<button onclick="history.back()" class="action-button back-btn">Back</button>
			</div>
		</div>

		<!-- Basic Information -->
		<div class="detail-section">
			<h3 class="section-title">Basic Information</h3>
			<div class="detail-grid">
				<div class="detail-item">
					<div class="detail-label">Name</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['name']); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">Age/Gender</div>
					<div class="detail-value">
						<?php echo $patient['age'].' years / '.
                            ('M' === $patient['gender'] ? 'Male' :
                            ('F' === $patient['gender'] ? 'Female' : 'Other')); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">Occupation</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['occupation'] ?: 'Not specified'); ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Contact Information -->
		<div class="detail-section">
			<h3 class="section-title">Contact Information</h3>
			<div class="detail-grid">
				<div class="detail-item">
					<div class="detail-label">Mobile Number</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['contact']); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">Address</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['address'] ?: 'Not provided'); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">City</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['city']); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">State</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['state']); ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Payment Information -->
		<div class="detail-section">
			<h3 class="section-title">Payment Information</h3>
			<div class="detail-grid">
				<div class="detail-item">
					<div class="detail-label">Payment Type</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['payment_type']); ?>
					</div>
				</div>
				<?php if ($patient['company_name']) { ?>
				<div class="detail-item">
					<div class="detail-label">Company Name</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['company_name']); ?>
					</div>
				</div>
				<?php } ?>
			</div>
		</div>

		<!-- ID Information -->
		<div class="detail-section">
			<h3 class="section-title">ID Information</h3>
			<div class="detail-grid">
				<div class="detail-item">
					<div class="detail-label">Aadhar Number</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['aadhar_number'] ?: 'Not provided'); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">ABHA ID</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['abha_id'] ?: 'Not provided'); ?>
					</div>
				</div>
				<div class="detail-item">
					<div class="detail-label">PM-JAY ID</div>
					<div class="detail-value">
						<?php echo htmlspecialchars($patient['ayushmaan_id'] ?: 'Not provided'); ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</body>

</html>