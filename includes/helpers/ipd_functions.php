<?php

function admitPatient($patientId, $wardId, $bedNumber, $doctorId, $diagnosis = '', $treatmentPlan = '', $admissionDateTime = null)
{
    $conn = getDbConnection();

    $query = 'INSERT INTO ipd_admissions (patient_id, ward_id, bed_number, doctor_id, diagnosis, treatment_plan, admission_date) 
              VALUES (?, ?, ?, ?, ?, ?, ?)';

    $admissionDateTime = $admissionDateTime ?: date('Y-m-d H:i:s');

    $stmt = $conn->prepare($query);
    $stmt->bind_param('iisisss', $patientId, $wardId, $bedNumber, $doctorId, $diagnosis, $treatmentPlan, $admissionDateTime);

    if ($stmt->execute()) {
        $admissionId = $stmt->insert_id;
        updateBedStatus($wardId, $bedNumber, 'Occupied', $admissionId);

        return $admissionId;
    }

    return false;
}

function dischargePatient($admissionId, $data)
{
    $conn = getDbConnection();

    // Ensure medications are stored as JSON string if present
    $dischargeMedications = isset($data['discharge_medications']) ?
        json_encode($data['discharge_medications']) : null;

    $query = "UPDATE ipd_admissions 
              SET status = 'Discharged',
                  discharge_date = CURRENT_TIMESTAMP,
                  presenting_complaints = ?,
                  examination_details = ?,
                  diagnosis = ?,
                  treatment_plan = ?,
                  surgery_details = ?,
                  surgery_date = ?,
                  discharge_condition = ?,
                  discharge_notes = ?,
                  followup_date = ?,
                  discharge_medications = ?
              WHERE admission_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        'ssssssssssi',
        $data['presenting_complaints'],
        $data['examination_details'],
        $data['diagnosis'],
        $data['treatment_plan'],
        $data['surgery_details'],
        $data['surgery_date'],
        $data['discharge_condition'],
        $data['discharge_notes'],
        $data['followup_date'],
        $dischargeMedications,
        $admissionId
    );

    if ($stmt->execute()) {
        // Free up the bed
        freeBedAllocation($admissionId);

        return true;
    }

    return false;
}

function saveDischargeSummary($admissionId, $data)
{
    $conn = getDbConnection();
    try {
        $dischargeMedications = json_encode($data['discharge_medications'], JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Unable to encode discharge medications: '.$exception->getMessage());

        return false;
    }

    $query = 'UPDATE ipd_admissions
              SET presenting_complaints = ?,
                  examination_details = ?,
                  diagnosis = ?,
                  treatment_plan = ?,
                  surgery_details = ?,
                  surgery_date = ?,
                  discharge_condition = ?,
                  discharge_notes = ?,
                  followup_date = ?,
                  discharge_medications = ?
              WHERE admission_id = ?';

    $data['surgery_date'] = '' === trim((string) $data['surgery_date']) ? null : $data['surgery_date'];
    $data['followup_date'] = '' === trim((string) $data['followup_date']) ? null : $data['followup_date'];

    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        'ssssssssssi',
        $data['presenting_complaints'],
        $data['examination_details'],
        $data['diagnosis'],
        $data['treatment_plan'],
        $data['surgery_details'],
        $data['surgery_date'],
        $data['discharge_condition'],
        $data['discharge_notes'],
        $data['followup_date'],
        $dischargeMedications,
        $admissionId
    );

    return $stmt->execute();
}

// Obstetric details are stored inside the existing text columns as a labelled block, so no schema change is needed.
function getObstetricSections()
{
    return [
        'history' => [
            'header' => 'Obstetric History',
            'fields' => [
                'gpla' => ['GPLA Status', 'text'],
                'lmp' => ['LMP', 'date'],
                'edd' => ['EDD', 'date'],
                'past_menstrual_history' => ['Past Menstrual History', 'text'],
                'previous_pregnancy_status' => ['Previous Pregnancy Status', 'text'],
            ],
        ],
        'delivery' => [
            'header' => 'Delivery Details',
            'fields' => [
                'delivery_mode' => ['Mode of Delivery', 'text'],
                'paediatrician' => ['Paediatrician Attended', 'text'],
                'delivery_datetime' => ['Date & Time of Birth', 'datetime'],
                'baby_gender' => ['Baby Gender', 'text'],
                'baby_weight' => ['Baby Weight (kg)', 'text'],
            ],
        ],
    ];
}

