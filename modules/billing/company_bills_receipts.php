<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!canListBills()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();
$companyId = (int) ($_GET['company_id'] ?? 0);
$fromDate = sanitizeInput($_GET['from_date'] ?? '');
$toDate = sanitizeInput($_GET['to_date'] ?? '');

if ('POST' === $_SERVER['REQUEST_METHOD'] && 'reconcile_balance' === ($_POST['action'] ?? '')) {
    if (!checkPermission('bills', 'edit')) {
        header('Location: ../auth/unauthorized.php');
        exit;
    }

    $companyId = (int) ($_POST['company_id'] ?? 0);
    if ($companyId <= 0) {
        header('Location: company_bills_receipts.php?error=invalid_company');
        exit;
    }

    $stmt = $conn->prepare("UPDATE company_accounts ca
        SET current_balance = (
            SELECT COALESCE(SUM(pl.balance_amount), 0)
            FROM payment_ledger pl
            JOIN bills b ON pl.bill_id = b.bill_id
            WHERE pl.company_id = ca.company_id
              AND (b.bill_type IS NULL OR b.bill_type <> 'ADVANCE')
              AND b.status = 'ACTIVE'
        )
        WHERE ca.company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();

    header('Location: company_bills_receipts.php?company_id='.$companyId.'&reconciled=1');
    exit;
}

$companies = $conn->query("SELECT company_id, company_name FROM company_accounts ORDER BY company_name")
    ->fetch_all(MYSQLI_ASSOC);
$companyName = '';
foreach ($companies as $company) {
    if ($companyId === (int) $company['company_id']) {
        $companyName = $company['company_name'];
        break;
    }
}
$summary = null;
$bills = [];
$receipts = [];
$advanceTotals = null;
$advanceTxns = [];

if ($companyId > 0) {
    $companyCondition = "(pl.company_id = ? OR (b.bill_type = 'ADVANCE' AND JSON_UNQUOTE(JSON_EXTRACT(b.payment_details, '$.company_name')) = ?))";
    $filters = [$companyCondition];
    $types = 'is';
    $params = [$companyId, $companyName];

    if ('' !== $fromDate) {
        $filters[] = 'b.bill_date >= ?';
        $types .= 's';
        $params[] = $fromDate;
    }
    if ('' !== $toDate) {
        $filters[] = 'b.bill_date <= ?';
        $types .= 's';
        $params[] = $toDate;
    }
    $billWhere = implode(' AND ', $filters);

    $summarySql = "SELECT ca.company_name, ca.credit_limit, ca.current_balance,
                   COUNT(DISTINCT pl.bill_id) AS bill_count,
                   COALESCE(SUM(CASE WHEN b.bill_type = 'ADVANCE' THEN 0 ELSE pl.amount_due END), 0) AS total_billed,
                   COALESCE(SUM(CASE WHEN b.bill_type = 'ADVANCE' THEN 0 ELSE pl.amount_paid END), 0) AS total_received,
                   COALESCE(SUM(CASE WHEN b.bill_type = 'ADVANCE' THEN pl.amount_paid ELSE 0 END), 0) AS total_advance,
                   COALESCE(SUM(CASE WHEN b.bill_type = 'ADVANCE' THEN 0 ELSE pl.balance_amount END), 0) AS total_balance
                   FROM company_accounts ca
                   LEFT JOIN payment_ledger pl ON ca.company_id = pl.company_id
                   LEFT JOIN bills b ON pl.bill_id = b.bill_id
                   WHERE {$billWhere}
                   GROUP BY ca.company_id, ca.company_name, ca.credit_limit, ca.current_balance";
    $stmt = $conn->prepare($summarySql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();

    $billSql = "SELECT b.bill_id, b.bill_date, b.bill_type, b.final_amount, p.patient_id, p.name AS patient_name,
                pl.amount_due, pl.amount_paid, pl.balance_amount, pl.payment_status, pl.payment_due_date
                FROM payment_ledger pl
                JOIN bills b ON pl.bill_id = b.bill_id
                JOIN patients p ON b.patient_id = p.patient_id
                WHERE {$billWhere}
                ORDER BY b.bill_date DESC, b.bill_id DESC";
    $stmt = $conn->prepare($billSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $receiptFilters = [$companyCondition];
    $receiptTypes = 'is';
    $receiptParams = [$companyId, $companyName];
    if ('' !== $fromDate) {
        $receiptFilters[] = 'DATE(pc.payment_date) >= ?';
        $receiptTypes .= 's';
        $receiptParams[] = $fromDate;
    }
    if ('' !== $toDate) {
        $receiptFilters[] = 'DATE(pc.payment_date) <= ?';
        $receiptTypes .= 's';
        $receiptParams[] = $toDate;
    }
    $receiptSql = 'SELECT pc.collection_id, pc.bill_id, pc.amount, pc.payment_date, pc.reference_no, pc.notes,
                   b.bill_type, pt.type_name AS payment_mode, u.username AS collected_by_name
                   FROM payment_collections pc
                   JOIN payment_ledger pl ON pc.ledger_id = pl.ledger_id
                   JOIN bills b ON pc.bill_id = b.bill_id
                   JOIN payment_types pt ON pc.payment_type_id = pt.payment_type_id
                   LEFT JOIN users u ON pc.collected_by = u.user_id
                   WHERE '.implode(' AND ', $receiptFilters).'
                   ORDER BY pc.payment_date DESC, pc.collection_id DESC';
    $stmt = $conn->prepare($receiptSql);
    $stmt->bind_param($receiptTypes, ...$receiptParams);
    $stmt->execute();
    $receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Advance balance is all-time; the transaction list follows the date filter
    $stmt = $conn->prepare("SELECT
            COALESCE(SUM(CASE WHEN txn_type = 'RECEIPT' THEN amount END), 0) AS deposited,
            COALESCE(SUM(CASE WHEN txn_type = 'ADJUSTMENT' THEN amount END), 0) AS adjusted,
            COALESCE(SUM(CASE WHEN txn_type = 'REFUND' THEN amount END), 0) AS refunded
        FROM company_advances WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $advanceTotals = $stmt->get_result()->fetch_assoc();
    $advanceTotals['balance'] = $advanceTotals['deposited'] - $advanceTotals['adjusted'] - $advanceTotals['refunded'];

    $advFilters = ['a.company_id = ?'];
    $advTypes = 'i';
    $advParams = [$companyId];
    if ('' !== $fromDate) {
        $advFilters[] = 'DATE(a.txn_date) >= ?';
        $advTypes .= 's';
        $advParams[] = $fromDate;
    }
    if ('' !== $toDate) {
        $advFilters[] = 'DATE(a.txn_date) <= ?';
        $advTypes .= 's';
        $advParams[] = $toDate;
    }
    $stmt = $conn->prepare('SELECT a.txn_date, a.txn_type, a.amount, a.bill_id, a.reference_no, a.notes,
            pt.type_name AS payment_mode, u.username
        FROM company_advances a
        LEFT JOIN payment_types pt ON a.payment_type_id = pt.payment_type_id
        LEFT JOIN users u ON a.created_by = u.user_id
        WHERE '.implode(' AND ', $advFilters).'
        ORDER BY a.txn_date DESC, a.txn_id DESC');
    $stmt->bind_param($advTypes, ...$advParams);
    $stmt->execute();
    $advanceTxns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Company Bills & Receipts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .report-container { max-width: 1400px; }
        .summary-value { font-size: 1.3rem; font-weight: 600; }
        .table-responsive { max-height: 500px; }
    </style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>
    <main class="container report-container py-4">
        <h2 class="mb-4">Company Bills & Receipts</h2>
        <form method="GET" class="row g-3 align-items-end mb-4">
            <div class="col-md-5">
                <label for="company_id" class="form-label">Company</label>
                <select name="company_id" id="company_id" class="form-select" required>
                    <option value="">Select company</option>
                    <?php foreach ($companies as $company) { ?>
                    <option value="<?php echo $company['company_id']; ?>" <?php echo $companyId === (int) $company['company_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($company['company_name']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="from_date" class="form-label">Bill / Receipt From</label>
                <input type="date" class="form-control" name="from_date" id="from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>
            <div class="col-md-2">
                <label for="to_date" class="form-label">Bill / Receipt To</label>
                <input type="date" class="form-control" name="to_date" id="to_date" value="<?php echo htmlspecialchars($toDate); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">View Report</button>
                <a href="company_bills_receipts.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>

        <?php if ($summary) { ?>
        <?php if (isset($_GET['reconciled'])) { ?>
        <div class="alert alert-success">Credit balance reconciled from active, non-advance ledger entries.</div>
        <?php } ?>
        <section class="row g-3 mb-4">
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Bills</div><div class="summary-value"><?php echo $summary['bill_count']; ?></div><small>₹<?php echo number_format($summary['total_billed'], 2); ?> billed</small></div></div>
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Receipts</div><div class="summary-value text-success">₹<?php echo number_format($summary['total_received'], 2); ?></div></div></div>
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Outstanding</div><div class="summary-value text-danger">₹<?php echo number_format($summary['total_balance'], 2); ?></div></div></div>
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Advance Balance</div><div class="summary-value text-info">₹<?php echo number_format($advanceTotals['balance'], 2); ?></div><small>₹<?php echo number_format($advanceTotals['deposited'], 2); ?> deposited overall</small></div></div>
        </section>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <p class="text-muted mb-0">Credit Limit: ₹<?php echo number_format($summary['credit_limit'], 2); ?> | Credit Usage: ₹<?php echo number_format($summary['current_balance'], 2); ?></p>
            <?php if (checkPermission('bills', 'edit')) { ?>
            <form method="POST" onsubmit="return confirm('Set credit usage to the outstanding balance calculated from active, non-advance ledger entries?');">
                <input type="hidden" name="action" value="reconcile_balance">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Reconcile Credit Balance</button>
            </form>
            <?php } ?>
        </div>

        <h4>Bills</h4>
        <div class="table-responsive mb-4">
            <table class="table table-striped table-bordered">
                <thead><tr><th>Date</th><th>Bill #</th><th>Entry Type</th><th>Patient</th><th>Due</th><th>Received</th><th>Balance</th><th>Status</th><th>Due Date</th></tr></thead>
                <tbody><?php foreach ($bills as $bill) { ?><tr>
                    <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($bill['bill_date']))); ?></td>
                    <td><a href="view_bill.php?id=<?php echo urlencode($bill['bill_id']); ?>"><?php echo htmlspecialchars($bill['bill_id']); ?></a></td>
                    <td><?php echo 'ADVANCE' === $bill['bill_type'] ? 'Advance' : 'Bill'; ?></td>
                    <td><?php echo htmlspecialchars($bill['patient_name']); ?> <small class="text-muted">(<?php echo htmlspecialchars($bill['patient_id']); ?>)</small></td>
                    <td>₹<?php echo number_format($bill['amount_due'], 2); ?></td><td>₹<?php echo number_format($bill['amount_paid'], 2); ?></td><td>₹<?php echo number_format($bill['balance_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($bill['payment_status']); ?></td><td><?php echo $bill['payment_due_date'] ? htmlspecialchars(date('d-m-Y', strtotime($bill['payment_due_date']))) : '-'; ?></td>
                </tr><?php } ?><?php if (!$bills) { ?><tr><td colspan="9" class="text-center text-muted">No bills found for this company and date range.</td></tr><?php } ?></tbody>
            </table>
        </div>

        <h4>Receipts</h4>
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead><tr><th>Date</th><th>Bill #</th><th>Entry Type</th><th>Amount</th><th>Payment Mode</th><th>Reference</th><th>Collected By</th><th>Notes</th></tr></thead>
                <tbody><?php foreach ($receipts as $receipt) { ?><tr>
                    <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($receipt['payment_date']))); ?></td><td><?php echo htmlspecialchars($receipt['bill_id']); ?></td><td><?php echo 'ADVANCE' === $receipt['bill_type'] ? 'Advance' : 'Receipt'; ?></td><td>₹<?php echo number_format($receipt['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($receipt['payment_mode']); ?></td><td><?php echo htmlspecialchars($receipt['reference_no'] ?: '-'); ?></td><td><?php echo htmlspecialchars($receipt['collected_by_name'] ?: '-'); ?></td><td><?php echo htmlspecialchars($receipt['notes'] ?: '-'); ?></td>
                </tr><?php } ?><?php if (!$receipts) { ?><tr><td colspan="8" class="text-center text-muted">No receipts found for this company and date range.</td></tr><?php } ?></tbody>
            </table>
        </div>
        <?php } ?>

        <?php if ($advanceTotals) { ?>
        <h4 class="mt-4">Advance Deposits</h4>
        <section class="row g-3 mb-3">
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Deposited</div><div class="summary-value text-success">₹<?php echo number_format($advanceTotals['deposited'], 2); ?></div></div></div>
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Adjusted to Bills</div><div class="summary-value">₹<?php echo number_format($advanceTotals['adjusted'], 2); ?></div></div></div>
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Refunded</div><div class="summary-value">₹<?php echo number_format($advanceTotals['refunded'], 2); ?></div></div></div>
            <div class="col-md-3"><div class="border p-3 bg-light"><div class="text-muted">Advance Balance Held</div><div class="summary-value text-info">₹<?php echo number_format($advanceTotals['balance'], 2); ?></div><small><a href="company_advances.php?company_id=<?php echo $companyId; ?>">Manage advances</a></small></div></div>
        </section>
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Payment Mode</th><th>Reference</th><th>Bill #</th><th>By</th><th>Notes</th></tr></thead>
                <tbody><?php foreach ($advanceTxns as $t) { ?><tr>
                    <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($t['txn_date']))); ?></td>
                    <td><?php echo htmlspecialchars($t['txn_type']); ?></td>
                    <td class="<?php echo 'RECEIPT' === $t['txn_type'] ? 'text-success' : 'text-danger'; ?>"><?php echo 'RECEIPT' === $t['txn_type'] ? '+' : '-'; ?>₹<?php echo number_format($t['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($t['payment_mode'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($t['reference_no'] ?: '-'); ?></td>
                    <td><?php echo $t['bill_id'] ? '<a href="view_bill.php?id='.urlencode($t['bill_id']).'">'.htmlspecialchars($t['bill_id']).'</a>' : '-'; ?></td>
                    <td><?php echo htmlspecialchars($t['username'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($t['notes'] ?: '-'); ?></td>
                </tr><?php } ?><?php if (!$advanceTxns) { ?><tr><td colspan="8" class="text-center text-muted">No advance transactions for this company and date range.</td></tr><?php } ?></tbody>
            </table>
        </div>
        <?php } ?>
    </main>
</body>
</html>