<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/settings_helper.php';

if (!checkPermission('ipd', 'print')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$admissionId = $_GET['id'] ?? 0;
$summary = generateDischargeSummary($admissionId);

if (!$summary) {
    die('Invalid admission ID');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Discharge Summary - Print</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@500;600&family=Oswald:wght@700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 1cm;
        }
        body {
            font-size: 12px;
            line-height: 1.3;
        }
        .header {
            margin-bottom: 20px;
        }
        .header-top {
            position: relative;
            text-align: center;
            min-height: 60px;
        }
        .logo {
            position: absolute;
            left: 0;
            top: 0;
            max-height: 120px;
        }
        .hospital-name {
    font-family: 'Oswald', 'Impact', 'Arial Black', sans-serif;
    font-size: 26px;
    font-weight: 700;
    text-transform: uppercase;
    color: #e53935; /* Matching the red brand color from the logo */
    letter-spacing: 0.5px;
    line-height: 1.1;
    margin: 0 0 6px 0;
}

.hospital-info {
    font-family: 'Barlow Semi Condensed', 'Arial Narrow', 'Franklin Gothic Medium', sans-serif;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.35;
    color: #2b2b2b;
    margin: 1px 0;
}

.hospital-info strong {
    font-weight: 600;
}
        /* .hospital-name {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
        }
        .hospital-info {
            font-size: 11px;
            margin: 2px 0;
        } */
        .section-title {
            background-color: #f8f9fa;
            padding: 5px;
            margin: 10px 0 5px 0;
            font-weight: bold;
            font-size: 13px;
        }
        .patient-info {
            width: 100%;
            margin-bottom: 10px;
            font-size: 11px;
        }
        .patient-info td, .patient-info th {
            padding: 3px 5px;
        }
        .clinical-section {
            margin-bottom: 10px;
        }
        .clinical-section h6 {
            font-size: 12px;
            font-weight: bold;
            margin: 5px 0;
        }
        .clinical-section p {
            margin: 0 0 5px 0;
            text-align: justify;
        }
        .medications-table {
            font-size: 11px;
            width: 100%;
            margin: 5px 0;
        }
        .medications-table th, .medications-table td {
            padding: 3px 5px;
            border: 1px solid #ddd;
        }
        .signature-section {
            margin-top: 20px;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            margin-top: 40px;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <?php if (getHospitalLogoUrl()): ?>
                <img src="<?php echo htmlspecialchars(getHospitalLogoUrl()); ?>" alt="<?php echo HOSPITAL_NAME; ?>" class="logo">
            <?php endif; ?>
            <div>
                <h1 class="hospital-name"><?php echo HOSPITAL_NAME; ?></h1>
                <p class="hospital-info"><?php echo HOSPITAL_ADDRESS; ?></p>
                <p class="hospital-info">Phone: <?php echo HOSPITAL_PHONE1; ?><?php echo HOSPITAL_PHONE2 ? ', '.HOSPITAL_PHONE2 : ''; ?></p>
                <?php if ('' !== (string) getSetting('emergency_contact')): ?>
                    <p class="hospital-info"><strong>Emergency Contact: <?php echo htmlspecialchars(getSetting('emergency_contact')); ?></strong></p>
                <?php endif; ?>
            </div>
        </div>
        <h2 style="font-size: 16px; margin: 15px 0; text-align: center;">DISCHARGE SUMMARY</h2>
    </div>

    <table class="patient-info table table-bordered">
        <tr>
            <th width="20%">Patient Name</th>
            <td width="30%"><?php echo $summary['patient_details']['name']; ?></td>
            <th width="20%">IPD No.</th>
            <td width="30%"><?php echo $summary['admission_id']; ?></td>
        </tr>
        <tr>
            <th>Age/Gender</th>
            <td><?php echo $summary['patient_details']['age']; ?> years / <?php echo formatGender($summary['patient_details']['gender']); ?></td>
            <th>Patient ID</th>
            <td><?php echo $summary['patient_details']['id']; ?></td>
        </tr>
        <tr>
            <th>Admission Date</th>
            <td><?php echo !empty($summary['admission_details']['admission_date']) ? date('d-m-Y H:i', strtotime($summary['admission_details']['admission_date'])) : ''; ?></td>
            <th>Discharge Date</th>
            <td><?php echo !empty($summary['admission_details']['discharge_date']) ? date('d-m-Y H:i', strtotime($summary['admission_details']['discharge_date'])) : 'Admitted'; ?></td>
        </tr>
        <tr>
            <th>Treating Doctor</th>
            <td>Dr. <?php echo $summary['doctor_details']['name']; ?></td>
            <th>Department</th>
            <td><?php echo $summary['doctor_details']['department']; ?></td>
        </tr>
    </table>

    <div class="clinical-section">
        <div class="section-title">Clinical Details</div>
        <?php
        [$complaintsText, $obstetricHistory] = parseObstetricText($summary['clinical_details']['presenting_complaints'] ?? '', 'history');
        [$surgeryText, $deliveryDetails] = parseObstetricText($summary['clinical_details']['surgery_details'] ?? '', 'delivery');
        $obstetricSections = getObstetricSections();
        $renderObstetric = static function ($section, array $values) use ($obstetricSections) {
            $cells = [];
            foreach ($obstetricSections[$section]['fields'] as $key => [$label, $type]) {
                $value = $values[$key] ?? '';
                if ('' === $value) {
                    continue;
                }
                if ('date' === $type) {
                    $value = date('d-m-Y', strtotime($value));
                } elseif ('datetime' === $type) {
                    $value = date('d-m-Y h:i A', strtotime($value));
                }
                $cells[$key] = '<th>'.htmlspecialchars($label).'</th><td>'.htmlspecialchars($value).'</td>';
            }

            // Short fields share a row; the rest get a full-width row each
            $rowGroups = 'history' === $section
                ? [['gpla', 'lmp', 'edd'], ['past_menstrual_history'], ['previous_pregnancy_status']]
                : [['delivery_mode', 'paediatrician'], ['delivery_datetime', 'baby_gender', 'baby_weight']];
            $totalCols = 2 * max(array_map('count', $rowGroups));
            $rows = '';
            foreach ($rowGroups as $group) {
                $rowCells = array_intersect_key($cells, array_flip($group));
                if (!$rowCells) {
                    continue;
                }
                // Last value cell absorbs the unused columns so every row spans the full table
                $span = $totalCols - 2 * (count($rowCells) - 1) - 1;
                $last = array_key_last($rowCells);
                if ($span > 1) {
                    $rowCells[$last] = str_replace('<td>', '<td colspan="'.$span.'">', $rowCells[$last]);
                }
                $rows .= '<tr>'.implode('', $rowCells).'</tr>';
            }

            return $rows ? '<table class="table table-bordered table-sm mb-2">'.$rows.'</table>' : '';
        };
        ?>
        <h6>Presenting Complaints</h6>
        <p><?php echo nl2br(htmlspecialchars($complaintsText)); ?></p>
        <?php echo $renderObstetric('history', $obstetricHistory); ?>

        <h6>Examination Findings</h6>
        <p><?php echo nl2br($summary['clinical_details']['examination_details'] ?? ''); ?></p>

        <h6>Final Diagnosis</h6>
        <p><?php echo nl2br($summary['clinical_details']['diagnosis'] ?? ''); ?></p>

        <h6>Course in Hospital & Treatment Given</h6>
        <?php [$treatmentText, $investigationsText] = parseInvestigationsText($summary['clinical_details']['treatment_plan'] ?? ''); ?>
        <p><?php echo nl2br(htmlspecialchars($treatmentText)); ?></p>

        <?php if ('' !== $investigationsText): ?>
            <h6>Investigations</h6>
            <p><?php echo nl2br(htmlspecialchars($investigationsText)); ?></p>
        <?php endif; ?>

        <?php if ('' !== $surgeryText || $deliveryDetails): ?>
            <h6>Surgery Details</h6>
            <?php if ('' !== $surgeryText): ?>
            <p>
                <?php echo nl2br(htmlspecialchars($surgeryText)); ?>
                <?php if (!empty($summary['clinical_details']['surgery_date'])): ?>
                    (<?php echo date('d-m-Y', strtotime($summary['clinical_details']['surgery_date'])); ?>)
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <?php echo $renderObstetric('delivery', $deliveryDetails); ?>
        <?php endif; ?>
    </div>

    <div class="section-title">Discharge Medications</div>
    <table class="medications-table">
        <thead>
            <tr>
                <th>Medicine</th>
                <th>Dosage</th>
                <th>Duration</th>
                <th>Frequency / Timing</th>
                <th>Instructions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $medications = $summary['clinical_details']['discharge_medications'] ? 
                json_decode($summary['clinical_details']['discharge_medications'], true) : [];
            if ($medications):
                foreach ($medications as $med): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($med['medicine']); ?></td>
                        <td><?php echo htmlspecialchars($med['dosage']); ?></td>
                        <td><?php echo htmlspecialchars($med['duration']); ?></td>
                        <td><?php echo htmlspecialchars($med['frequency'] ?? $med['timing'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($med['instructions'] ?? $med['notes'] ?? ''); ?></td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>

    <div class="clinical-section">
        <h6>Condition on Discharge</h6>
        <p><?php echo nl2br($summary['clinical_details']['discharge_condition'] ?? ''); ?></p>

        <?php if (!empty($summary['clinical_details']['discharge_notes'])): ?>
            <h6>Additional Notes</h6>
            <p><?php echo nl2br(htmlspecialchars($summary['clinical_details']['discharge_notes'])); ?></p>
        <?php endif; ?>

        <?php if (!empty($summary['clinical_details']['followup_date'])): ?>
            <h6>Follow-up Date</h6>
            <p><?php echo date('d-m-Y', strtotime($summary['clinical_details']['followup_date'])); ?></p>
        <?php endif; ?>
    </div>

    <div class="signature-section row">
        <div class="col-6">
            <div style="text-align: center;">
                <div class="signature-line mx-auto"></div>
                <p>Patient's Signature</p>
            </div>
        </div>
        <div class="col-6">
            <div style="text-align: center;">
                <div class="signature-line mx-auto"></div>
                <p>Doctor's Signature with Stamp</p>
                <p>Dr. <?php echo $summary['doctor_details']['name']; ?><br>
                <?php echo $summary['doctor_details']['degree']; ?><br>
                Reg. No: <?php echo $summary['doctor_details']['registration_no']; ?></p>
            </div>
        </div>
    </div>

    <div class="no-print mt-3 text-center">
        <button onclick="window.print()" class="btn btn-primary">Print</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>
</body>
</html>
