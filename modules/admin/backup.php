<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

function formatSize($size)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        ++$unit;
    }

    return round($size, 2).' '.$units[$unit];
}

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

function createDatabaseBackup()
{
    $backup_dir = __DIR__.'/../../database/backup/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $filename = 'db_backup_'.date('Ymd_His').'.sql.gz';
    $backup_file = $backup_dir.$filename;

    $command = 'mysqldump --user='.DB_USER.' --password='.DB_PASS.' --host='.DB_HOST.' '.DB_NAME.' | gzip > '.$backup_file;
    // system($command, $output);

    // return 0 === $output ? $backup_file : false;

    exec($command, $output, $resultCode);
    error_log("Backup command: {$command}");
    error_log("Return code: {$resultCode}");
    error_log('Output: '.implode("\n", $output));

    if (0 !== $resultCode || !file_exists($backup_file) || 0 === filesize($backup_file)) {
        error_log('Backup failed. Output: '.implode("\n", $output));
        throw new Exception('Database backup failed: '.implode("\n", $output));
    }

    return $backup_file;
}

function createApplicationBackup($includeDb = false)
{
    $backup_dir = __DIR__.'/../../database/backup/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $app_root = __DIR__.'/../../';
    $filename = 'app_backup_'.date('Ymd_His').'.tar.gz';
    $backup_file = $backup_dir.$filename;

    // Create temporary directory for database backup if needed
    if ($includeDb) {
        $db_backup = createDatabaseBackup();
    }

    // Create tar.gz archive excluding backup directory and temporary files
    $exclude = "--exclude='*/backup/*' --exclude='*/logs/*' --exclude='*/tmp/*' --exclude='*/.git/*'";
    $command = "tar -czf {$backup_file} {$exclude} -C ".dirname($app_root).' '.basename($app_root);
    system($command, $output);

    // Add database backup to archive if requested
    if ($includeDb && $db_backup) {
        $db_filename = basename($db_backup);
        $command = "tar -rf {$backup_file} -C {$backup_dir} {$db_filename}";
        system($command, $output);
        unlink($db_backup); // Remove temporary database backup
    }

    return 0 === $output ? ['status' => 'success', 'file' => $filename] : ['status' => 'error', 'message' => 'Backup failed'];
}

// Handle backup creation
if (isset($_POST['action']) && 'create_backup' === $_POST['action']) {
    try {
        $type = $_POST['type'] ?? 'db';
        $include_db = isset($_POST['include_db']) && 'true' === $_POST['include_db'];

        if ('app' === $type) {
            $result = createApplicationBackup($include_db);
        } else {
            $result = ['status' => 'success', 'file' => basename(createDatabaseBackup())];
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get list of backups from filesystem
$backup_dir = __DIR__.'/../../database/backup/';
$backup_files = glob($backup_dir.'*.sql.gz');
$backups = [];

foreach ($backup_files as $file) {
    $filename = basename($file);
    // Extract date from filename (backup_YYYYMMDD_HHMMSS.sql.gz)
    preg_match('/backup_(\d{8}_\d{6})\.sql\.gz/', $filename, $matches);
    $date_str = $matches[1] ?? '';

    $backups[] = [
        'filename' => $filename,
        'created_at' => date('Y-m-d H:i:s', filectime($file)),
        'file_size' => filesize($file),
        'status' => 'SUCCESS', // Assume success if file exists
        'backup_type' => 'MANUAL',
    ];
}

// Sort backups by creation time, newest first
usort($backups, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

?>
<!DOCTYPE html>
<html>

<head>
	<title>Database Backup</title>
	<link rel="stylesheet" href="../../assets/css/style.css">
	<style>
		.backup-controls {
			margin-bottom: 20px;
		}

		.status-badge {
			padding: 2px 8px;
			border-radius: 12px;
			font-size: 0.8em;
		}

		.status-success {
			background: #d4edda;
			color: #155724;
		}

		.status-failed {
			background: #f8d7da;
			color: #721c24;
		}

		.backup-options {
			margin-bottom: 20px;
		}

		.backup-type {
			margin-right: 20px;
		}

		#dbOption {
			display: none;
			/* Hide by default */
			margin-left: 20px;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>

	<div class="container">
		<h2>System Backup Management</h2>

		<div class="backup-controls">
			<div class="backup-options">
				<label class="backup-type">
					<input type="radio" name="backup_type" value="db" checked> Database Only
				</label>
				<label class="backup-type">
					<input type="radio" name="backup_type" value="app"> Application Files
				</label>
				<div id="dbOption">
					<label>
						<input type="checkbox" id="includeDb"> Include Database
					</label>
				</div>
			</div>
			<button onclick="createBackup()" class="btn btn-primary">Create New Backup</button>
		</div>

		<table class="data-table">
			<thead>
				<tr>
					<th>Filename</th>
					<th>Created At</th>
					<th>Size</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($backups as $backup) { ?>
				<tr>
					<td><?php echo htmlspecialchars($backup['filename']); ?>
					</td>
					<td><?php echo formatDate($backup['created_at']); ?>
						<?php echo formatTime($backup['created_at']); ?>
					</td>
					<td><?php echo formatSize($backup['file_size']); ?>
					</td>
					<td>
						<button
							onclick="downloadBackup('<?php echo htmlspecialchars($backup['filename']); ?>')"
							class="btn btn-small">Download</button>
						<button
							onclick="deleteBackup('<?php echo htmlspecialchars($backup['filename']); ?>')"
							class="btn btn-small btn-danger">Delete</button>
					</td>
				</tr>
				<?php } ?>
			</tbody>
		</table>
	</div>

	<script>
		// Initialize visibility based on selected type
		function updateDbOptionVisibility() {
			const selectedType = document.querySelector('input[name="backup_type"]:checked').value;
			document.getElementById('dbOption').style.display =
				selectedType === 'app' ? 'inline-block' : 'none';
		}

		// Run on page load to set initial state
		updateDbOptionVisibility();

		// Update on radio button change
		document.querySelectorAll('input[name="backup_type"]').forEach(radio => {
			radio.addEventListener('change', updateDbOptionVisibility);
		});

		function createBackup() {
			const type = document.querySelector('input[name="backup_type"]:checked').value;
			const includeDb = document.getElementById('includeDb').checked;

			if (!confirm(`Create new ${type.toUpperCase()} backup?`)) return;

			const formData = new FormData();
			formData.append('action', 'create_backup');
			formData.append('type', type);
			formData.append('include_db', includeDb);

			fetch('backup.php', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						alert('Backup created successfully!');
						window.location.reload();
					} else {
						alert('Backup failed: ' + data.message);
					}
				});
		}

		function downloadBackup(filename) {
			window.location.href = 'download_backup.php?file=' + encodeURIComponent(filename);
		}

		function deleteBackup(filename) {
			if (!confirm('Are you sure you want to delete this backup?')) return;

			fetch('delete_backup.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded'
					},
					body: 'filename=' + encodeURIComponent(filename)
				})
				.then(response => response.json())
				.then(data => {
					if (data.status === 'success') {
						window.location.reload();
					} else {
						alert('Delete failed: ' + data.message);
					}
				});
		}
	</script>
</body>

</html>