<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

if (!isset($_GET['id'])) {
    die("Admission ID is required");
}

// Get admission details using function instead of direct query
$admission = getAdmissionDetails($_GET['id']);
if (!$admission) {
    die("Invalid admission ID");
}

// Calculate days of stay using the dedicated function
$days_stayed = calculateAdmissionDays(
    $admission['admission_date'], 
    $admission['discharge_date'] ?? date('Y-m-d H:i:s')
);

// Get comprehensive billing summary
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT 
        COALESCE(b.bill_id, ps.bill_id) as bill_id,
        COALESCE(b.bill_date, ps.sale_date) as bill_date,
        CASE 
            WHEN b.bill_id IS NOT NULL THEN 'Hospital Bill'
            WHEN ps.bill_id IS NOT NULL THEN 'Pharmacy Bill'
        END as bill_type,
        COALESCE(b.total_amount, ps.subtotal) as total_amount,
        COALESCE(b.discount, ps.discount_amount) as discount,
        COALESCE(b.final_amount, ps.final_amount) as final_amount,
        COALESCE(b.payment_status, ps.payment_status) as payment_status,
        pt.type_name as payment_mode,
        pl.amount_due,
        pl.amount_paid,
        pl.balance_amount,
        pl.payment_status as ledger_status,
        pc.payment_date,
        pc.amount as paid_amount,
        JSON_UNQUOTE(JSON_EXTRACT(pc.payment_details, '$.mode')) as payment_method,
        COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(pc.payment_details, '$.reference_no')),
            JSON_UNQUOTE(JSON_EXTRACT(pc.payment_details, '$.card.reference_no')),
            JSON_UNQUOTE(JSON_EXTRACT(pc.payment_details, '$.upi.reference_no'))
        ) as reference_no,
        u.username as collected_by,
        GROUP_CONCAT(
            DISTINCT 
            CONCAT(
                pti.quantity, ' x ', 
                pi.name, ' ', 
                COALESCE(pb.batch_number, ''), 
                ' @ ₹', pti.unit_price
            ) 
            SEPARATOR '\n'
        ) as pharmacy_items
    FROM (
        SELECT bill_id, bill_date, total_amount, discount, final_amount, payment_status, payment_type_id, transaction_id 
        FROM bills 
        WHERE admission_id = ?
        UNION ALL
        SELECT bill_id, sale_date, subtotal, discount_amount, final_amount, payment_status, payment_type_id, transaction_id 
        FROM pharmacy_sales 
        WHERE admission_id = ?
    ) merged_bills
    LEFT JOIN bills b ON merged_bills.bill_id = b.bill_id
    LEFT JOIN pharmacy_sales ps ON merged_bills.bill_id = ps.bill_id
    LEFT JOIN payment_types pt ON COALESCE(b.payment_type_id, ps.payment_type_id) = pt.payment_type_id
    LEFT JOIN payment_ledger pl ON merged_bills.transaction_id = pl.transaction_id
    LEFT JOIN payment_collections pc ON pl.ledger_id = pc.ledger_id
    LEFT JOIN users u ON pc.collected_by = u.user_id
    LEFT JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id
    LEFT JOIN pharmacy_batches pb ON pti.batch_id = pb.batch_id
    LEFT JOIN pharmacy_items pi ON pb.item_id = pi.item_id
    GROUP BY COALESCE(b.bill_id, ps.bill_id), pc.collection_id
    ORDER BY bill_date, pc.payment_date");

