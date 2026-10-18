<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!canPrintBill()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$bill_id = $_GET['id'] ?? '';
$type = $_GET['type'] ?? 'hospital';

// Validate bill_id
if (empty($bill_id)) {
    exit('Error: Bill ID is required');
}

$conn = getDbConnection();

if ('pharmacy' === $type) {
    $query = 'SELECT 
              b.bill_id,
              b.bill_date,
              b.total_amount,
              b.discount,
              b.final_amount,
              p.name as patient_name,
              p.contact,
              p.address as patient_address,
              p.city as patient_city,
              b.payment_details,
              b.payment_type_id,
              b.payment_status
              FROM bills b
              JOIN patients p ON b.patient_id = p.patient_id
              WHERE b.bill_id = ? 
              AND b.transaction_id = b.bill_id'; // Ensures it's a pharmacy bill
} else {
    $query = 'SELECT b.*, p.name as patient_name, p.contact, p.address as patient_address, p.city as patient_city,
              d.name as doctor_name, d.specialization, d.registration_no as doctor_reg, dept.name as dept_name
              FROM bills b
              JOIN patients p ON b.patient_id = p.patient_id
              JOIN doctors d ON b.doctor_id = d.doctor_id
              JOIN departments dept ON b.dept_id = dept.dept_id
              WHERE b.bill_id = ?';
}

$stmt = $conn->prepare($query);
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

// Check if bill exists
if (!$bill) {
    exit('Error: Bill not found');
}

