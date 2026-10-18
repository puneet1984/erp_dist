<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$page_title = 'SMS Settings';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>
<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container py-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">SMS Configuration</h4>
                    </div>
                    <div class="card-body">
                        <form id="smsSettingsForm" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Provider</label>
                                <select class="form-select" name="provider" required onchange="toggleProviderFields()">
                                    <option value="local_api">Android SMS Gateway</option>
                                    <option value="textlocal">Textlocal</option>
                                    <option value="twilio">Twilio</option>
                                    <option value="msg91">MSG91</option>
                                </select>
                            </div>

                            <div class="provider-fields" id="local_api-fields" style="display:none">
                                <div class="alert alert-info">
                                    Using preconfigured Android SMS Gateway settings.
                                </div>
                            </div>

                            <div class="provider-fields" id="textlocal-fields">
                                <div class="mb-3">
                                    <label class="form-label">API Key</label>
                                    <input type="text" class="form-control" name="textlocal_api_key">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Sender ID</label>
                                    <input type="text" class="form-control" name="textlocal_sender" maxlength="6">
                                </div>
                            </div>

                            <div class="provider-fields" id="twilio-fields" style="display:none">
                                <div class="mb-3">
                                    <label class="form-label">Account SID</label>
                                    <input type="text" class="form-control" name="twilio_sid">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Auth Token</label>
                                    <input type="password" class="form-control" name="twilio_token">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">From Number</label>
                                    <input type="text" class="form-control" name="twilio_from">
                                </div>
                            </div>

                            <div class="border-top pt-3 mt-3">
                                <div class="mb-3">
                                    <label class="form-label">Test Number</label>
                                    <div class="input-group">
                                        <span class="input-group-text">+91</span>
                                        <input type="text" class="form-control" name="test_number" 
                                            pattern="[0-9]{10}" title="Please enter a valid 10-digit mobile number"
                                            placeholder="10 digit mobile number" maxlength="10" minlength="10" required>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Save Settings</button>
                                    <button type="button" class="btn btn-secondary" onclick="testSMS()">Test SMS</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleProviderFields() {
            const provider = document.querySelector('select[name="provider"]').value;
            document.querySelectorAll('.provider-fields').forEach(el => el.style.display = 'none');
            document.getElementById(provider + '-fields').style.display = 'block';
        }

        function testSMS() {
            const testNumber = document.querySelector('input[name="test_number"]');
            if (!testNumber.checkValidity()) {
                alert('Please enter a valid 10-digit mobile number');
                return;
            }

            fetch('test_sms.php', {
                    method: 'POST',
                    body: new FormData(document.getElementById('smsSettingsForm'))
                })
                .then(response => response.json())
                .then(data => {
                    alert(data.message);
                });
        }

        document.getElementById('smsSettingsForm').onsubmit = function(e) {
            e.preventDefault();
            fetch('save_sms_settings.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('SMS settings saved successfully!');
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        };
    </script>
</body>
</html>