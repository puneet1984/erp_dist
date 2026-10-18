<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Add this function at the start of the file
function getLastDayOfMonth($date)
{
    if (!$date || !strtotime($date)) {
        throw new Exception('Invalid date format');
    }

    return date('Y-m-d', strtotime(date('Y-m-t', strtotime($date))));
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    header('Content-Type: application/json');
    $conn = getDbConnection();
    $conn->begin_transaction();

    try {
        // Decode medicines array from POST data
        $medicines = json_decode($_POST['medicines'] ?? '[]', true);
        if (empty($medicines)) {
            throw new Exception('No medicines selected');
        }

        $distributor = sanitizeInput($_POST['distributor'] ?? '');
        $purchase_date = sanitizeInput($_POST['purchase_date'] ?? '');
        $invoice_number = sanitizeInput($_POST['bill_no'] ?? '');

        if (!$distributor || !$purchase_date || !$invoice_number) {
            throw new Exception('Missing required fields');
        }

        // Generate transaction ID first
        $transaction_id = generateTransactionId('PUR');

        // Calculate total amounts for the entire purchase
        $purchase_subtotal = 0;
        $purchase_tax_total = 0;
        $purchase_discount = isset($_POST['discount']) ? sanitizeNumeric($_POST['discount']) : 0;

        foreach ($medicines as $medicine) {
            // Sanitize medicine data
            $medicine_name = sanitizeInput($medicine['name']);
            $manufacturer = sanitizeInput($medicine['manufacturer']);
            $active_ingredient = sanitizeInput($medicine['active_ingredient']);
            $batch_number = makeUpperCaseMixed(sanitizeInput($medicine['batch_number']));
            $quantity = sanitizeNumeric($medicine['quantity']);
            $purchase_rate = sanitizeNumeric($medicine['purchase_rate']);
            $retail_price = sanitizeNumeric($medicine['retail_price']);
            $tax_percentage = sanitizeNumeric($medicine['tax_percentage']);

            // Validate and format expiry date
            $expiry_date = sanitizeInput($medicine['expiry_date']);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
                throw new Exception('Invalid expiry date format. Required: YYYY-MM-DD');
            }

            // Convert to last day of month with proper format
            $expiry_date = getLastDayOfMonth($expiry_date);

            // Check if we have an item_id from the form
            $item_id = sanitizeInteger($medicine['item_id']);

            // For new medicines, item_id should be 0 or null, not a string
            if (!is_numeric($medicine['item_id']) || '' === $medicine['item_id']) {
                // This is a new medicine - create new item
                $stmt = $conn->prepare('INSERT INTO pharmacy_items 
                    (name, active_ingredient, manufacturer) 
                    VALUES (?, ?, ?)');
                $stmt->bind_param(
                    'sss',
                    $medicine_name,
                    $active_ingredient,
                    $manufacturer
                );
                $stmt->execute();
                $item_id = $conn->insert_id;
                if (!$item_id) {
                    throw new Exception('Failed to create new medicine item');
                }
            } else {
                // Verify existing item_id
                $verify_stmt = $conn->prepare('SELECT item_id FROM pharmacy_items WHERE item_id = ?');
                $verify_stmt->bind_param('i', $item_id);
                $verify_stmt->execute();
                $result = $verify_stmt->get_result();
                if (!$result->fetch_assoc()) {
                    throw new Exception('Invalid medicine item ID');
                }
                $verify_stmt->close();
            }

            // Check if batch already exists for this item
            $stmt = $conn->prepare('SELECT batch_id, current_quantity, initial_quantity FROM pharmacy_batches 
                WHERE item_id = ? AND batch_number = ?');
            $stmt->bind_param('is', $item_id, $batch_number);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing_batch = $result->fetch_assoc();

            if ($existing_batch) {
                // Update existing batch quantities
                $stmt = $conn->prepare('UPDATE pharmacy_batches 
                    SET initial_quantity = initial_quantity + ?,
                        current_quantity = current_quantity + ?,
                        purchase_rate = ?, 
                        retail_price = ?,  /* Store as per unit price */
						supplier_id = ? /* Update supplier_id */
                    WHERE batch_id = ?');
                $stmt->bind_param(
                    'iiddsi',
                    $quantity,
                    $quantity,
                    $purchase_rate,
                    $retail_price,
                    $distributor,
                    $existing_batch['batch_id']
                );
                $stmt->execute();
                $batch_id = $existing_batch['batch_id'];
            } else {
                // Create new batch - keep retail_price as per unit
                $stmt = $conn->prepare('INSERT INTO pharmacy_batches 
                    (item_id, batch_number, initial_quantity, current_quantity,
                    purchase_rate, retail_price, expiry_date, supplier_id, invoice_number)
                    VALUES (?, ?, ?, ?, ?, ?, DATE(?), ?, ?)');

                $stmt->bind_param(
                    'isiiidsss',
                    $item_id,
                    $batch_number,
                    $quantity,
                    $quantity,
                    $purchase_rate,
                    $retail_price,  // Store as per unit price
                    $expiry_date,
                    $distributor,
                    $invoice_number
                );
                $stmt->execute();
                $batch_id = $conn->insert_id;
            }

            // Calculate amounts for this item
            $subtotal = $quantity * $purchase_rate;
            $tax_amount = ($subtotal * $tax_percentage) / 100;
            $final_amount = $subtotal + $tax_amount;

            // Add transaction item with the correct batch_id
            $stmt = $conn->prepare('INSERT INTO pharmacy_transaction_items 
                (transaction_id, batch_id, quantity, unit_price,
                tax_percentage, tax_amount, final_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)');

            $stmt->bind_param(
                'siidddd',  // Changed from 'siiddd' to 'siidddd' to match 7 parameters
                $transaction_id,
                $batch_id,
                $quantity,
                $purchase_rate,
                $tax_percentage,
                $tax_amount,
                $final_amount
            );
            $stmt->execute();

            // Add to purchase totals
            $purchase_subtotal += $subtotal;
            $purchase_tax_total += $tax_amount;
        }

        // Calculate final amount
        $purchase_final_amount = $purchase_subtotal + $purchase_tax_total - $purchase_discount;

        // Insert final purchase record with all required fields
        $stmt = $conn->prepare('INSERT INTO pharmacy_purchases 
            (transaction_id, invoice_number, supplier_id, invoice_date,
             purchase_date, subtotal, tax_amount, discount_amount, 
             final_amount, payment_status, payment_details, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $payment_status = 'PENDING';
        $payment_details = '{}'; // Empty JSON object
        $notes = '';

        $stmt->bind_param(
            'sssssddddsssi',
            $transaction_id,
            $invoice_number,
            $distributor,
            $purchase_date, // Using purchase_date as invoice_date
            $purchase_date,
            $purchase_subtotal,
            $purchase_tax_total,
            $purchase_discount,
            $purchase_final_amount,
            $payment_status,
            $payment_details,
            $notes,
            $_SESSION['user_id']
        );
        $stmt->execute();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Purchase recorded successfully']);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Purchase Transaction</title>
	<!-- Bootstrap CSS -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/pharmacy-purchase.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"
		integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
	<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
	<!-- Bootstrap Bundle with Popper -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script src="../../assets/js/tax_calculator.js"></script>
	<script src="../../assets/js/generate_bill.js"></script>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Medicine Purchase Entry</h2>
		<h4>If item not found, add it from inventory menu first</h4>
		<form id="purchaseForm" method="POST">
			<!-- Document Info Row -->
			<div class="form-row document-row">
				<div class="form-group">
					<label>Purchase Date:</label>
					<input type="date" name="purchase_date" id="purchase_date" required
						value="<?php echo date('Y-m-d'); ?>">
				</div>

				<div class="form-group">
					<label>Distributor:</label>
					<select class="distributor-select" name="distributor" style="width: 100%" required></select>
				</div>

				<div class="form-group">
					<label>Bill Number:</label>
					<input type="text" name="bill_no" id="bill_no" required>
				</div>
				<div class="form-group" style="justify-items: end;">
					<label>&nbsp;</label>
					<a href="add_items.php" class="btn-primary" style="text-decoration: none; white-space: nowrap;">Add
						New Item</a>
				</div>
			</div>

			<!-- Medicine Entry Row -->
			<div class="form-row medicine-entry-row">
				<div class="form-group medicine-select-group">
					<label>Medicine:</label>
					<div style="display: flex; gap: 10px; align-items: center;">
						<select class="medicine-select" style="width: 100%"></select>
					</div>
				</div>
				<div class="form-group">
					<label>Manufacturer:</label>
					<input type="text" name="manufacturer" id="manufacturer">
				</div>
				<div class="form-group">
					<label>Active Ingredient:</label>
					<input type="text" name="active_ingredient" id="active_ingredient">
				</div>
				<div class="form-group">
					<label>Batch:</label>
					<input type="text" id="batch_number">
				</div>
				<div class="form-group">
					<label>Quantity:</label>
					<input type="number" id="quantity" min="1">
				</div>
				<div class="form-group">
					<label>Purchase Rate:</label>
					<input type="number" id="purchase_rate" step="0.01">
				</div>
				<div class="form-group">
					<label>Retail Price:</label>
					<input type="number" id="retail_price" step="0.01">
				</div>
				<div class="form-group">
					<label>Expiry:</label>
					<input type="date" id="expiry_date">
				</div>
				<div class="form-group add-btn-group">
					<button type="button" class="btn-add" onclick="addMedicineToPurchase()">Add Medicine</button>
				</div>
			</div>
			<!-- Medicine Info Row -->
			<div class="form-row medicine-info-row">
				<div class="form-group">
					<label>Tax Percentage:</label>
					<div class="input-group" style="width: 20%;">
						<input type="number" name="tax_percentage" id="tax_percentage" value="12" min="0" max="100"
							step="0.01" onchange="calculateTax(this.value)">
						<span class="input-symbol">%</span>
					</div>
				</div>
			</div>
			<!-- Selected Medicines List -->
			<div id="medicines">
				<h3>Selected Medicines</h3>
				<div class="medicine-list"></div>
			</div>
			<div class="medicine-header">
				<span>Medicine</span>
				<span>Batch</span>
				<span>Quantity</span>
				<span>Purchase Rate</span>
				<span>Retail Price</span>
				<span>Expiry</span>
				<span>Total</span>
				<span>Action</span>
			</div>
			<div id="medicinesList"></div>
	</div>
	</div>

	<!-- Billing Summary -->
	<div class="billing-summary">
		<div class="billing-row">
			<div class="billing-item">
				<label>Subtotal:</label>
				<span>₹<span id="subtotal">0.00</span></span>
			</div>
			<div class="billing-item">
				<label>Tax Amount:</label>
				<span>₹<span id="tax_amount">0.00</span></span>
			</div>
			<div class="billing-item">
				<label>Discount Amount:</label>
				<div class="input-group">
					<input type="number" name="discount" value="0" min="0" step="0.01"
						onchange="calculateFromAmount(this.value)">
					<span class="input-symbol">₹</span>
				</div>
			</div>
			<div class="billing-item">
				<label>Discount %:</label>
				<div class="input-group">
					<input type="number" name="discount_percent" value="0" min="0" max="100" step="0.01"
						onchange="calculateFromPercent(this.value)">
					<span class="input-symbol">%</span>
				</div>
			</div>
		</div>
	</div>

	<div class="final-amount-row">
		<div>
			<label>Total Amount:</label>
			<span class="total-amount">₹<span id="totalAmount">0.00</span></span>
		</div>
		<button type="submit" class="btn-submit">Record Purchase</button>
	</div>


	</form>
	</div>

	<script>
		let selectedMedicines = [];

		function showTooltip(element, message) {
			// Remove any existing tooltips and error states
			$('.tooltip').remove();
			$('.input-error').removeClass('input-error');

			// Create and position the tooltip
			const tooltip = $('<div class="tooltip">' + message + '</div>');
			$('body').append(tooltip);

			// Add error state to input
			$(element).addClass('input-error');

			const pos = $(element).offset();
			tooltip.css({
				top: pos.top + $(element).outerHeight() + 5,
				left: pos.left
			}).fadeIn();

			// Focus the element
			if ($(element).hasClass('medicine-select')) {
				$(element).select2('open');
			} else {
				$(element).focus();
			}

			// Remove tooltip and error state after 3 seconds
			setTimeout(() => {
				tooltip.fadeOut(() => tooltip.remove());
				$(element).removeClass('input-error');
			}, 3000);

			// Remove error state on input change
			$(element).one('input change', function() {
				$(this).removeClass('input-error');
				$('.tooltip').remove();
			});
		}

		function addMedicineToPurchase() {
			const medicine = $('.medicine-select').select2('data')[0];
			if (!medicine) {
				showTooltip($('.medicine-select'), 'Please select a medicine');
				$('.medicine-select').select2('open');
				return;
			}

			// Create validation map of field ID and its value
			const requiredFields = [{
					id: 'batch_number',
					value: $('#batch_number').val(),
					message: 'Enter batch number'
				},
				{
					id: 'quantity',
					value: $('#quantity').val(),
					message: 'Enter quantity'
				},
				{
					id: 'purchase_rate',
					value: $('#purchase_rate').val(),
					message: 'Enter purchase rate'
				},
				{
					id: 'retail_price',
					value: $('#retail_price').val(),
					message: 'Enter retail price'
				},
				{
					id: 'expiry_date',
					value: $('#expiry_date').val(),
					message: 'Select expiry date'
				},
				{
					id: 'purchase_date',
					value: $('#purchase_date').val(),
					message: 'Select purchase date'
				},
				{
					id: 'bill_no',
					value: $('#bill_no').val(),
					message: 'Enter bill number'
				}
			];

			// Find first empty required field
			const emptyField = requiredFields.find(field => !field.value);
			if (emptyField) {
				showTooltip($('#' + emptyField.id), emptyField.message);
				return;
			}

			const medicineData = {
				item_id: medicine.id,
				name: medicine.text,
				manufacturer: $('#manufacturer').val(),
				active_ingredient: $('#active_ingredient').val(),
				batch_number: $('#batch_number').val(),
				quantity: parseInt($('#quantity').val()),
				purchase_rate: parseFloat($('#purchase_rate').val()),
				retail_price: parseFloat($('#retail_price').val()), // This is already per unit
				expiry_date: $('#expiry_date').val(),
				tax_percentage: parseFloat($('#tax_percentage').val()) || 0,
				total: parseFloat($('#quantity').val()) * parseFloat($('#purchase_rate').val())
			};

			// Calculate tax amount
			medicineData.tax_amount = (medicineData.total * medicineData.tax_percentage) / 100;

			// Validate required fields
			if (!medicineData.batch_number || !medicineData.quantity ||
				!medicineData.purchase_rate || !medicineData.retail_price ||
				!medicineData.expiry_date || !$('#purchase_date').val() || !$('#bill_no').val()) {
				alert('Please fill all required fields');
				return;
			}

			selectedMedicines.push(medicineData);
			updatePurchaseTable();
			resetMedicineInputs();
			calculateTotals();
		}

		function updatePurchaseTable() {
			const list = selectedMedicines.map((medicine, index) => `
                <div class="medicine-row">
                    <span>${medicine.name}</span>
                    <span>${medicine.batch_number}</span>
                    <span>${medicine.quantity}</span>
                    <span>${medicine.purchase_rate.toFixed(2)}</span>
                    <span>${medicine.retail_price.toFixed(2)}</span>
                    <span>${medicine.expiry_date}</span>
                    <span>${medicine.total.toFixed(2)}</span>
                    <span><button type="button" onclick="removeMedicine(${index})">Remove</button></span>
                </div>
            `).join('');
			$('#medicinesList').html(list);
		}

		function resetMedicineInputs() {
			$('.medicine-select').val(null).trigger('change');
			$('#manufacturer').val('');
			$('#active_ingredient').val('');
			$('#batch_number').val('');
			$('#quantity').val('');
			$('#purchase_rate').val('');
			$('#retail_price').val('');
			$('#expiry_date').val('');
		}

		function removeMedicine(index) {
			selectedMedicines.splice(index, 1);
			updatePurchaseTable();
			calculateTotals();
		}

		function calculateTotals() {
			// Calculate subtotal first
			const subtotal = selectedMedicines.reduce((sum, medicine) => sum + medicine.total, 0);
			$('#subtotal').text(subtotal.toFixed(2));

			// Recalculate everything in correct order
			updateAmounts();
		}

		function updateAmounts() {
			// Step 1: Get subtotal
			const subtotal = parseFloat($('#subtotal').text()) || 0;

			// Step 2: Calculate discount
			const discountAmount = parseFloat($('input[name="discount"]').val()) || 0;
			const amountAfterDiscount = Math.max(0, subtotal - discountAmount);
			$('#discount_applied').text(discountAmount.toFixed(2));

			// Step 3: Calculate tax on discounted amount
			const taxPercentage = parseFloat($('#tax_percentage').val()) || 0;
			const taxAmount = (amountAfterDiscount * (taxPercentage / 100));
			$('#tax_amount').text(taxAmount.toFixed(2));

			// Step 4: Calculate final total
			const finalTotal = amountAfterDiscount + taxAmount;
			$('#totalAmount').text(finalTotal.toFixed(2));

			console.log('Calculation Details:', {
				subtotal,
				discountAmount,
				amountAfterDiscount,
				taxAmount,
				finalTotal
			});
		}

		function calculateTax(percentage) {
			if (!percentage) percentage = 0;
			const subtotal = parseFloat($('#subtotal').text()) || 0;
			const taxAmount = (subtotal * (percentage / 100));
			$('#tax_amount').text(taxAmount.toFixed(2));
			$('#tax_rate').text(percentage);
			updateAmounts();
		}

		function calculateFromAmount(amount) {
			const subtotal = parseFloat($('#subtotal').text()) || 0;
			if (subtotal === 0) return;

			const discount = parseFloat(amount) || 0;
			if (discount > subtotal) {
				alert('Discount cannot be greater than subtotal');
				$('input[name="discount"]').val('0');
				$('input[name="discount_percent"]').val('0');
			} else {
				const percentage = ((discount / subtotal) * 100);
				$('input[name="discount_percent"]').val(percentage.toFixed(2));
			}
			updateAmounts();
		}

		function calculateFromPercent(percent) {
			const subtotal = parseFloat($('#subtotal').text()) || 0;
			if (subtotal === 0) return;

			const percentage = parseFloat(percent) || 0;
			if (percentage > 100) {
				alert('Discount percentage cannot exceed 100%');
				$('input[name="discount_percent"]').val('0');
				$('input[name="discount"]').val('0');
			} else {
				const discount = subtotal * (percentage / 100);
				$('input[name="discount"]').val(discount.toFixed(2));
			}
			updateAmounts();
		}

		function focusNextEmptyField() {
			const requiredFields = [
				'manufacturer',
				'active_ingredient',
				'batch_number',
				'quantity',
				'purchase_rate',
				'retail_price',
				'expiry_date'
			];

			// Find first empty field and focus on it
			const emptyField = requiredFields.find(id => !$('#' + id).val());
			if (emptyField) {
				$('#' + emptyField).focus();
			}
		}

		$(document).ready(function() {
			// Initialize Select2 for medicine
			$('.medicine-select').select2({
				ajax: {
					url: '../../includes/helpers/medicine_helper.php',
					dataType: 'json',
					delay: 250,
					data: function(params) {
						return {
							q: params.term,
							type: 'purchase' // Specify purchase type
						};
					},
					processResults: function(data) {
						if (!data || !data.results) {
							return {
								results: []
							};
						}
						return {
							results: data.results.map(item => ({
								id: item.id ? parseInt(item.id) : 0, // Ensure numeric ID or 0
								text: item.text || item.name,
								active_ingredient: item.active_ingredient,
								manufacturer: item.manufacturer,
								dosage_form: item.dosage_form,
								is_new: !item.id // Flag to indicate new item
							}))
						};
					},
					cache: true
				},
				minimumInputLength: 3,
				placeholder: 'Search medicine...'
			}).on('select2:opening', function() {
				// Use setTimeout to ensure DOM is ready after dropdown opens
				setTimeout(() => {
					const searchField = document.querySelector('.select2-search__field');
					if (searchField) {
						searchField.focus();
					}
				}, 250);
			}).on('select2:select', function(e) {
				const data = e.params.data;
				// Auto-fill fields from selected medicine data
				$('#manufacturer').val(data.manufacturer);
				$('#active_ingredient').val(data.active_ingredient);
				$('#dosage_form').val(data.dosage_form);

				// Focus on next empty field after auto-fill
				setTimeout(focusNextEmptyField, 100);
			});

			// Auto-calculate totals when purchase rate or quantity changes
			$('input[name="purchase_rate"], input[name="quantity"]').on('input', calculatePurchaseTotal);

			// Form submission
			$('#purchaseForm').on('submit', function(e) {
				e.preventDefault();
				const formData = new FormData(this);
				formData.append('medicines', JSON.stringify(selectedMedicines));

				$.ajax({
					url: 'purchase.php',
					method: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					dataType: 'json',
					success: function(response) {
						if (response.status === 'success') {
							alert(response.message);
							location.reload();
						} else {
							alert('Error: ' + response.message);
						}
					},
					error: function(xhr) {
						let message = 'An error occurred';
						try {
							const response = JSON.parse(xhr.responseText);
							message = response.message || message;
						} catch (e) {
							console.error('Failed to parse error response:', xhr.responseText);
						}
						alert('Error: ' + message);
					}
				});
			});


			// Initialize Select2 for distributor
			$('.distributor-select').select2({
				ajax: {
					url: '../../includes/helpers/distributor_helper.php',
					dataType: 'json',
					delay: 250,
					data: function(params) {
						return {
							q: params.term
						};
					},
					processResults: function(data) {
						return data;
					},
					cache: true
				},
				minimumInputLength: 3,
				placeholder: 'Search supplier...',
				tags: true,
				createTag: function(params) {
					const term = $.trim(params.term);
					if (term === '') {
						return null;
					}
					return {
						id: term,
						text: term,
						newTag: true
					};
				}
			}).on('select2:select', function(e) {
				const data = e.params.data;
				if (data.newTag) {
					// Post new supplier to server
					$.ajax({
						url: '../../includes/helpers/distributor_helper.php',
						method: 'POST',
						contentType: 'application/json',
						data: JSON.stringify({
							supplier_name: data.text
						}),
						success: function(response) {
							if (response.success) {
								// Update the option with new ID from server
								const option = new Option(data.text, response.id, true, true);
								$('.distributor-select').append(option).trigger('change');
							}
						},
						error: function() {
							alert('Failed to add new supplier');
						}
					});
				}
			});
		});

		function calculatePurchaseTotal() {
			const quantity = parseFloat($('input[name="quantity"]').val()) || 0;
			const rate = parseFloat($('input[name="purchase_rate"]').val()) || 0;
			const subtotal = quantity * rate;

			$('#subtotal').text(subtotal.toFixed(2));
			updateAmounts();
		}
	</script>
</body>

</html>