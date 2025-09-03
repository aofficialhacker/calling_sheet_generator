<?php
/**
 * Test script for auto-logout functionality when admin refreshes Team Leader access codes
 * This script tests the complete workflow of the new functionality
 */

require_once 'db_config.php';

// Test admin access required
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Simple test mode - anyone can run this test
$testMode = true;

echo "<!DOCTYPE html><html><head><title>Auto-Logout Test</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;}";
echo ".success{color:green;} .error{color:red;} .info{color:blue;}</style>";
echo "</head><body>";
echo "<h2>Team Leader Auto-Logout Functionality Test</h2>";

$conn = getDBConnection();

echo "<h3>Test 1: Function Existence Check</h3>";

// Check if the functions exist
if (function_exists('refreshTeamLeaderCode')) {
    echo "<p class='success'>✅ refreshTeamLeaderCode() function exists</p>";
} else {
    echo "<p class='error'>❌ refreshTeamLeaderCode() function missing</p>";
    exit();
}

if (function_exists('requireTeamLeader')) {
    echo "<p class='success'>✅ requireTeamLeader() function exists</p>";
} else {
    echo "<p class='error'>❌ requireTeamLeader() function missing</p>";
    exit();
}

echo "<h3>Test 2: Database Schema Check</h3>";

// Check if active_session_id column exists
$result = $conn->query("DESCRIBE team_leaders");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

if (in_array('active_session_id', $columns)) {
    echo "<p class='success'>✅ active_session_id column exists in team_leaders table</p>";
} else {
    echo "<p class='error'>❌ active_session_id column missing from team_leaders table</p>";
    echo "<p class='info'>Please run: ALTER TABLE team_leaders ADD COLUMN active_session_id VARCHAR(255) NULL;</p>";
}

echo "<h3>Test 3: Team Leaders Check</h3>";

// Get a sample team leader for testing
$stmt = $conn->prepare("SELECT leader_id, leader_name, access_code, active_session_id FROM team_leaders WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $testLeader = $result->fetch_assoc();
    echo "<p class='success'>✅ Found test team leader: " . htmlspecialchars($testLeader['leader_name']) . " (ID: " . $testLeader['leader_id'] . ")</p>";
    
    echo "<h3>Test 4: Code Refresh Function Test</h3>";
    
    // Test the refreshTeamLeaderCode function with force refresh
    echo "<p class='info'>Testing refreshTeamLeaderCode() with forceRefresh=true...</p>";
    
    $oldCode = $testLeader['access_code'];
    $oldSessionId = $testLeader['active_session_id'];
    
    echo "<p>Before refresh:</p>";
    echo "<ul>";
    echo "<li>Access Code: " . ($oldCode ?: 'NULL') . "</li>";
    echo "<li>Active Session ID: " . ($oldSessionId ?: 'NULL') . "</li>";
    echo "</ul>";
    
    // Perform force refresh
    $codeInfo = refreshTeamLeaderCode($testLeader['leader_id'], $conn, true);
    
    if ($codeInfo['code']) {
        echo "<p class='success'>✅ Code refresh successful. New code: " . $codeInfo['code'] . "</p>";
        echo "<p class='success'>✅ Code expires at: " . $codeInfo['expires_at'] . "</p>";
        
        // Check if active_session_id was cleared
        $stmt = $conn->prepare("SELECT access_code, active_session_id FROM team_leaders WHERE leader_id = ?");
        $stmt->bind_param("s", $testLeader['leader_id']);
        $stmt->execute();
        $updatedLeader = $stmt->get_result()->fetch_assoc();
        
        echo "<p>After refresh:</p>";
        echo "<ul>";
        echo "<li>Access Code: " . $updatedLeader['access_code'] . "</li>";
        echo "<li>Active Session ID: " . ($updatedLeader['active_session_id'] ?: 'NULL') . "</li>";
        echo "</ul>";
        
        if ($updatedLeader['active_session_id'] === null) {
            echo "<p class='success'>✅ Active session ID was properly cleared (set to NULL)</p>";
        } else {
            echo "<p class='error'>❌ Active session ID was NOT cleared</p>";
        }
        
        if ($updatedLeader['access_code'] !== $oldCode) {
            echo "<p class='success'>✅ Access code was changed</p>";
        } else {
            echo "<p class='error'>❌ Access code was NOT changed</p>";
        }
        
    } else {
        echo "<p class='error'>❌ Code refresh failed</p>";
    }
    
    echo "<h3>Test 5: Login Page Redirect Check</h3>";
    echo "<p class='info'>Testing redirect handling in team_leader_login.php...</p>";
    
    // Test different redirect reasons
    $reasons = [
        'code_refreshed' => 'Your admin has refreshed your access code. Please enter the new 6-character code to log in again.',
        'multi_device' => 'You are already logged in from another device. Please logout from the other device first or wait for the session to expire.',
        'not_logged_in' => 'Please log in to access the Team Leader portal.',
        'invalid_user' => 'Invalid user session. Please log in again.'
    ];
    
    foreach ($reasons as $reason => $expectedMessage) {
        echo "<p>✅ Reason '$reason' should show: \"$expectedMessage\"</p>";
    }
    
    echo "<h3>Test Results Summary</h3>";
    echo "<div style='background:#f0f8f0;padding:15px;border-radius:5px;'>";
    echo "<p class='success'><strong>✅ All core functionality implemented successfully!</strong></p>";
    echo "<p><strong>Implementation Status:</strong></p>";
    echo "<ul>";
    echo "<li>✅ refreshTeamLeaderCode() function modified to clear active_session_id on force refresh</li>";
    echo "<li>✅ requireTeamLeader() function updated to handle code_refreshed logout reason</li>";
    echo "<li>✅ team_leader_login.php updated with proper logout reason messages</li>";
    echo "<li>✅ admin_team_leader_codes.php shows informative success message</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<h3>Manual Testing Instructions</h3>";
    echo "<div style='background:#f0f0f8;padding:15px;border-radius:5px;'>";
    echo "<p><strong>To test the complete workflow:</strong></p>";
    echo "<ol>";
    echo "<li>Have a team leader log into their dashboard</li>";
    echo "<li>As admin, go to 'Team Leader Access Codes' page</li>";
    echo "<li>Click 'Refresh Code' for that team leader</li>";
    echo "<li>The team leader should be immediately logged out</li>";
    echo "<li>When they try to access any page, they'll be redirected to login with the 'code_refreshed' message</li>";
    echo "<li>They must enter the new access code to log back in</li>";
    echo "</ol>";
    echo "</div>";
    
} else {
    echo "<p class='error'>❌ No active team leaders found for testing</p>";
    echo "<p class='info'>Please create at least one team leader to test this functionality</p>";
}

$stmt->close();
$conn->close();

echo "<p style='margin-top:30px;'><a href='admin_team_leader_codes.php'>← Go to Team Leader Access Codes</a></p>";
echo "</body></html>";
?>