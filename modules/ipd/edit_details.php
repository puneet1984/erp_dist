<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('ipd', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$admissionId = $_GET['id'] ?? 0;
$success = false;
$error = '';

// Handle POST request for updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $query = "UPDATE ipd_admissions 
              SET diagnosis = ?,
                  treatment_plan = ?,
                  surgery_details = ?,
                  surgery_date = ?,
                  discharge_condition = ?,
                  discharge_notes = ?
              WHERE admission_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('ssssssi', 
        $_POST['diagnosis'],
        $_POST['treatment_plan'],
        $_POST['surgery_details'],
        $_POST['surgery_date'],
        $_POST['discharge_condition'],
        $_POST['discharge_notes'],
        $admissionId
    );

    if ($stmt->execute()) {
        $success = true;
    } else {
        $error = 'Failed to update patient details';
    }
}

// Get current admission details
$admission = getAdmissionDetails($admissionId);
if (!$admission) {
    header('Location: ipd_list.php');
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit IPD Patient Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Edit Patient Details</h2>
            <a href="view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success">
            Patient details updated successfully!
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="form-group mb-3">
                        <label class="form-label">Diagnosis</label>
                        <textarea name="diagnosis" class="form-control" rows="3"><?php echo htmlspecialchars($admission['diagnosis'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Treatment Plan</label>
                        <textarea name="treatment_plan" class="form-control" rows="3"><?php echo htmlspecialchars($admission['treatment_plan'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Surgery Details</label>
                        <textarea name="surgery_details" class="form-control" rows="3"><?php echo htmlspecialchars($admission['surgery_details'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Surgery Date</label>
                        <input type="date" name="surgery_date" class="form-control" 
                               value="<?php echo $admission['surgery_date'] ? date('Y-m-d', strtotime($admission['surgery_date'])) : ''; ?>">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Condition on Discharge</label>
                        <textarea name="discharge_condition" class="form-control" rows="2"><?php echo htmlspecialchars($admission['discharge_condition'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Discharge Notes</label>
                        <textarea name="discharge_notes" class="form-control" rows="3"><?php echo htmlspecialchars($admission['discharge_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
