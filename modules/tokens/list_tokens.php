<?php

define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/wa_service/whatsapp_sender.php';

// Check permissions
if (!canAccessTokens()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

$conn = getTokensDbConnection();
$doctors = getDoctorsList();

// Handle AJAX POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ('send_whatsapp' === $_POST['action']) {
        if (!canResendToken()) {
            echo json_encode(['success' => false, 'message' => 'Not authorized to send WhatsApp']);
            exit;
        }
        
        $contact = sanitizeInput($_POST['contact'] ?? '');
        $tokenNumber = sanitizeInput($_POST['tokenNumber'] ?? '');
        $doctorName = sanitizeInput($_POST['doctorName'] ?? '');
        $patientName = sanitizeInput($_POST['patientName'] ?? '');
        $date = sanitizeInput($_POST['date'] ?? '');
        
        if (!$contact || !$tokenNumber || !$doctorName) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        $message = "Token for\n{$patientName}:\nToken Number: {$tokenNumber}\nDate: {$date}\nDoctor: {$doctorName}";
        $result = sendWhatsAppMessage($contact, $message);
        
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'Token sent via WhatsApp']);
        } else {
            $err = $result['response']['message'] ?? ($result['error'] ?? 'Failed to send');
            echo json_encode(['success' => false, 'message' => $err]);
        }
        exit;
    } elseif ('cancel_token' === $_POST['action']) {
        if (!canCancelToken()) {
            echo json_encode(['success' => false, 'message' => 'Not authorized to cancel tokens']);
            exit;
        }
        
        $tokenId = intval($_POST['tokenId'] ?? 0);
        if ($tokenId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid token ID']);
            exit;
        }
        
        $cancelResult = cancelToken($conn, $tokenId);

        if ($cancelResult['success']) {
            echo json_encode(['success' => true, 'message' => 'Token cancelled successfully']);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $cancelResult['message'] ?? 'Failed to cancel token',
            ]);
        }
        exit;
    }
}

// GET request - display token list grouped by doctor and ordered by token number
$tokensByDoctor = getAllTokensByDoctor($conn);

