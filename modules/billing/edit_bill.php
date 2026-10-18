<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'edit')) {
    exit('Access Denied');
}

$bill_id = $_GET['id'] ?? '';
$type = $_GET['type'] ?? 'hospital';

// Get bill details
$conn = getDbConnection();

// Get active doctors list
$doctors = $conn->query("SELECT d.*, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        WHERE d.status = 'ACTIVE' 
                        ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare('SELECT b.*, p.name as patient_name, d.name as doctor_name, d.doctor_id, 
                       dept.name as dept_name, pt.type_name as payment_mode
                       FROM bills b 
                       JOIN patients p ON b.patient_id = p.patient_id
                       JOIN doctors d ON b.doctor_id = d.doctor_id
                       JOIN departments dept ON b.dept_id = dept.dept_id
                       LEFT JOIN payment_types pt ON b.payment_type_id = pt.payment_type_id
                       WHERE b.bill_id = ?');
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

if (!$bill) {
    header('Location: bills_list.php');
    exit;
}

// Get ledger details (company_id, etc.)
$stmt = $conn->prepare('SELECT pl.*, ca.company_name 
                       FROM payment_ledger pl 
                       LEFT JOIN company_accounts ca ON pl.company_id = ca.company_id 
                       WHERE pl.bill_id = ?');
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$ledger = $stmt->get_result()->fetch_assoc();

// Get active companies for Company Credit
$companies = $conn->query("SELECT company_id, company_name, credit_limit, current_balance, payment_days 
                          FROM company_accounts 
                          WHERE status = 'ACTIVE' 
                          ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

// Parse payment details if present
$payment_details = !empty($bill['payment_details']) ? json_decode($bill['payment_details'], true) : [];
$bill_company_name = $payment_details['company_name'] ?? ($ledger['company_name'] ?? '');
$bill_company_ref = $payment_details['reference_no'] ?? '';
$company_names = array_column($companies, 'company_name');
if (!empty($bill_company_name) && !in_array($bill_company_name, $company_names)) {
    $companies[] = [
        'company_id' => $ledger['company_id'] ?? 0,
        'company_name' => $bill_company_name,
        'credit_limit' => 0,
        'current_balance' => 0,
        'payment_days' => 30
    ];
}

// Get bill items
$stmt = $conn->prepare('SELECT bi.*, s.name as service_name 
                       FROM bill_items bi
                       JOIN services s ON bi.service_id = s.service_id
                       WHERE bi.bill_id = ?');
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
	<title>Edit Bill</title>
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
	<link rel="stylesheet" href="../../assets/css/cdn/all.min.css">
	<link rel="stylesheet" href="../../assets/css/style.css">
	<link rel="stylesheet" href="../../assets/css/generate_bill.css">

	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<script src="../../assets/js/tax_calculator.js"></script>

	<style>
		.select2-container {
			z-index: 9999;
			width: 100% !important;
		}

		.select2-dropdown {
			z-index: 9999;
		}

		.select2-search__field {
			width: 100% !important;
			display: block !important;
			padding: 6px !important;
		}

		.select2-container--default .select2-search--dropdown .select2-search__field {
			border: 1px solid #ddd;
		}

		.select2-results {
			max-height: 300px;
			overflow-y: auto;
		}

		.form-row {
			display: grid;
			grid-template-columns: 1fr 2fr 2fr;
			justify-content: space-between;
			margin-bottom: 10px;
		}

		.form-group {
			margin-right: 10px;
		}

		.form-group:last-child {
			margin-right: 0;
		}

		input[type="number"] {
			width: 100px;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>Edit Bill - <?php echo htmlspecialchars($bill_id); ?>
		</h2>

		<div class="bill-info">
			<div class="form-row">
				<div class="form-group">
					<label>Patient ID:</label>
					<div class="input-group">
						<input type="text" id="patient_search" name="patient_id"
							value="<?php echo $bill['patient_id']; ?>"
							required placeholder="Enter Patient ID" onblur="searchPatient()"
							title="Enter valid Patient ID">
					</div>
				</div>
				<div class="form-group">
					<label>Patient Name:</label>
					<input type="text" id="patientName" readonly class="readonly-field"
						value="<?php echo htmlspecialchars($bill['patient_name']); ?>"
						placeholder="Will be auto-populated">
				</div>
				<div class="form-group">
					<label>Doctor:</label>
					<select id="doctor_select" name="doctor_id" required class="form-control">
						<?php foreach ($doctors as $doctor) { ?>
						<option
							value="<?php echo $doctor['doctor_id']; ?>"
							<?php echo ($doctor['doctor_id'] == $bill['doctor_id']) ? 'selected' : ''; ?>
							data-dept="<?php echo $doctor['dept_id']; ?>">
							<?php echo htmlspecialchars($doctor['name'].' ('.$doctor['dept_name'].')'); ?>
						</option>
						<?php } ?>
					</select>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label>Bill Date:</label>
					<input type="date" id="bill_date" name="bill_date"
						value="<?php echo date('Y-m-d', strtotime($bill['bill_date'])); ?>"
						required class="form-control">
				</div>
				<div class="form-group">
					<label>Payment Mode:</label>
					<select id="payment_type_select" name="payment_type_id" class="form-control" onchange="togglePaymentFields(this.value)">
						<option value="1" <?php echo (1 == $bill['payment_type_id']) ? 'selected' : ''; ?>>CASH</option>
						<option value="2" <?php echo (2 == $bill['payment_type_id']) ? 'selected' : ''; ?>>COMPANY CREDIT</option>
						<?php if (!in_array($bill['payment_type_id'], [1, 2])) { ?>
						<option value="<?php echo $bill['payment_type_id']; ?>" selected>
							<?php echo htmlspecialchars($bill['payment_mode'] ?? 'OTHER'); ?>
						</option>
						<?php } ?>
					</select>
				</div>
				<div class="form-group">
					<label>Payment Status:</label>
					<input type="text" id="current_payment_status" readonly class="readonly-field"
						value="<?php echo htmlspecialchars($bill['payment_status']); ?>"
						style="font-weight: bold; color: <?php echo ('PAID' === $bill['payment_status']) ? '#28a745' : '#dc3545'; ?>;">
				</div>
			</div>

			<div id="company_credit_fields" style="<?php echo (2 == $bill['payment_type_id']) ? '' : 'display: none;'; ?> margin-top: 10px; margin-bottom: 15px; padding: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px;">
				<div style="font-weight: 600; margin-bottom: 8px; color: #1e40af;">
					<i class="fas fa-building"></i> Company Credit Details
				</div>
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
					<div class="form-group" style="margin-right: 0;">
						<label>Company Name: <span style="color:red;">*</span></label>
						<select id="company_name" name="company_name" class="form-control" style="width: 100%;">
							<option value="">-- Select Company --</option>
							<?php foreach ($companies as $comp) { ?>
							<option value="<?php echo htmlspecialchars($comp['company_name']); ?>"
								<?php echo ($bill_company_name === $comp['company_name']) ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($comp['company_name']); ?>
							</option>
							<?php } ?>
						</select>
					</div>
					<div class="form-group" style="margin-right: 0;">
						<label>Reference / Auth ID:</label>
						<input type="text" id="company_ref" name="company_ref" class="form-control"
							value="<?php echo htmlspecialchars($bill_company_ref); ?>"
							placeholder="Card / Emp / Ref number (optional)">
					</div>
				</div>
				<div class="text-muted small" style="margin-top: 8px;">
					<i class="fas fa-info-circle"></i> Selecting Company Credit will mark this bill as <strong>PENDING</strong> and link the receivable balance to the company ledger.
				</div>
			</div>
		</div>

		<form id="editBillForm">
			<input type="hidden" name="bill_id"
				value="<?php echo htmlspecialchars($bill_id); ?>">
			<input type="hidden" name="dept_id" id="dept_id"
				value="<?php echo $bill['dept_id']; ?>">

			<!-- Add Service Section -->
			<div class="service-row">
				<div class="form-group">
					<label>Add Service:</label>
					<select id="serviceSelect" style="width: 100%;"></select>
				</div>
			</div>

			<div class="services-list">
				<h3>Services</h3>
				<div id="servicesList">
					<?php foreach ($items as $item) { ?>
					<div class="service-item"
						data-service-id="<?php echo $item['service_id']; ?>">
						<span><?php echo htmlspecialchars($item['service_name']); ?></span>
						<input type="number"
							name="quantity[<?php echo $item['service_id']; ?>]"
							value="<?php echo $item['quantity']; ?>"
							min="1" onchange="updateTotal(this)">
						<input type="number"
							name="price[<?php echo $item['service_id']; ?>]"
							value="<?php echo $item['unit_price']; ?>"
							step="0.01" onchange="updateTotal(this)">
						<span
							class="item-total">₹<?php echo number_format($item['total'], 2); ?></span>
						<button type="button" class="btn-remove" onclick="removeService(this)">Remove</button>
					</div>
					<?php } ?>
				</div>
			</div>

			<div class="billing-summary">
				<div class="summary-row">
					<label>Subtotal:</label>
					<span
						id="subtotal">₹<?php echo number_format($bill['total_amount'], 2); ?></span>
				</div>
				<div class="discount-section">
					<label>Discount Amount:</label>
					<input type="number" name="discount"
						value="<?php echo $bill['discount']; ?>"
						min="0" step="0.01" onchange="updateFinalTotal()">
				</div>
				<div class="summary-row">
					<b><label>Final Total:</label></b>
					<b><span
							id="finalTotal">₹<?php echo number_format($bill['final_amount'], 2); ?></span></b>
				</div>
			</div>

			<div class="buttons">
				<button type="submit" class="btn-submit">Update Bill</button>
				<?php if (canPrintBill() && 'CANCELLED' !== $bill['payment_status']) { ?>
				<button type="button" onclick="window.open('print_bill.php?id=<?php echo urlencode($bill_id); ?>&type=hospital&trigger_print=1', '_blank')" style="padding: 10px 20px; font-size: 16px; border: none; border-radius: 4px; cursor: pointer; background-color: #0d6efd; color: white;">Print Bill</button>
				<?php } ?>
				<button type="button" onclick="window.location.href='bills_list.php'" class="btn-cancel">Cancel</button>
			</div>
		</form>
	</div>

	<script>
		// Initialize select2 for service selection
		$("#serviceSelect").select2({
			placeholder: 'Select a service',
			ajax: {
				url: '../../includes/helpers/get_services.php',
				dataType: 'json',
				delay: 250,
				data: function(params) {
					return {
						term: params.term,
						dept_id: $('#dept_id').val()
					};
				},
				processResults: function(data) {
					return {
						results: data.map(item => ({
							id: item.id,
							text: item.label,
							label: item.label,
							price: item.price,
							category: item.category
						}))
					};
				},
				cache: true
			},
			minimumInputLength: 3,
			templateResult: function(service) {
				if (service.loading) return service.text;
				if (service.category === 'CONSULTATION') return service.label;
				return `${service.label} (₹${service.price})`;
			},
			templateSelection: formatServiceSelection
		}).on('select2:select', function(e) {
			const service = e.params.data;
			if (service && service.id) {
				addService(service);
			}
			$(this).val(null).trigger('change');
		});

		function formatServiceSelection(service) {
			return service.label || service.text;
		}

		// Update department ID when doctor changes
		$("#doctor_select").on('change', function() {
			const selectedOption = $(this).find('option:selected');
			const deptId = selectedOption.data('dept');
			$("#dept_id").val(deptId); // Update hidden dept_id field
		});

		// Search patient function
		function searchPatient() {
			const patientId = document.getElementById('patient_search').value;

			fetch(`../../includes/helpers/get_patient.php?term=${patientId}&module=billing`)
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						document.getElementById('patientName').value = data.patient.name;
					} else {
						alert('Patient not found');
						document.getElementById('patientName').value = '';
					}
				});
		}

		// Add new service to the list
		function addService(service) {
			if (!service || !service.id) return;

			const existingService = document.querySelector(`.service-item[data-service-id="${service.id}"]`);
			if (existingService) {
				alert('Service already added!');
				return;
			}

			const price = parseFloat(service.price) || 0;
			const total = price;

			const serviceItem = `
				<div class="service-item" data-service-id="${service.id}">
					<span>${service.label}</span>
					<input type="number" name="quantity[${service.id}]" value="1" min="1" onchange="updateTotal(this)">
					<input type="number" name="price[${service.id}]" value="${price.toFixed(2)}" step="0.01" onchange="updateTotal(this)">
					<span class="item-total">₹${total.toFixed(2)}</span>
					<button type="button" class="btn-remove" onclick="removeService(this)">Remove</button>
				</div>
			`;

			document.getElementById('servicesList').insertAdjacentHTML('beforeend', serviceItem);
			updateFinalTotal();
		}

		// Remove service from the list
		function removeService(button) {
			if (confirm('Are you sure you want to remove this service?')) {
				button.closest('.service-item').remove();
				updateFinalTotal();
			}
		}

		// Update total for a service item
		function updateTotal(input) {
			const serviceItem = input.closest('.service-item');
			const quantity = parseInt(serviceItem.querySelector('input[name^="quantity"]').value) || 1;
			const price = parseFloat(serviceItem.querySelector('input[name^="price"]').value) || 0;
			const total = quantity * price;
			serviceItem.querySelector('.item-total').textContent = `₹${total.toFixed(2)}`;
			updateFinalTotal();
		}

		// Update final total
		function updateFinalTotal() {
			let subtotal = 0;
			document.querySelectorAll('.service-item').forEach(item => {
				const quantity = parseInt(item.querySelector('input[name^="quantity"]').value) || 1;
				const price = parseFloat(item.querySelector('input[name^="price"]').value) || 0;
				subtotal += quantity * price;
			});

			const discount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
			const final = Math.max(0, subtotal - discount);

			document.getElementById('subtotal').textContent = `₹${subtotal.toFixed(2)}`;
			document.getElementById('finalTotal').textContent = `₹${final.toFixed(2)}`;
		}

		function togglePaymentFields(typeId) {
			const isCompanyCredit = parseInt(typeId) === 2;
			if (isCompanyCredit) {
				$('#company_credit_fields').slideDown();
				$('#company_name').prop('required', true);
				$('#current_payment_status').val('Will change to PENDING').css('color', '#d97706');
			} else {
				$('#company_credit_fields').slideUp();
				$('#company_name').prop('required', false);
				const originalStatus = '<?php echo addslashes($bill['payment_status']); ?>';
				$('#current_payment_status').val(originalStatus).css('color', originalStatus === 'PAID' ? '#28a745' : '#dc3545');
			}
		}

		// Form submission handler
		$('#editBillForm').on('submit', function(e) {
			e.preventDefault();
			const paymentTypeId = $('#payment_type_select').val();
			if (parseInt(paymentTypeId) === 2) {
				const compName = $('#company_name').val();
				if (!compName) {
					alert('Please select a company for Company Credit.');
					$('#company_name').focus();
					return;
				}
			}

			if (!confirm('Are you sure you want to update this bill?')) return;

			const formData = new FormData(this);

			const services = [];
			document.querySelectorAll('.service-item').forEach(item => {
				const quantity = parseFloat(item.querySelector('input[name^="quantity"]').value) || 1;
				const price = parseFloat(item.querySelector('input[name^="price"]').value) || 0;
				const total = parseFloat((quantity * price).toFixed(2));
				services.push({
					service_id: item.dataset.serviceId,
					quantity: quantity,
					price: price,
					total: total
				});
			});
			formData.append('services', JSON.stringify(services));
			formData.append('patient_id', document.getElementById('patient_search').value);
			formData.append('doctor_id', $("#doctor_select").val());
			formData.append('dept_id', $("#dept_id").val());
			formData.append('bill_date', document.getElementById('bill_date').value);
			formData.append('payment_type_id', paymentTypeId);
			formData.append('company_name', $('#company_name').val() || '');
			formData.append('company_ref', $('#company_ref').val() || '');

			fetch('update_bill.php', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						alert('Bill updated successfully');
						window.location.href = 'bills_list.php';
					} else {
						alert('Error: ' + data.message);
					}
				});
		});
	</script>
</body>

</html>