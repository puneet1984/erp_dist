<?php

define('SECURE_ACCESS', true);
require_once 'includes/core.php';

// Redirect to login page using absolute web path
header('Location: /erp/modules/auth/login.php');
exit;
