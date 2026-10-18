<?php
/**
 * Helper functions for handling location data (states, districts, tehsils)
 */

/**
 * Get all states from JSON file
 * 
 * @return array Array of state names
 */
function getStates() {
    $statesFile = __DIR__ . '/../../assets/json/states.json';
    if (file_exists($statesFile)) {
        return json_decode(file_get_contents($statesFile), true);
    }
    return [];
}

/**
 * Get all districts from JSON file
 * 
 * @return array Array of district names
 */
function getDistricts() {
    $districtsFile = __DIR__ . '/../../assets/json/district_names.json';
    if (file_exists($districtsFile)) {
        return json_decode(file_get_contents($districtsFile), true);
    }
    return [];
}

/**
 * Get tehsils for a specific district
 * 
 * @param string $district District name
 * @return array Array of tehsil names for the district
 */
function getTehsils($district = '') {
    $tehsilsFile = __DIR__ . '/../../assets/json/district-tehsil.json';
    if (file_exists($tehsilsFile)) {
        $tehsilData = json_decode(file_get_contents($tehsilsFile), true);
        
        if (empty($district)) {
            return $tehsilData;
        } else {
            // For "Others" district, return single "Others" tehsil
            if ($district === 'Others') {
                return ['Others'];
            }
            
            // Handle different district name formats
            $district = str_replace('-', ' ', $district);
            $district = str_replace('(East Nimar)', '', $district);
            $district = str_replace('(West Nimar)', '', $district);
            $district = trim($district);
            
            return $tehsilData[$district] ?? [];
        }
    }
    return [];
}

/**
 * Get all tehsils as flattened array with district as key
 * 
 * @return array Array of all tehsils with their districts
 */
function getAllTehsilsWithDistricts() {
    $tehsilData = getTehsils();
    $result = [];
    
    foreach ($tehsilData as $district => $tehsils) {
        foreach ($tehsils as $tehsil) {
            $result[] = [
                'id' => $tehsil,
                'text' => $tehsil,
                'district' => $district
            ];
        }
    }
    
    return $result;
}

/**
 * Format location data for Select2
 * 
 * @param array $items Array of location items
 * @return array Formatted array for Select2
 */
function formatForSelect2($items) {
    $result = [];
    foreach ($items as $item) {
        $result[] = [
            'id' => $item,
            'text' => $item
        ];
    }
    return $result;
}

/**
 * Get location data as JSON for AJAX requests
 * 
 * @param string $type Type of location data (states, districts, tehsils)
 * @param string $parent Parent location (for tehsils, this would be district)
 * @return string JSON encoded location data
 */
function getLocationDataJson($type, $parent = '') {
    switch ($type) {
        case 'states':
            return json_encode(formatForSelect2(getStates()));
        case 'districts':
            return json_encode(formatForSelect2(getDistricts()));
        case 'tehsils':
            return json_encode(formatForSelect2(getTehsils($parent)));
        case 'all_tehsils':
            return json_encode(getAllTehsilsWithDistricts());
        default:
            return json_encode([]);
    }
}

/**
 * Handle location data API requests directly
 * This function should be called at the beginning of the file
 * to handle direct requests to this file
 */
function handleLocationRequest() {
    // Only handle when this file is accessed directly as an API endpoint
    if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
        // Check if core.php is required
        if (!defined('SECURE_ACCESS')) {
            define('SECURE_ACCESS', true);
            $corePath = __DIR__ . '/../core.php';
            if (file_exists($corePath)) {
                require_once $corePath;
            }
        }
        
        // Check if the request is AJAX
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        // Allow direct access for development but log warnings
        if (!$isAjax) {
            error_log('Warning: Direct access to location_helper.php without AJAX');
        }
        
        // Process request
        $type = $_GET['type'] ?? '';
        $parent = $_GET['parent'] ?? '';
        
        // Set headers for JSON response
        header('Content-Type: application/json');
        
        // Return appropriate data based on type
        echo getLocationDataJson($type, $parent);
        exit;
    }
}

// Handle direct API requests to this file
handleLocationRequest();
