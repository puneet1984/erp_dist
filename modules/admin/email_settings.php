<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

// Check admin permissions
if (!checkPermission('admin', 'view')) {
    header('Location: ../../auth/unauthorized.php');
    exit;
}

if (!checkSessionTimeout()) {
    redirectToLogin('timeout=1');
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'test':
            if (empty($_POST['test_email'])) {
                echo json_encode(['status' => 'error', 'message' => 'Email address is required']);
                exit;
            }

            require_once '../../includes/helpers/email_helper.php';
            $result = sendEmail(
                $_POST['test_email'],
                'Test Email',
                '<h1>This is a test email from the system.</h1><p>If you receive this, the email service is working correctly.</p>'
            );
            echo json_encode($result);
            exit;
            
        case 'save':
            if (!checkPermission('admin', 'edit')) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
                exit;
            }
            $current = getEmailSettings();
            $port = (int) ($_POST['smtp_port'] ?? 587);
            $enc = $_POST['smtp_encryption'] ?? 'tls';
            if ($port < 1 || $port > 65535 || !in_array($enc, ['tls', 'ssl', 'none'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid port or encryption']);
                exit;
            }
            $new = [
                'smtp_host' => trim(strip_tags($_POST['smtp_host'] ?? '')),
                'smtp_port' => $port,
                'smtp_username' => trim(strip_tags($_POST['smtp_username'] ?? '')),
                // blank password keeps the stored one
                'smtp_password' => ($_POST['smtp_password'] ?? '') !== '' ? $_POST['smtp_password'] : $current['smtp_password'],
                'smtp_encryption' => $enc,
                'api_key' => $current['api_key'],
            ];
            if (!empty($_POST['regenerate_api_key'])) {
                $new['api_key'] = bin2hex(random_bytes(24));
            }
            if (!saveEmailSettings($new)) {
                echo json_encode(['status' => 'error', 'message' => 'Could not write config/email_settings.json']);
                exit;
            }
            echo json_encode(['status' => 'success', 'message' => 'Email settings saved', 'api_key' => $new['api_key']]);
            exit;
    }
}

$page_title = 'Email SMTP Settings';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.js" integrity="sha512-lbwH47l/tPXJYG9AcFNoJaTMhGvYWhVM9YI43CT+uteTRRaiLCui8snIgyAN8XWgNjNhCqlAUdzZptso6OCoFQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.css" integrity="sha512-6S2HWzVFxruDlZxI3sXOZZ4/eJ8AcxkQH1+JjSe/ONCEqR9L4Ysq5JdT5ipqtzU7WHalNwzwBv+iE51gNHJNqQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
		.container{
			max-width: 800px;
			margin: 20px auto;
			padding: 0 15px;
		
		}
        .page-header {
            margin-bottom: 2rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 1rem;
        }
        .page-header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 2rem;
            font-weight: 600;
        }
        .subtitle {
            color: #7f8c8d;
            margin: 0.5rem 0 0;
            font-size: 1rem;
        }
        .settings-section {
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .form-group {
            flex: 1;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #34495e;
            font-weight: 500;
        }
        .form-group input {
            width: 95%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        .form-actions {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        .form-actions button {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .form-actions button[type="submit"] {
            background: #3498db;
            color: white;
            margin-right: 1rem;
        }
        .form-actions button[type="button"] {
            background: #ecf0f1;
            color: #2c3e50;
        }
        .form-actions button:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

<div class="container">
    <div class="page-header">
        <h1>Email SMTP Settings</h1>
        <p class="subtitle">Email is sent directly through SMTP. Settings are saved in config/email_settings.json.</p>
    </div>

    <div class="settings-section">
        <form id="emailSettingsForm">
            <div class="form-row">
                <div class="form-group">
                    <label>SMTP Host:</label>
                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars(SMTP_HOST); ?>" style="width: 100%;" placeholder="smtp.example.com">
                </div>
                <div class="form-group">
                    <label>SMTP Port:</label>
                    <input type="number" name="smtp_port" value="<?php echo (int) SMTP_PORT; ?>" style="width: 100%;" min="1" max="65535">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="smtp_username" value="<?php echo htmlspecialchars(SMTP_USERNAME); ?>" style="width: 100%;" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="smtp_password" value="" style="width: 100%;" autocomplete="new-password"
                           placeholder="<?php echo SMTP_PASSWORD !== '' ? 'Saved - leave blank to keep' : ''; ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Encryption:</label>
                    <select name="smtp_encryption" style="width: 100%; padding: 0.75rem;">
                        <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l): ?>
                            <option value="<?php echo $v; ?>" <?php echo SMTP_ENCRYPTION === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>API Key (for /api/send_email.php):</label>
                    <input type="text" id="apiKey" value="<?php echo htmlspecialchars(EMAIL_API_KEY); ?>" style="width: 100%;" readonly placeholder="Not set - API disabled">
                    <label style="font-weight: normal; margin-top: 0.5rem;">
                        <input type="checkbox" name="regenerate_api_key" value="1" style="width: auto;"> Generate new key on save
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit">Save Settings</button>
                <button type="button" onclick="testEmail()">Test Connection</button>
            </div>
        </form>
    </div>
</div>
        
<script>
    document.getElementById('emailSettingsForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'save');
        fetch('email_settings.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    toastr.success(data.message);
                    document.getElementById('apiKey').value = data.api_key || '';
                    this.regenerate_api_key.checked = false;
                    this.smtp_password.value = '';
                } else {
                    toastr.error(data.message);
                }
            })
            .catch(err => toastr.error('Error: ' + err.message));
    });

    function testEmail() {
        const testEmail = prompt("Enter email address for testing:");
        if (!testEmail) return;
        const formData = new FormData();
        formData.append('action', 'test');
        formData.append('test_email', testEmail);
        
        fetch('email_settings.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                toastr.success('Test email sent successfully');
            } else {
                toastr.error('Failed to send test email: ' + data.message);
            }
        })
        .catch(error => {
            toastr.error('Error: ' + error.message);
        });
    }

</script>
</body>
</html>