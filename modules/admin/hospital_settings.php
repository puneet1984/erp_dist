<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check admin permissions
if (!checkPermission('admin', 'edit')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized access']));
    }
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Load current settings at page load
$settings = getHospitalSettings();
if (empty($settings)) {
    $settings = [
        'hospital_name' => '',
        'registration_no' => '',
        'gst_number' => '',
        'drug_license' => '',
        'address' => '',
        'phone_primary' => '',
        'phone_secondary' => '',
        'email' => '',
        'website' => '',
        'working_hours_start' => '',
        'working_hours_end' => '',
        'emergency_contact' => '',
        'timezone' => 'Asia/Kolkata',
        'logo' => '',
        'last_updated' => ''
    ];
}

// Handle AJAX save request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'hospital_name' => capitalizeWords(sanitizeInput($_POST['hospital_name'] ?? '')),
        'registration_no' => sanitizeInput($_POST['registration_no'] ?? ''),
        'gst_number' => sanitizeInput($_POST['gst_number'] ?? ''),
        'drug_license' => sanitizeInput($_POST['drug_license'] ?? ''),
        'address' => capitalizeWords(sanitizeInput($_POST['address'] ?? '')),
        'phone_primary' => sanitizeNumeric($_POST['phone_primary'] ?? ''),
        'phone_secondary' => sanitizeNumeric($_POST['phone_secondary'] ?? ''),
        'email' => sanitizeInput($_POST['email'] ?? ''),
        'website' => sanitizeInput($_POST['website'] ?? ''),
        'working_hours_start' => $_POST['working_hours_start'] ?? '',
        'working_hours_end' => $_POST['working_hours_end'] ?? '',
        'emergency_contact' => sanitizeNumeric($_POST['emergency_contact'] ?? ''),
        'timezone' => $_POST['timezone'] ?? 'IST',
        'last_updated' => date('d-m-Y H:i:s'),
        'hospital_code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['hospital_code'] ?? '')), 0, 10)),
        'doctor_aliases' => parseDoctorAliases($_POST['doctor_aliases'] ?? ''),
        'logo' => getSetting('logo')
    ];

    try {
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $settings['logo'] = saveHospitalLogo($_FILES['logo']);
        }
        if (file_put_contents(HOSPITAL_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            syncBillSettings($settings);
            die(json_encode(['status' => 'success']));
        } else {
            throw new Exception('Failed to write settings file');
        }
    } catch (Exception $e) {
        die(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
    }
}

function safeHtmlValue($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$page_title = 'Hospital Settings';
$extra_css = ['../../assets/css/admin.css'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/admin.css">
<style>
    .container{
        width:800px;
    }
    .action-bar {
        position: sticky;
        top: 16px;
        z-index: 10;
        background: #f8f9fa;
    }
</style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container mt-4">
        <div class="card mb-4 shadow-sm border-0 action-bar">
            <div class="card-body d-flex justify-content-between align-items-center py-3">
                <div>
                    <h2 class="mb-0 h4">Hospital Settings</h2>
                </div>
                <div class="d-flex gap-4">
                    <button type="button" class="btn btn-outline-secondary" id="resetHospitalSettings">Reset</button>
                    <button type="submit" form="hospitalSettingsForm" class="btn btn-primary px-4">Save Settings</button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Basic Information</h3>
            </div>
            <div class="card-body">
                <form id="hospitalSettingsForm">
                    <div class="row">
                        <!-- Left Column -->
                        <div class="col-md-6 pe-4">
                            <div class="mb-3">
                                <label class="form-label">Hospital Name:</label>
                                <input type="text" class="form-control" name="hospital_name" 
                                       value="<?php echo safeHtmlValue($settings['hospital_name']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">GST Number:</label>
                                <input type="text" class="form-control" name="gst_number" 
                                       value="<?php echo safeHtmlValue($settings['gst_number'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Address:</label>
                                <textarea class="form-control" name="address" required rows="3"><?php echo safeHtmlValue($settings['address']); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Primary Phone:</label>
                                <input type="tel" class="form-control" name="phone_primary" 
                                       value="<?php echo safeHtmlValue($settings['phone_primary']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Secondary Phone:</label>
                                <input type="tel" class="form-control" name="phone_secondary" 
                                       value="<?php echo safeHtmlValue($settings['phone_secondary']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Working Hours Start:</label>
                                <input type="time" class="form-control" name="working_hours_start" 
                                       value="<?php echo safeHtmlValue($settings['working_hours_start']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Logo:</label>
                                <input type="file" class="form-control" name="logo" accept="image/*">
                                <small class="form-text text-muted">Recommended size: 300x100 pixels</small>
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Registration Number:</label>
                                <input type="text" class="form-control" name="registration_no" 
                                       value="<?php echo safeHtmlValue($settings['registration_no']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Drug License Number:</label>
                                <input type="text" class="form-control" name="drug_license" 
                                       value="<?php echo safeHtmlValue($settings['drug_license'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email:</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?php echo safeHtmlValue($settings['email']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Website:</label>
                                <input type="url" class="form-control" name="website" 
                                       value="<?php echo safeHtmlValue($settings['website']); ?>" placeholder="https://">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Emergency Contact:</label>
                                <input type="tel" class="form-control" name="emergency_contact" 
                                       value="<?php echo safeHtmlValue($settings['emergency_contact']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Working Hours End:</label>
                                <input type="time" class="form-control" name="working_hours_end" 
                                       value="<?php echo safeHtmlValue($settings['working_hours_end']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Timezone:</label>
                                <select class="form-select" name="timezone" required>
                                    <option value="Asia/Kolkata" <?php echo $settings['timezone'] === 'Asia/Kolkata' ? 'selected' : ''; ?>>India (IST)</option>
                                    <option value="UTC" <?php echo $settings['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 pe-4 mb-3">
                            <label class="form-label">Bill Code Prefix:</label>
                            <input type="text" class="form-control" name="hospital_code" maxlength="10"
                                   value="<?php echo safeHtmlValue($settings['hospital_code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Doctor Display Names:</label>
                            <textarea class="form-control" name="doctor_aliases" rows="3" placeholder="Database Name = Display Name"><?php echo safeHtmlValue(formatDoctorAliases($settings['doctor_aliases'] ?? [])); ?></textarea>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script>
        const form = document.getElementById('hospitalSettingsForm');
        const actionBarSubmitBtn = document.querySelector('button[form="hospitalSettingsForm"]');

        document.getElementById('resetHospitalSettings').addEventListener('click', function () {
            form.reset();
        });

        form.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            // Show loading state
            const submitBtn = actionBarSubmitBtn;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = 'Saving...';
            submitBtn.disabled = true;

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Show success message using Bootstrap alert
                    const alert = document.createElement('div');
                    alert.className = 'alert alert-success alert-dismissible fade show mt-3';
                    alert.innerHTML = `
                        Settings saved successfully!
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    this.insertAdjacentElement('beforebegin', alert);
                } else {
                    throw new Error(data.message);
                }
            })
            .catch(error => {
                // Show error message using Bootstrap alert
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger alert-dismissible fade show mt-3';
                alert.innerHTML = `
                    Error: ${error.message || 'An error occurred while saving settings'}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                this.insertAdjacentElement('beforebegin', alert);
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        };
    </script>
</body>
</html>