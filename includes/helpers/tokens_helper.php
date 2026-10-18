<?php

// ===== Database Connection =====
/**
 * Tokens live in the main ERP database
 */
function getTokensDbConnection()
{
    return getDbConnection();
}

function getErpDoctorIdForToken($tokenDoctorName)
{
    $name = trim((string) $tokenDoctorName);
    foreach (getDoctorsList() as $doctorId => $displayName) {
        if (0 === strcasecmp($displayName, $name)) {
            return (int) $doctorId;
        }
    }

    return 0;
}

// ===== Core Token Generation Functions =====
/**
 * Get list of doctors available for token generation
 * Uses doctors.name from ERP and maps known names to legacy token labels
 */
function getDoctorsList()
{
    // Fallback shown only when the doctors table is empty/unreachable
    $displayNameMapping = (array) getSetting('doctor_aliases', []);
    $fallbackDoctors = [];
    foreach ($displayNameMapping as $display) {
        $fallbackDoctors[$display] = $display;
    }

    $conn = getDbConnection();
    if (!$conn) {
        return $fallbackDoctors;
    }

    $queries = [
        "SELECT doctor_id, name FROM doctors WHERE status = 'ACTIVE' ORDER BY name ASC",
        'SELECT doctor_id, name FROM doctors ORDER BY name ASC',
    ];

    foreach ($queries as $sql) {
        try {
            $result = $conn->query($sql);
        } catch (Throwable $e) {
            continue;
        }

        if (!$result || 0 === $result->num_rows) {
            continue;
        }

        $doctors = [];
        while ($row = $result->fetch_assoc()) {
            $doctorId = isset($row['doctor_id']) ? (int) $row['doctor_id'] : 0;
            $dbName = trim((string) ($row['name'] ?? ''));
            if ($doctorId <= 0 || '' === $dbName) {
                continue;
            }
            
            // Check if database name has a specific mapping first
            if (isset($displayNameMapping[$dbName])) {
                $displayName = $displayNameMapping[$dbName];
            } else {
                // Format the name: add "Dr." prefix if not already present
                $displayName = $dbName;
                if (0 !== stripos($displayName, 'dr.') && 0 !== stripos($displayName, 'dr ')) {
                    $displayName = 'Dr. ' . $displayName;
                }
            }
            
            // Use doctor_id as key and display name as value.
            $doctors[(string) $doctorId] = $displayName;
        }

        if (!empty($doctors)) {
            return $doctors;
        }
    }

    return $fallbackDoctors;
}

/**
 * Check whether tokens table has doctor_id column
 */
function hasTokensDoctorIdColumn($conn)
{
    static $hasColumn = null;

    if (null !== $hasColumn) {
        return $hasColumn;
    }

    $hasColumn = false;
    try {
        $result = $conn->query("SHOW COLUMNS FROM tokens LIKE 'doctor_id'");
        $hasColumn = $result && $result->num_rows > 0;
    } catch (Throwable $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

/**
 * Get the next sequential token number for a doctor on a given date
 */
function getNextTokenNumber($conn, $doctorName, $date, $doctorId = null)
{
    if (hasTokensDoctorIdColumn($conn) && null !== $doctorId) {
        $query = 'SELECT token_number FROM tokens WHERE doctor_id = ? AND date = ? ORDER BY token_number DESC LIMIT 1';
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            return 1;
        }

        $doctorId = (int) $doctorId;
        $stmt->bind_param('is', $doctorId, $date);
    } else {
        $query = 'SELECT token_number FROM tokens WHERE doctor_name = ? AND date = ? ORDER BY token_number DESC LIMIT 1';
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            return 1; // Start from 1 if query fails
        }

        $stmt->bind_param('ss', $doctorName, $date);
    }

    $stmt->execute();
    $lastTokenNumber = null;
    $stmt->bind_result($lastTokenNumber);
    $stmt->fetch();
    $stmt->close();
    
    return $lastTokenNumber ? $lastTokenNumber + 1 : 1;
}

/**
 * Check if token already exists for patient+doctor+date combination
 */
function tokenExists($conn, $reconstructedId, $patientName, $doctorName, $date, $doctorId = null)
{
    if (hasTokensDoctorIdColumn($conn) && null !== $doctorId) {
        $query = 'SELECT COUNT(*) as count FROM tokens WHERE reconstructed_id = ? AND patient_name = ? AND doctor_id = ? AND date = ?';
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            return false;
        }

        $doctorId = (int) $doctorId;
        $stmt->bind_param('ssis', $reconstructedId, $patientName, $doctorId, $date);
    } else {
        $query = 'SELECT COUNT(*) as count FROM tokens WHERE reconstructed_id = ? AND patient_name = ? AND doctor_name = ? AND date = ?';
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssss', $reconstructedId, $patientName, $doctorName, $date);
    }

    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    return $count > 0;
}

