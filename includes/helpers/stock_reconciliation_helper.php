<?php

function getReconciliationBatchInventoryRows($conn)
{
    $sql = 'SELECT b.batch_id, b.item_id, b.batch_number, b.initial_quantity, b.current_quantity,
                   b.purchase_rate, b.retail_price, b.expiry_date,
                   i.name AS item_name, i.category, i.active_ingredient, i.manufacturer
            FROM pharmacy_batches b
            INNER JOIN pharmacy_items i ON i.item_id = b.item_id
            ORDER BY i.name ASC, b.batch_number ASC';

    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function groupReconciliationRowsByItem($rows)
{
    $groupedRows = [];

    foreach ($rows as $row) {
        $groupKey = (string) $row['item_id'];
        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = [
                'item_id' => (int) $row['item_id'],
                'item_name' => $row['item_name'],
                'category' => $row['category'] ?? '',
                'active_ingredient' => $row['active_ingredient'] ?? '',
                'manufacturer' => $row['manufacturer'] ?? '',
                'rows' => [],
            ];
        }

        $groupedRows[$groupKey]['rows'][] = $row;
    }

    return $groupedRows;
}

function normalizeFinancialYearValue($financialYear)
{
    $financialYear = sanitizeInput($financialYear ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $financialYear)) {
        return getCurrentFinancialYear();
    }

    return $financialYear;
}

function getPreviousFinancialYearValue($financialYear = null)
{
    $normalized = normalizeFinancialYearValue($financialYear ?? getCurrentFinancialYear());
    $startYear = (int) substr($normalized, 0, 4) - 1;

    return sprintf('%04d-%02d', $startYear, ($startYear + 1) % 100);
}

function getDefaultAdjustmentFinancialYear($type)
{
    $type = normalizeAdjustmentType($type);

    return 'YEAR_END' === $type ? getPreviousFinancialYearValue(getCurrentFinancialYear()) : getCurrentFinancialYear();
}

function getSelectableFinancialYears($limit = 5)
{
    $current = normalizeFinancialYearValue(getCurrentFinancialYear());
    $startYear = (int) substr($current, 0, 4);
    $limit = max(1, (int) $limit);
    $years = [];

    for ($index = 0; $index < $limit; ++$index) {
        $yearStart = $startYear - $index;
        $years[] = sprintf('%04d-%02d', $yearStart, ($yearStart + 1) % 100);
    }

    return $years;
}

function getSelectableFinancialYearsForType($type, $selectedFinancialYear = '', $limit = 5)
{
    return getSelectableFinancialYears($limit);
}

function normalizeAdjustmentType($type)
{
    $allowedTypes = ['YEAR_END', 'EXPIRY_WRITEOFF', 'DAMAGE', 'LOSS', 'FREE_ISSUE'];
    $type = sanitizeInput($type ?? 'YEAR_END');

    return in_array($type, $allowedTypes, true) ? $type : 'YEAR_END';
}

function getAdjustmentLabels()
{
    return [
        'YEAR_END' => 'Year End Reconciliation',
        'EXPIRY_WRITEOFF' => 'Expiry Write-Off',
        'DAMAGE' => 'Damage',
        'LOSS' => 'Loss',
        'FREE_ISSUE' => 'Free Issue / Management Approval',
    ];
}

function getAdjustmentTypeHelp($type)
{
    $help = [
        'YEAR_END' => 'Enter the physical stock counted at financial year end for each batch. The system will compare it against current database stock and post the difference.',
        'EXPIRY_WRITEOFF' => 'Enter only the quantity being removed because the batch has expired. Use this page for write-off/disposal only. For replacement stock, use the separate Stock Replacement page.',
        'DAMAGE' => 'Enter only the damaged quantity to be written off from current stock.',
        'LOSS' => 'Enter only the quantity lost, missing, or otherwise not traceable. The entered quantity will be deducted from current stock.',
        'FREE_ISSUE' => 'Enter only the quantity issued free of cost. Use Reference Name for the doctor, staff member, or other known recipient approved by management.',
    ];

    return $help[$type] ?? $help['YEAR_END'];
}

