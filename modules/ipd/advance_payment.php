<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check billing permissions
if (!checkPermission('bills', 'create')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    header('Content-Type: application/json');
    $conn->begin_transaction();

    try {
        // Get admission details if ID is provided
        if (!isset($_POST['admission_id'])) {
            throw new Exception('Admission ID is required');
        }
        
        $admission = getAdmissionDetails($_POST['admission_id']);
        if (!$admission) {
            throw new Exception('Invalid admission ID');
        }

        $payment_type_id = $_POST['payment_type_id'];
        $payment_status = 'PAID';  // Set default payment status as ADVANCE
        $amount_paid = floatval($_POST['amount']);
        
        // Generate IDs
        $bill_id = generateIPDBillId($conn);
        $transaction_id = generateTransactionId('IPD');

        // Handle different payment types
        switch ($payment_type_id) {
            case 1: // CASH
                $payment_details = [
                    'mode' => 'CASH',
                    'amount' => $amount_paid,
                    'payment_status' => 'ADVANCE',
                    'advance_payment' => true
                ];
                break;

            case 6: // CARD
                $card_type = $_POST['card_type'];
                $fee_percentage = ('credit' === $card_type) ? 0.035 : 0.015;
                $fee_amount = $amount_paid * $fee_percentage;
                $final_with_fee = $amount_paid + $fee_amount;

                $payment_details = [
                    'mode' => 'CARD',
                    'card_type' => $card_type,
                    'fee_percentage' => ('credit' === $card_type) ? 3.5 : 1.5,
                    'fee_amount' => $fee_amount,
                    'reference_no' => $_POST['card_reference'] ?? '',
                    'original_amount' => $amount_paid,
                    'final_amount' => $final_with_fee,
                    'advance_payment' => true
                ];
                $amount_paid = $final_with_fee;
                break;

            case 7: // UPI
                $payment_details = [
                    'mode' => 'UPI',
                    'amount' => $amount_paid,
                    'reference_no' => $_POST['upi_reference'],
                    'payment_status' => 'ADVANCE',
                    'advance_payment' => true
                ];
                break;

            case 8: // SPLIT
                $split_cash = floatval($_POST['split_cash']);
                $split_card = floatval($_POST['split_card']);
                $split_upi = floatval($_POST['split_upi']);
                $card_type = $_POST['split_card_type'];

                $card_fee = $split_card * (('credit' === $card_type) ? 0.035 : 0.015);
                $split_total = $split_cash + $split_card + $split_upi;
                $total_with_fees = $split_total + $card_fee;

                if (abs($split_total - $amount_paid) > 0.01) {
                    throw new Exception('Split payment total does not match amount');
                }

                $payment_details = [
                    'mode' => 'SPLIT',
                    'payments' => [
                        'cash' => [
                            'mode' => 'CASH',
                            'amount' => $split_cash,
                        ],
                        'card' => [
                            'mode' => 'CARD',
                            'amount' => $split_card,
                            'type' => $card_type,
                            'fee_percentage' => ('credit' === $card_type) ? 3.5 : 1.5,
                            'fee_amount' => $card_fee,
                            'reference_no' => $_POST['split_card_reference'] ?? '',
                        ],
                        'upi' => [
                            'mode' => 'UPI',
                            'amount' => $split_upi,
                            'reference_no' => $_POST['split_upi_reference'] ?? '',
                        ],
                    ],
                    'total_amount' => $split_total,
                    'total_with_fees' => $total_with_fees,
                    'advance_payment' => true
                ];
                $amount_paid = $total_with_fees;
                break;

            default:
                throw new Exception('Invalid payment type');
        }

        // Insert into bills table
        $stmt = $conn->prepare('
            INSERT INTO bills (
                bill_id, bill_date, patient_id, admission_id, doctor_id, dept_id,
                total_amount, discount, final_amount, payment_type_id, payment_status,
                payment_details, bill_type, transaction_id
            ) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, "ADVANCE", ?)
        ');

        $payment_details_json = json_encode($payment_details);

        $stmt->bind_param(
            'ssiiiddisss',
            $bill_id,
            $admission['patient_id'],
            $admission['admission_id'],
            $admission['doctor_id'],
            $admission['dept_id'],
            $amount_paid,
            $amount_paid,
            $payment_type_id,
            $payment_status,
            $payment_details_json,
            $transaction_id
        );
        $stmt->execute();

        // Create payment ledger entry
        $stmt = $conn->prepare('
            INSERT INTO payment_ledger (
                bill_id, transaction_id, transaction_date, amount_due, 
                amount_paid, payment_type_id, payment_status, created_by
            ) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?)
        ');

        $stmt->bind_param(
            'ssddisi',
            $bill_id,
            $transaction_id,
            $amount_paid,
            $amount_paid,
            $payment_type_id,
            $payment_status,
            $_SESSION['user_id']
        );
        $stmt->execute();
        $ledger_id = $conn->insert_id;

        // Process payment collection
        $stmt = $conn->prepare('
            INSERT INTO payment_collections (
                ledger_id, bill_id, amount, payment_type_id,
                payment_date, payment_details, collected_by
            ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
        ');

        $stmt->bind_param(
            'isidsi',
            $ledger_id,
            $bill_id,
            $amount_paid,
            $payment_type_id,
            $payment_details_json,
            $_SESSION['user_id']
        );
        $stmt->execute();

        $conn->commit();
        echo json_encode(['status' => 'success', 'bill_id' => $bill_id]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Get admission ID from URL if provided
$admission = null;
if (isset($_GET['admission_id'])) {
    $admission = getAdmissionDetails($_GET['admission_id']);
    if (!$admission) {
        exit('Invalid admission ID');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>IPD Advance Payment</title>
    <link rel="stylesheet" href="../../assets/css/cdn/select2.min.css">
    <link rel="stylesheet" href="../../assets/css/cdn/all.min.css">
    <link rel="stylesheet" href="../../assets/css/generate_bill.css">
    <script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
    <script src="../../assets/js/cdn/select2.min.js"></script>
    <script src="../../assets/js/generate_bill.js"></script>
    <style>
        .btn-back {
            margin-right: 10px;
        }
        .page-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <div class="page-header">
            <button onclick="goBack()" class="btn btn-outline-secondary btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <h2>IPD Advance Payment</h2>
        </div>
        
        <?php if ($admission) { ?>
            <div class="admission-details">
                <div class="row">
                    <div class="col">
                        <h4>Patient Details</h4>
                        <p>Name: <?php echo htmlspecialchars($admission['patient_name']); ?></p>
                        <p>Age/Gender: <?php echo $admission['age'].' / '.$admission['gender']; ?></p>
                        <p><strong>Patient ID:</strong> <?php echo htmlspecialchars('MNH'.str_pad($admission['patient_id'], 8, '0', STR_PAD_LEFT)); ?></p>
                        <p>IP Number: <?php echo htmlspecialchars(str_pad($admission['admission_id'], 4, '0', STR_PAD_LEFT)); ?></p>
                    </div>
                    <div class="col">
                        <h4>Admission Details</h4>
                        <p>Ward: <?php echo htmlspecialchars($admission['ward_name']); ?> (<?php echo $admission['ward_type']; ?>)</p>
                        <p>Bed Number: <?php echo htmlspecialchars($admission['bed_number']); ?></p>
                        <p>Doctor: Dr. <?php echo htmlspecialchars($admission['doctor_name']); ?></p>
                    </div>
                    <div class="col">
                        <h4>Previous Advances</h4>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT b.bill_id, b.bill_date, b.final_amount 
                            FROM bills b
                            WHERE b.admission_id = ? 
                            AND b.bill_type = 'ADVANCE'
                            ORDER BY b.bill_date DESC
                        ");
                        $stmt->bind_param('s', $_GET['admission_id']);
                        $stmt->execute();
                        $advances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        
                        if (empty($advances)) {
                            echo "<p>No previous advances</p>";
                        } else {
                            foreach ($advances as $advance) {
                                echo "<div class='previous-advance'>";
                                echo "<span class='advance-date'>".date('d-m-Y', strtotime($advance['bill_date']))."</span>";
                                echo "<span class='advance-amount'>₹".number_format($advance['final_amount'], 2)."</span>";
                                echo "</div>";
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>

            <form id="advanceForm">
                <input type="hidden" name="admission_id" value="<?php echo $admission['admission_id']; ?>">
                
                <!-- Payment Amount -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Advance Amount:</label>
                        <input type="number" name="amount" id="amount" required min="0" step="0.01" onchange="updatePaymentAmount(this.value)">
                    </div>
                </div>

                <!-- Payment Section -->
                <div class="payment-section">
                    <h3>Payment Details</h3>
                    <div class="form-group">
                        <label>Payment Type:</label>
                        <select name="payment_type_id" required onchange="handlePaymentType(this.value)">
                            <?php
                            $payment_types = $conn->query('SELECT * FROM payment_types WHERE type_name IN ("CASH", "CARD", "UPI", "SPLIT") ORDER BY type_name');
                            while ($type = $payment_types->fetch_assoc()) {
                                $selected = ('CASH' === $type['type_name']) ? 'selected' : '';
                                echo "<option value='".$type['payment_type_id']."' {$selected}>"
                                    .str_replace('_', ' ', $type['type_name']).'</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Payment Details Sections -->
                    <div id="card_details" class="payment-details" style="display:none;">
                        <div class="card-payment-row">
                            <div class="form-group">
                                <label>Card Type:</label>
                                <select name="card_type" onchange="calculateCardFee()">
                                    <option value="debit">Debit Card (1.5% fee)</option>
                                    <option value="credit">Credit Card (3.5% fee)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Card Reference:</label>
                                <input type="text" name="card_reference" required placeholder="Enter card reference number">
                            </div>
                        </div>
                        <div class="card-details-row">
                            <div class="form-group">
                                <label>Convenience Fee:</label>
                                <span id="convenience_fee">0.00</span>
                            </div>
                            <div class="form-group">
                                <label>Final Amount (with fee):</label>
                                <span id="final_with_fee">0.00</span>
                            </div>
                        </div>
                    </div>

                    <div id="upi_details" class="payment-details" style="display:none;">
                        <div class="form-group">
                            <label>UPI Reference:</label>
                            <input type="text" name="upi_reference" required>
                        </div>
                    </div>

                    <div id="split_details" class="payment-details" style="display:none;"></div>

                    </div>

                </div>

                <button type="submit" class="btn-submit">Process Advance Payment</button>
            </form>

            <script>
                $(document).ready(function() {
                    // Set default payment type as cash
                    handlePaymentType(1);

                    // Handle form submission
                    $('#advanceForm').on('submit', function(e) {
                        e.preventDefault();
                        const amount = parseFloat($('#amount').val());
                        if (!amount || amount <= 0) {
                            alert('Please enter a valid amount');
                            return;
                        }

                        const formData = new FormData(this);
                        
                        fetch('advance_payment.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                alert('Advance payment processed successfully!');
                                window.location.href = 'print_bill.php?id=' + data.bill_id;
                            } else {
                                throw new Error(data.message);
                            }
                        })
                        .catch(error => {
                            alert('Failed to process payment: ' + error.message);
                        });
                    });
                });

                function updatePaymentAmount(amount) {
                    // Update card fee if card payment is selected
                    if (document.getElementById('card_details').style.display === 'block') {
                        calculateCardFee();
                    }
                }

                function goBack() {
                    window.history.back();
                }
            </script>

        <?php } else { ?>
            <div class="alert alert-warning">
                Please select an admission from the <a href="ipd_list.php">IPD List</a>
            </div>
        <?php } ?>
    </div>
</body>
</html>