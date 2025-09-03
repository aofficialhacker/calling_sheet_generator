<?php
// Simple test to check AJAX notifications endpoint
session_start();
$_SESSION['leader_id'] = 'TL001'; // Set for testing

require_once 'ajax_followup_notifications.php';
?>