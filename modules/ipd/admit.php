<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    if (isset($_GET['action']) && 'get_beds' === ($_GET['action'] ?? '')) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
        exit;
    }

    header('Location: ../auth/unauthorized.php');
    exit;
}

// Handle AJAX request for available beds
if (isset($_GET['action']) && 'get_beds' === $_GET['action']) {
    $wardId = $_GET['ward_id'] ?? 0;
    $beds = getAvailableBeds($wardId);
    echo json_encode($beds);
    exit;
}

// Handle form submission
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $patientId = $_POST['patient_id'] ?? 0;
    $wardId = $_POST['ward_id'] ?? 0;
    $bedNumber = $_POST['bed_number'] ?? '';
    $doctorId = $_POST['doctor_id'] ?? 0;
    $diagnosis = $_POST['diagnosis'] ?? '';
    $treatmentPlan = $_POST['treatment_plan'] ?? '';

    // Get and combine admission date and time
    $admissionDate = $_POST['admission_date'] ?? date('Y-m-d');
    $admissionTime = $_POST['admission_time'] ?? date('H:i');
    $admissionDateTime = $admissionDate.' '.$admissionTime;

    if ($admissionId = admitPatient($patientId, $wardId, $bedNumber, $doctorId, $diagnosis, $treatmentPlan, $admissionDateTime)) {
        header('Location: view.php?id='.$admissionId);
        exit;
    }
    $error = 'Failed to process admission';

}

$wards = getActiveWards();
$doctors = getActiveDoctors();
?>

<!DOCTYPE html>
<html>

