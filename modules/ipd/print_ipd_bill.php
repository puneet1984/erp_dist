<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'print')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get bill details
$bill_id = $_GET['id'] ?? '';
$conn = getDbConnection();

// Get bill with admission details
$stmt = $conn->prepare("
    SELECT b.*, a.*, p.name as patient_name, p.age, p.gender, p.address,
           w.ward_name, w.ward_type, w.charge_per_day,
           d.name as doctor_name, d.degree,
           dept.name as dept_name
    FROM bills b
    JOIN ipd_admissions a ON b.admission_id = a.admission_id
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN wards w ON a.ward_id = w.id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN departments dept ON d.dept_id = dept.dept_id
    WHERE b.bill_id = ?
");

$stmt->bind_param("s", $bill_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();

if (!$bill) {
    die("Bill not found");
}

// Get bill items
$stmt = $conn->prepare("
    SELECT bi.*, s.name as service_name 
    FROM bill_items bi
    LEFT JOIN services s ON bi.service_id = s.service_id
    WHERE bi.bill_id = ?
    ORDER BY bi.id
");
$stmt->bind_param("s", $bill_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get payment details
$stmt = $conn->prepare("
    SELECT pc.*, pt.type_name as payment_mode
    FROM payment_collections pc
    JOIN payment_types pt ON pc.payment_type_id = pt.payment_type_id
    WHERE pc.bill_id = ?
    ORDER BY pc.collection_id
");
$stmt->bind_param("s", $bill_id);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate admission duration
$admission_date = new DateTime($bill['admission_date']);
$end_date = $bill['discharge_date'] ? new DateTime($bill['discharge_date']) : new DateTime();
$days = $end_date->diff($admission_date)->days + 1;
?>

<!DOCTYPE html>
<html>
<head>
    <title>IPD Bill #<?php echo $bill_id; ?></title>
    <link rel="stylesheet" href="../../assets/css/print_bill.css">
</head>
<body class="print-page">
    <div class="bill-header">
        <h1><?php echo HOSPITAL_NAME; ?></h1>
        <h2><?php echo $bill['bill_type']; ?> BILL</h2>
        <div class="bill-meta">
            <div>Bill No: <?php echo $bill_id; ?></div>
            <div>Date: <?php echo date('d-m-Y', strtotime($bill['bill_date'])); ?></div>
        </div>
    </div>

    <div class="patient-info">
        <div class="row">
            <div class="col">
                <strong>Patient Name:</strong> <?php echo $bill['patient_name']; ?><br>
                <strong>Age/Gender:</strong> <?php echo $bill['age']; ?>/<?php echo $bill['gender']; ?><br>
                <strong>Address:</strong> <?php echo $bill['address']; ?>
            </div>
            <div class="col">
                <strong>IP Number:</strong> <?php echo $bill['admission_id']; ?><br>
                <strong>Admission Date:</strong> <?php echo date('d-m-Y', strtotime($bill['admission_date'])); ?><br>
                <?php if ($bill['discharge_date']): ?>
                    <strong>Discharge Date:</strong> <?php echo date('d-m-Y', strtotime($bill['discharge_date'])); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <table class="bill-items">
        <thead>
            <tr>
                <th>Description</th>
                <th>Quantity</th>
                <th>Rate</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo $item['service_name']; ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>₹<?php echo number_format($item['total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="text-right">Total:</td>
                <td>₹<?php echo number_format($bill['total_amount'], 2); ?></td>
            </tr>
            <?php if ($bill['discount'] > 0): ?>
                <tr>
                    <td colspan="3" class="text-right">Discount:</td>
                    <td>₹<?php echo number_format($bill['discount'], 2); ?></td>
                </tr>
            <?php endif; ?>
            <tr class="grand-total">
                <td colspan="3" class="text-right">Net Amount:</td>
                <td>₹<?php echo number_format($bill['final_amount'], 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($payments)): ?>
        <div class="payment-info">
            <h3>Payment Details</h3>
            <?php foreach ($payments as $payment): ?>
                <div class="payment-row">
                    <?php echo $payment['payment_mode']; ?> - 
                    ₹<?php echo number_format($payment['amount'], 2); ?>
                    (<?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?>)
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bill-footer">
        <p>This is a computer generated bill</p>
    </div>

    <?php if (isset($_GET['print']) && $_GET['print'] == 1): ?>
        <script>window.print();</script>
    <?php endif; ?>
</body>
</html>
