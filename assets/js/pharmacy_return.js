let availableBatches = [];
let selectedReturnItems = [];
let lockedSupplier = null;
let lockedPurchase = null;

function renderReturnItems() {
	const tbody = $('#returnItemsTable tbody');
	tbody.empty();

	let baseTotal = 0;
	let deductionTotal = 0;
	let taxTotal = 0;
	let finalTotal = 0;

	selectedReturnItems.forEach((item, index) => {
		const baseAmt = item.quantity * item.purchase_rate;
		const deduction = item.deduction_amount || 0;
		const taxable = baseAmt - deduction;
		const taxAmt = (taxable * (item.tax_percentage || 0)) / 100;
		const finalAmt = taxable + taxAmt;

		baseTotal += baseAmt;
		deductionTotal += deduction;
		taxTotal += taxAmt;
		finalTotal += finalAmt;

		tbody.append(`
			<tr>
				<td>${item.label} <small class="text-muted">(ID: ${item.item_id})</small></td>
				<td>${item.batch_number}</td>
				<td>${item.quantity}</td>
				<td>${item.purchase_rate.toFixed(2)}</td>
				<td>${baseAmt.toFixed(2)}</td>
				<td>${deduction.toFixed(2)}</td>
				<td>${(item.tax_percentage || 0).toFixed(2)}</td>
				<td>${taxAmt.toFixed(2)}</td>
				<td>${finalAmt.toFixed(2)}</td>
				<td><small>${item.notes || '-'}</small></td>
				<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeReturnItem(${index})">Remove</button></td>
			</tr>
		`);
	});

	$('#baseTotal').text(baseTotal.toFixed(2));
	$('#deductionTotal').text(deductionTotal.toFixed(2));
	$('#taxTotal').text(taxTotal.toFixed(2));
	$('#returnTotal').text(finalTotal.toFixed(2));
}

function removeReturnItem(index) {
	selectedReturnItems.splice(index, 1);
	renderReturnItems();
}

function loadBatchesForItem(itemId) {
	availableBatches = [];
	$('#batch_id').empty().trigger('change');
	$('#available_qty').val('');
	$('#batch_rate').val('');

	if (!itemId) {
		return;
	}

	$.ajax({
		url: 'supplier_returns.php',
		method: 'POST',
		dataType: 'json',
		data: {
			action: 'get_batches_by_item',
			item_id: itemId
		},
		success: function(response) {
			if (response.status !== 'success') {
				alert('Failed to fetch batches');
				return;
			}

			availableBatches = response.batches || [];

			// Filter batches if supplier/purchase already locked
			let filteredBatches = availableBatches;
			if (lockedSupplier && lockedPurchase) {
				filteredBatches = availableBatches.filter(b =>
					parseInt(b.supplier_id) === lockedSupplier &&
					parseInt(b.purchase_id) === lockedPurchase
				);
			}

			const options = filteredBatches.map(b => {
				const exp = b.expiry_date ? b.expiry_date : '-';
				const invoice = b.invoice_number || '-';
				const label =
					`Batch ${b.batch_number} | Stock ${b.current_quantity} | Exp ${exp} | Invoice ${invoice}`;
				return new Option(label, b.batch_id, false, false);
			});

			$('#batch_id').empty().append(options).trigger('change');
		},
		error: function(xhr) {
			const msg = xhr.responseJSON?.message || 'Error loading batches';
			alert(msg);
		}
	});
}

