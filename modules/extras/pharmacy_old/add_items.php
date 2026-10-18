<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

function checkDuplicate($conn, $name, $activeIngredient, $manufacturer) {
    $name = "%$name%";
    $stmt = $conn->prepare('SELECT item_id, name, active_ingredient, manufacturer 
        FROM pharmacy_items 
        WHERE name LIKE ? AND active_ingredient = ? AND manufacturer = ?
        LIMIT 1');
    $stmt->bind_param('sss', $name, $activeIngredient, $manufacturer);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_object();
    return $result;
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $conn = getDbConnection();
    $name = makeUpperCaseMixed(sanitizeInput($_POST['name']));
    $category = sanitizeInput($_POST['category']);
    $activeIngredient = sanitizeInput($_POST['active_ingredient']);
    $dosageForm = sanitizeInput($_POST['dosage_form']);
    $manufacturer = capitalizeWords(sanitizeInput($_POST['manufacturer']));
    $reorderLevel = (int) sanitizeInput($_POST['reorder_level']);
    
    try {
        // Check for duplicates
        $duplicate = checkDuplicate($conn, $name, $activeIngredient, $manufacturer);
        if ($duplicate) {
            throw new Exception(sprintf(
                'Similar item already exists:%s%s (Active: %s, Mfr: %s)',
                PHP_EOL,
                $duplicate->name,
                $duplicate->active_ingredient,
                $duplicate->manufacturer
            ));
        }

        $stmt = $conn->prepare('INSERT INTO pharmacy_items 
            (name, category, active_ingredient, dosage_form, manufacturer, reorder_level) 
            VALUES (?, ?, ?, ?, ?, ?)');
        
        $stmt->bind_param(
            'sssssi',
            $name,
            $category,
            $activeIngredient,
            $dosageForm,
            $manufacturer,
            $reorderLevel
        );

        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => 'Item saved successfully']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Pharmacy Items Management</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<style>
		body {
			font-family: Arial, sans-serif;
			margin: 0;
			padding: 20px;
			background: #f5f5f5;
		}

		.container {
			max-width: 800px;
			margin: 0 auto;
			background: white;
			padding: 20px;
			border-radius: 5px;
			box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
		}

		.form-group {
			margin-bottom: 15px;
		}

		.alert {
			display: none;
			margin-top: 20px;
		}

		/* Custom select styling */
		.form-select {
			background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
			background-repeat: no-repeat;
			background-position: right 0.75rem center;
			background-size: 16px 12px;
			appearance: none;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="d-flex justify-content-between align-items-center mb-4">
			<h2>Add Pharmacy Item</h2>
			<button type="button" class="btn btn-secondary" onclick="window.history.back()">← Back</button>
		</div>

		<form id="itemForm">
			<div class="form-group">
				<label>Item Name*:</label>
				<input type="text" class="form-control" name="name" required>
			</div>

			<div class="form-group">
				<label>Category*:</label>
				<select class="form-control form-select" name="category" required>
					<option value="MEDICINE">Medicine</option>
					<option value="CONSUMABLE">Consumable</option>
				</select>
			</div>

			<div class="form-group">
				<label>Active Ingredient:</label>
				<input type="text" class="form-control" name="active_ingredient" id="active_ingredient">
			</div>

			<div class="form-group">
				<label>Dosage Form:</label>
				<select class="form-control form-select" name="dosage_form">
					<option value="">Select Dosage Form</option>
					<option value="INJECTION">Injection</option>
					<option value="INFUSION">Infusion</option>
					<option value="CREAM">Cream</option>
					<option value="INHALATION">Inhalation</option>
					<option value="TABLET">Tablet</option>
				</select>
			</div>

			<div class="form-group">
				<label>Manufacturer:</label>
				<input type="text" class="form-control" name="manufacturer" id="manufacturer">
			</div>

			<div class="form-group">
				<label>Reorder Level:</label>
				<input type="number" class="form-control" name="reorder_level" value="1">
			</div>

			<button type="submit" class="btn btn-primary">Save Item</button>
		</form>

		<div class="alert alert-success"></div>
		<div class="alert alert-danger"></div>
	</div>

	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script>
		$(document).ready(function () {
			// Add category change handler
			$('select[name="category"]').on('change', function () {
				const isMedicine = $(this).val() === 'MEDICINE';
				const activeIngredientGroup = $('#active_ingredient').closest('.form-group');
				const dosageFormGroup = $('select[name="dosage_form"]').closest('.form-group');

				// Show/hide fields
				activeIngredientGroup.toggle(isMedicine);
				dosageFormGroup.toggle(isMedicine);

				// Clear values when hidden
				if (!isMedicine) {
					$('#active_ingredient').val('');
					$('select[name="dosage_form"]').val('');
				}

				// Set required attributes
				$('#active_ingredient').prop('required', isMedicine);
				$('#manufacturer').prop('required', isMedicine);

				// Update labels
				$('#active_ingredient').prev('label').html(
					'Active Ingredient' + (isMedicine ? '*:' : ':')
				);
				$('#manufacturer').prev('label').html(
					'Manufacturer' + (isMedicine ? '*:' : ':')
				);
			});

			$('#itemForm').on('submit', function (e) {
				e.preventDefault();
				$.ajax({
					url: 'add_items.php',
					method: 'POST',
					data: $(this).serialize(),
					success: function (response) {
						const data = JSON.parse(response);
						if (data.status === 'success') {
							$('.alert-success').text(data.message).show();
							$('.alert-danger').hide();
							$('#itemForm')[0].reset();
						} else {
							$('.alert-danger').text(data.message).show();
							$('.alert-success').hide();
						}
					},
					error: function () {
						$('.alert-danger').text('Server error occurred').show();
						$('.alert-success').hide();
					}
				});
			});
		});
	</script>
</body>

</html>