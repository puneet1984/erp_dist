
let selectedMedicines = [];

function addMedicineToTable() {
    const medicine = $('.medicine-select').select2('data')[0];
    const batch = $('#batch_select option:selected');
    const quantity = parseInt($('#quantity').val());
    if (!medicine || !batch.val() || !quantity) {
        alert('Please select medicine, batch and quantity');
        return;
    }
    const medicineData = {
        item_id: medicine.id,
        name: medicine.text,
        batch_number: batch.val(),
        batch_id: batch.data('batch-id'),
        expiry_date: batch.data('expiry'),
        quantity: quantity,
        price: parseFloat(batch.data('price')),
        total: quantity * parseFloat(batch.data('price'))
    };
    selectedMedicines.push(medicineData);
    updateMedicinesTable();
    resetMedicineSelection();
    calculateTotals();
}

function updateMedicinesTable() {
    const list = document.getElementById('medicinesList');
    list.innerHTML = selectedMedicines.map((med, index) => `
        <div class="medicine-item">
            <span>${med.name}</span>
            <span>${med.batch_number}</span>
            <span>${formatDate(med.expiry_date)}</span>
            <span><input type="number" value="${med.quantity}" min="1" onchange="updateQuantity(${index}, this.value)"></span>
            <span>₹${med.price.toFixed(2)}</span>
            <span>₹${med.total.toFixed(2)}</span>
            <span><button type="button" class="btn-remove" onclick="removeMedicine(${index})">Remove</button></span>
        </div>
    `).join('');
}

function removeMedicine(index) {
    selectedMedicines.splice(index, 1);
    updateMedicinesTable();
    calculateTotals();
}

function calculateTotals() {
    const subtotal = selectedMedicines.reduce((sum, med) => sum + med.total, 0);
    $('#subtotal, #final_subtotal').text(subtotal.toFixed(2));
    updateAmounts();
}
// Initialize doctor select with Select2
$('.doctor-select').select2();
// Initialize page
$(document).ready(function() {
    initializeSelect2();
    // Default to cash payment
    handlePaymentType(1);
});
// Form submission
$('#saleForm').on('submit', function(e) {
    e.preventDefault();
    if (!validateForm()) return;
    // Add medicines data to form
    $('#medicinesJson').val(JSON.stringify(selectedMedicines));
    const formData = new FormData(this);
    if (confirm(`Confirm sale for ₹${$('#net_amount').text()}?`)) {
        $.ajax({
            url: 'sale.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                const data = JSON.parse(response);
                if (data.status === 'success') {
                    if (confirm('Sale recorded successfully. Do you want to print the bill?')) {
                        window.open(`print_pharmacy_bill.php?id=${data.bill_id}`, '_blank');
                    }
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            }
        });
    }
});

function updateTotalAmount(discount) {
    const subtotal = parseFloat(document.getElementById('subtotal').textContent);
    const total = Math.max(0, subtotal - discount);
    // Use calculateTotal from generate_bill.js instead of direct calculation
    calculateTotal(subtotal, 0); // Pass 0 as tax since pharmacy doesn't have tax

    // Handle card payment recalculation if needed
    if (document.getElementById('card_details').style.display === 'block') {
        calculateCardFee();
    }
}

function initializeSelect2() {
    $('.medicine-select').select2({
        ajax: {
            url: '../includes/helpers/medicine_helper.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    type: 'sale' // Specify sale type
                };
            },
            processResults: function(data) {
                // Add null check and ensure data has results property
                if (!data || !data.results) {
                    return {
                        results: []
                    };
                }
                return {
                    results: data.results.map(item => ({
                        id: item.id || item.name,
                        text: item.text || item.name,
                        data: item
                    }))
                };
            },
            cache: true
        },
        minimumInputLength: 2,
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
        fetchBatches(data.id);
    });
}

function fetchBatches(itemId) {
    $.get('sale.php', {
            item_id: itemId
        })
        .done(function(response) {
            const batches = JSON.parse(response);
            const select = $('#batch_select');
            select.empty().append('<option value="">Select batch</option>');

            batches.forEach(batch => {
                select.append(`<option value="${batch.batch_number}" 
                                    data-batch-id="${batch.batch_id}"
                                    data-price="${batch.retail_price}"
                                    data-expiry="${batch.expiry_date}"
                                    data-stock="${batch.current_quantity}">
                                    Batch: ${batch.batch_number} | Stock: ${batch.current_quantity} | 
                                    Exp: ${formatDate(batch.expiry_date)} | ₹${batch.retail_price}
                                </option>`);
            });
        });
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-IN', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function resetMedicineSelection() {
    $('.medicine-select').val(null).trigger('change');
    $('#batch_select').val('');
    $('#quantity').val('');
}

function updateQuantity(index, newQuantity) {
    const quantity = parseInt(newQuantity) || 1;
    selectedMedicines[index].quantity = quantity;
    selectedMedicines[index].total = quantity * selectedMedicines[index].price;
    updateMedicinesTable();
    calculateTotals();
}

function validateForm() {
    if (selectedMedicines.length === 0) {
        alert('Please add at least one medicine to the bill');
        return false;
    }
    const paymentType = document.querySelector('select[name="payment_type"]').value;
    if (paymentType === '9') { // Split payment
        const total = parseFloat(document.getElementById('totalAmount').textContent);
        const splitTotal = parseFloat(document.getElementById('split_total').textContent);
        if (Math.abs(total - splitTotal) > 0.01) {
            alert('Split payment amounts must equal the total bill amount');
            return false;
        }
    }
    return true;
}

function updateAmounts() {
    const subtotal = parseFloat(document.getElementById('subtotal').textContent);
    const discount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
    const net = Math.max(0, subtotal - discount);
    document.getElementById('totalAmount').textContent = net.toFixed(2);
    if (document.getElementById('card_details').style.display === 'block') {
        calculateCardFee();
    }
    if (document.getElementById('split_details').style.display === 'block') {
        updateSplitTotal();
    }
}