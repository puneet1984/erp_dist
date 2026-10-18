<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get active wards for filter
$wards = getActiveWards();

// Get filter parameters
$wardId = $_GET['ward_id'] ?? '';
$search = $_GET['search'] ?? '';

// Get admitted patients with optional ward filter
$patients = getIPDPatients('Admitted');

// Apply ward filter if selected
if (!empty($wardId)) {
    $patients = array_filter($patients, function($patient) use ($wardId) {
        return $patient['ward_id'] == $wardId;
    });
}

// Apply search filter if entered
if (!empty($search)) {
    $patients = array_filter($patients, function($patient) use ($search) {
        return stripos($patient['patient_name'], $search) !== false 
            || stripos($patient['admission_id'], $search) !== false
            || stripos($patient['patient_id'], $search) !== false;
    });
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admitted Patients</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container{
            
            max-width: 1200px; /* Set a maximum width for the container */
            margin: 0 auto; /* Center the container */
            padding: 15px; /* Add some padding */
        }
    </style>
</head>
<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Admitted Patients</h2>
        <div>
            <a href="discharged_list.php" class="btn btn-secondary me-2">
                <i class="fas fa-user-check"></i> Discharged Patients
            </a>
            <a href="admit.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Admission
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row">
                <div class="col-md-4">
                    <select name="ward_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Wards</option>
                        <?php foreach ($wards as $ward): ?>
                            <option value="<?php echo $ward['id']; ?>" 
                                <?php echo $wardId == $ward['id'] ? 'selected' : ''; ?>>
                                <?php echo $ward['ward_name'] . ' (' . $ward['ward_type'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" 
                            placeholder="Search by name, IP number or patient ID" 
                            value="<?php echo htmlspecialchars($search); ?>">
                        <?php if (!empty($search)): ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearSearch()">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Patients List -->
    <div class="table-responsive">
        <table class="table table-hover table-striped align-middle">
            <thead class="table-light">
                <tr>
                    <th>IP No</th>
                    <th>Patient ID</th>
                    <th>Patient Name</th>
                    <th>Ward/Bed</th>
                    <th>Doctor</th>
                    <th>Department</th>
                    <th>Admission Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $patient): ?>
                    <tr>
                        <td class="fw-bold"><?php echo $patient['admission_id']; ?></td>
                        <td><?php echo $patient['patient_id']; ?></td>
                        <td><?php echo htmlspecialchars($patient['patient_name']); ?></td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($patient['ward_name']); ?></div>
                            <small class="text-muted">Bed <?php echo $patient['bed_number']; ?></small>
                        </td>
                        <td>
                            <div>Dr. <?php echo htmlspecialchars($patient['doctor_name']); ?></div>
                            <?php if ($patient['degree']): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($patient['degree']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($patient['dept_name']); ?></td>
                        <td><?php echo date('d-m-Y', strtotime($patient['admission_date'])); ?></td>
                        <td>
                            <div class="btn-group">
                                <a href="view.php?id=<?php echo $patient['admission_id']; ?>" 
                                    class="btn btn-sm btn-info" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="ipd_bill.php?admission_id=<?php echo $patient['admission_id']; ?>" 
                                    class="btn btn-sm btn-primary" title="Generate Bill">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                                <a href="discharge.php?id=<?php echo $patient['admission_id']; ?>" 
                                    class="btn btn-sm btn-warning" title="Process Discharge">
                                    <i class="fas fa-procedures"></i>
                                </a>
                                <a href="discharge_summary.php?id=<?php echo $patient['admission_id']; ?>" 
                                    class="btn btn-sm btn-success" title="Discharge Summary">
                                    <i class="fas fa-file-medical"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($patients)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No admitted patients found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    // Add tooltips to buttons
    $('[title]').tooltip();
    
    // Add row hover effect
    $('.table tbody tr').hover(
        function() { $(this).addClass('table-hover'); },
        function() { $(this).removeClass('table-hover'); }
    );
});

function clearSearch() {
    const form = document.querySelector('form');
    const searchInput = form.querySelector('input[name="search"]');
    searchInput.value = '';
    form.submit();
}
</script>

</body>
</html>
