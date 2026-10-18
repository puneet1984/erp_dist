<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'cancel')) {
    exit('Access Denied');
}

$bill_id = $_GET['id'] ?? '';
$type = $_GET['type'] ?? 'hospital';

// Get bill details including payment info
$conn = getDbConnection();
$stmt = $conn->prepare('SELECT b.*, p.name as patient_name, pt.type_name as payment_type,
                       pc.amount as paid_amount, pc.payment_type_id, pc.payment_details
                       FROM bills b 
                       JOIN patients p ON b.patient_id = p.patient_id
                       JOIN payment_types pt ON b.payment_type_id = pt.payment_type_id
                       JOIN payment_collections pc ON b.bill_id = pc.bill_id
                       WHERE b.bill_id = ?');
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cancel Bill</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .refund-details {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .warning {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .checkbox-group {
            margin: 10px 0;
        }
        .checkbox-label {
            display: block;
            margin: 5px 0;
            cursor: pointer;
        }
        .checkbox-label input[type="radio"] {
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Cancel Bill</h2>
        
        <div class="bill-info">
            <p><strong>Bill Number:</strong> <?php echo htmlspecialchars($bill_id); ?></p>
            <p><strong>Patient:</strong> <?php echo htmlspecialchars($bill['patient_name']); ?></p>
            <p><strong>Amount:</strong> ₹<?php echo number_format($bill['final_amount'], 2); ?></p>
            <p><strong>Payment Mode:</strong> <?php echo htmlspecialchars($bill['payment_type']); ?></p>
        </div>

        <div class="warning">
            Warning: Canceling this bill will initiate a refund process. This action cannot be undone.
        </div>

        <form id="cancelBillForm">
            <input type="hidden" name="bill_id" value="<?php echo htmlspecialchars($bill_id); ?>">
            
            <div class="refund-details">
                <h3>Cancellation Details</h3>
                <p>Original Payment Mode: <?php echo htmlspecialchars($bill['payment_type']); ?></p>
                <p>Amount to Refund: ₹<?php echo number_format($bill['final_amount'], 2); ?></p>
                
                <div class="form-group">
                    <label>Cancellation Type:</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="radio" name="cancel_type" value="REFUND" required> Refund (Amount Refunded)
                        </label>
                        <label class="checkbox-label">
                            <input type="radio" name="cancel_type" value="CANCELLED"> Cancel (No Refund/Duplicate Bill)
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Cancellation Reason:</label>
                    <select name="cancel_reason" required>
                        <option value="">Select Reason</option>
                        <option value="PATIENT_REQUEST">Patient Request</option>
                        <option value="BILLING_ERROR">Billing Error</option>
                        <option value="SERVICE_NOT_PROVIDED">Service Not Provided</option>
                        <option value="DUPLICATE_BILL">Duplicate Bill</option>
                        <option value="OTHER">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Additional Notes:</label>
                    <textarea name="cancel_notes" rows="3"></textarea>
                </div>

                <div class="form-group refund-reference" style="display: none;">
                    <label>Refund Reference Number:</label>
                    <input type="text" name="refund_ref" placeholder="Enter refund transaction reference">
                </div>
            </div>

            <div class="buttons">
                <button type="submit" class="btn-danger">Process Request</button>
                <button type="button" onclick="window.location.href='bills_list.php'" class="btn-secondary">Back</button>
            </div>
        </form>
    </div>

    <script>
        document.querySelectorAll('input[name="cancel_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const refundRef = document.querySelector('.refund-reference');
                const submitBtn = document.querySelector('button[type="submit"]');
                
                if (this.value === 'REFUND') {
                    refundRef.style.display = 'block';
                    refundRef.querySelector('input').required = true;
                    submitBtn.textContent = 'Cancel Bill & Process Refund';
                } else {
                    refundRef.style.display = 'none';
                    refundRef.querySelector('input').required = false;
                    submitBtn.textContent = 'Cancel Bill';
                }
            });
        });

        document.getElementById('cancelBillForm').onsubmit = function(e) {
            e.preventDefault();
            const cancelType = document.querySelector('input[name="cancel_type"]:checked').value;
            const confirmMessage = cancelType === 'REFUND' ? 
                'Are you sure you want to cancel this bill and process the refund?' :
                'Are you sure you want to cancel this bill?';
                
            if (!confirm(confirmMessage)) return;

            const formData = new FormData(this);
            fetch('process_cancellation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(cancelType === 'REFUND' ? 
                        'Bill cancelled and refund initiated successfully' :
                        'Bill cancelled successfully');
                    window.location.href = 'bills_list.php';
                } else {
                    alert('Error: ' + data.message);
                }
            });
        };
    </script>
</body>
</html>