function composeObstetricText($text, $section, array $values)
{
    $def = getObstetricSections()[$section];
    $lines = [];
    foreach ($def['fields'] as $key => [$label, $type]) {
        $value = trim(preg_replace('/\s+/', ' ', (string) ($values[$key] ?? '')));
        if ('' === $value) {
            continue;
        }
        if ('date' === $type || 'datetime' === $type) {
            $parsed = date_create($value);
            $value = $parsed ? $parsed->format('date' === $type ? 'd-m-Y' : 'd-m-Y H:i') : $value;
        }
        $lines[] = $label.': '.$value;
    }

    $text = rtrim((string) $text);
    if (!$lines) {
        return $text;
    }

    return ltrim($text."\n\n[".$def['header']."]\n".implode("\n", $lines));
}

// Returns [text without the block, field values keyed by field name (dates in input-friendly format)].
function parseObstetricText($text, $section)
{
    $def = getObstetricSections()[$section];
    $text = (string) $text;
    $values = [];
    $pattern = '/\n*\['.preg_quote($def['header'], '/').'\]\n(.*?)(?=\n\[|\z)/s';

    if (!preg_match($pattern, $text, $match)) {
        return [$text, $values];
    }

    $byLabel = [];
    foreach ($def['fields'] as $key => [$label, $type]) {
        $byLabel[$label] = [$key, $type];
    }
    // Label used by earlier saves
    if ('delivery' === $section) {
        $byLabel['Date & Time of Delivery'] = ['delivery_datetime', 'datetime'];
    }
    foreach (preg_split('/\R/', $match[1]) as $line) {
        $parts = explode(': ', $line, 2);
        if (2 !== count($parts) || !isset($byLabel[$parts[0]])) {
            continue;
        }
        [$key, $type] = $byLabel[$parts[0]];
        $value = trim($parts[1]);
        if ('date' === $type) {
            $parsed = DateTime::createFromFormat('d-m-Y', $value);
            $value = $parsed ? $parsed->format('Y-m-d') : $value;
        } elseif ('datetime' === $type) {
            $parsed = DateTime::createFromFormat('d-m-Y H:i', $value);
            $value = $parsed ? $parsed->format('Y-m-d\TH:i') : $value;
        }
        $values[$key] = $value;
    }

    return [trim(preg_replace($pattern, '', $text, 1)), $values];
}

function composeGpla(array $parts)
{
    $filled = false;
    foreach (['g', 'p', 'l', 'a'] as $key) {
        if ('' !== ($parts[$key] ?? '') && ctype_digit((string) $parts[$key])) {
            $filled = true;
        }
    }
    if (!$filled) {
        return '';
    }

    // Once any value is entered, blank boxes are recorded as 0
    $out = [];
    foreach (['G', 'P', 'L', 'A'] as $letter) {
        $value = $parts[strtolower($letter)] ?? '';
        $out[] = $letter.('' !== $value && ctype_digit((string) $value) ? (int) $value : 0);
    }

    return implode(' ', $out);
}

function parseGpla($text)
{
    $parts = [];
    if (preg_match_all('/([GPLA])\s*(\d+)/i', (string) $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $parts[strtolower($match[1])] = $match[2];
        }
    }

    return $parts;
}

function composeMenstrualHistory($bleedingDays, $cycleDays)
{
    $out = [];
    if ('' !== $bleedingDays && ctype_digit((string) $bleedingDays)) {
        $out[] = 'Bleeding '.(int) $bleedingDays.' days';
    }
    if ('' !== $cycleDays && ctype_digit((string) $cycleDays)) {
        $out[] = 'Cycle '.(int) $cycleDays.' days (average)';
    }

    return implode(' / ', $out);
}

