<?php
defined('SECURE_ACCESS') or die('Unauthorized access');

// Creates any missing JSON data/config file (and its folder) with safe defaults
function ensureJsonFiles() {
    $root = __DIR__ . '/../../';
    $categories = [
        'CONSULTATION' => ['Consultation', 'Doctor consultation charges', 1, 0],
        'PROCEDURE' => ['Surgical and Medical Procedure', 'Procedures like dressing, stitching, etc.', 2, 0],
        'DIAGNOSTIC' => ['Diagnostic Test', 'General diagnostic tests', 3, 0],
        'RADIOLOGY' => ['Radiology', 'X-Ray, CT Scan, MRI, etc.', 4, 0],
        'PATHOLOGY' => ['Pathology', 'Blood tests, urine tests, etc.', 5, 0],
        'PHYSIOTHERAPY' => ['Physiotherapy', 'Physical therapy services', 6, 0],
        'PHARMACY' => ['Pharmacy', 'Medication and pharmacy services', 7, 12],
        'BNP' => ['BNP Packages', 'BNP Packages', 8, 0],
        'IPD' => ['IPD Services', 'Visit, room, surgery and administrative charges', 9, 0],
        'OTHER' => ['Other Services', 'Miscellaneous medical services', 99, 0],
    ];
    $serviceCategories = [];
    $taxRates = [];
    foreach ($categories as $key => [$name, $desc, $order, $gst]) {
        $serviceCategories[$key] = ['name' => $name, 'description' => $desc, 'order' => $order];
        $taxRates[$key] = ['gst' => $gst, 'description' => $desc];
    }

    $defaults = [
        'config/hospital_settings.json' => [
            'hospital_name' => '', 'registration_no' => '', 'gst_number' => '', 'drug_license' => '',
            'address' => '', 'phone_primary' => '', 'phone_secondary' => '', 'email' => '', 'website' => '',
            'working_hours_start' => '', 'working_hours_end' => '', 'emergency_contact' => '',
            'timezone' => 'Asia/Kolkata', 'hospital_code' => '', 'doctor_aliases' => [],
            'last_updated' => '', 'logo' => '',
        ],
        'config/bill_settings.json' => [
            'hospital_name' => 'Hospital', 'address_line1' => '', 'contact_number' => '',
            'footer_text' => 'Thank you for your purchase!',
            'terms_conditions' => "1. Medicines once sold will not be taken back or exchanged.\r\n2. Please check expiry date before purchase.\r\n3. Keep medicines away from children.\r\n4. Store in a cool and dry place.",
            'payment_terms' => 0, 'late_payment_fee' => 0, 'default_currency' => 'INR',
        ],
        'config/email_settings.json' => [
            'smtp_host' => getenv('SMTP_HOST') ?: '', 'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
            'smtp_username' => getenv('SMTP_USERNAME') ?: '', 'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
            'smtp_encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', 'api_key' => getenv('EMAIL_API_KEY') ?: '',
        ],
        'config/service_categories.json' => $serviceCategories,
        'config/tax_rates.json' => $taxRates,
        'assets/json/alert_numbers.json' => ['stock_alerts' => []],
        'logs/merge_transactions.json' => [],
        'logs/stock_alert_runs.json' => [],
        'logs/stock_alerts.json' => [],
    ];

    foreach ($defaults as $rel => $content) {
        $path = $root . $rel;
        if (file_exists($path)) {
            continue;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            error_log('Cannot create directory: ' . $dir);
            continue;
        }
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            error_log('Cannot create file: ' . $path);
        }
    }
}
ensureJsonFiles();

function getHospitalSettings() {
    static $settings = null;
    
    if ($settings === null) {
        $settings = []; // Default to an empty array
        $jsonFile = __DIR__ . '/../../config/hospital_settings.json';
        if (file_exists($jsonFile)) {
            $jsonContent = file_get_contents($jsonFile);
            if ($jsonContent !== false) {
                $decodedSettings = json_decode($jsonContent, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $settings = $decodedSettings;
                } else {
                    error_log('Error parsing hospital settings: ' . json_last_error_msg());
                }
            } else {
                error_log('Error reading hospital settings file');
            }
        } else {
            error_log('Hospital settings file not found: ' . $jsonFile);
        }
    }
    
    return $settings;
}

function getSetting($key, $default = '') {
    $settings = getHospitalSettings();
    return $settings[$key] ?? $default;
}

// Define constants for commonly used settings
define('HOSPITAL_NAME', getSetting('hospital_name'));
define('HOSPITAL_EMAIL', getSetting('email'));
define('HOSPITAL_PHONE1', getSetting('phone_primary'));
define('HOSPITAL_PHONE2', getSetting('phone_secondary'));
define('HOSPITAL_ADDRESS', getSetting('address'));
define('HOSPITAL_TIMEZONE', getSetting('timezone', 'IST'));
define('HOSPITAL_SETTINGS_FILE', __DIR__ . '/../../config/hospital_settings.json');
define('EMAIL_SETTINGS_FILE', __DIR__ . '/../../config/email_settings.json');

