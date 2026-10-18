<?php

session_start();

// Overwrite all session data first
$_SESSION = [];

// Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Regenerate to prevent session fixation, then destroy
session_regenerate_id(true);
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
