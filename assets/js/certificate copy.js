const handlerUrl = '../../includes/helpers/certificate_helper.php';

$(document).ready(function() {
    try {
        // Initialize date inputs with formatted display values
        $('.date-input').each(function() {
            const rawDate = $(this).val();
            if (rawDate) {
                const formattedDate = formatDate(rawDate);
                $(this).attr('data-formatted', formattedDate);
            }
        });

        // Handle date input changes
        $('.date-input').on('change', function() {
            const rawDate = $(this).val();
            if (rawDate) {
                const formattedDate = formatDate(rawDate);
                $(this).attr('data-formatted', formattedDate);
            }
        });

        if (!handlerUrl) {
            throw new Error('Handler URL not defined');
        }

        // Fetch patient details and display previously issued certificates
        $('#patientId').on('blur', function() {
            const patientId = $(this).val();
            if (patientId) {
                $.ajax({
                    url: handlerUrl,
                    method: 'GET',
                    data: { search: patientId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.found && response.patient) {
                            const patient = response.patient;
                            $('#patientName').val(patient.name);
                            $('#patientAge').val(patient.age);
                            $('#patientGender').val(patient.gender);
                            $('#patientAddress').val(patient.address);
                            $('#patientPhone').val(patient.phone);

                            // Fetch previously issued certificates
                            $.ajax({
                                url: handlerUrl,
                                method: 'POST',
                                data: {
                                    action: 'getCertificates',
                                    patientId: patientId
                                },
                                success: function(certResponse) {
                                    try {
                                        // Parse response if string
                                        const data = typeof certResponse === 'string' ? JSON.parse(certResponse) : certResponse;
                                        
                                        if (!data.success) {
                                            throw new Error(data.error || 'Failed to fetch certificates');
                                        }
                                        
                                        if (data.certificates && data.certificates.length > 0) {
                                            let certHtml = '<h5>Previously Issued Certificates</h5><table class="table table-bordered">';
                                            certHtml += '<thead><tr><th>Type</th><th>Start Date</th><th>End Date</th><th>Diagnosis</th><th>Issue Date</th></tr></thead><tbody>';
                                            data.certificates.forEach(cert => {
                                                certHtml += `<tr>
                                                    <td>${cert.certificate_type?.toUpperCase() || 'N/A'}</td>
                                                    <td>${formatDate(cert.start_date) || 'N/A'}</td>
                                                    <td>${formatDate(cert.end_date) || 'N/A'}</td>
                                                    <td>${cert.diagnosis || 'N/A'}</td>
                                                    <td>${formatDate(cert.issue_date) || 'N/A'}</td>
                                                </tr>`;
                                            });
                                            certHtml += '</tbody></table>';
                                            $('#previousCertificates').html(certHtml);
                                        } else {
                                            $('#previousCertificates').html('<p>No previous certificates found for this patient.</p>');
                                        }
                                    } catch (e) {
                                        console.error('Certificate parsing error:', e);
                                        $('#previousCertificates').html('<p>Error processing certificates data.</p>');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('Certificate fetch error:', error);
                                    $('#previousCertificates').html('<p>Error fetching previous certificates. Please try again.</p>');
                                }
                            });
                        } else {
                            alert('Patient not found');
                            clearPatientFields();
                        }
                    },
                    error: function() {
                        alert('Error fetching patient details');
                        clearPatientFields();
                    }
                });
            }
        });

        $('#doctorId').change(function() {
            const selected = $(this).find('option:selected');
            $('#doctorReg').val(selected.data('reg'));
            $('#department').val(selected.data('dept'));
            
            const type = $('#certificateType').val();
            if (type) {
                $('#certificateType').trigger('change');
            }
        });

        $('#certificateType').change(function() {
            const type = $(this).val();
            const patientId = $('#patientId').val();
            if (!type) return;

            // Show/hide leave details based on certificate type
            $('#leaveDetails').toggle(type === 'leave');
            
            // Initialize end date if it's a leave certificate
            if (type === 'leave') {
                const startDate = $('#leaveStartDate').val();
                const leaveDays = parseInt($('#leaveDays').val()) || 0;
                if (startDate && leaveDays > 0) {
                    const endDate = new Date(startDate);
                    endDate.setDate(endDate.getDate() + leaveDays - 1);
                    const formattedEndDate = formatDate(endDate);
                    $('#endDate').val(formattedEndDate);
                }
            }

            $.ajax({
                url: handlerUrl,
                method: 'POST',
                data: {
                    action: 'getTemplate',
                    type: type,
                    patientId: patientId,
                    doctorId: $('#doctorId').val(),
                    issueDate: $('#issueDate').attr('data-formatted') || formatDate($('#issueDate').val()),
                    leaveDays: $('#leaveDays').val(),
                    leaveStartDate: $('#leaveStartDate').attr('data-formatted') || formatDate($('#leaveStartDate').val()),
                    endDate: $('#endDate').val(),
                    hospitalName: $('#hospitalName').val(),
                    diagnosis: $('#diagnosis').val()
                },
                success: function(response) {
                    try {
                        const data = typeof response === 'string' ? JSON.parse(response) : response;
                        
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        
                        if (!data.template) {
                            alert('Invalid template response');
                            return;
                        }
                        
                        // Set certificate number if available
                        if (data.certificateNumber) {
                            $('#certificateNumber').val(data.certificateNumber);
                        }
                        
                        $('#certificateContent').html(`
                            <div role="article" class="certificate-container">
                                <h3 class="certificate-title">${data.template.title}</h3>
                                <div class="certificate-text">${data.template.content}</div>
                            </div>
                        `);
                    } catch (e) {
                        if (response.includes('Unauthorized')) {
                            alert('Session expired. Please login again.');
                            window.location.href = '../../login.php';
                            return;
                        }
                        
                        console.error('Error parsing response:', e);
                        alert('Invalid server response. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Template fetch error:', error);
                    if (xhr.status === 401) {
                        alert('Session expired. Please login again.');
                        window.location.href = '../../login.php';
                        return;
                    }
                    alert('Failed to fetch template. Please try again.');
                }
            });
        });

        // Update certificate when leave details change
        $('#issueDate, #leaveDays, #leaveStartDate, #doctorName, #doctorReg, #department, #hospitalName').on('change', function() {
            // Calculate end date if leave days or start date changes
            if ($(this).attr('id') === 'leaveDays' || $(this).attr('id') === 'leaveStartDate') {
                const startDate = $('#leaveStartDate').val();
                const leaveDays = parseInt($('#leaveDays').val()) || 0;
                
                if (startDate && leaveDays > 0) {
                    const endDate = new Date(startDate);
                    endDate.setDate(endDate.getDate() + leaveDays - 1); // -1 because start date counts as day 1
                    const formattedEndDate = formatDate(endDate);
                    $('#endDate').val(formattedEndDate);
                    
                    // Trigger template update to reflect new end date
                    $('#certificateType').trigger('change');
                }
            }
            
            const type = $('#certificateType').val();
            if (type) {
                $('#certificateType').trigger('change');
            }
        });

        $('#certificateForm').submit(function(e) {
            e.preventDefault();
            
            if (!validateData()) {
                return;
            }

            const certificateHtml = $('#certificateContent').html();
            const printableHtml = `
                <div class="certificate-preview">
                    ${certificateHtml}
                </div>
            `;
            
            $('#previewContent').html(printableHtml);
            const previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
            previewModal.show();
        });

        $('#printCertificate').click(function() {
            createCertificate();

            const content = document.getElementById('previewContent').innerHTML;
            const originalBody = document.body.innerHTML;

            document.body.innerHTML = `
                <div class="print-wrapper">
                    <style>
                        @media print {
                            @page { size: A4 portrait;
                                    margin: 72mm 6mm 12mm 41mm;  /* top right bottom left */
                                    }
                                    /* Hide header/footer content injected by browser */
                            html {
                                height: 100%;
                            }
                            body { 
                                font-family: Arial, sans-serif !important;
                                font-size: 12px !important;
                                line-height: 1.5 !important;
                            }
                            .certificate-title {
                                font-family: Arial, sans-serif !important;
                                font-size: 16px !important;
                                font-weight: bold !important;
                                text-align: center !important;
                                margin-bottom: 20px !important;
                            }
                            .certificate-text {
                                font-family: Arial, sans-serif !important;
                                font-size: 12px !important;
                                line-height: 1.5 !important;
                                white-space: pre-wrap !important;
                                word-wrap: break-word !important;
                            }
                        }
                    </style>
                    ${content}
                </div>`;

            window.print();

            document.body.innerHTML = originalBody;
            location.reload();
        });

    } catch (e) {
        console.error('Initialization error:', e);
        alert('Failed to initialize certificate module.');
    }
});

function createCertificate() {
    const certificateData = {
        patientId: $('#patientId').val(),
        certificateType: $('#certificateType').val(),
        doctorId: $('#doctorId').val(),
        issueDate: $('#issueDate').attr('data-formatted') || formatDate($('#issueDate').val()),
        diagnosis: $('#diagnosis').val(),
        certificateNumber: $('#certificateNumber').val()
    };

    if ($('#certificateType').val() === 'leave') {
        Object.assign(certificateData, {
            leaveDays: $('#leaveDays').val(),
            leaveStartDate: $('#leaveStartDate').attr('data-formatted') || formatDate($('#leaveStartDate').val()),
            endDate: $('#endDate').val()
        });
    }

    $.ajax({
        url: handlerUrl,
        method: 'POST',
        data: {
            action: 'saveCertificate',
            ...certificateData
        },
        success: function(response) {
            if (!response.success) {
                alert('Error saving certificate: ' + (response.error || 'Unknown error'));
                return false;
            }
            return true;
        },
        error: function(xhr, status, error) {
            console.error('Save certificate error:', error);
            alert('Failed to save certificate. Please try again.');
            return false;
        }
    });
}

function validateData() {
    const requiredFields = {
        'Patient ID': $('#patientId').val(),
        'Certificate Type': $('#certificateType').val(),
        'Patient Name': $('#patientName').val(),
        'Patient Age': $('#patientAge').val(),
        'Patient Address': $('#patientAddress').val(),
        'Doctor': $('#doctorId').val(),
        'Hospital Name': $('#hospitalName').val(),
        'Date of Issue': $('#issueDate').val(),
        'Diagnosis': $('#diagnosis').val()
    };

    if ($('#certificateType').val() === 'leave') {
        Object.assign(requiredFields, {
            'Number of Leave Days': $('#leaveDays').val(),
            'Leave Start Date': formatDate($('#leaveStartDate').val()),
        });
    }

    // Check for empty fields
    const emptyFields = Object.entries(requiredFields)
        .filter(([_, value]) => !value)
        .map(([key]) => key);

    if (emptyFields.length > 0) {
        alert(`Please fill in the following required fields:\n${emptyFields.join('\n')}`);
        return false;
    }
    
    return true;
}

function fetchPatientDetails(patientId) {
    if (!patientId) return;

    $.ajax({
        url: handlerUrl,
        method: 'GET',
        data: { 
            search: patientId
        },
        dataType: 'json',
        success: function(response) {
            if (response.found && response.patient) {
                const patient = response.patient;
                $('#patientName').val(patient.name);
                $('#patientAge').val(patient.age);
                $('#patientGender').val(patient.gender);
                $('#patientAddress').val(patient.address);
                $('#patientPhone').val(patient.phone);
                
                const selectedType = $('#certificateType').val();
                if (selectedType) {
                    $('#certificateType').trigger('change');
                }
            } else {
                alert('Patient not found');
                clearPatientFields();
            }
        },
        error: function() {
            alert('Error fetching patient details');
            clearPatientFields();
        }
    });
}

function generateCertificate() {
    // Implement certificate generation logic
}

function clearPatientFields() {
    $('#patientName, #patientAge, #patientGender, #patientAddress, #patientPhone').val('');
    $('#certificateContent').empty();
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '';
    
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    
    return `${day}-${month}-${year}`;
}