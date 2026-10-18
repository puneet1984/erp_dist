<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check admin permissions
if (!checkPermission('admin', 'edit')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

// Load categories from JSON
$categories_file = __DIR__ . '/../../config/service_categories.json';
$categories_json = file_get_contents($categories_file);
$categories = json_decode($categories_json, true);

// Get next order number
$usedOrders = [];
foreach ($categories as $category) {
    if ($category['order'] < 99) {  // Only collect orders less than 99
        $usedOrders[] = $category['order'];
    }
}

// Find the first missing number in sequence
$nextOrder = 1;
sort($usedOrders);  // Sort the used orders
foreach ($usedOrders as $order) {
    if ($order != $nextOrder) {
        break;
    }
    $nextOrder++;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        if (empty($_POST['key']) || empty($_POST['name']) || empty($_POST['description'])) {
            $response['message'] = 'All fields are required';
            echo json_encode($response);
            exit;
        }

        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['key']));
        $category = [
            'name' => trim($_POST['name']),
            'description' => trim($_POST['description']),
            'order' => intval($_POST['order'])
        ];

        if ($_POST['action'] === 'add' && !isset($categories[$key])) {
            $categories[$key] = $category;
            $response = ['status' => 'success'];
        } elseif ($_POST['action'] === 'update' && isset($categories[$key])) {
            $categories[$key] = $category;
            $response = ['status' => 'success'];
        } else {
            $response['message'] = 'Category key ' . ($categories[$key] ? 'already exists' : 'not found');
        }
    } elseif ($_POST['action'] === 'delete' && $_POST['key'] !== 'OTHER') {
        $key = $_POST['key'];
        if (isset($categories[$key])) {
            unset($categories[$key]);
            $response = ['status' => 'success'];
        }
    }

    if ($response['status'] === 'success') {
        // Sort categories by order
        uasort($categories, function ($a, $b) {
            return $a['order'] - $b['order'];
        });
        file_put_contents($categories_file, json_encode($categories, JSON_PRETTY_PRINT));
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Manage Service Categories - Admin</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.category-list {
			margin-top: 20px;
		}

		.action-buttons {
			display: flex;
			gap: 10px;
		}

		.form-section {
			margin-bottom: 30px;
		}

		.hidden {
			display: none;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Manage Service Categories</h2>

		<!-- Add Category Form -->
		<div class="form-section">
			<h3>Add New Category</h3>
			<form id="categoryForm">
				<input type="hidden" name="action" value="add">

				<div class="form-row">
					<div class="form-group">
						<label>Category Key:</label>
						<input type="text" name="key" required pattern="[A-Za-z0-9]+"
							title="Alphanumeric characters only">
						<small>Alphanumeric only, will be converted to uppercase</small>
					</div>
					<div class="form-group">
						<label>Display Name:</label>
						<input type="text" name="name" required>
					</div>
					<div class="form-group">
						<label>Description:</label>
						<input type="text" name="description" required>
					</div>
					<div class="form-group">
						<label>Display Order:</label>
						<input type="number" name="order" required min="1" max="98"
							value="<?= $nextOrder ?>">
					</div>
				</div>

				<div class="form-row">
					<button type="submit" id="submitBtn">Add Category</button>
					<button type="button" onclick="resetForm()" class="hidden" id="cancelBtn">Cancel</button>
				</div>
			</form>
		</div>

		<!-- Categories List -->
		<div class="category-list">
			<table class="data-table">
				<thead>
					<tr>
						<th>Key</th>
						<th>Name</th>
						<th>Description</th>
						<th>Order</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($categories as $key => $category): ?>
					<tr>
						<td><?= htmlspecialchars($key) ?></td>
						<td><?= htmlspecialchars($category['name']) ?>
						</td>
						<td><?= htmlspecialchars($category['description']) ?>
						</td>
						<td><?= $category['order'] ?>
						</td>
						<td class="action-buttons">
							<?php if ($key !== 'OTHER'): ?>
							<button
								onclick='editCategory("<?= $key ?>", <?= json_encode($category) ?>)'>Edit</button>
							<button
								onclick='deleteCategory("<?= $key ?>")'>Delete</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		function editCategory(key, category) {
			const form = document.getElementById('categoryForm');
			form.action.value = 'update';
			form.key.value = key;
			form.key.readOnly = true;
			form.name.value = category.name;
			form.description.value = category.description;
			form.order.value = category.order;

			document.getElementById('submitBtn').textContent = 'Update Category';
			document.getElementById('cancelBtn').classList.remove('hidden');
		}

		function resetForm() {
			const form = document.getElementById('categoryForm');
			form.reset();
			form.action.value = 'add';
			form.key.readOnly = false;
			document.getElementById('submitBtn').textContent = 'Add Category';
			document.getElementById('cancelBtn').classList.add('hidden');
		}

		function deleteCategory(key) {
			if (confirm('Are you sure you want to delete this category?')) {
				const formData = new FormData();
				formData.append('action', 'delete');
				formData.append('key', key);

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

		document.getElementById('categoryForm').onsubmit = function(e) {
			e.preventDefault();
			const formData = new FormData(this);
			
			fetch(window.location.href, {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						window.location.reload();
					} else {
						alert('Error: ' + (data.message || 'Failed to update category'));
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error: Failed to process request');
				});
		};
	</script>
</body>

</html>