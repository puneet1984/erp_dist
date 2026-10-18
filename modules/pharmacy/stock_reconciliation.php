<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/stock_reconciliation_helper.php';

requirePermission('pharmacy', 'edit');

$conn = getDbConnection();

$selectedType = normalizeAdjustmentType($_GET['type'] ?? 'YEAR_END');
$selectedFinancialYear = normalizeFinancialYearValue($_GET['fy'] ?? getDefaultAdjustmentFinancialYear($selectedType));
$selectedAdjustmentId = sanitizeInteger($_GET['adjustment_id'] ?? 0, 0);

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        if (!canAccessInventoryAdjustmentForm()) {
            throw new Exception('You do not have permission to enter physical quantities.');
        }

        $selectedType = normalizeAdjustmentType($_POST['adjustment_type'] ?? 'YEAR_END');
        $selectedFinancialYear = normalizeFinancialYearValue($_POST['financial_year'] ?? getDefaultAdjustmentFinancialYear($selectedType));
        $selectedAdjustmentId = sanitizeInteger($_POST['adjustment_id'] ?? 0, 0);

        if (!$selectedAdjustmentId && 'YEAR_END' === $selectedType && $selectedFinancialYear === normalizeFinancialYearValue(getCurrentFinancialYear())) {
            throw new Exception('Year end stock reconciliation must be created for a completed past financial year.');
        }

        $conn->begin_transaction();

        if (isset($_POST['revert_adjustment'])) {
            if (!$selectedAdjustmentId) {
                throw new Exception('Adjustment record not found for revert');
            }

            $header = getAdjustmentHeader($conn, $selectedAdjustmentId);
            if (!$header) {
                throw new Exception('Adjustment record not found for revert');
            }

            $selectedFinancialYear = normalizeFinancialYearValue($header['financial_year']);
            $selectedType = normalizeAdjustmentType($header['adjustment_type']);

            if (!canRevertAdjustmentType($selectedType)) {
                throw new Exception('You do not have permission to revert this adjustment type');
            }

            revertAdjustment($conn, $selectedAdjustmentId);
            $_SESSION['success'] = 'Stock adjustment reverted and live stock restored.';
        } else {
            if (!canCreateAdjustmentType($selectedType)) {
                throw new Exception('You do not have permission for this adjustment type');
            }

            $referenceName = trim((string) ($_POST['reference_name'] ?? ''));
            $notesText = (string) ($_POST['notes'] ?? '');
            if ('EXPIRY_WRITEOFF' === $selectedType && '' === $referenceName) {
                $referenceName = 'DISPOSED';
            }

            $inventoryRows = getReconciliationBatchInventoryRows($conn);
            $payloadData = parseStockReconciliationPayload($_POST['quantity_payload'] ?? '{}');
            validateYearEndBatchReplacementPayload($conn, $payloadData, $inventoryRows, $selectedType);
            $enteredQuantities = $payloadData['existing_batches'] ?? buildEnteredQuantityMap($_POST['quantity_payload'] ?? '{}');

            $selectedAdjustmentId = saveAdjustmentHeader($conn, [
                'financial_year' => $selectedFinancialYear,
                'adjustment_type' => $selectedType,
                'adjustment_date' => $_POST['adjustment_date'] ?? date('Y-m-d'),
                'reference_name' => $referenceName,
                'notes' => $notesText,
            ]);

            saveAdjustmentItems($conn, $selectedAdjustmentId, $selectedType, $inventoryRows, $enteredQuantities, $payloadData);
            applyAdjustmentToStock($conn, $selectedAdjustmentId);

            $_SESSION['success'] = 'Stock adjustment saved and applied to live stock.';
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Error: '.$e->getMessage();
        error_log('Stock adjustment error: '.$e->getMessage());
    }

    header('Location: stock_reconciliation.php?fy='.urlencode($selectedFinancialYear).'&type='.urlencode($selectedType).'&adjustment_id='.(int) $selectedAdjustmentId);
    exit;
}

$adjustmentHeader = null;
$adjustmentItems = [];
$entryMode = 'CLASSIC';
if ($selectedAdjustmentId) {
    $adjustmentHeader = getAdjustmentHeader($conn, $selectedAdjustmentId);
    if ($adjustmentHeader) {
        $selectedFinancialYear = normalizeFinancialYearValue($adjustmentHeader['financial_year']);
        $selectedType = normalizeAdjustmentType($adjustmentHeader['adjustment_type']);
        $adjustmentItems = getAdjustmentItemsByBatch($conn, $selectedAdjustmentId);
        if (hasBatchReplacementItems($adjustmentItems)) {
            $entryMode = 'BATCH_REPLACEMENT';
        }
    } else {
        $_SESSION['error'] = 'Selected stock adjustment was not found.';
        header('Location: stock_reconciliation.php?fy='.urlencode($selectedFinancialYear).'&type='.urlencode($selectedType));
        exit;
    }
}

$labels = getAdjustmentLabels();
$financialYearOptions = getSelectableFinancialYearsForType($selectedType, $selectedFinancialYear);
$recentAdjustments = getRecentAdjustments($conn, $selectedFinancialYear, $selectedType);
$inventoryRows = getReconciliationBatchInventoryRows($conn);
$isHistoricalView = (bool) $adjustmentHeader;
$isRevertedView = $adjustmentHeader && 1 === (int) $adjustmentHeader['is_reverted'];
$canEnterPhysicalQuantity = canAccessInventoryAdjustmentForm();
$currentFinancialYear = normalizeFinancialYearValue(getCurrentFinancialYear());
$previousFinancialYear = getPreviousFinancialYearValue($currentFinancialYear);

$tableRows = [];
$totals = [
    'system' => 0,
    'entered' => 0,
    'final' => 0,
    'variance' => 0,
];

