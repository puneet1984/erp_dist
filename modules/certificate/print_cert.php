<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is a doctor or admin
if (!isDoctor() && !isAdmin()) {
    header('Location: ../../unauthorized.php');
    exit;
}

// Get certificate ID and type from URL
$certificateId = $_GET['id'] ?? '';
$type = $_GET['type'] ?? '';

if (!$certificateId || !$type) {
    exit('Invalid certificate parameters');
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Print Certificate</title>
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<style>
		@media print {
			@page {
				size: A4 portrait;
				margin: 72mm 6mm 12mm 41mm;
			}

			body {
				font-family: Arial, sans-serif !important;
				font-size: 12px !important;
				line-height: 1.5 !important;
				margin: 0;
				padding: 20px;
			}

			.certificate-title {
				font-size: 16px !important;
				font-weight: bold !important;
				text-align: center !important;
				margin-bottom: 20px !important;
			}

			.certificate-text {
				white-space: pre-wrap !important;
			}
		}
	</style>
</head>

<body>
	<div id="certificateContent"></div>

	<script>
		$(document).ready(function() {
			$.ajax({
				url: '../../includes/helpers/certificate_helper.php',
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'getCertificateForPrint',
					certificateId: <?php echo json_encode($certificateId); ?> ,
					type: <?php echo json_encode($type); ?>
				},
				success: function(response) {
					console.log('Certificate response:', response);
					if (response.success && response.template) {
						$('#certificateContent').html(`
                            <div class="certificate-container">
                                <h3 class="certificate-title">${response.template.title}</h3>
                                <div class="certificate-text">${response.template.content}</div>
                            </div>
                        `);
						setTimeout(function() {
							window.print();
						}, 100);
						window.onafterprint = function() {
							window.close();
						};
						// Fallback for browsers that don't support onafterprint
						setTimeout(function() {
							if (!window.closed) {
								window.close();
							}
						}, 3000);
					} else {
						alert('Error loading certificate: ' + (response.error || 'Unknown error'));
						console.error('Certificate error:', response);
						// window.close();
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', {
						status: status,
						error: error,
						responseText: xhr.responseText,
						statusCode: xhr.status
					});
					alert('Failed to load certificate: ' + error + '\n\nResponse: ' + xhr.responseText);
					// window.close();
				}
			});
		});
	</script>
</body>

</html>