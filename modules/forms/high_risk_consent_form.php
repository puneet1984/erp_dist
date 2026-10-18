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
    'title' => 'उच्च जोखिम सर्जरी के लिए सहमति पत्र',
    'heading' => [
        'hospital_name' => getSetting('hospital_name'),
        'address' => getHospitalAddressLine(),
        'form_title' => 'उच्च जोखिम सर्जरी के लिए सहमति पत्र (High Risk Consent Form for Surgery)',
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
$selectedDoctorName = '';
foreach ($doctors as $doctor) {
    if ((int) $doctor['doctor_id'] === $selectedDoctorId) {
        $selectedDoctorName = formatDoctorName($doctor['name'] ?? '');
        break;
    }
}
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
            font-size: 12px;
            margin-top: 3px;
        }

        .form-title {
            font-size: 16px;
            font-weight: 700;
            margin-top: 6px;
        }

        .section {
            margin: 10px 0;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 13px;
            font-weight: 700;
            border-bottom: 1px solid #000;
            margin-bottom: 6px;
            padding-bottom: 2px;
        }

        .form-row {
            display: flex;
            gap: 14px;
            margin-bottom: 10px;
            align-items: flex-start;
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

        .readonly-field {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            padding: 4px;
            font-size: 11px;
            background: #fff;
            margin-bottom: 6px;
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
            min-height: 24px;
            margin: 3px 0;
        }

        .consent-name-row .readonly-field {
            border: none;
            border-bottom: 1px solid #000;
            border-radius: 0;
            background: transparent;
            min-height: 24px;
            margin: 3px 0;
            padding: 0 2px;
        }

        .consent-name-row .line-block {
            min-height: 24px;
            margin: 3px 0;
        }

        .single-line-textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            padding: 1px 3px;
            font-size: 11px;
            resize: none;
            height: 18px !important;
            min-height: 18px !important;
            max-height: 18px !important;
            line-height: 1.1 !important;
            overflow: hidden !important;
            font-family: Arial, sans-serif;
        }

        .line-block.tall {
            min-height: 48px;
        }

        .content-block p {
            margin: 0 0 8px 0;
            line-height: 1.5;
            text-align: justify;
        }

        .content-block .content-heading {
            font-weight: 700;
            margin-top: 10px;
            text-align: left;
        }

        .signature-grid {
            display: flex;
            gap: 14px;
            margin-top: 10px;
        }

        .signature-col {
            flex: 1;
        }

        .signature-box {
            height: 58px;
            border: 1.8px solid #000;
            margin-top: 4px;
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
                margin: 1mm;
                font-size: 9.5px;
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
                background: transparent !important;
            }

            .actions,
            .action-bar,
            .header,
            .patient-id-group {
                display: none !important;
            }

            .form-header {
                text-align: center;
                border-bottom: 1px solid #000 !important;
                margin-bottom: 5px;
                padding-bottom: 3px;
            }

            .hospital-name,
            .address,
            .form-title {
                margin: 0;
                font-size: 10px;
                line-height: 1.2;
                font-weight: 600;
            }

            .hospital-name {
                font-size: 13px;
                font-weight: 700;
            }

            .form-title {
                font-size: 11px;
                font-weight: 700;
            }

            .section {
                page-break-inside: auto !important;
                break-inside: auto !important;
                margin: 3px 0 !important;
            }

            .content-block p {
                margin: 0 0 3px 0;
                line-height: 1.25;
            }

            .content-block .content-heading {
                margin-top: 4px;
            }

            .form-row {
                gap: 6px !important;
                margin-bottom: 3px !important;
            }

            .patient-details-section {
                border: 0 !important;
                padding: 0 !important;
                margin-bottom: 4px !important;
            }

            .form-group label {
                font-size: 9px;
                margin-bottom: 0;
            }

            .readonly-field,
            .single-line-textarea {
                padding: 1px 2px !important;
                margin-bottom: 1px !important;
                font-size: 9.5px;
                border: 0 !important;
                border-bottom: 1px solid #000 !important;
                border-radius: 0 !important;
                background: transparent !important;
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

            textarea,
            .single-line-textarea {
                height: 18px !important;
                min-height: 18px !important;
                max-height: 18px !important;
                line-height: 1.2 !important;
                overflow: hidden !important;
                resize: none !important;
                -webkit-appearance: none;
                appearance: none;
            }

            .line-block {
                min-height: 16px;
                margin: 1px 0;
            }

            .signature-grid {
                gap: 8px;
                margin-top: 4px;
                border-top: 1px solid #000;
                padding-top: 3px;
            }

            .signature-box {
                height: 42px;
                border: 1.2px solid #000;
                margin-top: 2px;
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

        <div class="section patient-details-section">
            <div class="form-row">
                <div class="form-group patient-id-group" style="flex: 1;">
                    <label>रोगी आईडी (Patient ID)</label>
                    <div class="field-with-button">
                        <input id="patient-id-input" class="readonly-field" type="text" inputmode="numeric" pattern="[0-9]*" value="<?php echo htmlspecialchars((string) ($patient_data['patient_id'] ?? ($patientId > 0 ? $patientId : ''))); ?>" placeholder="Enter Patient ID">
                    </div>
                </div>
                <div class="form-group" style="flex: 3;">
                    <label>रोगी का नाम (Patient Name)</label>
                    <input id="patient-name" class="readonly-field" type="text" value="<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>" readonly>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>आयु (Age)</label>
                    <input id="patient-age" class="readonly-field" type="text" value="<?php echo htmlspecialchars((string) ($patient_data['age'] ?? '')); ?>" readonly>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>लिंग (Gender)</label>
                    <input id="patient-gender" class="readonly-field" type="text" value="<?php echo htmlspecialchars(formatGenderDisplay($patient_data['gender'] ?? '')); ?>" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>दिनांक (Date)</label>
                    <input id="admission-date" class="readonly-field" type="text" value="<?php echo htmlspecialchars($admissionDateDisplay); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>उपचाररत डॉक्टर (Treating Doctor)</label>
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
            <div class="form-row">
                <div class="form-group">
                    <label>Diagnosis</label>
                    <textarea class="single-line-textarea" rows="1"></textarea>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Proposed Surgery</label>
                    <textarea class="single-line-textarea" rows="1"></textarea>
                </div>
            </div>
        </div>
        <div class="section">
            <p style="margin: 0 0 6px 0; line-height: 1.45;">
                रोगी/परिजन, हम आपको सूचित करना चाहते हैं कि यह सहमति पत्र आपके और हमारे लिए बहुत महत्वपूर्ण है। कृपया इसे ध्यान से पढ़ें।
            </p>
            <div class="content-block">
                <p class="content-heading">1. सर्जरी के बारे में जानकारी</p>
                <p>
                    डॉक्टरों द्वारा जांच करने के बाद यह सलाह दी गई है कि रोगी का उपचार सर्जरी द्वारा किया जाना आवश्यक है।
                    रोगी की वर्तमान स्वास्थ्य स्थिति, विशेषकर हृदय संबंधी समस्या, अधिक आयु, पहले से मौजूद बीमारियां (जैसे ब्लड प्रेशर,
                    मधुमेह, सांस की बीमारी आदि) के कारण यह सर्जरी उच्च जोखिम (High Risk) वाली मानी जा रही है।
                    डॉक्टरों ने हमें समझाया है कि सर्जरी करने का उद्देश्य रोगी की बीमारी का उपचार करना है, लेकिन रोगी की स्वास्थ्य
                    स्थिति के कारण सर्जरी के दौरान या बाद में जटिलताएं होने की संभावना सामान्य से अधिक हो सकती है।
                </p>

                <p class="content-heading">2. सर्जरी से जुड़े संभावित खतरे और जटिलताएं</p>
                <p>
                    हमें बताया गया है कि सर्जरी और एनेस्थीसिया (बेहोशी की दवा) के दौरान या उसके बाद निम्न समस्याएं हो सकती हैं:
                    दिल से संबंधित समस्याएं जैसे दिल का दौरा, दिल की धड़कन का अनियमित होना या दिल की कार्यक्षमता में कमी;
                    ब्लड प्रेशर में अचानक कमी या वृद्धि; सांस लेने में तकलीफ, ऑक्सीजन की कमी या वेंटिलेटर की आवश्यकता;
                    खून बहना, जिसके लिए खून चढ़ाने की जरूरत पड़ सकती है; संक्रमण (इन्फेक्शन) या घाव भरने में देरी;
                    अचानक तबीयत बिगड़ना, बेहोशी, स्ट्रोक या अन्य गंभीर जटिलताएं; सर्जरी के दौरान या बाद में गंभीर स्थिति या मृत्यु का जोखिम भी हो सकता है।
                    हमें यह भी बताया गया है कि डॉक्टर और अस्पताल की टीम पूरी सावधानी और उपलब्ध सुविधाओं के अनुसार सर्वोत्तम इलाज देने की कोशिश करेगी,
                    लेकिन सभी जोखिमों को पूरी तरह से समाप्त करना संभव नहीं होता।
                </p>

                <p class="content-heading">3. उन्नत चिकित्सा सुविधाओं की आवश्यकता की संभावना</p>
                <p>
                    हमें स्पष्ट रूप से बताया गया है कि इस अस्पताल में कार्डियक मैनेजमेंट यूनिट (Cardiac Care Unit) और पूर्ण विकसित आईसीयू (ICU)
                    जैसी कुछ उन्नत सुविधाएं उपलब्ध नहीं हैं। यदि सर्जरी के दौरान या उसके बाद रोगी की स्थिति गंभीर हो जाती है और विशेष कार्डियक देखभाल,
                    वेंटिलेटर सपोर्ट या उन्नत आईसीयू सुविधा की आवश्यकता पड़ती है, तो रोगी को तुरंत उन्नत चिकित्सा सुविधाओं वाले बड़े अस्पताल (Higher Centre)
                    में स्थानांतरित करना पड़ सकता है। इस स्थिति में रोगी को एम्बुलेंस द्वारा अन्य अस्पताल भेजा जाएगा ताकि वहां आवश्यक विशेष उपचार दिया जा सके।
                </p>

                <p class="content-heading">4. डॉक्टरों और अस्पताल की जिम्मेदारी</p>
                <p>
                    डॉक्टरों और अस्पताल की टीम उपलब्ध संसाधनों और सुविधाओं के अनुसार रोगी को सुरक्षित और उचित उपचार देने का पूरा प्रयास करेगी।
                    फिर भी, रोगी की पहले से मौजूद बीमारियों, अधिक आयु या अन्य चिकित्सीय कारणों से सर्जरी के दौरान या बाद में अप्रत्याशित जटिलताएं हो सकती हैं,
                    जिन पर पूरी तरह नियंत्रण संभव नहीं होता।
                </p>

                <p class="content-heading">5. रोगी/परिजन की सहमति</p>
                <p>
                    मैं/हम यह पुष्टि करते हैं कि: हमें रोगी की बीमारी, प्रस्तावित सर्जरी और उससे जुड़े जोखिमों के बारे में स्पष्ट रूप से समझाया गया है।
                    हमें यह भी बताया गया है कि रोगी की हृदय संबंधी समस्या, अधिक आयु और अन्य स्वास्थ्य समस्याओं के कारण सर्जरी उच्च जोखिम वाली है।
                    हमें यह जानकारी है कि इस अस्पताल में कार्डियक यूनिट और पूर्ण आईसीयू सुविधा उपलब्ध नहीं है। यदि आवश्यकता हुई तो रोगी को उन्नत
                    चिकित्सा सुविधा वाले अस्पताल में स्थानांतरित किया जा सकता है। हमें अपने सभी प्रश्न पूछने का अवसर मिला और हमें संतोषजनक उत्तर प्राप्त हुए।
                    इन सभी बातों को समझने के बाद हम अपनी स्वेच्छा से इस सर्जरी के लिए सहमति प्रदान करते हैं।
                </p>
            </div>

            <div class="form-row consent-name-row">
                <div class="form-group" style="flex: 1;">
                    <label>रोगी का नाम (Patient Name)</label>
                    <input id="consent-patient-name" class="readonly-field" type="text" value="<?php echo htmlspecialchars($patient_data['patient_name'] ?? ''); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>गवाह/परिजन का नाम व संबंध (Witness Name & Relation)</label>
                    <div class="line-block"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>डॉक्टर का नाम (Doctor Name)</label>
                    <input id="consent-doctor-name" class="readonly-field" type="text" value=" <?php echo htmlspecialchars($selectedDoctorName); ?>" readonly>
                </div>
            </div>

            <div class="signature-grid">
                <div class="signature-col">
                    <label>रोगी के हस्ताक्षर</label>
                    <div class="signature-box"></div>
                </div>
                <div class="signature-col">
                    <label>डॉक्टर के हस्ताक्षर एवं मुहर</label>
                    <div class="signature-box"></div>
                </div>
                <div class="signature-col">
                    <label>गवाह/परिजन के हस्ताक्षर</label>
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

        function formatDoctorNameClient(name) {
            const trimmed = String(name || '').trim();
            if (!trimmed) {
                return '';
            }

            if (/^dr\.?\s+/i.test(trimmed)) {
                return trimmed;
            }

            return `Dr. ${trimmed}`;
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

        function syncFooterNames() {
            const patientName = document.getElementById('patient-name').value || '';
            const doctorSelect = document.getElementById('doctor-name');
            const rawDoctorName = doctorSelect && doctorSelect.selectedOptions.length
                ? (doctorSelect.selectedOptions[0].dataset.name || doctorSelect.selectedOptions[0].text || '')
                : '';
            const doctorName = formatDoctorNameClient(rawDoctorName);

            document.getElementById('consent-patient-name').value = patientName;
            document.getElementById('consent-doctor-name').value = doctorName;
        }

        function fillPatientDetails(patient) {
            document.getElementById('patient-name').value = patient.name || '';
            document.getElementById('patient-age').value = patient.age || '';
            document.getElementById('patient-gender').value = formatGenderDisplayClient(patient.gender || '');

            const admission = patient.admission || null;
            document.getElementById('admission-date').value = admission ? formatDateTimeDisplay(admission.admission_date || '') : getCurrentDateTimeDisplay();
            setDoctorSelection(admission ? admission.doctor_id : '', admission ? admission.doctor_name : '');
            syncFooterNames();
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
                syncFooterNames();
                lastFetchedPatientId = '';
                alert(error.message || 'Unable to fetch patient details');
            } finally {
                isFetchingPatient = false;
            }
        }

        document.getElementById('patient-id-input').addEventListener('blur', fetchAndAutofillPatient);
        document.getElementById('doctor-name').addEventListener('change', syncFooterNames);
        document.getElementById('patient-id-input').addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                fetchAndAutofillPatient();
            }
        });

        syncFooterNames();

        if (new URLSearchParams(window.location.search).get('print') === '1') {
            window.print();
        }
    </script>
</body>

</html>
