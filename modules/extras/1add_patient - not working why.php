<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/services/AlertService.php';
// Session checks
if (!isset($_SESSION['user_id'])) {
    header('Location: /erp/modules/auth/login.php');
    exit;
}

function checkDuplicate($field, $value, $conn)
{
    if (empty($value)) {
        return false;
    }

    // Special handling for name+contact combo
    if ('name_contact' === $field) {
        $stmt = $conn->prepare('SELECT patient_id, name, age, gender, contact, 
							   aadhar_number, abha_id, ayushmaan_id 
							   FROM patients 
							   WHERE name = ? AND contact = ?');
        $stmt->bind_param('ss', $value['name'], $value['contact']);
    } else {
        $stmt = $conn->prepare("SELECT patient_id, name, age, gender, contact, 
							   aadhar_number, abha_id, ayushmaan_id 
							   FROM patients 
							   WHERE {$field} = ?");
        $stmt->bind_param('s', $value);
    }

    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $conn = getDbConnection();
    
    try {
        // Set shorter timeout
        $conn->query("SET SESSION innodb_lock_wait_timeout=5");
        $conn->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
        
        $conn->begin_transaction();

        // Try getting next ID without NOWAIT first
        $stmt = $conn->prepare('SELECT MAX(patient_id) as max_id FROM patients');
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $next_id = ($result['max_id'] ?? 0) + 1;
        
        // Lock the range we're going to insert into
        $stmt = $conn->prepare('SELECT patient_id FROM patients WHERE patient_id = ? FOR UPDATE');
        $stmt->bind_param('i', $next_id);
        $stmt->execute();

        $errors = [];

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
        // Combine address and tehsil if tehsil is provided
        if (!empty($tehsil)) {
            $address = trim($address).(!empty($address) ? ', ' : '').'Tehsil: '.$tehsil;
        }

        // Additional fields
        $occupation = $_POST['occupation'];
        $payment_type_id = (int) $_POST['payment_type_id']; // Changed from payment_type
        $aadhar = sanitizeInput($_POST['aadhar_number'] ?? '');
        $abha = sanitizeInput($_POST['abha_id'] ?? '');
        $ayushmaan = sanitizeInput($_POST['ayushmaan_id'] ?? '');
        $company_name = (2 === $payment_type_id) ? capitalizeWords(sanitizeInput($_POST['company_name'])) : null; // 2 is COMPANY_CREDIT

        // Validation checks
        if (empty($name)) {
            $errors[] = 'Name is required';
        }

        if (!validateAge($age)) {
            $errors[] = 'Invalid age';
        }

        if (!validateMobile($contact)) {
            $errors[] = 'Invalid mobile number (must be 10 digits starting with 6-9)';
        }

        // Additional validations
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

        // Check for duplicates
        $duplicate = false;
        $duplicate_data = [];

        // Check name + contact combination first
        if ($found = checkDuplicate('name_contact', ['name' => $name, 'contact' => $contact], $conn)) {
            $duplicate = true;
            $duplicate_data['name_contact'] = $found;
        }

        if (!empty($aadhar)) {
            if ($found = checkDuplicate('aadhar_number', $aadhar, $conn)) {
                $duplicate = true;
                $duplicate_data['aadhar'] = $found;
            }
        }

        if (!empty($abha)) {
            if ($found = checkDuplicate('abha_id', $abha, $conn)) {
                $duplicate = true;
                $duplicate_data['abha'] = $found;
            }
        }

        if (!empty($ayushmaan)) {
            if ($found = checkDuplicate('ayushmaan_id', $ayushmaan, $conn)) {
                $duplicate = true;
                $duplicate_data['ayushmaan'] = $found;
            }
        }

        if ($duplicate) {
            echo json_encode([
                'status' => 'duplicate',
                'message' => 'Duplicate records found',
                'data' => $duplicate_data,
            ]);
            exit;
        }

        // Prepare empty values to pass by reference
        $aadhar_val = !empty($aadhar) ? $aadhar : null;
        $abha_val = !empty($abha) ? $abha : null;
        $ayushmaan_val = !empty($ayushmaan) ? $ayushmaan : null;

        $stmt = $conn->prepare('INSERT INTO patients (
            patient_id, name, age, gender, occupation, contact, address, city, state,
            payment_type_id, company_name, aadhar_number, abha_id, ayushmaan_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $stmt->bind_param(
            'isisssssssisss',
            $next_id,
            $name,
            $age,
            $gender,
            $occupation,
            $contact,
            $address,  // Now contains combined address and tehsil
            $city,    // New field
            $_POST['state'],   // New field
            $payment_type_id,
            $company_name,
            $aadhar_val,
            $abha_val,
            $ayushmaan_val
        );

        if ($stmt->execute()) {
            try {
                // Get next ID directly after insert without additional query
                $next_available_id = $next_id + 1;
                
                $conn->commit();

                echo json_encode([
                    'status' => 'success',
                    'patient_id' => $next_id,
                    'next_patient_id' => $next_available_id
                ]);
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception('Registration failed: ' . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        $conn->rollback();
        $errorMsg = 'System is busy. Please try again in a moment.';
        
        echo json_encode([
            'status' => 'error',
            'message' => $errorMsg
        ]);
    }
    exit;
}

$conn = getDbConnection();
$stmt = $conn->prepare('SELECT MAX(patient_id) as max_id FROM patients');
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$initial_next_id = ($result['max_id'] ?? 0) + 1; // Store as number

// Modify the company dropdown to fetch from company_accounts table
$companies = $conn->query("SELECT company_name, status 
                          FROM company_accounts 
                          WHERE status = 'ACTIVE' 
                          ORDER BY company_name");

// After the company query, add insurance providers query
$insurance_providers = $conn->query('SELECT TPA_name, insurance_comp_name 
                                   FROM insurance_providers 
                                   ORDER BY TPA_name, insurance_comp_name');

// Get states from helper
$states = getStates();
?>
<!DOCTYPE html>
<html>

<head>
	<title>Add New Patient</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<!-- Replace Select2 CDN with local file -->
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
	<style>
		body {
			margin: 0;
			padding: 20px;
			background: #f5f5f5;
		}

		/* Main content area */
		.container {
			background: #fff;
			padding: 20px;
			border-radius: 4px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}

		/* Select2 custom styling */
		.select2-container {
			width: 100% !important;
		}

		.select2-container--default .select2-selection--single {
			height: 38px;
			padding: 5px 0;
			border: 1px solid #ccc;
			border-radius: 4px;
		}

		.select2-container--default .select2-selection--single .select2-selection__arrow {
			height: 36px;
		}

		/* Add after existing styles */
		.modal {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.5);
			z-index: 1000;
		}

		.duplicate-action-btn {
			padding: 4px 8px;
			border: none;
			border-radius: 3px;
			margin: 2px;
			cursor: pointer;
			font-size: 12px;
		}

		.duplicate-action-btn.bill {
			background-color: #4CAF50;
			color: white;
		}

		.duplicate-action-btn.bill:hover {
			background-color: #45a049;
		}

		.duplicate-action-btn.pharmacy {
			background-color: #2196F3;
			color: white;
		}

		.duplicate-action-btn.pharmacy:hover {
			background-color: #1e87dc;
		}

		.duplicate-action-btn.view {
			background-color: #607d8b;
			color: white;
		}

		.duplicate-action-btn.view:hover {
			background-color: #546e7a;
		}

		/* Continue with existing styles */
		.modal-content {
			position: relative;
			background: #fff;
			margin: 5% auto;
			padding: 20px;
			width: 70%;
			max-width: 700px;
			border-radius: 5px;
			box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
		}

		.duplicate-table {
			width: 100%;
			border-collapse: collapse;
			margin: 10px 0;
		}

		.duplicate-table th,
		.duplicate-table td {
			padding: 8px;
			border: 1px solid #ddd;
			text-align: left;
		}

		.duplicate-table tr:hover {
			background: #f5f5f5;
			cursor: pointer;
		}

		.action-buttons {
			display: flex;
			gap: 10px;
			margin-top: 15px;
		}

		.action-buttons button {
			padding: 8px 15px;
			border: none;
			border-radius: 4px;
			cursor: pointer;
		}

		.close-modal {
			position: absolute;
			right: 10px;
			top: 10px;
			font-size: 24px;
			cursor: pointer;
			color: #666;
		}

		.phone-input {
			border: solid 1px #ddd;
			border-radius: 4px;
			padding: 8px;
			width: 100%;
			font-size: 14px;
		}

		.phone-input:focus {
			border-color: #4a90e2;
			outline: none;
			box-shadow: 0 0 3px rgba(74, 144, 226, 0.3);
		}

		.phone-input:invalid {
			border-color: #ff4444;
		}

		@keyframes slideIn {
			from {
				transform: translateX(100%);
				opacity: 0;
			}

			to {
				transform: translateX(0);
				opacity: 1;
			}
		}

		@keyframes slideOut {
			from {
				transform: translateX(0);
				opacity: 1;
			}

			to {
				transform: translateX(100%);
				opacity: 0;
			}
		}

		.notification {
			box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
			white-space: pre-line;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Add New Patient</h2>
		<form id="patientForm" onsubmit="validateForm(event)">
			<!-- Basic Information -->
			<div class="form-section">
				<h3>Basic Information</h3>
				<div class="form-row">
					<div class="form-group">
						<label>First Name:</label>
						<input type="text" name="first_name" required maxlength="30">
					</div>
					<div class="form-group" style="display: flex; gap: 10px;">
						<div>
							<label>Relation</label>
							<select name="relation" class="input-xs" onchange="updateGender(this.value)">
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
				<div class="form-row auto-spacing">
					<div class="form-group">
						<label>Date of Birth:</label>
						<input type="date" name="dob" onchange="calculateAge(this.value)">
					</div>
					<div class="form-group">
						<label>Age (in years):</label>
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
						<input type="tel" name="contact" required pattern="[6-9][0-9]{9}" maxlength="10"
							placeholder="Enter 10 digit mobile number"
							title="Please enter a valid 10-digit mobile number starting with 6-9" class="phone-input">
					</div>
				</div>
				<div class="form-group full">
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
			</div>

			<!-- Payment Information -->
			<div class="form-section">
				<h3>Payment Information</h3>
				<div class="form-group">
					<label>Payment Type:</label>
					<select name="payment_type_id" id="payment_type" onchange="togglePaymentField()">
						<?php
                        $conn = getDbConnection();
$payment_types = $conn->query('SELECT * FROM payment_types ORDER BY type_name');
while ($type = $payment_types->fetch_assoc()) {
    $selected = (1 == $type['payment_type_id']) ? 'selected' : ''; // CASH is ID 1
    echo "<option value='".$type['payment_type_id']."' ".$selected.'>'.
        htmlspecialchars($type['type_name']).'</option>';
}
?>
					</select>
				</div>

				<div class="form-group" id="company_field" style="display:none;">
					<label>Company Name:</label>
					<select name="company_name" id="company_select" onchange="handleCompanySelect(this.value)">
						<option value="">Select Company</option>
						<?php while ($company = $companies->fetch_assoc()) { ?>
						<option
							value="<?php echo htmlspecialchars($company['company_name']); ?>">
							<?php echo htmlspecialchars($company['company_name']); ?>
						</option>
						<?php } ?>
						<option value="new">+ Add New Company</option>
					</select>
					<input type="text" id="new_company_name" name="new_company_name" style="display:none;"
						maxlength="100" placeholder="Enter new company name">
				</div>

				<!-- After company_field div, add insurance_field div -->
				<div class="form-group" id="insurance_field" style="display:none;">
					<label>Insurance Provider:</label>
					<select name="insurance_provider" id="insurance_select"
						onchange="handleInsuranceSelect(this.value)">
						<option value="">Select Insurance Provider</option>
						<?php while ($provider = $insurance_providers->fetch_assoc()) { ?>
						<option
							value="<?php echo htmlspecialchars($provider['TPA_name'].'|'.$provider['insurance_comp_name']); ?>">
							<?php echo htmlspecialchars($provider['TPA_name'].' - '.$provider['insurance_comp_name']); ?>
						</option>
						<?php } ?>
						<option value="new">+ Add New Insurance Provider</option>
					</select>

					<div id="new_insurance_form" style="display:none;">
						<div class="form-row">
							<div class="form-group half">
								<label>TPA Name:</label>
								<input type="text" id="new_tpa_name" name="new_tpa_name" maxlength="100">
							</div>
							<div class="form-group half">
								<label>Insurance Company:</label>
								<input type="text" id="new_insurance_company" name="new_insurance_company"
									maxlength="100">
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- ID Information -->
			<div class="form-section">
				<h3>ID Information</h3>
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

			<div class="form-row" style="justify-content: space-between; align-items: center;">
				<button type="submit">Register Patient</button>
				<div class="next-id-display">
					<label>Next Patient ID:</label>
					<span
						id="nextPatientId">MNH<?php echo str_pad($initial_next_id, 8, '0', STR_PAD_LEFT); ?></span>
				</div>
			</div>
		</form>
	</div>

	<!-- Add before closing body tag -->
	<div id="duplicateModal" class="modal">
		<div class="modal-content">
			<span class="close-modal" onclick="closeDuplicateModal()">&times;</span>
			<h3>Duplicate Patient Records Found</h3>
			<table class="duplicate-table" id="duplicateTable">
				<thead>
					<tr>
						<th>Patient ID</th>
						<th>Name</th>
						<th>Age/Gender</th>
						<th>Contact</th>
						<th>Match Type</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<div class="action-buttons">
				<button onclick="proceedWithNew()" class="btn-secondary">Create New Patient</button>
				<button onclick="closeDuplicateModal()" class="btn-secondary">Cancel</button>
			</div>
		</div>
	</div>

	<!-- Replace jQuery and Select2 CDN with local files -->
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<script>
		// Form validation and submission
		function validateForm(e) {
			e.preventDefault();
			const form = e.target;
			const mobile = form.contact.value.trim();
			const age = parseFloat(form.age.value.trim());

			// Updated age validation
			if (isNaN(age) || age < 0 || age > 110) {
				alert('Invalid age (must be between 0 and 110)');
				form.age.focus();
				return false;
			}

			// Client-side validation
			if (!mobile.match(/^[6-9]\d{9}$/)) {
				alert('Invalid mobile number (must be 10 digits starting with 6-9)');
				form.contact.focus();
				return false;
			}

			const aadhar = form.aadhar_number.value.trim();
			const abha = form.abha_id.value.trim();
			const ayushmaan = form.ayushmaan_id.value.trim().toUpperCase();

			if (aadhar && !aadhar.match(/^\d{12}$/)) {
				alert('Invalid Aadhar number (must be 12 digits)');
				form.aadhar_number.focus();
				return false;
			}

			if (abha && !abha.match(/^\d{14}$/)) {
				alert('Invalid ABHA ID (must be 14 digits)');
				form.abha_id.focus();
				return false;
			}

			if (ayushmaan && !ayushmaan.match(/^[A-Z0-9]{8,20}$/)) {
				alert('Invalid PM-JAY ID (must be 8-20 alphanumeric characters)');
				form.ayushmaan_id.focus();
				return false;
			}

			// If validation passes, submit form
			submitForm(form);
		}

		function submitForm(form) {
			const formData = new FormData(form);

			// Handle company name
			if (formData.get('payment_type_id') === '2') { // COMPANY_CREDIT
				const newCompanyInput = document.getElementById('new_company_name');
				if (newCompanyInput.style.display === 'block') {
					// First add the new company with default values
					return fetch('../../modules/admin/manage_companies.php', {
							method: 'POST',
							body: new FormData(Object.assign(document.createElement('form'), {
								innerHTML: `
								<input name="action" value="add">
								<input name="company_name" value="${formData.get('new_company_name')}">
								<input name="credit_limit" value="50000">
								<input name="payment_days" value="30">
								<input name="late_fee_percentage" value="2">
							`
							}))
						})
						.then(response => response.json())
						.then(data => {
							if (data.status === 'success') {
								// Set the company name and continue with patient registration
								formData.set('company_name', data.company_name);
								return submitPatientData(formData);
							} else {
								throw new Error(data.message);
							}
						})
						.catch(error => {
							alert('Error adding company: ' + error.message);
						});
				}
			}

			// Handle insurance provider
			if (formData.get('payment_type_id') === '4') { // MEDICLAIM
				const newInsuranceForm = document.getElementById('new_insurance_form');
				if (newInsuranceForm.style.display === 'block') {
					// First add the new insurance provider
					const tpaName = formData.get('new_tpa_name');
					const insuranceCompany = formData.get('new_insurance_company');

					return fetch('../admin/manage_mediclaim.php', {
							method: 'POST',
							body: new FormData(Object.assign(document.createElement('form'), {
								innerHTML: `
								<input name="TPA_name" value="${tpaName}">
								<input name="insurance_comp_name" value="${insuranceCompany}">
							`
							})),
						})
						.then(response => response.json())
						.then(data => {
							if (data.status === 'success') {
								// Set the insurance provider value and continue with patient registration
								formData.set('insurance_provider', `${tpaName}|${insuranceCompany}`);
								return submitPatientData(formData);
							} else {
								throw new Error(data.message);
							}
						})
						.catch(error => {
							alert('Error adding insurance provider: ' + error.message);
						});
				}
			}

			// If no new insurance provider, submit patient data directly
			return submitPatientData(formData);
		}

		function submitPatientData(formData) {
			const submitButton = document.querySelector('button[type="submit"]');
			const form = document.getElementById('patientForm');
			
			// Show loading state
			submitButton.disabled = true;
			submitButton.innerHTML = '<span style="opacity: 0.7">Processing...</span>';
			form.style.opacity = '0.7';
			form.style.pointerEvents = 'none';

			// Add timeout to fetch
			const controller = new AbortController();
			const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout

			return fetch('add_patient.php', {
				method: 'POST',
				body: formData,
				signal: controller.signal
			})
			.then(response => response.json())
			.then(data => {
				if (data.status === 'success') {
					const currentId = formatPatientId(data.patient_id);
					showNotification('success', `Patient registered successfully with ID: ${currentId}`);
					document.getElementById('nextPatientId').textContent = 
						formatPatientId(data.next_patient_id);
					resetForm();
				} else if (data.status === 'duplicate') {
					showDuplicateDialog(data.data);
				} else {
					throw new Error(data.message || 'Unknown error occurred');
				}
			})
			.catch(error => {
				if (error.name === 'AbortError') {
					showNotification('error', 'Request timed out. Please try again.');
				} else {
					showNotification('error', error.message);
				}
			})
			.finally(() => {
				clearTimeout(timeoutId);
				submitButton.disabled = false;
				submitButton.innerHTML = 'Register Patient';
				form.style.opacity = '';
				form.style.pointerEvents = '';
			});
		}

		// Add helper function to reset form
		function resetForm() {
			const form = document.getElementById('patientForm');
			form.reset();

			// Restore default values
			document.querySelector('select[name="state"]').value = 'Madhya Pradesh';
			document.querySelector('select[name="payment_type_id"]').value = '1';
			document.querySelector('select[name="relation"]').value = 'S/O';
			updateGender('S/O');
			togglePaymentField();
		}

		function showDuplicateDialog(duplicates) {
			const modal = document.getElementById('duplicateModal');
			const tbody = document.querySelector('#duplicateTable tbody');
			tbody.innerHTML = '';

			// Map match types to readable formats
			const matchTypeDisplay = {
				'name_contact': 'Name & Number',
				'aadhar': 'Aadhaar Number',
				'abha': 'ABHA ID',
				'ayushmaan': 'Ayushmaan ID'
			};

			// Populate table with duplicate entries
			for (let type in duplicates) {
				const patient = duplicates[type];
				const tr = document.createElement('tr');
				tr.innerHTML = `
					<td>${formatPatientId(patient.patient_id)}</td>
					<td>${patient.name}</td>
					<td>${patient.age}/${patient.gender}</td>
					<td>${patient.contact}</td>
					<td>${matchTypeDisplay[type] || type}</td>
					<td>
						<button onclick="redirectTo('bill', ${patient.patient_id})" class="duplicate-action-btn bill">Generate Bill</button>
						<button onclick="redirectTo('pharmacy', ${patient.patient_id})" class="duplicate-action-btn pharmacy">Pharmacy Sale</button>
						<button onclick="redirectTo('view', ${patient.patient_id})" class="duplicate-action-btn view">View Details</button>
					</td>
				`;
				tbody.appendChild(tr);
			}

			modal.style.display = 'block';
		}

		function redirectTo(type, patientId) {
			const baseUrl = '/erp/modules/';
			let url;

			switch (type) {
				case 'bill':
					url = `${baseUrl}billing/generate_bill.php?patient_id=${patientId}`;
					break;
				case 'pharmacy':
					url = `${baseUrl}pharmacy/sale.php?patient_id=${patientId}`;
					break;
				case 'view':
					url = `view_patient.php?id=${patientId}`;
					break;
				default:
					console.error('Invalid redirect type:', type);
					return;
			}

			window.location.href = url;
		}

		function closeDuplicateModal() {
			document.getElementById('duplicateModal').style.display = 'none';
		}

		function proceedWithNew() {
			if (confirm('Are you sure you want to create a new patient record?')) {
				closeDuplicateModal();
				document.getElementById('patientForm').submit();
			}
		}

		// Close modal when clicking outside
		window.onclick = function(event) {
			const modal = document.getElementById('duplicateModal');
			if (event.target === modal) {
				closeDuplicateModal();
			}
		}

		function formatPatientId(id) {
			// Format numeric ID to padded string
			return 'MNH' + String(id).padStart(8, '0');
		}

		// Location dropdowns initialization
		$(document).ready(function() {
			// Initialize Select2
			$('.select2-dropdown').select2({
				placeholder: "Select...",
				allowClear: true,
				width: '100%'
			});

			// Store default values
			const defaultState = 'Madhya Pradesh';
			const defaultCity = 'Dewas';
			const defaultTehsil = 'Dewas';

			// Function to set tehsils for a district
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

			// Load and set states
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

					// After setting state, load districts
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

							// After setting city, load tehsils for Dewas
							setTehsilOptions(defaultCity, defaultTehsil);
						}
					});
				}
			});

			// When state changes
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

					// If switching back to MP, set Dewas as default
					if (citySelect.prop('disabled')) {
						citySelect.val(defaultCity).trigger('change');
					}
				}

				citySelect.select2();
				tehsilSelect.select2();
			});

			// When district changes
			$('#city').on('change', function() {
				if (!$(this).prop('disabled')) {
					var selectedDistrict = $(this).val() || defaultCity;
					setTehsilOptions(selectedDistrict, defaultTehsil);
				}
			});

			updateGender(document.querySelector('select[name="relation"]').value);
			togglePaymentField();
		});

		function togglePaymentField() {
			const paymentType = document.getElementById('payment_type').value;
			const companyField = document.getElementById('company_field');
			const insuranceField = document.getElementById('insurance_field');
			const cardDetails = document.getElementById('card_details');
			const upiDetails = document.getElementById('upi_details');

			// Hide all payment related fields first
			[companyField, insuranceField, cardDetails, upiDetails].forEach(field => {
				if (field) field.style.display = 'none';
			});

			// Show relevant field based on payment type
			switch (paymentType) {
				case '2': // COMPANY_CREDIT
					companyField.style.display = 'block';
					break;
				case '4': // MEDICLAIM
					insuranceField.style.display = 'block';
					break;
				case '6': // CARD
					if (cardDetails) {
						cardDetails.style.display = 'block';
						calculateCardFee();
					}
					break;
				case '7': // UPI
					if (upiDetails) upiDetails.style.display = 'block';
					break;
			}
		}

		function updateGender(relation) {
			const genderSelect = document.querySelector('select[name="gender"]');
			if (relation === 'S/O') {
				genderSelect.value = 'M';
			} else if (relation === 'W/O' || relation === 'D/O') {
				genderSelect.value = 'F';
			}
		}

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
			const totalYears = totalMonths / 12; // Convert months to years


			let decimalAge;
			if (totalYears < 5) {
				// If age is less than 5 year, calculate in years using days
				decimalAge = Math.round((totalDays / 365) * 10) / 10;
			} else {
				// For ages 5 years and above, calculate based on total years
				decimalAge = Math.round(totalYears);
			}

			console.log(decimalAge);

			document.querySelector('input[name="age"]').value = decimalAge;
		}

		function handleCompanySelect(value) {
			const newCompanyInput = document.getElementById('new_company_name');
			const companySelect = document.getElementById('company_select');

			if (value === 'new') {
				newCompanyInput.style.display = 'block';
				newCompanyInput.required = true;
				companySelect.name = ''; // Remove name attribute to prevent form submission
				newCompanyInput.name = 'new_company_name'; // Add name attribute for form submission
			} else {
				newCompanyInput.style.display = 'none';
				newCompanyInput.required = false;
				companySelect.name = 'company_name'; // Restore name attribute
				newCompanyInput.name = ''; // Remove name attribute
			}
		}

		function handleInsuranceSelect(value) {
			const newInsuranceForm = document.getElementById('new_insurance_form');
			const insuranceSelect = document.getElementById('insurance_select');
			const tpaInput = document.getElementById('new_tpa_name');
			const companyInput = document.getElementById('new_insurance_company');

			if (value === 'new') {
				newInsuranceForm.style.display = 'block';
				tpaInput.required = true;
				companyInput.required = true;
				insuranceSelect.name = ''; // Remove name attribute
			} else {
				newInsuranceForm.style.display = 'none';
				tpaInput.required = false;
				companyInput.required = false;
				insuranceSelect.name = 'insurance_provider';
				tpaInput.value = '';
				companyInput.value = '';
			}
		}

		function showNotification(type, message) {
			const notificationDiv = document.createElement('div');
			notificationDiv.className = `notification ${type}`;
			notificationDiv.textContent = message;

			// Add styles for the notification
			notificationDiv.style.position = 'fixed';
			notificationDiv.style.top = '20px';
			notificationDiv.style.right = '20px';
			notificationDiv.style.padding = '15px 25px';
			notificationDiv.style.borderRadius = '4px';
			notificationDiv.style.maxWidth = '400px';
			notificationDiv.style.zIndex = '9999';
			notificationDiv.style.animation = 'slideIn 0.5s ease-out';

			// Set colors based on type
			switch (type) {
				case 'success':
					notificationDiv.style.backgroundColor = '#4CAF50';
					notificationDiv.style.color = 'white';
					break;
				case 'warning':
					notificationDiv.style.backgroundColor = '#ff9800';
					notificationDiv.style.color = 'white';
					break;
				case 'error':
					notificationDiv.style.backgroundColor = '#f44336';
					notificationDiv.style.color = 'white';
					break;
			}

			document.body.appendChild(notificationDiv);

			// Remove notification after 5 seconds
			setTimeout(() => {
				notificationDiv.style.animation = 'slideOut 0.5s ease-in';
				setTimeout(() => {
					document.body.removeChild(notificationDiv);
				}, 500);
			}, 5000);
		}
	</script>
</body>

</html>