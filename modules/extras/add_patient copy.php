<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Session checks
if (!isset($_SESSION['user_id'])) {
    header('Location: /erp/modules/auth/login.php');
    exit;
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $conn = getDbConnection();

    // Sanitize and validate inputs
    $firstName = capitalizeWords(sanitizeInput($_POST['first_name']));
    $middleName = capitalizeWords(sanitizeInput($_POST['middle_name']));
    $relation = sanitizeInput($_POST['relation']);
    $lastName = capitalizeWords(sanitizeInput($_POST['last_name']));

    // Format full name
    $name = $firstName;
    if ($middleName) {
        $name .= " {$relation} {$middleName}";
    }
    if ($lastName) {
        $name .= " {$lastName}";
    }

    $age = sanitizeInput($_POST['age']);
    $gender = sanitizeInput($_POST['gender']);
    $contact = sanitizeInput($_POST['contact']);
    $address = sanitizeInput($_POST['address']);
    $tehsil = sanitizeInput($_POST['tehsil'] ?? '');
    $city = capitalizeWords(sanitizeInput($_POST['city']));
    $occupation = sanitizeInput($_POST['occupation']);
    $aadhar = sanitizeInput($_POST['aadhar_number'] ?? '');
    $abha = sanitizeInput($_POST['abha_id'] ?? '');
    $ayushmaan = sanitizeInput($_POST['ayushmaan_id'] ?? '');

    // Combine address and tehsil if tehsil is provided
    if (!empty($tehsil)) {
        $address = trim($address).(!empty($address) ? ', ' : '').'Tehsil: '.$tehsil;
    }

    // Validation checks
    $errors = [];
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    if (!validateAge($age)) {
        $errors[] = 'Invalid age';
    }
    if (!validateMobile($contact)) {
        $errors[] = 'Invalid mobile number';
    }
    if (!empty($aadhar) && !validateAadhar($aadhar)) {
        $errors[] = 'Invalid Aadhar number';
    }
    if (!empty($abha) && !validateAbha($abha)) {
        $errors[] = 'Invalid ABHA ID';
    }
    if (!empty($ayushmaan) && !validateAyushmaan($ayushmaan)) {
        $errors[] = 'Invalid Ayushmaan ID';
    }

    if (!empty($errors)) {
        echo json_encode(['status' => 'error', 'message' => implode(', ', $errors)]);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get next ID
        $stmt = $conn->prepare('SELECT MAX(patient_id) as max_id FROM patients');
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $next_id = ($result['max_id'] ?? 0) + 1;

        $stmt = $conn->prepare('INSERT INTO patients (
            patient_id, name, age, gender, occupation, contact, address, city, state,
            aadhar_number, abha_id, ayushmaan_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // Fix: Explicitly set null values and correct type string
        $aadhar_val = !empty($aadhar) ? $aadhar : null;
        $abha_val = !empty($abha) ? $abha : null;
        $ayushmaan_val = !empty($ayushmaan) ? $ayushmaan : null;

        $stmt->bind_param(
            'isssssssssss', // Fix: Changed type string to match parameters
            $next_id,
            $name,
            $age,
            $gender,
            $occupation,
            $contact,
            $address,
            $city,
            $_POST['state'],
            $aadhar_val,
            $abha_val,
            $ayushmaan_val
        );

        if ($stmt->execute()) {
            // Insert SMS log
            $sms_stmt = $conn->prepare('INSERT INTO sms_logs (
                patient_id, mobile_number, message, status, sms_type, message_type
            ) VALUES (?, ?, ?, ?, ?, ?)');

            // Get registration template
            $template_name = 'patient_registration_H'; // Default to Hindi template
            $template_stmt = $conn->prepare("SELECT template_text FROM sms_templates WHERE template_name = ?");
            $template_stmt->bind_param('s', $template_name);
            $template_stmt->execute();
            $template_result = $template_stmt->get_result()->fetch_assoc();
            
            $message = $template_result ? str_replace(
                ['{patient_name}', '{patient_id}'],
                [$name, 'MNH'.str_pad($next_id, 8, '0', STR_PAD_LEFT)],
                $template_result['template_text']
            ) : '';

            $status = 'PENDING';
            $sms_type = 'REGISTRATION';
            $message_type = 'PENDING';

            $sms_stmt->bind_param(
                'isssss',
                $next_id,
                $contact,
                $message,
                $status,
                $sms_type,
                $message_type
            );
            $sms_stmt->execute();

            $conn->commit();
            echo json_encode([
                'status' => 'success',
                'patient_id' => $next_id,
            ]);
            exit;
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get next available ID for display
$conn = getDbConnection();
$stmt = $conn->prepare('SELECT MAX(patient_id) as max_id FROM patients');
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$initial_next_id = ($result['max_id'] ?? 0) + 1;
?>
<!DOCTYPE html>
<html>

<head>
	<title>Add New Patient</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<!-- Add Select2 CSS -->
	<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
	<style>
		body {
			margin: 0;
			padding: 20px;
			background: #f5f5f5;
		}

		.container {
			background: #fff;
			padding: 20px;
			border-radius: 4px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}

		.select2-container {
			width: 100% !important;
		}

		.select2-container--default .select2-selection--single {
			height: 38px;
			padding: 5px 0;
			border: 1px solid #ccc;
			border-radius: 4px;
		}

		/* Add notification styles */
		@keyframes slideIn {
			from {
				transform: translateY(-100%);
				opacity: 0;
			}

			to {
				transform: translateY(0);
				opacity: 1;
			}
		}

		@keyframes slideOut {
			from {
				transform: translateY(0);
				opacity: 1;
			}

			to {
				transform: translateY(-100%);
				opacity: 0;
			}
		}

		.notification {
			position: fixed;
			top: 20px;
			left: 50%;
			transform: translateX(-50%);
			padding: 15px 25px;
			border-radius: 4px;
			background-color: #4CAF50;
			color: white;
			z-index: 1000;
			box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
			animation: slideIn 0.5s ease-out;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Add New Patient</h2>
		<form id="patientForm" onsubmit="submitForm(event)">
			<div class="form-section">
				<div class="form-row">
					<div class="form-group">
						<label>First Name:</label>
						<input type="text" name="first_name" required maxlength="30">
					</div>
					<div class="form-group" style="display: flex; gap: 10px;">
						<div>
							<label>Relation</label>
							<select name="relation" onchange="updateGender(this.value)">
								<option value="S/O">S/O</option>
								<option value="W/O">W/O</option>
								<option value="D/O">D/O</option>
							</select>
						</div>
						<div>
							<label>Name:</label>
							<input type="text" name="middle_name" maxlength="30" required>
						</div>
					</div>
					<div class="form-group">
						<label>Last Name:</label>
						<input type="text" name="last_name" maxlength="30">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>Date of Birth:</label>
						<input type="date" name="dob" onchange="calculateAge(this.value)">
					</div>
					<div class="form-group">
						<label>Age:</label>
						<input type="number" name="age" required min="0" max="110" step="0.1"
							title="Enter age (use decimal for months, e.g., 1.5 for 1.5 years)" placeholder="e.g., 1.5">
					</div>
					<div class="form-group">
						<label>Gender:</label>
						<select name="gender" required>
							<option value="">Select</option>
							<option value="M">Male</option>
							<option value="F">Female</option>
							<option value="O">Others</option>
						</select>
					</div>
					<div class="form-group">
						<label>Occupation:</label>
						<select name="occupation">
							<option value="">Select</option>
							<option value="FARMER">Farmer</option>
							<option value="LABOUR_WORK">Labour Work</option>
							<option value="DESK_JOB">Desk Job</option>
							<option value="HOUSE_MAKER">House Maker</option>
							<option value="UNEMPLOYED">Unemployed</option>
							<option value="STUDENT">Student</option>
							<option value="RETIRED">Retired</option>
						</select>
					</div>
					<div class="form-group">
						<label>Mobile Number:</label>
						<input type="tel" name="contact" required pattern="[6-9][0-9]{9}" maxlength="10">
					</div>
				</div>



				<div class="form-group">
					<label>Address:</label>
					<textarea name="address"></textarea>
				</div>

				<div class="form-row">
					<div class="form-group third">
						<label>State:</label>
						<select name="state" id="state" class="select2-dropdown">
							<option value="">Select State</option>
						</select>
					</div>
					<div class="form-group third">
						<label>District:</label>
						<select name="city" id="city" class="select2-dropdown">
							<option value="">Select District</option>
						</select>
					</div>
					<div class="form-group third">
						<label>Tehsil:</label>
						<select name="tehsil" id="tehsil" class="select2-dropdown">
							<option value="">Select Tehsil</option>
						</select>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group third">
						<label>Aadhar Number: (Optional)</label>
						<input type="text" name="aadhar_number" maxlength="12" pattern="\d{12}"
							title="12 digit Aadhar number" placeholder="12 digit Aadhar number">
					</div>
					<div class="form-group third">
						<label>ABHA ID: (Optional)</label>
						<input type="text" name="abha_id" maxlength="14" pattern="\d{14}" title="14 digit ABHA ID"
							placeholder="14 digit ABHA ID">
					</div>
					<div class="form-group third">
						<label>PM-JAY ID: (Optional)</label>
						<input type="text" name="ayushmaan_id" maxlength="20" pattern="[A-Za-z0-9]{8,20}"
							title="PM-JAY ID (alphanumeric, 8-20 characters)" placeholder="Enter PM-JAY ID"
							style="text-transform: uppercase;">
					</div>
				</div>
			</div>

			<div class="form-row">
				<button type="submit">Register Patient</button>
				<div class="next-id-display">
					<label>Next Patient ID:</label>
					<span
						id="nextPatientId">MNH<?php echo str_pad($initial_next_id, 8, '0', STR_PAD_LEFT); ?></span>
				</div>
			</div>
		</form>
	</div>

	<!-- Add jQuery and Select2 -->
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
	<script>
		function calculateAge(dob) {
			if (!dob) return;

			const birthDate = new Date(dob);
			const today = new Date();

			// Check if birthdate is in future
			if (birthDate > today) {
				alert('Date of birth cannot be in future');
				document.querySelector('input[name="dob"]').value = '';
				document.querySelector('input[name="age"]').value = '';
				return;
			}

			// Calculate total days between dates
			const diffTime = today - birthDate;
			const totalDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
			const totalMonths = Math.floor(totalDays / 30.44); // Average month length
			const totalYears = totalMonths / 12;

			let decimalAge;
			if (totalYears < 5) {
				// If age is less than 5 years, calculate in years using days
				decimalAge = Math.round((totalDays / 365) * 10) / 10;
			} else {
				// For ages 5 years and above, round to whole number
				decimalAge = Math.round(totalYears);
			}

			document.querySelector('input[name="age"]').value = decimalAge;
		}

		function updateGender(relation) {
			const genderSelect = document.querySelector('select[name="gender"]');
			if (relation === 'S/O') {
				genderSelect.value = 'M';
			} else if (relation === 'W/O' || relation === 'D/O') {
				genderSelect.value = 'F';
			}
		}

		function submitForm(e) {
			e.preventDefault();
			const form = new FormData(e.target);
			const patientName = [
				form.get('first_name'),
				form.get('relation'),
				form.get('middle_name'),
				form.get('last_name')
			].filter(Boolean).join(' ');

			fetch('add_patient.php', {
					method: 'POST',
					body: form
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						showNotification(
							`Patient "${patientName}" registered successfully with ID: MNH${String(data.patient_id).padStart(8, '0')}`
							);
						e.target.reset();
						updateGender('S/O');
						document.getElementById('nextPatientId').textContent =
							'MNH' + String(data.patient_id + 1).padStart(8, '0');
					} else {
						alert('Error: ' + data.message);
					}
				})
				.catch(error => {
					alert('Error: ' + error.message);
				});
		}

		// Add notification helper function
		function showNotification(message) {
			const notification = document.createElement('div');
			notification.className = 'notification';
			notification.textContent = message;
			document.body.appendChild(notification);

			// Remove notification after 4 seconds
			setTimeout(() => {
				notification.style.animation = 'slideOut 0.5s ease-in';
				setTimeout(() => {
					document.body.removeChild(notification);
				}, 500);
			}, 4000);
		}

		// Initialize Select2 and location dropdowns
		$(document).ready(function() {
			$('.select2-dropdown').select2({
				placeholder: "Select...",
				allowClear: true,
				width: '100%'
			});

			// Store default values
			const defaultState = 'Madhya Pradesh';
			const defaultCity = 'Dewas';
			const defaultTehsil = 'Dewas';

			function setTehsilOptions(district, defaultValue) {
				const tehsilSelect = $('#tehsil');
				tehsilSelect.empty().append(new Option('', '', false, false));

				$.ajax({
					url: '../../includes/helpers/location_helper.php',
					data: {
						type: 'tehsils',
						parent: district
					},
					dataType: 'json',
					success: function(data) {
						$.each(data, function(i, tehsil) {
							var selected = (tehsil.text === defaultValue);
							var option = new Option(tehsil.text, tehsil.text, selected, selected);
							tehsilSelect.append(option);
						});
						tehsilSelect.trigger('change');
					}
				});
			}

			// Load states and districts
			$.ajax({
				url: '../../includes/helpers/location_helper.php',
				data: {
					type: 'states'
				},
				dataType: 'json',
				success: function(data) {
					const stateSelect = $('#state');
					stateSelect.empty().append(new Option('', '', false, false));

					$.each(data, function(i, state) {
						var selected = (state.text === defaultState);
						var option = new Option(state.text, state.text, selected, selected);
						stateSelect.append(option);
					});

					// Load districts after setting state
					$.ajax({
						url: '../../includes/helpers/location_helper.php',
						data: {
							type: 'districts'
						},
						dataType: 'json',
						success: function(data) {
							const citySelect = $('#city');
							citySelect.empty().append(new Option('', '', false, false));

							$.each(data, function(i, district) {
								var selected = (district.text === defaultCity);
								var option = new Option(district.text, district.text,
									selected, selected);
								citySelect.append(option);
							});

							setTehsilOptions(defaultCity, defaultTehsil);
						}
					});
				}
			});

			// State change handler
			$('#state').on('change', function() {
				var selectedState = $(this).val();
				var citySelect = $('#city');
				var tehsilSelect = $('#tehsil');

				if (selectedState && selectedState !== 'Madhya Pradesh') {
					citySelect.val('Others').trigger('change');
					tehsilSelect.val('Others').trigger('change');
					citySelect.prop('disabled', true);
					tehsilSelect.prop('disabled', true);
				} else {
					citySelect.prop('disabled', false);
					tehsilSelect.prop('disabled', false);
					if (citySelect.prop('disabled')) {
						citySelect.val(defaultCity).trigger('change');
					}
				}
			});

			// District change handler
			$('#city').on('change', function() {
				if (!$(this).prop('disabled')) {
					var selectedDistrict = $(this).val() || defaultCity;
					setTehsilOptions(selectedDistrict, defaultTehsil);
				}
			});
		});

		// Set initial gender on page load
		updateGender(document.querySelector('select[name="relation"]').value);
	</script>
</body>

</html>