<head>
	<title>IPD Admission</title>
	<!-- Bootstrap CSS -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<!-- FontAwesome -->
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
	<!-- jQuery -->
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<!-- Bootstrap JS -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
	<link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container mt-4">
		<h2>New IPD Admission</h2>
		<form action="admit.php" method="POST">
			<div class="form-group">
				<label>Patient ID</label>
				<div class="input-group">
					<input type="text" id="patient_search" class="form-control" style="width: 90%;"
						placeholder="Enter Patient ID" maxlength="10" onblur="searchPatient()">
					<input type="hidden" id="patient_id" name="patient_id" required>
				</div>
				<div id="patient_details" class="mt-2"></div>
			</div>

			<div class="form-group mb-3">
				<div class="row">
					<div class="col-md-3">
						<label>Admission Date</label>
						<input type="date" name="admission_date" class="form-control"
							value="<?php echo date('Y-m-d'); ?>"
							required>
					</div>
					<div class="col-md-3">
						<label>Time</label>
						<input type="time" name="admission_time" class="form-control"
							value="<?php echo date('H:i'); ?>"
							required>
					</div>
					<div class="col-md-4">
						<label>Ward</label>
						<select name="ward_id" id="ward_id" class="form-control" required>
							<option value="">Select Ward</option>
							<?php foreach ($wards as $ward) { ?>
							<option
								value="<?php echo $ward['id']; ?>">
								<?php echo $ward['ward_name'].' ('.$ward['ward_type'].')'; ?>
							</option>
							<?php } ?>
						</select>
					</div>
					<div class="col-md-2">
						<label>Bed No.</label>
						<select name="bed_number" id="bed_number" class="form-control" required>
							<option value="">Select</option>
						</select>
					</div>
				</div>
			</div>

			<div class="form-group">
				<label>Doctor</label>
				<select name="doctor_id" id="doctor_id" class="form-control" style="width: 95%;" required>
					<option value="">Select Doctor</option>
					<?php foreach ($doctors as $doctor) { ?>
					<option
						value="<?php echo $doctor['doctor_id']; ?>"
						data-dept="<?php echo $doctor['dept_id']; ?>"
						data-dept-name="<?php echo htmlspecialchars($doctor['dept_name']); ?>">
						Dr.
						<?php echo htmlspecialchars($doctor['name']); ?>
						(<?php echo htmlspecialchars($doctor['dept_name']); ?>)
					</option>
					<?php } ?>
				</select>
				<div id="dept_info" class="text-muted small mt-1"></div>
			</div>

			<div class="form-group">
				<label>Initial Diagnosis</label>
				<textarea name="diagnosis" class="form-control"></textarea>
			</div>

			<div class="form-group">
				<label>Treatment Plan</label>
				<textarea name="treatment_plan" class="form-control"></textarea>
			</div>

			<button type="submit" class="btn btn-primary">Admit Patient</button>
		</form>
	</div>

	<script>
		$(document).ready(function() {
			// Load available beds when ward is selected
			$('#ward_id').change(function() {
				var wardId = $(this).val();
				$('#bed_number').html('<option value="">Select Bed</option>');
				if (!wardId) {
					return;
				}
				$.get('admit.php', {
					action: 'get_beds',
					ward_id: wardId
				}, function(response) {
					const beds = JSON.parse(response);
					let options = '<option value="">Select Bed</option>';
					beds.forEach(bed => {
						options += `<option value="${bed}">Bed ${bed}</option>`;
					});
					$('#bed_number').html(options);
				});
			});

			// Add keypress handler for patient search
			$('#patient_search').keypress(function(e) {
				if (e.which == 13) { // Enter key
					searchPatient();
					return false;
				}
			});

			// Handle doctor selection
			$('#doctor_id').change(function() {
				updateDepartment($(this).find(':selected'));
			});
		});

		function updateDepartment(selectedOption) {
			// Update department info when doctor is selected
			if (selectedOption && selectedOption.data('dept-name')) {
				$('#dept_info').text('Department: ' + selectedOption.data('dept-name'));
			} else {
				$('#dept_info').text('');
			}
		}

		function searchPatient() {
			const searchTerm = $('#patient_search').val().trim();
			if (!searchTerm) {
				return;
			}

			$.get('../../includes/helpers/get_patient.php', {
				term: searchTerm,
				module: 'ipd'
			})
			.then(response => {
				if (response.status === 'success') {
					const patient = response.patient;
					$('#patient_id').val(patient.id);
					showPatientInfo(patient, patient.admission);
				} else {
					alert(response.message || 'Patient not found');
					$('#patient_id').val('');
					$('#patient_details').html('');
				}
			})
			.catch(error => {
				console.error('Error:', error);
				alert('Failed to fetch patient details');
			});
		}

		function showPatientInfo(patient, admission) {
			let html = `
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <th width="100">ID:</th>
                                        <td>${patient.id}</td>
                                    </tr>
                                    <tr>
                                        <th>Name:</th>
                                        <td>${patient.name}</td>
                                    </tr>
                                    <tr>
                                        <th>Age/Gender:</th>
                                        <td>${patient.age} years / ${formatGender(patient.gender)}</td>
                                    </tr>
                                    <tr>
                                        <th>Address:</th>
                                        <td>${patient.address || 'Not provided'}</td>
                                    </tr>
                                </table>
                            </div>`;

			if (admission) {
				html += `
                            <div class="col-md-6">
                                <div class="alert alert-warning">
                                    <h5 class="alert-heading">⚠️ Active Admission Found</h5>
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <th>Patient:</th>
                                            <td>${patient.name}, ID (${patient.id})</td>
                                        </tr>
                                        <tr>
                                            <th>Admission:</th>
                                            <td>#${admission.admission_id}</td>
                                        </tr>
                                        <tr>
                                            <th>Doctor:</th>
                                            <td>Dr. ${admission.doctor_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Department:</th>
                                            <td>${admission.dept_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Date:</th>
                                            <td>${new Date(admission.admission_date).toLocaleString('en-IN', {
                                                day: '2-digit',
                                                month: '2-digit',
                                                year: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit'
                                            })}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>`;
			}

			html += `</div></div></div>`;
			$('#patient_details').html(html);
		}

		function formatGender(gender) {
			const genderMap = {
				'M': 'Male',
				'F': 'Female',
				'O': 'Others'
			};
			return genderMap[gender] || gender;
		}
	</script>
</body>

</html>