// SMTP/API settings from config/email_settings.json, falling back to environment variables
function getEmailSettings() {
    $stored = [];
    if (file_exists(EMAIL_SETTINGS_FILE)) {
        $stored = json_decode((string) file_get_contents(EMAIL_SETTINGS_FILE), true) ?: [];
    }
    $defaults = [
        'smtp_host' => getenv('SMTP_HOST') ?: '',
        'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
        'smtp_username' => getenv('SMTP_USERNAME') ?: '',
        'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
        'smtp_encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'api_key' => getenv('EMAIL_API_KEY') ?: '',
    ];
    return array_merge($defaults, array_intersect_key($stored, $defaults));
}

function saveEmailSettings(array $settings) {
    return file_put_contents(EMAIL_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

// Bill code prefix: configured value, else initials of the hospital name
function getHospitalCode() {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) getSetting('hospital_code')));
    if ($code !== '') {
        return $code;
    }
    $initials = '';
    foreach (preg_split('/\s+/', trim((string) getSetting('hospital_name'))) as $w) {
        $initials .= strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $w), 0, 1));
    }
    return $initials !== '' ? $initials : 'HMS';
}

// Textarea text ("Database Name = Display Name" per line) <-> array
function parseDoctorAliases($text) {
    $out = [];
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $parts = explode('=', strip_tags($line), 2);
        if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            $out[trim($parts[0])] = trim($parts[1]);
        }
    }
    return $out;
}

function formatDoctorAliases($aliases) {
    $lines = [];
    foreach ((array) $aliases as $db => $display) {
        $lines[] = $db . ' = ' . $display;
    }
    return implode("\n", $lines);
}

// Keep config/bill_settings.json in step with hospital settings (other bill keys are preserved)
function syncBillSettings(array $hospital) {
    $file = __DIR__ . '/../../config/bill_settings.json';
    $bill = [];
    if (file_exists($file)) {
        $bill = json_decode((string) file_get_contents($file), true) ?: [];
    }
    $bill['hospital_name'] = $hospital['hospital_name'] ?? '';
    $bill['address_line1'] = $hospital['address'] ?? '';
    unset($bill['address_line2']);
    $phones = array_filter([$hospital['phone_primary'] ?? '', $hospital['phone_secondary'] ?? '']);
    $bill['contact_number'] = implode(' / ', $phones);
    $bill['gst_number'] = strtoupper($hospital['gst_number'] ?? '');
    $bill['drug_license'] = strtoupper($hospital['drug_license'] ?? '');
    $bill['email'] = strtolower($hospital['email'] ?? '');
    return file_put_contents($file, json_encode($bill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function isHospitalConfigured() {
    return trim((string) getSetting('hospital_name')) !== '';
}

// Relative path from project root (e.g. uploads/logo/hospital_logo.png) or ''
function getHospitalLogoRelative() {
    $logo = (string) getSetting('logo');
    if ($logo !== '' && file_exists(__DIR__ . '/../../' . $logo)) {
        return $logo;
    }
    return '';
}

// Logo URL for modules located two levels below the project root (modules/x/)
function getHospitalLogoUrl($prefix = '../../') {
    $logo = getHospitalLogoRelative();
    return $logo === '' ? '' : $prefix . $logo;
}

function getHospitalAddressLine() {
    $phones = array_filter([getSetting('phone_primary'), getSetting('phone_secondary'), getSetting('emergency_contact')]);
    $line = (string) getSetting('address');
    if ($phones) {
        $line .= ($line !== '' ? '  |  ' : '') . 'Phone: ' . implode(', ', $phones);
    }
    return $line;
}

// Validates an uploaded logo and stores it; returns relative path. Throws on failure.
function saveHospitalLogo(array $file) {
    $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        throw new Exception('Logo must be PNG, JPG, GIF or WEBP');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $file['size'] > 2 * 1024 * 1024) {
        throw new Exception('Invalid logo image (max 2 MB)');
    }
    $dir = __DIR__ . '/../../uploads/logo/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new Exception('Cannot create logo directory');
    }
    foreach (glob($dir . 'hospital_logo.*') as $old) {
        @unlink($old);
    }
    $name = 'hospital_logo.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        throw new Exception('Failed to upload logo file');
    }
    return 'uploads/logo/' . $name;
}

define('HOSPITAL_LOGO_PATH', getHospitalLogoRelative() !== '' ? __DIR__ . '/../../' . getHospitalLogoRelative() : '');
define('HOSPITAL_LOGO_URL', getHospitalLogoRelative() !== '' ? '/' . getHospitalLogoRelative() : '');