<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

requirePermission('pharmacy', 'edit');

$conn = getDbConnection();

$search = trim(sanitizeInput($_GET['search'] ?? ''));
$onlyMismatch = isset($_GET['only_mismatch']) ? 1 : 0;
$limit = sanitizeInteger($_GET['limit'] ?? 200, 1, 2000);
if (!$limit) {
    $limit = 200;
}

$sql = "SELECT
    pb.batch_id,
    pb.batch_number,
    pb.item_id,
    pi.name AS item_name,
    pi.manufacturer,
    pb.initial_quantity,
    pb.current_quantity,
    COALESCE(pur.purchased_qty, 0) AS purchased_qty,
	GREATEST(0, pb.initial_quantity - COALESCE(pur.purchased_qty, 0)) AS opening_qty_inferred,
    COALESCE(sal.sold_qty, 0) AS sold_qty,
    COALESCE(ret.returned_qty, 0) AS supplier_return_qty,
    COALESCE(adj.adjustment_qty, 0) AS adjustment_qty,
	(CASE
		WHEN COALESCE(pur.purchased_qty, 0) > pb.initial_quantity
		THEN COALESCE(pur.purchased_qty, 0) - pb.initial_quantity
		ELSE 0
	END) AS initial_update_gap,
    (
		GREATEST(pb.initial_quantity, COALESCE(pur.purchased_qty, 0))
		- COALESCE(sal.sold_qty, 0)
		- COALESCE(ret.returned_qty, 0)
		+ COALESCE(adj.adjustment_qty, 0)
	) AS ledger_min_current,
	(CASE
		WHEN (
			GREATEST(pb.initial_quantity, COALESCE(pur.purchased_qty, 0))
			- COALESCE(sal.sold_qty, 0)
			- COALESCE(ret.returned_qty, 0)
			+ COALESCE(adj.adjustment_qty, 0)
		) > pb.current_quantity
		THEN (
			GREATEST(pb.initial_quantity, COALESCE(pur.purchased_qty, 0))
			- COALESCE(sal.sold_qty, 0)
			- COALESCE(ret.returned_qty, 0)
			+ COALESCE(adj.adjustment_qty, 0)
			- pb.current_quantity
		)
		ELSE 0
	END) AS current_update_gap,
    pb.created_at
FROM pharmacy_batches pb
INNER JOIN pharmacy_items pi ON pi.item_id = pb.item_id
LEFT JOIN (
    SELECT pti.batch_id, SUM(pti.quantity) AS purchased_qty
    FROM pharmacy_transaction_items pti
    INNER JOIN pharmacy_purchases pp ON pp.transaction_id = pti.transaction_id
    GROUP BY pti.batch_id
) pur ON pur.batch_id = pb.batch_id
LEFT JOIN (
    SELECT pti.batch_id, SUM(pti.quantity) AS sold_qty
    FROM pharmacy_transaction_items pti
    INNER JOIN pharmacy_sales ps ON ps.transaction_id = pti.transaction_id
    WHERE ps.payment_status != 'CANCELLED'
    GROUP BY pti.batch_id
) sal ON sal.batch_id = pb.batch_id
LEFT JOIN (
    SELECT sri.batch_id, SUM(sri.quantity) AS returned_qty
    FROM pharmacy_supplier_return_items sri
    GROUP BY sri.batch_id
) ret ON ret.batch_id = pb.batch_id
LEFT JOIN (
    SELECT sai.batch_id, SUM(sai.adjustment_quantity) AS adjustment_qty
    FROM pharmacy_stock_adjustment_items sai
    INNER JOIN pharmacy_stock_adjustments sa ON sa.adjustment_id = sai.adjustment_id
    WHERE sa.is_reverted = 0
    GROUP BY sai.batch_id
) adj ON adj.batch_id = pb.batch_id
WHERE 1 = 1";

$params = [];
$types = '';

if ('' !== $search) {
    $sql .= ' AND (pi.name LIKE ? OR pi.manufacturer LIKE ? OR pb.batch_number LIKE ? OR CAST(pb.batch_id AS CHAR) LIKE ?)';
    $searchLike = "%{$search}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= 'ssss';
}

