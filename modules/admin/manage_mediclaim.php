<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

// Check admin permissions
if (!checkPermission('admin', 'view')) {
    header('Location: ../../auth/unauthorized.php');
    exit;
}

if (!checkSessionTimeout()) {
    redirectToLogin('timeout=1');
}

$conn = getDbConnection();

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    // Handle GET request for fetching provider details
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get') {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("SELECT * FROM insurance_providers WHERE provider_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        
        if ($data) {
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Provider not found']);
        }
        exit;
    }

    // Handle POST request for insert/update
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!checkPermission('admin', 'edit')) {
            die(json_encode(['success' => false, 'message' => 'Unauthorized']));
        }

        $provider_id = $_POST['provider_id'] ?? '';
        $TPA_name = makeUpperCaseMixed(sanitizeInput($_POST['TPA_name']));
        $insurance_comp_name = capitalizeWords(sanitizeInput($_POST['insurance_comp_name']));
        $contact_person = capitalizeWords(sanitizeInput($_POST['contact_person']));
        $phone = validateMobile(sanitizeNumeric($_POST['phone']));
        $email = sanitizeInput($_POST['email']);

        try {
            if (empty($provider_id)) {
                $stmt = $conn->prepare("INSERT INTO insurance_providers 
                    (TPA_name, insurance_comp_name, contact_person, phone, email) 
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $TPA_name, $insurance_comp_name, $contact_person, $phone, $email);
            } else {
                $stmt = $conn->prepare("UPDATE insurance_providers 
                    SET TPA_name=?, insurance_comp_name=?, contact_person=?, phone=?, email=? 
                    WHERE provider_id=?");
                $stmt->bind_param("sssssi", $TPA_name, $insurance_comp_name, $contact_person, $phone, $email, $provider_id);
            }
            $stmt->execute();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch providers for the main page display
$providers = $conn->query("SELECT * FROM insurance_providers ORDER BY TPA_name, insurance_comp_name");
?>
<!DOCTYPE html>
<html>

<head>
    <title>Manage Insurance Providers</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 15px;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .data-table th {
            background-color: #f5f5f5;
        }

        .data-table tr:hover {
            background-color: #f9f9f9;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 5px;
            width: 90%;
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
        }

        button:hover {
            opacity: 0.9;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <div class="header-actions">
            <h2>Manage Insurance Providers</h2>
            <button onclick="showAddProviderForm()" class="btn-primary">Add New Provider</button>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>TPA Name</th>
                    <th>Insurance Company</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($provider = $providers->fetch_assoc()) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($provider['TPA_name']); ?></td>
                        <td><?php echo htmlspecialchars($provider['insurance_comp_name']); ?></td>
                        <td><?php echo htmlspecialchars($provider['contact_person']); ?></td>
                        <td><?php echo htmlspecialchars($provider['phone']); ?></td>
                        <td><?php echo htmlspecialchars($provider['email']); ?></td>
                        <td>
                            <button onclick="editProvider(<?php echo $provider['provider_id']; ?>)">Edit</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Provider Modal -->
    <div id="providerModal" class="modal">
        <div class="modal-content">
            <h3>Insurance Provider Details</h3>
            <form id="providerForm">
                <input type="hidden" name="provider_id">
                <div class="form-group">
                    <label>TPA Name:</label>
                    <input type="text" name="TPA_name" required>
                </div>
                <div class="form-group">
                    <label>Insurance Company:</label>
                    <input type="text" name="insurance_comp_name" required>
                </div>
                <div class="form-group">
                    <label>Contact Person:</label>
                    <input type="text" name="contact_person">
                </div>
                <div class="form-group">
                    <label>Phone:</label>
                    <input type="tel" name="phone" pattern="[0-9]{10,15}">
                </div>
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email">
                </div>
                <button type="submit">Save</button>
                <button type="button" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('providerModal');
        const providerForm = document.getElementById('providerForm');

        function showAddProviderForm() {
            providerForm.reset();
            providerForm.provider_id.value = '';
            modal.style.display = 'block';
        }

        function editProvider(providerId) {
            fetch(`manage_mediclaim.php?action=get&id=${providerId}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(response => {
                if (response.success) {
                    const data = response.data;
                    providerForm.provider_id.value = data.provider_id;
                    providerForm.TPA_name.value = data.TPA_name;
                    providerForm.insurance_comp_name.value = data.insurance_comp_name;
                    providerForm.contact_person.value = data.contact_person;
                    providerForm.phone.value = data.phone;
                    providerForm.email.value = data.email;
                    modal.style.display = 'block';
                } else {
                    alert('Error: ' + response.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while fetching provider details');
            });
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        providerForm.onsubmit = function (e) {
            e.preventDefault();
            const formData = new FormData(providerForm);

            fetch('manage_mediclaim.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        };

        // Close modal when clicking outside
        window.onclick = function (event) {
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>

</html>