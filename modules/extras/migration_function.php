<?php
require_once '../../database/migration/dbcopy.php';

/**
 * Migrate patient data from source to destination
 * 
 * @param bool $dryRun If true, only simulates migration
 * @return array Returns statistics and processed records
 */
function migratePatientData($dryRun = false) {
    global $serverA, $serverB;

    // Get source data
    $query = "SELECT customercode, customername, age, gender, mobilenumber, 
              address1, address2, area, city, state, registrationdate, 
              registrationtime, typeofcustomer 
              FROM customer_master";
    $resultA = $serverA->query($query);

    $stats = [
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0
    ];
    
    $processed = [];

    if (!$resultA) {
        throw new Exception("Failed to fetch source data: " . $serverA->error);
    }

    while ($row = $resultA->fetch_assoc()) {
        // Data processing
        $patient_id = intval(str_replace("MNH", "", $row['customercode']));
        $name = $serverB->real_escape_string(trim($row['customername']));
        $age = intval(trim($row['age'] ?? 0));
        $gender = mapGender($row['gender'] ?? '');
        $contact = (isset($row['mobilenumber']) && preg_match('/^\d{10}$/', $row['mobilenumber'])) 
                  ? $row['mobilenumber'] : '9999999999';

        // Address processing
        $address_parts = array_filter([
            trim($row['address1'] ?? ''),
            trim($row['address2'] ?? ''),
            trim($row['area'] ?? '')
        ], function($part) { return !empty($part); });
        $address = $serverB->real_escape_string(implode(', ', $address_parts));

        $city = normalizeCity($row['city'] ?? '');
        $state = normalizeState($row['state'] ?? '', $row['city'] ?? '');
        
        // DateTime processing
        $created_at = formatDateTime(
            $row['registrationdate'] ?? date('Y-m-d'),
            $row['registrationtime'] ?? '10:00:00'
        );

        $payment_type_id = (isset($row['typeofcustomer']) && $row['typeofcustomer'] == 'CREDIT') ? 2 : 1;

        // Check existing record
        $checkQuery = "SELECT created_at FROM patients WHERE patient_id = $patient_id";
        $resultB = $serverB->query($checkQuery);
        $rowB = $resultB->fetch_assoc();
        $existing_created_at = $rowB['created_at'] ?? null;

        try {
            if (!$existing_created_at) {
                if (!$dryRun) {
                    $insertQuery = "INSERT INTO patients (
                        patient_id, name, age, gender, contact, address, city, state,
                        occupation, aadhar_number, abha_id, ayushmaan_id,
                        payment_type_id, company_name, sms_sent, created_at
                    ) VALUES (
                        $patient_id, '$name', $age, '$gender', '$contact', '$address', 
                        '$city', '$state', NULL, NULL, NULL, NULL, 
                        $payment_type_id, NULL, 0, '$created_at'
                    )";
                    $success = $serverB->query($insertQuery);
                } else {
                    $success = true;
                }
                
                $processed[] = [
                    'patient_id' => $patient_id,
                    'name' => $name,
                    'action' => 'INSERT',
                    'status' => $success ? 'Success' : 'Failed',
                    'message' => $success ? '-' : $serverB->error
                ];
                $stats['inserted'] += $success ? 1 : 0;
                $stats['failed'] += $success ? 0 : 1;

            } elseif ($existing_created_at != $created_at) {
                if (!$dryRun) {
                    $updateQuery = "UPDATE patients SET 
                        name='$name', age=$age, gender='$gender', 
                        contact='$contact', address='$address',
                        city='$city', state='$state',
                        payment_type_id=$payment_type_id,
                        created_at='$created_at'
                        WHERE patient_id=$patient_id";
                    $success = $serverB->query($updateQuery);
                } else {
                    $success = true;
                }

                $processed[] = [
                    'patient_id' => $patient_id,
                    'name' => $name,
                    'action' => 'UPDATE',
                    'status' => $success ? 'Success' : 'Failed',
                    'message' => $success ? '-' : $serverB->error
                ];
                $stats['updated'] += $success ? 1 : 0;
                $stats['failed'] += $success ? 0 : 1;

            } else {
                $processed[] = [
                    'patient_id' => $patient_id,
                    'name' => $name,
                    'action' => 'SKIP',
                    'status' => 'No changes',
                    'message' => '-'
                ];
                $stats['skipped']++;
            }
        } catch (Exception $e) {
            $processed[] = [
                'patient_id' => $patient_id,
                'name' => $name,
                'action' => 'ERROR',
                'status' => 'Failed',
                'message' => $e->getMessage()
            ];
            $stats['failed']++;
        }
    }

    $resultA->free_result();
    return [
        'stats' => $stats,
        'processed' => $processed
    ];
}

/**
 * Helper function to format datetime
 */
function formatDateTime($date, $time) {
    try {
        $datetime = new DateTime($date . ' ' . $time);
        return $datetime->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return date('Y-m-d H:i:s');
    }
}