function parseMenstrualHistory($text)
{
    return [
        'bleeding_days' => preg_match('/Bleeding\s+(\d+)/i', (string) $text, $m) ? $m[1] : '',
        'cycle_days' => preg_match('/Cycle\s+(\d+)/i', (string) $text, $m) ? $m[1] : '',
    ];
}

// Investigations are stored inside treatment_plan as a labelled block, so no schema change is needed.
function composeInvestigationsText($treatment, $investigations)
{
    $treatment = rtrim((string) $treatment);
    $investigations = trim((string) $investigations);
    if ('' === $investigations) {
        return $treatment;
    }

    return ltrim($treatment."\n\n[Investigations]\n".$investigations);
}

// Returns [treatment text without the block, investigations text].
function parseInvestigationsText($text)
{
    $pattern = '/\n*\[Investigations\]\n(.*?)(?=\n\[|\z)/s';
    $text = (string) $text;
    if (!preg_match($pattern, $text, $match)) {
        return [$text, ''];
    }

    return [trim(preg_replace($pattern, '', $text, 1)), trim($match[1])];
}

function getIPDPatients($status = null)
{
    $conn = getDbConnection();

    $query = 'SELECT a.*, p.name as patient_name, p.age, p.gender,
              d.name as doctor_name, d.degree, d.registration_no,
              dept.name as dept_name,
              w.ward_name, wt.type_name as ward_type
              FROM ipd_admissions a
              JOIN patients p ON a.patient_id = p.patient_id
              JOIN doctors d ON a.doctor_id = d.doctor_id
              JOIN departments dept ON d.dept_id = dept.dept_id
              JOIN wards w ON a.ward_id = w.id
              JOIN ward_types wt ON w.ward_type_id = wt.ward_type_id';

    if (is_array($status) && !empty($status)) {
        // Handle array of conditions
        $conditions = [];
        $params = [];
        $types = '';

        if (!empty($status['status'])) {
            $conditions[] = 'a.status = ?';
            $params[] = $status['status'];
            $types .= 's';
        }

        if (!empty($status['start_date'])) {
            $conditions[] = 'DATE(a.discharge_date) >= ?';
            $params[] = $status['start_date'];
            $types .= 's';
        }

        if (!empty($status['end_date'])) {
            $conditions[] = 'DATE(a.discharge_date) <= ?';
            $params[] = $status['end_date'];
            $types .= 's';
        }

        if (!empty($status['search'])) {
            $conditions[] = '(p.name LIKE ? OR p.patient_id LIKE ? OR a.admission_id LIKE ?)';
            $params[] = '%'.$status['search'].'%';
            $params[] = '%'.$status['search'].'%';
            $params[] = '%'.$status['search'].'%';
            $types .= 'sss';
        }

        if (!empty($conditions)) {
            $query .= ' WHERE '.implode(' AND ', $conditions);
            $query .= ' ORDER BY a.admission_id DESC';
        }

        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
    } elseif ($status) {
        // Handle simple string status
        $query .= ' WHERE a.status = ?';
        // $query .= ' ORDER BY a.admission_id DESC';

        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $status);
    } else {
        $query .= ' ORDER BY a.admission_id DESC';
        $stmt = $conn->prepare($query);
    }
    // $query .= ' ORDER BY a.admission_id DESC';
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

function updateBedStatus($wardId, $bedNumber, $status, $admissionId = null)
{
    $conn = getDbConnection();

    $query = 'INSERT INTO bed_allocation (ward_id, bed_number, status, admission_id)
              VALUES (?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE 
              status = VALUES(status),
              admission_id = VALUES(admission_id)';

    $stmt = $conn->prepare($query);
    $stmt->bind_param('issi', $wardId, $bedNumber, $status, $admissionId);

    return $stmt->execute();
}

function freeBedAllocation($admissionId)
{
    $conn = getDbConnection();

    $query = "UPDATE bed_allocation 
              SET status = 'Available', admission_id = NULL
              WHERE admission_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $admissionId);

    return $stmt->execute();
}