/**
 * Generate and store a new token
 */
function generateToken($conn, $reconstructedId, $patientName, $doctorName, $contact = '', $doctorId = null)
{
    date_default_timezone_set('Asia/Kolkata');
    $date = date('Y-m-d');
    $createdAt = date('Y-m-d H:i:s');
    
    // Clean old tokens first
    cleanOldTokens($conn);
    
    // Check for duplicate
    if (tokenExists($conn, $reconstructedId, $patientName, $doctorName, $date, $doctorId)) {
        return [
            'success' => false,
            'message' => 'Duplicate record found. Appointment already exists for this patient on this date with the selected doctor.',
            'token_number' => null,
        ];
    }
    
    // Get next token number
    $tokenNumber = getNextTokenNumber($conn, $doctorName, $date, $doctorId);
    
    // Insert token
    if (hasTokensDoctorIdColumn($conn) && null !== $doctorId) {
        $query = 'INSERT INTO tokens (reconstructed_id, patient_name, contact, doctor_name, doctor_id, token_number, date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare insert statement: ' . $conn->error,
                'token_number' => null,
            ];
        }

        $doctorId = (int) $doctorId;
        $stmt->bind_param('ssssisss', $reconstructedId, $patientName, $contact, $doctorName, $doctorId, $tokenNumber, $date, $createdAt);
    } else {
        $query = 'INSERT INTO tokens (reconstructed_id, patient_name, doctor_name, token_number, date, contact, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare insert statement: ' . $conn->error,
                'token_number' => null,
            ];
        }

        $stmt->bind_param('sssisss', $reconstructedId, $patientName, $doctorName, $tokenNumber, $date, $contact, $createdAt);
    }

    $success = $stmt->execute();
    $stmt->close();
    
    if (!$success) {
        return [
            'success' => false,
            'message' => 'Failed to generate token: ' . $conn->error,
            'token_number' => null,
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Token generated successfully',
        'token_number' => $tokenNumber,
    ];
}

/**
 * Get all tokens grouped by doctor
 */
function getAllTokensByDoctor($conn)
{
    date_default_timezone_set('Asia/Kolkata');
    $today = date('Y-m-d');
    
    $query = 'SELECT * FROM tokens WHERE date = ? ORDER BY doctor_name ASC, token_number ASC';
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $tokens = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Group by doctor
    $grouped = [];
    foreach ($tokens as $token) {
        $doctor = $token['doctor_name'];
        if (!isset($grouped[$doctor])) {
            $grouped[$doctor] = [];
        }
        $grouped[$doctor][] = $token;
    }
    
    // Sort groups by most recent token
    uasort($grouped, function ($a, $b) {
        $aTime = strtotime(end($a)['created_at'] ?? '');
        $bTime = strtotime(end($b)['created_at'] ?? '');
        return $bTime - $aTime;
    });
    
    return $grouped;
}

/**
 * Cancel/delete a token
 */
function cancelToken($conn, $tokenId)
{
    $query = 'DELETE FROM tokens WHERE id = ?';
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'Failed to prepare delete statement',
        ];
    }
    
    $stmt->bind_param('i', $tokenId);
    $success = $stmt->execute();
    $stmt->close();
    
    if (!$success) {
        return [
            'success' => false,
            'message' => 'Failed to cancel token',
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Token cancelled successfully',
    ];
}

/**
 * Clean old tokens (older than current date)
 */
function cleanOldTokens($conn)
{
    date_default_timezone_set('Asia/Kolkata');
    $today = date('Y-m-d');
    
    $query = 'DELETE FROM tokens WHERE date < ?';
    $stmt = $conn->prepare($query);
    
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $stmt->close();
    }
}

// ===== Permission Helper Functions =====
/**
 * Check if user can access tokens module
 */
function canAccessTokens()
{
    return isAdmin() || checkPermission('tokens', 'view');
}

/**
 * Check if user can create a token
 */
function canCreateToken()
{
    return isAdmin() || checkPermission('tokens', 'create');
}

/**
 * Check if user can print/resend token
 */
function canPrintToken()
{
    return isAdmin() || checkPermission('tokens', 'print');
}

/**
 * Check if user can resend token via WhatsApp
 */
function canResendToken()
{
    return isAdmin() || checkPermission('tokens', 'resend_whatsapp');
}

/**
 * Check if user can cancel token
 */
function canCancelToken()
{
    return isAdmin() || checkPermission('tokens', 'delete');
}
