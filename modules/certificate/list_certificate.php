<?php
define('SECURE_ACCESS', true);
require_once('../../includes/core.php');

// Check if user is a doctor or admin
if (!isDoctor() && !isAdmin()) {
    header('Location: ../../unauthorized.php');
    exit();
}

function formatDateDMY($date) {
    return date('d-m-Y', strtotime($date));
}

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT c.*, 
                        p.name as patient_name,
                        d.name as doctor_name
                        FROM certificates c
                        LEFT JOIN patients p ON c.patient_id = p.patient_id 
                        LEFT JOIN doctors d ON c.doctor_id = d.doctor_id
                        ORDER BY c.created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>List of Certificates</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
    <style>
        .edit-modal .form-group { margin-bottom: 1rem; }
        .edit-modal label { margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <?php include_once('../../includes/simple_action_bar.php'); ?>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>List of Certificates</h2>
            <a href="index.php" class="btn btn-primary">Create New Certificate</a>
        </div>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Certificate Number</th>
                    <th>Patient Name</th>
                    <th>Diagnosis</th>
                    <th>Doctor Name</th>
                    <th>Type</th>
                    <th>Issue Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['certificate_number']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($row['patient_name']); ?><br>
                        <small class="text-muted"><?php echo htmlspecialchars($row['patient_id']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($row['diagnosis']); ?></td>
                    <td><?php echo htmlspecialchars($row['doctor_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['certificate_type']); ?></td>
                    <td><?php echo htmlspecialchars(formatDateDMY($row['issue_date'])); ?></td>
                    <td>
                        <button class="btn btn-warning btn-sm" onclick="editCertificate(<?php echo $row['id']; ?>)">Edit</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteCertificate(<?php echo $row['id']; ?>)">Delete</button>
                        <button class="btn btn-info btn-sm" onclick="printCertificate(<?php echo $row['id']; ?>, '<?php echo $row['certificate_type']; ?>')">Print</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Edit Certificate Modal -->
    <div class="modal fade" id="editCertificateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Certificate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editCertificateForm">
                        <input type="hidden" id="editCertificateId">
                        <div class="form-group mb-3">
                            <label class="required">Diagnosis</label>
                            <textarea class="form-control" id="editDiagnosis" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="required">Start Date</label>
                                    <input type="date" class="form-control" id="editStartDate" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="required">End Date</label>
                                    <input type="date" class="form-control" id="editEndDate" required>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveCertificateChanges">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function deleteCertificate(certificateId) {
            if (!confirm('Are you sure you want to delete this certificate?')) {
                return;
            }

            $.ajax({
                url: '../../includes/helpers/certificate_helper.php',
                method: 'POST',
                data: {
                    action: 'deleteCertificate',
                    certificateId: certificateId
                },
                success: function(response) {
                    if (response.success) {
                        alert('Certificate deleted successfully!');
                        location.reload();
                    } else {
                        alert('Failed to delete certificate. Please try again.');
                    }
                },
                error: function() {
                    alert('Error occurred while deleting the certificate.');
                }
            });
        }

        function editCertificate(certificateId) {
            $.ajax({
                url: '../../includes/helpers/certificate_helper.php',
                method: 'POST',
                data: {
                    action: 'getCertificateDetails',
                    certificateId: certificateId
                },
                success: function(response) {
                    if (response.success) {
                        $('#editCertificateId').val(certificateId);
                        $('#editDiagnosis').val(response.certificate.diagnosis);
                        $('#editStartDate').val(response.certificate.start_date);
                        $('#editEndDate').val(response.certificate.end_date);
                        
                        const editModal = new bootstrap.Modal(document.getElementById('editCertificateModal'));
                        editModal.show();
                    } else {
                        alert('Error loading certificate details: ' + response.error);
                    }
                },
                error: function() {
                    alert('Failed to load certificate details');
                }
            });
        }

        $('#saveCertificateChanges').click(function() {
            const updateData = {
                action: 'editCertificate',
                certificateId: $('#editCertificateId').val(),
                diagnosis: $('#editDiagnosis').val(),
                startDate: $('#editStartDate').val(),
                endDate: $('#editEndDate').val()
            };

            $.ajax({
                url: '../../includes/helpers/certificate_helper.php',
                method: 'POST',
                data: updateData,
                success: function(response) {
                    if (response.success) {
                        bootstrap.Modal.getInstance('#editCertificateModal').hide();
                        location.reload();
                    } else {
                        alert('Error updating certificate: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to update certificate');
                }
            });
        });

        function printCertificate(certificateId, certificateType) {
            const printUrl = `print_cert.php?id=${certificateId}&type=${certificateType}`;
            const printWin = window.open(printUrl, '_blank');
            if (printWin) {
                printWin.onafterprint = function() {
                    printWin.close();
                };
            }
        }
    </script>
</body>
</html>