if ($onlyMismatch) {
    $sql .= ' AND ((COALESCE(pur.purchased_qty, 0) > pb.initial_quantity) OR '
		.'((GREATEST(pb.initial_quantity, COALESCE(pur.purchased_qty, 0)) - COALESCE(sal.sold_qty, 0) - COALESCE(ret.returned_qty, 0) + COALESCE(adj.adjustment_qty, 0)) > pb.current_quantity))';
}

$sql .= ' ORDER BY '
	.'(CASE WHEN (GREATEST(pb.initial_quantity, COALESCE(pur.purchased_qty, 0)) - COALESCE(sal.sold_qty, 0) - COALESCE(ret.returned_qty, 0) + COALESCE(adj.adjustment_qty, 0)) > pb.current_quantity '
	.'THEN (GREATEST(pb.initial_quantity, COALESCE(pur.purchased_qty, 0)) - COALESCE(sal.sold_qty, 0) - COALESCE(ret.returned_qty, 0) + COALESCE(adj.adjustment_qty, 0) - pb.current_quantity) ELSE 0 END) DESC, '
    .'(CASE WHEN COALESCE(pur.purchased_qty, 0) > pb.initial_quantity THEN (COALESCE(pur.purchased_qty, 0) - pb.initial_quantity) ELSE 0 END) DESC, '
     .'pb.batch_id DESC';

$sql .= ' LIMIT ?';
$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    throw new Exception('Failed to prepare report query: '.$conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    throw new Exception('Failed to run report query: '.$stmt->error);
}

$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$summary = [
    'rows' => count($rows),
    'initial_gap_rows' => 0,
    'current_gap_rows' => 0,
    'initial_gap_total' => 0,
    'current_gap_total' => 0,
];

foreach ($rows as $row) {
    $initialGap = (int) $row['initial_update_gap'];
    $currentGap = (int) $row['current_update_gap'];

    if (0 !== $initialGap) {
        ++$summary['initial_gap_rows'];
    }

    if (0 !== $currentGap) {
        ++$summary['current_gap_rows'];
    }

    $summary['initial_gap_total'] += $initialGap;
    $summary['current_gap_total'] += $currentGap;
}