$(document).ready(function() {
	$('#item_id').select2({
		ajax: {
			url: '../../includes/helpers/medicine_helper.php',
			dataType: 'json',
			delay: 250,
			data: function(params) {
				return {
					q: params.term,
					type: 'sale'
				};
			},
			processResults: function(data) {
				if (!data.status || 'success' !== data.status) return { results: [] };
				return {
					results: (data.results || []).map(item => ({
						id: item.id,
						text: `${item.text} (ID: ${item.id})`
					}))
				};
			}
		},
		minimumInputLength: 2,
		placeholder: 'Search medicine...'
	});

	$('#batch_id').select2({
		placeholder: 'Select batch...'
	});

	$('#item_id').on('change', function() {
		loadBatchesForItem($(this).val());
	});

	$('#batch_id').on('change', function() {
		const batchId = parseInt($(this).val(), 10);
		const batch = availableBatches.find(b => parseInt(b.batch_id, 10) === batchId);
		if (!batch) {
			$('#available_qty').val('');
			$('#batch_rate').val('');
			return;
		}

		$('#available_qty').val(batch.current_quantity);
		$('#batch_rate').val(parseFloat(batch.purchase_rate).toFixed(2));

		// Auto-fill supplier and invoice if first item
		if (selectedReturnItems.length === 0) {
			lockedSupplier = parseInt(batch.supplier_id);
			lockedPurchase = parseInt(batch.purchase_id);
			$('#supplier_id').val(lockedSupplier);
			$('#purchase_id').val(lockedPurchase);
			$('#supplier_display').val(batch.supplier_name || '-');
			$('#invoice_display').val(batch.invoice_number || '-');
		}
	});

	$('#addReturnItem').on('click', function() {
		const batchId = parseInt($('#batch_id').val(), 10);
		const batch = availableBatches.find(b => parseInt(b.batch_id, 10) === batchId);
		const qty = parseInt($('#return_qty').val(), 10);
		const reason = $('#return_notes').val().trim();

		if (!batch) {
			alert('Please select a batch');
			return;
		}
		if (!Number.isInteger(qty) || qty <= 0) {
			alert('Return quantity must be a whole number');
			return;
		}
		if (qty > parseInt(batch.current_quantity, 10)) {
			alert('Return quantity cannot exceed available stock');
			return;
		}

		const existingIndex = selectedReturnItems.findIndex(x => x.batch_id === batchId);
		// Verify supplier/purchase match if already locked
		if (lockedSupplier && parseInt(batch.supplier_id) !== lockedSupplier) {
			alert('All batches must be from the same supplier');
			return;
		}
		if (lockedPurchase && parseInt(batch.purchase_id) !== lockedPurchase) {
			alert('All batches must be from the same purchase invoice');
			return;
		}

		if (existingIndex >= 0) {
			const updatedQty = selectedReturnItems[existingIndex].quantity + qty;
			if (updatedQty > parseInt(batch.current_quantity, 10)) {
				alert('Total return quantity for this batch exceeds available stock');
				return;
			}
			selectedReturnItems[existingIndex].quantity = updatedQty;
			selectedReturnItems[existingIndex].reason = reason || selectedReturnItems[existingIndex].reason;
		} else {
			const deductionAmt = parseFloat($('#deduction_amount').val()) || 0;
			const taxPct = parseFloat($('#tax_percentage').val()) || 0;

			selectedReturnItems.push({
				batch_id: batchId,
				batch_number: batch.batch_number,			item_id: batch.item_id,				label: batch.item_name,
				available: parseInt(batch.current_quantity, 10),
				quantity: qty,
				purchase_rate: parseFloat(batch.purchase_rate),
				deduction_amount: deductionAmt,
				tax_percentage: taxPct,
				notes: reason
			});
		}

		$('#return_qty').val('');
		$('#deduction_amount').val('0');
		$('#tax_percentage').val('0');
		$('#return_notes').val('');
		renderReturnItems();
	});

	$('#supplierReturnForm').on('submit', function(e) {
		e.preventDefault();

		if (selectedReturnItems.length === 0) {
			alert('Please add at least one return item');
			return;
		}
		if (!lockedSupplier || !lockedPurchase) {
			alert('Supplier and purchase information missing');
			return;
		}

		const formData = new FormData(this);
		formData.append('action', 'save_return');
		formData.append('items', JSON.stringify(selectedReturnItems.map(item => ({
			batch_id: item.batch_id,
			quantity: item.quantity,
			deduction_amount: item.deduction_amount || 0,
			tax_percentage: item.tax_percentage || 0,
			notes: item.notes
		}))));

		$.ajax({
			url: 'supplier_returns.php',
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
				alert('Error: ' + (xhr.responseJSON?.message || 'Request failed'));
			}
		});
	});

	$('.delete-return').on('click', function() {
		if (!confirm('Delete this supplier return? Stock will be restored.')) {
			return;
		}

		$.ajax({
			url: 'supplier_returns.php',
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'delete_return',
				return_id: $(this).data('id')
			},
			success: function(response) {
				if (response.status === 'success') {
					alert(response.message);
					location.reload();
				} else {
					alert('Error: ' + response.message);
				}
			},
			error: function(xhr) {
				alert('Error: ' + (xhr.responseJSON?.message || 'Request failed'));
			}
		});
	});
});
