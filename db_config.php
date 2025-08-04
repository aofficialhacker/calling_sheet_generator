<?php
// Centralized database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

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
    session_start();
    if (!isSuperadmin()) {
        header("Location: superadmin_login.php");
        exit();
    }
}

// Function to require admin access
function requireAdmin() {
    session_start();
    if (!isAdmin() && !isSuperadmin()) {
        header("Location: admin_login.php");
        exit();
    }
}

// Function to generate unique admin ID
function generateAdminId($name, $conn) {
    // Extract initials from name
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
    
    // Find the next available number for these initials
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

// Function to generate next vendor ID for an admin
function generateVendorId($adminId, $conn) {
    $stmt = $conn->prepare("SELECT vendor_id FROM vendors ORDER BY CAST(SUBSTRING(vendor_id, 2) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $lastId = $result->fetch_assoc()['vendor_id'];
        $number = intval(substr($lastId, 1)) + 1;
    } else {
        $number = 1;
    }
    
    $stmt->close();
    return 'V' . str_pad($number, 2, '0', STR_PAD_LEFT);
}