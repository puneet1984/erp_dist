<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

/**
 * Backfill FY in pharmacy_sales using sale_date.
 *
 * CLI usage:
 *   php modules/admin/fix_pharmacy_sales_fy.php --dry-run
 *   php modules/admin/fix_pharmacy_sales_fy.php --apply
 */

function collectPharmacySalesFyMismatches($conn)
{
    $selectSql = "SELECT transaction_id, bill_id, sale_date, FY
                  FROM pharmacy_sales
                  WHERE sale_date IS NOT NULL
                                        AND sale_date >= '1000-01-01'";
    $result = $conn->query($selectSql);

    if (!$result) {
        throw new Exception('Failed to fetch pharmacy sales rows');
    }

    $mismatches = [];
    while ($row = $result->fetch_assoc()) {
        $saleDate = (string) $row['sale_date'];
        if (false === strtotime($saleDate)) {
            continue;
        }
        $expectedFy = getCurrentFinancialYear($saleDate);
        $storedFy = trim((string) ($row['FY'] ?? ''));

        if ($storedFy !== $expectedFy) {
            $mismatches[] = [
                'transaction_id' => (string) $row['transaction_id'],
                'bill_id' => (string) $row['bill_id'],
                'sale_date' => $saleDate,
                'old_fy' => $storedFy,
                'new_fy' => $expectedFy,
            ];
        }
    }

    return $mismatches;
}

function runPharmacySalesFyFix($conn, $isDryRun)
{
    $conn->begin_transaction();

    try {
        $mismatches = collectPharmacySalesFyMismatches($conn);
        $totalMismatches = count($mismatches);
        $updated = 0;

        if (!$isDryRun && $totalMismatches > 0) {
            $updateStmt = $conn->prepare('UPDATE pharmacy_sales SET FY = ? WHERE transaction_id = ?');
            if (!$updateStmt) {
                throw new Exception('Failed to prepare FY update statement');
            }

            foreach ($mismatches as $item) {
                $updateStmt->bind_param('ss', $item['new_fy'], $item['transaction_id']);
                if (!$updateStmt->execute()) {
                    throw new Exception('Update failed for transaction '.$item['transaction_id']);
                }
                $updated += $updateStmt->affected_rows;
            }
        }

        if ($isDryRun) {
            $conn->rollback();
        } else {
            $conn->commit();
        }

        return [
            'is_dry_run' => $isDryRun,
            'total_mismatches' => $totalMismatches,
            'updated' => $updated,
            'mismatches' => $mismatches,
        ];
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

$isCli = ('cli' === php_sapi_name());
$conn = getDbConnection();

if ($isCli) {
    $arguments = $argv;
    $isApplyMode = in_array('--apply', $arguments, true);
    $isDryRun = !$isApplyMode;

    try {
        $result = runPharmacySalesFyFix($conn, $isDryRun);
        echo 'Mode: '.($result['is_dry_run'] ? 'DRY RUN' : 'APPLY').PHP_EOL;
        echo 'Affected rows: '.$result['total_mismatches'].PHP_EOL;

        if (0 === $result['total_mismatches']) {
            echo 'No FY corrections needed.'.PHP_EOL;
            exit;
        }

        $previewCount = min(20, $result['total_mismatches']);
        echo 'Preview (first '.$previewCount.' rows):'.PHP_EOL;
        for ($i = 0; $i < $previewCount; $i++) {
            $item = $result['mismatches'][$i];
            echo sprintf(
                '  %s | %s | sale_date=%s | FY %s -> %s',
                $item['transaction_id'],
                $item['bill_id'],
                $item['sale_date'],
                ('' === $item['old_fy'] ? 'NULL/EMPTY' : $item['old_fy']),
                $item['new_fy']
            ).PHP_EOL;
        }

        if ($result['is_dry_run']) {
            echo 'Dry run complete. Re-run with --apply to persist changes.'.PHP_EOL;
            exit;
        }

        echo 'FY correction complete. Updated rows: '.$result['updated'].PHP_EOL;
        exit;
    } catch (Exception $e) {
        echo 'Error: '.$e->getMessage().PHP_EOL;
        exit(1);
    }
}

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$runResult = null;
$runError = null;
$isDryRun = true;

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $isDryRun = isset($_POST['dry_run']);

    try {
        $runResult = runPharmacySalesFyFix($conn, $isDryRun);
    } catch (Exception $e) {
        $runError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Pharmacy Sales FY</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            background: #fff;
        }

        .form-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .btn {
            padding: 8px 14px;
            border: 0;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-primary {
            background: #005f73;
            color: #fff;
        }

        .btn-danger {
            background: #ae2012;
            color: #fff;
        }

        .alert {
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 14px;
        }

        .alert-info {
            background: #e7f5ff;
            border: 1px solid #9ad1ff;
        }

        .alert-success {
            background: #ecfdf3;
            border: 1px solid #8ce4b0;
        }

        .alert-danger {
            background: #fff1f2;
            border: 1px solid #ffb3bb;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #f6f8fa;
        }

        .muted {
            color: #666;
            font-size: 0.92rem;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <h2>Fix Pharmacy Sales FY</h2>

        <div class="card">
            <p class="muted">This tool recalculates FY from sale_date and updates mismatched rows in pharmacy_sales.</p>
            <form method="post">
                <div class="form-row">
                    <label for="dry_run">
                        <input type="checkbox" id="dry_run" name="dry_run" <?php echo $isDryRun ? 'checked' : ''; ?>>
                        Dry run (preview only, no DB changes)
                    </label>
                </div>
                <div class="form-row">
                    <button type="submit" class="btn <?php echo $isDryRun ? 'btn-primary' : 'btn-danger'; ?>">
                        <?php echo $isDryRun ? 'Run Preview' : 'Apply Fix'; ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if (null !== $runError) { ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> <?php echo htmlspecialchars($runError); ?>
            </div>
        <?php } ?>

        <?php if (is_array($runResult)) { ?>
            <div class="alert <?php echo $runResult['is_dry_run'] ? 'alert-info' : 'alert-success'; ?>">
                <div><strong>Mode:</strong> <?php echo $runResult['is_dry_run'] ? 'DRY RUN' : 'APPLY'; ?></div>
                <div><strong>Affected rows:</strong> <?php echo (int) $runResult['total_mismatches']; ?></div>
                <?php if (!$runResult['is_dry_run']) { ?>
                    <div><strong>Updated rows:</strong> <?php echo (int) $runResult['updated']; ?></div>
                <?php } ?>
            </div>

            <?php if (0 === (int) $runResult['total_mismatches']) { ?>
                <div class="alert alert-success">No FY corrections needed.</div>
            <?php } else { ?>
                <div class="card">
                    <h3>Preview</h3>
                    <p class="muted">Showing first <?php echo min(200, count($runResult['mismatches'])); ?> rows.</p>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Bill ID</th>
                                    <th>Sale Date</th>
                                    <th>Current FY</th>
                                    <th>Expected FY</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($runResult['mismatches'], 0, 200) as $item) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['transaction_id']); ?></td>
                                        <td><?php echo htmlspecialchars($item['bill_id']); ?></td>
                                        <td><?php echo htmlspecialchars($item['sale_date']); ?></td>
                                        <td><?php echo htmlspecialchars('' === $item['old_fy'] ? 'NULL/EMPTY' : $item['old_fy']); ?></td>
                                        <td><?php echo htmlspecialchars($item['new_fy']); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</body>

</html>
