<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'create')) {
	header('Location: ../auth/unauthorized.php');
	exit;
}

// Function to format gender display
function formatGenderDisplay($gender)
{
    switch ($gender) {
        case 'M':
            return 'Male (पुरुष)';
        case 'F':
            return 'Female (स्त्री)';
        case 'O':
            return 'Others (अन्य)';
        default:
            return $gender;
    }
}

// Template data directly in PHP
$form_data = [
    'title' => 'Discharge Document Handover Form',
    'heading' => [
        'hospital_name_hindi' => 'माहेश्वरी नर्सिंग होम',
        'address' => '163, भगत सिंग मार्ग, देवास (म.प्र.) फ़ोन - 07272-221223,252849,7828485666',
        'form_title_english' => 'DISCHARGE DOCUMENT HANDOVER & ACKNOWLEDGEMENT FORM',
        'form_title_hindi' => '(छुट्टी दस्तावेज़ हस्तांतरण और स्वीकृति पत्र)',
    ],
    'documents' => [
        'Discharge Summary (छुट्टी का सारांश)',
        'Investigation Reports (जांच रिपोर्ट)',
		'Hospital Bill (अस्पताल का बिल)',
        'Other Documents (अन्य दस्तावेज़)',
        // 'X-Ray / Scan Films (एक्स-रे / स्कैन फिल्में)',
        // 'Surgery/Procedure Report (सर्जरी/प्रक्रिया रिपोर्ट)',
        // 'Pathology Reports (पैथोलॉजी रिपोर्ट)',
        // 'OT/ICU Notes (ओटी/आईसीयू नोट्स)',
        // 'Consent Forms (सहमति पत्र)',
        // 'Medical Prescriptions (दवाओं के पर्चे)',
        // 'Insurance Documents (बीमा दस्तावेज़)',
    ],
];

// Get admission data using the same function as admission form
$admission_data = null;
$patient_data = null;
$readonly = false;
$patientId = sanitizeInteger($_GET['patient_id'] ?? 0, 0);

if (isset($_GET['id'])) {
    // Use the same function to get admission details
    $admission_data = getAdmissionDetails($_GET['id']);
    if ($admission_data) {
        $patient_data = $admission_data; // Same data structure
		$patientId = (int) ($admission_data['patient_id'] ?? $patientId);
    }
}

