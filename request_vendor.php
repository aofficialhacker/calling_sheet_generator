<?php
session_start();
require_once 'db_config.php';

if (!isAdmin() || !isset($_POST['vendor_name'])) {
    header("Location: admin_panel.php");
    exit();
}

$adminId = $_SESSION['admin_id'];
$vendorName = trim($_POST['vendor_name']);

if (empty($vendorName)) {
    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Vendor name cannot be empty.'];
    header("Location: admin_panel.php");
    exit();
}

$conn = getDBConnection();

// Check if a similar request is already pending
$checkStmt = $conn->prepare("
    SELECT id FROM vendor_requests 
    WHERE admin_id = ? AND vendor_name = ? AND status = 'pending'
");
$checkStmt->bind_param("ss", $adminId, $vendorName);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows > 0) {
    $_SESSION['flash_message'] = ['type' => 'warning', 'text' => 'A request for this vendor is already pending.'];
} else {
    // Insert new request
    $insertStmt = $conn->prepare("
        INSERT INTO vendor_requests (admin_id, vendor_name) 
        VALUES (?, ?)
    ");
    $insertStmt->bind_param("ss", $adminId, $vendorName);
    
    if ($insertStmt->execute()) {
        $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Vendor request submitted successfully. You will be notified once approved.'];
    } else {
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Failed to submit vendor request.'];
    }
    $insertStmt->close();
}

$checkStmt->close();
$conn->close();

header("Location: admin_panel.php");
exit();