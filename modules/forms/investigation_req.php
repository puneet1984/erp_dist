<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'create')) {
	header('Location: /erp/modules/auth/unauthorized.php');
	exit;
}
// Check if user is a doctor or admin
// if (!isDoctor() && !isAdmin()) {
//     header('Location: /erp/modules/auth/unauthorized.php');
//     exit;
// }

// Handle form submission
if ('POST' === $_SERVER['REQUEST_METHOD']) {
	$patient_id = $_POST['patient_id'] ?? '';
	$doctor_id = $_POST['doctor_id'] ?? '';
	$clinical_notes = $_POST['clinical_notes'] ?? '';

	// Collect selected test types and their details
	$selected_tests = [];
	if (isset($_POST['test_types']) && is_array($_POST['test_types'])) {
		foreach ($_POST['test_types'] as $test_type) {
			$test_details = [
				'type' => $test_type,
			];

			// Handle specific test type details
			if ('ultrasound' === $test_type || 'blood_test' === $test_type) {
				$test_details['sub_type'] = $_POST[$test_type . '_sub_type'] ?? '';
				$test_details['tests'] = $_POST[$test_type . '_tests'] ?? [];
				$test_details['others_text'] = $_POST[$test_type . '_others_text'] ?? '';
			} else {
				$test_details['test_name'] = $_POST[$test_type . '_test_name'] ?? '';
			}

			$selected_tests[] = $test_details;
		}
	}

	// Here you can add your custom logic to handle the form data
	// Store $selected_tests in database or process as needed
	$success_message = 'Investigation requisition submitted successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Investigation Requisition Form</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>
	<?php include_once '../../includes/simple_action_bar.php'; ?>

	<!-- Add this after including action bar -->
	<script>
		const HOSPITAL_NAME = "<?php echo HOSPITAL_NAME; ?>";
	</script>

	<div class="container mt-4">
		<div class="card">
			<div class="card-header">
				<h2 class="mb-0">Investigation Requisition Form</h2>
			</div>
			<div class="card-body">
				<?php if (isset($success_message)) { ?>
					<div class="alert alert-success" id="successAlert">
						<?php echo $success_message; ?>
					</div>
					<script>
						setTimeout(() => {
							document.getElementById('successAlert')?.remove();
						}, 5000);
					</script>
				<?php } ?>

				<form method="POST" action="" class="needs-validation" novalidate>
					<!-- Patient Details Card -->
					<div class="card mb-3">
						<div class="card-header">
							<h5 class="mb-0">Patient Information</h5>
						</div>
						<div class="card-body">
							<div class="row mb-3">
								<div class="col-md-6">
									<label for="patient_id" class="form-label">Patient ID</label>
									<input type="text" class="form-control" id="patient_id" name="patient_id" required
										onblur="searchPatient()">
								</div>
								<div class="col-md-6">
									<label for="patientName" class="form-label">Name</label>
									<input type="text" class="form-control" id="patientName" readonly>
								</div>
							</div>
							<div class="row">
								<div class="col-md-3">
									<label for="patientAge" class="form-label">Age</label>
									<input type="text" class="form-control" id="patientAge" readonly>
								</div>
								<div class="col-md-3">
									<label for="patientGender" class="form-label">Gender</label>
									<input type="text" class="form-control" id="patientGender" readonly>
								</div>
								<div class="col-md-6">
									<label for="patientAddress" class="form-label">Address</label>
									<input type="text" class="form-control" id="patientAddress" readonly>
								</div>
							</div>
						</div>
					</div>

					<!-- Doctor and Test Details Card -->
					<div class="card mb-3">
						<div class="card-header">
							<h5 class="mb-0">Investigation Details</h5>
						</div>
						<div class="card-body">
							<!-- Doctor selection row remains same -->
							<div class="row mb-3">
								<div class="col-12">
									<label for="doctor_id" class="form-label">Referring Doctor</label>
									<select class="form-select" id="doctor_id" name="doctor_id" required>
										<option value="">Select Doctor</option>
										<?php
										$conn = getDbConnection();
										$stmt = $conn->prepare("SELECT d.doctor_id, d.name, d.registration_no, dp.name as department 
                                                              FROM doctors d 
                                                              LEFT JOIN departments dp ON d.dept_id = dp.dept_id 
                                                              WHERE d.status = 'ACTIVE'");
										$stmt->execute();
										$result = $stmt->get_result();
										while ($row = $result->fetch_assoc()) {
											echo "<option value='{$row['doctor_id']}' 
                                                  data-reg='{$row['registration_no']}'
                                                  data-dept='{$row['department']}'>{$row['name']} ({$row['department']})</option>";
										}
										?>
									</select>
								</div>
							</div>
							<div class="row mb-3">
								<!-- <div class="col-md-6">
									<label for="test_type" class="form-label">Investigation Type</label>
									<select class="form-select" id="test_type" name="test_type" required onchange="handleTestTypeChange(this.value)">
										<option value="">Select Test Type</option>
										<option value="blood_test">Blood Test</option>
										<option value="x_ray">X-Ray</option>
										<option value="mri">MRI</option>
										<option value="ct_scan">CT Scan</option>
										<option value="ultrasound">Ultrasound</option>
									</select>
								</div> -->
								<div class="col-md-6">
									<div id="subTypeContainer" style="display:none;">
										<label for="sub_type" class="form-label">Select Sub Type</label>
										<select class="form-select" id="sub_type" name="sub_type"
											onchange="handleSubTypeChange(this.value)">
											<option value="">Select Sub Type</option>
										</select>
									</div>
								</div>
							</div>

							<div class="row mb-3">
								<div class="col-12">
									<label class="form-label">Select Investigations</label>
									<div id="investigationTypes">
										<?php
										$investigations = [
											'ultrasound' => 'Ultrasound',
											'blood_test' => 'Blood Test',
											'x_ray' => 'X-Ray',
											'ct_scan' => 'CT Scan',
											'mri' => 'MRI Scan',
										];
										foreach ($investigations as $value => $label) { ?>
											<div class="form-check form-check-inline">
												<input class="form-check-input" type="checkbox" name="test_types[]"
													value="<?php echo $value; ?>" id="check_<?php echo $value; ?>"
													onchange="toggleInvestigation('<?php echo $value; ?>')">
												<label class="form-check-label" for="check_<?php echo $value; ?>">
													<?php echo $label; ?>
												</label>
											</div>
										<?php } ?>
									</div>
								</div>
							</div>

							<!-- Investigation Options Container -->
							<div id="investigationOptionsContainer">
								<!-- Sections will be created dynamically -->
							</div>

							<div class="row">
								<div class="col-12">
									<label for="clinical_notes" class="form-label">Clinical Notes</label>
									<textarea class="form-control" id="clinical_notes" name="clinical_notes"
										rows="4"></textarea>
								</div>
							</div>
						</div>
					</div>

					<div class="text-end">
						<button type="submit" class="btn btn-primary">Submit Requisition</button>
						<h6 style="color: red; text-align: center;">Please put A5 paper in the printer</h6>

					</div>
				</form>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script>
		function searchPatient() {
			const patientId = document.getElementById('patient_id').value;
			if (!patientId) return;

			$.ajax({
				url: '../../includes/helpers/get_patient.php',
				type: 'GET',
				data: {
					term: patientId,
					module: 'default'
				},
				success: function (response) {
					if (response.status === 'success') {
						$('#patientName').val(response.patient.name);
						$('#patientAge').val(response.patient.age);
						$('#patientGender').val(response.patient.gender);
						$('#patientAddress').val(response.patient.address);
					} else {
						alert('Patient not found');
						$('#patientName').val('');
						$('#patientAge').val('');
						$('#patientGender').val('');
						$('#patientAddress').val('');
					}
				},
				error: function () {
					alert('Error fetching patient details');
				}
			});
		}

		// Update the test options object and handlers
		const testTypeOptions = {
			ultrasound: {
				options: [{
					value: 'obstetric',
					label: 'Obstetric USG'
				},
				{
					value: 'abdomen',
					label: 'Abdomen USG'
				},
				{
					value: 'other',
					label: 'Other USG'
				}
				],
				subOptions: {
					obstetric: [
						'Early Pregnancy',
						'NT Scan',
						'Anomaly Scan',
						'Growth Scan',
						'Doppler Study',
						'Ovulation Study'
					],
					abdomen: [
						'Upper Abdomen',
						'Lower Abdomen',
						'Whole Abdomen',
						'KUB',
						'Pelvis'
					],
					other: [
						'Thyroid',
						'Breast',
						'Neck',
						'Soft Tissue',
						'Small Parts',
						'Others'
					]
				},
				indications: [
					"i. To diagnose intra-uterine and/or ectopic pregnancy and confirm viability.",
					"ii. Estimation of gestational age (dating).",
					"iii. Detection of number of fetuses and their chorionicity.",
					"iv. Suspected pregnancy with IUCD in-situ or suspected pregnancy following contraceptive failure/MTP failure.",
					"v. Vaginal bleeding/leaking.",
					"vi. Follow-up of cases of abortion.",
					"vii. Assessment of cervical canal and diameter of internal os.",
					"viii. Discrepancy between uterine size and period of amenorrhea.",
					"ix. Any suspected adenexal or uterine pathology/abnormality.",
					"x. Detection of chromosomal abnormalities, fetal structural defects and other abnormalities and their follow-up.",
					"xi. To evaluate fetal presentation and position.",
					"xii. Assessment of liquor amnii.",
					"xiii. Preterm labor / preterm premature rupture of membranes.",
					"xiv. Evaluation of placental position, thickness, grading and abnormalities (placenta praevia, retro placental hemorrhage, abnormal adherence etc.).",
					"xv. Evaluation of umbilical cord – presentation, insertion, nuchal encirclement, number of vessels and presence of true knot.",
					"xvi. Evaluation of previous Caesarean Section scars.",
					"xvii. Evaluation of fetal growth parameters, fetal weight and fetal well being.",
					"xviii. Color flow mapping and duplex Doppler studies.",
					"xix. Ultrasound guided procedures such as medical termination of pregnancy, external cephalic version etc. and their follow-up.",
					"xx. Adjunct to diagnostic and therapeutic invasive interventions such as chorionic villus sampling (CVS), amniocenteses, fetal blood sampling, fetal skin biopsy, amnio-infusion, intrauterine infusion, placement of shunts etc.",
					"xxi. Observation of intra-partum events.",
					"xxii. Medical/surgical conditions complicating pregnancy.",
					"xxiii. Research/scientific studies in recognized institutions"
				]
			},
			blood_test: {
				options: [{
					value: 'routine',
					label: 'Routine Tests'
				},
				{
					value: 'specific',
					label: 'Specific Tests'
				}
				],
				subOptions: {
					routine: [
						'Complete Blood Count',
						'Blood Sugar',
						'Lipid Profile',
						'Liver Function Test (LFT)',
						'Renal Function Test (RFT)',
						'Others'
					],
					specific: [
						'ANC profile',
						'Pre Op profile',
						'Thyroid Profile',
						'Hormone Analysis',
						'Tumor Markers',
						'Cardiac Markers',
						'Coagulation Profile'
					]
				}
			},
			x_ray: {
				needsTestName: true,
				label: 'X-Ray'
			},
			ct_scan: {
				needsTestName: true,
				label: 'CT Scan'
			},
			mri: {
				needsTestName: true,
				label: 'MRI'
			}
		};

		function toggleInvestigation(testType) {
			let sectionId = `${testType}_section`;
			let container = document.getElementById('investigationOptionsContainer');
			let existingSection = document.getElementById(sectionId);
			let checkbox = document.getElementById(`check_${testType}`);

			if (checkbox.checked) {
				if (!existingSection) {
					// Create section if it doesn't exist
					let section = createInvestigationSection(testType);
					container.appendChild(section);
				} else {
					existingSection.style.display = 'block';
				}
			} else if (existingSection) {
				existingSection.remove();
			}
		}
		function handleSubTypeSelection(testType, value) {
			const optionsContainer = document.getElementById(`${testType}_options`);
			if (!optionsContainer) return;

			optionsContainer.innerHTML = '';

			if (testTypeOptions[testType]?.subOptions[value]) {
				const options = testTypeOptions[testType].subOptions[value];
				const div = document.createElement('div');
				div.className = 'mt-3';

				// Add TVS checkbox for obstetric USG
				if (testType === 'ultrasound' && value === 'obstetric') {
					const tvsDiv = document.createElement('div');
					tvsDiv.className = 'form-check mb-3';
					tvsDiv.innerHTML = `
						<input class="form-check-input" type="checkbox" 
							name="${testType}_tvs" 
							value="tvs" 
							id="${testType}_tvs">
						<label class="form-check-label" for="${testType}_tvs">
							TVS (Trans-vaginal Sonography)
						</label>`;
					div.appendChild(tvsDiv);
				}

				const label = document.createElement('label');
				label.className = 'form-label';
				label.textContent = 'Select Required Tests';
				div.appendChild(label);

				options.forEach(opt => {
					const checkboxDiv = document.createElement('div');
					checkboxDiv.className = 'form-check';

					if (opt === 'Others') {
						// Create a checkbox with text input for Others
						checkboxDiv.innerHTML = `
						<div class="d-flex align-items-center">
							<div class="me-2">
								<input class="form-check-input" type="checkbox" 
									name="${testType}_tests[]" 
									value="others" 
									id="${testType}_others"
									onchange="toggleOthersInput('${testType}_others_input', this.checked)">
								<label class="form-check-label" for="${testType}_others">
									${opt}
								</label>
							</div>
							<div>
								<input type="text" 
									class="form-control form-control-sm" 
									id="${testType}_others_input"
									name="${testType}_others_text"
									style="display:none;" 
									placeholder="Please specify">
							</div>
						</div>`;
					} else {
						// Regular checkbox for other options
						checkboxDiv.innerHTML = `
						<input class="form-check-input" type="checkbox" 
							name="${testType}_tests[]" 
							value="${opt}" 
							id="${testType}_${opt.replace(/\s+/g, '_')}">
						<label class="form-check-label" for="${testType}_${opt.replace(/\s+/g, '_')}">
							${opt}
						</label>`;
					}
					div.appendChild(checkboxDiv);
				});

				optionsContainer.appendChild(div);
			}
		}

		function toggleOthersInput(inputId, show) {
			const input = document.getElementById(inputId);
			if (input) {
				input.style.display = show ? 'block' : 'none';
				input.required = show;
				if (!show) input.value = '';
			}
		}

		function createInvestigationSection(testType) {
			let section = document.createElement('div');
			section.id = `${testType}_section`;
			section.className = 'investigation-section card mb-3';

			let html = `<div class="card-body">
			<h6 class="border-bottom pb-2">${testType.charAt(0).toUpperCase() + testType.slice(1)} Details</h6>`;

			if (testTypeOptions[testType].needsTestName) {
				// For X-Ray, CT Scan and MRI
				html += `
				<div class="form-group mb-3">
					<label class="form-label">Enter ${testTypeOptions[testType].label} Test Name/Region</label>
					<input type="text" class="form-control" name="${testType}_test_name" 
						   placeholder="Enter ${testTypeOptions[testType].label} details" required>
				</div>`;
			} else {
				// For Ultrasound and Blood tests
				html += `
				<div class="form-group mb-3">
					<label class="form-label">Select Type</label>
					<select class="form-select" onchange="handleSubTypeSelection('${testType}', this.value)">
						<option value="">Select ${testType.charAt(0).toUpperCase() + testType.slice(1)} Type</option>
						${getOptionsForTestType(testType)}
					</select>
				</div>`;

				// Add indication dropdown for ultrasound
				if (testType === 'ultrasound') {
					html += `
					<div class="form-group mb-3">
						<label class="form-label">Clinical Indication</label>
						<select class="form-select" name="${testType}_indication" id="${testType}_indication">
							<option value="">Select Clinical Indication</option>
							${getIndicationsForTestType(testType)}
						</select>
					</div>`;
				}

				html += `<div id="${testType}_options"></div>`;
			}

			html += `</div>`;
			section.innerHTML = html;
			return section;
		}

		function getOptionsForTestType(testType) {
			if (!testTypeOptions[testType]?.options) return '';
			return testTypeOptions[testType].options
				.map(opt => `<option value="${opt.value}">${opt.label}</option>`)
				.join('');
		}

		function getIndicationsForTestType(testType) {
			if (!testTypeOptions[testType]?.indications) return '';
			return testTypeOptions[testType].indications
				.map(indication => `<option value="${indication}">${indication}</option>`)
				.join('');
		}

		// Form validation
		(function () {
			'use strict';
			window.addEventListener('load', function () {
				var forms = document.getElementsByClassName('needs-validation');
				Array.prototype.filter.call(forms, function (form) {
					form.addEventListener('submit', function (event) {
						event.preventDefault();
						if (form.checkValidity()) {
							// Create printable content
							const printContent = generatePrintableContent();

							// Create a new window for print preview
							const printWindow = window.open('', '_blank');
							printWindow.document.write(`
							<!DOCTYPE html>
							<html>
							<head>
								<title>Investigation Requisition</title>
								<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
								<style>
									@page {
										// size: A5 landscape;
										size: A5;
										margin: 5mm;
									}
									* {
										margin: 0;
										padding: 0;
										box-sizing: border-box;
									}
									html, body {
										height: 210mm;
										width: 148mm;
										// width: 210mm;
										// height: 148mm;
									}
									@media print {
										html, body {
										height: 210mm;
										width: 148mm;
											// width: 210mm;
											// height: 148mm;
										}
										h2, h4 {
											margin: 0;
											text-align: center;
											margin-bottom: 10px;
										}
										.no-print {
											display: none !important;
										}
										.content {
											padding: 5mm;
											// height: 138mm; /* Account for margins */
											height: 200mm; /* Account for margins */

											overflow: hidden;
										}
										.content * {
											page-break-inside: avoid;
										}
										.btn {
											display: none !important;
										}
									}
									.content {
										padding: 5mm;
										// height: 138mm;
										height: 200mm;

									}
									.print-buttons {
										position: fixed;
										bottom: 20px;
										right: 20px;
										z-index: 1000;
									}
								</style>
							</head>
							<body>
								<div class="content">
									${printContent}
							   
								</div>
								<div class="print-buttons no-print">
									<button onclick="window.print();window.close();" class="btn btn-primary">Print & Close</button>
									<button onclick="window.close();" class="btn btn-secondary">Cancel</button>
								</div>
							</body>
							</html>
						`);

							// Submit the form after printing
							printWindow.document.close();
							printWindow.onafterprint = function () {
								form.submit();
							};
						}
						form.classList.add('was-validated');
					}, false);
				});
			}, false);
		})();

		function generatePrintableContent() {
			const patient = {
				id: document.getElementById('patient_id').value,
				name: document.getElementById('patientName').value,
				age: document.getElementById('patientAge').value,
				gender: document.getElementById('patientGender').value,
				address: document.getElementById('patientAddress').value
			};

			const doctor = document.getElementById('doctor_id');
			const doctorName = doctor.options[doctor.selectedIndex].text;

			let investigations = [];
			document.querySelectorAll('#investigationOptionsContainer .investigation-section').forEach(section => {
				const testType = section.id.replace('_section', '');
				const details = collectTestDetails(section, testType);
				investigations.push(details);
			});

			return `
			<div class="header">
				<h2>${HOSPITAL_NAME}</h2>
				<h4>Investigation Requisition Form</h4>
			</div>
			<div class="row mb-4">
				<div class="col-6">
					<p><strong>Name:</strong> ${patient.name}</p>
					<p><strong>Age/Gender:</strong> ${patient.age}/${patient.gender}</p>
				</div>
				<div class="col-6 text-end">
					<p><strong>Date:</strong> ${new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: '2-digit', year: 'numeric' }).split(',')[0]}</p>
					<p><strong>Doctor:</strong> ${doctorName}</p>
				</div>
			</div>
			<div class="investigations mb-4">
				<h5>Investigations Required:</h5>
				${investigations.map(inv => inv.html).join('')}
			</div>
			<div class="mt-4">
				<p><strong>Clinical Notes:</strong></p>
				<p>${document.getElementById('clinical_notes').value || ''}</p>
			</div>
			<div class="mt-5 text-end">
				<p>Doctor's Signature</p>
			</div>
		`;
		}

		function collectTestDetails(section, testType) {
			if (testTypeOptions[testType].needsTestName) {
				const testName = section.querySelector(`input[name="${testType}_test_name"]`).value;
				return {
					type: testType,
					html: `<div class="test-row">
					<strong>${testTypeOptions[testType].label}:</strong>
					<span class="test-details">${testName}</span>
				</div>`
				};
			} else {
				const subType = section.querySelector('select').value;
				const selectedTests = Array.from(section.querySelectorAll('input[type="checkbox"]:checked'))
					.map(cb => cb.nextElementSibling.textContent.trim())
					.filter(text => text !== 'TVS (Trans-vaginal Sonography)'); // Filter out TVS from regular tests

				const othersInput = section.querySelector(`#${testType}_others_input`);
				if (othersInput && othersInput.style.display !== 'none') {
					selectedTests.push(`Others: ${othersInput.value}`);
				}

				// Check if TVS is selected for obstetric USG
				const tvsChecked = testType === 'ultrasound' &&
					subType === 'obstetric' &&
					section.querySelector('#ultrasound_tvs')?.checked;

				// Get indication for ultrasound
				let indicationText = '';
				if (testType === 'ultrasound') {
					const indicationSelect = section.querySelector(`#${testType}_indication`);
					if (indicationSelect && indicationSelect.value) {
						indicationText = `<strong>Indication:</strong> ${indicationSelect.value}`;
					}
				}

				return {
					type: testType,
					html: `<div class="test-row">
					<strong>${testType.charAt(0).toUpperCase() + testType.slice(1)}:</strong>
					<span class="test-details">
						${subType}${tvsChecked ? ' (TVS)' : ''} - ${selectedTests.join(', ')}<p>${indicationText}</p>
					</span>
				</div>`
				};
			}
		}
	</script>
</body>

</html>


<!-- <p><strong>Patient ID:</strong> ${patient.id}</p> -->