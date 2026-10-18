<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get filter parameters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Get discharged patients
$patients = getIPDPatients([
    'status' => 'Discharged',
    'start_date' => $startDate,
    'end_date' => $endDate,
    'search' => $search
]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Discharged Patients</title>
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
        .container {
            max-width: 1200px; /* Set a maximum width for the container */
            margin: 0 auto; /* Center the container */
            padding: 15px; /* Add some padding */
        }
        .table th, .table td {
            vertical-align: middle; /* Center align text vertically */
        }
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
            margin: 0 1px;
        }
        .btn-group .btn i {
            font-size: 0.875rem;
        }
        .table td:last-child {
            min-width: 140px; /* Ensure enough space for buttons */
        }
    </style>
</head>

<body></body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Discharged Patients</h2>
        <a href="ipd_list.php" class="btn btn-primary">
            <i class="fas fa-procedures"></i> Admitted Patients
        </a>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="start_date" class="form-control" 
                           value="<?php echo $startDate; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="end_date" class="form-control" 
                           value="<?php echo $endDate; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" 
                               placeholder="Search by name or IP number" 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        Apply Filters
                    </button>
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
                    <th>Doctor</th>
                    <th>Ward</th>
                    <th>Admission Date</th>
                    <th>Discharge Date</th>
                    <th>Stay Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $patient): 
                    $days_stayed = calculateAdmissionDays(
                        $patient['admission_date'],
                        $patient['discharge_date']
                    );
                ?>
                    <tr>
                        <td class="fw-bold"><?php echo $patient['admission_id']; ?></td>
                        <td><?php echo $patient['patient_id']; ?></td>
                        <td>
                            <div><?php echo htmlspecialchars($patient['patient_name']); ?></div>
                            <small class="text-muted">
                                <?php echo $patient['age']; ?> years, 
                                <?php echo formatGender($patient['gender']); ?>
                            </small>
                        </td>
                        <td>
                            <div>Dr. <?php echo htmlspecialchars($patient['doctor_name']); ?></div>
                            <small class="text-muted"><?php echo $patient['dept_name']; ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($patient['ward_name']); ?></td>
                        <td><?php echo date('d-m-Y H:i', strtotime($patient['admission_date'])); ?></td>
                        <td><?php echo date('d-m-Y H:i', strtotime($patient['discharge_date'])); ?></td>
                        <td>
                            <?php 
                            echo $days_stayed . ' ' . ($days_stayed === 1 ? 'day' : 'days');
                            ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="discharge_summary.php?id=<?php echo $patient['admission_id']; ?>" 
                                   class="btn btn-sm btn-info" title="View Summary">
                                    <i class="fas fa-file-medical-alt"></i>
                                </a>
                                <a href="view.php?id=<?php echo $patient['admission_id']; ?>" 
                                   class="btn btn-sm btn-secondary" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" 
                                        class="btn btn-sm btn-success" 
                                        onclick="openSummary(<?php echo $patient['admission_id']; ?>)"
                                        title="Discharge Summary">
                                    <i class="fas fa-file-medical"></i>
                                </button>
                                <a href="ipd_bill.php?admission_id=<?php echo $patient['admission_id']; ?>" 
                                   class="btn btn-sm btn-primary" title="Generate Bill">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($patients)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            No discharged patients found in the selected date range
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[title]').tooltip();
});

function openSummary(admissionId) {
    window.location.href = `discharge_summary.php?id=${admissionId}`;
}
</script>

</body>
</html>
