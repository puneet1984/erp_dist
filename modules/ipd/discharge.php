<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$admissionId = $_GET['id'] ?? 0;

// Handle POST request
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $admissionId = $_POST['admission_id'] ?? 0;
    $dischargeDate = $_POST['discharge_date'] ?? date('Y-m-d');
    $dischargeTime = $_POST['discharge_time'] ?? date('H:i');
    $dischargeDateTime = $dischargeDate.' '.$dischargeTime;

    // Update admission with discharge date, notes, and diagnosis
    $conn = getDbConnection();
    $query = "UPDATE ipd_admissions 
              SET status = 'Discharged',
                  discharge_date = ?,
                  discharge_notes = ?,
                  diagnosis = ?
              WHERE admission_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('sssi', $dischargeDateTime, $_POST['discharge_notes'], $_POST['diagnosis'], $admissionId);

    if ($stmt->execute() && freeBedAllocation($admissionId)) {
        header('Location: discharge_summary.php?id='.$admissionId);
        exit;
    }
    $error = 'Failed to process discharge';

}

$admission = getAdmissionDetails($admissionId, true);

if (!$admission) {
    header('Location: ipd_list.php');
    exit;
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Patient Discharge</title>
	<!-- Bootstrap CSS -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<!-- FontAwesome -->
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
	<!-- jQuery -->
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<!-- Bootstrap JS -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.form-control {
			width: auto;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container mt-4">
		<h2>Discharge Patient</h2>
		<div class="card mb-4">
			<div class="card-body">
				<div class="row">
					<div class="col-md-3">
						<strong>Patient:</strong>
						<?php echo $admission['patient_name']; ?>
					</div>
					<div class="col-md-3">
						<strong>Ward:</strong>
						<?php echo $admission['ward_name']; ?>
					</div>
					<div class="col-md-3">
						<strong>Bed:</strong>
						<?php echo $admission['bed_number']; ?>
					</div>
					<div class="col-md-3">
						<strong>Admission Date:</strong>
						<?php echo date('d-m-Y', strtotime($admission['admission_date'])); ?>
					</div>
				</div>
			</div>
		</div>

		<form action="" method="POST">
			<input type="hidden" name="admission_id"
				value="<?php echo $admissionId; ?>">

			<div class="form-group mb-3">
				<div class="row">
					<div class="col-md-6">
						<label class="form-label">Discharge Date</label>
						<input type="date" name="discharge_date" class="form-control"
							value="<?php echo date('Y-m-d'); ?>"
							max="<?php echo date('Y-m-d'); ?>"
							required>
					</div>
					<div class="col-md-6">
						<label class="form-label">Discharge Time</label>
						<input type="time" name="discharge_time" class="form-control"
							value="<?php echo date('H:i'); ?>"
							required>
					</div>
				</div>
			</div>

			<div class="form-group mb-3">
				<label class="form-label">Diagnosis</label>
				<textarea name="diagnosis" class="form-control" rows="3" required></textarea>
			</div>

			<div class="form-group mb-3">
				<label class="form-label">Discharge Notes</label>
				<textarea name="discharge_notes" class="form-control" rows="3"></textarea>
			</div>

			<div class="text-end">
				<a href="discharge_summary.php?id=<?php echo $admissionId; ?>"
					class="btn btn-primary">
					Add Discharge Summary
				</a>
				<button type="submit" class="btn btn-warning" onclick="return confirm('Confirm patient discharge?')">
					Process Discharge
				</button>
				<a href="ipd_list.php" class="btn btn-secondary">Cancel</a>
			</div>
		</form>
	</div>
</body>

</html>