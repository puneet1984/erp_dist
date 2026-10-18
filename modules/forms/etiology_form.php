<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('bills', 'create')) {
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

function formatDoctorName($name)
{
    $trimmed = trim((string) $name);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^dr\.?\s+/i', $trimmed)) {
        return $trimmed;
    }

    return 'Dr. ' . $trimmed;
}

$form_data = [
    'title' => 'Etiology and History Declaration Form',
    'heading' => [
        'hospital_name' => getSetting('hospital_name'),
        'address' => getHospitalAddressLine(),
        'form_title' => 'ETIOLOGY, HISTORY AND DECLARATION FORM',
    ],
];

$admission_data = null;
$patient_data = null;
$doctors = [];

$admissionId = sanitizeInteger($_GET['id'] ?? 0, 0);
$patientId = sanitizeInteger($_GET['patient_id'] ?? 0, 0);

if ($admissionId) {
    $admission_data = getAdmissionDetails($admissionId);
    if ($admission_data) {
        $patient_data = $admission_data;
        $patientId = (int) ($admission_data['patient_id'] ?? $patientId);
    }
}

if (!$patient_data && $patientId) {
    $conn = getDbConnection();

    $stmt = $conn->prepare('SELECT patient_id, name AS patient_name, age, gender, contact, address, city, state
                            FROM patients
                            WHERE patient_id = ?');
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $patient_data = $stmt->get_result()->fetch_assoc() ?: null;

    if ($patient_data) {
        $stmt = $conn->prepare('SELECT a.admission_id, a.admission_date, a.discharge_date, a.status,
                                       a.doctor_id, d.name AS doctor_name
                                FROM ipd_admissions a
                                LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
                                WHERE a.patient_id = ?
                                ORDER BY a.admission_date DESC
                                LIMIT 1');
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $latest_admission = $stmt->get_result()->fetch_assoc() ?: null;

        if ($latest_admission) {
            $admission_data = $latest_admission;
            if (empty($patient_data['doctor_name']) && !empty($latest_admission['doctor_name'])) {
                $patient_data['doctor_name'] = $latest_admission['doctor_name'];
            }
        }
    }
}

$conn = getDbConnection();
$doctorResult = $conn->query('SELECT doctor_id, name FROM doctors ORDER BY name ASC');
if ($doctorResult) {
    while ($doctor = $doctorResult->fetch_assoc()) {
        $doctors[] = $doctor;
    }
}

$selectedDoctorId = (int) ($admission_data['doctor_id'] ?? $patient_data['doctor_id'] ?? 0);
$admissionDateDisplay = isset($admission_data['admission_date'])
    ? date('d/m/Y', strtotime($admission_data['admission_date']))
    : date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $form_data['title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #111;
        }

        .form-page {
            font-size: 10px;
            line-height: 1.25;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        .hospital-name {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.1;
        }

        .address {
            font-size: 10px;
            margin-top: 2px;
            line-height: 1.2;
        }

        .form-title {
            font-size: 13px;
            font-weight: 700;
            margin-top: 4px;
        }

        .section {
            margin: 6px 0;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 11px;
            font-weight: 700;
            border-bottom: 1px solid #000;
            margin-bottom: 4px;
            padding-bottom: 1px;
        }

        .form-row {
            display: flex;
            gap: 6px;
            margin-bottom: 4px;
        }

        .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 1px;
        }

        .readonly-field {
            width: 96%;
            border: 1px solid #d1d5db;
            padding: 2px 3px;
            font-size: 10px;
            background: #fff;
        }

        .field-with-button {
            display: block;
        }

        .field-with-button .readonly-field {
            width: 100%;
        }

        .line-block {
            border: none;
            border-bottom: 1px solid #000;
            min-height: 14px;
            margin: 1px 0 2px 0;
        }

        .line-block.tall {
            min-height: 22px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0 6px 0;
        }

        .history-table th,
        .history-table td {
            border: 1px solid #000;
            padding: 3px 4px;
            font-size: 9px;
            vertical-align: middle;
        }

        .history-table th {
            text-align: left;
            font-weight: 700;
        }

        .history-table td.manual-fill {
            height: 14px;
            min-width: 170px;
        }

        .content-heading-label {
            display: block;
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 1px;
        }

        .checkbox-line {
            display: flex;
            align-items: center;
            margin: 3px 0;
            font-size: 9px;
        }

        .box {
            width: 12px;
            height: 12px;
            border: 1.4px solid #000;
            margin-right: 5px;
            display: inline-block;
            background: #fff;
        }

        .signature-grid {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }

        .signature-col {
            flex: 1;
        }

        .signature-box {
            height: 34px;
            border: 1.4px solid #000;
            margin-top: 2px;
        }

        .actions {
            text-align: center;
            margin: 10px 0;
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
                margin: 1.5mm;
                font-size: 9px;
                line-height: 1.2;
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

            select.readonly-field {
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                appearance: none !important;
                background-image: none !important;
                padding-right: 2px !important;
            }

            select.readonly-field::-ms-expand {
                display: none;
            }

            .container {
                max-width: 100%;
            }

            .actions {
                display: none !important;
            }

            .action-bar,
            .header {
                display: none !important;
            }

            .patient-id-group {
                display: none !important;
            }

            .hospital-name {
                font-size: 17px;
            }

            .form-title {
                font-size: 11px;
            }

            .section {
                margin: 4px 0;
            }

            .form-row {
                gap: 5px;
                margin-bottom: 3px;
            }

            .line-block {
                min-height: 11px;
            }

            .line-block.tall {
                min-height: 18px;
            }

            .history-table th,
            .history-table td {
                padding: 2px 3px;
                font-size: 8.5px;
            }

            .history-table td.manual-fill {
                height: 12px;
            }

            .signature-box {
                height: 30px;
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
            <div class="section-title">Patient Details</div>
            <div class="form-row">
                <div class="form-group patient-id-group" style="flex: 1;">
                    <label>Patient ID</label>
                    <div class="field-with-button">
                        <input id="patient-id-input" class="readonly-field" type="text" inputmode="numeric" pattern="[0-9]*" value="<?php echo htmlspecialchars((string) ($patient_data['patient_id'] ?? ($patientId > 0 ? $patientId : ''))); ?>" placeholder="Enter Patient ID">
                    </div>
                </div>
                <div class="form-group" style="flex: 3;">
                    <label>Patient Name</label>
                    <input id="patient-name" class="readonly-field" type="text" value="<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>" readonly>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Age</label>
                    <input id="patient-age" class="readonly-field" type="text" value="<?php echo htmlspecialchars((string) ($patient_data['age'] ?? '')); ?>" readonly>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Gender</label>
                    <input id="patient-gender" class="readonly-field" type="text" value="<?php echo htmlspecialchars(formatGenderDisplay($patient_data['gender'] ?? '')); ?>" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Date - Time</label>
                    <input id="admission-date" class="readonly-field" type="text" value="<?php echo htmlspecialchars($admissionDateDisplay); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Doctor</label>
                    <select id="doctor-name" class="readonly-field">
                        <option value="">Select Doctor</option>
                        <?php foreach ($doctors as $doctor) { ?>
                            <option value="<?php echo (int) $doctor['doctor_id']; ?>" data-name="<?php echo htmlspecialchars((string) $doctor['name']); ?>" <?php echo ((int) $doctor['doctor_id'] === $selectedDoctorId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(formatDoctorName($doctor['name'] ?? '')); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="section">
            <label class="content-heading-label">Chief Complaints / Presenting Symptoms</label>
            <div class="line-block tall"></div>

            <label class="content-heading-label">Probable Etiology(Date/time/mode) - as per Patient History</label>
            <div class="line-block tall"></div>

            <label class="content-heading-label">Treatment Taken Elsewhere:</label>
            <div class="line-block"></div>

            <label class="content-heading-label">Provisional Diagnosis:</label>
            <div class="line-block"></div>

            <label class="content-heading-label">Date of first Consultation:</label>
            <div class="line-block"></div>

            <label class="content-heading-label">Alcohol/intoxicating substance influence (at time of injury):</label>
            <div class="line-block"></div>

            <label class="content-heading-label">FIR/MLC has been done for the above mentioned injury:</label>
            <div class="line-block"></div>
            
            <label class="content-heading-label">Past Medical / Surgical History</label>
            <table class="history-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">Condition</th>
                        <th style="width: 50%;">YES (duration) / NO</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Hypertension</td><td class="manual-fill"></td></tr>
                    <tr><td>Diabetes mellitus</td><td class="manual-fill"></td></tr>
                    <tr><td>Coronary Artery Disease</td><td class="manual-fill"></td></tr>
                    <tr><td>Cerebrovascular Accident</td><td class="manual-fill"></td></tr>
                    <tr><td>Pulmonary Tuberculosis</td><td class="manual-fill"></td></tr>
                    <tr><td>Asthma</td><td class="manual-fill"></td></tr>
                    <tr><td>Chronic Obstructive Pulmonary Disease</td><td class="manual-fill"></td></tr>
                    <tr><td>Any Other Diseases</td><td class="manual-fill"></td></tr>
                    <tr><td>Any surgery in past</td><td class="manual-fill"></td></tr>
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Disclaimer</div>
            <p style="margin: 0 0 6px 0; line-height: 1.4;">
                This certificate has been issued in response to a query raised by the Mediclaim/Health Insurance Company.
                The medical history and treatment documents have been provided by the patient or their attendants.
                The treating doctor(s) and hospital are not responsible for the accuracy or authenticity of the information submitted.
                If any information provided is found to be false, misleading, or intentionally concealed, this certificate shall be considered null and void.
            </p>
            <p style="margin: 0 0 6px 0; line-height: 1.4;">
                यह प्रमाण पत्र मेडिक्लेम/हेल्थ इंश्योरेंस कंपनी द्वारा पूछे गए प्रश्न के उत्तर में जारी किया गया है।
                रोगी या उनके परिजनों द्वारा प्रस्तुत की गई चिकित्सीय इतिहास और उपचार से संबंधित दस्तावेजों के आधार पर यह प्रमाण पत्र तैयार किया गया है।
                उपचार कर रहे चिकित्सक या अस्पताल इन जानकारियों की सत्यता या प्रामाणिकता के लिए उत्तरदायी नहीं हैं।
                यदि प्रस्तुत की गई जानकारी झूठी, भ्रामक या जानबूझकर छिपाई गई पाई जाती है, तो यह प्रमाण पत्र स्वतः निरस्त माना जाएगा।
            </p>
            
            <div class="signature-grid">
                <div class="signature-col">
                    <label>Patient / Guardian Name</label>
                    <div class="line-block"></div>
                    <label>Signature / Thumb Impression</label>
                    <div class="signature-box"></div>
                </div>
                <div class="signature-col">
                    <label>Witness Name / Relation</label>
                    <div class="line-block"></div>
                    <label>Witness Signature</label>
                    <div class="signature-box"></div>
                </div>
                <div class="signature-col">
                    <label>Attending Staff / Doctor</label>
                    <div class="line-block"></div>
                    <label>Signature and Stamp</label>
                    <div class="signature-box"></div>
                </div>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-print" type="button" onclick="window.print();">Print Form</button>
        </div>
    </div>

    <script>
        function formatGenderDisplayClient(gender) {
            if (gender === 'M') return 'Male';
            if (gender === 'F') return 'Female';
            if (gender === 'O') return 'Other';
            return gender || '';
        }

        function formatDateTimeDisplay(dateTime) {
            if (!dateTime) {
                return '';
            }

            const date = new Date(dateTime.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return '';
            }

            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }

        function getCurrentDateTimeDisplay() {
            return formatDateTimeDisplay(new Date().toISOString());
        }

        function setDoctorSelection(doctorId, doctorName) {
            const doctorSelect = document.getElementById('doctor-name');

            if (doctorId && doctorSelect.querySelector(`option[value="${String(doctorId)}"]`)) {
                doctorSelect.value = String(doctorId);
                return;
            }

            if (doctorName) {
                const doctorNameLower = String(doctorName).trim().toLowerCase();
                const options = Array.from(doctorSelect.options);
                const matched = options.find(function (option) {
                    return (option.dataset.name || '').trim().toLowerCase() === doctorNameLower;
                });
                doctorSelect.value = matched ? matched.value : '';
                return;
            }

            doctorSelect.value = '';
        }

        function fillPatientDetails(patient) {
            document.getElementById('patient-name').value = patient.name || '';
            document.getElementById('patient-age').value = patient.age || '';
            document.getElementById('patient-gender').value = formatGenderDisplayClient(patient.gender || '');

            const admission = patient.admission || null;
            document.getElementById('admission-date').value = admission ? formatDateTimeDisplay(admission.admission_date || '') : getCurrentDateTimeDisplay();
            setDoctorSelection(admission ? admission.doctor_id : '', admission ? admission.doctor_name : '');
        }

        let lastFetchedPatientId = (document.getElementById('patient-id-input').value || '').replace(/\D/g, '');
        let isFetchingPatient = false;

        async function fetchAndAutofillPatient() {
            const patientIdInput = document.getElementById('patient-id-input');
            const patientId = (patientIdInput.value || '').replace(/\D/g, '');

            if (!patientId) {
                lastFetchedPatientId = '';
                return;
            }

            if (isFetchingPatient || patientId === lastFetchedPatientId) {
                return;
            }

            isFetchingPatient = true;

            try {
                const response = await fetch(`../../includes/helpers/get_patient.php?term=${encodeURIComponent(patientId)}&module=ipd`);
                const data = await response.json();

                if (!response.ok || data.status !== 'success' || !data.patient) {
                    throw new Error(data.message || 'Patient not found');
                }

                patientIdInput.value = data.patient.id || patientId;
                fillPatientDetails(data.patient);
                lastFetchedPatientId = String(data.patient.id || patientId);
            } catch (error) {
                document.getElementById('patient-name').value = '';
                document.getElementById('patient-age').value = '';
                document.getElementById('patient-gender').value = '';
                document.getElementById('admission-date').value = getCurrentDateTimeDisplay();
                setDoctorSelection('', '');
                lastFetchedPatientId = '';
                alert(error.message || 'Unable to fetch patient details');
            } finally {
                isFetchingPatient = false;
            }
        }

        document.getElementById('patient-id-input').addEventListener('blur', fetchAndAutofillPatient);
        document.getElementById('patient-id-input').addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                fetchAndAutofillPatient();
            }
        });

        if (new URLSearchParams(window.location.search).get('print') === '1') {
            window.print();
        }
    </script>
</body>

</html>
