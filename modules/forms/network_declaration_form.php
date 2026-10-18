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
        case 'M': return 'Male';
        case 'F': return 'Female';
        case 'O': return 'Other';
        default:  return (string) $gender;
    }
}

$form_data = [
    'title' => 'Network Hospital – Declaration by Patient / Patient\'s Attendant',
    'heading' => [
        'hospital_name' => getSetting('hospital_name'),
        'address'       => getHospitalAddressLine(),
        'form_title'    => 'NETWORK HOSPITAL – DECLARATION BY PATIENT / PATIENT\'S ATTENDANT',
    ],
];

$admission_data = null;
$patient_data   = null;

$admissionId = sanitizeInteger($_GET['id']         ?? 0, 0);
$patientId   = sanitizeInteger($_GET['patient_id'] ?? 0, 0);

if ($admissionId) {
    $admission_data = getAdmissionDetails($admissionId);
    if ($admission_data) {
        $patient_data = $admission_data;
        $patientId    = (int) ($admission_data['patient_id'] ?? $patientId);
    }
}

if (!$patient_data && $patientId) {
    $conn = getDbConnection();

    $stmt = $conn->prepare(
        'SELECT patient_id, name AS patient_name, age, gender, contact, address, city, state
         FROM patients WHERE patient_id = ?'
    );
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $patient_data = $stmt->get_result()->fetch_assoc() ?: null;

    if ($patient_data) {
        $stmt = $conn->prepare(
            'SELECT a.admission_id, a.admission_date, a.discharge_date, a.status, a.doctor_id, d.name AS doctor_name
             FROM ipd_admissions a
             LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
             WHERE a.patient_id = ?
             ORDER BY a.admission_date DESC LIMIT 1'
        );
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $latest = $stmt->get_result()->fetch_assoc() ?: null;
        if ($latest) {
            $admission_data = $latest;
        }
    }
}

$admissionDateDisplay = isset($admission_data['admission_date'])
    ? date('d/m/Y', strtotime($admission_data['admission_date']))
    : '';
$admissionTimeDisplay = isset($admission_data['admission_date']) && $admission_data['admission_date']
    ? date('h:i A', strtotime($admission_data['admission_date']))
    : '';
$dischargeDateDisplay = isset($admission_data['discharge_date']) && $admission_data['discharge_date']
    ? date('d/m/Y', strtotime($admission_data['discharge_date']))
    : '';
