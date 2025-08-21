<?php
require_once 'db_config.php';
requireTeamLeader();

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$ipAddress = getRealIpAddress();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$sessionId = session_id();

$message = '';
$messageType = '';

if ($_POST) {
    $leadId = trim($_POST['lead_id']);
    $newDisposition = trim($_POST['new_disposition']);
    $remarks = trim($_POST['remarks']);
    
    // Validate that the lead exists and belongs to this admin's team
    $stmt = $conn->prepare("
        SELECT fcl.*, fcl.disposition as original_disposition
        FROM final_call_logs fcl
        JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
        WHERE fcl.id = ? AND acm.admin_id = ? AND fcl.disposition = 'Interested'
    ");
    $stmt->bind_param("ss", $leadId, $_SESSION['admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $leadData = $result->fetch_assoc();
        
        // Check if action already exists
        $stmt = $conn->prepare("SELECT id FROM team_leader_actions WHERE lead_id = ? AND leader_id = ?");
        $stmt->bind_param("ss", $leadId, $leaderId);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $_SESSION['message'] = "Action already taken on this lead.";
            $_SESSION['messageType'] = "warning";
        } else {
            // Generate unique action ID
            $actionId = 'TLA' . date('YmdHis') . substr(uniqid(), -4);
            
            // Insert team leader action
            $stmt = $conn->prepare("
                INSERT INTO team_leader_actions 
                (action_id, leader_id, lead_id, original_disposition, new_disposition, remarks, ip_address, user_agent, session_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssss", $actionId, $leaderId, $leadId, $leadData['original_disposition'], $newDisposition, $remarks, $ipAddress, $userAgent, $sessionId);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "Action recorded successfully!";
                $_SESSION['messageType'] = "success";
            } else {
                $_SESSION['message'] = "Error recording action: " . $stmt->error;
                $_SESSION['messageType'] = "danger";
            }
        }
    } else {
        $_SESSION['message'] = "Invalid lead or lead not accessible.";
        $_SESSION['messageType'] = "danger";
    }
    $stmt->close();
} else {
    $_SESSION['message'] = "Invalid request.";
    $_SESSION['messageType'] = "danger";
}

$conn->close();
header("Location: team_leader_dashboard.php");
exit();
?>