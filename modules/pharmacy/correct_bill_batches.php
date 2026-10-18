<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!canAccessBillBatchCorrections()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correct Bill Batches - Medicine Batch Issue Fix</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 28px;
        }

        .page-header p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .search-panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .search-group {
            display: flex;
            gap: 15px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .search-group .form-group {
            flex: 1;
            min-width: 300px;
            margin: 0;
        }

        .search-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .search-group input,
        .search-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-group input:focus,
        .search-group select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .btn-search {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            align-self: flex-end;
            transition: background 0.3s;
        }

        .btn-search:hover {
            background: #0056b3;
        }

        .btn-search:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .batches-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .batches-section.active {
            display: block;
        }

        .batches-header {
            margin-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }

        .batches-header h3 {
            margin: 0;
            color: #333;
            font-size: 16px;
        }

        .batches-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .batch-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }

        .batch-card.expired {
            border-left-color: #dc3545;
            background: #fff5f5;
        }

        .batch-card.expiring-soon {
            border-left-color: #ffc107;
            background: #fffbf0;
        }

        .batch-number {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .batch-detail {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
        }

        .batch-status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }

        .batch-status-badge.in-stock {
            background: #d4edda;
            color: #155724;
        }

        .batch-status-badge.out-of-stock {
            background: #f8d7da;
            color: #721c24;
        }

        .batch-status-badge.expiring-soon {
            background: #fff3cd;
            color: #856404;
        }

        .batch-status-badge.expired {
            background: #f8d7da;
            color: #721c24;
        }

        .bulk-move-panel {
            margin-top: 15px;
            padding: 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
        }

        .bulk-move-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            align-items: end;
            margin-bottom: 12px;
        }

        .bulk-move-controls label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .bulk-move-controls select {
            width: 100%;
            padding: 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
        }

        .bulk-move-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 13px;
            background: #fff;
        }

        .bulk-move-table th,
        .bulk-move-table td {
            border: 1px solid #e5e7eb;
            padding: 8px;
            text-align: left;
        }

        .bulk-move-table thead {
            background: #f1f5f9;
        }

        .bills-section {
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .bills-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .bills-section.active {
            display: block;
        }

        .bills-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
        }

        .bills-header h2 {
            margin: 0;
            color: #333;
            font-size: 20px;
        }

        .bill-count {
            background: #007bff;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .bills-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .bills-table thead {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }

        .bills-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            font-size: 13px;
        }

        .bills-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }

        .bills-table tbody tr:hover {
            background: #f8f9fa;
        }

        .bill-id-link {
            color: #007bff;
            text-decoration: none;
            font-weight: 500;
        }

        .bill-id-link:hover {
            text-decoration: underline;
        }

        .batch-info {
            background: #f0f7ff;
            padding: 8px 12px;
            border-radius: 4px;
            display: inline-block;
            font-size: 13px;
            font-weight: 500;
            color: #0056b3;
        }

        .batch-info-expiry {
            display: block;
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        .price-info {
            text-align: right;
        }

        .quantity-badge {
            background: #e8f5e9;
            color: #27752f;
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 13px;
            display: inline-block;
        }

		.btn-edit-batch {
			background: #007bff;
			color: white;
			border: none;
			padding: 6px 12px;
			border-radius: 4px;
			cursor: pointer;
			font-size: 12px;
			font-weight: 600;
			transition: background 0.3s;
		}

		.btn-edit-batch:hover {
			background: #0056b3;
		}

		/* Modal Styles */
		.modal {
			display: none;
			position: fixed;
			z-index: 1000;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			overflow: auto;
			background-color: rgba(0, 0, 0, 0.4);
		}

		.modal.show {
			display: block;
		}

		.modal-content {
			background-color: #fefefe;
			margin: 5% auto;
			padding: 20px;
			border: 1px solid #888;
			border-radius: 8px;
			width: 90%;
			max-width: 500px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
		}

		.modal-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 20px;
			padding-bottom: 15px;
			border-bottom: 2px solid #f0f0f0;
		}

		.modal-header h2 {
			margin: 0;
			color: #333;
			font-size: 18px;
		}

		.modal-close {
			background: none;
			border: none;
			font-size: 28px;
			font-weight: bold;
			color: #aaa;
			cursor: pointer;
			padding: 0;
			line-height: 1;
		}

		.modal-close:hover {
			color: #000;
		}

		.modal-body {
			margin-bottom: 20px;
		}

		.form-group-modal {
			margin-bottom: 15px;
		}

		.form-group-modal label {
			display: block;
			margin-bottom: 8px;
			font-weight: 600;
			color: #333;
			font-size: 14px;
		}

		.form-group-modal select {
			width: 100%;
			padding: 10px;
			border: 1px solid #ddd;
			border-radius: 4px;
			font-size: 14px;
		}

		.form-group-modal select:focus {
			outline: none;
			border-color: #007bff;
			box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
		}

		.modal-footer {
			display: flex;
			gap: 10px;
			justify-content: flex-end;
		}

		.btn-modal {
			padding: 10px 20px;
			border: none;
			border-radius: 4px;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			transition: background 0.3s;
		}

		.btn-modal-primary {
			background: #007bff;
			color: white;
		}

		.btn-modal-primary:hover {
			background: #0056b3;
		}

		.btn-modal-secondary {
			background: #6c757d;
			color: white;
		}

		.btn-modal-secondary:hover {
			background: #5a6268;
		}
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .loading {
            text-align: center;
            padding: 40px;
            display: none;
        }

        .loading.active {
            display: block;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: none;
        }

        .alert.active {
            display: block;
        }

        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            border: 1px solid #ddd !important;
            border-radius: 4px !important;
            height: 38px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #007bff !important;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
        }

        @media (max-width: 768px) {
            .search-group {
                flex-direction: column;
            }

            .search-group .form-group {
                min-width: 100%;
            }

            .bills-table {
                font-size: 12px;
            }

            .bills-table th,
            .bills-table td {
                padding: 8px;
            }

            .medicine-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-pills"></i> Correct Bill Batches</h1>
            <p>Fix medicine batch numbers in pharmacy bills due to clerical errors. Search for a medicine to view all
                bills using it.</p>
        </div>

        <!-- Alert Messages -->
        <div class="alert alert-warning" id="warningAlert">
            <strong><i class="fas fa-exclamation-triangle"></i> Warning:</strong> <span id="warningText"></span>
        </div>
        <div class="alert alert-error" id="errorAlert">
            <strong><i class="fas fa-times-circle"></i> Error:</strong> <span id="errorText"></span>
        </div>
        <div class="alert alert-success" id="successAlert">
            <strong><i class="fas fa-check-circle"></i> Success:</strong> <span id="successText"></span>
        </div>

        <!-- Search Panel -->
        <div class="search-panel">
            <div class="search-group">
                <div class="form-group">
                    <label for="medicineSelect">Search Medicine</label>
                    <select id="medicineSelect" style="width: 100%;"
                        placeholder="Search by medicine name or active ingredient...">
                        <option></option>
                    </select>
                </div>
                <button type="button" class="btn-search" id="btnSearch" onclick="searchBills()"
                    style="margin-top: 23px;">
                    <i class="fas fa-search"></i> Search Bills
                </button>
            </div>
        </div>

        <!-- Batches Section -->
        <div class="batches-section" id="batchesSection">
            <div class="batches-header">
                <h3>Available Batches</h3>
            </div>
            <div id="batchesContainer"></div>
        </div>

        <!-- Bills Section -->
        <div class="bills-section" id="billsSection">
            <div class="bills-header">
                <h2>Pharmacy Bills Using This Medicine</h2>
                <span class="bill-count" id="billCount">0 bills</span>
            </div>
            <div id="billsTableContainer">
                <div class="no-results">
                    <i class="fas fa-inbox"></i>
                    <p>No bills found using this medicine</p>
                </div>
            </div>
        </div>

        <div class="bills-section" id="bulkMoveSection">
            <div class="bills-header">
                <h2>Move Selected Bills To Another Batch</h2>
                <span class="bill-count" id="bulkMoveCount">0 selected</span>
            </div>
            <div class="bulk-move-panel">
                <div class="bulk-move-controls">
                    <div>
                        <label for="sourceBatchSelect">Source Batch</label>
                        <select id="sourceBatchSelect"></select>
                    </div>
                    <div>
                        <label for="targetBatchSelect">Target Batch</label>
                        <select id="targetBatchSelect"></select>
                    </div>
                    <div>
                        <button type="button" class="btn-search" id="moveSelectedBillsBtn" onclick="moveSelectedBatchBills()">Move Selected Bills</button>
                    </div>
                </div>
                <div id="bulkMoveSummary" class="batch-detail" style="margin-bottom: 10px; font-size: 13px; color: #1f2937;">
                    <span>Selected bills:</span>
                    <strong>0</strong>
                    <span style="margin-left: 12px;">Selected quantity:</span>
                    <strong id="bulkMoveQty">0</strong>
                </div>
                <div id="bulkMoveBillsContainer"></div>
            </div>
        </div>
    </div>

	<!-- Edit Batch Modal -->
	<div id="editBatchModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h2>Change Batch for Bill</h2>
				<button class="modal-close" onclick="closeEditModal()">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-group-modal">
					<label>Bill ID:</label>
					<div style="padding: 10px; background: #f8f9fa; border-radius: 4px; color: #333; font-weight: 500;" id="modalBillId"></div>
				</div>
				<div class="form-group-modal">
					<label>Current Batch:</label>
					<div style="padding: 10px; background: #f8f9fa; border-radius: 4px; color: #333; font-weight: 500;" id="modalCurrentBatch"></div>
				</div>
				<div class="form-group-modal">
					<label for="newBatchSelect">Select New Batch:</label>
					<select id="newBatchSelect">
						<option value="">-- Choose a batch --</option>
					</select>
				</div>
				<div id="batchDetails" style="padding: 15px; background: #f0f7ff; border-radius: 4px; border-left: 4px solid #007bff; display: none; margin-top: 15px;">
					<div style="margin-bottom: 10px;"><strong>Expiry:</strong> <span id="detailExpiry"></span></div>
					<div style="margin-bottom: 10px;"><strong>Available Qty:</strong> <span id="detailQty"></span></div>
					<div><strong>Price:</strong> ₹<span id="detailPrice"></span></div>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn-modal btn-modal-secondary" onclick="closeEditModal()">Cancel</button>
				<button class="btn-modal btn-modal-primary" onclick="updateBatchInBill()">Update Batch</button>
			</div>
		</div>
	</div>

	<!-- jQuery and Select2 Libraries -->
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

	<script>
        let currentBillsData = [];
        let currentBatchesData = [];

		$(document).ready(function() {
			// Ensure loading and sections are hidden initially
			$('#loading').removeClass('active');
			$('#batchesSection').removeClass('active');
			$('#billsSection').removeClass('active');
            $('#bulkMoveSection').removeClass('active');
			
			// Initialize Select2 for medicine search
			$('#medicineSelect').select2({
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
                    processResults: function (data) {
                        if (!data || !data.results) {
                            return {
                                results: []
                            };
                        }
                        return data;
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'Search by medicine name or active ingredient...',
                allowClear: true,
                width: '100%'
            }).on('select2:select', function (e) {
                // Automatically search when a medicine is selected
                setTimeout(function () {
                    searchBills();
                }, 100);
            });

            // Allow Enter key to search only when Select2 has focus
            $('#medicineSelect').on('select2:close', function (e) {
                const selectedOption = $('#medicineSelect').select2('data');
                if (selectedOption && selectedOption.length > 0) {
                    setTimeout(function() {
                        $('#btnSearch').focus();
                    }, 1);
                }
            // Auto-focus search input when Select2 dropdown is opening
            }).on('select2:opening', function () {
                setTimeout(() => {
                    const searchField = document.querySelector('.select2-search__field');
                    if (searchField) {
                        searchField.focus();
                    }
                }, 250);
            });
        });

        function showAlert(type, message) {
            // Hide all alerts first
            $('#warningAlert, #errorAlert, #successAlert').removeClass('active');

            if (type === 'warning') {
                $('#warningText').text(message);
                $('#warningAlert').addClass('active');
            } else if (type === 'error') {
                $('#errorText').text(message);
                $('#errorAlert').addClass('active');
            } else if (type === 'success') {
                $('#successText').text(message);
                $('#successAlert').addClass('active');
            }

            // Auto-hide after 5 seconds
            setTimeout(function () {
                $(`#${type}Alert`).fadeOut(function () {
                    $(this).removeClass('active').show();
                });
            }, 5000);
        }

        function searchBills() {
            const selectedOption = $('#medicineSelect').select2('data');

            if (!selectedOption || selectedOption.length === 0) {
                showAlert('error', 'Please select a medicine first');
                return;
            }

            const itemId = selectedOption[0].id;
            const itemName = selectedOption[0].text;

            // Show loading
            $('#loading').addClass('active');
            $('#medicineDetails').removeClass('active');
            $('#billsSection').removeClass('active');

            // AJAX call to fetch bills
            $.ajax({
                url: '../../includes/helpers/get_bill_medicines.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    item_id: itemId,
                    medicine_name: itemName
                },
                success: function (response) {
                    $('#loading').removeClass('active');

                    if (response.status === 'success') {
                        displayBatches(response.batches); displayBills(response.bills);

                        if (response.bills.length === 0) {
                            showAlert('warning', 'No bills found using this medicine');
                        } else {
                            showAlert('success', `Found ${response.bills.length} bill(s) using this medicine`);
                        }
                    } else {
                        showAlert('error', response.message || 'Failed to fetch bills');
                    }
                },
                error: function (xhr, status, error) {
                    $('#loading').removeClass('active');
                    showAlert('error', 'Error: ' + (xhr.responseJSON?.message || error || 'Unknown error'));
                    console.error('AJAX Error:', xhr.responseText);
                }
            });
        }

        function refreshMedicineData(itemId) {
            if (!itemId) {
                return;
            }

            $('#loading').addClass('active');

            $.ajax({
                url: '../../includes/helpers/get_bill_medicines.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    item_id: itemId
                },
                success: function (response) {
                    $('#loading').removeClass('active');

                    if (response.status === 'success') {
                        displayBatches(response.batches);
                        displayBills(response.bills);
                    } else {
                        showAlert('error', response.message || 'Failed to refresh data');
                    }
                },
                error: function (xhr, status, error) {
                    $('#loading').removeClass('active');
                    showAlert('error', 'Error: ' + (xhr.responseJSON?.message || error || 'Unknown error'));
                    console.error('AJAX Error:', xhr.responseText);
                }
            });
        }

        function displayBatches(batches) {
            currentBatchesData = Array.isArray(batches) ? batches : [];

            if (!batches || batches.length === 0) {
                $('#batchesSection').removeClass('active');
                $('#bulkMoveSection').removeClass('active');
                return;
            }

            let batchesHtml = '<div class="batches-grid">';

            batches.forEach(function (batch) {
                const expiryDate = new Date(batch.expiry_date).toLocaleDateString('en-IN');
                const isExpired = new Date(batch.expiry_date) < new Date();
                const expiringIn30Days = new Date(batch.expiry_date) < new Date(Date.now() + 30 * 24 * 60 * 60 * 1000) && !isExpired;
                // Calculate actual quantities from initial and sold amounts
                const initialQty = parseInt(batch.initial_quantity) || 0;
                const soldQty = parseInt(batch.total_sold) || 0;
                const remainingQty = initialQty - soldQty;

                let statusClass = 'in-stock';
                let statusText = 'In Stock';

                if (remainingQty < 0) {
                    statusClass = 'out-of-stock';
                    statusText = 'Negative Stock';
                } else if (remainingQty === 0) {
                    statusClass = 'out-of-stock';
                    statusText = 'Out of Stock';
                } else if (isExpired) {
                    statusClass = 'expired';
                    statusText = 'Expired';
                } else if (expiringIn30Days) {
                    statusClass = 'expiring-soon';
                    statusText = 'Expiring Soon';
                }

                const cardClass = isExpired ? 'expired' : (expiringIn30Days ? 'expiring-soon' : '');

                // Check for quantity discrepancy
                const hasDiscrepancy = batch.has_discrepancy;
                const expectedQty = parseInt(batch.expected_quantity) || 0;
                
                let discrepancyHtml = '';
                const recalcButtonHtml = `
                    <button class="btn-edit-batch" style="margin-top: 8px;" onclick="fixBatchQuantity(${batch.batch_id}, '${escapeHtml(batch.batch_number)}', ${expectedQty})">
                        Recalculate Quantity
                    </button>
                `;
                if (hasDiscrepancy) {
                    discrepancyHtml = `
                        <div class="batch-detail" style="color: #dc3545; font-weight: 600; border-top: 1px solid #ffc107; padding-top: 8px; margin-top: 8px;">
                            <span>⚠️ Qty Mismatch!</span>
                        </div>
                        <div class="batch-detail" style="color: #856404; font-size: 11px;">
                            <span>System shows (current_qty):</span>
                            <strong>${batch.current_quantity}</strong>
                        </div>
                        <div class="batch-detail" style="color: #856404; font-size: 11px;">
                            <span>Should be (init - sold):</span>
                            <strong>${expectedQty}</strong>
                        </div>
                        <button class="btn-edit-batch" style="margin-top: 8px; background: #ffc107; color: #000;" onclick="fixBatchQuantity(${batch.batch_id}, '${escapeHtml(batch.batch_number)}', ${expectedQty})">
                            Fix Quantity
                        </button>
                    `;
                }

                batchesHtml += `
                    <div class="batch-card ${cardClass}" style="${hasDiscrepancy ? 'border-left-color: #ffc107; background: #fff9e6;' : ''}">
                        <div class="batch-number">${escapeHtml(batch.batch_number)}</div>
                        <div class="batch-detail">
                            <span>Initial Qty:</span>
                            <strong>${initialQty}</strong>
                        </div>
                        <div class="batch-detail">
                            <span>Sold Qty:</span>
                            <strong>${soldQty}</strong>
                                                    <span>Remaining Qty:</span>
                                                    <strong style="color: ${remainingQty === 0 ? '#dc3545' : '#28a745'}">${remainingQty}</strong>
                                                </div>
                                                <div class="batch-detail">
                        </div>
                        <div class="batch-detail">
                            <span>Expiry:</span>
                            <strong>${expiryDate}</strong>
                        </div>
                        <div class="batch-detail">
                            <span>Price:</span>
                            <strong>₹${parseFloat(batch.retail_price).toFixed(2)}</strong>
                        </div>
                        <span class="batch-status-badge ${statusClass}">${statusText}</span>
                        ${discrepancyHtml}
                        ${!hasDiscrepancy ? recalcButtonHtml : ''}
                    </div>
                `;
            });

            batchesHtml += '</div>';
            $('#batchesContainer').html(batchesHtml);
            $('#batchesSection').addClass('active');
            renderBulkMoveControls();
        }

        function displayBills(bills) {
            currentBillsData = Array.isArray(bills) ? bills : [];
            $('#billCount').text(bills.length + ' bill' + (bills.length !== 1 ? 's' : ''));
            const totalQuantity = bills.reduce(function (sum, bill) {
                return sum + (parseFloat(bill.quantity) || 0);
            }, 0);

            if (bills.length === 0) {
                $('#billsTableContainer').html(`
                    <div class="no-results">
                        <i class="fas fa-inbox"></i>
                        <p>No bills found using this medicine</p>
                    </div>
                `);
                $('#billsSection').addClass('active');
                renderBulkMoveBillList();
                return;
            }

            let tableHtml = `
                <table class="bills-table">
                    <thead>
                        <tr>
                            <th>Bill ID</th>
                            <th>Bill Date</th>
                            <th>Patient / Direct Sale</th>
                            <th>Batch Number</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            bills.forEach(function (bill) {
                const billDate = new Date(bill.sale_date).toLocaleDateString('en-IN');
                const patientName = bill.patient_name ? escapeHtml(bill.patient_name) : '<em>Direct Sale</em>';
                const patientIdText = bill.patient_id
                    ? `<small style="display:block; color:#6b7280; margin-top:3px;">ID: ${escapeHtml(bill.patient_id)}</small>`
                    : '<small style="display:block; color:#6b7280; margin-top:3px;">No Patient ID</small>';
                const expiryDate = new Date(bill.expiry_date).toLocaleDateString('en-IN');
                const daysSinceExpiry = Math.floor((new Date() - new Date(bill.expiry_date)) / (1000 * 60 * 60 * 24));
                let expiryStatus = '';

                if (new Date(bill.expiry_date) < new Date()) {
                    expiryStatus = ` <span style="color: #dc3545; font-weight: bold;">(Expired ${Math.abs(daysSinceExpiry)} days ago)</span>`;
                }

                tableHtml += `
                    <tr>
                        <td><a href="view_pharmacy_bill.php?bill_id=${escapeHtml(bill.bill_id)}" class="bill-id-link" target="_blank">${escapeHtml(bill.bill_id)}</a></td>
                        <td>${billDate}</td>
                        <td>${patientName}${patientIdText}</td>
                        <td>
                            <div class="batch-info">
                                ${escapeHtml(bill.batch_number)}
                                <span class="batch-info-expiry">Exp: ${expiryDate}${expiryStatus}</span>
                            </div>
                        </td>
                        <td><span class="quantity-badge">${bill.quantity}</span></td>
                        <td>₹${parseFloat(bill.unit_price).toFixed(2)}</td>
                        <td class="price-info"><strong>₹${parseFloat(bill.final_amount).toFixed(2)}</strong></td>
                        <td>
                            <span style="padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; ${bill.payment_status === 'PAID' ? 'background: #d4edda; color: #155724;' : 'background: #fff3cd; color: #856404;'}">
                                ${escapeHtml(bill.payment_status)}
                            </span>
                        </td>
                        <td>
                            <button class="btn-edit-batch" onclick="openEditModal('${escapeHtml(bill.bill_id)}', '${escapeHtml(bill.batch_number)}', ${bill.item_id || 0})">Edit Batch</button>
                        </td>
                    </tr>
                `;
            });

            tableHtml += `
                    </tbody>
                    <tfoot>
                        <tr style="background: #f1f5f9; font-weight: 700;">
                            <th colspan="4" style="text-align: right;">Total Quantity</th>
                            <th><span class="quantity-badge">${totalQuantity}</span></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                </table>
            `;

            $('#billsTableContainer').html(tableHtml);
            $('#billsSection').addClass('active');
            renderBulkMoveBillList();
        }

        function renderBulkMoveControls() {
            if (!currentBatchesData.length) {
                $('#bulkMoveSection').removeClass('active');
                return;
            }

            const sourceSelect = $('#sourceBatchSelect');
            sourceSelect.empty();
            sourceSelect.append('<option value="">-- Select source batch --</option>');

            currentBatchesData.forEach(function(batch) {
                const remainingQty = (parseInt(batch.initial_quantity) || 0) - (parseInt(batch.total_sold) || 0);
                sourceSelect.append(`<option value="${batch.batch_id}">${escapeHtml(batch.batch_number)} (Remaining: ${remainingQty})</option>`);
            });

            $('#targetBatchSelect').html('<option value="">-- Select target batch --</option>');
            $('#bulkMoveBillsContainer').html('<div class="no-results"><p>Select source batch to view bill list.</p></div>');
            $('#bulkMoveCount').text('0 selected');
            $('#bulkMoveSummary strong').first().text('0');
            $('#bulkMoveQty').text('0');
            $('#bulkMoveSection').addClass('active');
        }

        function renderTargetBatchOptions(sourceBatchId) {
            const targetSelect = $('#targetBatchSelect');
            targetSelect.empty();
            targetSelect.append('<option value="">-- Select target batch --</option>');

            currentBatchesData
                .filter(batch => String(batch.batch_id) !== String(sourceBatchId))
                .forEach(function(batch) {
                    const remainingQty = (parseInt(batch.initial_quantity) || 0) - (parseInt(batch.total_sold) || 0);
                    targetSelect.append(`<option value="${batch.batch_id}">${escapeHtml(batch.batch_number)} (Remaining: ${remainingQty})</option>`);
                });
        }

        function renderBulkMoveBillList() {
            const sourceBatchId = $('#sourceBatchSelect').val();

            if (!sourceBatchId) {
                $('#bulkMoveBillsContainer').html('<div class="no-results"><p>Select source batch to view bill list.</p></div>');
                $('#bulkMoveCount').text('0 selected');
                $('#bulkMoveSummary strong').first().text('0');
                $('#bulkMoveQty').text('0');
                return;
            }

            const sourceBills = currentBillsData.filter(function(bill) {
                return String(bill.batch_id) === String(sourceBatchId);
            });

            if (!sourceBills.length) {
                $('#bulkMoveBillsContainer').html('<div class="no-results"><p>No sale bills found for selected source batch.</p></div>');
                $('#bulkMoveCount').text('0 selected');
                $('#bulkMoveSummary strong').first().text('0');
                $('#bulkMoveQty').text('0');
                return;
            }

            let html = `
                <table class="bulk-move-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAllBatchBills"></th>
                            <th>Bill ID</th>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            sourceBills.forEach(function(bill) {
                const billDate = new Date(bill.sale_date).toLocaleDateString('en-IN');
                const patientName = bill.patient_name ? escapeHtml(bill.patient_name) : 'Direct Sale';
                html += `
                    <tr>
                        <td><input type="checkbox" class="batch-bill-checkbox" value="${escapeHtml(bill.bill_id)}" data-quantity="${escapeHtml(bill.quantity)}"></td>
                        <td>${escapeHtml(bill.bill_id)}</td>
                        <td>${billDate}</td>
                        <td>${patientName}</td>
                        <td>${escapeHtml(bill.quantity)}</td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            $('#bulkMoveBillsContainer').html(html);
            updateBulkMoveCount();
        }

        function updateBulkMoveCount() {
            const selectedBills = Array.from(document.querySelectorAll('.batch-bill-checkbox:checked'));
            const selectedCount = selectedBills.length;
            const selectedQty = selectedBills.reduce(function(sum, checkbox) {
                return sum + Number(checkbox.dataset.quantity || 0);
            }, 0);
            $('#bulkMoveCount').text(`${selectedCount} selected`);
            $('#bulkMoveSummary strong').first().text(selectedCount);
            $('#bulkMoveQty').text(selectedQty);
        }

        function moveSelectedBatchBills() {
            const sourceBatchId = $('#sourceBatchSelect').val();
            const targetBatchId = $('#targetBatchSelect').val();
            const selectedBillCheckboxes = Array.from(document.querySelectorAll('.batch-bill-checkbox:checked'));
            const selectedBillIds = selectedBillCheckboxes.map(function(el) {
                return el.value;
            });
            const selectedQty = selectedBillCheckboxes.reduce(function(sum, checkbox) {
                return sum + Number(checkbox.dataset.quantity || 0);
            }, 0);
            const selectedMedicine = $('#medicineSelect').select2('data');
            const itemId = selectedMedicine && selectedMedicine.length ? selectedMedicine[0].id : null;
            const sourceBatchName = $('#sourceBatchSelect option:selected').text();
            const targetBatchName = $('#targetBatchSelect option:selected').text();

            if (!itemId) {
                showAlert('error', 'Please select medicine first');
                return;
            }

            if (!sourceBatchId) {
                showAlert('error', 'Please select source batch');
                return;
            }

            if (!targetBatchId) {
                showAlert('error', 'Please select target batch');
                return;
            }

            if (String(sourceBatchId) === String(targetBatchId)) {
                showAlert('warning', 'Source and target batch cannot be same');
                return;
            }

            if (!selectedBillIds.length) {
                showAlert('error', 'Please select at least one bill to move');
                return;
            }

            if (!confirm(`Move ${selectedBillIds.length} selected bill(s) with total quantity ${selectedQty} from ${sourceBatchName} to ${targetBatchName}?`)) {
                return;
            }

            $.ajax({
                url: '../../includes/helpers/update_batch_bills_bulk.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    item_id: itemId,
                    source_batch_id: sourceBatchId,
                    target_batch_id: targetBatchId,
                    bill_ids: JSON.stringify(selectedBillIds)
                },
                success: function(response) {
                    if (response.status === 'success') {
                        showAlert('success', response.message || 'Selected bills moved successfully');
                        refreshMedicineData(itemId);
                    } else {
                        showAlert('error', response.message || 'Failed to move selected bills');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('error', 'Error: ' + (xhr.responseJSON?.message || error || 'Unknown error'));
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
        }

		let currentEditData = {};

		function openEditModal(billId, currentBatch, itemId) {
			currentEditData = { billId, currentBatch, itemId };
			
			$('#modalBillId').text(billId);
			$('#modalCurrentBatch').text(currentBatch);
			
			// Populate batch dropdown with available batches
			const selectedOption = $('#medicineSelect').select2('data');
			if (selectedOption && selectedOption.length > 0) {
				// Get batches from the currently displayed batches section
				const batchCards = document.querySelectorAll('.batch-card');
				const newBatchSelect = $('#newBatchSelect');
				newBatchSelect.html('<option value="">-- Choose a batch --</option>');
				
				batchCards.forEach(function(card) {
					const batchNumber = card.querySelector('.batch-number')?.textContent.trim();
					const batchDetails = card.querySelector('.batch-detail');
					if (batchNumber) {
						newBatchSelect.append(`<option value="${batchNumber}">${batchNumber}</option>`);
					}
				});
			}
			
			$('#editBatchModal').addClass('show');
		}

		function closeEditModal() {
			$('#editBatchModal').removeClass('show');
			currentEditData = {};
		}

		$(document).on('change', '#newBatchSelect', function() {
			const selectedBatch = $(this).val();
			if (selectedBatch) {
				// Find the batch card with this batch number
				const batchCard = Array.from(document.querySelectorAll('.batch-card')).find(card => {
					return card.querySelector('.batch-number')?.textContent.trim() === selectedBatch;
				});
				
				if (batchCard) {
					const details = batchCard.querySelectorAll('.batch-detail');
					const expiryText = Array.from(details).find(d => d.textContent.includes('Expiry:'))?.textContent.replace('Expiry:', '').trim() || 'N/A';
					const qtyText = Array.from(details).find(d => d.textContent.includes('Current Qty:'))?.textContent.replace('Current Qty:', '').trim() || '0';
					const priceText = Array.from(details).find(d => d.textContent.includes('Price:'))?.textContent.replace('Price:', '').replace('₹', '').trim() || '0';
					
					$('#detailExpiry').text(expiryText);
					$('#detailQty').text(qtyText);
					$('#detailPrice').text(priceText);
					$('#batchDetails').show();
				}
			} else {
				$('#batchDetails').hide();
			}
		});

		function updateBatchInBill() {
			const newBatch = $('#newBatchSelect').val();
			
			if (!newBatch) {
				showAlert('error', 'Please select a new batch');
				return;
			}
			
			if (newBatch === currentEditData.currentBatch) {
				showAlert('warning', 'Please select a different batch');
				return;
			}
			
			// Send update request
			$.ajax({
				url: '../../includes/helpers/update_bill_batch.php',
				type: 'POST',
				dataType: 'json',
				data: {
					bill_id: currentEditData.billId,
					new_batch: newBatch,
					item_id: currentEditData.itemId
				},
				success: function(response) {
					if (response.status === 'success') {
                        const itemIdToRefresh = currentEditData.itemId;
						showAlert('success', 'Batch updated successfully!');
						closeEditModal();
                        // Refresh both batch container and bills table
                        refreshMedicineData(itemIdToRefresh);
					} else {
						showAlert('error', response.message || 'Failed to update batch');
					}
				},
				error: function(xhr, status, error) {
					showAlert('error', 'Error: ' + (xhr.responseJSON?.message || error || 'Unknown error'));
					console.error('AJAX Error:', xhr.responseText);
				}
			});
		}

		// Close modal when clicking outside of it
		$(window).click(function(event) {
			const modal = $('#editBatchModal')[0];
			if (event.target == modal) {
				closeEditModal();
			}
		});

        $(document).on('change', '#sourceBatchSelect', function() {
            renderTargetBatchOptions(this.value);
            renderBulkMoveBillList();
        });

        $(document).on('change', '#selectAllBatchBills', function() {
            $('.batch-bill-checkbox').prop('checked', this.checked);
            updateBulkMoveCount();
        });

        $(document).on('change', '.batch-bill-checkbox', function() {
            const all = document.querySelectorAll('.batch-bill-checkbox').length;
            const checked = document.querySelectorAll('.batch-bill-checkbox:checked').length;
            $('#selectAllBatchBills').prop('checked', all > 0 && all === checked);
            updateBulkMoveCount();
        });

		function fixBatchQuantity(batchId, batchNumber, expectedQty) {
			if (!confirm(`Fix quantity for batch ${batchNumber}?\n\nThis will update the current quantity to ${expectedQty} based on actual bills in the system.`)) {
				return;
			}

			$.ajax({
				url: '../../includes/helpers/fix_batch_quantity.php',
				type: 'POST',
				dataType: 'json',
				data: {
					batch_id: batchId,
					expected_quantity: expectedQty
				},
				success: function(response) {
					if (response.status === 'success') {
						showAlert('success', response.message);
						// Refresh the display
						searchBills();
					} else {
						showAlert('error', response.message || 'Failed to fix quantity');
					}
				},
				error: function(xhr, status, error) {
					showAlert('error', 'Error: ' + (xhr.responseJSON?.message || error || 'Unknown error'));
					console.error('AJAX Error:', xhr.responseText);
				}
			});
		}
	</script>
    
</body>

</html>