$todayDisplay = date('d/m/Y');
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
            font-size: 11px;
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
        }

        .section {
            margin: 10px 0;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            border-bottom: 1px solid #000;
            margin-bottom: 6px;
            padding-bottom: 2px;
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

        .fill-field {
            width: 100%;
            box-sizing: border-box;
            border: none;
            border-bottom: 1px solid #000;
            padding: 2px 2px;
            font-size: 11px;
            background: transparent;
        }

        .readonly-field {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            padding: 3px 4px;
            font-size: 11px;
            background: #fff;
        }

        .field-with-button {
            display: block;
        }

        .field-with-button .readonly-field {
            width: 100%;
        }

        .declaration-block {
            border: 1px solid #000;
            padding: 8px 10px;
            margin: 6px 0;
            font-size: 11px;
            line-height: 1.5;
        }

        .declaration-block .dec-heading {
            font-weight: 700;
            margin-bottom: 4px;
            font-size: 11px;
        }

        .option-row {
            display: flex;
            gap: 20px;
            margin: 4px 0;
            align-items: center;
        }

        .checkbox-box {
            width: 13px;
            height: 13px;
            border: 1.5px solid #000;
            display: inline-block;
            margin-right: 4px;
            vertical-align: middle;
            background: #fff;
        }

        .policy-form-row {
            display: flex;
            gap: 0;
            margin: 3px 0;
            align-items: baseline;
        }

        .policy-form-row .plabel {
            font-weight: 700;
            white-space: nowrap;
            margin-right: 6px;
            font-size: 11px;
        }

        .policy-form-row .fill-field {
            flex: 1;
        }

        .long-text {
            font-size: 11px;
            line-height: 1.55;
            text-align: justify;
            margin: 6px 0;
        }

        .signature-grid {
            display: flex;
            gap: 20px;
            margin-top: 14px;
        }

        .signature-col {
            flex: 1;
            text-align: center;
        }

        .signature-box {
            height: 55px;
            border: 1.5px solid #000;
            margin: 4px 0;
        }

        .signature-col .sig-label {
            font-size: 10px;
            font-weight: 700;
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

        .btn-print  { background: #1f7a38; }
        .btn-close  { background: #1f4f9f; }

        @media print {
            * {
                box-shadow: none !important;
                text-shadow: none !important;
                background: transparent !important;
            }

            body {
                margin: 2mm;
                font-size: 9.5px;
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
            .patient-id-group {
                display: none !important;
            }

            .form-header {
                border-bottom: 1px solid #000 !important;
                margin-bottom: 5px;
                padding-bottom: 3px;
            }

            .hospital-name { font-size: 14px; }
            .form-title    { font-size: 11px; }
            .address       { font-size: 9.5px; }

            .section { margin: 5px 0 !important; }

            .form-row {
                gap: 8px !important;
                margin-bottom: 4px !important;
            }

            .readonly-field,
            .fill-field {
                padding: 1px 2px !important;
                font-size: 9.5px;
                border-top: 0 !important;
                border-left: 0 !important;
                border-right: 0 !important;
                border-bottom: 1px solid #000 !important;
                background: transparent !important;
            }

            .declaration-block {
                border: 1px solid #000 !important;
                padding: 4px 6px !important;
                font-size: 9.5px;
            }

            .long-text { font-size: 9.5px; }

            .signature-box {
                height: 40px;
                border: 1px solid #000 !important;
            }

            .signature-grid { gap: 10px; margin-top: 8px; }
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

        <!-- Patient Info -->
        <div class="section">
            <div class="form-row">
                <div class="form-group patient-id-group" style="flex:1;">
                    <label>Patient ID</label>
                    <input id="patient-id-input" class="readonly-field" type="text" inputmode="numeric" pattern="[0-9]*"
                        value="<?php echo htmlspecialchars((string)($patient_data['patient_id'] ?? ($patientId > 0 ? $patientId : ''))); ?>"
                        placeholder="Enter Patient ID">
                </div>
                <div class="form-group" style="flex:3;">
                    <label>Patient Name (Block Letters)</label>
                    <input id="patient-name" class="readonly-field" type="text"
                        value="<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>" readonly style="text-transform:uppercase;">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Age / Sex</label>
                    <input id="patient-age-sex" class="readonly-field" type="text"
                        value="<?php echo htmlspecialchars((string)($patient_data['age'] ?? '')) . (isset($patient_data['gender']) ? ' / ' . htmlspecialchars(formatGenderDisplay($patient_data['gender'])) : ''); ?>" readonly>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Date</label>
                    <input id="form-date" class="fill-field" type="text" value="<?php echo htmlspecialchars($todayDisplay); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label>IP No.</label>
                    <input id="ip-no" class="fill-field" type="text"
                        value="<?php echo htmlspecialchars((string)($admission_data['admission_id'] ?? '')); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>UHID No.</label>
                    <input id="uhid-no" class="fill-field" type="text"
                        value="<?php echo htmlspecialchars((string)($patient_data['patient_id'] ?? '')); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Mobile No. of Patient</label>
                    <input id="patient-contact" class="fill-field" type="text"
                        value="<?php echo htmlspecialchars((string)($patient_data['contact'] ?? '')); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label>Date of Admission</label>
                    <input id="admission-date" class="fill-field" type="text" value="<?php echo htmlspecialchars($admissionDateDisplay); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Time of Admission</label>
                    <input id="admission-time" class="fill-field" type="text" value="<?php echo htmlspecialchars($admissionTimeDisplay); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Date of Discharge</label>
                    <input id="discharge-date" class="fill-field" type="text" value="<?php echo htmlspecialchars($dischargeDateDisplay); ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Time of Discharge</label>
                    <input class="fill-field" type="text" value="">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Address of the Patient</label>
                    <input id="patient-address" class="fill-field" type="text"
                        value="<?php echo htmlspecialchars(trim(($patient_data['address'] ?? '') . ', ' . ($patient_data['city'] ?? '') . ', ' . ($patient_data['state'] ?? ''), ', ')); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>Name of the Attendant</label>
                    <input class="fill-field" type="text" value="">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Relationship with Patient</label>
                    <input class="fill-field" type="text" value="">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label>Mobile No. of Attendant</label>
                    <input class="fill-field" type="text" value="">
                </div>
                <div class="form-group" style="flex:2;">
                    <label>Address of Attendant</label>
                    <input class="fill-field" type="text" value="">
                </div>
            </div>
        </div>

        <!-- Insurance Declaration -->
        <div class="section">
            <div class="section-title">Declaration regarding Insurance Policy <span style="font-weight:400;font-size:10px;">(Strike off the option which is not applicable)</span></div>

            <div class="declaration-block">
                <div class="dec-heading">(i) Declaration when patient has no insurance policy:</div>
                <p style="margin: 2px 0 0 0;">&#9679;&nbsp; I declare that I do not have any insurance policy.</p>
            </div>

            <div class="declaration-block">
                <div class="dec-heading">(ii) Declaration when patient has insurance policy:</div>
                <p style="margin: 2px 0 6px 0;">&#9679;&nbsp; I declare that I have following Insurance Policies</p>

                <div class="policy-form-row">
                    <span class="plabel">Policy No. / TPA Card No.:</span>
                    <input class="fill-field" type="text" value="">
                </div>
                <div class="policy-form-row">
                    <span class="plabel">Insurance Company:</span>
                    <input class="fill-field" type="text" value="">
                </div>
                <div class="policy-form-row">
                    <span class="plabel">Name of TPA:</span>
                    <input class="fill-field" type="text" value="">
                </div>

                <div style="margin: 6px 0 4px 0; font-size: 11px;">
                    Whether patient opted for Eligible Room Category under Policy:&nbsp;&nbsp;
                    <span><span class="checkbox-box"></span> Yes</span>&nbsp;&nbsp;&nbsp;
                    <span><span class="checkbox-box"></span> No</span>
                </div>

                <div style="margin-top: 6px; font-size: 11px; font-weight: 700;">In case, policyholder wishes to avail better facility:</div>
                <div class="policy-form-row" style="margin-top: 4px;">
                    <span class="plabel">Name of Additional Facility / Provision / Procedure / Treatment:</span>
                </div>
                <input class="fill-field" type="text" value="" style="width:100%; margin-bottom: 2px;">
                <input class="fill-field" type="text" value="" style="width:100%; margin-bottom: 4px;">

                <div class="policy-form-row">
                    <span class="plabel">which costs Rs.:</span>
                    <input class="fill-field" type="text" value="" style="flex:0.4;">
                    <span class="plabel" style="margin-left:14px;">In Words:</span>
                    <input class="fill-field" type="text" value="">
                </div>
            </div>

            <p class="long-text">
                On my own option, I wish to avail above better facility and I hereby agree to pay on my free will, after being explained in detail
                by the Hospital authority in my own and understandable language about the above mentioned Additional Facility / Procedure /
                Treatment and associated cost of it, which is over and above the agreed tariff. Further, if I opt to go for final bill reimbursement
                with insurance company, the respective insurance company will reimburse only as per agreed tariff rates and balance amount will be
                borne by the patient or myself only. The concerned hospital will not be liable in any circumstances for any deductions in the
                reimbursed amount.
            </p>
            <p class="long-text">
                I have also been explained that when room of a category higher than eligible room rent is availed by the patient, not only
                the difference in room rent but also an equal proportion of all other charges associated with the treatment shall be borne by me.
            </p>
        </div>

        <!-- Signatures -->
        <div class="signature-grid">
            <div class="signature-col">
                <div class="signature-box"></div>
                <div class="sig-label">Signature of Patient / Patient's Attendant</div>
                <div style="font-size:10px; margin-top:3px;">Name: <span style="display:inline-block; border-bottom:1px solid #000; min-width:160px;">&nbsp;</span></div>
            </div>
            <div class="signature-col">
                <div class="signature-box"></div>
                <div class="sig-label">Signature &amp; Seal of Hospital Representative</div>
                <div style="font-size:10px; margin-top:3px;">Name &amp; Designation: <span style="display:inline-block; border-bottom:1px solid #000; min-width:120px;">&nbsp;</span></div>
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

        function formatDateDisplay(dateTime) {
            if (!dateTime) return '';
            const date = new Date(dateTime.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return '';
            const d = String(date.getDate()).padStart(2, '0');
            const m = String(date.getMonth() + 1).padStart(2, '0');
            return `${d}/${m}/${date.getFullYear()}`;
        }

        function formatTimeDisplay(dateTime) {
            if (!dateTime) return '';
            const date = new Date(dateTime.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return '';
            const rawHours = date.getHours();
            const h = String(((rawHours + 11) % 12) + 1).padStart(2, '0');
            const m = String(date.getMinutes()).padStart(2, '0');
            const ampm = rawHours >= 12 ? 'PM' : 'AM';
            return `${h}:${m} ${ampm}`;
        }

        function todayDisplay() {
            const today = new Date();
            const dd = String(today.getDate()).padStart(2, '0');
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            return `${dd}/${mm}/${today.getFullYear()}`;
        }

        function fillPatientDetails(patient) {
            document.getElementById('patient-name').value = patient.name || '';
            document.getElementById('patient-age-sex').value =
                (patient.age || '') + (patient.gender ? ' / ' + formatGenderDisplayClient(patient.gender) : '');
            document.getElementById('uhid-no').value = patient.id || '';
            document.getElementById('form-date').value = todayDisplay();

            // Address & contact
            const parts = [patient.address || '', patient.city || '', patient.state || ''];
            document.getElementById('patient-address').value = parts.filter(Boolean).join(', ');
            const contactEl = document.getElementById('patient-contact');
            if (contactEl) contactEl.value = patient.contact || '';

            const admission = patient.admission || null;
            if (admission) {
                document.getElementById('ip-no').value = admission.admission_id || '';
                const admissionDate = formatDateDisplay(admission.admission_date || '');
                const admissionTime = formatTimeDisplay(admission.admission_date || '');
                const dischargeDate = formatDateDisplay(admission.discharge_date || '');
                if (admissionDate) {
                    document.getElementById('admission-date').value = admissionDate;
                }
                document.getElementById('admission-time').value = admissionTime;
                if (dischargeDate) {
                    document.getElementById('discharge-date').value = dischargeDate;
                }
            } else {
                document.getElementById('ip-no').value = '';
                document.getElementById('admission-date').value = '';
                document.getElementById('admission-time').value = '';
                document.getElementById('discharge-date').value = '';
                document.getElementById('form-date').value = todayDisplay();
            }
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

        if (new URLSearchParams(window.location.search).get('print') === '1') {
            window.print();
        }
    </script>
</body>

</html>
