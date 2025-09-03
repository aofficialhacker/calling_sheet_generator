<?php
session_start();
$_SESSION['leader_id'] = 'TL001';
$_SESSION['is_team_leader'] = true;

// Test mark all read functionality
echo "Testing Mark All Read functionality...\n\n";

// First check current count
echo "1. Current notification count:\n";
$_GET['action'] = 'check_notifications';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include 'ajax_followup_notifications.php';
$response = ob_get_clean();
$data = json_decode($response, true);
echo "Count: " . $data['count'] . "\n\n";

// Mark all as read
echo "2. Marking all notifications as read:\n";
unset($_GET['action']);
$_SERVER['REQUEST_METHOD'] = 'POST';
file_put_contents('php://input', json_encode(['action' => 'mark_all_read']));
ob_start();
include 'ajax_followup_notifications.php';
$response = ob_get_clean();
echo "Response: " . $response . "\n\n";

// Check count again
echo "3. Notification count after mark all read:\n";
$_GET['action'] = 'check_notifications';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include 'ajax_followup_notifications.php';
$response = ob_get_clean();
$data = json_decode($response, true);
echo "Count: " . $data['count'] . "\n";
echo "Success!\n";
?>