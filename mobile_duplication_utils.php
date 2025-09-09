<?php
require_once 'db_config.php';

/**
 * Check if a mobile number already exists anywhere in the system
 * Returns true if the number exists in final_call_logs table
 */
function isMobileNumberDuplicate($mobile_no) {
    $conn = getDBConnection();
    
    // Clean mobile number (remove all non-digits)
    $clean_mobile = preg_replace('/\D/', '', $mobile_no);
    
    // Check if number exists in final_call_logs table (system-wide)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM final_call_logs WHERE mobile_no = ?");
    $stmt->bind_param("s", $clean_mobile);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $result['count'] > 0;
}

/**
 * Get details of where a mobile number already exists in the system
 * Returns array with batch_id, admin_id, upload_date, etc.
 */
function getMobileDuplicateDetails($mobile_no) {
    $conn = getDBConnection();
    
    // Clean mobile number
    $clean_mobile = preg_replace('/\D/', '', $mobile_no);
    
    // Get details of existing record
    $sql = "SELECT fcl.batch_id, fcl.name, fb.admin_id, fb.upload_time, fb.original_filename, fb.product_code
            FROM final_call_logs fcl 
            JOIN file_batches fb ON fcl.batch_id = fb.id 
            WHERE fcl.mobile_no = ? 
            ORDER BY fb.upload_time ASC 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $clean_mobile);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $details = null;
    if ($result->num_rows > 0) {
        $details = $result->fetch_assoc();
    }
    
    $stmt->close();
    $conn->close();
    
    return $details;
}

/**
 * Filter an array of mobile numbers to remove duplicates
 * Returns array with 'allowed' (non-duplicate numbers) and 'duplicates' (duplicate info)
 */
function filterDuplicateMobileNumbers($mobile_numbers) {
    if (empty($mobile_numbers)) {
        return ['allowed' => [], 'duplicates' => []];
    }
    
    $conn = getDBConnection();
    
    // Clean all mobile numbers and create mapping
    $clean_numbers = [];
    $original_to_clean = [];
    
    foreach ($mobile_numbers as $index => $mobile) {
        $clean = preg_replace('/\D/', '', $mobile);
        if (strlen($clean) >= 10) { // Only process valid mobile numbers
            $clean_numbers[] = $clean;
            $original_to_clean[$index] = $clean;
        }
    }
    
    if (empty($clean_numbers)) {
        return ['allowed' => [], 'duplicates' => []];
    }
    
    // Create placeholders for IN clause
    $placeholders = str_repeat('?,', count($clean_numbers) - 1) . '?';
    
    // Query to find existing numbers
    $sql = "SELECT DISTINCT fcl.mobile_no, fcl.batch_id, fcl.name, fb.admin_id, fb.upload_time, fb.original_filename 
            FROM final_call_logs fcl 
            JOIN file_batches fb ON fcl.batch_id = fb.id 
            WHERE fcl.mobile_no IN ({$placeholders})
            ORDER BY fb.upload_time ASC";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat("s", count($clean_numbers));
    $stmt->bind_param($types, ...$clean_numbers);
    $stmt->execute();
    
    // Collect existing numbers
    $existing_numbers = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing_numbers[$row['mobile_no']] = $row;
    }
    $stmt->close();
    $conn->close();
    
    // Separate allowed and duplicate numbers
    $allowed = [];
    $duplicates = [];
    
    foreach ($mobile_numbers as $index => $original_mobile) {
        if (isset($original_to_clean[$index])) {
            $clean_mobile = $original_to_clean[$index];
            
            if (isset($existing_numbers[$clean_mobile])) {
                // This is a duplicate
                $duplicates[] = [
                    'original_mobile' => $original_mobile,
                    'clean_mobile' => $clean_mobile,
                    'existing_in_batch' => $existing_numbers[$clean_mobile]['batch_id'],
                    'existing_admin' => $existing_numbers[$clean_mobile]['admin_id'],
                    'existing_filename' => $existing_numbers[$clean_mobile]['original_filename'],
                    'existing_upload_date' => $existing_numbers[$clean_mobile]['upload_time'],
                    'existing_name' => $existing_numbers[$clean_mobile]['name']
                ];
            } else {
                // This is allowed
                $allowed[] = $original_mobile;
            }
        }
    }
    
    return [
        'allowed' => $allowed,
        'duplicates' => $duplicates
    ];
}

/**
 * Get a set of mobile numbers that exist in the system (bulk check)
 * Returns array of mobile numbers that are duplicates
 * Much faster than checking individually for large datasets
 */
function getBulkDuplicateMobileNumbers($mobile_numbers) {
    if (empty($mobile_numbers)) {
        return [];
    }
    
    $conn = getDBConnection();
    
    // Clean all mobile numbers and remove duplicates within the input
    $clean_numbers = [];
    foreach ($mobile_numbers as $mobile) {
        $clean = preg_replace('/\D/', '', $mobile);
        if (strlen($clean) >= 10) {
            $clean_numbers[] = $clean;
        }
    }
    
    if (empty($clean_numbers)) {
        $conn->close();
        return [];
    }
    
    // Remove duplicates within the array itself
    $unique_clean_numbers = array_unique($clean_numbers);
    
    // Split into chunks to avoid MySQL limitations on IN clause
    $chunk_size = 1000; // MySQL can handle this easily
    $existing_numbers = [];
    
    foreach (array_chunk($unique_clean_numbers, $chunk_size) as $chunk) {
        $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
        
        // Query to find existing numbers in this chunk
        $sql = "SELECT DISTINCT mobile_no FROM final_call_logs WHERE mobile_no IN ({$placeholders})";
        
        $stmt = $conn->prepare($sql);
        $types = str_repeat("s", count($chunk));
        $stmt->bind_param($types, ...$chunk);
        $stmt->execute();
        
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existing_numbers[$row['mobile_no']] = true;
        }
        $stmt->close();
    }
    
    $conn->close();
    
    return array_keys($existing_numbers);
}

