<?php

/**
 * Get all report settings from database.
 *
 * @return array Associative array of report settings
 */
function getReportSettings()
{
    global $db;
    $db = getDbConnection();

    if (!$db) {
        error_log('Database connection not available in getReportSettings()');

        return getDefaultSettings();
    }

    $defaults = getDefaultSettings();

    try {
        // Simplified query without department join since it's not needed
        $result = mysqli_query($db, "SELECT setting_key, setting_value 
                                   FROM system_settings 
                                   WHERE category = 'reports'");
        
        if (!$result) {
            throw new Exception(mysqli_error($db));
        }

        $settings = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        mysqli_free_result($result);

        // Convert boolean strings to actual booleans
        foreach (['auto_daily_reports', 'auto_email_reports'] as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = (bool)$settings[$key];
            }
        }

        return array_merge($defaults, $settings);
    } catch (Exception $e) {
        error_log('Error fetching report settings: ' . $e->getMessage());

        return $defaults;
    }
}

/**
 * Get default settings.
 *
 * @return array Default settings
 */
function getDefaultSettings()
{
    return [
        'default_format' => 'pdf',
        'paper_size' => 'A4',
        'header_logo' => '',
        'footer_text' => '',
        'auto_daily_reports' => false,
        'daily_report_time' => '23:59',
        'auto_email_reports' => false,
        'report_recipients' => '',
    ];
}

/**
 * Save report settings to database and handle logo upload.
 *
 * @param array $data Form data
 *
 * @return array Response with status and message
 */
function saveReportSettings($data)
{
    global $db;

    if (!$db) {
        error_log('Database connection not available in saveReportSettings()');

        return ['status' => 'error', 'message' => 'Database connection error'];
    }

    try {
        mysqli_begin_transaction($db);

        // Handle logo upload
        if (isset($_FILES['header_logo']) && 0 === $_FILES['header_logo']['error']) {
            $logo = $_FILES['header_logo'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif'];

            if (!in_array($logo['type'], $allowed)) {
                return ['status' => 'error', 'message' => 'Invalid file type'];
            }

            $filename = 'logo_'.time().'.'.pathinfo($logo['name'], PATHINFO_EXTENSION);
            $path = '../../assets/images/'.$filename;

            if (move_uploaded_file($logo['tmp_name'], $path)) {
                $data['header_logo'] = '/assets/images/'.$filename;
            }
        }

        // Save settings to database
        foreach ($data as $key => $value) {
            if ('action' !== $key) {
                $value = is_bool($value) ? (int) $value : $value;
                $key = mysqli_real_escape_string($db, $key);
                $value = mysqli_real_escape_string($db, $value);
                
                $query = "INSERT INTO system_settings (category, setting_key, setting_value) 
                         VALUES ('reports', '$key', '$value')
                         ON DUPLICATE KEY UPDATE setting_value = '$value'";
                
                if (!mysqli_query($db, $query)) {
                    throw new Exception(mysqli_error($db));
                }
            }
        }

        mysqli_commit($db);

        return ['status' => 'success', 'message' => 'Settings saved successfully'];

    } catch (Exception $e) {
        mysqli_rollback($db);
        error_log('Error saving report settings: '.$e->getMessage());

        return ['status' => 'error', 'message' => 'Failed to save settings'];
    }
}

/**
 * Handle AJAX request for saving report settings
 */
function handleSaveSettingsRequest() {
    // Check if this is an AJAX POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        return;
    }

    // Verify user has permission
    if (!function_exists('checkPermission') || !checkPermission('admin', 'edit')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
        return;
    }

    // Call saveReportSettings and return JSON response
    $result = saveReportSettings($_POST);
    header('Content-Type: application/json');
    echo json_encode($result);
}

// Handle AJAX request if this file is called directly
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    require_once __DIR__ . '/../core.php';
    handleSaveSettingsRequest();
}
