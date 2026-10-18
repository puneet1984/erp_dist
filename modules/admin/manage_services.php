<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check admin permissions
if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Load categories from JSON
$categories_json = file_get_contents(__DIR__ . '/../../config/service_categories.json');
$categories = json_decode($categories_json, true);

// Sort categories by order
uasort($categories, function ($a, $b) {
    return $a['order'] - $b['order'];
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $response = ['status' => 'error', 'message' => ''];

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        // Validate required fields
        $name = capitalizeWords(sanitizeInput($_POST['name'] ?? ''));
        $category = sanitizeInput($_POST['category'] ?? '');
        $base_charge = filter_var($_POST['base_charge'] ?? 0, FILTER_VALIDATE_FLOAT);

        // Validation checks
        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Service name is required']);
            exit;
        }

        if (strlen($name) > 100) {
            echo json_encode(['status' => 'error', 'message' => 'Service name is too long (max 100 characters)']);
            exit;
        }

        if (!array_key_exists($category, $categories)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid category']);
            exit;
        }

        if ($base_charge === false || $base_charge < 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid base charge amount']);
            exit;
        }

        // Prepare SQL based on action
        $sql = ($_POST['action'] === 'add')
            ? "INSERT INTO services (name, category, base_charge) VALUES (?, ?, ?)"
            : "UPDATE services SET name=?, category=?, base_charge=? WHERE service_id=?";

        $stmt = $conn->prepare($sql);

        if ($_POST['action'] === 'add') {
            $stmt->bind_param("ssd", $name, $category, $base_charge);
        } else {
            $service_id = filter_var($_POST['service_id'], FILTER_VALIDATE_INT);
            if (!$service_id) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid service ID']);
                exit;
            }
            $stmt->bind_param("ssdi", $name, $category, $base_charge, $service_id);
        }

    } elseif ($_POST['action'] === 'delete') {
        $service_id = filter_var($_POST['service_id'], FILTER_VALIDATE_INT);
        if (!$service_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid service ID']);
            exit;
        }

        // Check if service is in use
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM bill_items WHERE service_id = ?");
        $check_stmt->bind_param("i", $service_id);
        $check_stmt->execute();
        $count = $check_stmt->get_result()->fetch_row()[0];

        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete: Service is being used in bills']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM services WHERE service_id=?");
        $stmt->bind_param("i", $service_id);
    }

    $response['status'] = $stmt->execute() ? 'success' : 'error';
    $response['message'] = $stmt->error;

    echo json_encode($response);
    exit;
}

$conn = getDbConnection();

// Get filters from URL
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$selected_category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : 'ALL';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$rows_per_page = 10;
$offset = ($page - 1) * $rows_per_page;

// Build WHERE clause for category filter and search
$where_conditions = [];
if ($selected_category !== 'ALL') {
    $where_conditions[] = "category = '$selected_category'";
}
if (!empty($search)) {
    $where_conditions[] = "name LIKE '%$search%'";
}
$where_clause = !empty($where_conditions) ? "WHERE " . implode(' AND ', $where_conditions) : "";

// Get total count for pagination with filter
$total_services = $conn->query("SELECT COUNT(*) as count FROM services $where_clause")->fetch_assoc()['count'];
$total_pages = ceil($total_services / $rows_per_page);

