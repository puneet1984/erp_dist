<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

const JSON_SOURCE = __DIR__.'/patient_data.json';
const DB_HOST = '192.168.1.61:32778';
const DB_USER = 'root';
const DB_PASS = 'pramod58';
const DB_NAME = 'erp';

$errorMessage = '';
$warningMessage = '';
$mappings = [];
$rows = [];
$jsonCount = 0;
$maxPatientId = null;
$startNewId = null;

try {
    $rows = readPatientJsonRows(JSON_SOURCE);
    $jsonCount = count($rows);

    try {
        $conn = getErpConnection();
        $maxPatientId = fetchMaxPatientId($conn);
        $conn->close();
    } catch (Throwable $e) {
        $warningMessage = 'Could not fetch max patient_id from database. Enter starting new ID manually.';
    }

    if ($jsonCount > 0 && null !== $maxPatientId) {
        $startNewId = max(1, $maxPatientId - $jsonCount + 1);
    }

    if ('POST' === $_SERVER['REQUEST_METHOD']) {
        $inputStart = trim((string) ($_POST['start_new_id'] ?? ''));

        if ('' === $inputStart || !ctype_digit($inputStart) || (int) $inputStart <= 0) {
            throw new RuntimeException('Please enter a valid positive starting New ID.');
        }

        $startNewId = (int) $inputStart;
        $mappings = buildMappings($rows, $startNewId);
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

function getErpConnection(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        throw new RuntimeException('Connection failed: '.$conn->connect_error);
    }

    $conn->set_charset('utf8mb4');

    return $conn;
}

function fetchMaxPatientId(mysqli $conn): int
{
    $res = $conn->query('SELECT COALESCE(MAX(patient_id), 0) AS max_id FROM patients');
    if (!$res) {
        throw new RuntimeException('Failed fetching max patient id: '.$conn->error);
    }

    $max = (int) ($res->fetch_assoc()['max_id'] ?? 0);
    $res->free();

    return $max;
}

function readPatientJsonRows(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Source JSON not found: '.$path);
    }

    $raw = file_get_contents($path);
    if (false === $raw) {
        throw new RuntimeException('Unable to read source JSON file.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON format in source file.');
    }

    $rows = [];

    foreach ($decoded as $row) {
        $oldId = isset($row['patientid']) ? (int) $row['patientid'] : 0;
        $name = isset($row['name']) ? trim((string) $row['name']) : '';

        if ($oldId <= 0 || '' === $name) {
            continue;
        }

        $rows[] = [
            'old_id' => $oldId,
            'name' => $name,
        ];
    }

    return $rows;
}

function buildMappings(array $rows, int $startNewId): array
{
    $mapped = [];
    $next = $startNewId;

    foreach ($rows as $row) {
        $mapped[] = [
            'old_id' => $row['old_id'],
            'new_id' => $next,
            'name' => $row['name'],
        ];

        ++$next;
    }

    return $mapped;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Patient ID Mapping</title>
	<style>
		body {
			font-family: Segoe UI, Tahoma, sans-serif;
			margin: 24px;
			background: #f7f8fb;
			color: #1f2937;
		}

		.wrap {
			max-width: 1000px;
			margin: 0 auto;
		}

		.card {
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 10px;
			padding: 16px;
			margin-bottom: 14px;
		}

		.row {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
			align-items: center;
		}

		input[type=text] {
			padding: 9px;
			border: 1px solid #d1d5db;
			border-radius: 8px;
			min-width: 240px;
		}

		button {
			border: 0;
			border-radius: 8px;
			padding: 10px 14px;
			background: #2563eb;
			color: #fff;
			cursor: pointer;
		}

		.err {
			background: #fef2f2;
			border: 1px solid #ef4444;
			color: #991b1b;
			padding: 10px;
			border-radius: 8px;
		}

		.warn {
			background: #fffbeb;
			border: 1px solid #f59e0b;
			color: #92400e;
			padding: 10px;
			border-radius: 8px;
		}

		.muted {
			color: #6b7280;
		}

		table {
			width: 100%;
			border-collapse: collapse;
		}

		th,
		td {
			border: 1px solid #e5e7eb;
			padding: 8px;
			text-align: left;
		}

		th {
			background: #f3f4f6;
		}

		.num {
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
	</style>
</head>

<body>
	<div class="wrap">
		<div class="card">
			<h2>Patient Old/New ID Mapping</h2>
			<p class="muted">Builds mapping from JSON order. New IDs are generated sequentially from the starting value
				you provide.</p>
			<p class="muted">JSON rows loaded:
				<strong><?php echo $jsonCount; ?></strong></p>
			<?php if (null !== $maxPatientId) { ?>
			<p class="muted">Current max patient_id in DB:
				<strong><?php echo $maxPatientId; ?></strong></p>
			<?php } ?>
		</div>

		<?php if ('' !== $errorMessage) { ?>
		<div class="card">
			<div class="err"><?php echo h($errorMessage); ?></div>
		</div>
		<?php } ?>

		<?php if ('' !== $warningMessage) { ?>
		<div class="card">
			<div class="warn"><?php echo h($warningMessage); ?></div>
		</div>
		<?php } ?>

		<div class="card">
			<form method="post">
				<div class="row">
					<label for="start_new_id">Starting New ID:</label>
					<input id="start_new_id" type="text" name="start_new_id"
						value="<?php echo h((string) ($startNewId ?? '')); ?>"
						placeholder="e.g. 40445">
					<button type="submit">Generate Table</button>
				</div>
			</form>
		</div>

		<?php if (count($mappings) > 0) { ?>
		<div class="card">
			<table>
				<thead>
					<tr>
						<th>Old ID</th>
						<th>New ID</th>
						<th>Name</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($mappings as $map) { ?>
					<tr>
						<td class="num">
							<?php echo (int) $map['old_id']; ?>
						</td>
						<td class="num">
							<?php echo (int) $map['new_id']; ?>
						</td>
						<td><?php echo h((string) $map['name']); ?>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php } ?>
	</div>
</body>

</html>