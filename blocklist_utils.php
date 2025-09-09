<?php
require_once 'db_config.php';

/**
 * Check if a mobile number is in the blocklist for a specific admin
 */
function isMobileNumberBlocked($admin_id, $mobile_no) {
    $conn = getDBConnection();
    
    // Clean mobile number (remove all non-digits)
    $clean_mobile = preg_replace('/\D/', '', $mobile_no);
    
    // Check if number exists in blocklist
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM blocklist_numbers WHERE admin_id = ? AND mobile_no = ?");
    $stmt->bind_param("ss", $admin_id, $clean_mobile);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $result['count'] > 0;
}

/**
 * Add a mobile number to the blocklist
 */
function addToBlocklist($admin_id, $mobile_no, $created_by, $batch_id = null, $notes = null) {
    $conn = getDBConnection();
    
    // Clean mobile number
    $clean_mobile = preg_replace('/\D/', '', $mobile_no);
    
    // Insert with ON DUPLICATE KEY UPDATE to handle duplicates
    $stmt = $conn->prepare("INSERT INTO blocklist_numbers (admin_id, mobile_no, created_by, batch_id, notes) 
                           VALUES (?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE 
                           upload_date = CURRENT_TIMESTAMP, 
                           notes = COALESCE(VALUES(notes), notes)");
    $stmt->bind_param("sssss", $admin_id, $clean_mobile, $created_by, $batch_id, $notes);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * Remove a mobile number from the blocklist
 */
function removeFromBlocklist($admin_id, $mobile_no) {
    $conn = getDBConnection();
    
    $clean_mobile = preg_replace('/\D/', '', $mobile_no);
    
    $stmt = $conn->prepare("DELETE FROM blocklist_numbers WHERE admin_id = ? AND mobile_no = ?");
    $stmt->bind_param("ss", $admin_id, $clean_mobile);
    $success = $stmt->execute();
    $affected = $conn->affected_rows;
    $stmt->close();
    $conn->close();
    
    return $affected > 0;
}

/**
 * Get all blocklist numbers for an admin with pagination
 */
function getBlocklistNumbers($admin_id, $limit = 100, $offset = 0, $search = null) {
    $conn = getDBConnection();
    
    $sql = "SELECT id, mobile_no, batch_id, upload_date, created_by, notes 
            FROM blocklist_numbers 
            WHERE admin_id = ?";
    $params = [$admin_id];
    $types = "s";
    
    if ($search && !empty(trim($search))) {
        $sql .= " AND (mobile_no LIKE ? OR notes LIKE ? OR batch_id LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }
    
    $sql .= " ORDER BY upload_date DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("SQL Prepare Error: " . $conn->error . " - SQL: " . $sql);
        $conn->close();
        return [];
    }
    
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log("SQL Execute Error: " . $stmt->error);
        $stmt->close();
        $conn->close();
        return [];
    }
    
    $result = $stmt->get_result();
    $numbers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    
    return $numbers;
}

/**
 * Get count of blocklist numbers for an admin
 */
function getBlocklistCount($admin_id, $search = null) {
    $conn = getDBConnection();
    
    $sql = "SELECT COUNT(*) as count FROM blocklist_numbers WHERE admin_id = ?";
    $params = [$admin_id];
    $types = "s";
    
    if ($search && !empty(trim($search))) {
        $sql .= " AND (mobile_no LIKE ? OR notes LIKE ? OR batch_id LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("SQL Prepare Error in getBlocklistCount: " . $conn->error);
        $conn->close();
        return 0;
    }
    
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log("SQL Execute Error in getBlocklistCount: " . $stmt->error);
        $stmt->close();
        $conn->close();
        return 0;
    }
    
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $result['count'] ?? 0;
}

/**
 * Delete all blocklist numbers for a specific batch
 */
function deleteBlocklistBatch($admin_id, $batch_id) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("DELETE FROM blocklist_numbers WHERE admin_id = ? AND batch_id = ?");
    $stmt->bind_param("ss", $admin_id, $batch_id);
    $success = $stmt->execute();
    $affected = $conn->affected_rows;
    $stmt->close();
    $conn->close();
    
    return $affected;
}

/**
 * Get blocklist statistics for an admin
 */
