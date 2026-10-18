<?php

session_start();
require_once 'auth_helper.php';

header('Content-Type: application/json');
echo json_encode(['valid' => checkSessionTimeout()]);