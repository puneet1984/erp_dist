<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/pharmacy_helper.php';

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__.'/../../logs/php_errors.log');

requirePermission('pharmacy', 'view');

$item_id = sanitizeInteger($_GET['id'] ?? 0);
if (!$item_id) {
    error_log('Invalid item ID provided: ' . var_export($_GET['id'], true));
    $_SESSION['error'] = 'Invalid item ID';
    header('Location: inventory.php');
    exit;
}

$details = getItemDetails($item_id);
if (!$details) {
    error_log('Failed to get item details for ID: ' . $item_id);
    $_SESSION['error'] = 'Item not found or error occurred';
    header('Location: inventory.php');
    exit;
}

error_log('Successfully retrieved details for item ID: ' . $item_id);

$item = $details['item'];
$batches = $details['batches'];
$purchases = $details['purchases'];
$sales = $details['sales'];
?>

<!DOCTYPE html>
<html>

<head>
	<title>View Item Details</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.details-card {
			background: white;
			padding: 20px;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
			margin-bottom: 20px;
		}

		.item-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 20px;
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 20px;
			margin-bottom: 20px;
		}

		.stat-card {
			background: #f8f9fa;
			padding: 15px;
			border-radius: 6px;
			text-align: center;
		}

		.stat-card h3 {
			margin: 0;
			color: #6c757d;
			font-size: 0.9em;
		}

		.stat-card p {
			margin: 10px 0 0;
			font-size: 1.5em;
			font-weight: bold;
			color: #007bff;
		}

		.table-section {
			margin-top: 30px;
		}

		.back-btn {
			padding: 10px 20px;
			color: white;
			text-decoration: none;
			border-radius: 4px;
			margin-right: 10px;
			background: #007bff;
			font-size: 16px !important;
		}

		.expire-warning {
			color: #dc3545;
			font-weight: bold;
		}

		.table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 1rem;
			background-color: transparent;
		}

		.table th,
		.table td {
			padding: 12px;
			vertical-align: top;
			border-top: 1px solid #dee2e6;
			text-align: left;
		}

		.table thead th {
			vertical-align: bottom;
			border-bottom: 2px solid #dee2e6;
			background-color: #f8f9fa;
			font-weight: 600;
		}

		.table tbody tr:nth-of-type(odd) {
			background-color: rgba(0, 0, 0, 0.05);
		}

		.table tbody tr:hover {
			background-color: rgba(0, 0, 0, 0.075);
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="details-card">
			<div class="item-header">
				<h2><?php echo htmlspecialchars(makeUpperCaseMixed($item['name'] ?? '')); ?></h2>
				<a href="inventory.php" class="back-btn">Back to Inventory</a>
			</div>

			<div class="stats-grid">
				<div class="stat-card">
					<h3>Category</h3>
					<p><?php echo $item['category'] ?? 'N/A'; ?></p>
				</div>
				<div class="stat-card">
					<h3>Current Stock</h3>
					<p><?php echo array_sum(array_column($batches, 'current_quantity')); ?>
					</p>
				</div>
				<div class="stat-card">
					<h3>Reorder Level</h3>
					<p><?php echo $item['reorder_level']; ?>
					</p>
				</div>
				<div class="stat-card">
					<h3>Active Batches</h3>
					<p><?php echo count($batches); ?></p>
				</div>
				<div class="stat-card">
					<h3>Manufacturer</h3>
					<p><?php echo htmlspecialchars($item['manufacturer'] ?? ''); ?></p>
				</div>
			</div>

			<?php if ('MEDICINE' === $item['category']) { ?>
			<div class="details-card">
				<h3>Medicine Details</h3>
				<p><strong>Active Ingredient:</strong>
					<?php echo htmlspecialchars($item['active_ingredient'] ?? ''); ?>
				</p>
				<p><strong>Dosage Form:</strong>
					<?php echo htmlspecialchars($item['dosage_form'] ?? ''); ?>
				</p>
				<p><strong>Manufacturer:</strong>
					<?php echo htmlspecialchars($item['manufacturer'] ?? ''); ?>
				</p>
			</div>
			<?php } ?>

			<div class="table-section">
				<h3>Current Stock</h3>
				<div class="table-responsive">
					<table class="table">
						<thead>
							<tr>
								<th>Batch No</th>
								<th>Quantity</th>
								<th>Purchase Rate</th>
								<th>Sale Price</th>
								<th>Expiry Date</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($batches as $batch) {
							    $expiry_date = new DateTime($batch['expiry_date']);
							    $today = new DateTime();
							    $days_until_expiry = $today->diff($expiry_date)->days;
							    $expiry_class = $days_until_expiry <= 90 ? 'expire-warning' : '';
							    ?>
							<tr>
								<td><?php echo htmlspecialchars($batch['batch_number']); ?>
								</td>
								<td><?php echo $batch['current_quantity']; ?>
								</td>
								<td>₹<?php echo number_format($batch['purchase_rate'], 2); ?>
								</td>
								<td>₹<?php echo number_format($batch['retail_price'], 2); ?>
								</td>
								<td
									class="<?php echo $expiry_class; ?>">
									<?php echo date('d M Y', strtotime($batch['expiry_date'])); ?>
								</td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="table-section">
				<h3>Recent Transactions</h3>
				<div class="table-responsive">
					<table class="table">
						<thead>
							<tr>
								<th>Date</th>
								<th>Type</th>
								<th>Batch</th>
								<th>Quantity</th>
								<th>Rate</th>
								<th>Total</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$transactions = array_merge($purchases, $sales);
							usort($transactions, fn ($a, $b) => strtotime($b['transaction_date']) - strtotime($a['transaction_date']));
							
							// Create batch mapping using batch_id as key
							$batch_map = [];
							foreach ($batches as $batch) {
								$batch_map[$batch['batch_id']] = $batch['batch_number'];
							}
							
							foreach (array_slice($transactions, 0, 10) as $txn) {
								$batch_number = $batch_map[$txn['batch_id']] ?? '(Unknown)';
							?>
							<tr>
								<td><?php echo date('d M Y', strtotime($txn['transaction_date'])); ?></td>
								<td><?php echo $txn['transaction_type']; ?></td>
								<td><?php echo htmlspecialchars($batch_number); ?></td>
								<td><?php echo $txn['quantity']; ?></td>
								<td>₹<?php echo number_format($txn['unit_price'], 2); ?></td>
								<td>₹<?php echo number_format($txn['net_amount'], 2); ?></td>
							</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</body>

</html>