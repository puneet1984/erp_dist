<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $response = ['status' => 'error', 'message' => ''];

    try {
        // Validate input
        $type_name = isset($_POST['type_name']) ? strtoupper(trim($_POST['type_name'])) : '';
        if (!preg_match('/^[A-Z_]+$/', $type_name)) {
            throw new Exception('Payment mode name must contain only uppercase letters and underscores');
        }

        if (strlen($type_name) > 50) {
            throw new Exception('Payment mode name must be less than 50 characters');
        }

        if ($_POST['action'] === 'add') {
            // Check for duplicate
            $check = $conn->prepare("SELECT COUNT(*) FROM payment_types WHERE type_name = ?");
            $check->bind_param("s", $type_name);
            $check->execute();
            if ($check->get_result()->fetch_row()[0] > 0) {
                throw new Exception('Payment mode already exists');
            }

            $stmt = $conn->prepare("INSERT INTO payment_types (type_name) VALUES (?)");
            $stmt->bind_param("s", $type_name);
        } elseif ($_POST['action'] === 'update') {
            // Check for duplicate excluding current ID
            $check = $conn->prepare("SELECT COUNT(*) FROM payment_types WHERE type_name = ? AND payment_type_id != ?");
            $check->bind_param("si", $type_name, $_POST['payment_type_id']);
            $check->execute();
            if ($check->get_result()->fetch_row()[0] > 0) {
                throw new Exception('Payment mode name already exists');
            }

            $stmt = $conn->prepare("UPDATE payment_types SET type_name = ? WHERE payment_type_id = ?");
            $stmt->bind_param("si", $type_name, $_POST['payment_type_id']);
        } elseif ($_POST['action'] === 'delete') {
            // Check if payment mode is in use
            $checks = [
                'bills' => 'bills',
                'pharmacy_bills' => 'pharmacy bills',
                'payment_transactions' => 'payment transactions'
            ];

            foreach ($checks as $table => $description) {
                $check = $conn->prepare("SELECT COUNT(*) FROM $table WHERE payment_type_id = ?");
                $check->bind_param("i", $_POST['payment_type_id']);
                $check->execute();
                if ($check->get_result()->fetch_row()[0] > 0) {
                    throw new Exception("Cannot delete: Payment mode is being used in $description");
                }
            }

            $stmt = $conn->prepare("DELETE FROM payment_types WHERE payment_type_id = ?");
            $stmt->bind_param("i", $_POST['payment_type_id']);
        }

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $response['status'] = 'success';

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get list of payment modes
$conn = getDbConnection();
$payment_modes = $conn->query("SELECT * FROM payment_types ORDER BY payment_type_id ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>

<head>
	<title>Payment Modes Management</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/admin.css">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Payment Modes Management</h2>

		<div class="settings-section">
			<form id="paymentModeForm">
				<input type="hidden" name="action" value="add">
				<input type="hidden" name="payment_type_id" value="">

				<div class="form-row">
					<div class="form-group">
						<label>Payment Mode Name:</label>
						<input type="text" name="type_name" required pattern="[A-Z_]+"
							title="Uppercase letters and underscore only">
						<small>Example: CASH, CARD, UPI, etc.</small>
					</div>
				</div>

				<div class="form-actions">
					<button type="submit" id="submitBtn">Add Payment Mode</button>
					<button type="button" onclick="resetForm()" class="hidden" id="cancelBtn">Cancel</button>
				</div>
			</form>

			<div class="payment-modes-list">
				<h3>Available Payment Modes</h3>
				<table class="data-table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Payment Mode</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($payment_modes as $mode): ?>
						<tr>
							<td><?= $mode['payment_type_id'] ?>
							</td>
							<td><?= htmlspecialchars($mode['type_name']) ?>
							</td>
							<td>
								<button
									onclick='editMode(<?= json_encode($mode) ?>)'>Edit</button>
								<button
									onclick="deleteMode(<?= $mode['payment_type_id'] ?>)">Delete</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
		function editMode(mode) {
			const form = document.getElementById('paymentModeForm');
			form.action.value = 'update';
			form.payment_type_id.value = mode.payment_type_id;
			form.type_name.value = mode.type_name;

			document.getElementById('submitBtn').textContent = 'Update Payment Mode';
			document.getElementById('cancelBtn').classList.remove('hidden');
		}

		function resetForm() {
			const form = document.getElementById('paymentModeForm');
			form.reset();
			form.action.value = 'add';
			form.payment_type_id.value = '';
			document.getElementById('submitBtn').textContent = 'Add Payment Mode';
			document.getElementById('cancelBtn').classList.add('hidden');
		}

		function deleteMode(id) {
			if (!confirm('Are you sure you want to delete this payment mode?')) return;

			fetch(window.location.href, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded'
					},
					body: `action=delete&payment_type_id=${id}`
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

		document.getElementById('paymentModeForm').onsubmit = function(e) {
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