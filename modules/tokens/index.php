<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check permissions
if (!canCreateToken()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

$conn = getTokensDbConnection();
$doctors = getDoctorsList();

// Handle AJAX POST request
if ('POST' === $_SERVER['REQUEST_METHOD'] && !empty($_POST['action'])) {
    header('Content-Type: application/json');

    if ('generate_token' === $_POST['action']) {
        $reconstructedId = sanitizeInput($_POST['reconstructedId'] ?? '');
        $patientName = sanitizeInput($_POST['patientName'] ?? '');
        $doctorId = sanitizeInput($_POST['doctorId'] ?? '');
        $doctorName = sanitizeInput($_POST['doctorName'] ?? '');
        $contact = sanitizeInput($_POST['contact'] ?? '');

        if (!$reconstructedId || !$patientName || !$doctorId || !$doctorName) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields (Patient ID, Name, Doctor)',
            ]);
            exit;
        }

        // Validate doctor id and expected display name
        if (!isset($doctors[$doctorId]) || $doctors[$doctorId] !== $doctorName) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid doctor selected',
            ]);
            exit;
        }

        $result = generateToken($conn, $reconstructedId, $patientName, $doctorName, $contact, (int) $doctorId);

        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'token_number' => $result['token_number'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'],
            ]);
        }
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Appointment Token Generator - ERP</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
	<link rel="stylesheet" href="../../assets/css/tokens.css">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container no-print" style="margin-top: 16px;">
		<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
			<h1 style="margin:0;">Appointment Token Generator</h1>
			<a href="<?php echo BASE_PATH; ?>/modules/tokens/list_tokens.php"
				class="btn btn-back"><i class="fas fa-list"></i> List Tokens</a>
		</div>
	</div>

	<div class="container no-print">
		<!-- Doctor Selection -->
		<div class="section">
			<h2>Select Doctor:</h2>
			<div class="doctor-list">
				<?php foreach ($doctors as $doctorCode => $doctorName) { ?>
				<div class="doctor-item"
					onclick="selectDoctor(this, '<?php echo htmlspecialchars($doctorCode); ?>')">
					<?php echo htmlspecialchars($doctorName); ?>
				</div>
				<?php } ?>
			</div>
		</div>

		<!-- Patient Info -->
		<div class="section">
			<h2>Patient Details:</h2>
			<div class="form-group">
				<label for="patientId">Patient ID: </label>
				<input type="text" id="patientId" placeholder="Enter Patient ID" onblur="fetchPatientDetails()">
			</div>
			<div class="form-group">
				<label for="patientName">Patient Name: </label>
				<input type="text" id="patientName" placeholder="Patient Name" readonly>
			</div>
			<div class="form-group">
				<label for="patientContact">Mobile: </label>
				<input type="text" id="patientContact" placeholder="Patient Contact" readonly>
			</div>
		</div>

		<!-- Token Display -->
		<div class="section token-display">
			<label>Your Token Number: </label>
			<span id="tokenNumber" class="token-number">---</span>
		</div>

		<!-- Action Buttons -->
		<div class="section actions">
			<button onclick="generateToken()" class="btn btn-primary">Generate Token</button>
			<button onclick="printToken()" class="btn btn-secondary">Print Token</button>
		</div>
	</div>

	<!-- Print Area -->
	<div class="print-area" id="printArea">
		<div class="print-header">APPOINTMENT TOKEN</div>
		<div class="print-content">
			<p><strong>Patient ID: </strong><span id="printReconstructedId">---</span></p>
			<p><strong>Patient Name: </strong><span id="printPatientName">---</span></p>
			<p><strong>Doctor: </strong><span id="printDoctorName">---</span></p>
			<p class="token-large"><strong>Token Number: </strong><span id="printTokenNumber">---</span></p>
			<p><strong>Date: </strong><span id="printDate">---</span> <strong>Time: </strong><span
					id="printTime">---</span></p>
		</div>
	</div>

	<script src="../../assets/js/tokens.js?v=<?php echo time(); ?>"></script>
	<script>
		// Initialize with API endpoint pointing to the correct location
		window.tokenConfig = {
			apiGetPatient: '<?php echo BASE_PATH; ?>/includes/helpers/get_patient.php',
			apiGenerateToken: '<?php echo BASE_PATH; ?>/modules/tokens/index.php'
		};
	</script>
</body>

</html>