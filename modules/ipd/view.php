<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

if (!isset($_GET['id'])) {
    exit('Admission ID is required');
}

// Get admission details using function instead of direct query
$admission = getAdmissionDetails($_GET['id']);
if (!$admission) {
    exit('Invalid admission ID');
}

// Calculate days of stay using the dedicated function
$days_stayed = calculateAdmissionDays(
    $admission['admission_date'],
    $admission['discharge_date'] ?? date('Y-m-d H:i:s')
);

// Get simplified billing summary including advances
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT 
        mb.bill_id,
        mb.bill_date,
        mb.bill_type,
        mb.transaction_id,
        mb.final_amount,
        mb.payment_status,
        mb.is_advance
    FROM (
        SELECT 
            bill_id,
            bill_date as bill_date,
            'Hospital Bill' as bill_type,
            transaction_id,
            final_amount,
            payment_status,
            payment_status = 'ADVANCE' as is_advance
        FROM bills 
        WHERE admission_id = ?
        UNION ALL
        SELECT 
            bill_id,
            sale_date as bill_date,
            'Pharmacy Bill' as bill_type,
            transaction_id,
            final_amount,
            payment_status,
            FALSE as is_advance
        FROM pharmacy_sales 
        WHERE admission_id = ?
    ) mb
    ORDER BY mb.is_advance ASC, mb.bill_date DESC");

