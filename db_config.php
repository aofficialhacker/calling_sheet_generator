<?php
// Set timezone to UTC to ensure consistency
date_default_timezone_set('UTC');

// Centralized database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet3');

// Function to get database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+00:00'");
    return $conn;
}

// Function to check if user is superadmin
function isSuperadmin() {
    return isset($_SESSION['is_superadmin']) && $_SESSION['is_superadmin'] === true;
}

// Function to check if user is admin
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

// Function to require superadmin access
function requireSuperadmin() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (!isSuperadmin()) {
        header("Location: superadmin_login.php");
        exit();
    }
}

// Function to require admin access
function requireAdmin() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (!isAdmin() && !isSuperadmin()) { // Allow superadmin to access admin pages
        header("Location: admin_login.php");
        exit();
    }
}

// Function to check if user is team leader
function isTeamLeader() {
    return isset($_SESSION['is_team_leader']) && $_SESSION['is_team_leader'] === true;
}

// Function to require team leader access
function requireTeamLeader() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (!isTeamLeader()) {
        header("Location: team_leader_login.php?reason=not_logged_in");
        exit();
    }

    // Multi-device login check
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT active_session_id FROM team_leaders WHERE leader_id = ?");
    $stmt->bind_param("s", $_SESSION['leader_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['active_session_id'] !== session_id()) {
            // Current session ID does not match the active one in the DB.
            // This could mean another device has logged in OR admin refreshed the access code
            session_unset();
            session_destroy();
            if ($row['active_session_id'] === null || $row['active_session_id'] === '') {
                // If active_session_id is null, it likely means admin refreshed the code
                header("Location: team_leader_login.php?reason=code_refreshed");
            } else {
                // Otherwise it's a multi-device login scenario
                header("Location: team_leader_login.php?reason=multi_device");
            }
            exit();
        }
    } else {
        // Leader not found, something is wrong.
        session_unset();
        session_destroy();
        header("Location: team_leader_login.php?reason=invalid_user");
        exit();
    }
    $stmt->close();
    // Do not close the connection here if other queries need it on the same page.
    // $conn->close(); 
}

// Function to generate unique admin ID (No changes needed, logic is sound)
function generateAdminId($name, $conn) {
    $nameParts = explode(' ', trim($name));
    $initials = '';
    foreach ($nameParts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    
    if (strlen($initials) < 2) {
        $initials = strtoupper(substr($name, 0, 2));
    } else {
        $initials = substr($initials, 0, 2);
    }
    
    $stmt = $conn->prepare("SELECT admin_id FROM admin_users WHERE admin_id LIKE ? ORDER BY admin_id DESC LIMIT 1");
    $pattern = $initials . '%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $lastId = $result->fetch_assoc()['admin_id'];
        $number = intval(substr($lastId, 2)) + 1;
    } else {
        $number = 1;
    }
    
    $stmt->close();
    return $initials . str_pad($number, 2, '0', STR_PAD_LEFT);
}

/**
 * Generates the next globally unique vendor ID.
 * For additional vendor requests (is_additional = 1), starts from V61
 * For default vendors, uses V01-V60 range
 */
function generateVendorId($conn, $isAdditional = false) {
    if ($isAdditional) {
        // For additional vendor requests, start from V61
        $stmt = $conn->prepare("
            SELECT vendor_id FROM vendors 
            WHERE CAST(SUBSTRING(vendor_id, 2) AS UNSIGNED) >= 61 
            ORDER BY CAST(SUBSTRING(vendor_id, 2) AS UNSIGNED) DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $lastId = $result->fetch_assoc()['vendor_id'];
            $number = intval(substr($lastId, 1)) + 1;
        } else {
            $number = 61; // Start from V61 for additional vendors
        }
        
        $stmt->close();
        return 'V' . str_pad($number, 2, '0', STR_PAD_LEFT);
    } else {
        // Original logic for default vendors (V01-V60)
        $stmt = $conn->prepare("
            SELECT vendor_id FROM vendors 
            WHERE CAST(SUBSTRING(vendor_id, 2) AS UNSIGNED) < 61 
            ORDER BY CAST(SUBSTRING(vendor_id, 2) AS UNSIGNED) DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $lastId = $result->fetch_assoc()['vendor_id'];
            $number = intval(substr($lastId, 1)) + 1;
            if ($number > 60) {
                // If we've exhausted V01-V60, return null or handle error
                $stmt->close();
                return null;
            }
        } else {
            $number = 1;
        }
        
        $stmt->close();
        return 'V' . str_pad($number, 2, '0', STR_PAD_LEFT);
    }
}