$stmt->bind_param("ii", $_GET['id'], $_GET['id']);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>IPD Patient View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-weight: 500;
        }
        .status-admitted { background: #e7f5e7; color: #0a6b0a; }
        .status-discharged { background: #f8f9fa; color: #6c757d; }
        .info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .action-buttons {
            position: sticky;
            top: 0;
            background: white;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
            z-index: 100;
        }
        .bill-actions {
            margin-top: 10px;
            text-align: right;
        }
        .bill-actions .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container mt-4">
        <div class="action-buttons mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <h2>IPD Patient Details</h2>
                <div>
                    <?php if ($admission['status'] === 'Admitted'): ?>
                        <a href="discharge.php?id=<?php echo $admission['admission_id']; ?>" 
                           class="btn btn-success">
                            <i class="fas fa-procedures"></i> Discharge Patient
                        </a>
                    <?php endif; ?>
                    <a href="ipd_bill.php?admission_id=<?php echo $admission['admission_id']; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-file-invoice"></i> Generate Bill
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Patient & Admission Info -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Patient Information</h5>
                        <span class="status-badge status-<?php echo strtolower($admission['status']); ?>">
                            <?php echo $admission['status']; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Patient ID:</strong> <?php echo htmlspecialchars($admission['patient_id'] ?? ''); ?></p>
                                <p><strong>Patient Name:</strong> <?php echo htmlspecialchars($admission['patient_name'] ?? ''); ?></p>
                                <p><strong>Age/Gender:</strong> <?php echo $admission['age'] ?? ''; ?>/<?php echo formatGender($admission['gender'] ?? ''); ?></p>
                                <p><strong>Contact:</strong> <?php echo htmlspecialchars($admission['contact'] ?? 'Not provided'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Admission ID:</strong> <?php echo $admission['admission_id']; ?></p>
                                <p><strong>Admission Date:</strong> 
                                    <?php echo date('d-m-Y H:i', strtotime($admission['admission_date'] ?? '')); ?>
                                </p>
                                <p><strong>Days Stayed:</strong> <?php echo $days_stayed; ?> days</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Medical Details -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">Medical Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p><strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($admission['doctor_name'] ?? ''); ?></p>
                                <p><strong>Department:</strong> <?php echo htmlspecialchars($admission['dept_name'] ?? 'Not assigned'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Ward:</strong> <?php echo htmlspecialchars($admission['ward_name'] ?? ''); ?></p>
                                <p><strong>Bed Number:</strong> <?php echo htmlspecialchars($admission['bed_number'] ?? ''); ?></p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <h6>Diagnosis</h6>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($admission['diagnosis'] ?? 'Not provided')); ?></p>
                        </div>

                        <div class="mb-3">
                            <h6>Treatment Plan</h6>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($admission['treatment_plan'] ?? 'Not provided')); ?></p>
                        </div>

                        <?php if ($admission['surgery_details']): ?>
                        <div class="mb-3">
                            <h6>Surgery Details</h6>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($admission['surgery_details'])); ?></p>
                            <p><strong>Surgery Date:</strong> <?php echo date('d-m-Y', strtotime($admission['surgery_date'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($admission['status'] === 'Discharged'): ?>
                        <div class="mb-3">
                            <h6>Discharge Summary</h6>
                            <p><strong>Date:</strong> <?php echo date('d-m-Y H:i', strtotime($admission['discharge_date'])); ?></p>
                            <p><strong>Condition:</strong> <?php echo htmlspecialchars($admission['discharge_condition'] ?? ''); ?></p>
                            <p><strong>Notes:</strong></p>
                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($admission['discharge_notes'] ?? '')); ?></p>
                            
                            <?php if ($admission['discharge_medications']): ?>
                            <h6>Discharge Medications</h6>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th>Dosage</th>
                                        <th>Duration</th>
                                        <th>Timing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (json_decode($admission['discharge_medications'], true) as $med): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($med['medicine']); ?></td>
                                        <td><?php echo htmlspecialchars($med['dosage']); ?></td>
                                        <td><?php echo htmlspecialchars($med['duration']); ?></td>
                                        <td><?php echo htmlspecialchars($med['timing']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Billing Summary -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">Billing Summary</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($bills)): ?>
                            <p class="text-muted">No bills generated yet.</p>
                        <?php else: ?>
                            <?php 
                            $current_bill = '';
                            foreach ($bills as $bill): 
                                if ($current_bill !== $bill['bill_id']):
                                    $current_bill = $bill['bill_id'];
                            ?>
                            <div class="border-bottom mb-3 pb-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6><?php echo $bill['bill_type']; ?> #<?php echo $bill['bill_id']; ?></h6>
                                        <small class="text-muted">
                                            <?php echo date('d-m-Y', strtotime($bill['bill_date'])); ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?php echo $bill['payment_status'] === 'PAID' ? 'success' : 'warning'; ?>">
                                        <?php echo $bill['payment_status']; ?>
                                    </span>
                                </div>
                                <div class="mt-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Total Amount:</span>
                                        <span>₹<?php echo number_format($bill['total_amount'], 2); ?></span>
                                    </div>
                                    <?php if ($bill['discount'] > 0): ?>
                                    <div class="d-flex justify-content-between text-success">
                                        <span>Discount:</span>
                                        <span>₹<?php echo number_format($bill['discount'], 2); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between fw-bold">
                                        <span>Final Amount:</span>
                                        <span>₹<?php echo number_format($bill['final_amount'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($bill['paid_amount']): ?>
                                <div class="mt-2 bg-light p-2 rounded">
                                    <small class="d-block text-muted">Payment Details</small>
                                    <div class="d-flex justify-content-between">
                                        <span>Paid Amount:</span>
                                        <span>₹<?php echo number_format($bill['paid_amount'], 2); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Method:</span>
                                        <span><?php echo htmlspecialchars($bill['payment_method']); ?></span>
                                    </div>
                                    <?php if ($bill['reference_no']): ?>
                                    <div class="d-flex justify-content-between">
                                        <span>Reference:</span>
                                        <span><?php echo htmlspecialchars($bill['reference_no']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="bill-actions">
                                    <a href="view_bill.php?bill_id=<?php echo urlencode($bill['bill_id']); ?>" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye"></i> View Bill
                                    </a>
                                </div>
                            </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
