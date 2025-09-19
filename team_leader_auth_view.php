<?php
/**
 * Relationship Manager Authentication Endpoint for View Action
 * Validates access code and grants temporary access to unmask customer data
 */

require_once 'db_config.php';
require_once 'masking_utils.php';

// Ensure this is called via AJAX POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Use the same team leader validation as other pages
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isTeamLeader()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$accessCode = strtoupper(trim($input['access_code'] ?? ''));
$leadId = trim($input['lead_id'] ?? '');

$response = ['success' => false, 'message' => ''];

// Validate input
if (empty($accessCode) || empty($leadId)) {
    $response['message'] = 'Access code and lead ID are required';
    echo json_encode($response);
    exit();
}

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];

try {
    // Ensure view logs table exists
    ensureViewLogsTable($conn);
    
    // Log the view request
    logViewAction($leaderId, $leadId, 'view_request', $conn);
    
    // Check for rate limiting - max 10 view requests per 5 minutes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as request_count 
        FROM lv_team_leader_view_logs 
        WHERE leader_id = ? 
        AND action = 'view_request' 
        AND timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->bind_param("s", $leaderId);
    $stmt->execute();
    $requestCount = $stmt->get_result()->fetch_assoc()['request_count'];
    $stmt->close();
    
    if ($requestCount > 10) {
        $response['message'] = 'Too many view requests. Please wait before trying again.';
        echo json_encode($response);
        exit();
    }
    
    // Verify the lead exists and belongs to this team leader's admin
    $stmt = $conn->prepare("
        SELECT fcl.id, fcl.name, fcl.mobile_no 
        FROM lv_final_call_logs fcl
        JOIN lv_admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
        WHERE fcl.id = ? AND acm.admin_id = ? AND fcl.disposition = 'Interested'
    ");
    $stmt->bind_param("ss", $leadId, $_SESSION['admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $response['message'] = 'Invalid lead or access denied';
        echo json_encode($response);
        exit();
    }
    
    $leadData = $result->fetch_assoc();
    $stmt->close();
    
    // Validate access code using existing function
    if (validateTeamLeaderAccessCode($leaderId, $accessCode, $conn)) {
        // Clear any existing unmasked entry (only one at a time)
        clearUnmaskedEntry();
        
        // Set this lead as unmasked for 1 minute
        setUnmaskedEntry($leadId);
        
        // Log successful view grant
        logViewAction($leaderId, $leadId, 'view_granted', $conn);
        
        $response = [
            'success' => true,
            'message' => 'Access granted for 1 minute',
            'data' => [
                'name' => $leadData['name'],
                'mobile' => $leadData['mobile_no'],
                'expires_at' => $_SESSION['unmask_expires_at'],
                'remaining_time' => 60
            ]
        ];
    } else {
        // Log failed authentication
        logViewAction($leaderId, $leadId, 'auth_failed', $conn);
        $response['message'] = 'Invalid access code. Please contact your admin.';
    }
    
} catch (Exception $e) {
    error_log("Relationship Manager Auth View Error: " . $e->getMessage());
    $response['message'] = 'An error occurred. Please try again.';
}

$conn->close();

// Set JSON header and return response
header('Content-Type: application/json');
echo json_encode($response);
?>