// Check if it's an IPD bill and fetch admission details
$is_ipd_bill = (strpos($bill_id, '/IPD/') !== false);
$admission = null;
if ($is_ipd_bill && !empty($bill['admission_id'])) {
    $stmt = $conn->prepare("
        SELECT admission_id, admission_date, discharge_date, status
        FROM ipd_admissions 
        WHERE admission_id = ?");
    $stmt->bind_param('i', $bill['admission_id']);
    $stmt->execute();
    $admission = $stmt->get_result()->fetch_assoc();
}

// Get bill items
if ('pharmacy' === $type) {
    $items_query = 'SELECT 
                   t.quantity,
                   t.unit_price,
                   t.net_amount as total,
                   i.name,
                   i.active_ingredient as generic_name,
                   b.batch_number,
                   t.doctor_name
                   FROM pharmacy_transactions t
                   JOIN pharmacy_items i ON t.item_id = i.item_id
                   JOIN pharmacy_batches b ON t.batch_id = b.batch_id
                   WHERE t.transaction_id = ?
                   AND t.transaction_type = "SALE"';
} else {
    $items_query = 'SELECT bi.*, s.name
                   FROM bill_items bi
                   JOIN services s ON bi.service_id = s.service_id
                   WHERE bi.bill_id = ?';
}

$stmt = $conn->prepare($items_query);
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get payment details and store payment status
$stmt = $conn->prepare("
    SELECT pc.amount, pc.payment_date, pc.payment_type_id, pc.payment_details,
           pt.type_name as payment_mode,
           pl.amount_due, pl.amount_paid, pl.payment_status
    FROM payment_collections pc
    JOIN payment_types pt ON pc.payment_type_id = pt.payment_type_id
    JOIN payment_ledger pl ON pc.ledger_id = pl.ledger_id
    WHERE pc.bill_id = ?
    ORDER BY pc.payment_date ASC");
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get latest payment status from payment_ledger
$stmt = $conn->prepare("
    SELECT payment_status, amount_due, amount_paid 
    FROM payment_ledger 
    WHERE bill_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1");
$stmt->bind_param('s', $bill_id);
$stmt->execute();
$payment_status = $stmt->get_result()->fetch_assoc();
$current_payment_status = $payment_status['payment_status'] ?? $bill['payment_status'] ?? 'PENDING';
?>

<!DOCTYPE html>
<html>

<head>
    <title>Print Bill - <?php echo htmlspecialchars($bill_id); ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/bill.css">
    <script src="../../assets/js/number2words.js"></script>
    <?php if (isset($_GET['trigger_print']) && '1' === $_GET['trigger_print']) { ?>
    <script>
        window.onload = function() {
            window.print();
            window.onafterprint = function() {
                window.close();
            };
        }
    </script>
    <?php } ?>
</head>

<body>
    <div class="print-area">
        <div class="bill-container">
            <!-- Header Section -->
            <div class="row header-row">
                <div class="col-8 d-flex align-items-center">
                    <?php if (getHospitalLogoUrl()): ?>
                    <img src="<?php echo htmlspecialchars(getHospitalLogoUrl()); ?>" alt="Hospital Logo" class="me-3"
                        style="height: 60px;">
                    <?php endif; ?>
                    <div>
                        <h5 class="mb-1"><?php echo HOSPITAL_NAME; ?></h5>
                        <p class="mb-0 small" style="font-size: 12px;"><?php echo HOSPITAL_ADDRESS; ?></p>
                        <p class="mb-0 small" style="font-size: 10px;">Ph: <?php echo HOSPITAL_PHONE1; ?> / <?php echo HOSPITAL_PHONE2; ?></p>
                    </div>
                </div>
                <div class="col-4">
                    <h5 class="text-center mb-2">
                        <?php
                        if ('pharmacy' === $type) {
                            echo 'Pharmacy Bill';
                        } elseif ($is_ipd_bill) {
                            echo ('PAID' === $current_payment_status) ? 'IPD Payment Receipt' : 'IPD Bill';
                        } else {
                            echo ('PAID' === $current_payment_status) ? 'Payment Receipt' : 'Hospital Bill';
                        }
                        ?>
                    </h5>
                    <?php if ('PARTIAL' === $current_payment_status) { ?>
                        <div class="payment-status alert alert-warning mb-2 p-1 text-center">
                            <small>Partial Payment done - Payment Pending</small>
                        </div>
                    <?php } elseif ('PENDING' === $current_payment_status) { ?>
                        <div class="payment-status alert alert-danger mb-2 p-1 text-center">
                            <small>Payment Pending</small>
                        </div>
                    <?php } ?>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th style="width: 80px">Bill No:</th>
                            <td><?php echo htmlspecialchars($bill_id); ?></td>
                        </tr>
                        <tr>
                            <th>Date:</th>
                            <td><?php echo date('d-m-Y', strtotime($bill['bill_date'])); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Details Section -->
            <div class="row details-row">
                <div class="col-6">
                    <div class="card mb-0">
                        <div class="card-body py-2">
                            <h6 class="card-title mb-2">Patient Details</h6>
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td>Name:</td>
                                    <td><?php echo htmlspecialchars($bill['patient_name']); ?></td>
                                </tr>
                                <tr>
                                    <td>Address:</td>
                                    <td><?php echo htmlspecialchars($bill['patient_address']) . ', ' . 
                                               htmlspecialchars($bill['patient_city']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <?php if ('hospital' === $type || $is_ipd_bill) { ?>
                    <div class="card mb-0">
                        <div class="card-body py-2">
                            <h6 class="card-title mb-2">Doctor Details</h6>
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td>Doctor:</td>
                                    <td>Dr. <?php echo htmlspecialchars($bill['doctor_name']) . ', Dept: ' . htmlspecialchars($bill['dept_name']); ?></td>
                                </tr>
                                <tr>
                                    <td>Reg No:</td>
                                    <td><?php echo htmlspecialchars($bill['doctor_reg']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <!-- IPD Details Section -->
            <?php if ($is_ipd_bill && $admission) { ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="card mb-0" style="background-color: #f8f9fa;">
                        <div class="card-body py-2">
                            <h6 class="card-title mb-2">Admission Details</h6>
                            <p class="mb-0" style="font-size: 12px; line-height: 1.2;">
                                <strong>IPD Number:</strong> <span style="font-size: 15px;"><?php echo htmlspecialchars($admission['admission_id']); ?></span>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>Admission Date/Time:</strong> <span style="font-size: 13px; font-weight: 600;"><?php echo date('d-M-Y, H:i', strtotime($admission['admission_date'])); ?></span>
                                <?php if ($admission['discharge_date']) { ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>Discharge Date/Time:</strong> <span style="font-size: 13px; font-weight: 600;"><?php echo date('d-M-Y, H:i', strtotime($admission['discharge_date'])); ?></span>
                                <?php } ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <!-- Services Section -->
            <?php if (!empty($items)) { ?>
            <div class="row services-row mt-4">
                <div class="col-12">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <?php if ('pharmacy' === $type) { ?>
                                <th>Batch</th>
                                <?php } ?>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['name']); ?>
                                </td>
                                <?php if ('pharmacy' === $type) { ?>
                                <td><?php echo htmlspecialchars($item['batch_number']); ?>
                                </td>
                                <?php } ?>
                                <td><?php echo $item['quantity']; ?>
                                </td>
                                <td>₹<?php echo number_format($item['unit_price'], 2); ?>
                                </td>
                                <td>₹<?php echo number_format($item['total'], 2); ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="<?php echo 'pharmacy' === $type ? '4' : '3'; ?>">
                                    Subtotal</td>
                                <td>₹<?php echo number_format($bill['total_amount'], 2); ?></td>
                            </tr>
                            <?php 
                            $total_after_discount = $bill['total_amount'];
                            
                            // Show discount and total after discount if discount exists
                            if ($bill['discount'] > 0) { 
                                $total_after_discount = $bill['total_amount'] - $bill['discount'];
                            ?>
                            <tr>
                                <td colspan="<?php echo 'pharmacy' === $type ? '4' : '3'; ?>">
                                    Discount</td>
                                <td>₹<?php echo number_format($bill['discount'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="<?php echo 'pharmacy' === $type ? '4' : '3'; ?>">
                                    Total after discount</td>
                                <td>₹<?php echo number_format($total_after_discount, 2); ?></td>
                            </tr>
                            <?php }

                            // Look for card payments and fees in payments array
                            $fee_amount = 0;
                            $fee_percentage = 0;

                            foreach ($payments as $payment) {
                                $details = json_decode($payment['payment_details'], true);
                                
                                if ('CARD' === $payment['payment_mode']) {
                                    $fee_amount = $details['fee_amount'] ?? 0;
                                    $fee_percentage = $details['fee_percentage'] ?? 0;
                                    break;
                                } elseif ('SPLIT' === ($details['mode'] ?? '') && 
                                        isset($details['payments']['card'])) {
                                    $card_payment = $details['payments']['card'];
                                    if ($card_payment['amount'] > 0) {
                                        $fee_amount = $card_payment['fee_amount'] ?? 0;
                                        $fee_percentage = $card_payment['fee_percentage'] ?? 0;
                                        break;
                                    }
                                }
                            }

                            // Display card processing fee if exists and calculate final total
                            $final_total = $total_after_discount;
                            if ($fee_amount > 0) { 
                                $final_total += $fee_amount;
                            ?>
                            <tr>
                                <td colspan="<?php echo 'pharmacy' === $type ? '4' : '3'; ?>">
                                    Card Processing Fee (<?php echo $fee_percentage; ?>%)</td>
                                <td>₹<?php echo number_format($fee_amount, 2); ?></td>
                            </tr>
                            <?php } ?>

                            <tr class="total">
                                <td colspan="<?php echo 'pharmacy' === $type ? '4' : '3'; ?>">
                                    Final Amount</td>
                                <td>₹<?php echo number_format($final_total, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <p class="mb-0">Amount in words: <strong><span id="amountInWords"></span></strong></p>
                                </div>
                            </div>
                            <?php if (!empty($payments) && 'PARTIAL' === $current_payment_status) { ?>
                                <div class="mt-2">
                                    <strong>Payment History: </strong>
                                    <?php 
                                    $total_paid = 0;
                                    foreach ($payments as $pmt) {
                                        $details = json_decode($pmt['payment_details'], true);
                                        echo date('d-m-Y', strtotime($pmt['payment_date'])) . ': ';
                                        echo '₹' . number_format($pmt['amount'], 2) . ' via ' . $pmt['payment_mode'];
                                        
                                        if ('CARD' === $pmt['payment_mode'] && isset($details['fee_amount'])) {
                                            echo ' (+ ₹' . number_format($details['fee_amount'], 2) . ' fee)';
                                        }
                                        echo ', ';
                                        
                                        $total_paid += $pmt['amount'];
                                    }
                                    echo '<br>';
                                    $due_amount = ($payment_status['amount_due'] ?? $final_total) - $total_paid;
                                    echo '<strong>Balance Due / Payment Pending: ₹' . 
                                         number_format($due_amount, 2) . 
                                         '</strong>';
                                    ?>
                                </div>
                            <?php } elseif ('PENDING' === $current_payment_status) { ?>
                                <div class="mt-2 text-danger">
                                    <strong>Payment Status: </strong>Payment Pending<br>
                                    <strong>Balance Due: ₹<?php echo number_format($payment_status['amount_due'] ?? $final_total, 2); ?></strong>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Signature Section -->
                    <div class="row signature-row mt-3">
                        <div class="col-12 text-end">
                            <p>Authorized Signatory</p>
                            <div class="spacer">&nbsp;</div>
                            <div class="spacer">&nbsp;</div>
                            <div class="spacer">&nbsp;</div>
                            <p class="mt-2" style="font-weight: bold;"><?php echo HOSPITAL_NAME; ?></p>
                        </div>
                    </div>

                    <script>
                        // Get amount and split into rupees and paise
                        const amount = <?php echo $final_total; ?>;
                        const rupees = Math.floor(amount);
                        const paise = Math.round((amount - rupees) * 100);
                        
                        // Convert both parts to words
                        let words = rupees > 0 ? convertToIndianWords(rupees) + " Rupees" : "";
                        if (paise > 0) {
                            words += (words ? " and " : "") + convertToIndianWords(paise) + " Paise";
                        }
                        if (rupees === 0 && paise === 0) {
                            words = "Zero Rupees";
                        }
                        
                        document.getElementById('amountInWords').textContent = words + " Only";
                    </script>
                </div>
            </div>
            <?php } ?>
        </div>
        <div class="no-print text-center mt-4 mb-4">
            <button onclick="window.print()" class="btn btn-primary">Print Bill</button>
        </div>
    </div>
</body>

</html>