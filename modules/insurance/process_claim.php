<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'create')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

function generateClaimId()
{
	return 'CLM/' . date('d-m-y') . sprintf('/%04d', rand(0, 9999));
}

// Add this new code to fetch providers
$conn = getDbConnection();
$providers = [];
$provider_query = "SELECT provider_id, TPA_name, insurance_comp_name FROM insurance_providers ORDER BY TPA_name, insurance_comp_name";
$result = $conn->query($provider_query);
if ($result) {
	while ($row = $result->fetch_assoc()) {
		$providers[] = $row;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$conn = getDbConnection();

	// Sanitize all inputs
	$claim_number = generateClaimId();
	$patient_id = sanitizeInteger($_POST['patient_id']);
	$bill_id = sanitizeInput($_POST['bill_id']);
	$provider_id = sanitizeInteger($_POST['provider_id']);
	$member_id = sanitizeInput($_POST['member_id']);
	$policy_number = sanitizeInput($_POST['policy_number']);
	$approval_number = sanitizeInput($_POST['approval_number'] ?? '');

	// Sanitize all numeric values
	$total_amount = sanitizeNumeric($_POST['total_amount'] ?? 0);
	$approved_amount = sanitizeNumeric($_POST['approved_amount'] ?? 0);
	$copay_amount = sanitizeNumeric($_POST['copay_amount'] ?? 0);
	$discount_amount = sanitizeNumeric($_POST['discount_amount'] ?? 0);
	$deduction_amount = sanitizeNumeric($_POST['deduction_amount'] ?? 0);
	$patient_responsibility = sanitizeNumeric($_POST['patient_responsibility'] ?? 0);

	// Sanitize approval details
	$approval_details = [
		'remarks' => sanitizeInput($_POST['approval_remarks'] ?? ''),
		'deduction_reasons' => sanitizeInput($_POST['deduction_reasons'] ?? ''),
		'approval_date' => sanitizeDate(date('Y-m-d')),
		'documents' => []
	];

	// Validate required fields
	if (!$patient_id || !$provider_id) {
		echo json_encode(['status' => 'error', 'message' => 'Invalid patient or provider ID']);
		exit;
	}

	// File upload handling with sanitized patient ID
	$upload_dir = "../../uploads/insurance_claims/" . $patient_id . "/";

	// Create directory if not exists
	if (!file_exists($upload_dir)) {
		mkdir($upload_dir, 0755, true);
	}

	$uploaded_files = [];
	if (isset($_FILES['documents'])) {
		foreach ($_FILES['documents']['tmp_name'] as $key => $tmp_name) {
			$file_name = $_FILES['documents']['name'][$key];
			$file_path = $upload_dir . time() . '_' . $file_name;

			if (move_uploaded_file($tmp_name, $file_path)) {
				$uploaded_files[] = $file_path;
			}
		}
	}

	// Add uploaded files to approval details
	$approval_details['documents'] = $uploaded_files;

	$stmt = $conn->prepare("INSERT INTO insurance_claims 
                           (claim_number, patient_id, bill_id, provider_id, member_id, 
                            policy_number, approval_number, total_amount, 
                            approved_amount, copay_amount, discount_amount,
                            deduction_amount, patient_responsibility, status, approval_details) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)");

	$approval_json = json_encode($approval_details);

	$stmt->bind_param(
		"isssissdddddds",
		$claim_number,
		$patient_id,
		$bill_id,
		$provider_id,
		$member_id,
		$policy_number,
		$approval_number,
		$total_amount,
		$approved_amount,
		$copay_amount,
		$discount_amount,
		$deduction_amount,
		$patient_responsibility,
		$approval_json
	);

	if ($stmt->execute()) {
		echo json_encode(['status' => 'success', 'claim_number' => $claim_number]);
	} else {
		echo json_encode(['status' => 'error', 'message' => $conn->error]);
	}
	exit;
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Process Insurance Claim</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.form-row {
			display: flex;
			gap: 20px;
			margin-bottom: 20px;
		}

		.form-group {
			flex: 1;
		}

		.readonly-field {
			background: #f5f5f5;
		}

		.total-row {
			font-weight: bold;
		}

		#admission_date {
			border-radius: 5px;
			padding: 7px;
			box-sizing: border-box;
			border: 1px solid #ddd;
		}
	</style>
	<script>
		function calculateTotal() {
			const approved = parseFloat(document.getElementsByName('approved_amount')[0].value) || 0;
			const copay = parseFloat(document.getElementsByName('copay_amount')[0].value) || 0;
			const deductions = parseFloat(document.getElementsByName('deduction_amount')[0].value) || 0;
			const discounts = parseFloat(document.getElementsByName('discount_amount')[0].value) || 0;

			const total = approved + copay + deductions + discounts;
			document.getElementsByName('total_amount')[0].value = total.toFixed(2);
		}

		function fetchPatientDetails() {
			const patientId = document.querySelector('input[name="patient_id"]').value;
			const patientNameField = document.getElementById('patientName');
			const admissionDateField = document.querySelector('input[name="admission_date"]');
			const doctorDeptField = document.getElementById('doctorDept');

			if (!patientId) return;

			fetch('../../includes/helpers/get_patient.php?term=' + encodeURIComponent(patientId) + '&check_admission=1')
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success' && data.patient) {
						patientNameField.value = data.patient.name;
						if (data.admission) {
							admissionDateField.value = data.admission.admission_date.split(' ')[0];
							doctorDeptField.value = data.admission.doctor_name + ' - ' + data.admission.dept_name;
						} else {
							admissionDateField.value = '';
							doctorDeptField.value = '';
						}
					} else {
						alert('Patient not found');
						patientNameField.value = '';
						admissionDateField.value = '';
						doctorDeptField.value = '';
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error fetching patient details');
				});
		}
	</script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>
	<div class="container">
		<h2>Process Insurance Claim</h2>
		<form id="claimForm" method="post" enctype="multipart/form-data">
			<!-- Patient Details Row -->
			<div class="form-row">
				<div class="form-group">
					<label>Patient ID:</label>
					<input type="text" name="patient_id" placeholder="Enter Patient ID" required
						onblur="fetchPatientDetails()">
				</div>
				<div class="form-group">
					<label>Patient Name:</label>
					<input type="text" id="patientName" readonly class="readonly-field">
				</div>
				<div class="form-group">
					<label>Doctor & Department:</label>
					<input type="text" id="doctorDept" readonly class="readonly-field">
				</div>
				<div class="form-group">
					<label>Date of Admission:</label>
					<input type="date" name="admission_date" id="admission_date" readonly class="readonly-field">
				</div>
			</div>

			<!-- Insurance Details Row -->
			<div class="form-row">
				<div class="form-group">
					<label>Claim Number:</label>
					<input type="text" name="claim_number" value="<?php echo generateClaimId(); ?>" readonly
						class="readonly-field">
				</div>
				<div class="form-group">
					<label>Insurance Provider:</label>
					<select name="provider_id" required>
						<option value="">Select Provider</option>
						<?php foreach ($providers as $provider): ?>
							<option value="<?php echo htmlspecialchars($provider['provider_id']); ?>">
								<?php echo htmlspecialchars($provider['TPA_name'] . ' - ' . $provider['insurance_comp_name']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- Policy Details Row -->
			<div class="form-row">
				<div class="form-group">
					<label>Member ID:</label>
					<input type="text" name="member_id" >
				</div>
				<div class="form-group">
					<label>Policy Number:</label>
					<input type="text" name="policy_number" >
				</div>
				<div class="form-group">
					<label>Approval Number:</label>
					<input type="text" name="approval_number" >
				</div>
			</div>

			<!-- Amount Details Row -->
			<div class="form-row">
				<div class="form-group">
					<label>Approved Amount:</label>
					<input type="number" step="0.01" name="approved_amount" value="0" min="0" onchange="calculateTotal()">
				</div>
				<div class="form-group">
					<label>Co-pay Amount:</label>
					<input type="number" step="0.01" name="copay_amount" value="0" min="0" onchange="calculateTotal()">
				</div>
				<div class="form-group">
					<label>Deductions:</label>
					<input type="number" step="0.01" name="deduction_amount" value="0" min="0" onchange="calculateTotal()">
				</div>
				<div class="form-group">
					<label>Discounts:</label>
					<input type="number" step="0.01" name="discount_amount" value="0" min="0" onchange="calculateTotal()">
				</div>
			</div>
			<div class="form-row">
				<div class="form-group">
					<label>Total Amount:</label>
					<input type="number" step="0.01" name="total_amount" readonly class="readonly-field">
				</div>
			</div>

			<!-- Deduction Reasons Row -->
			<div class="form-row">
				<div class="form-group">
					<label>Deduction Reasons:</label>
					<textarea name="deduction_reasons" rows="2" style="width: 100%"></textarea>
				</div>
			</div>

			<!-- Documents Row -->
			<div class="form-row">
				<div class="form-group">
					<label>Supporting Documents:</label>
					<input type="file" name="documents[]" multiple>
					<small>Files will be uploaded to patient's folder</small>
				</div>
			</div>

			<button type="submit" class="btn-submit">Submit Claim</button>
		</form>
	</div>
</body>

</html>