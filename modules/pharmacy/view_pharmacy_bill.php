<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!isset($_GET['id'])) {
    exit('Transaction ID not provided');
}

$transaction_id = sanitizeInput($_GET['id']);
$conn = getDbConnection();

// Get sale details with patient and doctor info
$stmt = $conn->prepare('
    SELECT ps.*, p.name as patient_name, p.patient_id, p.address as patient_address, 
           p.city as patient_city, d.name as doctor_name,
           pt.type_name as payment_type
    FROM pharmacy_sales ps
    JOIN patients p ON ps.patient_id = p.patient_id
    LEFT JOIN doctors d ON ps.doctor_id = d.doctor_id
    JOIN payment_types pt ON ps.payment_type_id = pt.payment_type_id
    WHERE ps.transaction_id = ?
');

$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    exit('Transaction not found');
}

// Get medicine details
$stmt = $conn->prepare('
    SELECT pi.name as medicine_name, pi.manufacturer,
           pb.batch_number, pb.expiry_date,
           pti.quantity, pti.unit_price, pti.discount_amount,
           pti.final_amount
    FROM pharmacy_transaction_items pti
    JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    WHERE pti.transaction_id = ?
');

$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>View Pharmacy Bill</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<div class="row mb-3">
			<div class="col">
				<h2>Pharmacy Bill Details</h2>
			</div>
			<div class="col text-end">
				<a href="/erp/modules/billing/bills_list.php?search=&type=pharmacy&fy=&from_date=&to_date=" class="btn btn-secondary">Back</a>
				<button
					onclick="window.open('print_pharmacy_bill.php?id=<?php echo $transaction_id; ?>&trigger_print=1', '_blank')"
					class="btn btn-primary">Print Bill</button>
				<?php if (checkPermission('bills', 'edit')) { ?>
			<a href="edit_pharmacy_bill.php?id=<?php echo $transaction_id; ?>"
					onclick="return confirmEditPaidBill('<?php echo $sale['payment_status']; ?>')"
					class="btn btn-warning">Edit</a>
				<?php } ?>
				<?php if (checkPermission('bills', 'delete')) { ?>
				<button
					onclick="confirmDelete('<?php echo $transaction_id; ?>')"
					class="btn btn-danger">Delete</button>
				<?php } ?>
			</div>
		</div>

		<div class="card mb-3">
			<div class="card-body">
				<div class="row">
					<div class="col-md-6">
						<h5>Bill Information</h5>
						<p><strong>Bill No:</strong>
							<?php echo htmlspecialchars($sale['bill_id']); ?>
						</p>
						<p><strong>Date:</strong>
							<?php echo formatDisplayDate($sale['sale_date']); ?>
						</p>
						<p><strong>Payment Status:</strong>
							<?php echo $sale['payment_status']; ?>
						</p>
						<p><strong>Payment Type:</strong>
							<?php echo $sale['payment_type']; ?>
						</p>
					</div>
					<div class="col-md-6">
						<h5>Patient Information</h5>
						<p><strong>Name:</strong>
							<?php echo htmlspecialchars($sale['patient_name']); ?>
						</p>
						<p><strong>ID:</strong>
							<?php echo htmlspecialchars($sale['patient_id']); ?>
						</p>
						<p><strong>Address:</strong>
							<?php echo htmlspecialchars($sale['patient_address'].', '.$sale['patient_city']); ?>
						</p>
						<p><strong>Doctor:</strong>
							<?php echo htmlspecialchars($sale['doctor_name']); ?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="card">
			<div class="card-body">
				<h5>Medicines</h5>
				<table class="table">
					<thead>
						<tr>
							<th>Medicine</th>
							<th>Batch</th>
							<th>Expiry</th>
							<th>Qty</th>
							<th>Rate</th>
							<th>Amount</th>
							<th>Net</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($items as $item) { ?>
						<tr>
							<td><?php echo htmlspecialchars($item['medicine_name']); ?>
							</td>
							<td><?php echo htmlspecialchars($item['batch_number']); ?>
							</td>
							<td><?php echo formatDisplayDate($item['expiry_date']); ?>
							</td>
							<td><?php echo $item['quantity']; ?>
							</td>
							<td>₹<?php echo number_format($item['unit_price'], 2); ?>
							</td>
							<td>₹<?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?>
							</td>
							<td>₹<?php echo number_format($item['final_amount'], 2); ?>
							</td>
						</tr>
						<?php } ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="6" class="text-end">Subtotal:</td>
							<td>₹<?php echo number_format($sale['subtotal'], 2); ?>
							</td>
						</tr>
						<?php if ($sale['discount_amount'] > 0) { ?>
						<tr>
							<td colspan="6" class="text-end">Discount:</td>
							<td>₹<?php echo number_format($sale['discount_amount'], 2); ?>
							</td>
						</tr>
						<?php } ?>
						<tr>
							<td colspan="6" class="text-end"><strong>Total Amount:</strong></td>
							<td><strong>₹<?php echo number_format($sale['final_amount'], 2); ?></strong>
							</td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
	</div>

	<script>
		function confirmDelete(transactionId) {
			if (confirm('Are you sure you want to delete this bill? This action cannot be undone.')) {
				window.location.href = `delete_pharmacy_bill.php?id=${transactionId}`;
			}
		}

		function confirmEditPaidBill(status) {
			if (status === 'PAID') {
				return confirm(
					'Warning: This bill has already been paid. Edit only if there was a data entry mistake. Do you want to continue?'
					);
			}
			return true;
		}
	</script>
</body>

</html>