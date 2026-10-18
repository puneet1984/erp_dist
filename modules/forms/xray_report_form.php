<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!isAdmin() && !isDoctor()) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

function formatGenderDisplay($gender)
{
    switch ($gender) {
        case 'M':
            return 'Male';
        case 'F':
            return 'Female';
        case 'O':
            return 'Other';
        default:
            return (string) $gender;
    }
}

$form_data = [
    'title' => 'X-Ray Report Form',
    'heading' => [
        'hospital_name' => getSetting('hospital_name'),
        'address' => getHospitalAddressLine(),
        'form_title' => 'X-RAY REPORT',
    ],
];

$patient_data = null;
$patientId = sanitizeInteger($_GET['patient_id'] ?? 0, 0);
$doctors = [];

$conn = getDbConnection();
$doctorStmt = $conn->prepare(
    "SELECT d.doctor_id, d.name, d.degree, d.registration_no, dp.name AS department
     FROM doctors d
     LEFT JOIN departments dp ON d.dept_id = dp.dept_id
     WHERE d.status = 'ACTIVE'
     ORDER BY d.name"
);
$doctorStmt->execute();
$doctorResult = $doctorStmt->get_result();
while ($doctor = $doctorResult->fetch_assoc()) {
    $doctors[] = $doctor;
}

if ($patientId) {
    $stmt = $conn->prepare(
        'SELECT patient_id, name AS patient_name, age, gender, contact, address, city, state
         FROM patients WHERE patient_id = ?'
    );
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $patient_data = $stmt->get_result()->fetch_assoc() ?: null;
}