foreach ($inventoryRows as $batch) {
    $savedItem = $adjustmentItems[(int) $batch['batch_id']] ?? null;
    $systemQuantity = $savedItem ? (int) $savedItem['system_quantity'] : (int) $batch['current_quantity'];

    if ('YEAR_END' === $selectedType) {
        $enteredQuantity = $savedItem ? (int) $savedItem['entered_quantity'] : $systemQuantity;
        $finalQuantity = $savedItem ? (int) $savedItem['final_quantity'] : $systemQuantity;
    } else {
        $enteredQuantity = $savedItem ? (int) $savedItem['entered_quantity'] : 0;
        $finalQuantity = $savedItem ? (int) $savedItem['final_quantity'] : $systemQuantity;
    }

    $variance = $finalQuantity - $systemQuantity;
    $tableRows[] = [
        'batch_id' => (int) $batch['batch_id'],
        'item_id' => (int) $batch['item_id'],
        'item_name' => $batch['item_name'],
        'category' => $batch['category'],
        'active_ingredient' => $batch['active_ingredient'],
        'manufacturer' => $batch['manufacturer'],
        'batch_number' => $batch['batch_number'],
        'expiry_date' => $batch['expiry_date'],
        'purchase_rate' => $batch['purchase_rate'],
        'retail_price' => $batch['retail_price'],
        'system_quantity' => $systemQuantity,
        'entered_quantity' => $enteredQuantity,
        'final_quantity' => $finalQuantity,
        'variance' => $variance,
    ];

    $totals['system'] += $systemQuantity;
    $totals['entered'] += $enteredQuantity;
    $totals['final'] += $finalQuantity;
    $totals['variance'] += $variance;
}

$entryLabel = 'YEAR_END' === $selectedType ? 'Physical Qty' : 'Adjustment Qty';
$referenceLabel = 'FREE_ISSUE' === $selectedType ? 'Recipient / Approved By' : 'Reference';
$latestRateLookup = getLatestBatchRateLookup($conn);
$groupedRows = groupReconciliationRowsByItem($tableRows);
$groupedRows = is_array($groupedRows) ? $groupedRows : [];
$replacementMedicineGuide = [];

foreach ($groupedRows as $group) {
    $rows = isset($group['rows']) && is_array($group['rows']) ? $group['rows'] : [];
    if ([] === $rows) {
        continue;
    }

    $batches = [];
    foreach ($rows as $row) {
        $batches[] = [
            'batch_number' => (string) $row['batch_number'],
            'expiry_date' => (string) $row['expiry_date'],
            'system_quantity' => (int) $row['system_quantity'],
            'purchase_rate' => (float) $row['purchase_rate'],
            'retail_price' => (float) $row['retail_price'],
        ];
    }

    $replacementMedicineGuide[(int) $group['item_id']] = [
        'item_id' => (int) $group['item_id'],
        'item_name' => (string) $group['item_name'],
        'category' => (string) $group['category'],
        'active_ingredient' => (string) $group['active_ingredient'],
        'manufacturer' => (string) $group['manufacturer'],
        'batches' => $batches,
    ];
}

