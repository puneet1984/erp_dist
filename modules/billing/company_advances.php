<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
date_default_timezone_set('Asia/Kolkata');

if (!canListBills()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();
$canEdit = checkPermission('bills', 'edit');
$companyId = (int) ($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$message = '';
$error = '';

function companyAdvanceBalance($conn, $companyId)
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN txn_type = 'RECEIPT' THEN amount ELSE -amount END), 0) AS bal
        FROM company_advances WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();

    return (float) $stmt->get_result()->fetch_assoc()['bal'];
}

// Only real modes of payment; bill-type entries (COMPANY_CREDIT, IPD, ...) are excluded
$modeOptions = [];
$modeRes = $conn->query("SELECT payment_type_id, type_name FROM payment_types
    WHERE type_name NOT IN ('COMPANY_CREDIT','BNP','MEDICLAIM','ESI','SPLIT','IPD')
    ORDER BY payment_type_id");
foreach ($modeRes->fetch_all(MYSQLI_ASSOC) as $m) {
    $modeOptions[(int) $m['payment_type_id']] = $m['type_name'];
}

if ('POST' === $_SERVER['REQUEST_METHOD'] && $canEdit && $companyId > 0) {
    try {
        $action = $_POST['action'] ?? '';
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $paymentTypeId = (int) ($_POST['payment_type_id'] ?? 0);
        if (!isset($modeOptions[$paymentTypeId])) {
            throw new Exception('Invalid payment mode');
        }
        $reference = sanitizeInput($_POST['reference_no'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        $txnDate = ($_POST['txn_date'] ?? date('Y-m-d')).' '.date('H:i:s');
        $userId = $_SESSION['user_id'] ?? null;

        if ($amount <= 0) {
            throw new Exception('Amount must be greater than zero');
        }

        $conn->begin_transaction();
        // Lock the company row so concurrent refunds/adjustments cannot overdraw
        $lock = $conn->prepare('SELECT company_id FROM company_accounts WHERE company_id = ? FOR UPDATE');
        $lock->bind_param('i', $companyId);
        $lock->execute();
        if (!$lock->get_result()->fetch_assoc()) {
            throw new Exception('Company not found');
        }

        if ('receive' === $action) {
            $type = 'RECEIPT';
        } elseif ('refund' === $action) {
            $type = 'REFUND';
            if ($amount > companyAdvanceBalance($conn, $companyId)) {
                throw new Exception('Refund exceeds available advance balance');
            }
        } else {
            throw new Exception('Invalid action');
        }

        $stmt = $conn->prepare('INSERT INTO company_advances
            (company_id, txn_type, amount, payment_type_id, reference_no, txn_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isdisssi', $companyId, $type, $amount, $paymentTypeId, $reference, $txnDate, $notes, $userId);
        $stmt->execute();
        $conn->commit();
        header('Location: company_advances.php?company_id='.$companyId.'&saved=1');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}
if (isset($_GET['saved'])) {
    $message = 'Saved successfully.';
}

$companies = $conn->query('SELECT company_id, company_name FROM company_accounts ORDER BY company_name')
    ->fetch_all(MYSQLI_ASSOC);

$summary = $conn->query("SELECT ca.company_id, ca.company_name,
        COALESCE(SUM(CASE WHEN a.txn_type = 'RECEIPT' THEN a.amount ELSE -a.amount END), 0) AS balance,
        COALESCE(SUM(CASE WHEN a.txn_type = 'RECEIPT' THEN a.amount END), 0) AS received,
        COALESCE(SUM(CASE WHEN a.txn_type = 'ADJUSTMENT' THEN a.amount END), 0) AS used
    FROM company_accounts ca
    JOIN company_advances a ON a.company_id = ca.company_id
    GROUP BY ca.company_id, ca.company_name
    ORDER BY ca.company_name")->fetch_all(MYSQLI_ASSOC);

$history = [];
$balance = 0;
$companyName = '';
if ($companyId > 0) {
    foreach ($companies as $c) {
        if ((int) $c['company_id'] === $companyId) {
            $companyName = $c['company_name'];
        }
    }
    $balance = companyAdvanceBalance($conn, $companyId);
    $stmt = $conn->prepare('SELECT a.*, pt.type_name AS payment_mode, u.username FROM company_advances a
        LEFT JOIN payment_types pt ON a.payment_type_id = pt.payment_type_id
        LEFT JOIN users u ON a.created_by = u.user_id
        WHERE a.company_id = ? ORDER BY a.txn_date DESC, a.txn_id DESC');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Company Advance Payments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>
    <main class="container py-4">
        <h3>Company Advance Payments</h3>
        <?php if ($message) { ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php } ?>
        <?php if ($error) { ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php } ?>

        <form method="get" class="row g-2 mb-4">
            <div class="col-md-6">
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Select company</option>
                    <?php foreach ($companies as $c) { ?>
                    <option value="<?php echo (int) $c['company_id']; ?>" <?php echo $companyId === (int) $c['company_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['company_name']); ?></option>
                    <?php } ?>
                </select>
            </div>
        </form>

        <?php if ($companyId > 0 && '' !== $companyName) { ?>
        <div class="card mb-4"><div class="card-body">
            <h5><?php echo htmlspecialchars($companyName); ?></h5>
            <p class="mb-3">Available advance: <strong class="fs-4">₹<?php echo number_format($balance, 2); ?></strong></p>
            <?php if ($canEdit) { ?>
            <form method="post" class="row g-2">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                <div class="col-md-2"><input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="Amount" required></div>
                <div class="col-md-2">
                    <select name="payment_type_id" class="form-select">
                        <?php foreach ($modeOptions as $id => $name) { ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-2"><input type="text" name="reference_no" class="form-control" placeholder="Reference"></div>
                <div class="col-md-2"><input type="date" name="txn_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="col-md-2"><input type="text" name="notes" class="form-control" placeholder="Notes"></div>
                <div class="col-md-2 d-flex gap-1">
                    <button name="action" value="receive" class="btn btn-success">Receive</button>
                    <button name="action" value="refund" class="btn btn-outline-danger" onclick="return confirm('Refund this amount to the company?')">Refund</button>
                </div>
            </form>
            <?php } ?>
        </div></div>

        <h5>Transactions</h5>
        <div class="table-responsive mb-4">
            <table class="table table-striped table-bordered">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Mode</th><th>Reference</th><th>Bill #</th><th>By</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h) { ?><tr>
                    <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($h['txn_date']))); ?></td>
                    <td><?php echo htmlspecialchars($h['txn_type']); ?></td>
                    <td class="<?php echo 'RECEIPT' === $h['txn_type'] ? 'text-success' : 'text-danger'; ?>"><?php echo 'RECEIPT' === $h['txn_type'] ? '+' : '-'; ?>₹<?php echo number_format($h['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($h['payment_mode'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($h['reference_no'] ?: '-'); ?></td>
                    <td><?php echo $h['bill_id'] ? '<a href="view_bill.php?id='.urlencode($h['bill_id']).'">'.htmlspecialchars($h['bill_id']).'</a>' : '-'; ?></td>
                    <td><?php echo htmlspecialchars($h['username'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($h['notes'] ?: '-'); ?></td>
                </tr><?php } ?>
                <?php if (!$history) { ?><tr><td colspan="8" class="text-center text-muted">No advance transactions yet.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

        <h5>All Companies with Advances</h5>
        <table class="table table-bordered">
            <thead><tr><th>Company</th><th>Received</th><th>Used</th><th>Available</th></tr></thead>
            <tbody>
            <?php foreach ($summary as $s) { ?><tr>
                <td><a href="?company_id=<?php echo (int) $s['company_id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></a></td>
                <td>₹<?php echo number_format($s['received'], 2); ?></td>
                <td>₹<?php echo number_format($s['used'], 2); ?></td>
                <td>₹<?php echo number_format($s['balance'], 2); ?></td>
            </tr><?php } ?>
            <?php if (!$summary) { ?><tr><td colspan="4" class="text-center text-muted">No advances recorded.</td></tr><?php } ?>
            </tbody>
        </table>
    </main>
</body>
</html>