// Fetch purchase breakdown for affected batches
$purchasesByBatch = [];
if (!empty($rows)) {
    $batchIds = array_map(fn($r) => (int)$r['batch_id'], $rows);
    // Build the batch list as a comma-separated string for SQL
    $batchIdList = implode(',', $batchIds);
    
    // Get unique purchase-batch combinations with aggregated quantities
    $purchasesSql = "
        SELECT 
            pp.purchase_id,
            DATE_FORMAT(pp.purchase_date, '%d-%m-%Y') AS purchase_date_formatted,
            pp.purchase_date,
            SUM(pti.quantity) AS purchase_qty,
            pti.batch_id,
            pb.batch_number,
            COALESCE(s.supplier_name, 'N/A') AS supplier_name,
            COALESCE(pp.invoice_number, 'N/A') AS invoice_no
        FROM pharmacy_purchases pp
        INNER JOIN pharmacy_transaction_items pti ON pti.transaction_id = pp.transaction_id
        INNER JOIN pharmacy_batches pb ON pb.batch_id = pti.batch_id
        LEFT JOIN suppliers s ON s.supplier_id = pp.supplier_id
        WHERE pti.batch_id IN ($batchIdList)
        GROUP BY pp.purchase_id, pti.batch_id
        ORDER BY pti.batch_id, pp.purchase_date, pp.purchase_id
    ";
    
    $purchasesResult = $conn->query($purchasesSql);
    if (!$purchasesResult) {
        throw new Exception('Failed to fetch purchases: ' . $conn->error);
    }
    
    while ($purchRow = $purchasesResult->fetch_assoc()) {
        $batchId = (int)$purchRow['batch_id'];
        if (!isset($purchasesByBatch[$batchId])) {
            $purchasesByBatch[$batchId] = [];
        }
        $purchasesByBatch[$batchId][] = $purchRow;
    }
    
    // Calculate FIFO sales allocation per purchase
    foreach ($purchasesByBatch as $batchId => &$purchases) {
        // Get total batch sales
        $totalSalesStmt = $conn->prepare("
            SELECT COALESCE(SUM(pti_s.quantity), 0) AS total_sale_qty
            FROM pharmacy_transaction_items pti_s
            JOIN pharmacy_sales ps_s ON ps_s.transaction_id = pti_s.transaction_id 
            WHERE pti_s.batch_id = ? AND ps_s.payment_status != 'CANCELLED'
        ");
        $totalSalesStmt->bind_param('i', $batchId);
        $totalSalesStmt->execute();
        $totalSalesResult = $totalSalesStmt->get_result();
        $totalBatchSales = (int)$totalSalesResult->fetch_assoc()['total_sale_qty'];
        
        $cumulative = 0;
        // Allocate sales to each purchase using FIFO
        foreach ($purchases as &$purchase) {
            $purchaseQty = (int)$purchase['purchase_qty'];
            $cumBefore = $cumulative;
            $cumAfter = $cumulative + $purchaseQty;
            
            // Sales from this purchase = sales that fall within cumBefore to cumAfter
            $saleFromThisPurchase = max(0, min($totalBatchSales, $cumAfter) - max(0, $cumBefore));
            $remaining = $purchaseQty - $saleFromThisPurchase;
            
            $purchase['sale_qty'] = $saleFromThisPurchase;
            $purchase['remaining'] = $remaining;
            
            $cumulative = $cumAfter;
        }
    }
}

?>
<!DOCTYPE html>
<html>

<head>
	<title>Purchase vs Batch Mismatch Report</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.report-shell {
			max-width: auto;
			margin: 20px auto;
			padding: 0 16px;
		}

		.card {
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
			margin-bottom: 16px;
			padding: 16px;
		}

		.filters {
			display: flex;
			flex-wrap: wrap;
			gap: 12px;
			align-items: center;
		}

		.filters input[type="text"],
		.filters input[type="number"] {
			height: 36px;
			padding: 6px 10px;
		}

		.filters button,
		.filters a {
			height: 36px;
			display: inline-flex;
			align-items: center;
			padding: 0 12px;
			border-radius: 6px;
			border: 1px solid #d0d7de;
			text-decoration: none;
			background: #f6f8fa;
			color: #1f2328;
		}

		.filters button {
			cursor: pointer;
		}

		.summary-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 12px;
		}

		.summary-tile {
			background: #f6f8fa;
			border-radius: 6px;
			padding: 10px;
		}

		.summary-tile .label {
			color: #57606a;
			font-size: 12px;
		}

		.summary-tile .value {
			font-size: 20px;
			font-weight: 700;
		}

		.table-wrap {
			overflow-x: auto;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			font-size: 13px;
		}

		th,
		td {
			border: 1px solid #d0d7de;
			padding: 8px;
			text-align: left;
			white-space: nowrap;
		}

		th {
			position: sticky;
			top: 0;
			background: #f6f8fa;
			z-index: 1;
		}

		.bad {
			color: #b42318;
			font-weight: 700;
		}

		.ok {
			color: #137333;
			font-weight: 700;
		}

		.muted {
			color: #57606a;
			font-size: 12px;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="report-shell">
		<div class="card">
			<h2>Purchase vs Batch Mismatch Report</h2>
			<p class="muted">Focused check for missing batch updates after purchase posting.
				If purchase/transaction rows exist but batch quantities were not incremented, this report shows positive
				Update Gaps in Initial and/or Current stock.</p>

			<form method="GET" class="filters">
				<input type="text" name="search" placeholder="Search item, manufacturer, batch, batch id"
					value="<?php echo htmlspecialchars($search); ?>">
				<input type="number" name="limit" min="1" max="2000"
					value="<?php echo (int) $limit; ?>">
				<label>
					<input type="checkbox" name="only_mismatch" value="1"
						<?php echo $onlyMismatch ? 'checked' : ''; ?>>
					Show only mismatches
				</label>
				<button type="submit">Run</button>
				<a href="purchase_batch_mismatch_report.php">Reset</a>
			</form>
		</div>

		<div class="card summary-grid">
			<div class="summary-tile">
				<div class="label">Rows</div>
				<div class="value">
					<?php echo (int) $summary['rows']; ?>
				</div>
			</div>
			<div class="summary-tile">
				<div class="label">Initial Update Gap Rows</div>
				<div class="value">
					<?php echo (int) $summary['initial_gap_rows']; ?>
				</div>
			</div>
			<div class="summary-tile">
				<div class="label">Current Update Gap Rows</div>
				<div class="value">
					<?php echo (int) $summary['current_gap_rows']; ?>
				</div>
			</div>
			<div class="summary-tile">
				<div class="label">Initial Update Gap Total</div>
				<div
					class="value <?php echo 0 !== (int) $summary['initial_gap_total'] ? 'bad' : 'ok'; ?>">
					<?php echo (int) $summary['initial_gap_total']; ?>
				</div>
			</div>
			<div class="summary-tile">
				<div class="label">Current Update Gap Total</div>
				<div
					class="value <?php echo 0 !== (int) $summary['current_gap_total'] ? 'bad' : 'ok'; ?>">
					<?php echo (int) $summary['current_gap_total']; ?>
				</div>
			</div>
		</div>

		<div class="card table-wrap">
			<table>
				<thead>
					<tr>
						<th>Batch ID</th>
						<th>Batch Number</th>
						<th>Item</th>
						<th>Manufacturer</th>
						<th>Purchased</th>
						<th>Stored Initial</th>
						<th>Initial Update Gap</th>
						<th>Sold</th>
						<th>Supplier Return</th>
						<th>Stock Adj (+/-)</th>
						<th>Ledger Min Current</th>
						<th>Stored Current</th>
						<th>Current Update Gap</th>
						<th>Created</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($rows)) { ?>
					<tr>
						<td colspan="14">No rows found</td>
					</tr>
					<?php } ?>

					<?php foreach ($rows as $row) {
					    $initialGap = (int) $row['initial_update_gap'];
					    $currentGap = (int) $row['current_update_gap'];
					    ?>
					<tr>
						<td><?php echo (int) $row['batch_id']; ?>
						</td>
						<td><?php echo htmlspecialchars($row['batch_number']); ?>
						</td>
						<td><?php echo htmlspecialchars($row['item_name']); ?>
							(<?php echo (int) $row['item_id']; ?>)
						</td>
						<td><?php echo htmlspecialchars((string) $row['manufacturer']); ?>
						</td>
						<td><?php echo (int) $row['purchased_qty']; ?>
						</td>
						<td><?php echo (int) $row['initial_quantity']; ?>
						</td>
						<td
							class="<?php echo 0 !== $initialGap ? 'bad' : 'ok'; ?>">
							<?php echo $initialGap; ?>
						</td>
						<td><?php echo (int) $row['sold_qty']; ?>
						</td>
						<td><?php echo (int) $row['supplier_return_qty']; ?>
						</td>
						<td><?php echo (int) $row['adjustment_qty']; ?>
						</td>
						<td><?php echo (int) $row['ledger_min_current']; ?>
						</td>
						<td><?php echo (int) $row['current_quantity']; ?>
						</td>
						<td
							class="<?php echo 0 !== $currentGap ? 'bad' : 'ok'; ?>">
							<?php echo $currentGap; ?>
						</td>
						<td><?php echo htmlspecialchars((string) $row['created_at']); ?>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>

		<!-- Purchase Timeline Breakdown -->
		<div class="card">
			<h3>Purchase Timeline Breakdown (Where Quantities Come From)</h3>
			<p class="muted">Detailed record of each purchase order for affected batches</p>
			
			<div class="table-wrap">
				<table style="font-size: 12px;">
					<thead>
						<tr>
							<th>Purchase Date</th>
							<th>Supplier Name</th>
							<th>Invoice No</th>
							<th>Batch ID</th>
							<th>Batch Number</th>
							<th>Purchase Qty</th>
							<th>Sale Qty (Batch)</th>
							<th>Remaining</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$purchaseCount = 0;
						foreach ($purchasesByBatch as $batchId => $purchases) {
							foreach ($purchases as $purchase) {
								$purchaseCount++;
								?>
								<tr>
									<td><?php echo htmlspecialchars($purchase['purchase_date_formatted']); ?></td>
									<td><?php echo htmlspecialchars($purchase['supplier_name']); ?></td>
									<td><?php echo htmlspecialchars($purchase['invoice_no']); ?></td>
									<td><?php echo (int)$purchase['batch_id']; ?></td>
									<td><?php echo htmlspecialchars($purchase['batch_number']); ?></td>
									<td><?php echo (int)$purchase['purchase_qty']; ?></td>
									<td><?php echo (int)$purchase['sale_qty']; ?></td>
									<td style="font-weight: 700; background: #fff3cd;">
										<?php echo (int)$purchase['remaining']; ?>
									</td>
								</tr>
								<?php
							}
						}
						if ($purchaseCount === 0) {
							echo '<tr><td colspan="8">No purchase data found</td></tr>';
						}
						?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</body>

</html>