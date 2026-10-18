<?php
define('SECURE_ACCESS', true);
define('SETUP_PAGE', true);
require_once __DIR__ . '/../../includes/helpers/settings_helper.php';

// Setup is only available until hospital details have been saved once
if (isHospitalConfigured()) {
    header('Location: ../auth/login.php');
    exit;
}

function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function clean($v) { return trim(strip_tags((string) $v)); }
function digits($v) { return preg_replace('/[^0-9+\-\s,]/', '', (string) $v); }

$errors = [];
$data = [
    'hospital_name' => '', 'registration_no' => '', 'gst_number' => '', 'drug_license' => '',
    'address' => '', 'phone_primary' => '', 'phone_secondary' => '', 'email' => '',
    'website' => '', 'working_hours_start' => '', 'working_hours_end' => '',
    'emergency_contact' => '', 'timezone' => 'Asia/Kolkata', 'hospital_code' => '', 'doctor_aliases' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['hospital_name', 'registration_no', 'gst_number', 'drug_license', 'address', 'email', 'website'] as $k) {
        $data[$k] = clean($_POST[$k] ?? '');
    }
    foreach (['phone_primary', 'phone_secondary', 'emergency_contact'] as $k) {
        $data[$k] = digits($_POST[$k] ?? '');
    }
    $data['working_hours_start'] = clean($_POST['working_hours_start'] ?? '');
    $data['working_hours_end'] = clean($_POST['working_hours_end'] ?? '');
    $data['hospital_code'] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['hospital_code'] ?? '')), 0, 10));
    $data['doctor_aliases'] = parseDoctorAliases($_POST['doctor_aliases'] ?? '');
    $tz = clean($_POST['timezone'] ?? '');
    $data['timezone'] = in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Asia/Kolkata';

    if ($data['hospital_name'] === '') {
        $errors[] = 'Hospital name is required.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }

    $data['logo'] = '';
    if (!$errors && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        try {
            $data['logo'] = saveHospitalLogo($_FILES['logo']);
        } catch (Exception $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        $data['last_updated'] = date('d-m-Y H:i:s');
        $ok = file_put_contents(HOSPITAL_SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($ok === false) {
            $errors[] = 'Could not write config/hospital_settings.json. Check folder permissions.';
        } else {
            syncBillSettings($data);
            header('Location: ../auth/login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Initial Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 820px;">
    <div class="card shadow-sm">
        <div class="card-header"><h4 class="mb-0">Welcome! Set up your hospital</h4></div>
        <div class="card-body">
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger py-2"><?php echo e($err); ?></div>
            <?php endforeach; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Hospital Name *</label>
                        <input class="form-control" name="hospital_name" value="<?php echo e($data['hospital_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Registration No.</label>
                        <input class="form-control" name="registration_no" value="<?php echo e($data['registration_no']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"><?php echo e($data['address']); ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Primary Phone</label>
                        <input class="form-control" name="phone_primary" value="<?php echo e($data['phone_primary']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Secondary Phone</label>
                        <input class="form-control" name="phone_secondary" value="<?php echo e($data['phone_secondary']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Emergency Contact</label>
                        <input class="form-control" name="emergency_contact" value="<?php echo e($data['emergency_contact']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo e($data['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Website</label>
                        <input class="form-control" name="website" value="<?php echo e($data['website']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">GST Number</label>
                        <input class="form-control" name="gst_number" value="<?php echo e($data['gst_number']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Drug License</label>
                        <input class="form-control" name="drug_license" value="<?php echo e($data['drug_license']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Working Hours Start</label>
                        <input type="time" class="form-control" name="working_hours_start" value="<?php echo e($data['working_hours_start']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Working Hours End</label>
                        <input type="time" class="form-control" name="working_hours_end" value="<?php echo e($data['working_hours_end']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Timezone</label>
                        <select class="form-select" name="timezone">
                            <?php foreach (timezone_identifiers_list() as $tz): ?>
                                <option value="<?php echo e($tz); ?>" <?php echo $tz === $data['timezone'] ? 'selected' : ''; ?>><?php echo e($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Bill Code Prefix</label>
                        <input class="form-control" name="hospital_code" maxlength="10" placeholder="e.g. ABC" value="<?php echo e($data['hospital_code']); ?>">
                        <div class="form-text">Used in bill numbers (ABC/2025-26/H/1). Blank = initials of name.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Doctor Display Names</label>
                        <textarea class="form-control" name="doctor_aliases" rows="3" placeholder="Database Name = Display Name (one per line)"><?php echo e(formatDoctorAliases($data['doctor_aliases'])); ?></textarea>
                        <div class="form-text">Optional. Used on tokens, e.g. "Jane Doe = Dr. Jane mam". Others get a "Dr." prefix automatically.</div>
                    </div>
                    <div class="col-12">
                        <hr>
                        <h6>Hospital Logo</h6>
                        <input type="file" class="form-control" name="logo" id="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                        <div class="form-text">PNG, JPG, GIF or WEBP, max 2 MB. Shown on bills and reports.</div>
                        <img id="logoPreview" class="mt-2 d-none" style="max-height: 80px;" alt="Logo preview">
                    </div>
                </div>
                <div class="text-end mt-4">
                    <button class="btn btn-primary px-4" type="submit">Save &amp; Continue</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('logo').addEventListener('change', function () {
    var p = document.getElementById('logoPreview');
    if (this.files && this.files[0]) {
        p.src = URL.createObjectURL(this.files[0]);
        p.classList.remove('d-none');
    }
});
</script>
</body>
</html>
