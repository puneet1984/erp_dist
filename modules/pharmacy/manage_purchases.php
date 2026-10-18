<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('pharmacy', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$canPayPurchase = checkPermission('pharmacy', 'edit') || checkPermission('pharmacy', 'create');
$canDeletePurchase = checkPermission('pharmacy', 'delete');

// Handle DELETE request
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action']) && 'delete' === $_POST['action']) {
    header('Content-Type: application/json');

    if (!$canDeletePurchase) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
        exit;
    }

    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        $purchase_id = sanitizeInput($_POST['purchase_id']);
        $transaction_id = sanitizeInput($_POST['transaction_id']);

        // Get transaction items to know how much quantity to subtract from batches
        $stmt = $conn->prepare('SELECT batch_id, quantity FROM pharmacy_transaction_items 
                               WHERE transaction_id = ?');
        $stmt->bind_param('s', $transaction_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Update batch quantities by subtracting the purchased quantities
        foreach ($items as $item) {
            $stmt = $conn->prepare('UPDATE pharmacy_batches 
                                   SET initial_quantity = initial_quantity - ?,
                                       current_quantity = current_quantity - ?
                                   WHERE batch_id = ?');
            $stmt->bind_param('iii', $item['quantity'], $item['quantity'], $item['batch_id']);
            $stmt->execute();
        }

        // Delete transaction items for this purchase
        $stmt = $conn->prepare('DELETE FROM pharmacy_transaction_items WHERE transaction_id = ?');
        $stmt->bind_param('s', $transaction_id);
        $stmt->execute();

        // Finally delete the purchase record
        $stmt = $conn->prepare('DELETE FROM pharmacy_purchases WHERE purchase_id = ?');
        $stmt->bind_param('i', $purchase_id);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Purchase record deleted successfully']);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action']) && 'bulk_delete' === $_POST['action']) {
	header('Content-Type: application/json');

	if (!$canDeletePurchase) {
		http_response_code(403);
		echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
		exit;
	}

	$purchaseIds = $_POST['purchase_ids'] ?? [];
	if (!is_array($purchaseIds) || empty($purchaseIds)) {
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => 'Select at least one purchase record']);
		exit;
	}

	$conn = getDbConnection();
	$conn->begin_transaction();

	try {
		foreach ($purchaseIds as $purchaseId) {
			$purchaseId = (int) sanitizeInput($purchaseId);
			$stmt = $conn->prepare('SELECT transaction_id, payment_status FROM pharmacy_purchases WHERE purchase_id = ?');
			$stmt->bind_param('i', $purchaseId);
			$stmt->execute();
			$purchase = $stmt->get_result()->fetch_assoc();

			if (!$purchase || 'PENDING' !== $purchase['payment_status']) {
				throw new Exception('Only pending purchase records can be deleted');
			}

			$stmt = $conn->prepare('SELECT batch_id, quantity FROM pharmacy_transaction_items WHERE transaction_id = ?');
			$stmt->bind_param('s', $purchase['transaction_id']);
			$stmt->execute();
			$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

			foreach ($items as $item) {
				$stmt = $conn->prepare('UPDATE pharmacy_batches
									   SET initial_quantity = initial_quantity - ?,
										   current_quantity = current_quantity - ?
									   WHERE batch_id = ?');
				$stmt->bind_param('iii', $item['quantity'], $item['quantity'], $item['batch_id']);
				$stmt->execute();
			}

			$stmt = $conn->prepare('DELETE FROM pharmacy_transaction_items WHERE transaction_id = ?');
			$stmt->bind_param('s', $purchase['transaction_id']);
			$stmt->execute();

			$stmt = $conn->prepare('DELETE FROM pharmacy_purchases WHERE purchase_id = ?');
			$stmt->bind_param('i', $purchaseId);
			$stmt->execute();
		}

		$conn->commit();
		echo json_encode(['status' => 'success', 'message' => 'Selected purchase records deleted successfully']);
	} catch (Exception $e) {
		$conn->rollback();
		http_response_code(400);
		echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
	}
	exit;
}

