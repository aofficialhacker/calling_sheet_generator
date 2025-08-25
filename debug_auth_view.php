<?php
/**
 * Debug version of team leader auth view
 */

session_start();
require_once 'db_config.php';
require_once 'masking_utils.php';

// Debug information
echo "Debug Information:\n";
echo "Session ID: " . session_id() . "\n";
echo "is_team_leader: " . (isset($_SESSION['is_team_leader']) ? ($_SESSION['is_team_leader'] ? 'true' : 'false') : 'not set') . "\n";
echo "leader_id: " . ($_SESSION['leader_id'] ?? 'not set') . "\n";
echo "admin_id: " . ($_SESSION['admin_id'] ?? 'not set') . "\n";
echo "Request method: " . $_SERVER['REQUEST_METHOD'] . "\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    echo "Raw input: " . $rawInput . "\n";
    
    $input = json_decode($rawInput, true);
    echo "Decoded input: " . print_r($input, true) . "\n";
    
    if ($input) {
        $accessCode = strtoupper(trim($input['access_code'] ?? ''));
        $leadId = trim($input['lead_id'] ?? '');
        
        echo "Access Code: '" . $accessCode . "'\n";
        echo "Lead ID: '" . $leadId . "'\n";
        
        if (isset($_SESSION['leader_id']) && isset($_SESSION['admin_id'])) {
            $conn = getDBConnection();
            
            // Test the validation function
            $isValid = validateTeamLeaderAccessCode($_SESSION['leader_id'], $accessCode, $conn);
            echo "Access code validation result: " . ($isValid ? 'VALID' : 'INVALID') . "\n";
            
            $conn->close();
        }
    }
}
?>