function getActiveWards()
{
    $conn = getDbConnection();
    $query = "SELECT w.id, w.ward_name, wt.type_name as ward_type 
              FROM wards w 
              JOIN ward_types wt ON w.ward_type_id = wt.ward_type_id 
              WHERE w.status = 'Active'";
    $result = $conn->query($query);

    return $result->fetch_all(MYSQLI_ASSOC);
}

function getActiveDoctors()
{
    $conn = getDbConnection();
    $query = "SELECT d.*, dept.name as dept_name 
                        FROM doctors d 
                        JOIN departments dept ON d.dept_id = dept.dept_id 
                        WHERE d.status = 'ACTIVE' 
                        ORDER BY d.name";
    $result = $conn->query($query);

    return $result->fetch_all(MYSQLI_ASSOC);
}

function calculateAdmissionDays($admission_date, $discharge_date = null)
{
    // Convert to DateTime objects if needed
    $admission = $admission_date instanceof DateTime ?
                $admission_date :
                new DateTime($admission_date);

    if ($discharge_date) {
        $checkout = $discharge_date instanceof DateTime ?
                   $discharge_date :
                   new DateTime($discharge_date);

        // Debugging
        // echo "Admission: " . $admission->format('d-m-Y H:i') . "\n";
        // echo "Discharge: " . $checkout->format('d-m-Y H:i') . "\n";

        // Get admission and discharge dates (without time)
        $admission_day = clone $admission;
        $admission_day->setTime(0, 0, 0);

        $checkout_day = clone $checkout;
        $checkout_day->setTime(0, 0, 0);

        // Count total days including the admission day
        $total_days = $admission_day->diff($checkout_day)->days + 1;

        // echo "Calendar Days Difference (Including Admission Day): $total_days\n";

        $admission_time = (int) $admission->format('H');
        $discharge_time = (int) $checkout->format('H');

        // echo "Admission Time: $admission_time:00\n";
        // echo "Discharge Time: $discharge_time:00\n";

        // If patient is admitted and discharged on the same date, count as 1 day
        if (1 == $total_days) {
            // echo "Same-day admission & discharge → 1 day\n";
            return 1;
        }

        // If checkout is before 2 PM, reduce one day from total stay
        if ($discharge_time < 14) {
            // echo "Discharge before 2 PM → Removing 1 day\n";
            --$total_days;
        }

        // echo "Final Stay Duration: $total_days days\n";
        return max(1, $total_days);
    }
    // For current patients
    $now = new DateTime();

    // echo "Current time: " . $now->format('d-m-Y H:i') . "\n";

    // Count total days including admission day
    $total_days = $admission->diff($now)->days + 1;

    // echo "Calendar Days Difference (Current Patients): $total_days\n";

    $current_time = (int) $now->format('H');

    // echo "Current Time: $current_time:00\n";

    // If it's before 2 PM today, reduce one day from total stay
    if ($current_time < 14) {
        // echo "Before 2 PM today → Removing 1 day\n";
        --$total_days;
    }

    // echo "Final Stay Duration for Current Patients: $total_days days\n";

    return max(1, $total_days);

}

function getAdmissionDetails($admissionId, $onlyAdmitted = false)
{
    $conn = getDbConnection();

    $query = 'SELECT a.*, 
              p.name as patient_name, p.age, p.gender, p.contact, p.address, p.city, p.state, p.aadhar_number, p.abha_id, p.ayushmaan_id,
              d.name as doctor_name, d.degree,
              d.dept_id,
              dept.name as dept_name,
              w.ward_name, w.charges_per_day as charge_per_day,
              wt.type_name as ward_type
              FROM ipd_admissions a 
              JOIN patients p ON a.patient_id = p.patient_id 
              JOIN doctors d ON a.doctor_id = d.doctor_id 
              JOIN departments dept ON d.dept_id = dept.dept_id
              JOIN wards w ON a.ward_id = w.id
              JOIN ward_types wt ON w.ward_type_id = wt.ward_type_id 
              WHERE a.admission_id = ?';

    if ($onlyAdmitted) {
        $query .= " AND a.status = 'Admitted'";
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $admissionId);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();
    if ($result) {
        $result['days_stayed'] = calculateAdmissionDays(
            $result['admission_date'],
            $result['discharge_date']
        );
    }

    return $result;
}

