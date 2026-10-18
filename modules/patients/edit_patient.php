<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check edit permissions
if (!checkPermission('patients', 'edit')) {
    header('Location: /hospital/modules/auth/unauthorized.php');
    exit;
}

$conn = getDbConnection();

// Handle POST request for updates
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    try {
        $patient_id = $_POST['patient_id'];

        // Verify that the patient_id matches the URL parameter
        if ($patient_id != $_GET['id']) {
            throw new Exception('Invalid patient ID modification attempt');
        }

        // Make patient_id readonly in the form
        $stmt = $conn->prepare('SELECT patient_id FROM patients WHERE patient_id = ?');
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            throw new Exception('Patient ID verification failed');
        }

        // Build name from components
        $firstName = capitalizeWords(sanitizeInput($_POST['first_name']));
        $middleName = capitalizeWords(sanitizeInput($_POST['middle_name']));
        $relation = sanitizeInput($_POST['relation']);
        $lastName = capitalizeWords(sanitizeInput($_POST['last_name']));

        // Format full name
        $name = $firstName;
        if ($middleName) {
            $name .= " {$relation} {$middleName}";
        }
        if ($lastName) {
            $name .= " {$lastName}";
        }
        $occupation = $_POST['occupation'];

        // Process address with tehsil
        $address = sanitizeInput($_POST['address']);
        $tehsil = sanitizeInput($_POST['tehsil'] ?? '');

        // Combine address and tehsil if tehsil is provided
        if (!empty($tehsil)) {
            // Remove any existing tehsil information first
            $address = preg_replace('/,\s*Tehsil:\s*[^,]+/', '', $address);
            $address = trim($address).(!empty($address) ? ', ' : '').'Tehsil: '.$tehsil;
        }

        // Prepare variables for optional fields
        $aadhar = !empty($_POST['aadhar_number']) ? $_POST['aadhar_number'] : null;
        $abha = !empty($_POST['abha_id']) ? $_POST['abha_id'] : null;
        $ayushmaan = !empty($_POST['ayushmaan_id']) ? $_POST['ayushmaan_id'] : null;
        $company = !empty($_POST['company_name']) ? $_POST['company_name'] : null;
        $city = capitalizeWords(sanitizeInput($_POST['city']));
        $state = sanitizeInput($_POST['state']);

        $stmt = $conn->prepare('UPDATE patients SET 
            name = ?,
            age = ?,
            gender = ?,
            occupation = ?,
            contact = ?,
            address = ?,
            city = ?,
            state = ?,
            payment_type_id = ?,
            company_name = ?,
            aadhar_number = ?,
            abha_id = ?,
            ayushmaan_id = ?
            WHERE patient_id = ?');

        $stmt->bind_param(
            'sdsssssssssssi',
            $name,
            $_POST['age'],
            $_POST['gender'],
            $occupation,
            $_POST['contact'],
            $address,
            $city,     // City field (from district)
            $state,    // State field
            $_POST['payment_type_id'],
            $company,
            $aadhar,
            $abha,
            $ayushmaan,
            $patient_id
        );

        if ($stmt->execute()) {
            header("Location: view_patient.php?id={$patient_id}&updated=1");
            exit;
        }
        throw new Exception($conn->error);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get patient data for editing
if (isset($_GET['id'])) {
    $patient_id = $_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM patients WHERE patient_id = ?');
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();

    if (!$patient) {
        header('Location: view_patient.php?error=not_found');
        exit;
    }

    // Extract tehsil from address if present
    $tehsil = '';
    if (preg_match('/Tehsil:\s*([^,]+)/', $patient['address'], $matches)) {
        $tehsil = $matches[1];
        // Remove tehsil part from address for clean display
        $address = preg_replace('/,\s*Tehsil:\s*[^,]+/', '', $patient['address']);
    } else {
        $address = $patient['address'];
    }

    // Parse name components - modified for new format with relation
    if (preg_match('/^(\S+(?:\s+\S+)?)\s+(?:([A-Z]\/O)\s+([^,]+))?(?:\s+(.*))?$/', $patient['name'], $matches)) {
        $first_name = $matches[1] ?? '';
        $relation = $matches[2] ?? 'S/O';
        $middle_name = $matches[3] ?? '';
        $last_name = $matches[4] ?? '';
    } else {
        // Fallback if regex doesn't match
        $name_parts = explode(' ', $patient['name'], 2);
        $first_name = $name_parts[0] ?? '';
        $middle_name = '';
        $last_name = $name_parts[1] ?? '';
        $relation = 'S/O';
    }
} else {
    header('Location: view_patient.php');
    exit;
}

// Get payment types for dropdown
$payment_types = $conn->query('SELECT * FROM payment_types ORDER BY type_name')->fetch_all(MYSQLI_ASSOC);

// Get company accounts for dropdown
$companies = $conn->query("SELECT company_name, status 
                          FROM company_accounts 
                          WHERE status = 'ACTIVE' 
                          ORDER BY company_name");

// Get insurance providers for dropdown
$insurance_providers = $conn->query('SELECT TPA_name, insurance_comp_name 
                                   FROM insurance_providers 
                                   ORDER BY TPA_name, insurance_comp_name');

// Get states and districts for dropdowns
$statesArray = getStates();
$districtsArray = getDistricts();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Edit Patient -
        <?php echo formatPatientId($patient['patient_id']); ?>
        -
        <?php echo htmlspecialchars($patient['name']); ?>
    </title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Add Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Select2 custom styling */
        .select2-container {
            width: 100% !important;
        }

        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 5px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }

        .phone-input {
            border: solid 1px #ddd;
            border-radius: 4px;
            padding: 8px;
            width: 100%;
            font-size: 14px;
        }

        .phone-input:focus {
            border-color: #4a90e2;
            outline: none;
            box-shadow: 0 0 3px rgba(74, 144, 226, 0.3);
        }

        .phone-input:invalid {
            border-color: #ff4444;
        }
    </style>
</head>

<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <h2>Edit Patient Details</h2>
        <div class="patient-header">
            <div class="patient-id">Patient ID:
                <?php echo formatPatientId($patient['patient_id']); ?>
            </div>
            <div class="registration-date">Registered:
                <?php echo formatDate($patient['created_at']); ?>
            </div>
        </div>

        <?php if (isset($error)) { ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?>
        </div>
        <?php } ?>

        <form method="POST" id="editForm">
            <input type="hidden" name="patient_id"
                value="<?php echo $patient['patient_id']; ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Basic Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name:</label>
                        <input type="text" name="first_name"
                            value="<?php echo htmlspecialchars(trim($first_name)); ?>"
                            required>
                    </div>
                    <div class="form-group" style="display: flex; gap: 10px;">
                        <div>
                            <label>Relation:</label>
                            <select name="relation" class="input-xs" onchange="updateGender(this.value)">
                                <option value="S/O" <?php echo 'S/O' === $relation ? 'selected' : ''; ?>>S/O
                                </option>
                                <option value="W/O" <?php echo 'W/O' === $relation ? 'selected' : ''; ?>>W/O
                                </option>
                                <option value="D/O" <?php echo 'D/O' === $relation ? 'selected' : ''; ?>>D/O
                                </option>
                            </select>
                        </div>
                        <div>
                            <label>Name:</label>
                            <input type="text" name="middle_name"
                                value="<?php echo htmlspecialchars($middle_name); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Last Name:</label>
                        <input type="text" name="last_name"
                            value="<?php echo htmlspecialchars(trim($last_name)); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Age:</label>
                        <input type="number" name="age"
                            value="<?php echo $patient['age']; ?>"
                            required min="0" max="150" step="0.1">
                    </div>
                    <div class="form-group">
                        <label>Gender:</label>
                        <select name="gender" required>
                            <option value="M" <?php echo 'M' === $patient['gender'] ? 'selected' : ''; ?>>Male
                            </option>
                            <option value="F" <?php echo 'F' === $patient['gender'] ? 'selected' : ''; ?>>Female
                            </option>
                            <option value="O" <?php echo 'O' === $patient['gender'] ? 'selected' : ''; ?>>Others
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Occupation:</label>
                        <select name="occupation">
                            <option value="">Select</option>
                            <option value="DESK_JOB" <?php echo 'DESK_JOB' === $patient['occupation'] ? 'selected' : ''; ?>>Desk
                                Job</option>
                            <option value="FARMER" <?php echo 'FARMER' === $patient['occupation'] ? 'selected' : ''; ?>>Farmer
                            </option>
                            <option value="HOUSE_MAKER" <?php echo 'HOUSE_MAKER' === $patient['occupation'] ? 'selected' : ''; ?>>House
                                Maker</option>
                            <option value="UNEMPLOYED" <?php echo 'UNEMPLOYED' === $patient['occupation'] ? 'selected' : ''; ?>>Unemployed
                            </option>
                            <option value="STUDENT" <?php echo 'STUDENT' === $patient['occupation'] ? 'selected' : ''; ?>>Student
                            </option>
                            <option value="RETIRED" <?php echo 'RETIRED' === $patient['occupation'] ? 'selected' : ''; ?>>Retired
                            </option>
                            <option value="LABOUR_WORK" <?php echo 'LABOUR_WORK' === $patient['occupation'] ? 'selected' : ''; ?>>Labour
                                Work</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number:</label>
                        <input type="tel" name="contact"
                            value="<?php echo htmlspecialchars($patient['contact']); ?>"
                            pattern="[6-9][0-9]{9}" maxlength="10" placeholder="Enter 10 digit mobile number"
                            title="Please enter a valid 10-digit mobile number starting with 6-9" class="phone-input"
                            required>
                    </div>
                </div>

                <div class="form-group full">
                    <label>Address:</label>
                    <textarea
                        name="address"><?php echo htmlspecialchars($address); ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group third">
                        <label>State:</label>
                        <select name="state" id="state" class="select2-dropdown">
                            <option value="">Select State</option>
                            <?php foreach ($statesArray as $state) { ?>
                            <option
                                value="<?php echo htmlspecialchars($state); ?>"
                                <?php echo ($patient['state'] === $state) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($state); ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group third">
                        <label>District:</label>
                        <select name="city" id="city" class="select2-dropdown">
                            <option value="">Select District</option>
                            <?php foreach ($districtsArray as $district) { ?>
                            <option
                                value="<?php echo htmlspecialchars($district); ?>"
                                <?php echo ($patient['city'] === $district) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($district); ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group third">
                        <label>Tehsil:</label>
                        <select name="tehsil" id="tehsil" class="select2-dropdown">
                            <option value="">Select Tehsil</option>
                            <!-- Tehsil options will be populated by JavaScript -->
                        </select>
                    </div>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="form-section">
                <h3>Payment Information</h3>
                <div class="form-group">
                    <label>Payment Type:</label>
                    <select name="payment_type_id" id="payment_type" onchange="togglePaymentField()">
                        <?php foreach ($payment_types as $type) { ?>
                        <option
                            value="<?php echo $type['payment_type_id']; ?>"
                            <?php echo $type['payment_type_id'] == $patient['payment_type_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['type_name']); ?>
                        </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group" id="company_field" style="display:none;">
                    <label>Company Name:</label>
                    <select name="company_name" id="company_select" onchange="handleCompanySelect(this.value)">
                        <option value="">Select Company</option>
                        <?php while ($company = $companies->fetch_assoc()) { ?>
                        <option
                            value="<?php echo htmlspecialchars($company['company_name']); ?>"
                            <?php echo $patient['company_name'] === $company['company_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($company['company_name']); ?>
                        </option>
                        <?php } ?>
                        <option value="new">+ Add New Company</option>
                    </select>
                    <input type="text" id="new_company_name" name="new_company_name" style="display:none;"
                        maxlength="100" placeholder="Enter new company name">
                </div>

                <div class="form-group" id="insurance_field" style="display:none;">
                    <label>Insurance Provider:</label>
                    <select name="insurance_provider" id="insurance_select"
                        onchange="handleInsuranceSelect(this.value)">
                        <option value="">Select Insurance Provider</option>
                        <?php
                        // Reset the result pointer
                        $insurance_providers->data_seek(0);
while ($provider = $insurance_providers->fetch_assoc()) {
    $provider_value = $provider['TPA_name'].'|'.$provider['insurance_comp_name'];
    ?>
                        <option
                            value="<?php echo htmlspecialchars($provider_value); ?>">
                            <?php echo htmlspecialchars($provider['TPA_name'].' - '.$provider['insurance_comp_name']); ?>
                        </option>
                        <?php } ?>
                        <option value="new">+ Add New Insurance Provider</option>
                    </select>

                    <div id="new_insurance_form" style="display:none;">
                        <div class="form-row">
                            <div class="form-group half">
                                <label>TPA Name:</label>
                                <input type="text" id="new_tpa_name" name="new_tpa_name" maxlength="100">
                            </div>
                            <div class="form-group half">
                                <label>Insurance Company:</label>
                                <input type="text" id="new_insurance_company" name="new_insurance_company"
                                    maxlength="100">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ID Information -->
            <div class="form-section">
                <h3>ID Information</h3>
                <div class="form-row">
                    <div class="form-group third">
                        <label>Aadhar Number:</label>
                        <input type="text" name="aadhar_number"
                            value="<?php echo htmlspecialchars($patient['aadhar_number'] ?? ''); ?>"
                            pattern="\d{12}" maxlength="12">
                    </div>
                    <div class="form-group third">
                        <label>ABHA ID:</label>
                        <input type="text" name="abha_id"
                            value="<?php echo htmlspecialchars($patient['abha_id'] ?? ''); ?>"
                            pattern="\d{14}" maxlength="14">
                    </div>
                    <div class="form-group third">
                        <label>PM-JAY ID:</label>
                        <input type="text" name="ayushmaan_id"
                            value="<?php echo htmlspecialchars($patient['ayushmaan_id'] ?? ''); ?>"
                            pattern="[A-Za-z0-9]{8,20}" maxlength="20">
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit">Save Changes</button>
                <a href="view_patient.php?id=<?php echo $patient['patient_id']; ?>"
                    class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Add jQuery and Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        let currentTehsil = '<?php echo htmlspecialchars($tehsil); ?>';

        function togglePaymentField() {
            const paymentType = document.getElementById('payment_type').value;
            const companyField = document.getElementById('company_field');
            const insuranceField = document.getElementById('insurance_field');
            const cardDetails = document.getElementById('card_details');
            const upiDetails = document.getElementById('upi_details');

            // Hide all payment related fields first
            [companyField, insuranceField, cardDetails, upiDetails].forEach(field => {
                if (field) field.style.display = 'none';
            });

            // Show relevant field based on payment type
            switch (paymentType) {
                case '2': // COMPANY_CREDIT
                    companyField.style.display = 'block';
                    break;
                case '4': // MEDICLAIM
                    insuranceField.style.display = 'block';
                    break;
                case '6': // CARD
                    if (cardDetails) {
                        cardDetails.style.display = 'block';
                        calculateCardFee();
                    }
                    break;
                case '7': // UPI
                    if (upiDetails) upiDetails.style.display = 'block';
                    break;
            }
        }

        function handleCompanySelect(value) {
            const newCompanyInput = document.getElementById('new_company_name');
            const companySelect = document.getElementById('company_select');

            if (value === 'new') {
                newCompanyInput.style.display = 'block';
                newCompanyInput.required = true;
                companySelect.name = ''; // Remove name attribute to prevent form submission
                newCompanyInput.name = 'new_company_name'; // Add name attribute for form submission
            } else {
                newCompanyInput.style.display = 'none';
                newCompanyInput.required = false;
                companySelect.name = 'company_name'; // Restore name attribute
                newCompanyInput.name = ''; // Remove name attribute
            }
        }

        function handleInsuranceSelect(value) {
            const newInsuranceForm = document.getElementById('new_insurance_form');
            const insuranceSelect = document.getElementById('insurance_select');
            const tpaInput = document.getElementById('new_tpa_name');
            const companyInput = document.getElementById('new_insurance_company');

            if (value === 'new') {
                newInsuranceForm.style.display = 'block';
                tpaInput.required = true;
                companyInput.required = true;
                insuranceSelect.name = ''; // Remove name attribute
            } else {
                newInsuranceForm.style.display = 'none';
                tpaInput.required = false;
                companyInput.required = false;
                insuranceSelect.name = 'insurance_provider';
                tpaInput.value = '';
                companyInput.value = '';
            }
        }

        function updateGender(relation) {
            if (!document.querySelector('select[name="gender"]').value) {
                document.querySelector('select[name="gender"]').value =
                    relation === 'S/O' ? 'M' : (relation === 'W/O' || relation === 'D/O' ? 'F' : '');
            }
        }

        $(document).ready(function() {
            // Initialize Select2
            $('.select2-dropdown').select2({
                placeholder: "Select...",
                allowClear: true,
                width: '100%'
            });

            // When state changes, handle city and tehsil selection
            $('#state').on('change', function() {
                var selectedState = $(this).val();
                var citySelect = $('#city');
                var tehsilSelect = $('#tehsil');

                if (selectedState && selectedState !== 'Madhya Pradesh') {
                    // For states other than MP, set city and tehsil to 'Others'
                    citySelect.val('Others').trigger('change');
                    tehsilSelect.val('Others').trigger('change');

                    // Disable city and tehsil dropdowns
                    citySelect.prop('disabled', true);
                    tehsilSelect.prop('disabled', true);
                } else {
                    // Re-enable dropdowns for MP
                    citySelect.prop('disabled', false);
                    tehsilSelect.prop('disabled', false);

                    // Reset selections only if currently disabled
                    if (citySelect.prop('disabled')) {
                        citySelect.val(null).trigger('change');
                    }
                }

                // Refresh Select2 to update disabled state
                citySelect.select2();
                tehsilSelect.select2();
            });

            // When district changes, update tehsil options
            $('#city').on('change', function() {
                var selectedDistrict = $(this).val();
                var tehsilSelect = $('#tehsil');

                // Skip if city is disabled (non-MP state)
                if ($(this).prop('disabled')) {
                    return;
                }

                tehsilSelect.empty().append(new Option('', '', false, false));

                if (!selectedDistrict) return;

                // Get tehsils for selected district
                $.ajax({
                    url: '../../includes/helpers/location_helper.php',
                    data: {
                        type: 'tehsils',
                        parent: selectedDistrict
                    },
                    dataType: 'json',
                    success: function(data) {
                        $.each(data, function(i, tehsil) {
                            var isSelected = (tehsil.text === currentTehsil);
                            var option = new Option(tehsil.text, tehsil.text,
                                isSelected, isSelected);
                            tehsilSelect.append(option);
                        });

                        tehsilSelect.trigger('change');
                    }
                });
            });

            // Trigger state change to initialize correct state
            setTimeout(function() {
                $('#state').trigger('change');
                // Trigger city change to load tehsils
                $('#city').trigger('change');
            }, 500);

            // Initialize on load
            togglePaymentField(); // Changed from toggleCompanyField to togglePaymentField
        });
    </script>
</body>

</html>