$billingConn = $conn;
foreach ($tokensByDoctor as &$doctorTokens) {
    foreach ($doctorTokens as &$token) {
        $patientId = (int) preg_replace('/\D+/', '', $token['reconstructed_id']);
        $token['is_new_patient'] = false;
        $token['is_paid_consultation'] = false;

        if ($patientId > 0) {
            $stmt = $billingConn->prepare('SELECT created_at FROM patients WHERE patient_id = ? LIMIT 1');
            $stmt->bind_param('i', $patientId);
            $stmt->execute();
            $patient = $stmt->get_result()->fetch_assoc();
            if ($patient && $token['date'] === date('Y-m-d', strtotime($patient['created_at']))) {
                $token['is_new_patient'] = true;
            }
        }

        $doctorId = (int) ($token['doctor_id'] ?? 0);
        if ($doctorId <= 0) {
            $doctorId = getErpDoctorIdForToken($token['doctor_name']);
        }

        if ($patientId > 0 && $doctorId > 0) {
            $stmt = $billingConn->prepare("SELECT b.payment_status
                FROM bills b
                JOIN bill_items bi ON b.bill_id = bi.bill_id
                JOIN services s ON bi.service_id = s.service_id
                WHERE b.patient_id = ? AND b.doctor_id = ? AND b.bill_date = ?
                  AND b.status = 'ACTIVE'
                  AND s.category IN ('CONSULTATION', 'BNP')
                ORDER BY b.created_at DESC LIMIT 1");
            $stmt->bind_param('iis', $patientId, $doctorId, $token['date']);
            $stmt->execute();
            $sameDayBill = $stmt->get_result()->fetch_assoc();

            if ($sameDayBill && 'PAID' === $sameDayBill['payment_status']) {
                $token['is_paid_consultation'] = true;
            }
        }
    }
}
unset($doctorTokens, $token);

// Enforce token-number ordering inside each doctor group
foreach ($tokensByDoctor as &$doctorTokens) {
    usort($doctorTokens, function ($a, $b) {
        return (int) ($b['token_number'] ?? 0) <=> (int) ($a['token_number'] ?? 0);
    });
}
unset($doctorTokens);

$totalTokens = 0;
$newPatientTokens = 0;
$paidPatientTokens = 0;
$followupPatientTokens = 0;
foreach ($tokensByDoctor as $doctorTokens) {
    foreach ($doctorTokens as $token) {
        $totalTokens++;
        if ($token['is_new_patient']) {
            $newPatientTokens++;
        }
        if ($token['is_paid_consultation']) {
            $paidPatientTokens++;
        }
        if (!$token['is_new_patient'] && !$token['is_paid_consultation']) {
            $followupPatientTokens++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Token Management - ERP</title>
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <style>
        .consultation-marker {
            display: inline-block;
            min-width: 1.5rem;
            margin-left: 0.35rem;
            padding: 0.1rem 0.35rem;
            border-radius: 3px;
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
        }

        .consultation-marker.paid { background: #198754; }
        .consultation-marker.followup { background: #0d6efd; }
        .consultation-marker.new { background: #fd7e14; }
    </style>
</head>
<body class="tokens-list-page">
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container no-print" style="margin-top: 16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <h1 style="margin:0;">Token Management</h1>
            <div class="buttons">
                <a href="<?php echo BASE_PATH; ?>/modules/tokens/index.php" class="btn btn-primary">Generate New Token</a>
            </div>
        </div>
    </div>

    <div class="container no-print">
        <?php if (empty($tokensByDoctor)): ?>
            <div class="alert alert-info">
                <p>No tokens available for today.</p>
            </div>
        <?php else: ?>
            <div class="token-list-container">
                <div class="doctor-tabs" id="doctorTabs">
                    <button type="button" class="doctor-tab active" data-doctor="all" onclick="showDoctorTokens('all', this)">All Doctors</button>
                    <?php foreach (array_keys($tokensByDoctor) as $doctorName): ?>
                        <button
                            type="button"
                            class="doctor-tab"
                            data-doctor="<?php echo htmlspecialchars($doctorName); ?>"
                            onclick="showDoctorTokens('<?php echo htmlspecialchars($doctorName, ENT_QUOTES); ?>', this)">
                            <?php echo htmlspecialchars($doctorName); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="token-count-summary" id="tokenCountSummary">
                    <div class="token-summary-total">
                        <span>Total Tokens</span>
                        <strong><?php echo $totalTokens; ?></strong>
                    </div>
                    <div class="token-summary-doctors">
                        <?php foreach ($tokensByDoctor as $doctorName => $tokens) { ?>
                        <?php
                            $doctorNewTokens = count(array_filter($tokens, fn($token) => $token['is_new_patient']));
                            $doctorPaidTokens = count(array_filter($tokens, fn($token) => $token['is_paid_consultation']));
                            $doctorFollowupTokens = count(array_filter($tokens, fn($token) => !$token['is_new_patient'] && !$token['is_paid_consultation']));
                        ?>
                        <div class="token-summary-doctor">
                            <span class="token-summary-doctor-name"><?php echo htmlspecialchars($doctorName); ?></span>
                            <strong><?php echo count($tokens); ?> Total</strong>
                            <span class="token-summary-status-counts">
                                <span class="token-summary-new">N-<?php echo $doctorNewTokens; ?></span>
                                <span class="token-summary-paid">P-<?php echo $doctorPaidTokens; ?></span>
                                <span class="token-summary-followup">F-<?php echo $doctorFollowupTokens; ?></span>
                            </span>
                        </div>
                        <?php } ?>
                    </div>
                </div>

                <?php foreach ($tokensByDoctor as $doctorName => $tokens): ?>
                    <div class="token-group" data-doctor="<?php echo htmlspecialchars($doctorName); ?>">
                        <h3><?php echo htmlspecialchars($doctorName); ?></h3>
                        <table class="tokens-table">
                            <thead>
                                <tr>
                                    <th>Token</th>
                                    <th>Patient ID</th>
                                    <th>Patient Name</th>
                                    <th>Contact</th>
                                    <th>Generated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tokens as $token): ?>
                                    <tr>
                                        <td class="token-number">#<?php echo htmlspecialchars($token['token_number']); ?></td>
                                        <td><?php echo htmlspecialchars($token['reconstructed_id']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($token['patient_name']); ?>
                                            <?php if ($token['is_paid_consultation']) { ?>
                                            <span class="consultation-marker paid" title="Consultation paid">P</span>
                                            <?php } ?>
                                            <?php if ($token['is_new_patient']) { ?>
                                            <span class="consultation-marker new" title="New patient">N</span>
                                            <?php } ?>
                                            <?php if (!$token['is_paid_consultation'] && !$token['is_new_patient']) { ?>
                                            <span class="consultation-marker followup" title="Follow-up consultation">F</span>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($token['contact'] ?? '---'); ?></td>
                                        <td><?php echo date('d-m-Y H:i:s', strtotime($token['created_at'])); ?></td>
                                        <td>
                                            <div class="token-actions">
                                                <?php if (canPrintToken()): ?>
                                                    <button class="btn btn-print" onclick="printToken(<?php echo htmlspecialchars(json_encode($token)); ?>)">
                                                        Print
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($token['contact']): ?>
                                                    <button class="btn btn-whatsapp" onclick="sendWhatsApp(<?php echo htmlspecialchars(json_encode($token)); ?>)">
                                                        WhatsApp
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canCancelToken()): ?>
                                                    <button class="btn btn-cancel" onclick="cancelToken(<?php echo $token['id']; ?>, '<?php echo htmlspecialchars($token['patient_name']); ?>')">
                                                        Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Print Area -->
    <div class="print-area" id="printArea" style="display:none;">
        <div class="print-header">APPOINTMENT TOKEN</div>
        <div class="print-content">
            <p><strong>Patient ID: </strong><span id="printReconstructedId">---</span></p>
            <p><strong>Patient Name: </strong><span id="printPatientName">---</span></p>
            <p><strong>Doctor: </strong><span id="printDoctorName">---</span></p>
            <p class="token-large"><strong>Token Number: </strong><span id="printTokenNumber">---</span></p>
            <p><strong>Date: </strong><span id="printDate">---</span></p>
        </div>
    </div>

    <script src="../../assets/js/tokens.js?v=<?php echo time(); ?>"></script>
    <script>
        window.tokenConfig = {
            apiGetPatient: '<?php echo BASE_PATH; ?>/includes/helpers/get_patient.php',
            apiGenerateToken: '<?php echo BASE_PATH; ?>/modules/tokens/index.php',
            apiListTokens: '<?php echo BASE_PATH; ?>/modules/tokens/list_tokens.php'
        };
    </script>
</body>
</html>
