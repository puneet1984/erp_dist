<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}
$db = $conn = getDbConnection();

$predefined_variables = [
    'REGISTRATION' => [
        'patient_name', 'patient_id', 'doctor_name', 'department', 'visit_date', 'visit_time', 'hospital_name'
    ],
    'APPOINTMENT' => [
        'patient_name', 'doctor_name', 'appointment_date', 'appointment_time', 'department', 'hospital_name'
    ],
    'REMINDER' => [
        'patient_name', 'doctor_name', 'reminder_date', 'reminder_time', 'department', 'hospital_name'
    ],
    'TEST' => [
        'patient_name', 'test_name', 'test_date', 'test_time', 'department', 'hospital_name'
    ],
    'MARKETING' => [
        'patient_name', 'hospital_name', 'offer_details', 'valid_till', 'contact_number'
    ],
    'OTHER' => [
        'patient_name', 'hospital_name'
    ]
];

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'XMLHttpRequest' === $_SERVER['HTTP_X_REQUESTED_WITH']) {
    header('Content-Type: application/json');

    // Get templates
    if ('GET' === $_SERVER['REQUEST_METHOD']) {
        try {
            $sql = 'SELECT template_id, template_name, template_text, variables FROM sms_templates ORDER BY template_name';
            $result = mysqli_query($db, $sql);

            if (!$result) {
                error_log('SQL Error: '.mysqli_error($db));
                throw new Exception(mysqli_error($db));
            }

            $templates = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $templates[] = $row;
            }

            error_log('Templates found: '.count($templates));
            echo json_encode($templates);
            exit;
        } catch (Exception $e) {
            error_log('Exception: '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error: '.$e->getMessage()]);
            exit;
        }
    }

    // Save template
    if ('POST' === $_SERVER['REQUEST_METHOD'] && !isset($_GET['action'])) {
        try {
            $template_id = $_POST['template_id'] ?? null;
            $template_name = $_POST['template_name'];
            $template_text = $_POST['template_text'];
            $variables = json_encode(array_map('trim', explode(',', $_POST['variables'])));

            if ($template_id) {
                $stmt = mysqli_prepare(
                    $db,
                    'UPDATE sms_templates SET template_name = ?, template_text = ?, variables = ? WHERE template_id = ?'
                );
                mysqli_stmt_bind_param($stmt, 'sssi', $template_name, $template_text, $variables, $template_id);
            } else {
                $stmt = mysqli_prepare(
                    $db,
                    'INSERT INTO sms_templates (template_name, template_text, variables) VALUES (?, ?, ?)'
                );
                mysqli_stmt_bind_param($stmt, 'sss', $template_name, $template_text, $variables);
            }

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($db));
            }

            mysqli_stmt_close($stmt);
            echo json_encode(['status' => 'success', 'message' => 'Template saved successfully']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: '.$e->getMessage()]);
            exit;
        }
    }

    // Delete template
    if ('DELETE' === $_SERVER['REQUEST_METHOD']) {
        try {
            $template_id = $_GET['id'] ?? null;
            if (!$template_id) {
                throw new Exception('Template ID is required');
            }

            $stmt = mysqli_prepare($db, 'DELETE FROM sms_templates WHERE template_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $template_id);

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($db));
            }

            mysqli_stmt_close($stmt);
            echo json_encode(['status' => 'success', 'message' => 'Template deleted successfully']);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // Test template
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_GET['action']) && 'test' === $_GET['action']) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $template_id = $data['template_id'] ?? null;
            $test_data = $data['test_data'] ?? [];

            $stmt = mysqli_prepare($db, 'SELECT template_text FROM sms_templates WHERE template_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $template_id);

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($db));
            }

            $result = mysqli_stmt_get_result($stmt);
            $template = mysqli_fetch_assoc($result);

            if (!$template) {
                throw new Exception('Template not found');
            }

            $message = $template['template_text'];
            foreach ($test_data as $key => $value) {
                $message = str_replace('{'.$key.'}', $value, $message);
            }

            echo json_encode(['status' => 'success', 'message' => $message]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
}

