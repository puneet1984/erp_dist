<?php

// Session timeout settings (10 minutes)
define('SESSION_TIMEOUT', 600); // 10 minutes in seconds (600)
define('SESSION_CHECK_INTERVAL', 30); // Check every 30 seconds (30)

// Session settings must be done before session_start()
if (is_dir('C:\Users\Puneet\Documents\VScode\www\logs')) {
    ini_set('session.save_path', 'C:\Users\Puneet\Documents\VScode\www\logs');
} elseif (is_dir('/var/www/html/logs')) {
    ini_set('session.save_path', '/var/www/html/logs');
}

// Other session settings
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
ini_set('session.cookie_lifetime', 0); // Cookie expires when browser closes
ini_set('session.use_strict_mode', 1); // Reject unrecognized session IDs (prevents fixation)
ini_set('session.cookie_httponly', 1); // Prevent JS access to the cookie
ini_set('session.cookie_samesite', 'Strict'); // Prevent CSRF via cookie