/**
 * Get count of how many mobile numbers from an array are duplicates
 * Optimized for just getting the count without detailed info
 */
function countDuplicateMobileNumbers($mobile_numbers) {
    if (empty($mobile_numbers)) {
        return 0;
    }
    
    $conn = getDBConnection();
    
    // Clean all mobile numbers
    $clean_numbers = [];
    foreach ($mobile_numbers as $mobile) {
        $clean = preg_replace('/\D/', '', $mobile);
        if (strlen($clean) >= 10) {
            $clean_numbers[] = $clean;
        }
    }
    
    if (empty($clean_numbers)) {
        return 0;
    }
    
    // Remove duplicates within the array itself first
    $unique_clean_numbers = array_unique($clean_numbers);
    
    // Create placeholders for IN clause
    $placeholders = str_repeat('?,', count($unique_clean_numbers) - 1) . '?';
    
    // Query to count existing numbers
    $sql = "SELECT COUNT(DISTINCT mobile_no) as count FROM final_call_logs WHERE mobile_no IN ({$placeholders})";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat("s", count($unique_clean_numbers));
    $stmt->bind_param($types, ...array_values($unique_clean_numbers));
    $stmt->execute();
    
    $result = $stmt->get_result()->fetch_assoc();
    $count = $result['count'] ?? 0;
    
    $stmt->close();
    $conn->close();
    
    return (int)$count;
}

/**
 * Get statistics about mobile number duplicates in a batch
 */
function getDuplicationStats($admin_id = null) {
    $conn = getDBConnection();
    
    $sql = "SELECT 
                COUNT(*) as total_numbers,
                COUNT(DISTINCT mobile_no) as unique_numbers,
                (COUNT(*) - COUNT(DISTINCT mobile_no)) as internal_duplicates
            FROM final_call_logs fcl
            JOIN file_batches fb ON fcl.batch_id = fb.id";
    
    $params = [];
    $types = "";
    
    if ($admin_id) {
        $sql .= " WHERE fb.admin_id = ?";
        $params[] = $admin_id;
        $types = "s";
    }
    
    $stmt = $conn->prepare($sql);
    if ($admin_id) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $result ?: [
        'total_numbers' => 0,
        'unique_numbers' => 0,
        'internal_duplicates' => 0
    ];
}

/**
 * Get a set of mobile numbers that are blocked for a specific admin (bulk check)
 * Returns array of mobile numbers that are blocked
 */
function getBulkBlockedMobileNumbers($admin_id, $mobile_numbers) {
    if (empty($mobile_numbers)) {
        return [];
    }
    
    $conn = getDBConnection();
    
    // Clean all mobile numbers and remove duplicates within the input
    $clean_numbers = [];
    foreach ($mobile_numbers as $mobile) {
        $clean = preg_replace('/\D/', '', $mobile);
        if (strlen($clean) >= 10) {
            $clean_numbers[] = $clean;
        }
    }
    
    if (empty($clean_numbers)) {
        $conn->close();
        return [];
    }
    
    // Remove duplicates within the array itself
    $unique_clean_numbers = array_unique($clean_numbers);
    
    // Split into chunks to avoid MySQL limitations on IN clause
    $chunk_size = 1000;
    $blocked_numbers = [];
    
    foreach (array_chunk($unique_clean_numbers, $chunk_size) as $chunk) {
        $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
        
        // Query to find blocked numbers in this chunk
        $sql = "SELECT DISTINCT mobile_no FROM blocklist_numbers WHERE admin_id = ? AND mobile_no IN ({$placeholders})";
        
        $stmt = $conn->prepare($sql);
        $types = "s" . str_repeat("s", count($chunk));
        $params = array_merge([$admin_id], $chunk);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $blocked_numbers[$row['mobile_no']] = true;
        }
        $stmt->close();
    }
    
    $conn->close();
    
    return array_keys($blocked_numbers);
}

/**
 * Clean and validate mobile number
 * Returns cleaned mobile number or null if invalid
 */
function cleanAndValidateMobile($mobile_no) {
    if (empty($mobile_no)) {
        return null;
    }
    
    // Remove all non-digits
    $clean = preg_replace('/\D/', '', $mobile_no);
    
    // Check if it's a valid Indian mobile number (10 digits)
    if (strlen($clean) >= 10) {
        // Take last 10 digits if more than 10 (handles +91 prefix)
        if (strlen($clean) > 10) {
            $clean = substr($clean, -10);
        }
        
        // Check if it starts with valid Indian mobile prefixes (6,7,8,9)
        if (preg_match('/^[6-9]\d{9}$/', $clean)) {
            return $clean;
        }
    }
    
    return null;
}
?>