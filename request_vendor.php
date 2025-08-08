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
    header("Location: upload_batch.php");
    exit();
}

$conn = getDBConnection();

// Ensure admin has not exceeded maximum of 4 requests
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM vendor_requests WHERE admin_id = ?");
$countStmt->bind_param("s", $adminId);
$countStmt->execute();
$totalRequests = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

if ($totalRequests >= 4) {
    $_SESSION['flash_message'] = ['type' => 'warning', 'text' => 'You have reached the maximum number of vendor requests (4).'];
    header("Location: upload_batch.php");
    exit();
}

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

header("Location: upload_batch.php");
exit();