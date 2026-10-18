<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

const JSON_SOURCE = __DIR__.'/patient_data.json';
const DB_HOST = '192.168.1.61:32778';
const DB_USER = 'root';
const DB_PASS = 'pramod58';
const DB_NAME = 'erp';

$mode = 'idle';
$result = null;
$errorMessage = '';

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $mode = strtolower(trim((string) ($_POST['action'] ?? '')));

    try {
        if (!in_array($mode, ['report', 'apply'], true)) {
            throw new RuntimeException('Invalid action requested.');
        }

        if ('apply' === $mode) {
            $confirmApply = strtoupper(trim((string) ($_POST['confirm_apply'] ?? '')));
            if ('YES' !== $confirmApply) {
                throw new RuntimeException('For apply, type YES in confirmation box.');
            }
        }

        $rows = readPatientJson(JSON_SOURCE);
        $conn = getErpConnection();
        $conn->query("SET time_zone = '+05:30'");

        try {
            if ('report' === $mode) {
                $maxId = fetchMaxPatientId($conn);
                $validRows = count($rows);

                $result = [
                    'mode' => 'report',
                    'json_valid_rows' => $validRows,
                    'current_max_patient_id' => $maxId,
                    'new_patient_id_start' => $maxId + 1,
                    'new_patient_id_end' => $maxId + $validRows,
                    'current_total_patients' => countTotalPatients($conn),
                ];
            } else {
                $conn->begin_transaction();

                $beforeMax = fetchMaxPatientId($conn);
                $inserted = importPatientsAsNewIds($conn, $rows);
                $afterMax = fetchMaxPatientId($conn);

                $conn->commit();

                $result = [
                    'mode' => 'apply',
                    'json_valid_rows' => count($rows),
                    'inserted_rows' => $inserted,
                    'max_patient_id_before' => $beforeMax,
                    'max_patient_id_after' => $afterMax,
                    'total_patients_after' => countTotalPatients($conn),
                ];
            }
        } catch (Throwable $e) {
            if ('apply' === $mode && 0 === $conn->errno) {
                // ignore rollback failure when no transaction exists
            }
            if ('apply' === $mode) {
                $conn->rollback();
            }
            throw $e;
        } finally {
            $conn->close();
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

function getErpConnection(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        throw new RuntimeException('Connection failed: '.$conn->connect_error);
    }

    if (!$conn instanceof mysqli) {
        throw new RuntimeException('ERP database connection not available.');
    }

    $conn->set_charset('utf8mb4');

    return $conn;
}

function readPatientJson(string $path): array
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
    $rowNo = 0;

    foreach ($decoded as $row) {
        ++$rowNo;

        $oldId = isset($row['patientid']) ? (int) $row['patientid'] : 0;
        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        $contact = isset($row['mobile_number']) ? trim((string) $row['mobile_number']) : null;
        $gender = isset($row['gender']) ? strtoupper(trim((string) $row['gender'])) : 'O';

        if ($oldId <= 0 || '' === $name) {
            continue;
        }

        if (!in_array($gender, ['M', 'F', 'O'], true)) {
            $gender = 'O';
        }

        $rows[] = [
            'source_row' => $rowNo,
            'name' => $name,
            'contact' => $contact,
            'gender' => $gender,
        ];
    }

    return $rows;
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

function countTotalPatients(mysqli $conn): int
{
    $res = $conn->query('SELECT COUNT(*) AS c FROM patients');
    if (!$res) {
        throw new RuntimeException('Failed counting patients: '.$conn->error);
    }

    $count = (int) ($res->fetch_assoc()['c'] ?? 0);
    $res->free();

    return $count;
}

function importPatientsAsNewIds(mysqli $conn, array $rows): int
{
    if (0 === count($rows)) {
        return 0;
    }

    $nextId = fetchMaxPatientId($conn);

    $stmt = $conn->prepare(
        'INSERT INTO patients (
            patient_id, name, age, gender, occupation, contact, address, city, state,
            aadhar_number, abha_id, ayushmaan_id, payment_type_id, company_name, created_at, sms_sent
        ) VALUES (?, ?, NULL, ?, NULL, ?, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NOW(), 0)'
    );

    if (!$stmt) {
        throw new RuntimeException('Failed preparing patient insert: '.$conn->error);
    }

    $inserted = 0;

    foreach ($rows as $row) {
        $newPatientId = ++$nextId;
        $name = (string) $row['name'];
        $gender = (string) $row['gender'];
        $contact = null !== $row['contact'] ? (string) $row['contact'] : null;

        $stmt->bind_param('isss', $newPatientId, $name, $gender, $contact);
        if (!$stmt->execute()) {
            throw new RuntimeException('Failed inserting source row '.$row['source_row'].': '.$stmt->error);
        }

        ++$inserted;
    }

    $stmt->close();

    return $inserted;
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
	<title>Patient Data Restore</title>
	<style>
		body {
			font-family: Segoe UI, Tahoma, sans-serif;
			margin: 24px;
			background: #f7f8fb;
			color: #1f2937;
		}

		.wrap {
			max-width: 900px;
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
		}

		button {
			border: 0;
			border-radius: 8px;
			padding: 10px 14px;
			cursor: pointer;
		}

		.btn-report {
			background: #2563eb;
			color: #fff;
		}

		.btn-apply {
			background: #b91c1c;
			color: #fff;
		}

		input[type=text] {
			padding: 9px;
			border: 1px solid #d1d5db;
			border-radius: 8px;
			min-width: 220px;
		}

		.ok {
			background: #ecfdf5;
			border: 1px solid #10b981;
			color: #065f46;
			padding: 10px;
			border-radius: 8px;
		}

		.err {
			background: #fef2f2;
			border: 1px solid #ef4444;
			color: #991b1b;
			padding: 10px;
			border-radius: 8px;
		}

		pre {
			background: #111827;
			color: #e5e7eb;
			padding: 12px;
			border-radius: 8px;
			overflow: auto;
		}

		.muted {
			color: #6b7280;
		}
	</style>
</head>

<body>
	<div class="wrap">
		<div class="card">
			<h2>Patient Data Restore</h2>
			<p>Imports data from <strong>erp/patient_data.json</strong> as new patient entries with new patient IDs.</p>
			<p class="muted">Simple mode: no logs, no staging tables, no remapping.</p>
		</div>

		<div class="card">
			<form method="post">
				<div class="row">
					<button class="btn-report" type="submit" name="action" value="report">Generate Report</button>
				</div>
			</form>

			<hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0">

			<form method="post">
				<input type="hidden" name="action" value="apply">
				<div class="row">
					<label for="confirm_apply">Type YES to enable Apply:</label>
					<input id="confirm_apply" type="text" name="confirm_apply" placeholder="YES">
					<button class="btn-apply" type="submit">Apply Import</button>
				</div>
			</form>
		</div>

		<?php if ('' !== $errorMessage) { ?>
		<div class="card">
			<div class="err"><?php echo h($errorMessage); ?></div>
		</div>
		<?php } ?>

		<?php if (null !== $result) { ?>
		<div class="card">
			<div class="ok">Action completed:
				<strong><?php echo h(strtoupper($mode)); ?></strong>
			</div>
			<pre><?php echo h((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
		</div>
		<?php } ?>
	</div>
</body>

</html>