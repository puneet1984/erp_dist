<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$tax_file = __DIR__ . '/../../config/tax_rates.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            $tax_rates = json_decode(file_get_contents($tax_file), true);

            switch ($_POST['action']) {
                case 'delete':
                    $category = sanitizeInput($_POST['category']);

                    // Prevent deletion of essential categories
                    $essential_categories = ['PHARMACY', 'OTHER'];
                    if (in_array($category, $essential_categories)) {
                        throw new Exception("Cannot delete essential category: $category");
                    }

                    // Check if category exists
                    if (!isset($tax_rates[$category])) {
                        throw new Exception("Category not found");
                    }

                    // Here you can add checks for category usage in services table if needed
                    $conn = getDbConnection();
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM services WHERE category = ?");
                    $stmt->bind_param("s", $category);
                    $stmt->execute();
                    if ($stmt->get_result()->fetch_row()[0] > 0) {
                        throw new Exception("Cannot delete: Category is being used by services");
                    }

                    // Delete the category
                    unset($tax_rates[$category]);
                    break;

                case 'add_category':
                    // Add new category
                    $category = strtoupper(sanitizeInput($_POST['new_category']));

                    if (empty($category)) {
                        throw new Exception("Category name is required");
                    }

                    if (isset($tax_rates[$category])) {
                        throw new Exception("Category already exists");
                    }

                    $tax_rates[$category] = [
                        'gst' => floatval($_POST['gst']),
                        'description' => sanitizeInput($_POST['description'])
                    ];
                    break;

                default:
                    // Update existing categories
                    $tax_rates = [];
                    foreach ($_POST['categories'] as $category => $data) {
                        $category = sanitizeInput($category);
                        $gst = filter_var($data['gst'], FILTER_VALIDATE_FLOAT);
                        if ($gst === false || $gst < 0 || $gst > 100) {
                            throw new Exception("Invalid GST rate for $category");
                        }

                        $tax_rates[$category] = [
                            'gst' => $gst,
                            'description' => sanitizeInput($data['description'])
                        ];
                    }
                    break;
            }

            // Save changes
            if (!file_put_contents($tax_file, json_encode($tax_rates, JSON_PRETTY_PRINT))) {
                throw new Exception("Failed to save tax rates");
            }

            $success = "Tax rates updated successfully";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Read current tax rates
$tax_rates = json_decode(file_get_contents($tax_file), true);
?>
<!DOCTYPE html>
<html>

<head>
	<title>Tax Settings</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.tax-list {
			margin: 20px 0;
		}

		.tax-item {
			background: #f8f9fa;
			padding: 15px;
			margin-bottom: 10px;
			border: 1px solid #dee2e6;
			border-radius: 5px;
		}

		.new-category {
			background: #e9ecef;
			padding: 20px;
			margin: 20px 0;
			border-radius: 5px;
		}

		.message {
			padding: 10px;
			margin: 10px 0;
			border-radius: 4px;
		}

		.success {
			background: #d4edda;
			color: #155724;
		}

		.error {
			background: #f8d7da;
			color: #721c24;
		}

		.form-row-tax {
			display: flex;
			gap: 20px;
			align-items: flex-start;
		}

		.gst-input {
			flex: 0 0 200px;
			/* Fixed width for GST input */
		}

		.description-input {
			flex: 1;
			/* Takes remaining space */
		}

		.description-input textarea {
			width: 95%;
			resize: vertical;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Tax Settings</h2>

		<?php if (isset($success)): ?>
		<div class="message success"><?= $success ?></div>
		<?php endif; ?>

		<?php if (isset($error)): ?>
		<div class="message error"><?= $error ?></div>
		<?php endif; ?>

		<!-- Add New Category Form -->
		<div class="new-category">
			<h3>Add New Category</h3>
			<form method="post">
				<input type="hidden" name="action" value="add_category">
				<div class="form-row">
					<div class="form-group">
						<label>Category Name:</label>
						<input type="text" name="new_category" required pattern="[A-Z_]+"
							title="Uppercase letters and underscore only" placeholder="e.g., MEDICAL_DEVICES">
					</div>
					<div class="form-group">
						<label>GST Rate (%):</label>
						<input type="number" name="gst" required min="0" max="100" step="0.01" value="0">
					</div>
				</div>
				<div class="form-group">
					<label>Description:</label>
					<textarea name="description" rows="2" required></textarea>
				</div>
				<button type="submit" class="btn-primary">Add Category</button>
			</form>
		</div>

		<!-- Existing Categories -->
		<form method="post">
			<div class="tax-list">
				<h3>Existing Categories</h3>
				<?php foreach ($tax_rates as $category => $data): ?>
				<div class="tax-item">
					<div class="form-row-tax">
						<div class="form-group gst-input">
							<label><?= htmlspecialchars($category) ?>:</label>
							<input type="number"
								name="categories[<?= $category ?>][gst]"
								value="<?= $data['gst'] ?>"
								min="0" max="100" step="0.01" required>
						</div>
						<div class="form-group description-input">
							<label>Description:</label>
							<textarea
								name="categories[<?= $category ?>][description]"><?= htmlspecialchars($data['description']) ?></textarea>
						</div>
						<?php if (!in_array($category, ['PHARMACY', 'OTHER'])): ?>
						<div class="form-group actions">
							<button type="button" class="btn-delete"
								onclick="deleteCategory('<?= htmlspecialchars($category) ?>')">
								Delete
							</button>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<button type="submit" class="btn-primary">Save All Changes</button>
		</form>
	</div>
	<script>
		function deleteCategory(category) {
			if (!confirm(`Are you sure you want to delete the category: ${category}?`)) {
				return;
			}

			const formData = new FormData();
			formData.append('action', 'delete');
			formData.append('category', category);

			fetch(window.location.href, {
					method: 'POST',
					body: formData
				})
				.then(response => response.text())
				.then(html => {
					window.location.reload();
				})
				.catch(error => {
					alert('Error deleting category');
					console.error('Error:', error);
				});
		}
	</script>
</body>

</html>