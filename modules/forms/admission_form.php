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
    'title' => 'Hospital Admission Form',
    'heading' => [
        'hospital_name_hindi' => 'माहेश्वरी नर्सिंग होम',
        'address' => '163, भगत सिंग मार्ग, देवास (म.प्र.) फ़ोन - 07272-221223,252849,7828485666',
        'form_title_english' => 'ADMISSION FORM AND VOLUNTARY AND INFORMED CONSENT FOR ADMISSION AND TREATMENT',
        'form_title_hindi' => '(भर्ती पत्र एवं भर्ती हेतु स्वैच्छिक और सूचित सहमति पत्र)',
        'legal_reference' => '(Under Section 13 of The Indian Contract Act, Indian Penal Code and Indian Medical Council (Professional Conduct and Ethics) Regulations 2002)',
    ],
];

// Get admission data using the same function as view.php
$admission_data = null;
$patient_data = null;
$readonly = false;

if (isset($_GET['id'])) {
    // Use the same function as view.php
    $admission_data = getAdmissionDetails($_GET['id']);
    if ($admission_data) {
        $patient_data = $admission_data; // Same data structure
        $readonly = true;
    }
}

if ($_POST && !$readonly) {
    // Process form submission for new admissions
    $admission_data = [
        'patient_name' => $_POST['patient_name'] ?? '',
        'age' => $_POST['age'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'contact_number' => $_POST['contact_number'] ?? '',
        'address' => $_POST['address'] ?? '',
        'emergency_contact' => $_POST['emergency_contact'] ?? '',
        'chief_complaint' => $_POST['chief_complaint'] ?? '',
        'medical_history' => $_POST['medical_history'] ?? '',
        'allergies' => $_POST['allergies'] ?? '',
        'current_medications' => $_POST['current_medications'] ?? '',
        'admission_date' => date('Y-m-d'),
        'admission_time' => date('H:i:s'),
    ];

    // Save to database or file (implement as needed)
    echo "<script>alert('Admission form submitted successfully!');</script>";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $form_data['title']; ?>
	</title>
	<style>
		body {
			font-family: Arial, sans-serif;
			margin: 10px;
			font-size: 12px;
		}

		.header {
			text-align: center;
			margin-bottom: 15px;
			border-bottom: 2px solid #333;
			padding-bottom: 10px;
		}

		.hospital-name {
			font-size: 18px;
			font-weight: bold;
			color: #2c5aa0;
			margin-bottom: 3px;
		}

		.address {
			font-size: 11px;
			margin-bottom: 8px;
		}

		.form-title {
			font-size: 13px;
			font-weight: bold;
			margin-bottom: 3px;
		}

		.legal-ref {
			font-size: 10px;
			font-style: italic;
		}

		.section {
			margin: 10px 0;
			padding: 8px;
			border-radius: 3px;
		}

		.section-title {
			font-size: 14px;
			font-weight: bold;
			margin-bottom: 8px;
			color: #2c5aa0;
		}

		.form-group {
			margin: 5px 0;
		}

		.form-group label {
			display: block;
			margin-bottom: 2px;
			font-weight: bold;
			font-size: 11px;
		}

		.form-group input,
		.form-group textarea,
		.form-group select {
			width: 95%;
			padding: 4px;
			border: 1px solid #ccc;
			border-radius: 2px;
			font-size: 11px;
			resize: none;
		}

		.form-row {
			display: flex;
			gap: 10px;
		}

		.form-row .form-group {
			flex: 1;
		}

		.form-row-3 {
			display: flex;
			gap: 8px;
		}

		.form-row-3 .form-group {
			flex: 1;
		}

		.consent-text {
			padding: 8px;
			border-radius: 3px;
			margin: 5px 0;
			font-size: 10px;
		}

		.consent-text ul {
			margin: 5px 0;
			padding-left: 15px;
		}

		.consent-text li {
			margin: 2px 0;
		}

		.submit-btn {
			background: #2c5aa0;
			color: white;
			padding: 8px 20px;
			border: none;
			border-radius: 3px;
			font-size: 12px;
			cursor: pointer;
		}

		.submit-btn:hover {
			background: #1e3f73;
		}

		.print-btn {
			background: #28a745;
			color: white;
			padding: 8px 20px;
			border: none;
			border-radius: 3px;
			font-size: 12px;
			cursor: pointer;
			margin-right: 10px;
		}

		.print-btn:hover {
			background: #218838;
		}

		.action-buttons {
			text-align: center;
			margin: 15px 0;
		}

		.admission-info {
			margin-top: 8px;
			padding: 5px;
			border-radius: 3px;
			font-size: 12px;
		}

		.hindi-consent {
			line-height: 1.3;
			text-align: justify;
			font-size: 10px;
		}

		.hindi-consent input {
			display: inline;
			width: auto;
			min-width: 100px;
			border: none;
			border-bottom: 1px solid #000;
			padding: 1px;
			font-size: 10px;
			background: transparent;
		}

		.signature-box {
			border: 1px solid #000;
			height: 60px;
			width: 100%;
			margin-top: 10px;
			position: relative;
		}

		.signature-label {
			position: absolute;
			bottom: -20px;
			left: 0;
			font-size: 10px;
			font-weight: bold;
		}

		.name-below-signature {
			margin-top: 25px;
			/* border-top: 1px solid #000; */
			text-align: center;
			padding-top: 2px;
			font-size: 10px;
			font-weight: bold;
		}

		.witness-name-input {
			margin-top: 25px;
			width: 100%;
			text-align: center;
			border: none;
			/* border-top: 1px solid #000; */
			padding-top: 2px;
			font-size: 10px;
			background: transparent;
		}

		.checkout-time-section {
			text-align: center;
			margin: 20px 0;
			padding: 15px;
			border: 2px solid #000;
			border-radius: 5px;
		}

		.checkout-time-label {
			font-size: 18px;
			font-weight: bold;
			margin-bottom: 10px;
		}

		.checkout-time-value {
			font-size: 24px;
			font-weight: bold;
			color: #2c5aa0;
		}

		/* Print styles for A4 */
		@media print {

			.action-buttons,
			.print-btn {
				display: none !important;
			}

			body {
				margin: 0;
				font-size: 10px;
			}

			.section {
				page-break-inside: avoid;
				margin: 2px 0;
				padding: 2px;
			}

			.header {
				border-bottom: 2px solid #000;
				margin-bottom: 10px;
				padding-bottom: 8px;
			}

			.hospital-name {
				font-size: 20px;
			}

			.form-title {
				font-size: 12px;
			}

			.section-title {
				font-size: 12px;
			}

			.form-group label {
				font-size: 9px;
			}

			.form-group input,
			.form-group textarea,
			.form-group select {
				font-size: 9px;
				padding: 2px;
			}

			.consent-text {
				font-size: 8px;
				padding: 5px;
			}

			.hindi-consent {
				font-size: 10px;
			}

			.hindi-consent input {
				font-size: 10px;
				width: auto;
				min-width: 150px;
				font-weight: bold;
			}

			.checkout-time-section {
				margin: 15px 0;
				padding: 10px;
			}

			.checkout-time-label {
				font-size: 14px;
			}

			.checkout-time-value {
				font-size: 18px;
			}

			.admission-info {
				font-size: 14px;
			}
		}

		/* Read-only styles */
		input[readonly],
		textarea[readonly],
		select[disabled] {
			border-color: #e9ecef;
		}
	</style>
</head>

<body>
	<div class="header">
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
		<div class="legal-ref">
			<?php echo $form_data['heading']['legal_reference']; ?>
		</div>

		<?php if ($readonly && $admission_data) { ?>
		<div class="admission-info">
			<div style="margin-bottom: 5px;">
				<strong>Admission ID:</strong>
				<?php echo $admission_data['admission_id']; ?>
				|
				<strong>Admission Date:</strong>
				<?php echo date('d/m/Y H:i', strtotime($admission_data['admission_date'])); ?>
				|
				<strong>Discharge Date:</strong>
				<?php if (!empty($admission_data['discharge_date'])) { ?>
				<?php echo date('d/m/Y H:i', strtotime($admission_data['discharge_date'])); ?>
				<?php } else { ?>
				____/___/____ ___:___
				<?php } ?>
			</div>
			<div>
				<strong>Doctor:</strong> Dr.
				<?php echo $admission_data['doctor_name']; ?>
				<?php if (!empty($admission_data['dept_name'])) { ?>
				(<?php echo $admission_data['dept_name']; ?>)
				<?php } ?>
				|
				<strong>Ward:</strong>
				<?php echo $admission_data['ward_name']; ?>
				- Bed
				<?php echo $admission_data['bed_number']; ?>
			</div>
		</div>
		<?php } ?>
	</div>

	<form method="POST" action="">
		<div class="section">
			<div class="section-title">Patient Information (रोगी की जानकारी)</div>

			<div class="form-row-3">
				<div class="form-group">
					<label for="patient_id">Patient ID (रोगी आईडी) *</label>
					<input type="text" id="patient_id" name="patient_id"
						value="<?php echo $patient_data['patient_id'] ?? ''; ?>"
						<?php echo $readonly ? 'readonly' : 'required'; ?>>
				</div>
				<div class="form-group">
					<label for="patient_name">Patient Name (रोगी का नाम) *</label>
					<input type="text" id="patient_name" name="patient_name"
						value="<?php echo $patient_data['patient_name'] ?? ''; ?>"
						<?php echo $readonly ? 'readonly' : 'required'; ?>>
				</div>
				<div class="form-group">
					<label for="age">Age (उम्र) *</label>
					<input type="number" id="age" name="age" min="0" max="150"
						value="<?php echo $patient_data['age'] ?? ''; ?>"
						<?php echo $readonly ? 'readonly' : 'required'; ?>>
				</div>
				<div class="form-group">
					<label for="gender">Gender (लिंग) *</label>
					<input type="text" id="gender" name="gender"
						value="<?php echo formatGenderDisplay($patient_data['gender'] ?? ''); ?>"
						<?php echo $readonly ? 'readonly' : 'required'; ?>>
				</div>
			</div>

			<div class="form-row-3">
				<div class="form-group">
					<label for="address">Address (पता) *</label>
					<textarea id="address" name="address"
						<?php echo $readonly ? 'readonly' : 'required'; ?>><?php echo $patient_data['address'] ?? ''; ?>, City:<?php echo $patient_data['city'] ?? ''; ?>, State:<?php echo $patient_data['state'] ?? ''; ?></textarea>
				</div>
				<div class="form-group">
					<label for="contact_number">Contact Number (संपर्क नंबर) *</label>
					<input type="tel" id="contact_number" name="contact_number"
						value="<?php echo $patient_data['contact'] ?? ''; ?>"
						<?php echo $readonly ? 'readonly' : 'required'; ?>>
				</div>
			</div>

		</div>
		<div class="section">
			<div class="section-title">Attendant Information (सहयोगी की जानकारी)</div>

			<div class="form-row">
				<div class="form-group">
					<label for="attendant_name">Attendant Name (सहयोगी का नाम) *</label>
					<input type="text" id="attendant_name" name="attendant_name"
						value="<?php echo $patient_data['attendant_name'] ?? ''; ?>"
						required>
				</div>
				<div class="form-group">
					<label for="attendant_relation">Relation (रिश्ता) *</label>
					<input type="text" id="attendant_relation" name="attendant_relation"
						value="<?php echo $patient_data['attendant_relation'] ?? ''; ?>"
						required>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label for="attendant_address">Attendant Address (सहयोगी का पता) *</label>
					<textarea id="attendant_address" name="attendant_address"
						required><?php echo $patient_data['attendant_address'] ?? ''; ?></textarea>
				</div>
				<div class="form-group">
					<label for="attendant_mobile">Mobile Number (मोबाइल नंबर) *</label>
					<input type="tel" id="attendant_mobile" name="attendant_mobile"
						value="<?php echo $patient_data['attendant_mobile'] ?? ''; ?>"
						required>
				</div>
			</div>
		</div>
		<div class="section">
			<div class="form-row">
				<div class="form-group">
					<label for="diagnosis">Diagnosis (निदान) *</label>
					<textarea id="diagnosis" name="diagnosis"
						<?php echo $readonly ? 'readonly' : 'required'; ?>><?php echo $admission_data['diagnosis'] ?? ''; ?></textarea>
				</div>
			</div>
			<div class="form-row">
				<div class="form-group">
					<label for="surgery_name">Name of Surgery Done</label>
					<textarea id="surgery_name" name="surgery_name"
						<?php echo $readonly ? 'readonly' : ''; ?>><?php echo $admission_data['surgery_name'] ?? ''; ?></textarea>
				</div>
				<div class="form-group">
					<label for="surgery_date">Date of Surgery</label>
					<input type="text" id="surgery_date" name="surgery_date"
						value="<?php echo $admission_data['surgery_date'] ?? ''; ?>"
						<?php echo $readonly ? 'readonly' : ''; ?>>
				</div>
			</div>
		</div>


		<div class="section">
			<div class="section-title">Consent for Treatment (उपचार हेतु सहमति)</div>

			<div class="consent-text">
				<div style="margin-top: 8px; padding: 8px; border: 1px solid #ccc;">
					<p><strong>Consent:</strong></p>
					<p class="hindi-consent">
						I <input type="text" name="consent_name" id="consent_name"
							value="<?php echo $patient_data['patient_name'] ?? ''; ?>"
							<?php echo $readonly ? 'readonly' : 'required'; ?>
						oninput="adjustInputWidth(this)" onload="adjustInputWidth(this)">
						hereby consent for admission to <span style="font-weight:bold"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></span>
						for medical/surgical treatment, including medical examination, diagnostic procedures, and
						treatment as deemed necessary by the medical staff. I also consent to the administration of
						medications and anesthesia if required for my treatment. In case my condition becomes critical,
						I authorize the hospital to perform emergency medical procedures as necessary for my care.</p>

					<p><strong>सहमति पत्र:</strong></p>
					<p class="hindi-consent">
						मैं <input type="text" name="consent_name" id="consent_name"
							value="<?php echo $patient_data['patient_name'] ?? ''; ?>"
							<?php echo $readonly ? 'readonly' : 'required'; ?>
						oninput="adjustInputWidth1(this)" onload="adjustInputWidth1(this)">
						नीचे हस्ताक्षर करने वाला/वाली, ऊपर लिखी बीमारी के ईलाज़ के लिए माहेश्वरी नर्सिंग होम में भर्ती
						होने एवं ईलाज़ के लिए मेरी स्वैच्छिक और सूचित सहमति प्रदान करता/करती हूँ। मुझे यह बता दिया है की
						किसी भी ईलाज़ के सफल होने की कोई गारंटी (प्रत्याभूति) नहीं होती है और ना हीं अस्पताल प्रशासन या
						डॉक्टर ने मुझे ऐसी कोई गारंटी दी है। ईलाज़ से अगर पूरा फ़ायदा/आराम नहीं होता है तो मैं किसी भी
						प्रकार का विवाद नहीं करूँगा एवं विवाद करने कर अस्पताल प्रशासन मेरे ख़िलाफ़ पुलिस में रिपोर्ट कर
						सकता है। मुझे ईलाज़ में आने वाले अनुमानित ख़र्चे (जो बड़ या घट सकता है) के बारे में बता दिया है
						जो कि में देने के लिए तैयार हूँ। इलाज़ के दोरान होने वाला खर्च मेरे स्वयं के लिए है एवं इसका
						उत्तरदायित्व मेरा है एवं खर्च के प्रबंधन एवं भुक्तान कि जवाबदारी मेरी स्वयं की रहेगी। मैं
						अस्पताल के सभी नियमो का पालन करूँगा ।
					</p>
				</div>
			</div>

			<?php if ($readonly && $admission_data) { ?>
			<div class="checkout-time-section">
				<div class="checkout-time-label">
					Check Out Time / छुट्टी का समय - दोपहर 2 बजे (इसके बाद अगले दिन का शुल्क लगेगा)
				</div>

			</div>
			<?php } ?>

			<div class="form-row">
				<div class="form-group">
					<label for="patient_signature">Patient/Guardian Signature (रोगी/अभिभावक हस्ताक्षर) *</label>
					<div class="signature-box">
						<div class="signature-label">Patient/Guardian (रोगी/अभिभावक) --
							<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>
						</div>
					</div>
					<!-- <div class="name-below-signature">
						<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>
				</div> -->
				<input type="hidden" id="patient_signature" name="patient_signature"
					value="<?php echo $patient_data['patient_name'] ?? ''; ?>"
					<?php echo $readonly ? '' : 'required'; ?>>
			</div>
			<div class="form-group">
				<label for="witness_signature">Witness Signature (गवाह हस्ताक्षर)</label>
				<div class="signature-box">
					<div class="signature-label">Witness (गवाह)</div>
				</div>
				<input type="text" class="witness-name-input" name="witness_name"
					placeholder="Witness Name (गवाह का नाम)"
					value="<?php echo $patient_data['witness_name'] ?? $patient_data['attendant_name'] ?? ''; ?>"
					<?php echo $readonly ? 'readonly' : ''; ?>>
				<input type="hidden" id="witness_signature" name="witness_signature">
			</div>
		</div>

		<?php if (!$readonly) { ?>
		<div class="form-group">
			<input type="checkbox" id="consent_checkbox" name="consent_checkbox" required>
			<label for="consent_checkbox" style="display: inline; margin-left: 5px;">I have read and understood all
				the terms and conditions mentioned above
				(मैंने उपरोक्त सभी नियम और शर्तें पढ़ी और समझी हैं) *</label>
		</div>
		<?php } ?>
		</div>

		<div class="action-buttons">
			<?php if ($readonly) { ?>
			<button type="button" onclick="window.print()" class="print-btn">
				<i class="fas fa-print"></i> Print Form
			</button>
			<button type="button"
				onclick="window.location.href='document_handover_form.php?id=<?php echo $_GET['id']; ?>'"
				class="print-btn" style="background: #dc3545;">
				<i class="fas fa-file-alt"></i> Document Handover Form
			</button>
			<button type="button" onclick="window.close()" class="submit-btn">Close</button>
			<?php } else { ?>
			<button type="submit" class="submit-btn">Submit Admission Form (भर्ती फॉर्म जमा करें)</button>
			<?php } ?>
		</div>
	</form>

	<script>
		// Function to dynamically adjust input width
		function adjustInputWidth(input) {
			// Create a temporary span to measure text width
			const span = document.createElement('span');
			span.style.visibility = 'hidden';
			span.style.position = 'absolute';
			span.style.fontSize = window.getComputedStyle(input).fontSize;
			span.style.fontFamily = window.getComputedStyle(input).fontFamily;
			span.style.fontWeight = window.getComputedStyle(input).fontWeight;
			span.textContent = input.value || input.placeholder;

			document.body.appendChild(span);
			const textWidth = span.offsetWidth;
			document.body.removeChild(span);

			// Set minimum width and add some padding
			const minWidth = 100;
			const padding = 20;
			const newWidth = Math.max(minWidth, textWidth + padding);

			input.style.width = newWidth + 'px';
		}

		function adjustInputWidth1(input) {
			// Create a temporary span to measure text width
			const span = document.createElement('span');
			span.style.visibility = 'hidden';
			span.style.position = 'absolute';
			span.style.fontSize = window.getComputedStyle(input).fontSize;
			span.style.fontFamily = window.getComputedStyle(input).fontFamily;
			span.style.fontWeight = window.getComputedStyle(input).fontWeight;
			span.textContent = input.value || input.placeholder;

			document.body.appendChild(span);
			const textWidth = span.offsetWidth;
			document.body.removeChild(span);

			// Set minimum width and add some padding
			const minWidth = 150;
			const padding = 20;
			const newWidth = Math.max(minWidth, textWidth + padding);

			input.style.width = newWidth + 'px';
		}

		// Function to update witness name with attendant information
		function updateWitnessName() {
			const attendantName = document.getElementById('attendant_name').value;
			const attendantRelation = document.getElementById('attendant_relation').value;
			const witnessField = document.querySelector('input[name="witness_name"]');

			if (attendantName && attendantRelation) {
				witnessField.value = attendantName + ' (' + attendantRelation + ')';
			} else if (attendantName) {
				witnessField.value = attendantName;
			}
		}

		// Auto-print if print parameter is passed
		if (new URLSearchParams(window.location.search).get('print') === '1') {
			window.print();
		}

		// Adjust width on page load for pre-filled values
		document.addEventListener('DOMContentLoaded', function() {
			const consentInput = document.getElementById('consent_name');
			if (consentInput && consentInput.value) {
				adjustInputWidth(consentInput);
			}

			// Auto-fill witness name with attendant name on page load
			updateWitnessName();

			// Add event listeners to attendant fields to auto-update witness name
			const attendantNameField = document.getElementById('attendant_name');
			const attendantRelationField = document.getElementById('attendant_relation');

			if (attendantNameField) {
				attendantNameField.addEventListener('input', updateWitnessName);
				attendantNameField.addEventListener('blur', updateWitnessName);
			}

			if (attendantRelationField) {
				attendantRelationField.addEventListener('input', updateWitnessName);
				attendantRelationField.addEventListener('blur', updateWitnessName);
			}
		});
	</script>
</body>

</html>