// Add payment processing handler
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action']) && 'add_payment' === $_POST['action']) {
    header('Content-Type: application/json');

    if (!$canPayPurchase) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized action']);
        exit;
    }

    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        $purchase_id = sanitizeInput($_POST['purchase_id']);
        $amount = sanitizeNumeric($_POST['amount']);
        $payment_type = sanitizeInput($_POST['payment_type']);
        $payment_date = sanitizeInput($_POST['payment_date']);
        $invoice_date = sanitizeInput($_POST['invoice_date']);

        // Validate payment date is not before invoice date
        if (strtotime($payment_date) < strtotime($invoice_date)) {
            throw new Exception('Payment date cannot be earlier than invoice date');
        }

        $reference = sanitizeInput($_POST['reference_no']);
        $notes = sanitizeInput($_POST['notes']);

        // Get current payment details
        $stmt = $conn->prepare('SELECT final_amount, payment_details FROM pharmacy_purchases WHERE purchase_id = ?');
        $stmt->bind_param('i', $purchase_id);
        $stmt->execute();
        $purchase = $stmt->get_result()->fetch_object();

        // Get existing payments or initialize empty array
        $payment_details = !empty($purchase->payment_details) ? json_decode($purchase->payment_details, true) : [];

        // Add new payment to array with payment date
        $payment_details[] = [
            'date' => $payment_date,
            'amount' => $amount,
            'type' => $payment_type,
            'reference' => $reference,
            'notes' => $notes,
            'user_id' => $_SESSION['user_id'],
        ];

        // Calculate total paid
        $total_paid = array_sum(array_column($payment_details, 'amount'));

        // Determine payment status
        $payment_status = $total_paid >= $purchase->final_amount ? 'PAID' : 'PARTIALLY_PAID';

        // Update purchase record with new payment details and status
        $stmt = $conn->prepare('
            UPDATE pharmacy_purchases 
            SET payment_status = ?,
                payment_details = ?
            WHERE purchase_id = ?
        ');

        $payment_details_json = json_encode($payment_details);
        $stmt->bind_param(
            'ssi',
            $payment_status,
            $payment_details_json,
            $purchase_id
        );
        $stmt->execute();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fix action check
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['action'])) {
    if ('get_details' === $_POST['action']) {
        try {
            // Get purchase details with paid amount calculation
            $stmt = $conn->prepare('
                SELECT p.*, 
                       COALESCE(
                         CAST(
                           JSON_UNQUOTE(
                             JSON_EXTRACT(
                               payment_details,
                               "$[*].amount"
                             )
                           ) AS DECIMAL(10,2)
                         ), 
                         0
                       ) as amount_paid,
                       u.username as created_by_name 
                FROM pharmacy_purchases p 
                LEFT JOIN users u ON p.created_by = u.user_id 
                WHERE p.purchase_id = ?
            ');
            $stmt->bind_param('i', $_POST['purchase_id']);
            $stmt->execute();
            $purchase = $stmt->get_result()->fetch_assoc();

            echo json_encode($purchase);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
}

// Build search filters for listing
$where = [];
$params = [];
$types = '';

$search = sanitizeInput($_GET['search'] ?? '');
if ('' !== $search) {
    $where[] = '(p.invoice_number LIKE ? OR s.supplier_name LIKE ?)';
    $likeTerm = "%{$search}%";
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $types .= 'ss';
}

$itemName = sanitizeInput($_GET['item_name'] ?? '');
if ('' !== $itemName) {
    $where[] = 'EXISTS (
		SELECT 1
		FROM pharmacy_transaction_items pti
		JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
		JOIN pharmacy_items pi ON pb.item_id = pi.item_id
		WHERE pti.transaction_id = p.transaction_id
		  AND (pi.name LIKE ? OR pi.active_ingredient LIKE ?)
	)';
    $itemLikeTerm = "%{$itemName}%";
    $params[] = $itemLikeTerm;
    $params[] = $itemLikeTerm;
    $types .= 'ss';
}

$fromDate = sanitizeInput($_GET['from_date'] ?? '');
if ('' !== $fromDate) {
    $where[] = 'p.purchase_date >= ?';
    $params[] = $fromDate;
    $types .= 's';
}

$toDate = sanitizeInput($_GET['to_date'] ?? '');
if ('' !== $toDate) {
    $where[] = 'p.purchase_date <= ?';
    $params[] = $toDate;
    $types .= 's';
}

// Fix SQL query with proper GROUP BY
$sql = 'SELECT p.*, u.username as created_by_name,
        s.supplier_name as supplier_name,
        COALESCE(
            SUM(
                JSON_EXTRACT(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(p.payment_details, "$[*].amount")
                    ),
                    "$"
                )
            ),
            0
        ) as paid_amount 
        FROM pharmacy_purchases p 
        LEFT JOIN users u ON p.created_by = u.user_id
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id';

if (!empty($where)) {
    $sql .= ' WHERE '.implode(' AND ', $where);
}

$sql .= ' GROUP BY p.purchase_id, p.transaction_id, p.invoice_number, 
          p.supplier_id, p.invoice_date, p.purchase_date,
          p.subtotal, p.tax_amount, p.discount_amount, 
          p.final_amount, p.payment_status, p.payment_details,
          p.notes, p.created_by, p.created_at,
          u.username, s.supplier_name
          ORDER BY p.purchase_date DESC';

$conn = getDbConnection();
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// Update payment types query to use hardcoded values instead of database
$payment_types = [
    ['id' => 'CASH', 'name' => 'Cash'],
    ['id' => 'CHEQUE', 'name' => 'Cheque'],
    ['id' => 'UPI', 'name' => 'UPI'],
];

?>

<!DOCTYPE html>
<html>

<head>
	<title>Manage Purchases</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/pharmacy-purchase.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<style>
		.search-box {
			margin-bottom: 20px;
			padding: 15px;
			background: #f8f9fa;
			border-radius: 5px;
		}

		.purchase-table {
			margin-top: 20px;
		}

		.action-buttons {
			white-space: nowrap;
		}

		/* Add custom select styling */
		.custom-select {
			background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3E%3C/svg%3E");
			background-repeat: no-repeat;
			background-position: right 0.75rem center;
			background-size: 16px 12px;
			-webkit-appearance: none;
			-moz-appearance: none;
			appearance: none;
			padding-right: 2.25rem;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Manage Purchase Entries</h2>

		<div class="search-box">
			<form method="GET" class="row g-3">
				<div class="col-md-2">
					<input type="search" name="search" class="form-control" placeholder="Search invoice/supplier"
						value="<?php echo htmlspecialchars($search); ?>">
				</div>
				<div class="col-md-3">
					<input type="search" name="item_name" class="form-control"
						placeholder="Search item/active ingredient"
						value="<?php echo htmlspecialchars($itemName); ?>">
				</div>
				<div class="col-md-2">
					<input type="date" name="from_date" class="form-control" placeholder="From Date"
						value="<?php echo htmlspecialchars($fromDate); ?>">
				</div>
				<div class="col-md-2">
					<input type="date" name="to_date" class="form-control" placeholder="To Date"
						value="<?php echo htmlspecialchars($toDate); ?>">
				</div>
				<div class="col-md-3">
					<button type="submit" class="btn btn-primary">Search</button>
					<a href="manage_purchases.php" class="btn btn-secondary">Reset</a>
				</div>
			</form>
		</div>

		<div class="purchase-table">
			<?php if ($canDeletePurchase) { ?>
			<div class="mb-2">
				<button type="button" class="btn btn-danger" id="bulk-delete-btn" disabled>Delete Selected</button>
			</div>
			<?php } ?>
			<table class="table table-striped">
				<thead>
					<tr>
						<?php if ($canDeletePurchase) { ?><th><input type="checkbox" id="select-all-purchases" aria-label="Select all pending purchases"></th><?php } ?>
						<th>Date</th>
						<th>Invoice #</th>
						<th>Supplier</th>
						<th>Amount</th>
						<th>Status</th>
						<th>Created By</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($purchases as $purchase) { ?>
					<tr>
						<?php if ($canDeletePurchase) { ?>
						<td>
							<?php if ('PENDING' === $purchase['payment_status']) { ?>
							<input type="checkbox" class="purchase-select" value="<?php echo $purchase['purchase_id']; ?>" aria-label="Select purchase <?php echo htmlspecialchars($purchase['invoice_number']); ?>">
							<?php } ?>
						</td>
						<?php } ?>
						<td><?php echo htmlspecialchars(date('d-m-Y', strtotime($purchase['purchase_date']))); ?>
						</td>
						<td><?php echo htmlspecialchars($purchase['invoice_number']); ?>
						</td>
						<td><?php echo htmlspecialchars($purchase['supplier_name']); ?>
						</td>
						<td>₹<?php echo number_format($purchase['final_amount'], 2); ?>
							<?php if ($purchase['paid_amount'] > 0) { ?>
							<br><small>(Paid:
								₹<?php echo number_format($purchase['paid_amount'], 2); ?>)</small>
							<?php } ?>
						</td>
						<td><?php echo htmlspecialchars($purchase['payment_status']); ?>
						</td>
						<td><?php echo htmlspecialchars($purchase['created_by_name']); ?>
						</td>
						<td class="action-buttons">
							<button class="btn btn-sm btn-info view-btn"
								data-id="<?php echo $purchase['purchase_id']; ?>">View</button>
							<?php if ($canPayPurchase && 'PAID' !== $purchase['payment_status']) { ?>
							<button class="btn btn-sm btn-success pay-btn"
								data-id="<?php echo $purchase['purchase_id']; ?>"
								data-amount="<?php echo $purchase['final_amount']; ?>"
								data-paid="<?php echo $purchase['paid_amount'] ?? 0; ?>"
								data-invoice-date="<?php echo $purchase['invoice_date']; ?>">
								Pay
							</button>
							<?php } ?>
							<?php if ($canDeletePurchase && 'PENDING' === $purchase['payment_status']) { ?>
							<button class="btn btn-sm btn-danger delete-btn"
								data-id="<?php echo $purchase['purchase_id']; ?>"
								data-tid="<?php echo $purchase['transaction_id']; ?>">Delete</button>
							<?php } ?>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
		$(document).ready(function() {
			let lastSelectedPurchase = null;

			function updateBulkDeleteButton() {
				$('#bulk-delete-btn').prop('disabled', $('.purchase-select:checked').length === 0);
			}

			$('#select-all-purchases').on('change', function() {
				$('.purchase-select').prop('checked', this.checked);
				updateBulkDeleteButton();
			});

			$(document).on('click', '.purchase-select', function(event) {
				if (event.shiftKey && lastSelectedPurchase) {
					const checkboxes = $('.purchase-select');
					const start = checkboxes.index(lastSelectedPurchase);
					const end = checkboxes.index(this);
					const isChecked = $(this).prop('checked');

					checkboxes.slice(Math.min(start, end), Math.max(start, end) + 1).prop('checked', isChecked);
				}

				lastSelectedPurchase = this;
			});

			$(document).on('change', '.purchase-select', function() {
				const total = $('.purchase-select').length;
				const selected = $('.purchase-select:checked').length;
				$('#select-all-purchases').prop('checked', total > 0 && total === selected);
				updateBulkDeleteButton();
			});

			$('#bulk-delete-btn').click(function() {
				const purchaseIds = $('.purchase-select:checked').map(function() {
					return $(this).val();
				}).get();

				if (!purchaseIds.length || !confirm('Are you sure you want to delete the selected purchase records?')) {
					return;
				}

				$.ajax({
					url: 'manage_purchases.php',
					method: 'POST',
					data: { action: 'bulk_delete', purchase_ids: purchaseIds },
					success: function(response) {
						if (response.status === 'success') {
							alert(response.message);
							location.reload();
						} else {
							alert('Error: ' + response.message);
						}
					},
					error: function() {
						alert('Error processing request');
					}
				});
			});

			$('.delete-btn').click(function() {
				if (!confirm('Are you sure you want to delete this purchase record?')) {
					return;
				}

				const purchaseId = $(this).data('id');
				const transactionId = $(this).data('tid');

				$.ajax({
					url: 'manage_purchases.php',
					method: 'POST',
					data: {
						action: 'delete',
						purchase_id: purchaseId,
						transaction_id: transactionId
					},
					success: function(response) {
						if (response.status === 'success') {
							alert(response.message);
							location.reload();
						} else {
							alert('Error: ' + response.message);
						}
					},
					error: function() {
						alert('Error processing request');
					}
				});
			});

			$('.view-btn').click(function() {
				const purchaseId = $(this).data('id');
				window.location.href = 'view_purchase_bill.php?id=' + purchaseId;
			});

			const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));

			$('.pay-btn').click(function() {
				const purchaseId = $(this).data('id');
				const totalAmount = parseFloat($(this).data('amount'));
				const amountPaid = parseFloat($(this).data('paid') || 0);
				const balanceAmount = totalAmount - amountPaid;
				const invoiceDate = $(this).data('invoice-date');

				$('#payment_purchase_id').val(purchaseId);
				$('#invoice_date').val(invoiceDate);
				$('#total_amount').val(totalAmount.toFixed(2));
				$('#amount_paid').val(amountPaid.toFixed(2));
				$('#balance_amount').val(balanceAmount.toFixed(2));

				// Set min date for payment date input
				$('input[name="payment_date"]').attr('min', invoiceDate);

				// Set max payment amount to balance due
				$('#payment_amount').attr('max', balanceAmount);
				$('#payment_amount').val(balanceAmount.toFixed(2));

				paymentModal.show();
			});

			$('#paymentForm').on('submit', function(e) {
				e.preventDefault();
				const formData = new FormData(this);
				formData.append('action', 'add_payment');

				$.ajax({
					url: 'manage_purchases.php',
					method: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						if (response.status === 'success') {
							alert(response.message);
							location.reload();
						} else {
							alert('Error: ' + response.message);
						}
					},
					error: function() {
						alert('Error processing payment');
					}
				});
			});
		});
	</script>

	<!-- Add Payment Modal -->
	<div class="modal fade" id="paymentModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Add Payment</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<form id="paymentForm">
					<div class="modal-body">
						<input type="hidden" name="purchase_id" id="payment_purchase_id">
						<input type="hidden" name="invoice_date" id="invoice_date">
						<div class="mb-3">
							<label>Payment Date:</label>
							<input type="date" class="form-control" name="payment_date"
								value="<?php echo date('Y-m-d'); ?>"
								required>
						</div>
						<div class="mb-3">
							<label>Total Amount Due:</label>
							<input type="text" class="form-control" id="total_amount" readonly>
						</div>
						<div class="mb-3">
							<label>Amount Already Paid:</label>
							<input type="text" class="form-control" id="amount_paid" readonly>
						</div>
						<div class="mb-3">
							<label>Balance Due:</label>
							<input type="text" class="form-control" id="balance_amount" readonly>
						</div>
						<div class="mb-3">
							<label>Payment Amount:</label>
							<input type="number" class="form-control" name="amount" required step="0.01" max=""
								id="payment_amount">
						</div>
						<div class="mb-3">
							<label>Payment Type:</label>
							<select class="form-control custom-select" name="payment_type" required>
								<?php foreach ($payment_types as $type) { ?>
								<option
									value="<?php echo $type['id']; ?>">
									<?php echo htmlspecialchars($type['name']); ?>
								</option>
								<?php } ?>
							</select>
						</div>
						<div class="mb-3">
							<label>Reference No:</label>
							<input type="text" class="form-control" name="reference_no">
						</div>
						<div class="mb-3">
							<label>Notes:</label>
							<textarea class="form-control" name="notes"></textarea>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn btn-primary">Save Payment</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</body>

</html>