$stmt->bind_param('ii', $_GET['id'], $_GET['id']);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>IPD Patient View</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
	<style>
		.status-badge {
			padding: 5px 10px;
			border-radius: 15px;
			font-weight: 500;
		}

		.status-admitted {
			background: #e7f5e7;
			color: #0a6b0a;
		}

		.status-discharged {
			background: #f8f9fa;
			color: #6c757d;
		}

		.info-card {
			background: #f8f9fa;
			padding: 15px;
			border-radius: 5px;
			margin-bottom: 20px;
		}

		.action-buttons {
			position: sticky;
			top: 0;
			background: white;
			padding: 10px 0;
			border-bottom: 1px solid #dee2e6;
			z-index: 100;
		}

		.bill-actions {
			margin-top: 10px;
			text-align: right;
		}

		.bill-actions .btn-sm {
			padding: 0.25rem 0.5rem;
			font-size: 0.875rem;
		}

		.btn-back {
			margin-right: 10px;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container mt-4">
		<div class="action-buttons mb-4">
			<div class="d-flex justify-content-between align-items-center">
				<div class="d-flex align-items-center">
					<button onclick="goBack()" class="btn btn-outline-secondary btn-back">
						<i class="fas fa-arrow-left"></i> Back
					</button>
					<h2>IPD Patient Details</h2>
				</div>
				<div>
					<?php if ('Admitted' === $admission['status']) { ?>
					<a href="discharge.php?id=<?php echo $admission['admission_id']; ?>"
						class="btn btn-success">
						<i class="fas fa-procedures"></i> Discharge Patient
					</a>
					<a href="advance_payment.php?admission_id=<?php echo $admission['admission_id']; ?>"
						class="btn btn-info">
						<i class="fas fa-money-bill-wave"></i> Add Advance
					</a>
					<?php } ?>
					<a href="../forms/admission_form.php?id=<?php echo $admission['admission_id']; ?>"
						class="btn btn-secondary" target="_blank">
						<i class="fas fa-file-contract"></i> View Admission Form
					</a>
					<a href="ipd_bill.php?admission_id=<?php echo $admission['admission_id']; ?>"
						class="btn btn-primary">
						<i class="fas fa-file-invoice"></i> Generate Bill
					</a>
				</div>
			</div>
		</div>

		<div class="row">
			<div class="col-md-8">
				<!-- Patient & Admission Info -->
				<div class="card mb-3">
					<div class="card-header d-flex justify-content-between align-items-center">
						<h5 class="mb-0">Patient Information</h5>
						<span
							class="status-badge status-<?php echo strtolower($admission['status']); ?>">
							<?php echo $admission['status']; ?>
						</span>
					</div>
					<div class="card-body">
						<div class="row">
							<div class="col-md-6">
								<p><strong>Patient ID:</strong>
									<?php echo htmlspecialchars($admission['patient_id'] ?? ''); ?>
								</p>
								<p><strong>Patient Name:</strong>
									<?php echo htmlspecialchars($admission['patient_name'] ?? ''); ?>
								</p>
								<p><strong>Age/Gender:</strong>
									<?php echo $admission['age'] ?? ''; ?>/<?php echo formatGender($admission['gender'] ?? ''); ?>
								</p>
								<p><strong>Contact:</strong>
									<?php echo htmlspecialchars($admission['contact'] ?? 'Not provided'); ?>
								</p>
							</div>
							<div class="col-md-6">
								<p><strong>Admission ID:</strong>
									<?php echo $admission['admission_id']; ?>
								</p>
								<p><strong>Admission Date:</strong>
									<?php echo date('d-m-Y H:i', strtotime($admission['admission_date'] ?? '')); ?>
								</p>
								<p><strong>Discharge Date:</strong>
									<?php if (!empty($admission['discharge_date'])) {
									    echo date('d-m-Y H:i', strtotime($admission['discharge_date']));
									} else {
									    echo 'Not discharged';
									} ?>
								</p>
								<p><strong>Days Stayed:</strong>
									<?php echo $days_stayed; ?> days
								</p>
							</div>
						</div>
					</div>
				</div>

				<!-- Medical Details -->
				<div class="card mb-3">
					<div class="card-header">
						<div class="d-flex justify-content-between align-items-center">
							<h5 class="mb-0">Medical Details</h5>
							<a href="edit_details.php?id=<?php echo $admission['admission_id']; ?>"
								class="btn btn-sm btn-primary">
								<i class="fas fa-edit"></i> Edit Details
							</a>
						</div>
					</div>
					<div class="card-body">
						<div class="row mb-3">
							<div class="col-md-6">
								<p><strong>Doctor:</strong> Dr.
									<?php echo htmlspecialchars($admission['doctor_name'] ?? ''); ?>
								</p>
								<p><strong>Department:</strong>
									<?php echo htmlspecialchars($admission['dept_name'] ?? 'Not assigned'); ?>
								</p>
							</div>
							<div class="col-md-6">
								<p><strong>Ward:</strong>
									<?php echo htmlspecialchars($admission['ward_name'] ?? ''); ?>
								</p>
								<p><strong>Bed Number:</strong>
									<?php echo htmlspecialchars($admission['bed_number'] ?? ''); ?>
								</p>
							</div>
						</div>

						<div class="mb-3">
							<h6>Diagnosis</h6>
							<p class="text-muted">
								<?php echo nl2br(htmlspecialchars($admission['diagnosis'] ?? 'Not provided')); ?>
							</p>
						</div>

						<div class="mb-3">
							<h6>Treatment Plan</h6>
							<p class="text-muted">
								<?php echo nl2br(htmlspecialchars($admission['treatment_plan'] ?? 'Not provided')); ?>
							</p>
						</div>

						<?php if ($admission['surgery_details']) { ?>
						<div class="mb-3">
							<h6>Surgery Details</h6>
							<p class="text-muted">
								<?php echo nl2br(htmlspecialchars($admission['surgery_details'])); ?>
							</p>
							<p><strong>Surgery Date:</strong>
								<?php echo date('d-m-Y', strtotime($admission['surgery_date'])); ?>
							</p>
						</div>
						<?php } ?>

						<?php if ('Discharged' === $admission['status']) { ?>
						<div class="mb-3">
							<h6>Discharge Summary</h6>
							<!-- <p><strong>Date:</strong> <?php echo date('d-m-Y H:i', strtotime($admission['discharge_date'])); ?>
							</p> -->
							<p><strong>Condition on discharge:</strong>
								<?php echo htmlspecialchars($admission['discharge_condition'] ?? ''); ?>
							</p>
							<p><strong>Discharge Notes:</strong></p>
							<p class="text-muted">
								<?php echo nl2br(htmlspecialchars($admission['discharge_notes'] ?? '')); ?>
							</p>

							<?php if ($admission['discharge_medications']) { ?>
							<h6>Discharge Medications</h6>
							<table class="table table-sm">
								<thead>
									<tr>
										<th>Medicine</th>
										<th>Dosage</th>
										<th>Duration</th>
										<th>Frequency / Timing</th>
										<th>Instructions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach (json_decode($admission['discharge_medications'], true) as $med) { ?>
									<tr>
										<td><?php echo htmlspecialchars($med['medicine']); ?>
										</td>
										<td><?php echo htmlspecialchars($med['dosage']); ?>
										</td>
										<td><?php echo htmlspecialchars($med['duration']); ?>
										</td>
										<td><?php echo htmlspecialchars($med['frequency'] ?? $med['timing'] ?? ''); ?>
										</td>
										<td><?php echo htmlspecialchars($med['instructions'] ?? $med['notes'] ?? ''); ?>
										</td>
									</tr>
									<?php } ?>
								</tbody>
							</table>
							<?php } ?>
						</div>
						<?php } ?>
					</div>
				</div>
			</div>

			<div class="col-md-4">
				<!-- Billing Summary -->
				<div class="card mb-3">
					<div class="card-header d-flex justify-content-between">
						<h5 class="mb-0">Billing Summary</h5>
						<?php
									                    $total_advance = 0;
foreach ($bills as $bill) {
    if ($bill['is_advance']) {
        $total_advance += $bill['final_amount'];
    }
}
if ($total_advance > 0) { ?>
						<span class="text-success">
							Advance Balance:
							₹<?php echo number_format($total_advance, 2); ?>
						</span>
						<?php } ?>
					</div>
					<div class="card-body">
						<?php if (empty($bills)) { ?>
						<p class="text-muted">No bills generated yet.</p>
						<?php } else { ?>
						<?php foreach ($bills as $bill) { ?>
						<div class="border-bottom mb-3 pb-3">
							<div class="d-flex justify-content-between align-items-start">
								<div>
									<h6>
										<?php if ($bill['is_advance']) { ?>
										<i class="fas fa-piggy-bank text-success"></i>
										<?php } ?>
										<?php echo $bill['bill_type']; ?>
										#<?php echo $bill['bill_id']; ?>
									</h6>
									<small class="text-muted">
										<?php echo date('d-m-Y', strtotime($bill['bill_date'])); ?>
									</small>
									<div class="mt-1">
										<span
											class="fw-bold">₹<?php echo number_format($bill['final_amount'], 2); ?></span>
									</div>
								</div>
								<div class="d-flex flex-column align-items-end">
									<span
										class="badge bg-<?php echo $bill['is_advance'] ? 'success' : ('PAID' === $bill['payment_status'] ? 'success' : 'warning'); ?>">
										<?php echo $bill['payment_status']; ?>
									</span>
									<div class="bill-actions mt-2">
										<?php if ($bill['is_advance']) { ?>
										<a href="view_advance.php?bill_id=<?php echo urlencode($bill['bill_id']); ?>"
											class="btn btn-outline-success btn-sm">
											<i class="fas fa-receipt"></i> View Advance
										</a>
										<?php } elseif ('Hospital Bill' === $bill['bill_type']) { ?>
										<a href="../billing/view_bill.php?bill_id=<?php echo urlencode($bill['bill_id']); ?>"
											class="btn btn-outline-primary btn-sm">
											<i class="fas fa-eye"></i> View Bill
										</a>
										<?php } else { ?>
										<a href="../pharmacy/view_pharmacy_bill.php?id=<?php echo urlencode($bill['transaction_id']); ?>"
											class="btn btn-outline-primary btn-sm">
											<i class="fas fa-eye"></i> View Pharmacy Bill
										</a>
										<?php } ?>
									</div>
								</div>
							</div>
						</div>
						<?php } ?>
						<?php } ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
		function goBack() {
			window.history.back();
		}
	</script>
</body>

</html>