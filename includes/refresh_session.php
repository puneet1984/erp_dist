<?php

session_start();
require_once 'auth_helper.php';

$_SESSION['last_activity'] = time();
header('Content-Type: application/json');
echo json_encode(['status' => 'success']);