$page_title = 'SMS Templates';
?>
<!DOCTYPE html>
<html>

<head>
	<title><?php echo $page_title; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<style>
		.template-card {
			height: 100%;
		}

		.variables-badge {
			font-size: 0.8em;
			background: #f8f9fa;
			padding: 2px 8px;
			border-radius: 12px;
			margin: 2px;
			display: inline-block;
			cursor: pointer;
		}

		.variables-badge:hover {
			background: #e9ecef;
		}

		.template-text {
			white-space: pre-wrap;
			font-size: 0.9em;
			background: #f8f9fa;
			padding: 10px;
			border-radius: 4px;
			margin: 10px 0;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container py-4">
		<div class="d-flex justify-content-between align-items-center mb-4">
			<h2>SMS Templates</h2>
			<div class="d-flex gap-2">
				<select id="languageFilter" class="form-select">
					<option value="">All Languages</option>
					<option value="_H">Hindi</option>
					<option value="_E">English</option>
				</select>
				<button class="btn btn-primary" onclick="showAddTemplate()">
					<i class="bi bi-plus"></i> Add Template
				</button>
			</div>
		</div>

		<div id="templateGrid" class="row g-4"></div>
	</div>

	<!-- Bootstrap Modal -->
	<div class="modal fade" id="templateModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="modalTitle">Add Template</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<form id="templateForm">
						<input type="hidden" name="template_id">
						<div class="mb-3">
							<label class="form-label">Template Type:</label>
							<select class="form-select" name="sms_type" required onchange="updateAvailableVariables()">
								<option value="REGISTRATION">Registration</option>
								<option value="APPOINTMENT">Appointment</option>
								<option value="REMINDER">Reminder</option>
								<option value="TEST">Test</option>
								<option value="MARKETING">Marketing</option>
								<option value="OTHER">Other</option>
							</select>
						</div>
						<div class="mb-3">
							<label class="form-label">Template Name:</label>
							<input type="text" class="form-control" name="template_name" required>
						</div>
						<div class="mb-3">
							<label class="form-label">Language:</label>
							<select class="form-select" name="language" required>
								<option value="_E">English</option>
								<option value="_H">Hindi</option>
							</select>
						</div>
						<div class="mb-3">
							<label class="form-label">Available Variables:</label>
							<div id="availableVariables" class="mb-2 p-2 border rounded bg-light">
								<!-- Variables will be populated by JavaScript -->
							</div>
						</div>
						<div class="mb-3">
							<label class="form-label">Template Text:</label>
							<textarea class="form-control" name="template_text" rows="4" required></textarea>
						</div>
						<div class="mb-3">
							<label class="form-label">Custom Variables (comma-separated):</label>
							<input type="text" class="form-control" name="variables" 
								   placeholder="Add any additional variables">
						</div>
					</form>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="button" class="btn btn-primary" onclick="saveTemplate()">Save</button>
				</div>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		let templates = [];
		let modalInstance;

		const PREDEFINED_VARIABLES = <?php echo json_encode($predefined_variables); ?>;

		function loadTemplates() {
			fetch('sms_templates.php', {
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					}
				})
				.then(response => {
					if (!response.ok) {
						throw new Error('Network response was not ok');
					}
					return response.json();
				})
				.then(data => {
					if (Array.isArray(data)) {
						templates = data;
						renderTemplates();
					} else if (data.error) {
						console.error('Error:', data.error);
						alert('Error loading templates: ' + data.error);
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Failed to load templates. Please refresh the page.');
				});
		}

		function saveTemplate() {
			const form = document.getElementById('templateForm');
			const formData = new FormData(form);
			
			let templateName = formData.get('template_name');
			const language = formData.get('language');
			
			templateName = templateName.replace(/_(E|H)$/, '');
			formData.set('template_name', templateName + language);

			fetch('sms_templates.php', {
					method: 'POST',
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					},
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						loadTemplates();
						modalInstance.hide();
					}
					alert(data.message);
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Failed to save template. Please try again.');
				});
		}

		function deleteTemplate(id) {
			if (!confirm('Are you sure you want to delete this template?')) {
				return;
			}

			fetch(`sms_templates.php?id=${id}`, {
					method: 'DELETE',
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					}
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						loadTemplates();
						alert(data.message);
					} else {
						throw new Error(data.message);
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Failed to delete template: ' + error.message);
				});
		}

		function testTemplate(id) {
			const template = templates.find(t => t.template_id === id);
			if (!template) return;

			const testData = {};
			JSON.parse(template.variables).forEach(v => {
				testData[v] = prompt(`Enter test value for ${v}:`) || v;
			});

			fetch('sms_templates.php?action=test', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest'
					},
					body: JSON.stringify({
						template_id: id,
						test_data: testData
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						alert('Preview:\n\n' + data.message);
					} else {
						throw new Error(data.message);
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Failed to test template: ' + error.message);
				});
		}

		function renderTemplates() {
			const grid = document.getElementById('templateGrid');
			const languageFilter = document.getElementById('languageFilter').value;

			const filtered = languageFilter ?
				templates.filter(t => t.template_name.endsWith(languageFilter)) :
				templates;

			grid.innerHTML = filtered.map(template => `
                <div class="col-md-6 col-lg-4">
                    <div class="card template-card">
                        <div class="card-body">
                            <h5 class="card-title">${template.template_name}</h5>
                            <div class="template-text">${template.template_text}</div>
                            <div class="mb-3">
                                ${JSON.parse(template.variables).map(v => 
                                    `<span class="variables-badge">{${v}}</span>`
                                ).join('')}
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-primary" onclick="editTemplate(${template.template_id})">
                                    Edit
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="testTemplate(${template.template_id})">
                                    Test
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteTemplate(${template.template_id})">
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
		}

		function updateAvailableVariables() {
			const smsType = document.querySelector('select[name="sms_type"]').value;
			const varsDiv = document.getElementById('availableVariables');
			const variables = PREDEFINED_VARIABLES[smsType] || [];
			
			varsDiv.innerHTML = variables.map(v => 
				`<span class="variables-badge" onclick="insertVariable('${v}')">{${v}}</span>`
			).join('');
		}

		function insertVariable(variable) {
			const textarea = document.querySelector('textarea[name="template_text"]');
			const pos = textarea.selectionStart;
			const text = textarea.value;
			const before = text.substring(0, pos);
			const after = text.substring(pos);
			
			textarea.value = before + '{' + variable + '}' + after;
			textarea.focus();
			textarea.selectionStart = textarea.selectionEnd = pos + variable.length + 2;
		}

		function showAddTemplate() {
			document.getElementById('templateForm').reset();
			document.getElementById('modalTitle').textContent = 'Add Template';
			document.querySelector('input[name="template_id"]').value = '';
			updateAvailableVariables();
			modalInstance.show();
		}

		function editTemplate(id) {
			const templateId = Number(id);
			const template = templates.find(t => Number(t.template_id) === templateId);
			
			if (!template) {
				console.error('Template not found:', id);
				return;
			}

			const form = document.getElementById('templateForm');
			document.getElementById('modalTitle').textContent = 'Edit Template';
			
			const templateName = template.template_name;
			const language = templateName.endsWith('_H') ? '_H' : '_E';
			const baseName = templateName.replace(/_(E|H)$/, '');
			
			form.querySelector('input[name="template_id"]').value = template.template_id;
			form.querySelector('input[name="template_name"]').value = baseName;
			form.querySelector('select[name="language"]').value = language;
			form.querySelector('textarea[name="template_text"]').value = template.template_text;
			form.querySelector('input[name="variables"]').value = JSON.parse(template.variables).join(', ');
			
			updateAvailableVariables();
			modalInstance.show();
		}

		document.addEventListener('DOMContentLoaded', function() {
			modalInstance = new bootstrap.Modal(document.getElementById('templateModal'));
			loadTemplates();

			document.getElementById('languageFilter').onchange = renderTemplates;
		});
	</script>
</body>

</html>