/**
 * Generates the next unique batch ID based on product, vendor, and admin.
 * Format: [ProductCode][VendorID]B[BatchNumber] e.g., LIV01B001
 */
function generateBatchId($productCode, $vendorId, $adminId, $conn) {
    $baseId = strtoupper($productCode) . $vendorId . 'B';
    
    $stmt = $conn->prepare("SELECT id FROM file_batches WHERE id LIKE ? ORDER BY id DESC LIMIT 1");
    $pattern = $baseId . '%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $lastId = $result->fetch_assoc()['id'];
        // Extract the numeric part after 'B'
        $lastNumStr = substr($lastId, strrpos($lastId, 'B') + 1);
        $number = intval($lastNumStr) + 1;
    } else {
        $number = 1;
    }
    
    $stmt->close();
    return $baseId . str_pad($number, 3, '0', STR_PAD_LEFT);
}

/**
 * Generates a unique log ID for a record within a batch.
 * Format: [BatchID][PaddedRowNumber] e.g., LIV01B00100001
 */
function generateLogRowId($batchId, $rowNumber) {
    return $batchId . str_pad($rowNumber, 5, '0', STR_PAD_LEFT);
}

/**
 * Generates a 6-character alphanumeric access code for team leaders
 * @return string 6-character uppercase code
 */
function generateTeamLeaderAccessCode() {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $characters[mt_rand(0, strlen($characters) - 1)];
    }
    return $code;
}

/**
 * Checks if a team leader's access code needs to be refreshed (older than 4 hours)
 * and generates a new one if needed
 * @param string $leaderId The team leader ID
 * @param mysqli $conn Database connection
 * @param bool $forceRefresh Force generation of new code regardless of age
 * @return array Contains 'code' and 'expires_at'
 */
function refreshTeamLeaderCode($leaderId, $conn, $forceRefresh = false) {
    // Get current database time to ensure consistency
    $dbTimeStmt = $conn->prepare("SELECT NOW() as current_time, UNIX_TIMESTAMP(NOW()) as current_timestamp");
    if ($dbTimeStmt === false) {
        error_log("Database time query failed: " . $conn->error);
        // Fallback to original method if database query fails
        return refreshTeamLeaderCodeFallback($leaderId, $conn, $forceRefresh);
    }
    $dbTimeStmt->execute();
    $dbTimeResult = $dbTimeStmt->get_result();
    $dbTime = $dbTimeResult->fetch_assoc();
    $dbTimeStmt->close();
    
    $stmt = $conn->prepare("SELECT access_code, code_generated_at, UNIX_TIMESTAMP(code_generated_at) as generated_timestamp FROM team_leaders WHERE leader_id = ?");
    if ($stmt === false) {
        error_log("Team leader query failed: " . $conn->error);
        return refreshTeamLeaderCodeFallback($leaderId, $conn, $forceRefresh);
    }
    $stmt->bind_param("s", $leaderId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Use database timestamps for consistent calculation
        $codeAge = $dbTime['current_timestamp'] - $row['generated_timestamp'];
        
        // If code is older than 4 hours (14400 seconds), doesn't exist, or force refresh is requested
        if (!$row['access_code'] || $codeAge >= 14400 || $forceRefresh) {
            $newCode = generateTeamLeaderAccessCode();
            
            // If this is a forced refresh, clear the active session to force logout
            if ($forceRefresh) {
                $stmt = $conn->prepare("UPDATE team_leaders SET access_code = ?, code_generated_at = NOW(), active_session_id = NULL WHERE leader_id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE team_leaders SET access_code = ?, code_generated_at = NOW() WHERE leader_id = ?");
            }
            $stmt->bind_param("ss", $newCode, $leaderId);
            $stmt->execute();
            
            // Calculate expiry time using database time + 4 hours
            $expiryStmt = $conn->prepare("SELECT DATE_ADD(NOW(), INTERVAL 4 HOUR) as expires_at");
            $expiryStmt->execute();
            $expiryResult = $expiryStmt->get_result();
            $expiryTime = $expiryResult->fetch_assoc()['expires_at'];
            $expiryStmt->close();
            
            return [
                'code' => $newCode,
                'expires_at' => $expiryTime
            ];
        } else {
            // For existing codes, calculate expiry using database time
            $expiryStmt = $conn->prepare("SELECT DATE_ADD(code_generated_at, INTERVAL 4 HOUR) as expires_at FROM team_leaders WHERE leader_id = ?");
            $expiryStmt->bind_param("s", $leaderId);
            $expiryStmt->execute();
            $expiryResult = $expiryStmt->get_result();
            $expiryTime = $expiryResult->fetch_assoc()['expires_at'];
            $expiryStmt->close();
            
            return [
                'code' => $row['access_code'],
                'expires_at' => $expiryTime
            ];
        }
    }
    
    $stmt->close();
    return ['code' => null, 'expires_at' => null];
}

