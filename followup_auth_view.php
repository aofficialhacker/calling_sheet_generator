<?php
require_once 'db_config.php';
requireTeamLeader();

header('Content-Type: application/json');

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$adminId = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accessCode = trim($_POST['access_code'] ?? '');
    $followUpId = trim($_POST['follow_up_id'] ?? '');
    
    if (empty($accessCode) || empty($followUpId)) {
        echo json_encode(['success' => false, 'message' => 'Missing access code or follow-up ID']);
        exit;
    }
    
    try {
        // Get team leader data for verification
        $stmt = $conn->prepare("
            SELECT tl.*, 
                   TIMESTAMPDIFF(HOUR, tl.code_generated_at, NOW()) as hours_since_generation
            FROM lv_team_leaders tl 
            WHERE tl.id = ? AND tl.admin_id = ?
        ");
        $stmt->bind_param("ss", $leaderId, $adminId);
        $stmt->execute();
        $leaderData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$leaderData) {
            echo json_encode(['success' => false, 'message' => 'Team leader not found']);
            exit;
        }
        
        // Check if code is expired (refresh every 4 hours)
        if ($leaderData['hours_since_generation'] >= 4) {
            echo json_encode(['success' => false, 'message' => 'Access code expired. Contact admin for new code.']);
            exit;
        }
        
        // Verify access code
        if (strtoupper($accessCode) !== strtoupper($leaderData['access_code'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid access code']);
            exit;
        }
        
        // Get follow-up details and verify it belongs to this team leader
        $stmt = $conn->prepare("
            SELECT fs.*, fcl.name as customer_name, fcl.mobile_no as customer_mobile
            FROM lv_follow_up_schedules fs
            JOIN lv_final_call_logs fcl ON fs.lead_id = fcl.id
            WHERE fs.id = ? AND fs.leader_id = ?
        ");
        $stmt->bind_param("is", $followUpId, $leaderId);
        $stmt->execute();
        $followUpData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$followUpData) {
            echo json_encode(['success' => false, 'message' => 'Follow-up not found or access denied']);
            exit;
        }
        
        // Log the view action
        $viewLogId = 'FUV' . date('YmdHis') . substr(uniqid(), -4);
        $ipAddress = getRealIpAddress();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $conn->prepare("
            INSERT INTO lv_team_leader_view_logs 
            (log_id, leader_id, lead_id, follow_up_id, customer_name, mobile_number, view_timestamp, ip_address, user_agent, session_id)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->bind_param("sssssssss", 
            $viewLogId, $leaderId, $followUpData['lead_id'], $followUpId,
            $followUpData['customer_name'], $followUpData['customer_mobile'], 
            $ipAddress, $userAgent, session_id()
        );
        $stmt->execute();
        $stmt->close();
        
        // Return unmasked data
        echo json_encode([
            'success' => true,
            'customer_name' => $followUpData['customer_name'],
            'customer_mobile' => $followUpData['customer_mobile'],
            'follow_up_id' => $followUpId
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

$conn->close();
?>