<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!canPrintBill()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}
// require_once '../../includes/helpers/print_header.php';
// require_once '../../includes/helpers/print_footer.php';

// Get hospital settings constants
$settings = getHospitalSettings();

if (!isset($_GET['id'])) {
    exit('Transaction ID not provided');
}

$transaction_id = sanitizeInput($_GET['id']); // This is actually transaction_id from sale.php
$conn = getDbConnection();

// Get sale details
$stmt = $conn->prepare('
    SELECT ps.*, p.name as patient_name, p.patient_id, p.address as patient_address, p.city as patient_city,
           d.name as doctor_name,
           pi.name as medicine_name, pi.manufacturer,
           pb.batch_number, pb.expiry_date,
           pti.quantity, pti.unit_price, pti.discount_amount,
           pti.final_amount,
           ps.payment_status
    FROM pharmacy_sales ps
    JOIN patients p ON ps.patient_id = p.patient_id
    LEFT JOIN doctors d ON ps.doctor_id = d.doctor_id
    JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id
    JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    WHERE ps.transaction_id = ?
');

$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$result = $stmt->get_result();
$sales = $result->fetch_all(MYSQLI_ASSOC);

if (empty($sales)) {
    exit('Transaction not found');
}

// Calculate totals from transaction_items
$subtotal = 0;
$total_discount = 0;
foreach ($sales as $sale) {
    $subtotal += $sale['unit_price'] * $sale['quantity'];
    $total_discount += $sale['discount_amount'];
}
$net_amount = $subtotal - $total_discount;
?>
<!DOCTYPE html>
<html>

<head>
	<title>Print Pharmacy Bill -
		<?php echo htmlspecialchars($sales[0]['bill_id']); ?>
	</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/bill.css">
	<script src="../../assets/js/number2words.js"></script>
	<?php if (isset($_GET['trigger_print']) && '1' === $_GET['trigger_print']) { ?>
	<script>
		window.onload = function() {
			window.print();
			window.onafterprint = function() {
				window.close();
			};
		}
	</script>
	<?php } ?>
</head>

<body>
	<div class="print-area">
		<div class="bill-container">
			<!-- Header Section -->
			<div class="row header-row">
				<div class="col-8 d-flex align-items-center">
					<?php if (getHospitalLogoUrl()): ?>
					<img src="<?php echo htmlspecialchars(getHospitalLogoUrl()); ?>" alt="Hospital Logo" class="me-3"
						style="height: 60px;">
					<?php endif; ?>
					<div>
						<h5 class="mb-1"><?php echo HOSPITAL_NAME; ?>
						</h5>
						<p class="mb-0 small" style="font-size: 12px;">
							<?php echo HOSPITAL_ADDRESS; ?></p>
						<p class="mb-0 small" style="font-size: 10px;">Ph:
							<?php echo HOSPITAL_PHONE1; ?> /
							<?php echo HOSPITAL_PHONE2; ?></p>
					</div>
				</div>
				<div class="col-4">
					<h5 class="text-center mb-2">
						Pharmacy Bill
						<?php if (isset($sales[0]['payment_status']) && 'CANCELLED' === $sales[0]['payment_status']) { ?>
						<br><span class="text-danger">(Cancelled)</span>
						<?php } ?>
					</h5>
					<table class="table table-sm table-borderless mb-0">
						<tr>
							<th style="width: 80px">Bill No:</th>
							<td><?php echo htmlspecialchars($sales[0]['bill_id']); ?>
							</td>
						</tr>
						<tr>
							<th>Date:</th>
							<td><?php echo date('d-m-Y', strtotime($sales[0]['sale_date'])); ?>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Details Section -->
			<div class="row details-row">
				<div class="col-6">
					<div class="card mb-0">
						<div class="card-body py-2">
							<h6 class="card-title mb-2">Patient Details</h6>
							<table class="table table-sm table-borderless mb-0">
								<tr>
									<td>Name:</td>
									<td><?php echo htmlspecialchars($sales[0]['patient_name']); ?>
									</td>
								</tr>
								<tr>
									<td>Address:</td>
									<td><?php echo htmlspecialchars($sales[0]['patient_address']).', '.
                                               htmlspecialchars($sales[0]['patient_city']); ?>
									</td>
								</tr>
							</table>
						</div>
					</div>
				</div>
				<div class="col-6">
					<div class="card mb-0">
						<div class="card-body py-2">
							<h6 class="card-title mb-2">Doctor Details</h6>
							<table class="table table-sm table-borderless mb-0">
								<tr>
									<td>Doctor:</td>
									<td>Dr.
										<?php echo htmlspecialchars($sales[0]['doctor_name']); ?>
									</td>
								</tr>
							</table>
						</div>
					</div>
				</div>
			</div>

			<!-- Services Section -->
			<div class="row services-row mt-4">
				<div class="col-12">
					<table class="table table-sm">
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
							<?php foreach ($sales as $sale) { ?>
							<tr>
								<td><?php echo $sale['medicine_name']; ?>
								</td>
								<td><?php echo $sale['batch_number']; ?>
								</td>
								<td><?php echo date('m/Y', strtotime($sale['expiry_date'])); ?>
								</td>
								<td><?php echo $sale['quantity']; ?>
								</td>
								<td>₹<?php echo number_format($sale['unit_price'], 2); ?>
								</td>
								<td>₹<?php echo number_format($sale['unit_price'] * $sale['quantity'], 2); ?>
								</td>
								<td>₹<?php echo number_format($sale['final_amount'], 2); ?>
								</td>
							</tr>
							<?php } ?>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="6" class="text-end">Subtotal:</td>
								<td>₹<?php echo number_format($subtotal, 2); ?>
								</td>
							</tr>
							<?php if ($total_discount > 0) { ?>
							<tr>
								<td colspan="6" class="text-end">Total Discount:</td>
								<td>₹<?php echo number_format($total_discount, 2); ?>
								</td>
							</tr>
							<?php } ?>
							<tr class="total">
								<td colspan="6" class="text-end"><strong>Net Amount:</strong></td>
								<td><strong>₹<?php echo number_format($net_amount, 2); ?></strong>
								</td>
							</tr>
						</tfoot>
					</table>

					<div class="row mt-3">
						<div class="col-12">
							<div class="card">
								<div class="card-body">
									<p class="mb-0">Amount in words: <strong><span id="amountInWords"></span></strong>
									</p>
								</div>
							</div>
						</div>
					</div>

					<!-- Signature Section -->
					<div class="row signature-row mt-3">
						<div class="col-12 text-end">
							<p>Authorized Signatory</p>
							<div class="spacer">&nbsp;</div>
							<div class="spacer">&nbsp;</div>
							<div class="spacer">&nbsp;</div>
							<p class="mt-2" style="font-weight: bold;">
								<?php echo HOSPITAL_NAME; ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="no-print text-center mt-4 mb-4">
			<button onclick="window.print()" class="btn btn-primary">Print Bill</button>
		</div>
	</div>

	<script>
		// Convert amount to words
		const amount = <?php echo $net_amount; ?> ;
		const rupees = Math.floor(amount);
		const paise = Math.round((amount - rupees) * 100);

		let words = rupees > 0 ? convertToIndianWords(rupees) + " Rupees" : "";
		if (paise > 0) {
			words += (words ? " and " : "") + convertToIndianWords(paise) + " Paise";
		}
		if (rupees === 0 && paise === 0) {
			words = "Zero Rupees";
		}

		document.getElementById('amountInWords').textContent = words + " Only";
	</script>
</body>

</html>