/**
 * Validates a team leader's access code
 * @param string $leaderId The team leader ID
 * @param string $inputCode The code provided by user
 * @param mysqli $conn Database connection
 * @return boolean True if code is valid and not expired
 */
function validateTeamLeaderAccessCode($leaderId, $inputCode, $conn) {
    $stmt = $conn->prepare("SELECT access_code, code_generated_at FROM team_leaders WHERE leader_id = ? AND is_active = 1");
    $stmt->bind_param("s", $leaderId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $codeAge = time() - strtotime($row['code_generated_at']);
        
        // Check if code matches and is not expired (less than 4 hours old)
        if ($row['access_code'] === strtoupper($inputCode) && $codeAge < 14400) {
            $stmt->close();
            return true;
        }
    }
    
    $stmt->close();
    return false;
}

/**
 * Fallback function for refreshTeamLeaderCode using original PHP time methods
 * Used when database timestamp queries fail
 */
function refreshTeamLeaderCodeFallback($leaderId, $conn, $forceRefresh = false) {
    $stmt = $conn->prepare("SELECT access_code, code_generated_at FROM team_leaders WHERE leader_id = ?");
    $stmt->bind_param("s", $leaderId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $codeAge = time() - strtotime($row['code_generated_at']);
        
        // If code is older than 4 hours (14400 seconds), doesn't exist, or force refresh is requested
        if (!$row['access_code'] || $codeAge >= 14400 || $forceRefresh) {
            $newCode = generateTeamLeaderAccessCode();
            
            // If this is a forced refresh, clear the active session to force logout
            if ($forceRefresh) {
                $stmt = $conn->prepare("UPDATE team_leaders SET access_code = ?, code_generated_at = NOW(), active_session_id = NULL WHERE leader_id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE team_leaders SET access_code = ?, code_generated_at = NOW() WHERE leader_id = ?");
            }
            $stmt->bind_param("ss", $newCode, $leaderId);
            $stmt->execute();
            
            return [
                'code' => $newCode,
                'expires_at' => date('Y-m-d H:i:s', time() + 14400)
            ];
        } else {
            return [
                'code' => $row['access_code'],
                'expires_at' => date('Y-m-d H:i:s', strtotime($row['code_generated_at']) + 14400)
            ];
        }
    }
    
    $stmt->close();
    return ['code' => null, 'expires_at' => null];
}

/**
 * Get the real client IP address, handling proxies and load balancers
 * @return string The client's IP address
 */
function getRealIpAddress() {
    // Check for various proxy headers in order of preference
    $ipKeys = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Standard proxy header
        'HTTP_X_FORWARDED',          // Alternative proxy header
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster/load balancer
        'HTTP_FORWARDED_FOR',        // Another proxy header
        'HTTP_FORWARDED',            // RFC 7239
        'HTTP_CLIENT_IP',            // Some proxies
        'REMOTE_ADDR'                // Direct connection
    ];
    
    foreach ($ipKeys as $key) {
        if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]); // Get the first IP if comma-separated
            
            // Validate IP address
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    // If no valid public IP found, return the REMOTE_ADDR (which might be local)
    $fallbackIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // For localhost development, return a placeholder
    if (in_array($fallbackIp, ['127.0.0.1', '::1', 'localhost'])) {
        return 'localhost-dev';
    }
    
    return $fallbackIp;
}