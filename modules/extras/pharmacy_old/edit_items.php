<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $conn = getDbConnection();
    $itemId = (int) sanitizeInput($_POST['item_id']);
    $activeIngredient = sanitizeInput($_POST['active_ingredient']);
    $manufacturer = capitalizeWords(sanitizeInput($_POST['manufacturer']));
    $reorderLevel = (int) sanitizeInput($_POST['reorder_level']);

    try {
        $stmt = $conn->prepare('UPDATE pharmacy_items 
            SET active_ingredient = ?, manufacturer = ?, reorder_level = ?
            WHERE item_id = ?');

        $stmt->bind_param(
            'ssii',
            $activeIngredient,
            $manufacturer,
            $reorderLevel,
            $itemId
        );

        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => 'Item updated successfully']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$conn = getDbConnection();
$items = $conn->query('SELECT item_id, name, category, active_ingredient, manufacturer, reorder_level 
    FROM pharmacy_items 
    ORDER BY 
        CASE 
            WHEN category = "MEDICINE" AND (manufacturer IS NULL OR active_ingredient IS NULL) THEN 0 
            ELSE 1 
        END,
        name, category');
?>
<!DOCTYPE html>
<html>

<head>
    <title>Edit Pharmacy Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            max-width: 1200px;
            margin-top: 40px;
            background: #fff;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            padding-top: 20px;
            padding-bottom: 20px;
        }

        .alert {
            display: none;
            margin-top: 20px;
        }

        .edit-form {
            display: none;
        }
    </style>
</head>

<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Edit Pharmacy Items</h2>
            <a href="add_items.php" class="btn btn-primary">+ Add New Item</a>
            <button type="button" class="btn btn-secondary" onclick="window.history.back()">← Back</button>
        </div>

        <div class="mb-3">
            <input type="text" id="searchInput" class="form-control"
                placeholder="Search by name or active ingredient...">
        </div>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Active Ingredient</th>
                    <th>Manufacturer</th>
                    <th>Reorder Level</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($item = $items->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars(capitalizeWords($item['name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($item['category'] ?? '') ?></td>
                        <td>
                            <span class="view-mode"><?= htmlspecialchars($item['active_ingredient'] ?? '') ?></span>
                            <input type="text" class="form-control edit-form" name="active_ingredient"
                                value="<?= htmlspecialchars($item['active_ingredient'] ?? '') ?>"
                                <?= $item['category'] === 'MEDICINE' ? 'required' : '' ?>>
                        </td>
                        <td>
                            <span class="view-mode"><?= htmlspecialchars($item['manufacturer'] ?? '') ?></span>
                            <input type="text" class="form-control edit-form" name="manufacturer"
                                value="<?= htmlspecialchars($item['manufacturer'] ?? '') ?>"
                                <?= $item['category'] === 'MEDICINE' ? 'required' : '' ?>>
                        </td>
                        <td>
                            <span class="view-mode"><?= htmlspecialchars($item['reorder_level'] ?? '') ?></span>
                            <input type="number" class="form-control edit-form" name="reorder_level"
                                value="<?= htmlspecialchars($item['reorder_level'] ?? '') ?>">
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary edit-btn" data-id="<?= $item['item_id'] ?>">Edit</button>
                            <div class="btn-group edit-form">
                                <button class="btn btn-sm btn-success save-btn">Save</button>
                                <button class="btn btn-sm btn-secondary cancel-btn">Cancel</button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div class="alert alert-success"></div>
        <div class="alert alert-danger"></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#searchInput').on('keyup', function () {
                const value = $(this).val().toLowerCase();
                $('table tbody tr').filter(function () {
                    const name = $(this).find('td:first').text().toLowerCase();
                    const activeIngredient = $(this).find('td:nth-child(3)').text().toLowerCase();
                    const matchFound = name.includes(value) || activeIngredient.includes(value);
                    $(this).toggle(matchFound);
                });
            });

            $('.edit-btn').click(function () {
                const row = $(this).closest('tr');
                row.find('.view-mode').hide();
                row.find('.edit-form').show();
                $(this).hide();
            });

            $('.cancel-btn').click(function () {
                const row = $(this).closest('tr');
                row.find('.view-mode').show();
                row.find('.edit-form').hide();
                row.find('.edit-btn').show();
            });

            $('.save-btn').click(function () {
                const row = $(this).closest('tr');
                const itemId = row.find('.edit-btn').data('id');

                $.ajax({
                    url: 'edit_items.php',
                    method: 'POST',
                    data: {
                        item_id: itemId,
                        active_ingredient: row.find('input[name="active_ingredient"]').val(),
                        manufacturer: row.find('input[name="manufacturer"]').val(),
                        reorder_level: row.find('input[name="reorder_level"]').val()
                    },
                    success: function (response) {
                        const data = JSON.parse(response);
                        if (data.status === 'success') {
                            $('.alert-success').text(data.message).show();
                            $('.alert-danger').hide();
                            location.reload();
                        } else {
                            $('.alert-danger').text(data.message).show();
                            $('.alert-success').hide();
                        }
                    },
                    error: function () {
                        $('.alert-danger').text('Server error occurred').show();
                        $('.alert-success').hide();
                    }
                });
            });
        });
    </script>
</body>

</html>