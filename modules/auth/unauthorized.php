<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
if (PHP_SESSION_NONE === session_status()) {
    session_start();
}

// Log unauthorized access attempt
error_log("Unauthorized access attempt - User: " . ($_SESSION['username'] ?? 'Guest') . 
         " IP: " . $_SERVER['REMOTE_ADDR'] . 
         " URL: " . ($_SERVER['HTTP_REFERER'] ?? 'Direct access'));

// Set proper HTTP response code
http_response_code(403);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Unauthorized Access</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .error-container { max-width: 600px; margin: 50px auto; text-align: center; padding: 20px; }
        .error-container h1 { color: #d9534f; }
        .button-group { margin-top: 20px; }
        .button-group a { 
            display: inline-block; 
            margin: 0 10px; 
            padding: 10px 20px; 
            background-color: #337ab7; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
        }
        .button-group a.back { background-color: #5bc0de; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Access Denied</h1>
        <p>You do not have permission to access this page.</p>
        <div class="button-group">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="../dashboard/">Return to Dashboard</a>
                <a href="javascript:history.back()" class="back">Go Back</a>
            <?php else: ?>
                <a href="login.php">Login</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>