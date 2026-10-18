<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';
require_once '../../includes/helpers/settings_helper.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$admissionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$saveError = '';
$readPostString = static function ($key) {
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
};

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $postedMedicationJson = $_POST['discharge_medications'] ?? '';
    $postedMedications = is_string($postedMedicationJson) && '' !== trim($postedMedicationJson)
        ? json_decode($postedMedicationJson, true)
        : [];

    if (!$admissionId || !is_array($postedMedications)) {
        $saveError = 'Unable to save the discharge summary. Please check the submitted details and try again.';
    } else {
        $medications = [];
        foreach ($postedMedications as $medication) {
            if (!is_array($medication) || !is_string($medication['medicine'] ?? null) || '' === trim($medication['medicine'])) {
                continue;
            }

            $cleanMedication = ['medicine' => trim($medication['medicine'])];
            foreach (['medicine_id', 'medicine_source', 'dosage', 'frequency', 'duration', 'instructions'] as $field) {
                $value = $medication[$field] ?? '';
                $cleanMedication[$field] = is_scalar($value) ? trim((string) $value) : '';
            }
            $medications[] = $cleanMedication;
        }

        $isObstetric = '1' === $readPostString('is_obstetric');
        $presentingComplaints = $readPostString('presenting_complaints');
        $surgeryDetails = $readPostString('surgery_details');
        if ($isObstetric) {
            $readFields = static function ($section) use ($readPostString) {
                $values = [];
                foreach (array_keys(getObstetricSections()[$section]['fields']) as $key) {
                    $values[$key] = $readPostString('ob_'.$key);
                }

                return $values;
            };
            $presentingComplaints = composeObstetricText($presentingComplaints, 'history', array_merge($readFields('history'), [
                'gpla' => composeGpla([
                    'g' => $readPostString('ob_g'),
                    'p' => $readPostString('ob_p'),
                    'l' => $readPostString('ob_l'),
                    'a' => $readPostString('ob_a'),
                ]),
                'past_menstrual_history' => composeMenstrualHistory($readPostString('ob_bleeding_days'), $readPostString('ob_cycle_days')),
            ]));
            $surgeryDetails = composeObstetricText($surgeryDetails, 'delivery', $readFields('delivery'));
        }

        $saveData = [
            'presenting_complaints' => $presentingComplaints,
            'examination_details' => $readPostString('examination_details'),
            'diagnosis' => $readPostString('diagnosis'),
            'treatment_plan' => composeInvestigationsText($readPostString('treatment_plan'), $readPostString('investigations')),
            'surgery_details' => $surgeryDetails,
            'surgery_date' => $readPostString('surgery_date') ?: null,
            'discharge_condition' => $readPostString('discharge_condition'),
            'discharge_notes' => $readPostString('discharge_notes'),
            'followup_date' => $readPostString('followup_date'),
            'discharge_medications' => $medications,
        ];

        if (saveDischargeSummary($admissionId, $saveData)) {
            header('Location: discharge_summary.php?id='.$admissionId.'&saved=1');
            exit;
        }

        $saveError = 'Unable to save the discharge summary. Please try again.';
    }
}

$summary = generateDischargeSummary($admissionId);

if (!$summary) {
    header('Location: ipd_list.php');
    exit;
}

$existingMedications = json_decode($summary['clinical_details']['discharge_medications'] ?? '[]', true);
$initialMedications = is_array($existingMedications) ? $existingMedications : [];
if ('POST' === $_SERVER['REQUEST_METHOD'] && $saveError) {
    foreach ([
        'presenting_complaints',
        'examination_details',
        'diagnosis',
        'treatment_plan',
        'surgery_details',
        'discharge_condition',
        'discharge_notes',
        'followup_date',
    ] as $field) {
        $summary['clinical_details'][$field] = $readPostString($field);
    }
    $summary['clinical_details']['surgery_date'] = $readPostString('surgery_date');
    if (is_array($postedMedications)) {
        $initialMedications = $postedMedications;
    }
}