function getBlocklistStats($admin_id) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT 
                           COUNT(*) as total_blocked,
                           COUNT(DISTINCT batch_id) as total_batches,
                           MAX(upload_date) as latest_upload
                           FROM blocklist_numbers 
                           WHERE admin_id = ?");
    
    if ($stmt === false) {
        error_log("SQL Prepare Error in getBlocklistStats: " . $conn->error);
        $conn->close();
        return [
            'total_blocked' => 0,
            'total_batches' => 0,
            'latest_upload' => null
        ];
    }
    
    $stmt->bind_param("s", $admin_id);
    if (!$stmt->execute()) {
        error_log("SQL Execute Error in getBlocklistStats: " . $stmt->error);
        $stmt->close();
        $conn->close();
        return [
            'total_blocked' => 0,
            'total_batches' => 0,
            'latest_upload' => null
        ];
    }
    
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $result ?: [
        'total_blocked' => 0,
        'total_batches' => 0,
        'latest_upload' => null
    ];
}

/**
 * Filter mobile numbers array to remove blocked ones
 */
function filterBlockedNumbers($admin_id, $mobile_numbers) {
    if (empty($mobile_numbers)) {
        return [];
    }
    
    $conn = getDBConnection();
    
    // Clean all mobile numbers
    $clean_numbers = array_map(function($num) {
        return preg_replace('/\D/', '', $num);
    }, $mobile_numbers);
    
    // Create placeholders for IN clause
    $placeholders = str_repeat('?,', count($clean_numbers) - 1) . '?';
    
    $sql = "SELECT mobile_no FROM blocklist_numbers 
            WHERE admin_id = ? AND mobile_no IN ({$placeholders})";
    
    $stmt = $conn->prepare($sql);
    $types = "s" . str_repeat("s", count($clean_numbers));
    $params = array_merge([$admin_id], $clean_numbers);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $blocked = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $blocked[] = $row['mobile_no'];
    }
    $stmt->close();
    $conn->close();
    
    // Return numbers that are NOT in the blocked list
    $allowed = [];
    for ($i = 0; $i < count($mobile_numbers); $i++) {
        if (!in_array($clean_numbers[$i], $blocked)) {
            $allowed[] = $mobile_numbers[$i];
        }
    }
    
    return $allowed;
}

/**
 * Generate a unique batch ID for blocklist uploads
 */
function generateBlocklistBatchId($admin_id) {
    return "BL_" . $admin_id . "_" . date('YmdHis') . "_" . substr(uniqid(), -4);
}

/**
 * Map Excel columns for blocklist processing (similar to upload_batch.php)
 */
function mapBlocklistColumns(array $headerRow): array {
    $map = ['mobile_no' => -1, 'phone' => -1, 'contact' => -1, 'number' => -1, 'notes' => -1];
    
    foreach ($headerRow as $index => $header) {
        if (is_null($header)) continue;
        $normalizedHeader = strtolower(trim(str_replace(['_', ' '], '', $header)));
        if (empty($normalizedHeader)) continue;
        
        switch (true) {
            case ($map['mobile_no'] === -1 && preg_match('/mobile|cell/i', $normalizedHeader)):
                $map['mobile_no'] = $index;
                break;
            case ($map['phone'] === -1 && preg_match('/phone/i', $normalizedHeader)):
                $map['phone'] = $index;
                break;
            case ($map['contact'] === -1 && preg_match('/contact/i', $normalizedHeader)):
                $map['contact'] = $index;
                break;
            case ($map['number'] === -1 && preg_match('/number|num/i', $normalizedHeader)):
                $map['number'] = $index;
                break;
            case ($map['notes'] === -1 && preg_match('/note|comment|reason/i', $normalizedHeader)):
                $map['notes'] = $index;
                break;
        }
    }
    
    return $map;
}

/**
 * Extract mobile number from mapped columns
 */
function extractMobileNumber($dataRow, $columnMap) {
    // Try each potential mobile number column in order of preference
    $mobileColumns = ['mobile_no', 'phone', 'contact', 'number'];
    
    foreach ($mobileColumns as $column) {
        if ($columnMap[$column] !== -1 && isset($dataRow[$columnMap[$column]])) {
            $mobile = (string)$dataRow[$columnMap[$column]];
            if (!empty($mobile)) {
                return $mobile;
            }
        }
    }
    
    return null;
}
?>