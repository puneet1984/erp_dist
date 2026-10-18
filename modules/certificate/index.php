<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is a doctor or admin
if (!isDoctor() && !isAdmin()) {
    header('Location: ../../unauthorized.php');
    exit;
}
// file includes/helpers/certificate_helper.php
?>
<!DOCTYPE html>
<html>

<head>
	<title>Certificate Generation</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/certificates.css">
	<style>
		.certificate-text {
			white-space: pre-wrap;
			word-wrap: break-word;
			font-family: Arial, sans-serif;
			line-height: 1.5;
		}

		.modal-dialog {
			max-width: 800px;
		}

		.certificate-preview {
			padding: 20px;
		}
	</style>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>
	<?php include_once '../../includes/simple_action_bar.php'; ?>
	<div class="container">
		<div class="d-flex justify-content-between align-items-center mb-4">
			<h2>Certificate Generation</h2>
			<a href="list_certificate.php" class="btn btn-primary">View All Certificates</a>
		</div>
		<div class="certificate-form">
			<form id="certificateForm">
				<div class="row mb-3">
					<div class="col-md-6">
						<div class="form-group">
							<label class="required">Certificate Type</label>
							<select class="form-control" id="certificateType" required>
								<option value="">Select Certificate</option>
								<option value="leave">Leave Certificate</option>
								<option value="fitness">Fitness Certificate</option>
								<!-- <option value="procedure">Procedure Certificate</option> -->
								<!-- <option value="highrisk">High Risk Certificate</option> -->
								<!-- <option value="anes-consent">Anesthesia and Surgery Consent Form</option> -->
								<!-- <option value="admission">Admission Form</option> -->
								<!-- <option value="consent">Consent Form</option> -->
								<option value="fit_to_travel">Fit to Travel</option>
								<option value="metal_implant">Metal Implant Certificate</option>
							</select>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group">
							<label class="required">Patient ID</label>
							<input type="text" class="form-control" id="patientId" required>
						</div>
					</div>
				</div>
				<div id="patientDetails">
					<div class="row">
						<div class="col-md-6">
							<div class="form-group mb-3">
								<label>Name</label>
								<input type="text" class="form-control" id="patientName" readonly>
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group mb-3">
								<label>Age</label>
								<input type="text" class="form-control" id="patientAge" readonly>
							</div>
						</div>
						<div class="col-md-3">
							<div class="form-group mb-3">
								<label>Gender</label>
								<input type="text" class="form-control" id="patientGender" readonly>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-8">
							<div class="form-group mb-3">
								<label>Address</label>
								<input type="text" class="form-control" id="patientAddress" readonly>
							</div>
						</div>
						<div class="col-md-4">
							<div class="form-group mb-3">
								<label>Phone</label>
								<input type="text" class="form-control" id="patientPhone" readonly>
							</div>
						</div>
					</div>
				</div>

				<div id="previousCertificates" class="border p-3 mb-3"></div>

				<!-- Common Certificate Details -->
				<div class="border p-3 mb-3">
					<h4>Certificate Details</h4>
					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label class="required">Date of Issue</label>
								<input type="date" class="form-control date-input" id="issueDate"
									value="<?php echo date('Y-m-d'); ?>"
									data-display-format="dd-mm-yyyy" required>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label>Certificate Number</label>
								<input type="text" class="form-control" id="certificateNumber" readonly>
							</div>
						</div>
						<div class="col-md-12">
							<div class="form-group">
								<label class="required">Diagnosis/Condition</label>
								<textarea class="form-control" id="diagnosis" rows="2" required></textarea>
							</div>
						</div>
					</div>
				</div>

				<!-- Leave Certificate Details -->
				<div id="leaveDetails" class="border p-3 mb-3" style="display:none;">
					<h4>Leave Details</h4>
					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label class="required">Number of Days</label>
								<input type="number" class="form-control" id="leaveDays" min="1" value="30" max="120">
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label class="required">Start Date of Leave</label>
								<input type="date" class="form-control date-input" id="leaveStartDate"
									value="<?php echo date('Y-m-d'); ?>"
									data-display-format="dd-mm-yyyy">
							</div>
						</div>
					</div>
					<input type="hidden" id="endDate" name="endDate" value="">
				</div>

				<!-- Metal Implant Certificate Details -->
				<div id="metalImplantDetails" class="border p-3 mb-3" style="display:none;">
					<h4>Metal Implant Details</h4>
					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label class="required">Date of Surgery</label>
							<input type="date" class="form-control date-input" id="surgeryDate"
								data-display-format="dd-mm-yyyy">
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label class="required">Procedure Performed</label>
								<textarea class="form-control" id="procedurePerformed" rows="2"
									placeholder="Enter procedure performed"></textarea>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label>Date of Implant Verification</label>
								<input type="date" class="form-control date-input" id="implantCheckDate"
									value="<?php echo date('Y-m-d'); ?>"
									data-display-format="dd-mm-yyyy">
							</div>
						</div>
					</div>
				</div>

				<!-- Doctor Details -->
				<div id="doctorDetails" class="border p-3 mb-3">
					<h4>Doctor Details</h4>
					<div class="row">
						<div class="col-md-6">
							<div class="form-group">
								<label>Select Doctor</label>
								<select class="form-control" id="doctorId" required>
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
                                              data-dept='{$row['department']}'>{$row['name']}</option>";
}
?>
								</select>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label>Registration Number</label>
								<input type="text" class="form-control" id="doctorReg" readonly>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label>Department</label>
								<input type="text" class="form-control" id="department" readonly>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group">
								<label>Hospital Name</label>
								<input type="text" class="form-control" id="hospitalName"
									value="<?php echo HOSPITAL_NAME; ?>"
									readonly>
							</div>
						</div>
					</div>
				</div>

				<div id="certificateContent"></div>
				<button type="submit" class="btn btn-primary">Preview Certificate</button>
			</form>
		</div>
	</div>

	<!-- Certificate Preview Modal -->
	<div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-labelledby="previewModalTitle">
		<div class="modal-dialog modal-lg" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="previewModalTitle">Certificate Preview</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body certificate-preview" id="previewContent">
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
						Cancel
					</button>
					<button type="button" class="btn btn-primary" id="printCertificate">
						Print
					</button>
				</div>
			</div>
		</div>
	</div>

	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/certificate.js"></script>
</body>

</html>