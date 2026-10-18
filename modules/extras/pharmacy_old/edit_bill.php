<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    exit('Invalid transaction ID');
}

// Validate user has edit permissions
if (!checkPermission('bills', 'edit')) {
    exit('Access Denied');
}

$transaction_id = sanitizeInput($_GET['id']);
$conn = getDbConnection();

// Get bill details
$stmt = $conn->prepare('SELECT ps.*, p.name as patient_name, d.name as doctor_name, d.dept_id 
                        FROM pharmacy_sales ps 
                        LEFT JOIN patients p ON ps.patient_id = p.patient_id
                        LEFT JOIN doctors d ON ps.doctor_id = d.doctor_id
                        WHERE ps.transaction_id = ?');
$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

if (!$bill) {
    exit('Bill not found');
}

// Get medicine items
$stmt = $conn->prepare('SELECT pti.*, pb.batch_number, pi.name as medicine_name
                        FROM pharmacy_transaction_items pti
                        JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
                        JOIN pharmacy_items pi ON pb.item_id = pi.item_id
                        WHERE pti.transaction_id = ?');
$stmt->bind_param('s', $transaction_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle POST request for updates
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        $conn->begin_transaction();

        // Get updated values
        $updatedItems = json_decode($_POST['updated_items'], true);
        $newDiscount = floatval($_POST['discount']);
        $discountType = $_POST['discount_type'];

        // Calculate new totals
        $subtotal = 0;
        foreach ($updatedItems as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }

        $discountAmount = ('percentage' === $discountType)
            ? ($subtotal * $newDiscount / 100)
            : $newDiscount;

        $finalAmount = $subtotal - $discountAmount;

        // Validate final amount is not negative
        if ($finalAmount < 0) {
            throw new Exception('Final amount cannot be negative');
        }

        // Update pharmacy_sales
        $stmt = $conn->prepare('UPDATE pharmacy_sales 
                               SET subtotal = ?, discount_amount = ?, 
                                   discount_type = ?, final_amount = ?
                               WHERE transaction_id = ?');
        $stmt->bind_param(
            'ddsds',
            $subtotal,
            $discountAmount,
            $discountType,
            $finalAmount,
            $transaction_id
        );
        $stmt->execute();

        // Update items and check stock
        foreach ($updatedItems as $item) {
            // Get current stock
            $stmt = $conn->prepare('SELECT current_quantity, initial_quantity 
                                  FROM pharmacy_batches 
                                  WHERE batch_id = ?');
            $stmt->bind_param('i', $item['batch_id']);
            $stmt->execute();
            $batch = $stmt->get_result()->fetch_assoc();

            // Get original quantity
            $stmt = $conn->prepare('SELECT quantity FROM pharmacy_transaction_items 
                                  WHERE item_id = ? AND transaction_id = ?');
            $stmt->bind_param('is', $item['item_id'], $transaction_id);
            $stmt->execute();
            $original = $stmt->get_result()->fetch_assoc();

            // Calculate stock change
            $stockChange = $item['quantity'] - $original['quantity'];
            $newStock = $batch['current_quantity'] - $stockChange;

            if ($newStock < 0) {
                throw new Exception("Insufficient stock for item ID: {$item['item_id']}");
            }

            // Update transaction item
            $stmt = $conn->prepare('UPDATE pharmacy_transaction_items 
                                  SET quantity = ?, final_amount = ?
                                  WHERE item_id = ? AND transaction_id = ?');
            $itemTotal = $item['quantity'] * $item['unit_price'];
            $stmt->bind_param(
                'idis',
                $item['quantity'],
                $itemTotal,
                $item['item_id'],
                $transaction_id
            );
            $stmt->execute();

            // Update batch stock
            $stmt = $conn->prepare('UPDATE pharmacy_batches 
                                  SET current_quantity = ?
                                  WHERE batch_id = ?');
            $stmt->bind_param('ii', $newStock, $item['batch_id']);
            $stmt->execute();
        }

        // Add audit log
        $changes = [
            'subtotal' => $subtotal,
            'discount' => $discountAmount,
            'final_amount' => $finalAmount,
            'items' => $updatedItems,
        ];

        // $stmt = $conn->prepare("INSERT INTO pharmacy_bill_audit 
        //                       (transaction_id, action, modified_by, changes)
        //                       VALUES (?, 'UPDATE', ?, ?)");
        // $changesJson = json_encode($changes);
        // $stmt->bind_param('sis', $transaction_id, $_SESSION['user_id'], $changesJson);
        // $stmt->execute();

        $conn->commit();
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
	<title>Edit Pharmacy Bill</title>
	<link rel="stylesheet" href="../../assets/css/pharmacy-sale.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Edit Pharmacy Bill</h2>
		<div class="bill-info">
			<div class="info-row">
				<span>Bill ID:
					<?php echo htmlspecialchars($bill['bill_id']); ?></span>
				<span>Date:
					<?php echo date('d-M-Y', strtotime($bill['sale_date'])); ?></span>
			</div>
			<div class="info-row">
				<span>Patient:
					<?php echo htmlspecialchars($bill['patient_name']); ?></span>
				<span>Doctor:
					<?php echo htmlspecialchars($bill['doctor_name']); ?></span>
			</div>
		</div>

		<form id="editForm">
			<div class="medicine-list">
				<div class="medicine-header">
					<span>Medicine</span>
					<span>Batch</span>
					<span>Quantity</span>
					<span>Price</span>
					<span>Total</span>
				</div>
				<div id="medicinesList">
					<?php foreach ($items as $item) { ?>
					<div class="medicine-item"
						data-item-id="<?php echo $item['item_id']; ?>">
						<span><?php echo htmlspecialchars($item['medicine_name']); ?></span>
						<span><?php echo htmlspecialchars($item['batch_number']); ?></span>
						<span>
							<input type="number" class="quantity-input"
								value="<?php echo $item['quantity']; ?>"
								min="1"
								data-price="<?php echo $item['unit_price']; ?>"
								data-batch="<?php echo $item['batch_id']; ?>">
						</span>
						<span>₹<?php echo number_format($item['unit_price'], 2); ?></span>
						<span
							class="item-total">₹<?php echo number_format($item['final_amount'], 2); ?></span>
					</div>
					<?php } ?>
				</div>
			</div>

			<div class="billing-summary">
				<div class="billing-row">
					<div class="form-group">
						<label>Subtotal:</label>
						<span
							id="subtotal"><?php echo number_format($bill['subtotal'], 2); ?></span>
					</div>
					<div class="form-group">
						<label>Discount Type:</label>
						<select name="discount_type" id="discount_type">
							<option value="amount" <?php echo 'amount' === $bill['discount_type'] ? 'selected' : ''; ?>>Amount
							</option>
							<option value="percentage" <?php echo 'percentage' === $bill['discount_type'] ? 'selected' : ''; ?>>Percentage
							</option>
						</select>
					</div>
					<div class="form-group">
						<label>Discount:</label>
						<input type="number" name="discount" id="discount"
							value="<?php echo $bill['discount_amount']; ?>"
							step="0.01" min="0">
					</div>
				</div>
				<div class="total-row">
					<div class="form-group">
						<label>Final Amount:</label>
						<span
							id="finalAmount"><?php echo number_format($bill['final_amount'], 2); ?></span>
					</div>
				</div>
			</div>

			<div class="form-actions">
				<button type="submit" class="btn-submit">Save Changes</button>
				<!-- <a href="print_pharmacy_bill.php?id=<?php echo $transaction_id; ?>"
					class="btn-print" target="_blank">Print Bill</a> -->
			</div>
		</form>
	</div>

	<script>
		$(document).ready(function() {
			$('.quantity-input').on('change', function() {
				updateTotals();
			});

			$('#discount, #discount_type').on('change', function() {
				updateTotals();
			});

			function updateTotals() {
				let subtotal = 0;
				const items = [];

				$('.medicine-item').each(function() {
					const quantity = parseInt($(this).find('.quantity-input').val());
					const price = parseFloat($(this).find('.quantity-input').data('price'));
					const total = quantity * price;

					$(this).find('.item-total').text('₹' + total.toFixed(2));
					subtotal += total;

					items.push({
						item_id: $(this).data('item-id'),
						batch_id: $(this).find('.quantity-input').data('batch'),
						quantity: quantity,
						unit_price: price
					});
				});

				$('#subtotal').text(subtotal.toFixed(2));

				const discountType = $('#discount_type').val();
				const discountValue = parseFloat($('#discount').val()) || 0;

				const discount = (discountType === 'percentage') ?
					(subtotal * discountValue / 100) :
					discountValue;

				const finalAmount = Math.max(0, subtotal - discount);
				$('#finalAmount').text(finalAmount.toFixed(2));

				return items;
			}

			$('#editForm').on('submit', function(e) {
				e.preventDefault();

				const items = updateTotals();
				const data = {
					updated_items: JSON.stringify(items),
					discount: $('#discount').val(),
					discount_type: $('#discount_type').val()
				};

				$.post('edit_bill.php?id=<?php echo $transaction_id; ?>',
						data)
					.done(function(response) {
						const result = JSON.parse(response);
						if (result.status === 'success') {
							alert('Bill updated successfully');
							location.reload();
						} else {
							alert('Error: ' + result.message);
						}
					})
					.fail(function() {
						alert('Error updating bill');
					});
			});
		});
	</script>
</body>

</html>