function generateDischargeSummary($admissionId)
{
    $conn = getDbConnection();

    $query = 'SELECT a.*, 
              p.patient_id, p.name as patient_name, p.age, p.gender, 
              p.address, p.contact,
              d.name as doctor_name, d.degree, d.registration_no,
              dept.name as dept_name,
              w.ward_name, pt.type_name as payment_type,
              wt.type_name as ward_type
              FROM ipd_admissions a
              JOIN patients p ON a.patient_id = p.patient_id
              JOIN doctors d ON a.doctor_id = d.doctor_id
              JOIN departments dept ON d.dept_id = dept.dept_id
              JOIN wards w ON a.ward_id = w.id
              JOIN ward_types wt ON w.ward_type_id = wt.ward_type_id
              JOIN payment_types pt ON a.payment_type_id = pt.payment_type_id
              WHERE a.admission_id = ?';

    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $admissionId);
    $stmt->execute();
    $admission = $stmt->get_result()->fetch_assoc();

    if (!$admission) {
        return false;
    }

    return [
        'admission_id' => $admission['admission_id'],
        'patient_details' => [
            'id' => $admission['patient_id'],
            'name' => $admission['patient_name'],
            'age' => $admission['age'],
            'gender' => $admission['gender'],
            'address' => $admission['address'],
            'contact' => $admission['contact'],
        ],
        'admission_details' => [
            'admission_date' => $admission['admission_date'],
            'status' => $admission['status'],
            'discharge_date' => $admission['discharge_date'],
            'ward' => $admission['ward_name'].' ('.$admission['ward_type'].')',
            'payment_type' => $admission['payment_type'],
        ],
        'doctor_details' => [
            'name' => $admission['doctor_name'],
            'degree' => $admission['degree'],
            'registration_no' => $admission['registration_no'],
            'department' => $admission['dept_name'],
        ],
        'clinical_details' => [
            'presenting_complaints' => $admission['presenting_complaints'],
            'examination_details' => $admission['examination_details'],
            'diagnosis' => $admission['diagnosis'],
            'treatment_plan' => $admission['treatment_plan'],
            'surgery_details' => $admission['surgery_details'],
            'surgery_date' => $admission['surgery_date'],
            'discharge_condition' => $admission['discharge_condition'],
            'discharge_notes' => $admission['discharge_notes'],
            'discharge_medications' => $admission['discharge_medications'],
            'followup_date' => $admission['followup_date'],
        ],
    ];
}

function getAvailableBeds($wardId)
{
    $conn = getDbConnection();

    // Get total beds for ward
    $wardQuery = 'SELECT total_beds FROM wards WHERE id = ?';
    $stmt = $conn->prepare($wardQuery);
    $stmt->bind_param('i', $wardId);
    $stmt->execute();
    $wardResult = $stmt->get_result()->fetch_assoc();

    if (!$wardResult) {
        return [];
    }

    // Get currently occupied beds
    $query = "SELECT bed_number 
              FROM bed_allocation 
              WHERE ward_id = ? AND status = 'Occupied'";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $wardId);
    $stmt->execute();

    $occupiedBeds = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $occupiedBeds[] = $row['bed_number'];
    }

    // Generate list of available beds
    $availableBeds = [];
    for ($i = 1; $i <= $wardResult['total_beds']; ++$i) {
        $bedNumber = sprintf('%02d', $i);
        if (!in_array($bedNumber, $occupiedBeds)) {
            $availableBeds[] = $bedNumber;
        }
    }

    return $availableBeds;
}

// ward management functions

