<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();

    // Check if action exists in POST data
    $action = $_POST['action'] ?? '';

    if ($action === 'add_department') {
        $dept_name = sanitizeInput($_POST['dept_name']);
        if (empty($dept_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Department name cannot be empty']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO departments (name) VALUES (?)");
        $stmt->bind_param("s", $dept_name);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $conn->error]);
        }
        exit;
    } else {
        // Validate and sanitize required fields
        $name = ucwords(strtolower(sanitizeInput($_POST['name'])));
        $degree = sanitizeInput($_POST['degree']);
        $registration = strtoupper(sanitizeInput($_POST['registration_no']));
        $dept_id = filter_var($_POST['dept_id'], FILTER_VALIDATE_INT);
        $charge = filter_var($_POST['charge_per_visit'], FILTER_VALIDATE_FLOAT);

        // Validate required fields
        if (empty($name) || empty($degree) || empty($registration) || !$dept_id || $charge === false) {
            echo json_encode(['status' => 'error', 'message' => 'Please fill all required fields correctly']);
            exit;
        }

        // Optional fields - use NULL for empty values
        $specialization = !empty($_POST['specialization']) ? sanitizeInput($_POST['specialization']) : null;
        $contact = !empty($_POST['contact']) ? sanitizeInput($_POST['contact']) : null;
        $email = !empty($_POST['email']) ? filter_var(sanitizeInput($_POST['email']), FILTER_VALIDATE_EMAIL) : null;

        // Additional validation for contact number if provided
        if ($contact !== null && !preg_match('/^[6-9]\d{9}$/', $contact)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid contact number format']);
            exit;
        }

        // Email validation if provided
        if ($email !== false && $email !== null) {
            // Check for duplicate email
            $check_stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email address already exists']);
                exit;
            }
        }

        // Modify the SQL to handle NULL values
        $stmt = $conn->prepare("INSERT INTO doctors 
            (name, dept_id, specialization, degree, registration_no, charge_per_visit, contact, email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "sississs",
            $name,
            $dept_id,
            $specialization,
            $degree,
            $registration,
            $charge,
            $contact,
            $email
        );

        try {
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$conn = getDbConnection();
$departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$doctors = $conn->query("SELECT d.*, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Manage Doctors</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/doctors.css">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Manage Doctors</h2>

		<!-- Add Doctor Form -->
		<form id="doctorForm">
			<div class="form-row">
				<div class="form-group third">
					<label>Name: *</label>
					<input type="text" name="name" required>
				</div>
				<div class="form-group third">
					<label>Degree: *</label>
					<input type="text" name="degree" required placeholder="e.g., MBBS, MS">
				</div>
				<div class="form-group third">
					<label>Registration No: *</label>
					<input type="text" name="registration_no" required>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group half">
					<label>Department: *</label>
					<div class="department-select-group">
						<select name="dept_id" id="dept_select" required>
							<option value="">Select Department</option>
							<?php foreach ($departments as $dept): ?>
							<option
								value="<?= $dept['dept_id'] ?>">
								<?= htmlspecialchars($dept['name']) ?>
							</option>
							<?php endforeach; ?>
							<option value="new">+ Add New Department</option>
						</select>
						<div id="new_dept_input" style="display: none; margin-top: 10px;">
							<div class="input-group">
								<input type="text" id="new_dept_name" placeholder="Enter new department name">
								<button type="button" onclick="addNewDepartment()">Add</button>
							</div>
						</div>
					</div>
				</div>
				<div class="form-group half">
					<label>Consultation Fee: *</label>
					<input type="number" name="charge_per_visit" required min="0" step="1"
						placeholder="Enter consultation fee">
				</div>
			</div>

			<div class="form-row">
				<div class="form-group third">
					<label>Specialization: (optional)</label>
					<input type="text" name="specialization" pattern="[A-Za-z ,\-]+"
						title="Only letters and spaces, or commas allowed">
				</div>
				<div class="form-group third">
					<label>Contact: (optional)</label>
					<input type="tel" name="contact" pattern="[6-9][0-9]{9}" title="10 digits mobile number">
				</div>
				<div class="form-group third">
					<label>Email: (optional)</label>
					<input type="email" name="email">
				</div>
			</div>
			<div class="required-note">* Required fields</div>
			<button type="submit">Add Doctor</button>
		</form>

		<!-- Add this right after the form -->
		<div id="confirmationDialog" style="display:none;">
			<h3>Please Confirm Details</h3>
			<div id="confirmationDetails"></div>
			<div class="form-actions">
				<button type="button" onclick="submitForm()">Confirm</button>
				<button type="button" onclick="hideConfirmation()">Edit</button>
			</div>
		</div>

		<!-- Doctors List -->
		<div class="doctors-list">
			<table class="data-table">
				<thead>
					<tr>
						<th>Name</th>
						<th>Degree</th>
						<th>Registration</th>
						<th>Department</th>
						<th>Consultation Fee</th>
						<th>Specialization</th>
						<th>Contact</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($doctors as $doctor): ?>
					<tr>
						<td><?= htmlspecialchars($doctor['name'] ?? '') ?>
						</td>
						<td><?= htmlspecialchars($doctor['degree'] ?? '') ?>
						</td>
						<td><?= htmlspecialchars($doctor['registration_no'] ?? '') ?>
						</td>
						<td><?= htmlspecialchars($doctor['dept_name'] ?? '') ?>
						</td>
						<td>₹<?= number_format($doctor['charge_per_visit'] ?? 0, 2) ?>
						</td>
						<td><?= htmlspecialchars($doctor['specialization'] ?? '') ?>
						</td>
						<td><?= htmlspecialchars($doctor['contact'] ?? '') ?>
						</td>
						<td><?= $doctor['status'] ?? 'ACTIVE' ?>
						</td>
						<td><a href="edit_doctors.php?id=<?= $doctor['doctor_id'] ?>"
								class="edit-btn">Edit</a></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		document.getElementById('dept_select').addEventListener('change', function() {
			const newDeptInput = document.getElementById('new_dept_input');
			newDeptInput.style.display = this.value === 'new' ? 'block' : 'none';
		});

		function addNewDepartment() {
			const deptName = document.getElementById('new_dept_name').value.trim();
			if (!deptName) {
				alert('Please enter department name');
				return;
			}

			fetch('manage_doctors.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: `action=add_department&dept_name=${encodeURIComponent(deptName)}`
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						const select = document.getElementById('dept_select');
						const option = new Option(deptName, data.id);
						select.insertBefore(option, select.lastChild);
						select.value = data.id;
						document.getElementById('new_dept_input').style.display = 'none';
						document.getElementById('new_dept_name').value = '';
					} else {
						alert('Error: ' + data.message);
					}
				})
				.catch(error => {
					alert('An error occurred. Please try again.');
					console.error('Error:', error);
				});
		}

		function showConfirmation(formData) {
			const name = formData.get('name') || 'Not provided';
			const contact = formData.get('contact') || 'Not provided';
			const email = formData.get('email') || 'Not provided';
			const registration = formData.get('registration_no') || 'Not provided';
			const dept = document.querySelector('select[name="dept_id"] option:checked').text;
			const charge = formData.get('charge_per_visit') || '0';

			const confirmationHtml = `
                <div class="confirm-details">
                    <p><strong>Name:</strong> ${name}</p>
                    <p><strong>Contact:</strong> ${contact}</p>
                    <p><strong>Email:</strong> ${email}</p>
                    <p><strong>Registration No:</strong> ${registration}</p>
                    <p><strong>Department:</strong> ${dept}</p>
                    <p><strong>Consultation Charge:</strong> ₹${charge}</p>
                </div>
            `;

			document.getElementById('confirmationDetails').innerHTML = confirmationHtml;
			document.getElementById('confirmationDialog').style.display = 'block';
		}

		function hideConfirmation() {
			document.getElementById('confirmationDialog').style.display = 'none';
		}

		let formToSubmit = null;

		document.getElementById('doctorForm').onsubmit = function(e) {
			e.preventDefault();

			// Format inputs first
			const nameInput = this.elements['name'];
			const registrationInput = this.elements['registration_no'];

			// Format name: capitalize first letter of each word
			nameInput.value = nameInput.value
				.toLowerCase()
				.replace(/\b\w/g, c => c.toUpperCase())
				.trim();

			// Format registration: all uppercase
			registrationInput.value = registrationInput.value.toUpperCase().trim();

			// Then do validation
			const requiredFields = ['name', 'degree', 'registration_no', 'dept_id', 'charge_per_visit'];
			let isValid = true;
			let firstInvalidField = null;

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
				firstInvalidField.focus();
				alert('Please fill in all required fields');
				return;
			}

			// Only create FormData after formatting and validation
			formToSubmit = new FormData(this);
			showConfirmation(formToSubmit);
		};

		function submitForm() {
			if (!formToSubmit) return;

			fetch(window.location.href, {
					method: 'POST',
					body: formToSubmit
				})
				.then(response => {
					// First check if response is ok
					if (!response.ok) {
						throw new Error('Network response was not ok');
					}
					return response.text(); // Use text() instead of json()
				})
				.then(text => {
					// Try to parse as JSON, but handle cases where it's not JSON
					try {
						const data = JSON.parse(text);
						if (data.status === 'success') {
							alert('Doctor added successfully!');
							window.location.reload();
						} else {
							alert('Error: ' + (data.message || 'Unknown error occurred'));
						}
					} catch (e) {
						console.error('Invalid JSON response:', text);
						alert('Server error occurred. Please try again.');
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('An error occurred while submitting the form. Please try again.');
				});

			// Hide confirmation dialog after submit
			hideConfirmation();
		}

		// Add some CSS for the confirmation dialog
		document.head.insertAdjacentHTML('beforeend', `
            <style>
                #confirmationDialog {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: white;
                    padding: 20px;
                    border-radius: 5px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    z-index: 1000;
                }
                .confirm-details {
                    margin: 15px 0;
                }
                .confirm-details p {
                    margin: 5px 0;
                }
				.required-note {
					color: #666;
					font-size: 0.9em;
					margin-bottom: 10px;
				}
				input.invalid {
					border-color: #dc3545;
					background-color: #fff8f8;
				}
				.form-group label {
					display: flex;
					align-items: center;
					gap: 5px;
				}
            </style>
        `);
	</script>
</body>

</html>