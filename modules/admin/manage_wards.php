<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $message = '';
    
    // Sanitize form data based on action
    switch($action) {
        case 'add_ward':
            $wardData = [
                'ward_name' => capitalizeWords(sanitizeInput($_POST['ward_name'])),
                'ward_type_id' => (int)sanitizeNumeric($_POST['ward_type_id']),
                'total_beds' => (int)sanitizeNumeric($_POST['total_beds']),
                'charges_per_day' => (float)sanitizeNumeric($_POST['charges_per_day'])
            ];
            if(addWard($wardData)) {
                $message = "Ward added successfully!";
            }
            break;
            
        case 'edit_ward':
            $wardData = [
                'id' => (int)sanitizeNumeric($_POST['id']),
                'ward_name' => capitalizeWords(sanitizeInput($_POST['ward_name'])),
                'ward_type_id' => (int)sanitizeNumeric($_POST['ward_type_id']),
                'total_beds' => (int)sanitizeNumeric($_POST['total_beds']),
                'charges_per_day' => (float)sanitizeNumeric($_POST['charges_per_day'])
            ];
            if(updateWard($wardData)) {
                $message = "Ward updated successfully!";
            }
            break;
            
        case 'delete_ward':
            deleteWard((int)sanitizeInput($_POST['id']));
            break;
            
        case 'add_type':
            $typeData = [
                'type_name' => capitalizeWords(sanitizeInput($_POST['type_name'])),
                'description' => sanitizeInput($_POST['description'])
            ];
            addWardType($typeData);
            break;

        case 'edit_type':
            $typeData = [
                'ward_type_id' => (int)sanitizeNumeric($_POST['ward_type_id']),
                'type_name' => capitalizeWords(sanitizeInput($_POST['type_name'])),
                'description' => sanitizeInput($_POST['description'])
            ];
            if(updateWardType($typeData)) {
                $message = "Ward type updated successfully!";
            }
            break;
            
        case 'delete_type':
            if(deleteWardType((int)sanitizeInput($_POST['ward_type_id']))) {
                $message = "Ward type deleted successfully!";
            }
            break;
    }
    
    header('Location: manage_wards.php' . ($message ? '?message=' . urlencode($message) : ''));
    exit;
}

$wardTypes = getWardTypes();
$wards = getAllWards();

// Show success message if exists
$message = $_GET['message'] ?? '';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ward Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/manage_wards.css">
</head>
<body>
<?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container py-4">
        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Main Wards Table Section -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="card-title h4 mb-4">Ward Overview</h2>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Ward Name</th>
                                <th>Type</th>
                                <th>Total Beds</th>
                                <th>Available</th>
                                <th>Occupied</th>
                                <th>Charges/Day</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalBeds = 0;
                            $totalAvailable = 0;
                            $totalOccupied = 0;
                            foreach($wards as $ward): 
                                $totalBeds += $ward['total_beds'];
                                $totalAvailable += $ward['available_beds'];
                                $totalOccupied += $ward['occupied_beds'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($ward['ward_name']) ?></td>
                                <td><?= htmlspecialchars($ward['type_name']) ?></td>
                                <td><?= $ward['total_beds'] ?></td>
                                <td><?= $ward['available_beds'] ?></td>
                                <td><?= $ward['occupied_beds'] ?></td>
                                <td>₹<?= number_format($ward['charges_per_day'], 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= $ward['status'] === 'Active' ? 'success' : 'danger' ?>">
                                        <?= $ward['status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editWard(<?= htmlspecialchars(json_encode($ward)) ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="#" class="btn btn-sm btn-danger" onclick="deleteWard(<?= $ward['id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-info fw-bold">
                                <td colspan="2">Total</td>
                                <td><?= $totalBeds ?></td>
                                <td><?= $totalAvailable ?></td>
                                <td><?= $totalOccupied ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Two Column Form Section -->
        <div class="row g-4">
            <!-- Ward Type Form -->
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="card-title h5 mb-4">Add Ward Type</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_type">
                            <div class="mb-3">
                                <label class="form-label">Type Name</label>
                                <input type="text" name="type_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Add Type</button>
                        </form>

                        <!-- Ward Types List -->
                        <div class="mt-4">
                            <h4 class="h6 mb-3">Existing Types</h4>
                            <div class="list-group">
                                <?php foreach($wardTypes as $type): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($type['type_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($type['description']) ?></small>
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-info" onclick="editWardType(<?= htmlspecialchars(json_encode($type)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteWardType(<?= $type['ward_type_id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Ward Form -->
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="card-title h5 mb-4">Add New Ward</h3>
                        <form method="POST" id="wardForm">
                            <input type="hidden" name="action" value="add_ward">
                            <input type="hidden" name="id" value="">
                            <div class="mb-3">
                                <label class="form-label">Ward Name</label>
                                <input type="text" name="ward_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Ward Type</label>
                                <select name="ward_type_id" class="form-select" required>
                                    <?php foreach($wardTypes as $type): ?>
                                    <option value="<?= $type['ward_type_id'] ?>">
                                        <?= htmlspecialchars($type['type_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Total Beds</label>
                                <input type="number" name="total_beds" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Charges per Day</label>
                                <input type="number" name="charges_per_day" class="form-control" step="0.01" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Add Ward</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Ward Modal -->
    <div class="modal fade" id="editWardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_ward">
                    <input type="hidden" name="id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Ward</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Ward Name</label>
                            <input type="text" name="ward_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ward Type</label>
                            <select name="ward_type_id" class="form-select" required>
                                <?php foreach($wardTypes as $type): ?>
                                <option value="<?= $type['ward_type_id'] ?>">
                                    <?= htmlspecialchars($type['type_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Total Beds</label>
                            <input type="number" name="total_beds" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Charges per Day</label>
                            <input type="number" name="charges_per_day" class="form-control" step="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Update Ward</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Ward Type Modal -->
    <div class="modal fade" id="editWardTypeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_type">
                    <input type="hidden" name="ward_type_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Ward Type</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3"></div>
                            <label class="form-label">Type Name</label>
                            <input type="text" name="type_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Update Type</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function editWard(ward) {
        const modal = document.getElementById('editWardModal');
        const form = modal.querySelector('form');
        
        // Fill form data
        form.querySelector('[name="id"]').value = ward.id;
        form.querySelector('[name="ward_name"]').value = ward.ward_name;
        form.querySelector('[name="ward_type_id"]').value = ward.ward_type_id;
        form.querySelector('[name="total_beds"]').value = ward.total_beds;
        form.querySelector('[name="charges_per_day"]').value = ward.charges_per_day;
        
        // Show modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    function deleteWard(id) {
        if (confirm('Are you sure you want to delete this ward?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_ward">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function editWardType(type) {
        const modal = document.getElementById('editWardTypeModal');
        const form = modal.querySelector('form');
        
        form.querySelector('[name="ward_type_id"]').value = type.ward_type_id;
        form.querySelector('[name="type_name"]').value = type.type_name;
        form.querySelector('[name="description"]').value = type.description;
        
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    function deleteWardType(id) {
        if (confirm('Are you sure you want to delete this ward type?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_type">
                <input type="hidden" name="ward_type_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html>
