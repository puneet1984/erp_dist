<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check admin permissions
if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Load service categories
$categories = getServiceCategories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['status' => 'error', 'message' => '', 'details' => []];

    if (isset($_FILES['csv_file'])) {
        if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $preview_data = [];
            $errors = [];

            // Skip header row
            fgetcsv($handle);
            $row = 2;

            while (($data = fgetcsv($handle)) !== false) {
                $service = [
                    'name' => sanitizeInput($data[0] ?? ''),
                    'category' => sanitizeInput($data[1] ?? ''),
                    'base_charge' => filter_var($data[2] ?? 0, FILTER_VALIDATE_FLOAT),
                    'row' => $row,
                    'valid' => true,
                    'errors' => []
                ];

                // Validate each row
                if (empty($service['name'])) {
                    $service['errors'][] = 'Service name is required';
                    $service['valid'] = false;
                }

                if (strlen($service['name']) > 100) {
                    $service['name'] = substr($service['name'], 0, 100);
                    $service['errors'][] = 'Name truncated to 100 characters';
                }

                if (!array_key_exists($service['category'], $categories)) {
                    $service['errors'][] = 'Invalid category';
                    $service['valid'] = false;
                }

                if ($service['base_charge'] === false || $service['base_charge'] < 0) {
                    $service['errors'][] = 'Invalid base charge';
                    $service['valid'] = false;
                }

                $preview_data[] = $service;
                $row++;
            }

            fclose($handle);

            if (isset($_POST['confirm']) && $_POST['confirm'] === 'true') {
                // Process actual insertion
                $conn = getDbConnection();
                $conn->begin_transaction();

                try {
                    foreach ($preview_data as $service) {
                        if ($service['valid']) {
                            $stmt = $conn->prepare("INSERT INTO services (name, category, base_charge) VALUES (?, ?, ?)");
                            $stmt->bind_param("ssd", $service['name'], $service['category'], $service['base_charge']);

                            if (!$stmt->execute()) {
                                throw new Exception("Error inserting service: " . $stmt->error);
                            }
                            $response['details'][] = "Added service: {$service['name']}";
                        }
                    }

                    $conn->commit();
                    $response['status'] = 'success';
                    $response['message'] = 'Services uploaded successfully';

                } catch (Exception $e) {
                    $conn->rollback();
                    $response['message'] = $e->getMessage();
                }
            } else {
                // Return preview data
                $response['status'] = 'preview';
                $response['data'] = $preview_data;
                $response['valid_count'] = count(array_filter($preview_data, fn ($s) => $s['valid']));
                $response['invalid_count'] = count(array_filter($preview_data, fn ($s) => !$s['valid']));
            }
        } else {
            $response['message'] = 'Error uploading file';
        }
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Bulk Upload Services - Admin</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.sample-csv {
			background: #f5f5f5;
			padding: 15px;
			border-radius: 5px;
			margin: 20px 0;
		}

		.upload-result {
			margin-top: 20px;
			padding: 10px;
			border-radius: 5px;
			display: none;
		}

		.success {
			background: #d4edda;
		}

		.error {
			background: #f8d7da;
		}

		.details-list {
			margin-top: 10px;
			max-height: 200px;
			overflow-y: auto;
		}

		.preview-table {
			width: 100%;
			margin-top: 20px;
		}

		.preview-table th,
		.preview-table td {
			padding: 8px;
			text-align: left;
		}

		.preview-table tr.invalid {
			background-color: #ffe6e6;
		}

		.error-list {
			color: #dc3545;
			font-size: 0.9em;
			margin-top: 5px;
		}

		.preview-controls {
			margin-top: 20px;
		}

		.hidden {
			display: none;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Bulk Upload Services</h2>

		<div class="sample-csv">
			<h3>Sample CSV Structure</h3>
			<p>Your CSV file should have the following columns:</p>
			<code>Service Name,Category,Base Charge</code>
			<p>
				<a href="../../includes/samples/sample_services.csv" class="btn btn-small" download>
					Download Sample CSV
				</a>
			</p>
			<p>Available Categories:</p>
			<ul>
				<?php foreach ($categories as $key => $category): ?>
				<li><?= htmlspecialchars($key) ?> -
					<?= htmlspecialchars($category['name']) ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<p>Example:</p>
			<pre>
            Service Name,Category,Base Charge
            X-Ray Chest,RADIOLOGY,500
            Blood Sugar,PATHOLOGY,100
            Dressing Small,PROCEDURE,200
        </pre>
			<p>Notes:</p>
			<ul>
				<li>Service names longer than 100 characters will be trimmed</li>
				<li>Base charge must be a positive number</li>
				<li>Category must match one of the available categories exactly</li>
			</ul>
		</div>

		<form id="uploadForm" enctype="multipart/form-data">
			<div class="form-group">
				<label>Select CSV File:</label>
				<input type="file" name="csv_file" accept=".csv" required>
			</div>
			<button type="submit">Preview Upload</button>
		</form>

		<div id="previewSection" class="hidden">
			<h3>Preview Results</h3>
			<div id="previewStats"></div>
			<table class="preview-table">
				<thead>
					<tr>
						<th>Row</th>
						<th>Service Name</th>
						<th>Category</th>
						<th>Base Charge</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody id="previewBody"></tbody>
			</table>
			<div class="preview-controls">
				<button onclick="confirmUpload()" id="confirmButton" class="hidden">Confirm Upload</button>
				<button onclick="cancelUpload()">Cancel</button>
			</div>
		</div>

		<div id="uploadResult" class="upload-result">
			<h3>Upload Results</h3>
			<p id="resultMessage"></p>
			<div id="resultDetails" class="details-list"></div>
		</div>
	</div>

	<script>
		let previewData = null;

		document.getElementById('uploadForm').onsubmit = function(e) {
			e.preventDefault();
			const formData = new FormData(this);

			fetch('bulk_upload_services.php', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'preview') {
						showPreview(data);
					} else {
						showResult(data);
					}
				})
				.catch(error => {
					alert('Error uploading file: ' + error.message);
				});
		};

		function showPreview(data) {
			previewData = data.data;
			const previewSection = document.getElementById('previewSection');
			const previewStats = document.getElementById('previewStats');
			const previewBody = document.getElementById('previewBody');
			const confirmButton = document.getElementById('confirmButton');

			previewStats.innerHTML = `
                <p>Total Services: ${data.data.length}</p>
                <p>Valid Services: ${data.valid_count}</p>
                <p>Invalid Services: ${data.invalid_count}</p>
            `;

			previewBody.innerHTML = data.data.map(service => `
                <tr class="${service.valid ? '' : 'invalid'}">
                    <td>${service.row}</td>
                    <td>${service.name}</td>
                    <td>${service.category}</td>
                    <td>₹${service.base_charge}</td>
                    <td>
                        ${service.valid ? 'Valid' : `
                            <div>Invalid</div>
                            <div class="error-list">${service.errors.join('<br>')}</div>
                        `}
                    </td>
                </tr>
            `).join('');

			previewSection.classList.remove('hidden');
			confirmButton.classList.toggle('hidden', data.valid_count === 0);
		}

		function confirmUpload() {
			const formData = new FormData();
			formData.append('confirm', 'true');
			formData.append('csv_file', document.querySelector('input[name="csv_file"]').files[0]);

			fetch('bulk_upload_services.php', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => showResult(data))
				.catch(error => {
					alert('Error uploading file: ' + error.message);
				});
		}

		function cancelUpload() {
			document.getElementById('previewSection').classList.add('hidden');
			document.getElementById('uploadForm').reset();
		}

		function showResult(data) {
			const resultDiv = document.getElementById('uploadResult');
			const messageDiv = document.getElementById('resultMessage');
			const detailsDiv = document.getElementById('resultDetails');

			resultDiv.style.display = 'block';
			resultDiv.className = 'upload-result ' + (data.status === 'success' ? 'success' : 'error');
			messageDiv.textContent = data.message;

			if (data.details && data.details.length > 0) {
				detailsDiv.innerHTML = data.details.map(detail => `<div>${detail}</div>`).join('');
			} else {
				detailsDiv.innerHTML = '';
			}

			if (data.status === 'success') {
				document.getElementById('uploadForm').reset();
			}
		}
	</script>
</body>

</html>