// Update query to include category filter and pagination
$services = $conn->query("SELECT * FROM services $where_clause ORDER BY category, name LIMIT $rows_per_page OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Manage Services - Admin</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.service-list {
			margin-top: 20px;
		}

		.action-buttons {
			display: flex;
			gap: 10px;
		}

		.form-section {
			margin-bottom: 30px;
		}

		.form-row {
			display: grid;
			grid-template-columns: 2fr 4fr 2fr;
			gap: 20px;
			align-items: start;
		}

		.hidden {
			display: none;
		}

		.pagination {
			margin-top: 20px;
			text-align: center;
			background-color: wheat;
			border-radius: 5px;
		}

		.pagination a {
			display: inline-block;
			padding: 5px 10px;
			margin: 5px 2px;
			border: 1px solid #ddd;
			text-decoration: none;
			color: #333;
		}

		.pagination a.active {
			background-color: #007bff;
			color: white;
			border-color: #007bff;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 10px;
			margin-top: 20px;
		}

		.pagination-info {
			margin: 0 15px;
		}

		.pagination button {
			padding: 5px 15px;
			border: 1px solid #ddd;
			background: #fff;
			cursor: pointer;
			color: black;
		}

		.pagination button:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}

		.filter-bar {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
			margin-bottom: 20px;
		}

		.search-box {
			display: flex;
			gap: 10px;
			align-items: center;
			margin-top: 12px;
			position: relative;
		}

		.search-box input {
			padding: 8px;
			padding-right: 30px;
			border: 1px solid #ddd;
			border-radius: 4px;
			flex: 1;
		}

		.search-clear {
			position: absolute;
			right: 8px;
			top: 50%;
			transform: translateY(-50%);
			cursor: pointer;
			color: #666;
			padding: 4px 8px;
			display: none;
			font-weight: bold;
		}

		.search-clear:hover {
			color: #333;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Manage Services</h2>

		<!-- Add Service Form -->
		<div class="form-section">
			<h3>Add New Service</h3>
			<form id="serviceForm">
				<input type="hidden" name="action" value="add">
				<input type="hidden" name="service_id">

				<div class="form-row">
					<div class="form-group">
						<label>Service Name:</label>
						<input type="text" name="name" required>
					</div>
					<div class="form-group">
						<label>Category:</label>
						<select name="category" required>
							<?php foreach ($categories as $key => $category): ?>
							<option value="<?= $key ?>">
								<?= htmlspecialchars($category['name']) ?>
							</option>
							<?php endforeach; ?>
						</select>
						<small class="help-text" id="categoryDesc"></small>
					</div>
					<div class="form-group">
						<label>Base Charge (₹):</label>
						<input type="number" name="base_charge" required min="0" step="0.01">
					</div>
				</div>

				<div class="form-row">
					<button type="submit" id="submitBtn">Add Service</button>
					<button type="button" onclick="resetForm()" class="hidden" id="cancelBtn">Cancel</button>
				</div>
			</form>
		</div>

		<!-- Services List -->
		<div class="service-list">
			<div class="filter-bar">
				<div class="filter-section">
					<label>Filter by Category:</label>
					<select onchange="applyFilters()">
						<option value="ALL" <?= $selected_category === 'ALL' ? 'selected' : '' ?>>All Services</option>
						<?php foreach ($categories as $key => $category): ?>
						<option value="<?= $key ?>" <?= $selected_category === $key ? 'selected' : '' ?>>
							<?= htmlspecialchars($category['name']) ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="search-box">
					<label>Search:</label>
					<input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>" 
						   placeholder="Search by service name..." onkeyup="handleSearch(event)">
					<span class="search-clear" onclick="clearSearch()">×</span>
				</div>
			</div>
			<table class="data-table">
				<thead>
					<tr>
						<th>#</th>
						<th>Service Name</th>
						<th>Category</th>
						<th>Base Charge</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($services as $index => $service): ?>
						<tr data-category="<?= $service['category'] ?>">
							<td><?= $offset + $index + 1 ?></td>
							<td><?= htmlspecialchars($service['name']) ?></td>
							<td><?= htmlspecialchars($categories[$service['category']]['name'] ?? $service['category']) ?></td>
							<td>₹<?= number_format($service['base_charge'], 2) ?></td>
							<td class="action-buttons">
								<button onclick='editService(<?= json_encode($service) ?>)'>Edit</button>
								<button onclick="deleteService(<?= $service['service_id'] ?>)">Delete</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			
			<!-- Pagination controls -->
			<?php if ($total_pages > 1): ?>
			<div class="pagination">
				<button onclick="changePage(<?= $page - 1 ?>)" <?= $page <= 1 ? 'disabled' : '' ?>>Previous</button>
				<span class="pagination-info">Page <?= $page ?> of <?= $total_pages ?></span>
				<button onclick="changePage(<?= $page + 1 ?>)" <?= $page >= $total_pages ? 'disabled' : '' ?>>Next</button>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<script>
		// Add global variable declaration
		let searchTimer;
		
		// Add F5 key handler
        document.addEventListener('keydown', function(event) {
            if (event.key === 'F5') {
                event.preventDefault();
                window.location.href = window.location.pathname;
            }
        });
        
		// Add category description display
		const categories = <?= json_encode($categories) ?>;
		document.querySelector('select[name="category"]').addEventListener('change', function() {
			const desc = document.getElementById('categoryDesc');
			desc.textContent = categories[this.value]?.description || '';
		});

		function toggleDepartmentField() {
			const deptField = document.getElementById('deptField');
			const deptSelect = deptField.querySelector('select');
			const serviceType = document.querySelector('select[name="service_type"]').value;

			if (serviceType === 'COMMON') {
				deptField.style.display = 'none';
				deptSelect.required = false;
				deptSelect.value = '';
			} else {
				deptField.style.display = 'block';
				deptSelect.required = true;
			}
			}

        function applyFilters() {
            const category = document.querySelector('.filter-section select').value;
            const search = document.getElementById('searchInput').value.trim();
            window.location.href = `?category=${category}&search=${encodeURIComponent(search)}&page=1&focus=search`;
        }

		function handleSearch(event) {
			const searchInput = document.getElementById('searchInput');
			const clearBtn = document.querySelector('.search-clear');
			clearBtn.style.display = searchInput.value ? 'block' : 'none';
			
			clearTimeout(searchTimer);
			searchTimer = setTimeout(() => {
				applyFilters();
				searchInput.focus();
			}, 1500);
		}

		function clearSearch() {
			const searchInput = document.getElementById('searchInput');
			searchInput.value = '';
			document.querySelector('.search-clear').style.display = 'none';
			applyFilters();
		}

		// Initialize clear button visibility and focus
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const clearBtn = document.querySelector('.search-clear');
            clearBtn.style.display = searchInput.value ? 'block' : 'none';

            // Set focus if coming from search
            if (new URLSearchParams(window.location.search).get('focus') === 'search') {
                searchInput.focus();
            }
        });

		function changePage(page) {
			const category = document.querySelector('.filter-section select').value;
			const search = document.getElementById('searchInput').value.trim();
			window.location.href = `?category=${category}&search=${encodeURIComponent(search)}&page=${page}`;
		}

		function editService(service) {
			const form = document.getElementById('serviceForm');
			form.action.value = 'update';
			form.service_id.value = service.service_id;
			form.name.value = service.name;
			form.category.value = service.category;
			form.base_charge.value = service.base_charge;

			document.getElementById('submitBtn').textContent = 'Update Service';
			document.getElementById('cancelBtn').classList.remove('hidden');
			form.scrollIntoView({
				behavior: 'smooth'
			});
		}

		function resetForm() {
			const form = document.getElementById('serviceForm');
			form.reset();
			form.action.value = 'add';
			form.service_id.value = '';
			document.getElementById('submitBtn').textContent = 'Add Service';
			document.getElementById('cancelBtn').classList.add('hidden');
		}

		function deleteService(serviceId) {
			if (confirm('Are you sure you want to delete this service?')) {
				const formData = new FormData();
				formData.append('action', 'delete');
				formData.append('service_id', serviceId);

				fetch(window.location.href, {
						method: 'POST',
						body: formData
					})
					.then(response => response.json())
					.then(data => {
						if (data.status === 'success') {
							window.location.reload();
						} else {
							alert('Error: ' + data.message);
						}
					});
			}
		}

		document.getElementById('serviceForm').onsubmit = function(e) {
			e.preventDefault();
			fetch(window.location.href, {
					method: 'POST',
					body: new FormData(this)
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						window.location.reload();
					} else {
						alert('Error: ' + data.message);
					}
				});
		};
	</script>
</body>

</html>