// Split stored obstetric blocks back out of the text fields for editing
[$summary['clinical_details']['presenting_complaints'], $obstetricValues] = parseObstetricText($summary['clinical_details']['presenting_complaints'] ?? '', 'history');
[$summary['clinical_details']['surgery_details'], $deliveryValues] = parseObstetricText($summary['clinical_details']['surgery_details'] ?? '', 'delivery');
$obstetricValues += $deliveryValues;
if ('POST' === $_SERVER['REQUEST_METHOD'] && $saveError) {
    $obstetricValues = [];
    foreach (['history', 'delivery'] as $section) {
        foreach (array_keys(getObstetricSections()[$section]['fields']) as $key) {
            $obstetricValues[$key] = $readPostString('ob_'.$key);
        }
    }
    $isObstetric = '1' === $readPostString('is_obstetric');
    foreach (['g', 'p', 'l', 'a', 'bleeding_days', 'cycle_days'] as $key) {
        $obstetricValues[$key] = $readPostString('ob_'.$key);
    }
} else {
    $isObstetric = (bool) array_filter($obstetricValues, static fn ($value) => '' !== $value);
    $obstetricValues += parseGpla($obstetricValues['gpla'] ?? '');
    $obstetricValues += parseMenstrualHistory($obstetricValues['past_menstrual_history'] ?? '');
}
$obValue = static fn ($key) => htmlspecialchars($obstetricValues[$key] ?? '');
[$summary['clinical_details']['treatment_plan'], $investigationsText] = parseInvestigationsText($summary['clinical_details']['treatment_plan'] ?? '');
if ('POST' === $_SERVER['REQUEST_METHOD'] && $saveError) {
    $investigationsText = $readPostString('investigations');
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Discharge Summary</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- jQuery -->
    <script src="../../assets/js/cdn/jquery-3.6.0.min.js"></script>
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .discharge-summary {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Discharge Summary</h2>
            <button type="button" class="btn btn-outline-secondary" onclick="history.back()">Back</button>
        </div>

        <?php if (isset($_GET['saved'])) { ?>
            <div class="alert alert-success" role="alert">Discharge summary saved successfully.</div>
        <?php } ?>
        <?php if ($saveError) { ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($saveError); ?></div>
        <?php } ?>

        <!-- Display Patient & Doctor Info -->
        <div class="card mb-3">
            <div class="card-header bg-light">
                <h5 class="mb-0">Patient & Admission Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Patient Name:</strong> <?php echo $summary['patient_details']['name']; ?></p>
                        <p><strong>Patient ID:</strong> <?php echo $summary['patient_details']['id']; ?></p>
                        <p><strong>Age/Gender:</strong> <?php echo $summary['patient_details']['age']; ?> years / 
                            <?php echo formatGender($summary['patient_details']['gender']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Admission Date:</strong> <?php echo date('d-m-Y H:i', strtotime($summary['admission_details']['admission_date'])); ?></p>
                        <?php if ('Discharged' === $summary['admission_details']['status'] && !empty($summary['admission_details']['discharge_date'])) { ?>
                            <p><strong>Discharge Date:</strong> <?php echo date('d-m-Y H:i', strtotime($summary['admission_details']['discharge_date'])); ?></p>
                        <?php } else { ?>
                            <p><strong>Status:</strong> Admitted</p>
                        <?php } ?>
                        <p><strong>Ward/Bed:</strong> <?php echo $summary['admission_details']['ward']; ?></p>
                        <p><strong>Doctor:</strong> Dr. <?php echo $summary['doctor_details']['name']; ?> (<?php echo $summary['doctor_details']['department']; ?>)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Discharge Summary Form -->
        <form action="discharge_summary.php?id=<?php echo (int) $admissionId; ?>" method="POST" class="mb-5" id="dischargeSummaryForm">

            <!-- Clinical Details Section -->
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Clinical Details</h5>
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_obstetric" value="1" id="isObstetric" <?php echo $isObstetric ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="isObstetric">Obstetrics admission</label>
                    </div>

                    <div class="form-group mb-3">
                        <label>Presenting Complaints</label>
                        <textarea name="presenting_complaints" class="form-control" rows="3"><?php echo htmlspecialchars($summary['clinical_details']['presenting_complaints'] ?? ''); ?></textarea>
                    </div>

                    <div class="obstetric-section border rounded p-3 mb-3" id="obstetricHistorySection" style="display: none;">
                        <h6>Obstetric History</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>GPLA Status</label>
                                <div class="row g-2">
                                    <?php foreach (['g' => 'G (Gravida)', 'p' => 'P (Para)', 'l' => 'L (Living)', 'a' => 'A (Abortion)'] as $key => $label): ?>
                                        <div class="col-3">
                                            <input type="number" step="1" name="ob_<?php echo $key; ?>" min="0" class="form-control" placeholder="<?php echo strtoupper($key); ?>" title="<?php echo $label; ?>" aria-label="<?php echo $label; ?>" value="<?php echo $obValue($key); ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label>LMP</label>
                                <input type="date" name="ob_lmp" id="obLmp" class="form-control" value="<?php echo $obValue('lmp'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>EDD</label>
                                <input type="date" name="ob_edd" id="obEdd" class="form-control" value="<?php echo $obValue('edd'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>Days of Bleeding</label>
                                <input type="number" min="0" step="1" name="ob_bleeding_days" class="form-control" value="<?php echo $obValue('bleeding_days'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>Cycle Length (avg. days)</label>
                                <input type="number" min="0" step="1" name="ob_cycle_days" class="form-control" value="<?php echo $obValue('cycle_days'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label>Previous Pregnancy Status</label>
                                <textarea name="ob_previous_pregnancy_status" class="form-control" rows="2"><?php echo $obValue('previous_pregnancy_status'); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label>Examination Details</label>
                        <textarea name="examination_details" class="form-control" rows="3"><?php echo htmlspecialchars($summary['clinical_details']['examination_details'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Final Diagnosis</label>
                        <textarea name="diagnosis" class="form-control" rows="3"><?php echo htmlspecialchars($summary['clinical_details']['diagnosis'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Treatment Given</label>
                        <textarea name="treatment_plan" class="form-control" rows="3"><?php echo htmlspecialchars($summary['clinical_details']['treatment_plan'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Investigations</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary quick-template" data-target="investigations" data-text="All investigations reports (blood/sonography/xray) handed over to the patient party">All investigation reports handed over</button>
                        </div>
                        <textarea name="investigations" class="form-control" rows="2"><?php echo htmlspecialchars($investigationsText); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Surgery Details (if applicable)</label>
                        <textarea name="surgery_details" class="form-control" rows="2"><?php echo htmlspecialchars($summary['clinical_details']['surgery_details'] ?? ''); ?></textarea>
                        <div class="mt-2">
                            <label>Surgery Date:</label>
                            <input type="date" name="surgery_date" class="form-control" value="<?php echo htmlspecialchars($summary['clinical_details']['surgery_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="obstetric-section border rounded p-3 mb-3" id="deliveryDetailsSection" style="display: none;">
                        <h6>Delivery Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Mode of Delivery</label>
                                <select name="ob_delivery_mode" class="form-select">
                                    <option value="">Not applicable / not delivered</option>
                                    <?php foreach (['Normal', 'LSCS'] as $mode): ?>
                                        <option value="<?php echo $mode; ?>" <?php echo ($obstetricValues['delivery_mode'] ?? '') === $mode ? 'selected' : ''; ?>><?php echo $mode; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Paediatrician Attended</label>
                                <input type="text" name="ob_paediatrician" class="form-control" value="<?php echo $obValue('paediatrician'); ?>">
                            </div>
                            <div class="col-12"><strong>Newborn Details</strong></div>
                            <div class="col-md-4">
                                <label>Date &amp; Time of Birth</label>
                                <input type="datetime-local" name="ob_delivery_datetime" class="form-control" value="<?php echo $obValue('delivery_datetime'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label>Gender</label>
                                <select name="ob_baby_gender" class="form-select">
                                    <option value="">Select</option>
                                    <?php foreach (['Male', 'Female'] as $gender): ?>
                                        <option value="<?php echo $gender; ?>" <?php echo ($obstetricValues['baby_gender'] ?? '') === $gender ? 'selected' : ''; ?>><?php echo $gender; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Weight (kg)</label>
                                <input type="number" step="0.01" min="0" name="ob_baby_weight" class="form-control" value="<?php echo $obValue('baby_weight'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Discharge Medications -->
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Discharge Medications</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="medicineTable" class="table table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 24px;"></th>
                                    <th>Medicine Name</th>
                                    <th>Form</th>
                                    <th>Frequency</th>
                                    <th>Duration</th>
                                    <th>Instructions</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="MedicineTableBody"></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-primary" id="addMedicineButton">Add Medicine</button>
                    <input type="hidden" name="discharge_medications" id="dischargeMedications">
                </div>
            </div>

            <!-- Discharge Status -->
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Discharge Status</h5>
                </div>
                <div class="card-body">
                    <div class="form-group mb-3">
                        <label>Condition on Discharge</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary quick-template" data-target="discharge_condition" data-text="Stable, discharged to home">Stable, discharged to home</button>
                        </div>
                        <textarea name="discharge_condition" class="form-control" rows="3"><?php echo htmlspecialchars($summary['clinical_details']['discharge_condition'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Additional Notes (if any)</label>
                        <textarea name="discharge_notes" class="form-control" rows="3"><?php echo htmlspecialchars($summary['clinical_details']['discharge_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Follow-up Date</label>
                        <input type="date" name="followup_date" class="form-control" value="<?php echo htmlspecialchars($summary['clinical_details']['followup_date'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary" onclick="return validateForm()">
                    Save Discharge Summary
                </button>
                <a href="print_discharge.php?id=<?php echo (int) $admissionId; ?>" target="_blank" class="btn btn-success">Print Summary</a>
                <button type="button" class="btn btn-outline-danger" id="clearAllButton">Clear All Fields</button>
                <a href="ipd_list.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script src="../../assets/js/cdn/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/cdn/jquery-ui.min.css">
    <script>
        $(function () {
            const toggleObstetric = () => $('.obstetric-section').toggle($('#isObstetric').is(':checked'));
            $('#isObstetric').on('change', toggleObstetric);
            toggleObstetric();

            // Naegele's rule: EDD = LMP + 280 days, filled only when EDD is empty
            $('#obLmp').on('change', function () {
                if (!this.value || $('#obEdd').val()) return;
                const d = new Date(this.value + 'T00:00:00');
                d.setDate(d.getDate() + 280);
                const pad = n => String(n).padStart(2, '0');
                $('#obEdd').val(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()));
            });
        });
        function initializeMedicineSelect(select) {
            select.select2({
                width: '100%',
                dropdownParent: $(document.body),
                ajax: {
                    url: '../../includes/helpers/medicine_helper.php',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term,
                            type: 'prescription'
                        };
                    },
                    processResults: function (data) {
                        if (!data || !data.results) {
                            return { results: [] };
                        }
                        return {
                            results: data.results.map(item => ({
                                id: item.id,
                                text: item.name || item.text,
                                active_ingredient: item.active_ingredient,
                                dosage_form: item.dosage_form,
                                source: item.source
                            }))
                        };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'Search medicine or type a custom one...',
                allowClear: true,
                tags: true,
                createTag: function (params) {
                    const term = $.trim(params.term);
                    if (term.length < 2) {
                        return null;
                    }
                    return { id: 'custom:' + term, text: term, source: 'custom', isCustom: true };
                },
                templateResult: function (item) {
                    if (item.isCustom) {
                        return $('<span>').text('Add custom medicine: ' + item.text);
                    }
                    return item.text;
                }
            }).on('select2:select', function (event) {
                const medicine = event.params.data;
                const row = $(this).closest('tr');
                row.find('.active-ingredient').text(medicine.active_ingredient || '');
                if (!medicine.isCustom) {
                    row.find('.dosage').val(medicine.dosage_form || '');
                }
            });
        }

        const existingDischargeMedications = <?php
echo json_encode(
    $initialMedications,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>;

        function addMedicineRow(medicine = null) {
            const row = $(`
                <tr>
                    <td class="drag-handle text-center" title="Drag to reorder" style="cursor: move;">&#9776;</td>
                    <td>
                        <select class="medicine-select"></select>
                        <div class="active-ingredient small text-muted"></div>
                    </td>
                    <td><input type="text" class="form-control dosage" placeholder="Tablet/Capsule/Ointment/Syp"></td>
                    <td>
                        <select class="form-select frequency">
                            <option value="" selected disabled>Select Frequency</option>
                            <option value="1-0-0">Morning Only</option>
                            <option value="0-1-0">Afternoon Only</option>
                            <option value="0-0-1">Night Only</option>
                            <option value="0-0-1E">Evening Only</option>
                            <option value="1-0-1">Morning and Night</option>
                            <option value="1-1-1">Morning, Afternoon, and Night</option>
                            <option value="SINGLE">Single Dose</option>
                            <option value="WEEKLY">Once Weekly</option>
                        </select>
                    </td>
                    <td><input type="text" class="form-control duration" placeholder="e.g., 5" inputmode="numeric"></td>
                    <td><input type="text" class="form-control instructions" placeholder="Optional instructions"></td>
                    <td><button type="button" class="btn btn-sm btn-danger delete-medicine" aria-label="Remove medicine">&times;</button></td>
                </tr>
            `);
            $('#MedicineTableBody').append(row);
            const medicineSelect = row.find('.medicine-select');
            initializeMedicineSelect(medicineSelect);
            if (medicine) {
                const isCustom = 'custom' === medicine.medicine_source || !medicine.medicine_id;
                const option = new Option(medicine.medicine || '', isCustom ? 'custom:' + (medicine.medicine || '') : medicine.medicine_id, true, true);
                medicineSelect.append(option).trigger('change');
                row.find('.dosage').val(medicine.dosage || '');
                row.find('.frequency').val(medicine.frequency || '');
                row.find('.duration').val(medicine.duration || '');
                row.find('.instructions').val(medicine.instructions || medicine.notes || '');
            }
        }

        // A plain number means days; any other text (e.g. "2 weeks") is kept as typed
        function normalizeDuration(value) {
            const text = $.trim(value || '');
            return /^\d+$/.test(text) ? text + ' days' : text;
        }

        function collectDischargeMedicines() {
            const medicines = [];
            $('#MedicineTableBody tr').each(function () {
                const row = $(this);
                const medicineSelect = row.find('.medicine-select');
                if (!medicineSelect.val()) {
                    return;
                }

                const selectedMedicine = medicineSelect.select2('data')[0];
                const isCustom = String(medicineSelect.val()).startsWith('custom:');
                medicines.push({
                    medicine: selectedMedicine.text,
                    medicine_id: isCustom ? '' : medicineSelect.val(),
                    medicine_source: isCustom ? 'custom' : (selectedMedicine.source || ''),
                    dosage: row.find('.dosage').val(),
                    frequency: row.find('.frequency').val() || '',
                    duration: normalizeDuration(row.find('.duration').val()),
                    instructions: row.find('.instructions').val()
                });
            });
            return medicines;
        }

        $(document).ready(function () {
            if (existingDischargeMedications.length) {
                existingDischargeMedications.forEach(addMedicineRow);
            } else {
                addMedicineRow();
            }
            $('#addMedicineButton').on('click', addMedicineRow);
            $('#clearAllButton').on('click', function () {
                if (!confirm('Clear all fields? Nothing is deleted from the database until you click Save Discharge Summary.')) {
                    return;
                }
                const form = $('#dischargeSummaryForm');
                form.find('textarea, input[type="text"], input[type="number"], input[type="date"], input[type="datetime-local"]').val('');
                form.find('select').not('.medicine-select').val('');
                form.find('#isObstetric').prop('checked', false).trigger('change');
                $('#MedicineTableBody').empty();
                addMedicineRow();
            });
            $('.quick-template').on('click', function () {
                const field = $('textarea[name="' + $(this).data('target') + '"]');
                const text = $(this).data('text');
                const current = $.trim(field.val());
                if (current.indexOf(text) === -1) {
                    field.val(current ? current + '\n' + text : text);
                }
                field.focus();
            });
            $('#MedicineTableBody').on('blur', '.duration', function () {
                $(this).val(normalizeDuration($(this).val()));
            });
            $('#MedicineTableBody').on('click', '.delete-medicine', function () {
                $(this).closest('tr').remove();
            });
            $('#MedicineTableBody').sortable({
                handle: '.drag-handle',
                items: '> tr',
                axis: 'y'
            }).disableSelection();

            $('#dischargeSummaryForm').on('submit', function (event) {
                const medicines = collectDischargeMedicines();
                $('#dischargeMedications').val(JSON.stringify(medicines));
            });
        });

        function validateForm() {
            if ($('#isObstetric').is(':checked')) {
                const g = parseInt($('input[name="ob_g"]').val(), 10);
                if (isNaN(g) || g < 1) {
                    const proceed = confirm('Warning: G (Gravida) is empty or 0. For an obstetric case it should be at least 1 (current pregnancy counts).\n\nSave anyway?');
                    if (!proceed) {
                        $('input[name="ob_g"]').focus();
                        return false;
                    }
                }
            }
            return confirm('Save this discharge summary?');
        }
    </script>
</body>

</html>