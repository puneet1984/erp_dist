<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check admin permissions
if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Add this helper function at the top
// function capitalizeWords($string)
// {
//     // Split on spaces and punctuation, but preserve the delimiters
//     return preg_replace_callback('/[^,.:;!?]++/u', function ($match) {
//         return mb_convert_case($match[0], MB_CASE_TITLE, "UTF-8");
//     }, $string);
// }

// Fix the config file path and POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get the absolute path to config directory
        $config_dir = realpath(__DIR__ . '/../../config');
        if (!$config_dir) {
            // Create config directory if it doesn't exist
            $config_dir = __DIR__ . '/../../config';
            if (!mkdir($config_dir, 0755, true)) {
                throw new Exception("Failed to create config directory");
            }
        }

        $config_file = $config_dir . '/bill_settings.json';
        error_log("Config file path: " . $config_file); // Debug log

        // Debug: Print received data
        error_log("POST data: " . print_r($_POST, true));

        // Create settings array
        $settings = [
            'hospital_name' => capitalizeWords(sanitizeInput($_POST['hospital_name'])),
            'address_line1' => capitalizeWords(sanitizeInput($_POST['address_line1'])),
            'address_line2' => capitalizeWords(sanitizeInput($_POST['address_line2'])),
            'contact_number' => sanitizeInput($_POST['contact_number']),
            'gst_number' => strtoupper(sanitizeInput($_POST['gst_number'])),
            'drug_license' => strtoupper(sanitizeInput($_POST['drug_license'])),
            'email' => strtolower(sanitizeInput($_POST['email'])),
            'footer_text' => sanitizeInput($_POST['footer_text']),
            'terms_conditions' => sanitizeInput($_POST['terms_conditions']),
            'invoice_prefix' => strtoupper(sanitizeInput($_POST['invoice_prefix'])),
            'payment_terms' => (int)($_POST['payment_terms'] ?? 30),
            'late_payment_fee' => (float)($_POST['late_fee'] ?? 2),
            'default_currency' => sanitizeInput($_POST['currency'] ?? 'INR')
        ];

        // Remove any null or empty string values
        $settings = array_filter($settings, function ($value) {
            return $value !== null && $value !== '';
        });

        // Encode with pretty print and error checking
        $json_data = json_encode($settings, JSON_PRETTY_PRINT);
        if ($json_data === false) {
            throw new Exception("JSON encode error: " . json_last_error_msg());
        }

        // Write file with error checking
        if (file_put_contents($config_file, $json_data) === false) {
            throw new Exception("Failed to write settings file. Check permissions.");
        }

        error_log("Settings saved successfully to: " . $config_file);
        echo json_encode(['status' => 'success']);
        exit;

    } catch (Exception $e) {
        error_log("Error saving settings: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Load current settings
$settings = getBillSettings();
?>
<!DOCTYPE html>
<html>

<head>
	<title>Billing Settings</title>
	<link rel="stylesheet" href="../../assets/css/admin.css">
	<link rel="stylesheet" href="../../assets/css/billing-settings.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"
		integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Billing Settings</h2>
		<!-- Combine both forms into one -->
		<form id="settingsForm" method="POST">
			<div class="settings-section">
				<h3>General Settings</h3>
				<div class="form-row">
					<div class="form-group">
						<label>Invoice Prefix:</label>
						<input type="text" name="invoice_prefix"
							value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? ''); ?>">
					</div>
					<div class="form-group">
						<label>Default Payment Terms (days):</label>
						<input type="number" name="payment_terms" min="0"
							value="<?php echo htmlspecialchars($settings['payment_terms'] ?? ''); ?>">
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label>Late Payment Fee (%):</label>
						<input type="number" name="late_fee" min="0" max="100" step="0.01"
							value="<?php echo htmlspecialchars($settings['late_payment_fee'] ?? ''); ?>">
					</div>
					<div class="form-group">
						<label>Default Currency:</label>
						<select name="currency">
							<option value="INR" <?php echo ($settings['default_currency'] ?? 'INR') === 'INR' ? 'selected' : ''; ?>>Indian
								Rupee (₹)</option>
							<option value="USD" <?php echo ($settings['default_currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>US
								Dollar ($)</option>
						</select>
					</div>
				</div>
			</div>

			<div class="settings-section">
				<h3>Bill Format Settings</h3>
				<!-- Hospital Info -->
				<div class="form-row">
					<div class="form-group full-width">
						<label>Hospital Name:</label>
						<input type="text" name="hospital_name"
							value="<?php echo htmlspecialchars($settings['hospital_name'] ?? ''); ?>">
					</div>
				</div>
				<div class="form-row">
					<div class="form-group full-width">
						<label>Address Line 1:</label>
						<input type="text" name="address_line1"
							value="<?php echo htmlspecialchars($settings['address_line1'] ?? ''); ?>">
					</div>
				</div>
				<div class="form-row">
					<div class="form-group full-width">
						<label>Address Line 2:</label>
						<input type="text" name="address_line2"
							value="<?php echo htmlspecialchars($settings['address_line2'] ?? ''); ?>">
					</div>
				</div>

				<!-- Contact Info -->
				<div class="form-row">
					<div class="form-group">
						<label>Contact Number:</label>
						<input type="text" name="contact_number"
							value="<?php echo htmlspecialchars($settings['contact_number'] ?? ''); ?>">
					</div>
					<div class="form-group">
						<label>Email:</label>
						<input type="email" name="email"
							value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>"
							placeholder="Enter Email">
					</div>
				</div>

				<!-- Legal Info -->
				<div class="form-row">
					<div class="form-group">
						<label>GST Number:</label>
						<input type="text" name="gst_number"
							value="<?php echo htmlspecialchars($settings['gst_number'] ?? ''); ?>"
							placeholder="Enter GST Number">
					</div>
					<div class="form-group">
						<label>Drug License Number:</label>
						<input type="text" name="drug_license"
							value="<?php echo htmlspecialchars($settings['drug_license'] ?? ''); ?>"
							placeholder="Enter Drug License Number">
					</div>
				</div>

				<!-- Footer and Terms -->
				<div class="form-row">
					<div class="form-group full-width">
						<label>Footer Text:</label>
						<textarea name="footer_text"
							rows="3"><?php echo htmlspecialchars($settings['footer_text'] ?? ''); ?></textarea>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group full-width">
						<label>Terms & Conditions:</label>
						<textarea name="terms_conditions"
							rows="4"><?php echo htmlspecialchars($settings['terms_conditions'] ?? ""); ?></textarea>
					</div>
				</div>

				<button type="submit" class="btn-submit">Save All Settings</button>
			</div>
		</form>
	</div>

	<script>
		$(document).ready(function() {
			$('#settingsForm').on('submit', function(e) {
				e.preventDefault();
				const formData = $(this).serialize();

				$.ajax({
					url: 'manage_billing.php',
					type: 'POST',
					data: formData,
					dataType: 'json',
					success: function(response) {
						if (response.status === 'success') {
							alert('Settings saved successfully');
							location.reload();
						} else {
							alert('Error saving settings: ' + (response.message ||
								'Unknown error'));
						}
					},
					error: function(xhr) {
						console.error('Server Response:', xhr.responseText);
						alert('Error saving settings. Check console for details.');
					}
				});
			});
		});
	</script>
</body>

</html>