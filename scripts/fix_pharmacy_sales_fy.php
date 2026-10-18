<?php
if ('cli' === php_sapi_name()) {
    require_once __DIR__.'/../modules/admin/fix_pharmacy_sales_fy.php';
    exit;
}

header('Location: ../modules/admin/fix_pharmacy_sales_fy.php');
exit;
