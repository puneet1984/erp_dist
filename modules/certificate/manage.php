<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is a doctor or admin
if (!isDoctor() && !isAdmin()) {
    header('Location: ../../unauthorized.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Certificates</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/certificates.css">
    <style>
        .search-section {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .certificates-list {
            margin-top: 20px;
        }
        .certificate-row:hover {
            background-color: #f5f5f5;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include_once '../../includes/simple_action_bar.php'; ?>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Manage Certificates</h2>
            <a href="index.php" class="btn btn-primary">Create New Certificate</a>
        </div>

        <div class="search-section">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Search by Patient ID</label>
                        <input type="text" class="form-control" id="searchPatientId">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" class="form-control" id="fromDate">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" class="form-control" id="toDate">
                    </div>
                </div>
            </div>
        </div>

        <div class="certificates-list">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Certificate No.</th>
                        <th>Patient Name</th>
                        <th>Type</th>
                        <th>Issue Date</th>
                        <th>Diagnosis</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="certificatesTableBody">
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
                            <div class="mb-3">
                                <label class="required">Diagnosis</label>
                                <textarea class="form-control" id="editDiagnosis" rows="3" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="required">Start Date</label>
                                <input type="date" class="form-control" id="editStartDate" required>
                            </div>
                            <div class="mb-3">
                                <label class="required">End Date</label>
                                <input type="date" class="form-control" id="editEndDate" required>
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
    </div>

    <script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Load initial certificates
            loadCertificates();

            // Search functionality
            $('#searchPatientId, #fromDate, #toDate').on('change', function() {
                loadCertificates();
            });

            function loadCertificates() {
                const searchData = {
                    action: 'getCertificates',
                    patientId: $('#searchPatientId').val(),
                    fromDate: $('#fromDate').val(),
                    toDate: $('#toDate').val()
                };

                $.post('../../includes/helpers/certificate_helper.php', searchData, function(response) {
                    if (response.success) {
                        displayCertificates(response.certificates);
                    } else {
                        alert('Error loading certificates: ' + response.error);
                    }
                });
            }

            function displayCertificates(certificates) {
                let html = '';
                certificates.forEach(cert => {
                    html += `
                        <tr class="certificate-row">
                            <td>${cert.certificate_number}</td>
                            <td>${cert.patient_name}</td>
                            <td>${cert.certificate_type?.toUpperCase()}</td>
                            <td>${cert.issue_date}</td>
                            <td>${cert.diagnosis}</td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-cert" data-id="${cert.id}">Edit</button>
                                <button class="btn btn-sm btn-danger delete-cert" data-id="${cert.id}">Delete</button>
                            </td>
                        </tr>`;
                });
                $('#certificatesTableBody').html(html);
            }

            // Edit certificate
            $(document).on('click', '.edit-cert', function() {
                const certId = $(this).data('id');
                $.post('../../includes/helpers/certificate_helper.php', {
                    action: 'getCertificateDetails',
                    certificateId: certId
                }, function(response) {
                    if (response.success) {
                        $('#editCertificateId').val(certId);
                        $('#editDiagnosis').val(response.certificate.diagnosis);
                        $('#editStartDate').val(response.certificate.start_date);
                        $('#editEndDate').val(response.certificate.end_date);
                        new bootstrap.Modal('#editCertificateModal').show();
                    }
                });
            });

            // Save certificate changes
            $('#saveCertificateChanges').click(function() {
                const updateData = {
                    action: 'editCertificate',
                    certificateId: $('#editCertificateId').val(),
                    diagnosis: $('#editDiagnosis').val(),
                    startDate: $('#editStartDate').val(),
                    endDate: $('#editEndDate').val()
                };

                $.post('../../includes/helpers/certificate_helper.php', updateData, function(response) {
                    if (response.success) {
                        bootstrap.Modal.getInstance('#editCertificateModal').hide();
                        loadCertificates();
                    } else {
                        alert('Error updating certificate: ' + response.error);
                    }
                });
            });

            // Delete certificate
            $(document).on('click', '.delete-cert', function() {
                if (confirm('Are you sure you want to delete this certificate?')) {
                    const certId = $(this).data('id');
                    $.post('../../includes/helpers/certificate_helper.php', {
                        action: 'deleteCertificate',
                        certificateId: certId
                    }, function(response) {
                        if (response.success) {
                            loadCertificates();
                        } else {
                            alert('Error deleting certificate: ' + response.error);
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