function getAllWards()
{
    $conn = getDbConnection();
    $query = "SELECT w.id, w.ward_name, w.total_beds, w.charges_per_day, w.status,
              wt.type_name, wt.ward_type_id
              FROM wards w 
              JOIN ward_types wt ON w.ward_type_id = wt.ward_type_id 
              WHERE w.status = 'Active'
              ORDER BY w.ward_name";
    $result = $conn->query($query);
    $wards = $result->fetch_all(MYSQLI_ASSOC);

    // Add available and occupied beds count
    foreach ($wards as &$ward) {
        $availableBeds = getAvailableBeds($ward['id']);
        $ward['available_beds'] = count($availableBeds);
        $ward['occupied_beds'] = $ward['total_beds'] - $ward['available_beds'];
    }

    return $wards;
}

function addWard($data)
{
    $conn = getDbConnection();
    $query = "INSERT INTO wards (ward_name, ward_type_id, total_beds, charges_per_day, status) 
              VALUES (?, ?, ?, ?, 'Active')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        'siid',
        $data['ward_name'],
        $data['ward_type_id'],
        $data['total_beds'],
        $data['charges_per_day']
    );

    return $stmt->execute();
}

function updateWard($data)
{
    $conn = getDbConnection();
    $query = 'UPDATE wards 
              SET ward_name = ?, ward_type_id = ?, total_beds = ?, charges_per_day = ? 
              WHERE id = ?';
    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        'siidi',
        $data['ward_name'],
        $data['ward_type_id'],
        $data['total_beds'],
        $data['charges_per_day'],
        $data['id']
    );

    return $stmt->execute();
}

function deleteWard($id)
{
    $conn = getDbConnection();
    $query = "UPDATE wards SET status = 'Inactive' WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $id);

    return $stmt->execute();
}

function getWardTypes()
{
    $conn = getDbConnection();
    $query = "SELECT ward_type_id, type_name, description 
              FROM ward_types 
              WHERE status = 'Active'
              ORDER BY type_name";
    $result = $conn->query($query);

    return $result->fetch_all(MYSQLI_ASSOC);
}

function addWardType($data)
{
    $conn = getDbConnection();
    $query = 'INSERT INTO ward_types (type_name, description) 
              VALUES (?, ?)';
    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        'ss',
        $data['type_name'],
        $data['description']
    );

    return $stmt->execute();
}

function updateWardType($data)
{
    $conn = getDbConnection();
    $query = 'UPDATE ward_types 
              SET type_name = ?, description = ?
              WHERE ward_type_id = ?';
    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        'ssi',
        $data['type_name'],
        $data['description'],
        $data['ward_type_id']
    );

    return $stmt->execute();
}

function deleteWardType($id)
{
    $conn = getDbConnection();
    $query = "UPDATE ward_types SET status = 'Inactive' WHERE ward_type_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $id);

    return $stmt->execute();
}

function formatGender($gender)
{
    $genderMap = [
        'M' => 'Male',
        'F' => 'Female',
        'O' => 'Others',
    ];

    return $genderMap[$gender] ?? $gender;
}

