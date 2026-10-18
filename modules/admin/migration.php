<?php
require '../../database/migration/dbcopy.php';
// HTML Output
echo '<!DOCTYPE html>
<html>
<head>
    <title>Dry Run Results</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        .new { background-color: #d4edda; } /* Green for new inserts */
        .update { background-color: #fff3cd; } /* Yellow for updates */
        .skip { background-color: #e2e3e5; } /* Gray for skipped */
    </style>
</head>
<body>
    <h2>Dry Run Results</h2>
    <table>
        <tr>
            <th>Patient ID</th>
            <th>Name</th>
            <th>Age</th>
            <th>Gender</th>
            <th>Contact</th>
            <th>Address</th>
            <th>City</th>
            <th>State</th>
            <th>Registration Date/Time</th>
            <th>Action</th>
        </tr>';


if ($resultA->num_rows > 0) {
    while ($row = $resultA->fetch_assoc()) {
        // Extract patient ID (remove 'MNH' from customercode)
        $patient_id = intval(str_replace('MNH', '', $row['customercode']));
        $name = formatName(trim($row['customername']));
        $age = intval(trim($row['age']));
        $gender = mapGender($row['gender']);  // Convert gender format
        $contact = (preg_match('/^\d{10}$/', $row['mobilenumber'])) ? $row['mobilenumber'] : '9999999999';

        // Format address components separately
        $address = trim($row['address1']);
        $city = normalizeCity($row['city']);
        $state = normalizeState($row['state'], $row['city']);
        
        // Format registration date and time as TIMESTAMP
        $reg_date = $row['registrationdate'] ?? date('Y-m-d');
        $reg_time = $row['registrationtime'] ?? '10:00:00';
        
        try {
            $datetime = new DateTime($reg_date . ' ' . $reg_time);
            $created_at = $datetime->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $created_at = date('Y-m-d H:i:s');
        }

        // Check if the patient exists in Server B
        $checkQuery = "SELECT created_at, address, city, state FROM patients WHERE patient_id = {$patient_id}";
        $resultB = $serverB->query($checkQuery);
        $rowB = $resultB->fetch_assoc();
        $existing_created_at = $rowB['created_at'] ?? null;
        
        if (!$existing_created_at) {
            echo "<tr class='new'>
                    <td>{$patient_id}</td>
                    <td>{$name}</td>
                    <td>{$age}</td>
                    <td>{$gender}</td>
                    <td>{$contact}</td>
                    <td>{$address}</td>
                    <td>{$city}</td>
                    <td>{$state}</td>
                    <td>{$created_at}</td>
                    <td>INSERT</td>
                  </tr>";
        } elseif ($existing_created_at != $created_at &&
                  ($rowB['address'] != $address ||
                   $rowB['city'] != $city ||
                   $rowB['state'] != $state)) {
            echo "<tr class='update'>
                    <td>{$patient_id}</td>
                    <td>{$name}</td>
                    <td>{$age}</td>
                    <td>{$gender}</td>
                    <td>{$contact}</td>
                    <td>{$address}</td>
                    <td>{$city}</td>
                    <td>{$state}</td>
                    <td>{$created_at}</td>
                    <td>UPDATE</td>
                  </tr>";
        }
    }
}

echo '    </table>
    <p>Dry run completed!</p>
</body>
</html>';


$serverA->close();
$serverB->close();