if (!$patient_data && $patientId) {
	$conn = getDbConnection();
	$stmt = $conn->prepare('SELECT patient_id, name AS patient_name, age, gender, contact, address, city, state
							FROM patients
							WHERE patient_id = ?');
	$stmt->bind_param('i', $patientId);
	$stmt->execute();
	$patient_data = $stmt->get_result()->fetch_assoc() ?: null;

	if ($patient_data) {
		$stmt = $conn->prepare('SELECT admission_id, admission_date, discharge_date, status
								FROM ipd_admissions
								WHERE patient_id = ?
								ORDER BY admission_date DESC
								LIMIT 1');
		$stmt->bind_param('i', $patientId);
		$stmt->execute();
		$admission_data = $stmt->get_result()->fetch_assoc() ?: null;
	}
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $form_data['title']; ?>
	</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		body {
			font-family: Arial, sans-serif;
		}

		.form-page {
			font-size: 10px;
		}

		.form-header {
			text-align: center;
			margin-bottom: 8px;
			border-bottom: 2px solid #333;
			padding-bottom: 5px;
		}

		.hospital-name {
			font-size: 18px;
			font-weight: bold;
			color: #000;
			margin-bottom: 2px;
		}

		.address {
			font-size: 10px;
			margin-bottom: 3px;
		}

		.form-title {
			font-size: 12px;
			font-weight: bold;
			margin-bottom: 2px;
		}

		.section {
			margin: 5px 0;
			padding: 5px;
			border: none;
			border-radius: 0;
			background: transparent;
		}

		.section-title {
			font-size: 12px;
			font-weight: bold;
			margin-bottom: 5px;
			color: #000;
			border-bottom: 1px solid #000;
			padding-bottom: 2px;
		}

		.form-group {
			margin: 3px 0;
		}

		.form-group label {
			display: block;
			margin-bottom: 1px;
			font-weight: bold;
			font-size: 9px;
		}

		.form-group input,
		.form-group textarea {
			width: 95%;
			padding: 2px;
			border: 1px solid #ccc;
			border-radius: 2px;
			font-size: 9px;
		}

		.form-row {
			display: flex;
			gap: 8px;
		}

		.form-row .form-group {
			flex: 1;
		}

		.form-row-3 {
			display: flex;
			gap: 5px;
		}

		.form-row-3 .form-group {
			flex: 1;
		}

		.document-list {
			margin: 5px 0;
		}

		.document-item {
			display: flex;
			align-items: center;
			padding: 3px;
			margin: 2px 0;
			background: white;
			border: 1px solid #dee2e6;
			border-radius: 2px;
		}

		.document-item input[type="checkbox"] {
			width: 20px;
			height: 20px;
			margin-right: 10px;
			cursor: pointer;
		}

		.document-item label {
			margin: 0;
			cursor: pointer;
			font-weight: normal;
			font-size: 10px;
			color: #000;
		}

		.signature-box {
			border: 2px solid #000;
			height: 50px;
			width: 100%;
			margin-top: 5px;
			position: relative;
			background: white;
		}

		.signature-label {
			position: absolute;
			bottom: -18px;
			left: 0;
			font-size: 8px;
			font-weight: bold;
		}

		.submit-btn {
			background: #2c5aa0;
			color: white;
			padding: 10px 25px;
			border: none;
			border-radius: 4px;
			font-size: 13px;
			cursor: pointer;
			font-weight: bold;
		}

		.submit-btn:hover {
			background: #1e3f73;
		}

		.print-btn {
			background: #28a745;
			color: white;
			padding: 10px 25px;
			border: none;
			border-radius: 4px;
			font-size: 13px;
			cursor: pointer;
			margin-right: 10px;
			font-weight: bold;
		}

		.print-btn:hover {
			background: #218838;
		}

		.action-buttons {
			text-align: center;
			margin: 30px 0;
		}

		.info-box {
			background: transparent;
			border: none;
			padding: 15px;
			border-radius: 0;
			margin: 15px 0;
		}

		.info-row {
			display: flex;
			margin: 8px 0;
			font-size: 13px;
		}

		.info-label {
			font-weight: bold;
			min-width: 150px;
			color: #000;
		}

		.manual-entry-line {
			border: none;
			border-bottom: 1px solid #000;
			background: transparent;
			min-height: 30px;
			padding: 5px 5px;
		}

		.checkbox-space {
			width: 18px;
			height: 18px;
			border: 2px solid #000;
			display: inline-block;
			margin-right: 12px;
			vertical-align: middle;
			background: white;
		}

		/* Read-only styles */
		input[readonly] {
			border-color: #e9ecef;
			background: #ffffffff;
		}

		/* Print styles for A4 - DO NOT MODIFY */
		@media print {
			.container {
				max-width: 100%;
				margin: 0;
				padding: 3mm;
				box-shadow: none;
			}

			.action-buttons {
				display: none !important;
			}

			body {
				margin: 0;
				padding: 3mm;
				font-size: 8.5px;
				background: white;
			}

			.form-header {
				border-bottom: 1.5px solid #000;
				margin-bottom: 3px;
				padding-bottom: 3px;
				text-align: center;
			}

			.action-bar,
			.header,
			.patient-id-group {
				display: none !important;
			}

			.hospital-name {
				font-size: 24px;
				display: block;
			}

			.address {
				font-size: 14px;
				display: block;
				text-align: center;
			}

			.form-title {
				font-size: 16px;
				display: block;
				text-align: center;
			}

			.section {
				page-break-inside: avoid;
				margin: 4px 2px;
				padding: 2px;
			}

			.section-title {
				font-size: 10px;
				margin-bottom: 3px;
				border-bottom: 0.5px solid #000;
			}

			.form-group {
				margin: 2px 0;
			}

			.form-group label {
				font-size: 8.5px;
				margin-bottom: 4px;
				font-weight: bold;
			}

			.form-group input {
				font-size: 12px;
				padding: 1px;
			}

			.form-row-3 .form-group input {
				font-size: 12px;
			}

			.document-item {
				padding: 1px;
				margin: 0.5px 0;
				border-color: #ffffffff;
			}

			.document-item label {
				font-size: 10px;
				color: #000;
			}

			.info-box {
				padding: 2px;
				margin: 4px 2px;
			}

			.signature-box {
				height: 40px;
				margin-top: 4px;
			}

			.signature-label {
				font-size: 7px;
				bottom: -18px;
			}

			.manual-entry-line {
				min-height: 14px;
				padding: 1px;
				margin-top: 2px;
			}

			.staff-entry {
				margin-top: 20px !important;
			}

			p {
				font-size: 12px !important;
				margin: 1px 1px !important;
				line-height: 1.2 !important;
			}
		}
	</style>
</head>

<body>
	<?php include_once '../../includes/simple_action_bar.php'; ?>
	<div class="container mt-4 form-page">
		<div class="form-header">
			<div class="hospital-name">
				<?php echo $form_data['heading']['hospital_name_hindi']; ?>
			</div>
			<div class="address">
				<?php echo $form_data['heading']['address']; ?>
			</div>
			<div class="form-title">
				<?php echo $form_data['heading']['form_title_english']; ?>
			</div>
			<div class="form-title">
				<?php echo $form_data['heading']['form_title_hindi']; ?>
			</div>

		</div>

		<!-- Patient Information Section - Auto-filled -->
		<div class="info-box">
			<div class="section-title">Patient Information (रोगी की जानकारी)</div>

			<div class="form-row-3">
				<div class="form-group patient-id-group" style="flex: 1;">
					<label for="patient_id">Patient ID</label>
					<input type="text" id="patient_id" name="patient_id" inputmode="numeric" pattern="[0-9]*"
						value="<?php echo htmlspecialchars((string) ($patient_data['patient_id'] ?? ($patientId > 0 ? $patientId : ''))); ?>"
						placeholder="Enter Patient ID">
				</div>
				<div class="form-group" style="flex: 5;">
					<label for="patient_name">Patient Name (रोगी का नाम)</label>
					<input type="text" id="patient_name" name="patient_name"
						value="<?php echo $patient_data['patient_name'] ?? ''; ?>"
						readonly>
				</div>
				<div class="form-group" style="flex: 1;">
					<label for="age">Age-years (उम्र)</label>
					<input type="text" id="age" name="age"
						value="<?php echo $patient_data['age'] ?? ''; ?>"
						readonly>
				</div>
				<div class="form-group" style="flex: 1;">
					<label for="gender">Gender (लिंग)</label>
					<input type="text" id="gender" name="gender"
						value="<?php echo formatGenderDisplay($patient_data['gender'] ?? ''); ?>"
						readonly>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group" style="flex: 1.2;">
					<label for="admission_date">Date & Time of Admission (भर्ती की तिथि और समय)</label>
					<input type="text" id="admission_date" name="admission_date"
						value="<?php echo isset($admission_data['admission_date']) ? date('d/m/Y h:i A', strtotime($admission_data['admission_date'])) : ''; ?>"
						readonly>
				</div>
				<div class="form-group" style="flex: 1;">
					<label for="discharge_date">Date & Time of Discharge (छुट्टी की तिथि और समय)</label>
					<div class="manual-entry-line" style="width: 95%; display: inline-block;"></div>
				</div>
			</div>
		</div>

		<!-- Documents Handover Section -->
		<div class="section">
			<div class="section-title">Documents Handover (दस्तावेज़ हस्तांतरण)</div>

			<p style="font-size: 9px; margin-bottom: 5px; margin-top: 0; font-style: italic;">
				Please check the documents being handed over to the patient/guardian:<br>
				कृपया रोगी/अभिभावक को दिए जा रहे दस्तावेजों को चेक करें:
			</p>

			<div class="document-list">
				<?php foreach ($form_data['documents'] as $index => $document) { ?>
				<div class="document-item">
					<span class="checkbox-space"></span>
					<label
						style="cursor: default;"><?php echo $document; ?></label>
				</div>
				<?php } ?>
			</div>

			<div class="form-group" style="margin-top: 5px;">
				<label for="other_documents">Other Documents (अन्य दस्तावेज़ - if any)</label>
				<div class="manual-entry-line" style="width: 95%; display: inline-block;"></div>
			</div>

			<div style="margin-top: 8px; border-top: 1px solid #dee2e6; padding-top: 5px;">
				<p style="font-size: 10px; font-weight: bold; margin-bottom: 5px; margin-top: 0;">
					Hospital Staff Details (अस्पताल कर्मचारी विवरण)
				</p>

				<div class="form-row">
					<div class="form-group">
						<label for="staff_name">Staff Name (कर्मचारी का नाम)</label>
						<div class="manual-entry-line staff-entry"
							style="width: 95%; display: inline-block; margin-top: 8px;"></div>
					</div>
					<div class="form-group">
						<label for="staff_signature">Staff Signature (कर्मचारी के हस्ताक्षर)</label>
						<div class="signature-box">
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Patient Party Acknowledgement Section -->
		<div class="section">
			<div class="section-title">Acknowledgement of Receipt (प्राप्ति की स्वीकृति)</div>

			<p style="font-size: 12px; margin-bottom: 5px; margin-top: 0; line-height: 1.4; text-align: justify;">
				<strong>Declaration (घोषणा):</strong><br>
				I hereby acknowledge that I have received all the above-mentioned documents from the hospital staff.
				All documents are complete and in good condition. I understand that I am responsible for keeping these
				documents safe and bringing them for follow-up visits as required. I am satisfied with the treatment of
				my patient.
				I am discharging my patient from the hospital/nursing home on my own risk.<br><br>
				मैं स्वीकार करता/करती हूँ कि मुझे अस्पताल के कर्मचारी से उपरोक्त सभी दस्तावेज़ मिल गए हैं। सभी दस्तावेज़
				पूर्ण और अच्छी स्थिति में हैं।
				मैं समझता/समझती हूँ कि इन दस्तावेजों को सुरक्षित रखना और आवश्यकतानुसार फॉलो-अप विज़िट के लिए लाना मेरी
				जिम्मेदारी है।
				मैं अपने मरीज़ के इलाज से संतुष्ट हूँ। मैं अपने मरीज़ को अपनी जवाबदारी पर अस्पताल से छुट्टी करवाकर ले जा
				रहा/रही हूँ।<br><br>
			</p>

			<div class="form-row">
				<div class="form-group">
					<label for="receiver_name">Name of Receiver (प्राप्तकर्ता का नाम)</label>
					<div class="manual-entry-line" style="width: 95%; display: inline-block;"></div>
				</div>
				<div class="form-group">
					<label for="receiver_relation">Relation with Patient (रोगी से रिश्ता)</label>
					<div class="manual-entry-line" style="width: 95%; display: inline-block;"></div>
				</div>
			</div>

			<div class="form-group">
				<label for="receiver_signature">Signature of Receiver/Patient/Guardian (प्राप्तकर्ता/रोगी/अभिभावक के
					हस्ताक्षर)</label>
				<div class="signature-box">
				</div>
			</div>
		</div>

		<div class="action-buttons">
			<!-- <button type="button" class="print-btn" onclick="window.location.href='../ipd/ipd_list.php';" style="background: #6c757d;">
			<i class="fas fa-arrow-left"></i> Back to IPD List
		</button> -->
			<button type="button" class="print-btn" onclick="window.print();">
				<i class="fas fa-print"></i> Print Form
			</button>
		</div>

	</div>

	<script>
		function formatGenderDisplayClient(gender) {
			if (gender === 'M') return 'Male (पुरुष)';
			if (gender === 'F') return 'Female (स्त्री)';
			if (gender === 'O') return 'Others (अन्य)';
			return gender || '';
		}

		function formatDateTimeDisplay(dateTime) {
			if (!dateTime) return '';
			const date = new Date(dateTime.replace(' ', 'T'));
			if (Number.isNaN(date.getTime())) return '';
			const day = String(date.getDate()).padStart(2, '0');
			const month = String(date.getMonth() + 1).padStart(2, '0');
			const year = date.getFullYear();
			const rawHours = date.getHours();
			const hours = String(((rawHours + 11) % 12) + 1).padStart(2, '0');
			const minutes = String(date.getMinutes()).padStart(2, '0');
			const ampm = rawHours >= 12 ? 'PM' : 'AM';
			return `${day}/${month}/${year} ${hours}:${minutes} ${ampm}`;
		}

		function fillPatientDetails(patient) {
			document.getElementById('patient_id').value = patient.id || '';
			document.getElementById('patient_name').value = patient.name || '';
			document.getElementById('age').value = patient.age || '';
			document.getElementById('gender').value = formatGenderDisplayClient(patient.gender || '');

			const admission = patient.admission || null;
			const admissionField = document.getElementById('admission_date');
			if (admissionField) {
				admissionField.value = admission ? formatDateTimeDisplay(admission.admission_date || '') : '';
			}
		}

		let lastFetchedPatientId = (document.getElementById('patient_id').value || '').replace(/\D/g, '');
		let isFetchingPatient = false;

		async function fetchAndAutofillPatient() {
			const patientIdInput = document.getElementById('patient_id');
			const patientId = (patientIdInput.value || '').replace(/\D/g, '');

			if (!patientId) {
				lastFetchedPatientId = '';
				return;
			}

			if (isFetchingPatient || patientId === lastFetchedPatientId) {
				return;
			}

			isFetchingPatient = true;

			try {
				const response = await fetch(`../../includes/helpers/get_patient.php?term=${encodeURIComponent(patientId)}&module=ipd`);
				const data = await response.json();

				if (!response.ok || data.status !== 'success' || !data.patient) {
					throw new Error(data.message || 'Patient not found');
				}

				fillPatientDetails(data.patient);
				lastFetchedPatientId = String(data.patient.id || patientId);
			} catch (error) {
				document.getElementById('patient_name').value = '';
				document.getElementById('age').value = '';
				document.getElementById('gender').value = '';
				document.getElementById('admission_date').value = '';
				lastFetchedPatientId = '';
				alert(error.message || 'Unable to fetch patient details');
			} finally {
				isFetchingPatient = false;
			}
		}

		// Auto-print if print parameter is passed
		if (new URLSearchParams(window.location.search).get('print') === '1') {
			window.print();
		}

		document.getElementById('patient_id').addEventListener('blur', fetchAndAutofillPatient);
		document.getElementById('patient_id').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				fetchAndAutofillPatient();
			}
		});
	</script>
</body>

</html>