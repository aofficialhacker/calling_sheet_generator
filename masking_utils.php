<?php
/**
 * Masking Utilities for Team Leader Portal
 * Provides secure data masking/unmasking functions for customer information
 */

/**
 * Mask customer name with asterisks
 * Format: "ANTHONY DSOUZA" -> "A***Y D*****A"
 * @param string $name Full customer name
 * @return string Masked name
 */
function maskName($name) {
    if (empty($name)) return $name;
    
    $name = trim($name);
    $words = explode(' ', $name);
    $maskedWords = [];
    
    foreach ($words as $word) {
        if (strlen($word) <= 2) {
            $maskedWords[] = $word; // Keep short words as is
        } else {
            $first = substr($word, 0, 1);
            $last = substr($word, -1, 1);
            $middle = str_repeat('*', strlen($word) - 2);
            $maskedWords[] = $first . $middle . $last;
        }
    }
    
    return implode(' ', $maskedWords);
}

/**
 * Mask mobile number
 * Format: "9876543210" -> "98XXXXXX10"
 * @param string $mobile Mobile number
 * @return string Masked mobile number
 */
function maskMobile($mobile) {
    if (empty($mobile)) return $mobile;
    
    $mobile = preg_replace('/[^0-9]/', '', $mobile); // Remove non-numeric chars
    
    if (strlen($mobile) < 4) {
        return $mobile; // Too short to mask properly
    }
    
    if (strlen($mobile) == 10) {
        // Standard 10-digit format: keep first 2 and last 2
        return substr($mobile, 0, 2) . str_repeat('X', 6) . substr($mobile, -2);
    } elseif (strlen($mobile) > 10) {
        // Longer numbers: keep first 2 and last 2, mask the rest
        $maskLength = strlen($mobile) - 4;
        return substr($mobile, 0, 2) . str_repeat('X', $maskLength) . substr($mobile, -2);
    } else {
        // Shorter numbers: keep first and last, mask middle
        $first = substr($mobile, 0, 1);
        $last = substr($mobile, -1);
        $middle = str_repeat('X', strlen($mobile) - 2);
        return $first . $middle . $last;
    }
}

/**
 * Check if current session has an unmasked entry
 * @return string|null Lead ID of currently unmasked entry or null
 */
function getCurrentUnmaskedEntry() {
    if (isset($_SESSION['unmasked_lead_id']) && isset($_SESSION['unmask_expires_at'])) {
        if (time() <= $_SESSION['unmask_expires_at']) {
            return $_SESSION['unmasked_lead_id'];
        } else {
            // Expired, clean up
            unset($_SESSION['unmasked_lead_id'], $_SESSION['unmask_expires_at']);
        }
    }
    return null;
}

/**
 * Set an entry as unmasked for 1 minute
 * @param string $leadId Lead ID to unmask
 */
function setUnmaskedEntry($leadId) {
    $_SESSION['unmasked_lead_id'] = $leadId;
    $_SESSION['unmask_expires_at'] = time() + 60; // 1 minute from now
}

/**
 * Clear unmasked entry from session
 */
function clearUnmaskedEntry() {
    unset($_SESSION['unmasked_lead_id'], $_SESSION['unmask_expires_at']);
}

/**
 * Check if a specific lead is currently unmasked
 * @param string $leadId Lead ID to check
 * @return bool True if unmasked and not expired
 */
function isLeadUnmasked($leadId) {
    $currentUnmasked = getCurrentUnmaskedEntry();
    return $currentUnmasked === $leadId;
}

/**
 * Get remaining unmask time in seconds
 * @return int Remaining seconds or 0 if expired/no unmasked entry
 */
function getRemainingUnmaskTime() {
    if (isset($_SESSION['unmask_expires_at'])) {
        $remaining = $_SESSION['unmask_expires_at'] - time();
        return max(0, $remaining);
    }
    return 0;
}

/**
 * Format display name (masked or unmasked based on session)
 * @param string $name Original name
 * @param string $leadId Lead ID
 * @return string Masked or unmasked name
 */
function getDisplayName($name, $leadId) {
    return isLeadUnmasked($leadId) ? $name : maskName($name);
}

/**
 * Format display mobile (masked or unmasked based on session)
 * @param string $mobile Original mobile
 * @param string $leadId Lead ID
 * @return string Masked or unmasked mobile
 */
function getDisplayMobile($mobile, $leadId) {
    return isLeadUnmasked($leadId) ? $mobile : maskMobile($mobile);
}

/**
 * Log view action for audit trail
 * @param string $leaderId Team Leader ID
 * @param string $leadId Lead ID
 * @param string $action Action type ('view_request', 'view_granted', 'view_expired')
 * @param PDO $conn Database connection
 */
function logViewAction($leaderId, $leadId, $action, $conn) {
    try {
        $ipAddress = getRealIpAddress();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $sessionId = session_id();
        
        $stmt = $conn->prepare("
            INSERT INTO lv_team_leader_view_logs 
            (leader_id, lead_id, action, ip_address, user_agent, session_id, timestamp) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("ssssss", $leaderId, $leadId, $action, $ipAddress, $userAgent, $sessionId);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Log error but don't break functionality
        error_log("View action logging failed: " . $e->getMessage());
    }
}

/**
 * Create lv_team_leader_view_logs table if it doesn't exist
 * @param mysqli $conn Database connection
 */
function ensureViewLogsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS lv_team_leader_view_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        leader_id VARCHAR(50) NOT NULL,
        lead_id VARCHAR(50) NOT NULL,
        action VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        session_id VARCHAR(100),
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_leader_id (leader_id),
        INDEX idx_lead_id (lead_id),
        INDEX idx_timestamp (timestamp)
    ) ENGINE=InnoDB";
    
    $conn->query($sql);
}
?>