function generateAdjustmentCode($financialYear, $type)
{
    $prefixMap = [
        'YEAR_END' => 'YER',
        'EXPIRY_WRITEOFF' => 'EXP',
        'DAMAGE' => 'DMG',
        'LOSS' => 'LOS',
        'FREE_ISSUE' => 'FOC',
    ];

    $prefix = $prefixMap[$type] ?? 'ADJ';

    return sprintf('%s-%s-%s', $prefix, str_replace('-', '', $financialYear), strtoupper(substr(uniqid('', true), -6)));
}

function getAdjustmentHeader($conn, $adjustmentId)
{
    $stmt = $conn->prepare('SELECT adjustment_id, adjustment_code, financial_year, adjustment_type,
                                   adjustment_date, reference_name, notes, is_reverted, reverted_at, created_at
                            FROM pharmacy_stock_adjustments
                            WHERE adjustment_id = ?');
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

function getAdjustmentItemsByBatch($conn, $adjustmentId)
{
    $stmt = $conn->prepare('SELECT adjustment_item_id, adjustment_id, batch_id, item_id, batch_number,
                                   system_quantity, entered_quantity, final_quantity, adjustment_quantity,
                                   purchase_rate, retail_price, expiry_date, line_note, created_at
                            FROM pharmacy_stock_adjustment_items
                            WHERE adjustment_id = ?');
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[(int) $row['batch_id']] = $row;
    }

    return $items;
}

function hasBatchReplacementItems($adjustmentItems)
{
    foreach ($adjustmentItems as $item) {
        if (0 === strpos((string) ($item['line_note'] ?? ''), 'YEAR_END_REPLACEMENT_')) {
            return true;
        }
    }

    return false;
}

function getRecentAdjustments($conn, $financialYear, $type)
{
    $stmt = $conn->prepare('SELECT adjustment_id, adjustment_code, adjustment_date, adjustment_type,
                                   reference_name, is_reverted, reverted_at, created_at
                            FROM pharmacy_stock_adjustments
                            WHERE financial_year = ? AND adjustment_type = ?
                            ORDER BY adjustment_id DESC
                            LIMIT 10');
    $stmt->bind_param('ss', $financialYear, $type);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function buildEnteredQuantityMap($payload)
{
    $decoded = json_decode($payload ?? '{}', true);
    if (!is_array($decoded)) {
        throw new Exception('Invalid stock adjustment payload');
    }

    $quantities = [];
    foreach ($decoded as $batchId => $quantity) {
        $normalizedBatchId = sanitizeInteger((string) $batchId, 1);
        $normalizedQuantity = sanitizeInteger((string) $quantity, 0);

        if (!$normalizedBatchId) {
            continue;
        }

        if (null === $normalizedQuantity) {
            throw new Exception('Entered quantity must be a whole number');
        }

        $quantities[(int) $normalizedBatchId] = $normalizedQuantity;
    }

    return $quantities;
}

function parseStockReconciliationPayload($payload)
{
    $decoded = json_decode($payload ?? '{}', true);
    if (!is_array($decoded)) {
        throw new Exception('Invalid stock adjustment payload');
    }

    if (!isset($decoded['mode'])) {
        return [
            'mode' => 'CLASSIC',
            'existing_batches' => buildEnteredQuantityMap($payload),
            'new_batches' => [],
        ];
    }

    $mode = strtoupper((string) ($decoded['mode'] ?? 'CLASSIC'));
    if (!in_array($mode, ['CLASSIC', 'BATCH_REPLACEMENT'], true)) {
        throw new Exception('Invalid stock adjustment entry mode');
    }

    $existingBatchSource = $decoded['existing_batches'] ?? [];
    if (!is_array($existingBatchSource)) {
        throw new Exception('Invalid existing batch payload');
    }

    $existingBatches = [];
    foreach ($existingBatchSource as $batchId => $quantity) {
        $normalizedBatchId = sanitizeInteger((string) $batchId, 1);
        $normalizedQuantity = sanitizeInteger((string) $quantity, 0);
        if (!$normalizedBatchId) {
            continue;
        }
        if (null === $normalizedQuantity) {
            throw new Exception('Entered quantity must be a whole number');
        }
        $existingBatches[(int) $normalizedBatchId] = (int) $normalizedQuantity;
    }

    $newBatches = [];
    foreach (($decoded['new_batches'] ?? []) as $itemId => $rows) {
        $normalizedItemId = sanitizeInteger((string) $itemId, 1);
        if (!$normalizedItemId) {
            continue;
        }
        if (!is_array($rows)) {
            throw new Exception('Invalid new batch rows for selected medicine');
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new Exception('Invalid new physical batch payload row');
            }

            $batchNumber = makeUpperCaseMixed(sanitizeInput($row['batch_number'] ?? ''));
            $quantity = sanitizeInteger($row['quantity'] ?? 0, 1);
            $expiryDate = sanitizeDate($row['expiry_date'] ?? '');
            $purchaseRate = isset($row['purchase_rate']) && '' !== trim((string) $row['purchase_rate'])
                ? round((float) $row['purchase_rate'], 2)
                : null;
            $retailPrice = isset($row['retail_price']) && '' !== trim((string) $row['retail_price'])
                ? round((float) $row['retail_price'], 2)
                : null;

            $normalizedRows[] = [
                'batch_number' => $batchNumber,
                'quantity' => (int) ($quantity ?? 0),
                'expiry_date' => $expiryDate,
                'purchase_rate' => $purchaseRate,
                'retail_price' => $retailPrice,
            ];
        }

        $newBatches[(int) $normalizedItemId] = $normalizedRows;
    }

    return [
        'mode' => $mode,
        'existing_batches' => $existingBatches,
        'new_batches' => $newBatches,
    ];
}

function getLatestBatchRateLookup($conn)
{
    $sql = "SELECT b.item_id, b.batch_id, b.batch_number, b.purchase_rate, b.retail_price,
                   COALESCE(pp.purchase_date, DATE(b.created_at)) AS source_date
            FROM pharmacy_batches b
            LEFT JOIN pharmacy_purchases pp ON pp.invoice_number = b.invoice_number
            ORDER BY b.item_id ASC, source_date DESC, b.batch_id DESC";
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $lookup = [];
    while ($row = $result->fetch_assoc()) {
        $itemId = (int) $row['item_id'];
        if (isset($lookup[$itemId])) {
            continue;
        }

        $lookup[$itemId] = [
            'batch_id' => (int) $row['batch_id'],
            'batch_number' => (string) $row['batch_number'],
            'purchase_rate' => (float) $row['purchase_rate'],
            'retail_price' => (float) $row['retail_price'],
            'source_date' => (string) $row['source_date'],
        ];
    }

    return $lookup;
}

function validateYearEndBatchReplacementPayload($conn, $payloadData, $inventoryRows, $selectedType)
{
    if ('BATCH_REPLACEMENT' !== ($payloadData['mode'] ?? 'CLASSIC')) {
        return;
    }
    if ('YEAR_END' !== $selectedType) {
        throw new Exception('Batch replacement mode is allowed only for year end reconciliation');
    }

    $existingBatchRows = [];
    $existingBatchNumbersByItem = [];
    foreach ($inventoryRows as $row) {
        $batchId = (int) $row['batch_id'];
        $itemId = (int) $row['item_id'];
        $existingBatchRows[$batchId] = $row;
        $existingBatchNumbersByItem[$itemId][strtoupper((string) $row['batch_number'])] = true;
    }

    $latestRateLookup = getLatestBatchRateLookup($conn);

    foreach (($payloadData['existing_batches'] ?? []) as $batchId => $quantity) {
        if (!isset($existingBatchRows[(int) $batchId])) {
            throw new Exception('One or more existing batches in the payload are invalid');
        }
        if ((int) $quantity < 0) {
            throw new Exception('Physical quantity cannot be negative');
        }
    }

    foreach (($payloadData['new_batches'] ?? []) as $itemId => $rows) {
        if (empty($rows)) {
            continue;
        }
        if (!isset($existingBatchNumbersByItem[(int) $itemId])) {
            throw new Exception('Selected medicine for new physical batch was not found in current stock');
        }
        if (!isset($latestRateLookup[(int) $itemId])) {
            throw new Exception('Unable to resolve latest rates from system for selected medicine');
        }

        $seenBatchNumbers = [];
        foreach ($rows as $row) {
            $batchNumber = strtoupper((string) ($row['batch_number'] ?? ''));
            if ('' === $batchNumber) {
                throw new Exception('New physical batch number is required');
            }
            if (isset($seenBatchNumbers[$batchNumber])) {
                throw new Exception('Duplicate new physical batch number '.$batchNumber.' for selected medicine');
            }
            if (isset($existingBatchNumbersByItem[(int) $itemId][$batchNumber])) {
                throw new Exception('Batch '.$batchNumber.' already exists in the system for this medicine. Enter its physical quantity in the existing batch row instead.');
            }
            if ((int) ($row['quantity'] ?? 0) <= 0) {
                throw new Exception('New physical batch quantity must be greater than zero');
            }
            if (empty($row['expiry_date'])) {
                throw new Exception('Expiry date is required for new physical batches');
            }
            $seenBatchNumbers[$batchNumber] = true;
        }
    }
}

function resolveLatestRatesForItem($conn, $itemId)
{
    $stmt = $conn->prepare("SELECT b.purchase_rate, b.retail_price, b.batch_number,
                                   COALESCE(pp.purchase_date, DATE(b.created_at)) AS source_date
                            FROM pharmacy_batches b
                            LEFT JOIN pharmacy_purchases pp ON pp.invoice_number = b.invoice_number
                            WHERE b.item_id = ?
                            ORDER BY source_date DESC, b.batch_id DESC
                            LIMIT 1");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new Exception('Unable to resolve latest batch rates from system');
    }

    return [
        'purchase_rate' => (float) $row['purchase_rate'],
        'retail_price' => (float) $row['retail_price'],
        'batch_number' => (string) $row['batch_number'],
        'source_date' => (string) $row['source_date'],
    ];
}

function saveAdjustmentHeader($conn, $payload)
{
    $financialYear = normalizeFinancialYearValue($payload['financial_year'] ?? getCurrentFinancialYear());
    $type = normalizeAdjustmentType($payload['adjustment_type'] ?? 'YEAR_END');
    $adjustmentDate = sanitizeDate($payload['adjustment_date'] ?? date('Y-m-d'));
    $referenceName = sanitizeInput($payload['reference_name'] ?? '');
    $notes = sanitizeInput($payload['notes'] ?? '');

    $adjustmentCode = generateAdjustmentCode($financialYear, $type);
    $createdBy = $_SESSION['user_id'] ?? null;
    $stmt = $conn->prepare('INSERT INTO pharmacy_stock_adjustments
                            (adjustment_code, financial_year, adjustment_type, adjustment_date,
                             reference_name, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssssssi', $adjustmentCode, $financialYear, $type, $adjustmentDate, $referenceName, $notes, $createdBy);
    if (!$stmt->execute()) {
        throw new Exception('Failed to create stock adjustment header');
    }

    return (int) $conn->insert_id;
}

function saveAdjustmentItems($conn, $adjustmentId, $type, $inventoryRows, $enteredQuantities, $payloadData = null)
{
    $deleteStmt = $conn->prepare('DELETE FROM pharmacy_stock_adjustment_items WHERE adjustment_id = ?');
    $deleteStmt->bind_param('i', $adjustmentId);
    $deleteStmt->execute();

    $insertStmt = $conn->prepare('INSERT INTO pharmacy_stock_adjustment_items
                                  (adjustment_id, batch_id, item_id, batch_number, system_quantity,
                                   entered_quantity, final_quantity, adjustment_quantity,
                                   purchase_rate, retail_price, expiry_date, line_note)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $replacementMode = is_array($payloadData) && 'BATCH_REPLACEMENT' === ($payloadData['mode'] ?? 'CLASSIC');
    $existingQuantities = $replacementMode ? ($payloadData['existing_batches'] ?? []) : $enteredQuantities;

    foreach ($inventoryRows as $batch) {
        $batchId = (int) $batch['batch_id'];
        $systemQuantity = (int) $batch['current_quantity'];

        if ('YEAR_END' === $type) {
            $enteredQuantity = $existingQuantities[$batchId] ?? $systemQuantity;
            $finalQuantity = $enteredQuantity;
        } else {
            $enteredQuantity = $existingQuantities[$batchId] ?? 0;
            if ($enteredQuantity > $systemQuantity) {
                throw new Exception('Entered quantity cannot exceed current stock for batch '.$batch['batch_number']);
            }
            $finalQuantity = $systemQuantity - $enteredQuantity;
        }

        $adjustmentQuantity = $finalQuantity - $systemQuantity;
        $lineNote = $replacementMode ? 'YEAR_END_REPLACEMENT_EXISTING' : null;

        $insertStmt->bind_param(
            'iiisiiiiddss',
            $adjustmentId,
            $batchId,
            $batch['item_id'],
            $batch['batch_number'],
            $systemQuantity,
            $enteredQuantity,
            $finalQuantity,
            $adjustmentQuantity,
            $batch['purchase_rate'],
            $batch['retail_price'],
            $batch['expiry_date'],
            $lineNote
        );

        if (!$insertStmt->execute()) {
            throw new Exception('Failed to save stock adjustment items');
        }
    }

    if (!$replacementMode || 'YEAR_END' !== $type) {
        return;
    }

    $findExistingBatchStmt = $conn->prepare('SELECT batch_id FROM pharmacy_batches WHERE item_id = ? AND batch_number = ? LIMIT 1');
    $insertBatchStmt = $conn->prepare('INSERT INTO pharmacy_batches
                                      (item_id, batch_number, initial_quantity, current_quantity,
                                       purchase_rate, retail_price, expiry_date)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)');

    foreach (($payloadData['new_batches'] ?? []) as $itemId => $rows) {
        foreach ($rows as $row) {
            $batchNumber = (string) $row['batch_number'];
            $quantity = (int) $row['quantity'];
            $expiryDate = (string) $row['expiry_date'];

            $findExistingBatchStmt->bind_param('is', $itemId, $batchNumber);
            $findExistingBatchStmt->execute();
            $existingBatch = $findExistingBatchStmt->get_result()->fetch_assoc();
            if ($existingBatch) {
                throw new Exception('Batch '.$batchNumber.' already exists in the system for this medicine');
            }

            $latestRates = resolveLatestRatesForItem($conn, (int) $itemId);
            $purchaseRate = isset($row['purchase_rate']) && null !== $row['purchase_rate']
                ? (float) $row['purchase_rate']
                : (float) $latestRates['purchase_rate'];
            $retailPrice = isset($row['retail_price']) && null !== $row['retail_price']
                ? (float) $row['retail_price']
                : (float) $latestRates['retail_price'];

            $insertBatchStmt->bind_param(
                'isiiids',
                $itemId,
                $batchNumber,
                $quantity,
                $quantity,
                $purchaseRate,
                $retailPrice,
                $expiryDate
            );

            if (!$insertBatchStmt->execute()) {
                throw new Exception('Failed to create new physical batch '.$batchNumber);
            }

            $newBatchId = (int) $conn->insert_id;
            $zeroSystemQuantity = 0;
            $finalQuantity = $quantity;
            $adjustmentQuantity = $quantity;
            $lineNote = 'YEAR_END_REPLACEMENT_NEW';

            $insertStmt->bind_param(
                'iiisiiiiddss',
                $adjustmentId,
                $newBatchId,
                $itemId,
                $batchNumber,
                $zeroSystemQuantity,
                $quantity,
                $finalQuantity,
                $adjustmentQuantity,
                $purchaseRate,
                $retailPrice,
                $expiryDate,
                $lineNote
            );

            if (!$insertStmt->execute()) {
                throw new Exception('Failed to save new physical batch adjustment item');
            }
        }
    }
}

function applyAdjustmentToStock($conn, $adjustmentId)
{
    $header = getAdjustmentHeader($conn, $adjustmentId);
    if (!$header) {
        throw new Exception('Stock adjustment header not found');
    }
    if (1 === (int) $header['is_reverted']) {
        throw new Exception('This stock adjustment has already been reverted');
    }

    $itemStmt = $conn->prepare('SELECT adjustment_item_id, batch_id, final_quantity
                                FROM pharmacy_stock_adjustment_items
                                WHERE adjustment_id = ?');
    $itemStmt->bind_param('i', $adjustmentId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $batchStmt = $conn->prepare('SELECT batch_id, batch_number, current_quantity
                                 FROM pharmacy_batches
                                 WHERE batch_id = ?
                                 FOR UPDATE');
    $updateBatchStmt = $conn->prepare('UPDATE pharmacy_batches SET current_quantity = ? WHERE batch_id = ?');

    foreach ($items as $item) {
        $batchId = (int) $item['batch_id'];
        $batchStmt->bind_param('i', $batchId);
        $batchStmt->execute();
        $batch = $batchStmt->get_result()->fetch_assoc();

        if (!$batch) {
            throw new Exception('Batch not found while posting stock adjustment');
        }

        $finalQuantity = (int) $item['final_quantity'];
        $updateBatchStmt->bind_param('ii', $finalQuantity, $batchId);
        if (!$updateBatchStmt->execute()) {
            throw new Exception('Failed to update live stock');
        }
    }
}

function createReplacementBatchForExpiryWriteoff($conn, $adjustmentId, $replacementPayload)
{
    $batchNumber = sanitizeInput($replacementPayload['batch_number'] ?? '');
    $expiryDate = sanitizeDate($replacementPayload['expiry_date'] ?? '');
    $quantity = sanitizeInteger($replacementPayload['quantity'] ?? 0, 1);

    if ('' === $batchNumber || !$expiryDate || !$quantity) {
        throw new Exception('Replacement batch details are invalid');
    }

    $header = getAdjustmentHeader($conn, $adjustmentId);
    if (!$header) {
        throw new Exception('Stock adjustment header not found for replacement reference');
    }

    $replacementReference = sprintf('EXP-REP:%s', $header['adjustment_code'] ?: ('ADJ'.$adjustmentId));
    if (strlen($replacementReference) > 50) {
        $replacementReference = substr($replacementReference, 0, 50);
    }

    $itemStmt = $conn->prepare('SELECT DISTINCT item_id
                               FROM pharmacy_stock_adjustment_items
                               WHERE adjustment_id = ? AND entered_quantity > 0');
    $itemStmt->bind_param('i', $adjustmentId);
    $itemStmt->execute();
    $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($itemRows)) {
        throw new Exception('No expired quantity found to replace in this adjustment');
    }
    if (count($itemRows) > 1) {
        throw new Exception('Replacement can be posted only when one medicine item is adjusted. Please create separate expiry write-off entries per medicine.');
    }

    $itemId = (int) $itemRows[0]['item_id'];
    $sourceSupplierId = resolveReplacementSupplierId($conn, $adjustmentId);

    $rateStmt = $conn->prepare('SELECT purchase_rate, retail_price
                               FROM pharmacy_stock_adjustment_items
                               WHERE adjustment_id = ? AND item_id = ? AND entered_quantity > 0
                               ORDER BY adjustment_item_id ASC
                               LIMIT 1');
    $rateStmt->bind_param('ii', $adjustmentId, $itemId);
    $rateStmt->execute();
    $rateRow = $rateStmt->get_result()->fetch_assoc();

    if (!$rateRow) {
        throw new Exception('Unable to resolve rates for replacement batch');
    }

    $purchaseRate = (float) $rateRow['purchase_rate'];
    $retailPrice = (float) $rateRow['retail_price'];

    $existingStmt = $conn->prepare('SELECT batch_id, initial_quantity, current_quantity, invoice_number
                                   FROM pharmacy_batches
                                   WHERE item_id = ? AND batch_number = ?
                                   FOR UPDATE');
    $existingStmt->bind_param('is', $itemId, $batchNumber);
    $existingStmt->execute();
    $existingBatch = $existingStmt->get_result()->fetch_assoc();

    if ($existingBatch) {
        $newInitialQuantity = (int) $existingBatch['initial_quantity'] + (int) $quantity;
        $newCurrentQuantity = (int) $existingBatch['current_quantity'] + (int) $quantity;
        $batchId = (int) $existingBatch['batch_id'];
        $existingInvoiceNumber = (string) ($existingBatch['invoice_number'] ?? '');
        $updatedInvoiceNumber = trim($existingInvoiceNumber);
        if ('' === $updatedInvoiceNumber || 'NA' === strtoupper($updatedInvoiceNumber)) {
            $updatedInvoiceNumber = $replacementReference;
        }

        $updateStmt = $conn->prepare('UPDATE pharmacy_batches
                                      SET initial_quantity = ?,
                                          current_quantity = ?,
                                          purchase_rate = ?,
                                          retail_price = ?,
                                          expiry_date = ?,
                                          invoice_number = ?
                                      WHERE batch_id = ?');
        $updateStmt->bind_param('iiddssi', $newInitialQuantity, $newCurrentQuantity, $purchaseRate, $retailPrice, $expiryDate, $updatedInvoiceNumber, $batchId);
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update replacement batch');
        }

        createReplacementPurchaseEntry($conn, [
            'adjustment_id' => $adjustmentId,
            'adjustment_code' => $header['adjustment_code'] ?? ('ADJ'.$adjustmentId),
            'supplier_id' => $sourceSupplierId,
            'item_id' => $itemId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'quantity' => (int) $quantity,
            'purchase_rate' => $purchaseRate,
            'reference' => $replacementReference,
            'is_existing_batch' => true,
        ]);

        return;
    }

    $insertStmt = $conn->prepare('INSERT INTO pharmacy_batches
                                  (item_id, batch_number, initial_quantity, current_quantity,
                                   purchase_rate, retail_price, expiry_date, invoice_number)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insertStmt->bind_param('isiiddss', $itemId, $batchNumber, $quantity, $quantity, $purchaseRate, $retailPrice, $expiryDate, $replacementReference);
    if (!$insertStmt->execute()) {
        throw new Exception('Failed to create replacement batch');
    }

    createReplacementPurchaseEntry($conn, [
        'adjustment_id' => $adjustmentId,
        'adjustment_code' => $header['adjustment_code'] ?? ('ADJ'.$adjustmentId),
        'supplier_id' => $sourceSupplierId,
        'item_id' => $itemId,
        'batch_number' => $batchNumber,
        'expiry_date' => $expiryDate,
        'quantity' => (int) $quantity,
        'purchase_rate' => $purchaseRate,
        'reference' => $replacementReference,
        'is_existing_batch' => false,
    ]);
}

function resolveReplacementSupplierId($conn, $adjustmentId)
{
    $sourceSupplierStmt = $conn->prepare('SELECT b.supplier_id
                                          FROM pharmacy_stock_adjustment_items ai
                                          INNER JOIN pharmacy_batches b ON b.batch_id = ai.batch_id
                                          WHERE ai.adjustment_id = ?
                                            AND ai.entered_quantity > 0
                                            AND b.supplier_id IS NOT NULL
                                          ORDER BY ai.adjustment_item_id ASC
                                          LIMIT 1');
    $sourceSupplierStmt->bind_param('i', $adjustmentId);
    $sourceSupplierStmt->execute();
    $sourceSupplierRow = $sourceSupplierStmt->get_result()->fetch_assoc();
    if ($sourceSupplierRow && !empty($sourceSupplierRow['supplier_id'])) {
        return (int) $sourceSupplierRow['supplier_id'];
    }

    $fallbackSupplierStmt = $conn->prepare("SELECT supplier_id
                                            FROM suppliers
                                            WHERE LOWER(supplier_name) IN ('direct', 'management', 'internal')
                                            ORDER BY supplier_id ASC
                                            LIMIT 1");
    $fallbackSupplierStmt->execute();
    $fallbackSupplierRow = $fallbackSupplierStmt->get_result()->fetch_assoc();
    if ($fallbackSupplierRow && !empty($fallbackSupplierRow['supplier_id'])) {
        return (int) $fallbackSupplierRow['supplier_id'];
    }

    $anySupplierStmt = $conn->prepare('SELECT supplier_id FROM suppliers ORDER BY supplier_id ASC LIMIT 1');
    $anySupplierStmt->execute();
    $anySupplierRow = $anySupplierStmt->get_result()->fetch_assoc();
    if ($anySupplierRow && !empty($anySupplierRow['supplier_id'])) {
        return (int) $anySupplierRow['supplier_id'];
    }

    throw new Exception('No supplier available to record replacement purchase entry');
}

function createReplacementPurchaseEntry($conn, $payload)
{
    $adjustmentCode = sanitizeInput($payload['adjustment_code'] ?? 'ADJ');
    $supplierId = (int) ($payload['supplier_id'] ?? 0);
    $batchNumber = sanitizeInput($payload['batch_number'] ?? '');
    $expiryDate = sanitizeDate($payload['expiry_date'] ?? date('Y-m-d'));
    $quantity = (int) ($payload['quantity'] ?? 0);
    $purchaseRate = (float) ($payload['purchase_rate'] ?? 0);
    $reference = sanitizeInput($payload['reference'] ?? 'EXP-REP');
    $isExistingBatch = !empty($payload['is_existing_batch']);

    if ($supplierId <= 0) {
        throw new Exception('Invalid supplier for replacement purchase entry');
    }
    if ('' === $batchNumber || !$expiryDate || $quantity <= 0) {
        throw new Exception('Invalid replacement purchase payload');
    }

    $transactionId = function_exists('generateTransactionId')
        ? generateTransactionId('REP')
        : ('REP-'.strtoupper(substr(uniqid('', true), -10)));
    $invoiceNumber = $reference;
    if (strlen($invoiceNumber) > 50) {
        $invoiceNumber = substr($invoiceNumber, 0, 50);
    }
    $purchaseDate = date('Y-m-d');
    $subtotal = $quantity * $purchaseRate;
    $taxAmount = 0.0;
    $discountAmount = 0.0;
    $finalAmount = $subtotal;
    $paymentStatus = 'PAID';
    $paymentDetails = '{}';
    $entryTypeText = $isExistingBatch ? 'existing replacement batch updated' : 'new replacement batch created';
    $notes = sprintf(
        'Expiry replacement entry via adjustment %s (%s). Batch %s, Expiry %s, Qty %d.',
        $adjustmentCode,
        $entryTypeText,
        $batchNumber,
        $expiryDate,
        $quantity
    );
    $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

    $stmt = $conn->prepare('INSERT INTO pharmacy_purchases
                            (transaction_id, invoice_number, supplier_id, invoice_date,
                             purchase_date, subtotal, tax_amount, discount_amount,
                             final_amount, payment_status, payment_details, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param(
        'ssissddddsssi',
        $transactionId,
        $invoiceNumber,
        $supplierId,
        $purchaseDate,
        $purchaseDate,
        $subtotal,
        $taxAmount,
        $discountAmount,
        $finalAmount,
        $paymentStatus,
        $paymentDetails,
        $notes,
        $createdBy
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to record replacement purchase entry');
    }
}

function revertAdjustment($conn, $adjustmentId)
{
    $header = getAdjustmentHeader($conn, $adjustmentId);
    if (!$header) {
        throw new Exception('Stock adjustment header not found');
    }
    if (1 === (int) $header['is_reverted']) {
        throw new Exception('This stock adjustment is already reverted');
    }

    $itemStmt = $conn->prepare('SELECT batch_id, system_quantity, final_quantity
                                FROM pharmacy_stock_adjustment_items
                                WHERE adjustment_id = ?');
    $itemStmt->bind_param('i', $adjustmentId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $batchStmt = $conn->prepare('SELECT batch_id, batch_number, current_quantity
                                 FROM pharmacy_batches
                                 WHERE batch_id = ?
                                 FOR UPDATE');
    $updateBatchStmt = $conn->prepare('UPDATE pharmacy_batches SET current_quantity = ? WHERE batch_id = ?');

    foreach ($items as $item) {
        $batchId = (int) $item['batch_id'];
        $batchStmt->bind_param('i', $batchId);
        $batchStmt->execute();
        $batch = $batchStmt->get_result()->fetch_assoc();

        if (!$batch) {
            throw new Exception('Batch not found while reverting stock adjustment');
        }

        if ((int) $batch['current_quantity'] !== (int) $item['final_quantity']) {
            throw new Exception('Cannot revert because stock changed after this adjustment for batch '.$batch['batch_number']);
        }

        $systemQuantity = (int) $item['system_quantity'];
        $updateBatchStmt->bind_param('ii', $systemQuantity, $batchId);
        if (!$updateBatchStmt->execute()) {
            throw new Exception('Failed to restore live stock during revert');
        }
    }

    $isReverted = 1;
    $revertedAt = date('Y-m-d H:i:s');
    $headerStmt = $conn->prepare('UPDATE pharmacy_stock_adjustments
                                  SET is_reverted = ?, reverted_at = ?
                                  WHERE adjustment_id = ?');
    $headerStmt->bind_param('isi', $isReverted, $revertedAt, $adjustmentId);
    if (!$headerStmt->execute()) {
        throw new Exception('Failed to mark stock adjustment as reverted');
    }
}