$printGroupedRows = groupReconciliationRowsByItem(array_values(array_filter($tableRows, static function ($row) {
    return (int) $row['system_quantity'] > 0;
})));
$printDate = date('d M Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Stock Reconciliation</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
	<style>
		body {
			background: #f4f7fb;
		}

		.page-shell {
			padding: 24px;
		}

		.page-card {
			background: #ffffff;
			border-radius: 18px;
			box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
			padding: 24px;
			margin-bottom: 20px;
		}

		.summary-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 16px;
			margin-bottom: 20px;
		}

		.summary-card {
			border: 1px solid #e2e8f0;
			border-radius: 14px;
			padding: 16px;
			background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
		}

		.summary-label {
			font-size: 0.82rem;
			letter-spacing: 0.05em;
			text-transform: uppercase;
			color: #64748b;
		}

		.summary-value {
			font-size: 1.7rem;
			font-weight: 700;
			color: #0f172a;
		}

		.variance-positive {
			color: #166534;
		}

		.variance-negative {
			color: #b91c1c;
		}

		.table-responsive {
			max-height: 68vh;
		}

		.table thead th {
			position: sticky;
			top: 0;
			background: #f8fafc;
			z-index: 1;
		}

		.item-meta {
			color: #64748b;
			font-size: 0.86rem;
		}

		.medicine-group-row td {
			background: #eef4ff;
			border-top: 1px solid #dbeafe;
			border-bottom: 1px solid #dbeafe;
			font-weight: 600;
			color: #1e3a8a;
		}

		.medicine-group-meta {
			display: block;
			margin-top: 4px;
			font-size: 0.85rem;
			font-weight: 400;
			color: #475569;
		}

		.batch-row td:first-child {
			padding-left: 24px;
		}

		.batch-label {
			color: #64748b;
			font-size: 0.85rem;
			font-weight: 500;
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}

		.replacement-panel {
			border: 1px solid #dbeafe;
			background: #f8fbff;
			border-radius: 14px;
			padding: 16px;
		}

		.replacement-panel .replacement-help {
			font-size: 0.9rem;
			color: #475569;
		}

		.replacement-guide {
			border: 1px solid #dbeafe;
			border-radius: 12px;
			background: #ffffff;
			padding: 14px;
		}

		.replacement-guide-title {
			font-size: 0.82rem;
			letter-spacing: 0.05em;
			text-transform: uppercase;
			color: #64748b;
			margin-bottom: 8px;
		}

		.replacement-selected-name {
			font-weight: 700;
			color: #0f172a;
		}

		.replacement-batch-chip-list {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
		}

		.replacement-batch-chip {
			border: 1px solid #dbeafe;
			border-radius: 999px;
			padding: 6px 10px;
			background: #f8fbff;
			font-size: 0.86rem;
			color: #1e3a8a;
		}

		.replacement-batch-chip strong {
			color: #0f172a;
		}

		.replacement-error {
			display: none;
			border: 1px solid #fecaca;
			background: #fef2f2;
			color: #991b1b;
			border-radius: 10px;
			padding: 10px 12px;
			font-size: 0.9rem;
		}

		.replacement-batch-row.is-invalid {
			background: #fef2f2;
		}

		.replacement-batch-row.is-invalid input {
			border-color: #dc2626;
		}

		.print-sheet-wrapper {
			display: none;
		}

		.print-sheet-only {
			font-family: Arial, sans-serif;
			margin: 10px;
			color: #111827;
			font-size: 11px;
		}

		.print-sheet-only .title {
			text-align: center;
			margin: 2px 0;
			font-size: 16px;
			font-weight: 700;
		}

		.print-sheet-only .subtitle {
			text-align: center;
			margin: 2px 0 8px;
			color: #374151;
			font-size: 11px;
		}

		.print-sheet-only .sheet-meta {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 6px;
			margin-bottom: 8px;
			font-size: 11px;
		}

		.print-sheet-only table {
			width: 100%;
			border-collapse: collapse;
			font-size: 10px;
			table-layout: auto;
		}

		.print-sheet-only .medicine-sheet-table {
			margin-bottom: 8px;
		}

		.print-sheet-only .medicine-sheet-table thead {
			display: table-header-group;
		}

		.print-sheet-only th,
		.print-sheet-only td {
			border: 1px solid #111827;
			padding: 3px 4px;
			vertical-align: middle;
			line-height: 1;
		}

		.print-sheet-only th {
			background: #f3f4f6;
			text-align: left;
		}

		.print-sheet-only .medicine-row td {
			background: #e5e7eb;
			font-weight: 700;
		}

		.print-sheet-only .medicine-name-cell {
			font-weight: 700;
			background: #e5e7eb;
			font-size: 9px;
		}

		.print-sheet-only .physical-cell {
			height: 18px;
			min-width: 64px;
		}

		.print-sheet-only .manual-entry-row td {
			height: 18px;
		}

		.print-sheet-only .print-col-auto {
			width: auto;
			white-space: nowrap;
		}

		.print-sheet-only .footer-notes {
			margin-top: 10px;
			font-size: 10px;
		}

		.print-sheet-only .sign-grid {
			margin-top: 18px;
			display: flex;
			justify-content: flex-start;
			font-size: 10px;
		}

		.print-sheet-only .sign-line {
			border-top: 1px solid #111827;
			padding-top: 5px;
			text-align: center;
			min-width: 180px;
		}

		@media print {
			@page {
				size: A4;
				margin: 15mm;
			}

			body> :not(#print-sheet-wrapper) {
				display: none !important;
			}

			#print-sheet-wrapper {
				display: block !important;
			}

			#print-sheet-wrapper .print-sheet-only {
				margin: 0;
			}

			#print-sheet-wrapper tr {
				page-break-inside: avoid;
			}

			#print-sheet-wrapper .medicine-sheet-table {
				page-break-inside: auto;
			}

			#print-sheet-wrapper .medicine-name-cell {
				break-inside: avoid;
			}
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="page-shell">
		<div class="page-card">
			<div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
				<div>
					<h2 class="mb-1">Stock Reconciliation & Adjustments</h2>
					<p class="text-muted mb-0">Use year-end reconciliation to match physical count. Use the other modes
						for expiry write-off,
						damage,
						loss,
						and approved free issue without creating fake sales or dummy patients.</p>
				</div>
				<div class="d-flex gap-2"><a href="inventory.php" class="btn btn-outline-secondary">Back to
						Inventory</a><a href="stock_replacement.php" class="btn btn-outline-success">Stock
						Replacement</a><a
						href="stock_reconciliation.php?fy=<?php echo urlencode($selectedFinancialYear); ?>&type=<?php echo urlencode($selectedType); ?>"
						class="btn btn-outline-primary">New Entry</a><button type="button" class="btn btn-outline-dark"
						onclick="openPrintSheet()">Print Count Sheet</button></div>
			</div>
			<?php if (isset($_SESSION['success'])) { ?>
			<div class="alert alert-success">
				<?php echo htmlspecialchars($_SESSION['success']);
			    unset($_SESSION['success']); ?>
			</div>
			<?php } ?>

			<?php if (isset($_SESSION['error'])) { ?>
			<div class="alert alert-danger">
				<?php echo htmlspecialchars($_SESSION['error']);
			    unset($_SESSION['error']); ?>
			</div>
			<?php } ?>

			<form method="GET" class="row g-3 align-items-end">
				<div class="col-md-3"><label for="fy" class="form-label">Financial Year</label><select id="fy" name="fy"
						class="form-select"
						data-current-fy="<?php echo htmlspecialchars($currentFinancialYear); ?>"
						data-previous-fy="<?php echo htmlspecialchars($previousFinancialYear); ?>">
						<?php foreach ($financialYearOptions as $financialYearOption) { ?>
						<option
							value="<?php echo htmlspecialchars($financialYearOption); ?>"
							<?php echo $selectedFinancialYear === $financialYearOption ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($financialYearOption); ?>
						</option>
						<?php } ?>
					</select></div>
				<div class="col-md-4"><label for="type" class="form-label">Adjustment Type</label><select id="type"
						name="type" class="form-select">
						<?php foreach ($labels as $typeKey => $typeLabel) { ?>
						<option
							value="<?php echo htmlspecialchars($typeKey); ?>"
							<?php echo $selectedType === $typeKey ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($typeLabel); ?>
						</option>
						<?php } ?>
					</select></div>
				<?php if ('YEAR_END' === $selectedType || (!$selectedAdjustmentId && 'YEAR_END' === normalizeAdjustmentType($_GET['type'] ?? 'YEAR_END'))) { ?>
				<div class="col-md-3"><label for="entry_mode" class="form-label">Entry Mode</label><select
						id="entry_mode" class="form-select"
						<?php echo $isHistoricalView ? 'disabled' : ''; ?>>
						<option value="CLASSIC" <?php echo 'CLASSIC' === $entryMode ? 'selected' : ''; ?>>Classic
							Batch Entry</option>
						<option value="BATCH_REPLACEMENT" <?php echo 'BATCH_REPLACEMENT' === $entryMode ? 'selected' : ''; ?>>Physical
							Batch Replacement</option>
					</select></div>
				<?php } ?>
				<div class="col-md-2"><button type="submit" class="btn btn-primary">Load</button></div>
				<?php if ($adjustmentHeader) { ?>
				<div class="col-md-3 text-md-end text-muted small">Current record: <strong>
						<?php echo htmlspecialchars($adjustmentHeader['adjustment_code']); ?>
					</strong><br>Created: <strong>
						<?php echo htmlspecialchars(date('d M Y H:i', strtotime($adjustmentHeader['created_at']))); ?>
					</strong>
					<?php if ($isRevertedView && !empty($adjustmentHeader['reverted_at'])) { ?>
					<br>Reverted: <strong>
						<?php echo htmlspecialchars(date('d M Y H:i', strtotime($adjustmentHeader['reverted_at']))); ?>
					</strong>
					<?php } ?>
				</div>
				<?php } ?>
			</form>
			<div class="alert alert-info mt-3 mb-0">
				<?php echo htmlspecialchars(getAdjustmentTypeHelp($selectedType)); ?>
			</div>
			<?php if ('EXPIRY_WRITEOFF' === $selectedType) { ?>
			<div class="alert alert-warning mt-3 mb-0">For replacement stock entry, use the separate <a
					href="stock_replacement.php" class="alert-link">Stock Replacement</a> page.</div>
			<?php } ?>
			<?php if ($isRevertedView) { ?>
			<div class="alert alert-secondary mt-3 mb-0">This stock adjustment has been reverted.</div>
			<?php } ?>
			<?php if (!$isHistoricalView && !$canEnterPhysicalQuantity) { ?>
			<div class="alert alert-warning mt-3 mb-0">
				You can view this page with pharmacy view permission, but physical quantity entry needs inventory add,
				edit, or delete permission.
			</div>
			<?php } ?>
		</div>
		<div class="page-card">
			<div class="summary-grid">
				<div class="summary-card">
					<div class="summary-label">Batches</div>
					<div class="summary-value">
						<?php echo count($tableRows); ?>
					</div>
				</div>
				<div class="summary-card">
					<div class="summary-label">System Qty</div>
					<div class="summary-value">
						<?php echo $totals['system']; ?>
					</div>
				</div>
				<div class="summary-card">
					<div class="summary-label">
						<?php echo htmlspecialchars($entryLabel); ?>
					</div>
					<div class="summary-value" id="entered-total">
						<?php echo $totals['entered']; ?>
					</div>
				</div>
				<div class="summary-card">
					<div class="summary-label">Final Qty</div>
					<div class="summary-value" id="final-total">
						<?php echo $totals['final']; ?>
					</div>
				</div>
				<div class="summary-card">
					<div class="summary-label">Net Change</div>
					<div class="summary-value <?php echo $totals['variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>"
						id="variance-total">
						<?php echo $totals['variance']; ?>
					</div>
				</div>
			</div>
			<form method="POST" id="adjustmentForm"><input type="hidden" name="adjustment_id"
					value="<?php echo (int) ($adjustmentHeader['adjustment_id'] ?? 0); ?>"><input
					type="hidden" name="financial_year"
					value="<?php echo htmlspecialchars($selectedFinancialYear); ?>"><input
					type="hidden" name="adjustment_type"
					value="<?php echo htmlspecialchars($selectedType); ?>"><input
					type="hidden" name="quantity_payload" id="quantity_payload">
				<div class="row g-3 mb-3">
					<div class="col-md-3"><label for="adjustment_date" class="form-label">Date</label><input type="date"
							id="adjustment_date" name="adjustment_date" class="form-control"
							value="<?php echo htmlspecialchars($adjustmentHeader['adjustment_date'] ?? date('Y-m-d')); ?>"
							<?php echo $isHistoricalView || !$canEnterPhysicalQuantity ? 'disabled' : ''; ?>>
					</div>
					<div class="col-md-4"><label for="reference_name" class="form-label">
							<?php echo htmlspecialchars($referenceLabel); ?>
						</label><input type="text" id="reference_name" name="reference_name" class="form-control"
							value="<?php echo htmlspecialchars($adjustmentHeader['reference_name'] ?? ''); ?>"
							placeholder="Optional reference"
							<?php echo $isHistoricalView || !$canEnterPhysicalQuantity ? 'disabled' : ''; ?>>
					</div>
					<div class="col-md-5"><label for="notes" class="form-label">Notes</label><input type="text"
							id="notes" name="notes" class="form-control"
							value="<?php echo htmlspecialchars($adjustmentHeader['notes'] ?? ''); ?>"
							placeholder="Optional remarks"
							<?php echo $isHistoricalView || !$canEnterPhysicalQuantity ? 'disabled' : ''; ?>>
					</div>
				</div>
				<div class="row g-3 mb-3">
					<div class="col-md-4"><label for="medicine-search" class="form-label">Medicine Search</label><select
							id="medicine-search" class="form-select" style="width: 100%;"></select></div>
					<div class="col-md-4"><label for="stock-search" class="form-label">Keyword Search</label><input
							type="search" id="stock-search" class="form-control"
							placeholder="Search ingredient, manufacturer, batch"></div>
				</div>
				<div id="replacement-panel" class="replacement-panel mb-3" style="display: none;">
					<div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-3">
						<div>
							<div class="fw-semibold">Physical Batch Replacement</div>
							<div class="replacement-help">Select one medicine, verify its current system batches below,
								then add only truly new physical batches for that medicine.</div>
						</div>
						<button type="button" class="btn btn-outline-primary btn-sm" id="add-replacement-batch">Add New
							Physical Batch</button>
					</div>
					<div id="replacement-error" class="replacement-error mb-3"></div>
					<div class="replacement-guide mb-3">
						<div class="replacement-guide-title">Selected Medicine</div>
						<div id="replacement-selected-medicine" class="replacement-selected-name text-muted">Use
							Medicine Search to choose one medicine.</div>
						<div id="replacement-selected-meta" class="small text-muted mt-1"></div>
						<div class="replacement-guide-title mt-3">Current System Batches</div>
						<div id="replacement-batch-guide" class="replacement-batch-chip-list">
							<span class="replacement-batch-chip">No medicine selected.</span>
						</div>
					</div>
					<div class="table-responsive border rounded bg-white">
						<table class="table table-sm align-middle mb-0">
							<thead>
								<tr>
									<th>Medicine</th>
									<th>Batch</th>
									<th>Expiry</th>
									<th>Qty</th>
									<th>Purchase Rate</th>
									<th>Retail Rate</th>
									<th></th>
								</tr>
							</thead>
							<tbody id="replacement-batch-body"></tbody>
						</table>
					</div>
				</div>
				<div class="table-responsive border rounded">
					<table class="table table-hover align-middle mb-0" id="stock-table"
						data-mode="<?php echo htmlspecialchars($selectedType); ?>">
						<thead>
							<tr>
								<th>Item</th>
								<th>Batch</th>
								<th>Expiry</th>
								<th>Purchase Rate</th>
								<th>Retail Rate</th>
								<th>System Qty</th>
								<th>
									<?php echo htmlspecialchars($entryLabel); ?>
								</th>
								<th>Final Qty</th>
								<th>Net Change</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($groupedRows as $group) { ?>
							<tr class="medicine-group-row"
								data-group-id="<?php echo (int) $group['item_id']; ?>">
								<td colspan="9">
									<?php echo htmlspecialchars(html_entity_decode((string) $group['item_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
									<span class="medicine-group-meta">
										<?php echo htmlspecialchars(html_entity_decode((string) $group['category'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
										<?php echo $group['active_ingredient'] ? ' | '.htmlspecialchars(html_entity_decode((string) $group['active_ingredient'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : ''; ?>
										<?php echo $group['manufacturer'] ? ' | '.htmlspecialchars(html_entity_decode((string) $group['manufacturer'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : ''; ?>
										| Batches:
										<?php echo count($group['rows']); ?>
									</span>
								</td>
							</tr>
							<?php foreach ($group['rows'] as $row) { ?>
							<tr class="batch-row"
								data-group-id="<?php echo (int) $group['item_id']; ?>"
								data-item-id="<?php echo (int) $row['item_id']; ?>"
								data-search="<?php echo htmlspecialchars(strtolower(implode(' ', [$row['item_name'], $row['batch_number'], $row['active_ingredient'], $row['manufacturer']]))); ?>">
								<td><span class="batch-label">Batch Row</span></td>
								<td>
									<?php echo htmlspecialchars($row['batch_number']); ?>
								</td>
								<td>
									<?php echo htmlspecialchars(date('d M Y', strtotime($row['expiry_date']))); ?>
								</td>
								<td>
									<?php echo number_format((float) $row['purchase_rate'], 2); ?>
								</td>
								<td>
									<?php echo number_format((float) $row['retail_price'], 2); ?>
								</td>
								<td class="system-qty">
									<?php echo (int) $row['system_quantity']; ?>
								</td>
								<td><input type="number" min="0" class="form-control entered-qty"
										data-batch-id="<?php echo (int) $row['batch_id']; ?>"
										data-system="<?php echo (int) $row['system_quantity']; ?>"
										value="<?php echo (int) $row['entered_quantity']; ?>"
										<?php echo $isHistoricalView || !$canEnterPhysicalQuantity ? 'readonly' : ''; ?>>
								</td>
								<td class="final-qty">
									<?php echo (int) $row['final_quantity']; ?>
								</td>
								<td
									class="variance-cell <?php echo $row['variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
									<?php echo (int) $row['variance']; ?>
								</td>
							</tr>
							<?php } ?>
							<?php } ?>
						</tbody>
					</table>
				</div>
				<?php if (!$isHistoricalView && canCreateAdjustmentType($selectedType) && $canEnterPhysicalQuantity) { ?>
				<div class="d-flex flex-wrap gap-2 mt-3"><button type="submit" name="apply_adjustment" value="1"
						class="btn btn-primary"
						onclick="return confirm('This will save the entry and update live stock quantities immediately. Continue?');">Save
						And Apply</button></div>
				<?php } elseif ($isHistoricalView) { ?>
				<div class="d-flex flex-wrap gap-2 mt-3">
					<?php if (!$isRevertedView && canRevertAdjustmentType($selectedType)) { ?>
					<button type="submit" name="revert_adjustment" value="1" class="btn btn-outline-danger"
						onclick="return confirm('This will reverse the full adjustment and restore the previous stock quantities. Continue?');">Revert
						Adjustment</button>
					<?php } ?>
				</div>
				<div class="alert alert-secondary mt-3 mb-0">This is a historical stock adjustment record and cannot be
					edited.</div>
				<?php } ?>
			</form>
		</div>
		<div class="page-card">
			<div class="d-flex justify-content-between align-items-center mb-3">
				<h4 class="mb-0">Recent
					<?php echo htmlspecialchars($labels[$selectedType]); ?>
					Records
				</h4><span class="text-muted small">Financial year
					<?php echo htmlspecialchars($selectedFinancialYear); ?>
				</span>
			</div>
			<div class="table-responsive">
				<table class="table table-striped align-middle mb-0">
					<thead>
						<tr>
							<th>Code</th>
							<th>Date</th>
							<th>State</th>
							<th>Reference</th>
							<th>Created</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($recentAdjustments)) { ?>
						<tr>
							<td colspan="6" class="text-center text-muted">No adjustment records found for this
								financial year and type.</td>
						</tr>
						<?php } ?>
						<?php foreach ($recentAdjustments as $recent) { ?>
						<tr>
							<td>
								<?php echo htmlspecialchars($recent['adjustment_code']); ?>
							</td>
							<td>
								<?php echo htmlspecialchars(date('d M Y', strtotime($recent['adjustment_date']))); ?>
							</td>
							<td>
								<?php echo 1 === (int) $recent['is_reverted'] ? 'Reverted' : 'Applied'; ?>
							</td>
							<td>
								<?php echo htmlspecialchars($recent['reference_name'] ?: '-'); ?>
							</td>
							<td>
								<?php echo htmlspecialchars(date('d M Y H:i', strtotime($recent['created_at']))); ?>
							</td>
							<td><a class="btn btn-sm btn-outline-primary"
									href="stock_reconciliation.php?fy=<?php echo urlencode($selectedFinancialYear); ?>&type=<?php echo urlencode($selectedType); ?>&adjustment_id=<?php echo (int) $recent['adjustment_id']; ?>">Open</a>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<div id="print-sheet-wrapper" class="print-sheet-wrapper">
		<div class="print-sheet-only">
			<div class="title">Pharmacy Stock Count Sheet</div>
			<div class="subtitle">Batch-wise physical counting format for manual entry and later reconciliation in app
			</div>
			<div class="sheet-meta">
				<div><strong>Financial Year:</strong>
					<?php echo htmlspecialchars($selectedFinancialYear); ?>
				</div>
				<div><strong>Print Date:</strong>
					<?php echo htmlspecialchars($printDate); ?>
				</div>
				<div><strong>Count Date:</strong>____________________</div>
			</div>
			<?php if (empty($printGroupedRows)) { ?>
			<table class="medicine-sheet-table">
				<tbody>
					<tr>
						<td colspan="4" style="text-align: center;">No in-stock batches available for printing.</td>
					</tr>
				</tbody>
			</table>
			<?php } ?>

			<?php if (!empty($printGroupedRows)) { ?>
			<table class="medicine-sheet-table">
				<thead>
					<tr>
						<th style="width: 40%;">Medicine</th>
						<th class="print-col-auto">Batch</th>
						<th class="print-col-auto">Expiry</th>
						<th class="print-col-auto" style="text-align: right;">Current Qty</th>
						<th class="print-col-auto">Physical Qty</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($printGroupedRows as $item) { ?>
					<?php $isFirstRow = true; ?>
					<?php foreach ($item['rows'] as $row) { ?>
					<?php $isExpiredBatch = date('Y-m', strtotime($row['expiry_date'])) < date('Y-m'); ?>
					<tr>
						<?php if ($isFirstRow) { ?>
						<td class="medicine-name-cell"
							rowspan="<?php echo count($item['rows']); ?>">
							<?php echo htmlspecialchars($item['item_name']); ?>
						</td>
						<?php $isFirstRow = false; ?>
						<?php } ?>
						<td>
							<?php echo htmlspecialchars($row['batch_number']); ?><?php echo $isExpiredBatch ? ' *' : ''; ?>
						</td>
						<td>
							<?php echo htmlspecialchars(date('m-Y', strtotime($row['expiry_date']))); ?>
						</td>
						<td style="text-align: right;">
							<?php echo (int) $row['system_quantity']; ?>
						</td>
						<td class="physical-cell"></td>
					</tr>
					<?php } ?>
					<?php } ?>
					<?php for ($manualRow = 0; $manualRow < 8; ++$manualRow) { ?>
					<tr class="manual-entry-row">
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td>&nbsp;</td>
						<td class="physical-cell"></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
			<?php } ?>
			<div class="footer-notes"><strong>Instructions:</strong><br>1. Enter physical count batch-wise in the
				printed
				sheet.<br>2. Use these values while entering stock reconciliation in the application.<br>* Expired batch
			</div>
			<div class="sign-grid">
				<div class="sign-line">Counted By</div>
			</div>
		</div>
	</div>
	<script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
	<script src="../../assets/js/cdn/select2.min.js"></script>
	<script>
		const stockTable = document.getElementById('stock-table');
		const searchInput = document.getElementById('stock-search');
		const medicineSearch = document.getElementById('medicine-search');
		const financialYearSelect = document.getElementById('fy');
		const adjustmentTypeSelect = document.getElementById('type');
		const entryModeSelect = document.getElementById('entry_mode');
		const replacementPanel = document.getElementById('replacement-panel');
		const replacementBatchBody = document.getElementById('replacement-batch-body');
		const addReplacementBatchButton = document.getElementById('add-replacement-batch');
		const replacementError = document.getElementById('replacement-error');
		const replacementSelectedMedicine = document.getElementById('replacement-selected-medicine');
		const replacementSelectedMeta = document.getElementById('replacement-selected-meta');
		const replacementBatchGuide = document.getElementById('replacement-batch-guide');
		const enteredInputs = Array.from(document.querySelectorAll('.entered-qty'));
		const payloadInput = document.getElementById('quantity_payload');
		const enteredTotalEl = document.getElementById('entered-total');
		const finalTotalEl = document.getElementById('final-total');
		const varianceTotalEl = document.getElementById('variance-total');
		const adjustmentMode = stockTable ? stockTable.dataset.mode : 'YEAR_END';
		const
			latestRateLookup = <?php echo json_encode($latestRateLookup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> ;
		const
			replacementMedicineGuide = <?php echo json_encode($replacementMedicineGuide, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> ;
		let selectedMedicineId = '';
		let selectedMedicineLabel = '';
		let replacementRowCounter = 0;

		function openPrintSheet() {
			window.print();
		}

		function getCurrentEntryMode() {
			return entryModeSelect ? entryModeSelect.value : 'CLASSIC';
		}

		function escapeHtml(value) {
			return String(value || '')
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function clearReplacementError() {
			if (!replacementError) {
				return;
			}
			replacementError.style.display = 'none';
			replacementError.textContent = '';
		}

		function showReplacementError(message) {
			if (!replacementError) {
				alert(message);
				return;
			}
			replacementError.textContent = message;
			replacementError.style.display = '';
		}

		function getSelectedMedicineData() {
			return replacementMedicineGuide[String(selectedMedicineId)] || replacementMedicineGuide[selectedMedicineId] ||
				null;
		}

		function renderReplacementMedicineGuide() {
			if (!replacementSelectedMedicine || !replacementBatchGuide) {
				return;
			}

			const medicineData = getSelectedMedicineData();
			if (!medicineData) {
				replacementSelectedMedicine.textContent = 'Use Medicine Search to choose one medicine.';
				replacementSelectedMedicine.classList.add('text-muted');
				replacementSelectedMeta.textContent = '';
				replacementBatchGuide.innerHTML = '<span class="replacement-batch-chip">No medicine selected.</span>';
				return;
			}

			replacementSelectedMedicine.textContent = medicineData.item_name || selectedMedicineLabel;
			replacementSelectedMedicine.classList.remove('text-muted');
			replacementSelectedMeta.textContent = [medicineData.category, medicineData.active_ingredient, medicineData
				.manufacturer
			].filter(Boolean).join(' | ');

			replacementBatchGuide.innerHTML = medicineData.batches.map((batch) => {
				const expiryText = batch.expiry_date ? new Date(batch.expiry_date).toLocaleDateString('en-GB', {
					day: '2-digit',
					month: 'short',
					year: 'numeric'
				}) : '-';
				return `<span class="replacement-batch-chip"><strong>${escapeHtml(batch.batch_number)}</strong> | Qty ${Number(batch.system_quantity || 0)} | Exp ${escapeHtml(expiryText)} | P ${Number(batch.purchase_rate || 0).toFixed(2)} | R ${Number(batch.retail_price || 0).toFixed(2)}</span>`;
			}).join('');
		}

		function normalizeBatchNumber(value) {
			return String(value || '').trim().toUpperCase();
		}

		function validateReplacementRows() {
			if (getCurrentEntryMode() !== 'BATCH_REPLACEMENT' || !replacementBatchBody) {
				clearReplacementError();
				return {
					valid: true
				};
			}

			clearReplacementError();
			const seenByItem = {};
			const systemBatchLookup = {};

			Object.values(replacementMedicineGuide || {}).forEach((medicine) => {
				systemBatchLookup[String(medicine.item_id)] = new Set((medicine.batches || []).map((batch) =>
					normalizeBatchNumber(batch.batch_number)));
			});

			const rows = Array.from(replacementBatchBody.querySelectorAll('.replacement-batch-row'));
			for (const row of rows) {
				row.classList.remove('is-invalid');
				const itemId = String(row.dataset.itemId || '');
				const batchInput = row.querySelector('.replacement-batch-number');
				const expiryInput = row.querySelector('.replacement-expiry');
				const qtyInput = row.querySelector('.replacement-qty');
				const batchNumber = normalizeBatchNumber(batchInput?.value || '');
				const expiryDate = String(expiryInput?.value || '').trim();
				const quantity = Number(qtyInput?.value || 0);

				if (!batchNumber) {
					row.classList.add('is-invalid');
					showReplacementError('Batch number is required for every new physical batch row.');
					if (batchInput) {
						batchInput.focus();
					}
					return {
						valid: false,
						message: 'Missing batch number'
					};
				}

				if (!expiryDate) {
					row.classList.add('is-invalid');
					showReplacementError(`Expiry date is required for batch ${batchNumber}.`);
					if (expiryInput) {
						expiryInput.focus();
					}
					return {
						valid: false,
						message: 'Missing expiry date'
					};
				}

				if (!Number.isFinite(quantity) || quantity <= 0) {
					row.classList.add('is-invalid');
					showReplacementError(`Quantity must be greater than zero for batch ${batchNumber}.`);
					if (qtyInput) {
						qtyInput.focus();
					}
					return {
						valid: false,
						message: 'Invalid quantity'
					};
				}

				if (!seenByItem[itemId]) {
					seenByItem[itemId] = new Set();
				}

				if (systemBatchLookup[itemId] && systemBatchLookup[itemId].has(batchNumber)) {
					row.classList.add('is-invalid');
					showReplacementError(
						`Batch ${batchNumber} already exists in system stock for this medicine. Enter the physical quantity in the existing row instead.`
						);
					if (batchInput) {
						batchInput.focus();
					}
					return {
						valid: false,
						message: 'Duplicate with existing system batch'
					};
				}

				if (seenByItem[itemId].has(batchNumber)) {
					row.classList.add('is-invalid');
					showReplacementError(`Batch ${batchNumber} is entered more than once for the same medicine.`);
					if (batchInput) {
						batchInput.focus();
					}
					return {
						valid: false,
						message: 'Duplicate new batch rows'
					};
				}

				seenByItem[itemId].add(batchNumber);
			}

			return {
				valid: true
			};
		}

		function syncEntryModeUI() {
			if (!replacementPanel) {
				return;
			}

			const showReplacement = adjustmentMode === 'YEAR_END' && getCurrentEntryMode() === 'BATCH_REPLACEMENT' &&
				! <?php echo $isHistoricalView ? 'true' : 'false'; ?> ;
			replacementPanel.style.display = showReplacement ? '' : 'none';
			if (!showReplacement) {
				clearReplacementError();
			}
			renderReplacementMedicineGuide();
			updateRows();
			updatePayload();
		}

		function addReplacementBatchRow() {
			if (!replacementBatchBody) {
				return;
			}
			if (!selectedMedicineId || !selectedMedicineLabel) {
				alert('Select a medicine first using Medicine Search');
				return;
			}

			const rates = latestRateLookup[String(selectedMedicineId)] || latestRateLookup[selectedMedicineId];
			const medicineData = getSelectedMedicineData();
			if (!rates) {
				alert('Latest batch rates could not be resolved from the system for this medicine');
				return;
			}
			if (!medicineData) {
				alert('Selected medicine was not found in current system stock');
				return;
			}

			const row = document.createElement('tr');
			row.className = 'replacement-batch-row';
			row.dataset.itemId = String(selectedMedicineId);
			row.dataset.rowKey = String(replacementRowCounter++);
			row.innerHTML = `
				<td>
					<div class="fw-semibold">${selectedMedicineLabel}</div>
					<div class="small text-muted">Existing system batches: ${(medicineData.batches || []).length} | Latest rate source batch: ${rates.batch_number || '-'}</div>
				</td>
				<td><input type="text" class="form-control replacement-batch-number" value=""></td>
				<td><input type="date" class="form-control replacement-expiry"></td>
				<td><input type="number" min="1" class="form-control replacement-qty" value="1"></td>
				<td><input type="number" min="0" step="0.01" class="form-control replacement-purchase-rate" value="${Number(rates.purchase_rate || 0).toFixed(2)}"></td>
				<td><input type="number" min="0" step="0.01" class="form-control replacement-retail-rate" value="${Number(rates.retail_price || 0).toFixed(2)}"></td>
				<td><button type="button" class="btn btn-outline-danger btn-sm replacement-remove">Remove</button></td>
			`;

			replacementBatchBody.appendChild(row);
			row.querySelectorAll('input').forEach((input) => {
				input.addEventListener('input', () => {
					validateReplacementRows();
					updateRows();
					updatePayload();
				});
			});
			row.querySelector('.replacement-remove').addEventListener('click', () => {
				row.remove();
				validateReplacementRows();
				updateRows();
				updatePayload();
			});

			validateReplacementRows();
			updateRows();
			updatePayload();
		}

		function collectReplacementBatches() {
			const batches = {};
			if (!replacementBatchBody) {
				return batches;
			}

			replacementBatchBody.querySelectorAll('.replacement-batch-row').forEach((row) => {
				const itemId = row.dataset.itemId;
				if (!itemId) {
					return;
				}
				if (!batches[itemId]) {
					batches[itemId] = [];
				}

				batches[itemId].push({
					batch_number: row.querySelector('.replacement-batch-number')?.value || '',
					quantity: Number(row.querySelector('.replacement-qty')?.value || 0),
					expiry_date: row.querySelector('.replacement-expiry')?.value || '',
					purchase_rate: row.querySelector('.replacement-purchase-rate')?.value || '',
					retail_price: row.querySelector('.replacement-retail-rate')?.value || ''
				});
			});

			return batches;
		}

		function syncFinancialYearForAdjustmentType() {
			if (!financialYearSelect || !adjustmentTypeSelect) {
				return;
			}

			const currentFinancialYear = financialYearSelect.dataset.currentFy || '';
			const previousFinancialYear = financialYearSelect.dataset.previousFy || '';

			if (adjustmentTypeSelect.value === 'YEAR_END' && financialYearSelect.value === currentFinancialYear &&
				previousFinancialYear) {
				financialYearSelect.value = previousFinancialYear;
			}
		}

		function updateRows() {
			let enteredTotal = 0;
			let finalTotal = 0;
			let varianceTotal = 0;

			enteredInputs.forEach((input) => {
				const row = input.closest('tr');
				const systemQty = Number(input.dataset.system || 0);
				let enteredQty = Number(input.value || 0);

				if (enteredQty < 0) {
					enteredQty = 0;
					input.value = 0;
				}

				if (adjustmentMode !== 'YEAR_END' && enteredQty > systemQty) {
					enteredQty = systemQty;
					input.value = systemQty;
				}

				const finalQty = adjustmentMode === 'YEAR_END' ? enteredQty : systemQty - enteredQty;
				const variance = finalQty - systemQty;

				row.querySelector('.final-qty').textContent = finalQty;

				const varianceCell = row.querySelector('.variance-cell');
				varianceCell.textContent = variance;
				varianceCell.classList.toggle('variance-positive', variance >= 0);
				varianceCell.classList.toggle('variance-negative', variance < 0);

				enteredTotal += enteredQty;
				finalTotal += finalQty;
				varianceTotal += variance;
			});

			if (getCurrentEntryMode() === 'BATCH_REPLACEMENT' && replacementBatchBody) {
				replacementBatchBody.querySelectorAll('.replacement-batch-row').forEach((row) => {
					const qty = Number(row.querySelector('.replacement-qty')?.value || 0);
					if (qty > 0) {
						enteredTotal += qty;
						finalTotal += qty;
						varianceTotal += qty;
					}
				});
			}

			enteredTotalEl.textContent = enteredTotal;
			finalTotalEl.textContent = finalTotal;
			varianceTotalEl.textContent = varianceTotal;
			varianceTotalEl.classList.toggle('variance-positive', varianceTotal >= 0);
			varianceTotalEl.classList.toggle('variance-negative', varianceTotal < 0);
		}

		function updatePayload() {
			if (getCurrentEntryMode() === 'BATCH_REPLACEMENT' && adjustmentMode === 'YEAR_END') {
				const payload = {
					mode: 'BATCH_REPLACEMENT',
					existing_batches: {},
					new_batches: collectReplacementBatches()
				};

				enteredInputs.forEach((input) => {
					payload.existing_batches[input.dataset.batchId] = Number(input.value || 0);
				});

				payloadInput.value = JSON.stringify(payload);
				validateReplacementRows();
				return;
			}

			const payload = {};
			enteredInputs.forEach((input) => {
				payload[input.dataset.batchId] = Number(input.value || 0);
			});
			payloadInput.value = JSON.stringify(payload);
		}

		enteredInputs.forEach((input) => {
			input.addEventListener('input', () => {
				updateRows();
				updatePayload();
			});
		});

		function applyTableFilters() {
			const query = (searchInput?.value || '').trim().toLowerCase();

			stockTable.querySelectorAll('tbody .batch-row').forEach((row) => {
				const matchesText = row.dataset.search.includes(query);
				const matchesMedicine = !selectedMedicineId || row.dataset.itemId === selectedMedicineId;
				row.style.display = matchesText && matchesMedicine ? '' : 'none';
			});

			stockTable.querySelectorAll('tbody .medicine-group-row').forEach((groupRow) => {
				const groupId = groupRow.dataset.groupId;
				const visibleBatchRow = stockTable.querySelector(
					`tbody .batch-row[data-group-id="${groupId}"]:not([style*="display: none"])`);
				groupRow.style.display = visibleBatchRow ? '' : 'none';
			});
		}

		if (searchInput) {
			searchInput.addEventListener('input', applyTableFilters);
		}

		if (adjustmentTypeSelect) {
			adjustmentTypeSelect.addEventListener('change', syncFinancialYearForAdjustmentType);
		}

		if (entryModeSelect) {
			entryModeSelect.addEventListener('change', syncEntryModeUI);
		}

		if (addReplacementBatchButton) {
			addReplacementBatchButton.addEventListener('click', addReplacementBatchRow);
		}

		if (window.jQuery && medicineSearch) {
			$('#medicine-search').select2({
				ajax: {

					url: '../../includes/helpers/medicine_helper.php',
					dataType: 'json',
					delay: 250,
					data: function(params) {
							return {
								q: params.term,
								type: 'sale'
							}

							;
						}

						,
					processResults: function(data) {
							if (!data || !data.results) {
								return {
									results: []
								}

								;
							}

							return {
								results: data.results
							}

							;
						}

						,
					cache: true
				}

				,
				minimumInputLength: 2,
				placeholder: 'Search medicine...',
				allowClear: true,
				width: 'resolve'
			});

			$('#medicine-search').on('select2:select', function(e) {
				selectedMedicineId = String(e.params.data.id || '');
				selectedMedicineLabel = String(e.params.data.text || '');
				renderReplacementMedicineGuide();
				applyTableFilters();
			});

			$('#medicine-search').on('select2:clear', function() {
				selectedMedicineId = '';
				selectedMedicineLabel = '';
				renderReplacementMedicineGuide();
				applyTableFilters();
			});
		}

		const adjustmentForm = document.getElementById('adjustmentForm');

		if (adjustmentForm) {
			adjustmentForm.addEventListener('submit', (event) => {
				const validationResult = validateReplacementRows();
				if (!validationResult.valid) {
					event.preventDefault();
					return;
				}
				updatePayload();
			});
		}

		updateRows();
		updatePayload();
		syncFinancialYearForAdjustmentType();
		syncEntryModeUI();
		renderReplacementMedicineGuide();
		applyTableFilters();
	</script>
</body>

</html>