<?php
require '../../database/migration/dbcopy.php';

// Track statistics
$stats = [
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'failed' => 0
];

// HTML Output
echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Data Migration Results</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f4f4f4; }
            .success { background-color: #d4edda; }
            .error { background-color: #f8d7da; }
            .skip { background-color: #e2e3e5; }
        </style>
    </head>
    <body>
        <h2>Data Migration Results</h2>
        <table>
            <tr>
                <th>Patient ID</th>
                <th>Name</th>
                <th>Action</th>
                <th>Status</th>
                <th>Message</th>
            </tr>";

if ($resultA->num_rows > 0) {
    while ($row = $resultA->fetch_assoc()) {
        // Enhanced data processing with null checks
        $patient_id = intval(str_replace("MNH", "", $row['customercode']));
        $name = $serverB->real_escape_string(trim($row['customername']));
        $age = intval(trim($row['age'] ?? 0));
        $gender = mapGender($row['gender'] ?? '');
        $contact = (isset($row['mobilenumber']) && preg_match('/^\d{10}$/', $row['mobilenumber'])) ? $row['mobilenumber'] : '9999999999';

        // Safely combine address parts with null checks
        $address_parts = array_filter([
            trim($row['address1'] ?? ''),
            trim($row['address2'] ?? ''),
            trim($row['area'] ?? '')
        ], function($part) { return !empty($part); });
        $address = $serverB->real_escape_string(implode(', ', $address_parts));

        $city = normalizeCity($row['city'] ?? '');
        $state = normalizeState($row['state'] ?? '', $row['city'] ?? '');
        
        // Format registration date and time as TIMESTAMP
        $reg_date = $row['registrationdate'] ?? date('Y-m-d');
        $reg_time = $row['registrationtime'] ?? '10:00:00';
        
        // Validate and format date/time
        try {
            $datetime = new DateTime($reg_date . ' ' . $reg_time);
            $created_at = $datetime->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // Use current timestamp if invalid date/time
            $created_at = date('Y-m-d H:i:s');
        }

        // Map payment type with null check
        $payment_type_id = (isset($row['typeofcustomer']) && $row['typeofcustomer'] == 'CREDIT') ? 2 : 1;

        // Check if patient exists
        $checkQuery = "SELECT created_at FROM patients WHERE patient_id = $patient_id";
        $resultB = $serverB->query($checkQuery);
        $rowB = $resultB->fetch_assoc();
        $existing_created_at = $rowB['created_at'] ?? null;

        try {
            if (!$existing_created_at) {
                // Insert new record with all columns
                $insertQuery = "INSERT INTO patients (
                    patient_id, name, age, gender, contact, address, city, state,
                    occupation, aadhar_number, abha_id, ayushmaan_id,
                    payment_type_id, company_name, sms_sent, created_at
                ) VALUES (
                    $patient_id, '$name', $age, '$gender', '$contact', '$address', 
                    '$city', '$state', NULL, NULL, NULL, NULL, 
                    $payment_type_id, NULL, 0, '$created_at'
                )";

                if ($serverB->query($insertQuery)) {
                    echo "<tr class='success'>";
                    echo "<td>$patient_id</td><td>$name</td><td>INSERT</td><td>Success</td><td>-</td>";
                    $stats['inserted']++;
                } else {
                    throw new Exception($serverB->error);
                }
            } elseif ($existing_created_at != $created_at) {
                // Only update if timestamps don't match
                $updateQuery = "UPDATE patients 
                    SET name='$name', 
                        age=$age, 
                        gender='$gender', 
                        contact='$contact', 
                        address='$address',
                        city='$city',
                        state='$state',
                        payment_type_id=$payment_type_id,
                        created_at='$created_at'
                    WHERE patient_id=$patient_id";

                if ($serverB->query($updateQuery)) {
                    echo "<tr class='success'>";
                    echo "<td>$patient_id</td><td>$name</td><td>UPDATE</td><td>Success</td><td>-</td>";
                    $stats['updated']++;
                } else {
                    throw new Exception($serverB->error);
                }
            } else {
                // Record is unchanged - only update stats, don't display
                $stats['skipped']++;
            }
        } catch (Exception $e) {
            echo "<tr class='error'>";
            echo "<td>$patient_id</td><td>$name</td><td>ERROR</td><td>Failed</td><td>" . $e->getMessage() . "</td>";
            $stats['failed']++;
        }
        // Only output tr if not skipped
        if (isset($row['action']) && $row['action'] !== 'SKIP') {
            echo "</tr>";
        }
    }
}

echo "</table>
    <h3>Summary:</h3>
    <p>
        Records Inserted: {$stats['inserted']}<br>
        Records Updated: {$stats['updated']}<br>
        Records Skipped: {$stats['skipped']}<br>
        Failed Operations: {$stats['failed']}
    </p>
    </body>
    </html>";

    $serverA->close();
    $serverB->close();