function processIPDPayment($conn, $data)
{
    try {
        $payment_type_id = $data['payment_type_id'];
        $amount = floatval($data['amount']);
        $bill_id = $data['bill_id'];

        // Generate transaction ID
        $transaction_id = generateTransactionId('IPD');

        // Insert into payment_ledger
        $stmt = $conn->prepare('
            INSERT INTO payment_ledger (
                bill_id, transaction_id, transaction_date, amount_due, 
                amount_paid, payment_type_id, payment_status, created_by
            ) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?)
        ');

        $payment_status = $data['payment_status'] ?? 'PARTIAL';

        $stmt->bind_param(
            'ssddisi',
            $bill_id,
            $transaction_id,
            $amount,
            $amount,
            $payment_type_id,
            $payment_status,
            $_SESSION['user_id']
        );
        $stmt->execute();
        $ledger_id = $conn->insert_id;

        // Prepare payment details based on mode
        switch ($payment_type_id) {
            case 6: // CARD
                $fee_percentage = ('credit' === $data['card_type']) ? 0.035 : 0.015;
                $fee_amount = $amount * $fee_percentage;
                $payment_details = [
                    'mode' => 'CARD',
                    'card_type' => $data['card_type'],
                    'fee_percentage' => ('credit' === $data['card_type']) ? 3.5 : 1.5,
                    'fee_amount' => $fee_amount,
                    'reference_no' => $data['card_reference'],
                    'original_amount' => $amount,
                    'final_amount' => $amount + $fee_amount,
                ];
                break;

            case 7: // UPI
                $payment_details = [
                    'mode' => 'UPI',
                    'reference_no' => $data['upi_reference'],
                    'amount' => $amount,
                ];
                break;

            case 8: // SPLIT
                $payment_details = [
                    'mode' => 'SPLIT',
                    'payments' => [
                        'cash' => ['amount' => floatval($data['split_cash'])],
                        'card' => [
                            'amount' => floatval($data['split_card']),
                            'type' => $data['split_card_type'],
                            'reference_no' => $data['split_card_reference'],
                        ],
                        'upi' => [
                            'amount' => floatval($data['split_upi']),
                            'reference_no' => $data['split_upi_reference'],
                        ],
                    ],
                    'total_amount' => $amount,
                ];
                break;

            default: // CASH
                $payment_details = [
                    'mode' => 'CASH',
                    'amount' => $amount,
                ];
        }

        // Add notes if provided
        if (!empty($data['notes'])) {
            $payment_details['notes'] = $data['notes'];
        }

        // Insert payment collection
        $stmt = $conn->prepare('
            INSERT INTO payment_collections (
                ledger_id, bill_id, amount, payment_type_id,
                payment_date, payment_details, collected_by
            ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
        ');

        $payment_details_json = json_encode($payment_details);

        $stmt->bind_param(
            'isidsi',
            $ledger_id,
            $bill_id,
            $amount,
            $payment_type_id,
            $payment_details_json,
            $_SESSION['user_id']
        );
        $stmt->execute();

        return ['status' => 'success', 'ledger_id' => $ledger_id];

    } catch (Exception $e) {
        throw new Exception('Payment processing failed: '.$e->getMessage());
    }
}

function processIPDRefund($conn, $data)
{
    try {
        $collection_id = $data['collection_id'];
        $refund_amount = floatval($data['refund_amount']);
        $reason = $data['reason'];

        // Get original payment details
        $stmt = $conn->prepare('
            SELECT * FROM payment_collections 
            WHERE collection_id = ?
        ');
        $stmt->bind_param('i', $collection_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();

        if (!$payment) {
            throw new Exception('Original payment not found');
        }

        if ($refund_amount > $payment['amount']) {
            throw new Exception('Refund amount cannot exceed original payment');
        }

        // Generate refund ID
        $refund_id = generateRefundId();

        // Create payment details for refund
        $refund_details = [
            'mode' => 'REFUND',
            'original_payment_id' => $collection_id,
            'refund_id' => $refund_id,
            'reason' => $reason,
            'original_amount' => $payment['amount'],
            'refund_amount' => $refund_amount,
        ];

        // Insert refund record
        $stmt = $conn->prepare('
            INSERT INTO payment_collections (
                ledger_id, bill_id, amount, payment_type_id,
                payment_date, payment_details, collected_by
            ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)
        ');

        $refund_details_json = json_encode($refund_details);

        $stmt->bind_param(
            'isidsi',
            $payment['ledger_id'],
            $payment['bill_id'],
            $refund_amount,
            $payment['payment_type_id'],
            $refund_details_json,
            $_SESSION['user_id']
        );
        $stmt->execute();

        return ['status' => 'success', 'refund_id' => $refund_id];

    } catch (Exception $e) {
        throw new Exception('Refund processing failed: '.$e->getMessage());
    }
}

function getDoctorCharges($doctorId)
{
    $conn = getDbConnection();
    $query = "SELECT d.doctor_id, d.name, d.charge_per_visit, dept.dept_id, dept.name as dept_name
              FROM doctors d
              JOIN departments dept ON d.dept_id = dept.dept_id
              WHERE d.doctor_id = ? AND d.status = 'ACTIVE'";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_assoc();
}
