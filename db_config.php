<?php
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