$todayDateIso = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($form_data['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #111;
        }

        .form-page {
            font-size: 12px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }

        .hospital-name {
            font-size: 22px;
            font-weight: 700;
        }

        .address {
            font-size: 11px;
            margin-top: 2px;
        }

        .form-title {
            font-size: 14px;
            font-weight: 700;
            margin-top: 6px;
            letter-spacing: 0.4px;
        }

        .section {
            margin: 10px 0;
        }

        .form-row {
            display: flex;
            gap: 14px;
            margin-bottom: 8px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 0;
        }

        .form-group label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .fill-field,
        .readonly-field,
        textarea.fill-field {
            width: 100%;
            box-sizing: border-box;
            border: none;
            border-bottom: 1px solid #000;
            padding: 2px 2px;
            font-size: 11px;
            background: transparent;
        }

        .readonly-field {
            border: 1px solid #d1d5db;
            padding: 3px 4px;
            background: #fff;
        }

        .report-box {
            border: 1px solid #000;
            padding: 8px 10px;
            margin-top: 8px;
        }

        .date-field {
            width: 18ch;
            margin-left: auto;
            display: block;
        }

        .xray-exam-group {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
        }

        .xray-exam-group label {
            text-align: center;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .xray-exam-input {
            width: 26ch;
            max-width: 100%;
            min-width: 18ch;
            margin: 0 auto;
            display: block;
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .report-label {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .report-textarea {
            border: 0;
            min-height: 90px;
            padding: 6px;
            font-size: 11px;
            line-height: 1.4;
            resize: vertical;
            width: 100%;
            box-sizing: border-box;
            outline: none;
        }

        .signature-grid {
            display: flex;
            gap: 20px;
            margin-top: 16px;
        }

        .signature-col {
            flex: 1;
        }

        .line {
            display: inline-block;
            min-width: 170px;
            border-bottom: 1px solid #000;
        }

        .actions {
            text-align: center;
            margin: 18px 0;
        }

        .btn {
            border: none;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 4px;
            color: #fff;
            cursor: pointer;
            margin: 0 5px;
        }

        .btn-print {
            background: #1f7a38;
        }

        .btn-close {
            background: #1f4f9f;
        }

        @media print {
            * {
                box-shadow: none !important;
                text-shadow: none !important;
                background: transparent !important;
            }

            body {
                margin: 2mm;
                font-size: 10.5px;
                color: #000;
            }

            .container,
            .form-page {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 0 !important;
            }

            .actions,
            .action-bar,
            .header,
            .patient-id-group,
            #reported-by-row {
                display: none !important;
            }

            .form-header {
                border-bottom: 1px solid #000 !important;
                margin-bottom: 5px;
                padding-bottom: 3px;
            }

            .hospital-name {
                font-size: 15px;
            }

            .form-title {
                font-size: 12px;
            }

            .address {
                font-size: 10.5px;
            }

            .section {
                margin: 5px 0 !important;
            }

            .form-row {
                gap: 8px !important;
                margin-bottom: 4px !important;
            }

            .readonly-field,
            .fill-field,
            textarea.fill-field {
                padding: 1px 2px !important;
                font-size: 10.5px;
                border-top: 0 !important;
                border-left: 0 !important;
                border-right: 0 !important;
                border-bottom: 1px solid #000 !important;
                background: transparent !important;
                resize: none !important;
            }

            .report-textarea {
                border: 0 !important;
                padding: 2px 0 !important;
                resize: none !important;
                background: transparent !important;
            }

            .report-box {
                border: 1px solid #000 !important;
                padding: 4px 6px !important;
            }

            .patient-info-row .readonly-field,
            .patient-info-row .fill-field,
            .patient-info-row .date-field {
                border: 0 !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .date-field {
                width: 18ch !important;
                margin-left: auto !important;
            }

            .xray-exam-input {
                width: 26ch !important;
                max-width: 100% !important;
                min-width: 18ch !important;
                margin: 0 auto !important;
                text-align: center !important;
                font-size: 12px !important;
            }

            body.hide-referring-doctor-label #referring-doctor-group {
                display: none !important;
            }

            input::placeholder,
            textarea::placeholder {
                color: transparent !important;
                opacity: 0 !important;
            }
        }
    </style>
</head>

<body>
    <?php include_once '../../includes/simple_action_bar.php'; ?>

    <div class="container mt-4 form-page">
        <div class="form-header">
            <div class="hospital-name"><?php echo $form_data['heading']['hospital_name']; ?></div>
            <div class="address"><?php echo $form_data['heading']['address']; ?></div>
            <div class="form-title"><?php echo $form_data['heading']['form_title']; ?></div>
        </div>

        <div class="section">
            <div class="form-row patient-info-row">
                <div class="form-group patient-id-group" style="flex:1;">
                    <label>Patient ID</label>
                    <input id="patient-id-input" class="readonly-field" type="text" inputmode="numeric" pattern="[0-9]*"
                        value="<?php echo htmlspecialchars((string) ($patient_data['patient_id'] ?? ($patientId > 0 ? $patientId : ''))); ?>"
                        placeholder="Enter Patient ID">
                </div>
                <div class="form-group" style="flex:3;">
                    <label>Patient Name</label>
                    <input id="patient-name" class="readonly-field" type="text"
                        value="<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>" readonly
                        style="text-transform:uppercase;">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Age / Sex</label>
                    <input id="patient-age-sex" class="readonly-field" type="text"
                        value="<?php echo htmlspecialchars((string) ($patient_data['age'] ?? '')) . (isset($patient_data['gender']) ? ' / ' . htmlspecialchars(formatGenderDisplay($patient_data['gender'])) : ''); ?>"
                        readonly>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Date</label>
                    <input id="report-date" class="fill-field date-field" type="date"
                        value="<?php echo htmlspecialchars($todayDateIso); ?>">
                </div>
            </div>

            <div class="form-row">
                <div id="referring-doctor-group" class="form-group" style="flex:1;">
                    <label id="referring-doctor-label">Referring Doctor</label>
                    <input id="referring-doctor-input" class="fill-field" type="text" value="">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group xray-exam-group" style="flex:3;">
                    <label>X-Ray Examination</label>
                    <input id="xray-exam-input" class="fill-field xray-exam-input" type="text"
                        placeholder="e.g., Chest PA View" value="" required>
                </div>
            </div>

            <div class="report-label">Impression</div>
            <div class="report-box">
                <textarea class="report-textarea" rows="3"></textarea>
            </div>

            <div class="form-row" id="reported-by-row" style="margin-top: 10px;">
                <div class="form-group" style="flex:3;">
                    <label>Reported By</label>
                    <select id="reported-by-id" class="fill-field"
                        style="border:1px solid #d1d5db; padding:4px; background:#fff;">
                        <option value="">Select Doctor</option>
                        <?php foreach ($doctors as $doctor) {
                            $doctorName = trim((string) ($doctor['name'] ?? ''));
                            $doctorDegree = trim((string) ($doctor['degree'] ?? ''));
                            $registrationNo = trim((string) ($doctor['registration_no'] ?? ''));
                            $department = trim((string) ($doctor['department'] ?? ''));
                            ?>
                            <option value="<?php echo (int) $doctor['doctor_id']; ?>"
                                data-name="<?php echo htmlspecialchars($doctorName); ?>"
                                data-degree="<?php echo htmlspecialchars($doctorDegree); ?>"
                                data-reg="<?php echo htmlspecialchars($registrationNo); ?>"
                                data-dept="<?php echo htmlspecialchars($department); ?>">
                                <?php echo htmlspecialchars($doctorName); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="signature-grid">
            <div class="signature-col">
                <div style="font-size: 11px; font-weight: 700;">Radiologist</div>
                <div style="margin-top: 24px;">Signature: <span class="line">&nbsp; &nbsp;</span></div>
                <div style="margin-top: 8px;">Name: <span id="radiologist-name-text" class="line">&nbsp;</span></div>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-print" type="button" onclick="handlePrint();">Print Form</button>
        </div>
    </div>

    <script>
        function formatGenderDisplayClient(gender) {
            if (gender === 'M') return 'Male';
            if (gender === 'F') return 'Female';
            if (gender === 'O') return 'Other';
            return gender || '';
        }

        function fillPatientDetails(patient) {
            document.getElementById('patient-name').value = patient.name || '';
            document.getElementById('patient-age-sex').value =
                (patient.age || '') + (patient.gender ? ' / ' + formatGenderDisplayClient(patient.gender) : '');
        }

        function updateReportedByText() {
            const select = document.getElementById('reported-by-id');
            const output = document.getElementById('radiologist-name-text');
            const option = select.options[select.selectedIndex];

            if (!option || !option.value) {
                output.innerHTML = '&nbsp;';
                return;
            }

            const name = (option.getAttribute('data-name') || '').trim();
            const degree = (option.getAttribute('data-degree') || '').trim();
            const reg = (option.getAttribute('data-reg') || '').trim();
            const dept = (option.getAttribute('data-dept') || '').trim();

            const details = [];
            if (degree) details.push(degree);
            if (reg) details.push('Reg. No: ' + reg);
            if (dept) details.push(dept);

            const full = 'Dr. ' + name + (details.length ? ' (' + details.join(' | ') + ')' : '');
            output.textContent = full;
        }

        function adjustXrayExamWidth() {
            const input = document.getElementById('xray-exam-input');
            if (!input) return;
            const text = (input.value || input.placeholder || '').trim();
            const chars = Math.max(18, Math.min(60, text.length + 2));
            input.style.width = `${chars}ch`;
        }

        function normalizeXrayExamValue() {
            const input = document.getElementById('xray-exam-input');
            if (!input) return;
            input.value = (input.value || '').toUpperCase();
        }

        function applyPrintVisibilityState() {
            const refInput = document.getElementById('referring-doctor-input');
            const isEmpty = !refInput || !refInput.value.trim();
            document.body.classList.toggle('hide-referring-doctor-label', isEmpty);
        }

        function handlePrint() {
            applyPrintVisibilityState();
            window.print();
        }

        let lastFetchedPatientId = (document.getElementById('patient-id-input').value || '').replace(/\D/g, '');
        let isFetchingPatient = false;

        async function fetchAndAutofillPatient() {
            const patientIdInput = document.getElementById('patient-id-input');
            const patientId = (patientIdInput.value || '').replace(/\D/g, '');

            if (!patientId) { lastFetchedPatientId = ''; return; }
            if (isFetchingPatient || patientId === lastFetchedPatientId) return;

            isFetchingPatient = true;
            try {
                const response = await fetch(`../../includes/helpers/get_patient.php?term=${encodeURIComponent(patientId)}&module=default`);
                const data = await response.json();

                if (!response.ok || data.status !== 'success' || !data.patient) {
                    throw new Error(data.message || 'Patient not found');
                }

                patientIdInput.value = data.patient.id || patientId;
                fillPatientDetails(data.patient);
                lastFetchedPatientId = String(data.patient.id || patientId);
            } catch (error) {
                document.getElementById('patient-name').value = '';
                document.getElementById('patient-age-sex').value = '';
                lastFetchedPatientId = '';
                alert(error.message || 'Unable to fetch patient details');
            } finally {
                isFetchingPatient = false;
            }
        }

        document.getElementById('patient-id-input').addEventListener('blur', fetchAndAutofillPatient);
        document.getElementById('patient-id-input').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); fetchAndAutofillPatient(); }
        });
        document.getElementById('xray-exam-input').addEventListener('input', function () {
            normalizeXrayExamValue();
            adjustXrayExamWidth();
        });
        document.getElementById('reported-by-id').addEventListener('change', updateReportedByText);
        normalizeXrayExamValue();
        adjustXrayExamWidth();
        updateReportedByText();
        window.addEventListener('beforeprint', applyPrintVisibilityState);

        if (new URLSearchParams(window.location.search).get('print') === '1') {
            handlePrint();
        }
    </script>
</body>

</html>