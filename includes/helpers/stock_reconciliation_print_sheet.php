<?php
define('SECURE_ACCESS', true);
require_once '../core.php';

requirePermission('pharmacy', 'view');

$financialYear = sanitizeInput($_GET['fy'] ?? getCurrentFinancialYear());
$type = sanitizeInput($_GET['type'] ?? 'YEAR_END');

header('Location: ../../modules/pharmacy/stock_reconciliation.php?fy='.urlencode($financialYear).'&type='.urlencode($type));
exit;
