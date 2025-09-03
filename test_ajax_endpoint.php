<?php
// Test AJAX endpoint directly
session_start();
$_SESSION['leader_id'] = 'TL001';
$_SESSION['is_team_leader'] = true;

// Simulate GET request with action parameter
$_GET['action'] = 'check_notifications';
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "Testing AJAX endpoint...\n\n";

// Include the AJAX file
ob_start();
include 'ajax_followup_notifications.php';
$response = ob_get_clean();

echo "Response:\n";
echo $response;
?>