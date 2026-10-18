<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

requirePermission('pharmacy', 'view');

$conn = getDbConnection();

$form = [
    'replacement_date' => date('Y-m-d'),
    'item_id' => '',
    'item_label' => '',
    'batch_number' => '',
    'expiry_date' => '',
    'quantity' => '',
    'purchase_rate' => '',
    'retail_price' => '',
    'supplier_id' => '',
    'reference_text' => '',
    'notes' => '',
];

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        if (!canAccessInventoryAdjustmentForm()) {
            throw new Exception('You do not have permission to add replacement stock.');
        }

        $form['replacement_date'] = sanitizeDate($_POST['replacement_date'] ?? date('Y-m-d')) ?: date('Y-m-d');
        $form['item_id'] = (string) (sanitizeInteger($_POST['item_id'] ?? 0, 1) ?: '');
        $form['item_label'] = sanitizeInput($_POST['item_label'] ?? '');
        $form['batch_number'] = sanitizeInput($_POST['batch_number'] ?? '');
        $form['expiry_date'] = sanitizeDate($_POST['expiry_date'] ?? '');
        $form['quantity'] = (string) (sanitizeInteger($_POST['quantity'] ?? 0, 1) ?: '');
        $form['purchase_rate'] = trim((string) ($_POST['purchase_rate'] ?? ''));
        $form['retail_price'] = trim((string) ($_POST['retail_price'] ?? ''));
        $form['supplier_id'] = (string) (sanitizeInteger($_POST['supplier_id'] ?? 0, 1) ?: '');
        $form['reference_text'] = sanitizeInput($_POST['reference_text'] ?? '');
        $form['notes'] = sanitizeInput($_POST['notes'] ?? '');

        if ('' === $form['item_id']) {
            throw new Exception('Medicine selection is required');
        }
        if ('' === $form['batch_number']) {
            throw new Exception('Replacement batch number is required');
        }
        if ('' === $form['expiry_date']) {
            throw new Exception('Replacement expiry date is required');
        }
        if ('' === $form['quantity']) {
            throw new Exception('Replacement quantity must be at least 1');
        }
        if (!is_numeric($form['purchase_rate']) || (float) $form['purchase_rate'] < 0) {
            throw new Exception('Purchase rate must be a valid number');
        }
        if (!is_numeric($form['retail_price']) || (float) $form['retail_price'] < 0) {
            throw new Exception('Retail price must be a valid number');
        }
        if ('' === $form['supplier_id']) {
            throw new Exception('Supplier is required');
        }

        $itemId = (int) $form['item_id'];
        $batchNumber = $form['batch_number'];
        $expiryDate = $form['expiry_date'];
        $quantity = (int) $form['quantity'];
        $purchaseRate = (float) $form['purchase_rate'];
        $retailPrice = (float) $form['retail_price'];
        $supplierId = (int) $form['supplier_id'];
        $referenceText = $form['reference_text'];

        if ('' === $referenceText) {
            $referenceText = 'EXP-REP:MANUAL-'.date('YmdHis');
        }
        if (strlen($referenceText) > 50) {
            $referenceText = substr($referenceText, 0, 50);
        }

        $conn->begin_transaction();

        $itemStmt = $conn->prepare('SELECT item_id, name FROM pharmacy_items WHERE item_id = ? LIMIT 1');
        $itemStmt->bind_param('i', $itemId);
        $itemStmt->execute();
        $item = $itemStmt->get_result()->fetch_assoc();
        if (!$item) {
            throw new Exception('Selected medicine was not found');
        }

        $supplierStmt = $conn->prepare('SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_id = ? LIMIT 1');
        $supplierStmt->bind_param('i', $supplierId);
        $supplierStmt->execute();
        $supplier = $supplierStmt->get_result()->fetch_assoc();
        if (!$supplier) {
            throw new Exception('Selected supplier was not found');
        }

        $existingBatchStmt = $conn->prepare('SELECT batch_id, initial_quantity, current_quantity, invoice_number
                                             FROM pharmacy_batches
                                             WHERE item_id = ? AND batch_number = ?
                                             FOR UPDATE');
        $existingBatchStmt->bind_param('is', $itemId, $batchNumber);
        $existingBatchStmt->execute();
        $existingBatch = $existingBatchStmt->get_result()->fetch_assoc();

        $entryType = 'new replacement batch created';
        if ($existingBatch) {
            $entryType = 'existing replacement batch updated';
            $newInitial = (int) $existingBatch['initial_quantity'] + $quantity;
            $newCurrent = (int) $existingBatch['current_quantity'] + $quantity;
            $existingInvoice = trim((string) ($existingBatch['invoice_number'] ?? ''));
            $updatedInvoice = ('' === $existingInvoice || 'NA' === strtoupper($existingInvoice)) ? $referenceText : $existingInvoice;
            $batchId = (int) $existingBatch['batch_id'];

            $updateBatchStmt = $conn->prepare('UPDATE pharmacy_batches
                                               SET initial_quantity = ?,
                                                   current_quantity = ?,
                                                   purchase_rate = ?,
                                                   retail_price = ?,
                                                   expiry_date = ?,
                                                   supplier_id = ?,
                                                   invoice_number = ?
                                               WHERE batch_id = ?');
            $updateBatchStmt->bind_param('iiddsisi', $newInitial, $newCurrent, $purchaseRate, $retailPrice, $expiryDate, $supplierId, $updatedInvoice, $batchId);
            if (!$updateBatchStmt->execute()) {
                throw new Exception('Failed to update replacement batch');
            }
        } else {
            $insertBatchStmt = $conn->prepare('INSERT INTO pharmacy_batches
                                               (item_id, batch_number, initial_quantity, current_quantity,
                                                purchase_rate, retail_price, expiry_date, supplier_id, invoice_number)
                                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insertBatchStmt->bind_param('isiiddsis', $itemId, $batchNumber, $quantity, $quantity, $purchaseRate, $retailPrice, $expiryDate, $supplierId, $referenceText);
            if (!$insertBatchStmt->execute()) {
                throw new Exception('Failed to create replacement batch');
            }
        }

        $transactionId = function_exists('generateTransactionId')
            ? generateTransactionId('REP')
            : ('REP-'.strtoupper(substr(uniqid('', true), -10)));

        $subtotal = $quantity * $purchaseRate;
        $taxAmount = 0.0;
        $discountAmount = 0.0;
        $finalAmount = $subtotal;
        $paymentStatus = 'PAID';
        $paymentDetails = '{}';

        $historyNote = 'Stock replacement entry via separate page. Ref: '.$referenceText.
            '. Item: '.$item['name'].
            '. Batch: '.$batchNumber.
            '. Expiry: '.$expiryDate.
            '. Qty: '.$quantity.
            '. Mode: '.$entryType.'.';
        if ('' !== trim($form['notes'])) {
            $historyNote .= ' Notes: '.$form['notes'];
        }

        $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $insertPurchaseStmt = $conn->prepare('INSERT INTO pharmacy_purchases
                                              (transaction_id, invoice_number, supplier_id, invoice_date,
                                               purchase_date, subtotal, tax_amount, discount_amount,
                                               final_amount, payment_status, payment_details, notes, created_by)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertPurchaseStmt->bind_param(
            'ssissddddsssi',
            $transactionId,
            $referenceText,
            $supplierId,
            $form['replacement_date'],
            $form['replacement_date'],
            $subtotal,
            $taxAmount,
            $discountAmount,
            $finalAmount,
            $paymentStatus,
            $paymentDetails,
            $historyNote,
            $createdBy
        );

        if (!$insertPurchaseStmt->execute()) {
            throw new Exception('Failed to create replacement purchase history entry');
        }

        $conn->commit();
        $_SESSION['success'] = 'Replacement stock entry saved with purchase history reference.';
        header('Location: stock_replacement.php');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Error: '.$e->getMessage();
        error_log('Stock replacement error: '.$e->getMessage());
    }
}

$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name ASC')->fetch_all(MYSQLI_ASSOC);
$recentReplacementEntries = $conn->query("SELECT purchase_id, transaction_id, invoice_number, purchase_date, notes, created_at
                                         FROM pharmacy_purchases
                                         WHERE invoice_number LIKE 'EXP-REP:%'
                                            OR notes LIKE 'Stock replacement entry%'
                                         ORDER BY purchase_id DESC
                                         LIMIT 15")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Replacement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
    <style>
        body { background: #f4f7fb; }
        .page-shell { padding: 24px; }
        .page-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            padding: 24px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="page-shell">
        <div class="page-card">
            <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                <div>
                    <h2 class="mb-1">Stock Replacement</h2>
                    <p class="text-muted mb-0">Use this page only to add replacement stock batches and keep audit reference history.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="stock_reconciliation.php" class="btn btn-outline-secondary">Back to Reconciliation</a>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php } ?>

            <?php if (isset($_SESSION['error'])) { ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php } ?>

            <form method="POST" class="row g-3">
                <div class="col-md-3">
                    <label for="replacement_date" class="form-label">Entry Date</label>
                    <input type="date" id="replacement_date" name="replacement_date" class="form-control"
                        value="<?php echo htmlspecialchars($form['replacement_date']); ?>">
                </div>
                <div class="col-md-5">
                    <label for="medicine-search" class="form-label">Medicine</label>
                    <select id="medicine-search" class="form-select" style="width: 100%;"></select>
                    <input type="hidden" id="item_id" name="item_id" value="<?php echo htmlspecialchars($form['item_id']); ?>">
                    <input type="hidden" id="item_label" name="item_label" value="<?php echo htmlspecialchars($form['item_label']); ?>">
                </div>
                <div class="col-md-4">
                    <label for="supplier_id" class="form-label">Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="form-select" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($suppliers as $supplier) { ?>
                        <option value="<?php echo (int) $supplier['supplier_id']; ?>"
                            <?php echo (string) $supplier['supplier_id'] === (string) $form['supplier_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                        </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="batch_number" class="form-label">Batch Number</label>
                    <input type="text" id="batch_number" name="batch_number" class="form-control"
                        value="<?php echo htmlspecialchars($form['batch_number']); ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="expiry_date" class="form-label">Expiry Date</label>
                    <input type="date" id="expiry_date" name="expiry_date" class="form-control"
                        value="<?php echo htmlspecialchars($form['expiry_date']); ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="quantity" class="form-label">Quantity</label>
                    <input type="number" min="1" id="quantity" name="quantity" class="form-control"
                        value="<?php echo htmlspecialchars($form['quantity']); ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="purchase_rate" class="form-label">Purchase Rate</label>
                    <input type="number" min="0" step="0.01" id="purchase_rate" name="purchase_rate" class="form-control"
                        value="<?php echo htmlspecialchars($form['purchase_rate']); ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="retail_price" class="form-label">Retail Price</label>
                    <input type="number" min="0" step="0.01" id="retail_price" name="retail_price" class="form-control"
                        value="<?php echo htmlspecialchars($form['retail_price']); ?>" required>
                </div>

                <div class="col-md-4">
                    <label for="reference_text" class="form-label">Reference Text</label>
                    <input type="text" id="reference_text" name="reference_text" class="form-control"
                        value="<?php echo htmlspecialchars($form['reference_text']); ?>"
                        placeholder="Example: EXP-REP:EXP-202526-ABC123">
                </div>
                <div class="col-md-8">
                    <label for="notes" class="form-label">Notes</label>
                    <input type="text" id="notes" name="notes" class="form-control"
                        value="<?php echo htmlspecialchars($form['notes']); ?>"
                        placeholder="Optional notes for audit trail">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save Replacement Entry</button>
                </div>
            </form>
        </div>

        <div class="page-card">
            <h4 class="mb-3">Recent Replacement History</h4>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Purchase ID</th>
                            <th>Transaction</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Created</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentReplacementEntries)) { ?>
                        <tr><td colspan="6" class="text-center text-muted">No replacement history entries found.</td></tr>
                        <?php } ?>
                        <?php foreach ($recentReplacementEntries as $entry) { ?>
                        <tr>
                            <td><?php echo (int) $entry['purchase_id']; ?></td>
                            <td><?php echo htmlspecialchars($entry['transaction_id'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($entry['invoice_number'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars(date('d M Y', strtotime($entry['purchase_date']))); ?></td>
                            <td><?php echo htmlspecialchars(date('d M Y H:i', strtotime($entry['created_at']))); ?></td>
                            <td><?php echo htmlspecialchars($entry['notes'] ?: '-'); ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
    <script src="../../assets/js/cdn/select2.min.js"></script>
    <script>
        const itemIdInput = document.getElementById('item_id');
        const itemLabelInput = document.getElementById('item_label');

        if (window.jQuery) {
            $('#medicine-search').select2({
                ajax: {
                    url: '../../includes/helpers/medicine_helper.php',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return { q: params.term, type: 'sale' };
                    },
                    processResults: function(data) {
                        if (!data || !data.results) {
                            return { results: [] };
                        }
                        return { results: data.results };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'Search medicine...',
                allowClear: true,
                width: 'resolve'
            });

            const currentItemId = itemIdInput ? String(itemIdInput.value || '') : '';
            const currentItemLabel = itemLabelInput ? String(itemLabelInput.value || '') : '';
            if (currentItemId && currentItemLabel) {
                const option = new Option(currentItemLabel, currentItemId, true, true);
                $('#medicine-search').append(option).trigger('change');
            }

            $('#medicine-search').on('select2:select', function(e) {
                if (itemIdInput) {
                    itemIdInput.value = String(e.params.data.id || '');
                }
                if (itemLabelInput) {
                    itemLabelInput.value = String(e.params.data.text || '');
                }
            });

            $('#medicine-search').on('select2:clear', function() {
                if (itemIdInput) {
                    itemIdInput.value = '';
                }
                if (itemLabelInput) {
                    itemLabelInput.value = '';
                }
            });
        }
    </script>
</body>

</html>
