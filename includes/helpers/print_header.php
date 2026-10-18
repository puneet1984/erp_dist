<?php
if (!defined('SECURE_ACCESS')) {
    die('Direct access not permitted');
}
require_once '../../includes/core.php';
$settings = getHospitalSettings();
printBillHeader('Bill Header', $settings);
function printBillHeader($title = '', $settings = [])
{
    ?>
<!DOCTYPE html>
<html>

<head>
    <title><?php echo $title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            @page {
                size: A5 landscape;
                margin-top: 0.2cm;
				margin: 0.2cm;
            }

            body {
                margin: 0;
                padding: 0;
                font-size: 10pt;
                background: white;
            }

            .no-print {
                display: none !important;
            }

            .header {
                margin-top: 0;
            }
        }

        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            font-size: 12px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }

        .header h2 {
            margin: 0;
            color: #333;
            font-size: 24px;
        }

        .header p {
            margin: 5px 0;
            color: #666;
        }

        .bill-info {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
        }

        .bill-info div {
            margin: 5px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 11px;
        }

        th {
            background-color: #f5f5f5;
        }

        .totals {
            float: right;
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }

        .print-btn {
            padding: 10px 20px;
            margin: 20px;
            cursor: pointer;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
        }

        .print-btn:hover {
            background-color: #45a049;
        }

        .hospital-logo {
            max-width: 100px;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <?php if (file_exists('../../assets/images/logo.png')): ?>
        <img src="../../assets/images/logo.png" alt="Hospital Logo" class="hospital-logo">
        <?php endif; ?>
        <h2><?php echo HOSPITAL_NAME; ?></h2>
        <p><?php echo HOSPITAL_ADDRESS; ?></p>
        <p>Contact: <?php echo HOSPITAL_PHONE1; ?></p>
        <p>Email: <?php echo HOSPITAL_EMAIL; ?></p>
        <?php if (isset($settings['gst_number'])): ?>
        <p>GST No: <?php echo htmlspecialchars($settings['gst_number']); ?></p>
        <?php endif; ?>
        <?php if (isset($settings['drug_license'])): ?>
        <p>Drug License: <?php echo htmlspecialchars($settings['drug_license']); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
?>