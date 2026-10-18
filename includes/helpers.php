<?php

// Hospital Settings
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/helpers/settings_helper.php';

define('HOSPITAL_CODE', getHospitalCode());
define('FINANCIAL_YEAR_START_MONTH', 4); // April
require_once __DIR__.'/helpers/date_helper.php';
require_once __DIR__.'/helpers/patient_helper.php';
require_once __DIR__.'/helpers/billing_helper.php';
require_once __DIR__.'/helpers/dashboard_helper.php';
require_once __DIR__.'/helpers/credit_helper.php';
require_once __DIR__.'/helpers/ipd_functions.php';
require_once __DIR__.'/helpers/location_helper.php';
require_once __DIR__.'/helpers/pharmacy_helper.php';
require_once __DIR__.'/helpers/messaging_helper.php';
require_once __DIR__.'/helpers/email_helper.php';
require_once __DIR__.'/helpers/stock_helper.php';
require_once __DIR__.'/helpers/stock_reconciliation_helper.php';
require_once __DIR__.'/helpers/tokens_helper.php';
