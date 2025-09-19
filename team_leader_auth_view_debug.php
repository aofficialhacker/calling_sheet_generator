<?php
/**
 * Debug version of Team Leader Authentication Endpoint
 */

require_once 'db_config.php';
require_once 'masking_utils.php';

// Start output buffering to capture any errors
ob_start();

$debug = [];
$response = ['success' => false, 'message' => '', 'debug' => []];

try {
    $debug[] = "Starting authentication process";
    
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    }
    $debug[] = "Request method OK";
    
    // Start session if needed
    if (session_status() == PHP_SESSION_NONE) {
        require_once __DIR__ . '/session_manager.php';\nSessionManager::start();
        $debug[] = "Session started";
    } else {
        $debug[] = "Session already active";
    }
    
    $debug[] = "Session ID: " . session_id();
    
    // Check team leader status
    $debug[] = "Checking team leader status";
    $debug[] = "is_team_leader set: " . (isset($_SESSION['is_team_leader']) ? 'yes' : 'no');
    $debug[] = "is_team_leader value: " . (isset($_SESSION['is_team_leader']) ? ($_SESSION['is_team_leader'] ? 'true' : 'false') : 'not set');
    
    if (!isTeamLeader()) {
        throw new Exception("Team leader authentication failed");
    }
    $debug[] = "Team leader validation passed";
    
    // Get POST data
    $rawInput = file_get_contents('php://input');
    $debug[] = "Raw input length: " . strlen($rawInput);
    
    if (empty($rawInput)) {
        throw new Exception("No POST data received");
    }
    
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg());
    }
    
    $accessCode = strtoupper(trim($input['access_code'] ?? ''));
    $leadId = trim($input['lead_id'] ?? '');
    
    $debug[] = "Access code length: " . strlen($accessCode);
    $debug[] = "Lead ID: " . $leadId;
    
    if (empty($accessCode) || empty($leadId)) {
        throw new Exception("Access code or lead ID missing");
    }
    
    // Get database connection
    $conn = getDBConnection();
    $debug[] = "Database connection established";
    
    // Check if lead exists and belongs to this admin
    $stmt = $conn->prepare("
        SELECT fcl.id, fcl.name, fcl.mobile_no 
        FROM lv_final_call_logs fcl
        JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
        WHERE fcl.id = ? AND acm.admin_id = ? AND fcl.disposition = 'Interested'
    ");
    $stmt->bind_param("ss", $leadId, $_SESSION['admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        throw new Exception("Lead not found or access denied");
    }
    
    $leadData = $result->fetch_assoc();
    $stmt->close();
    $debug[] = "Lead found: " . $leadData['name'];
    
    // Validate access code
    $debug[] = "Validating access code for leader: " . $_SESSION['leader_id'];
    $isValid = validateTeamLeaderAccessCode($_SESSION['leader_id'], $accessCode, $conn);
    $debug[] = "Access code validation result: " . ($isValid ? 'VALID' : 'INVALID');
    
    if (!$isValid) {
        $conn->close();
        throw new Exception("Invalid access code");
    }
    
    // Success - set unmasked entry and return data
    clearUnmaskedEntry();
    setUnmaskedEntry($leadId);
    
    $response = [
        'success' => true,
        'message' => 'Access granted for 1 minute',
        'data' => [
            'name' => $leadData['name'],
            'mobile' => $leadData['mobile_no'],
            'expires_at' => $_SESSION['unmask_expires_at'],
            'remaining_time' => 60
        ],
        'debug' => $debug
    ];
    
    $conn->close();
    
} catch (Exception $e) {
    $debug[] = "ERROR: " . $e->getMessage();
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $debug
    ];
}

// Capture any output that might have been generated
$output = ob_get_clean();
if (!empty($output)) {
    $debug[] = "Unexpected output: " . $output;
    $response['debug'] = $debug;
}

// Set JSON header and return response
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
?>