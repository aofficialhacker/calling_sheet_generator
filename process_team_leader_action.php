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
    $followUpDate = isset($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
    $followUpTime = isset($_POST['follow_up_time']) ? $_POST['follow_up_time'] : null;
    
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
            // Check if disposition requires calendar scheduling
            $stmt = $conn->prepare("
                SELECT tld.*, db.has_calendar_enabled, db.id as bucket_id
                FROM team_leader_dispositions tld
                LEFT JOIN disposition_buckets db ON tld.bucket_id = db.id
                WHERE tld.disposition_name = ? AND tld.is_active = 1
            ");
            $stmt->bind_param("s", $newDisposition);
            $stmt->execute();
            $dispositionData = $stmt->get_result()->fetch_assoc();
            
            // Validate follow-up scheduling if required
            if ($dispositionData['has_calendar_enabled']) {
                if (!$followUpDate || !$followUpTime) {
                    $_SESSION['message'] = "Follow-up date and time are required for this disposition.";
                    $_SESSION['messageType'] = "danger";
                    $stmt->close();
                    $conn->close();
                    header("Location: team_leader_dashboard.php");
                    exit();
                }
                
                // Validate future datetime (must be at least 1 minute in the future)
                $followUpDatetime = $followUpDate . ' ' . $followUpTime . ':00';
                if (strtotime($followUpDatetime) <= (time() + 60)) {
                    $_SESSION['message'] = "Follow-up time must be at least 1 minute in the future.";
                    $_SESSION['messageType'] = "danger";
                    $stmt->close();
                    $conn->close();
                    header("Location: team_leader_dashboard.php");
                    exit();
                }
            }
            
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Generate unique action ID
                $actionId = 'TLA' . date('YmdHis') . substr(uniqid(), -4);
                
                // Insert team leader action
                $stmt = $conn->prepare("
                    INSERT INTO team_leader_actions 
                    (action_id, leader_id, lead_id, original_disposition, new_disposition, remarks, ip_address, user_agent, session_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssssssss", $actionId, $leaderId, $leadId, $leadData['original_disposition'], $newDisposition, $remarks, $ipAddress, $userAgent, $sessionId);
                $stmt->execute();
                
                // If calendar is enabled, create follow-up schedule
                if ($dispositionData['has_calendar_enabled'] && $followUpDate && $followUpTime) {
                    $scheduleId = 'FUS' . date('YmdHis') . substr(uniqid(), -4);
                    $followUpDatetime = $followUpDate . ' ' . $followUpTime . ':00';
                    
                    $stmt = $conn->prepare("
                        INSERT INTO follow_up_schedules 
                        (schedule_id, lead_id, leader_id, disposition_name, bucket_id, follow_up_datetime, remarks) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("sssssss", $scheduleId, $leadId, $leaderId, $newDisposition, $dispositionData['bucket_id'], $followUpDatetime, $remarks);
                    $stmt->execute();
                }
                
                // Commit transaction
                $conn->commit();
                
                $message = "Action recorded successfully!";
                if ($dispositionData['has_calendar_enabled'] && $followUpDate && $followUpTime) {
                    $message .= " Follow-up scheduled for " . date('d-M-Y H:i', strtotime($followUpDatetime)) . ".";
                }
                
                $_SESSION['message'] = $message;
                $_SESSION['messageType'] = "success";
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $_SESSION['message'] = "Error recording action: " . $e->getMessage();
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