<?php
session_start();
echo "<h2>Session Debug</h2>";

// Check if we're simulating a team leader session
if (!isset($_SESSION['leader_id'])) {
    echo "<div style='background: #ffe6e6; padding: 10px; border: 1px solid #red; margin: 10px 0;'>";
    echo "<strong>⚠️ WARNING:</strong> No team leader session found!<br>";
    echo "Setting test session: leader_id = TL001<br>";
    $_SESSION['leader_id'] = 'TL001';
    $_SESSION['is_team_leader'] = true;
    $_SESSION['leader_name'] = 'Test Leader';
    $_SESSION['admin_id'] = 'ADMIN001';
    echo "</div>";
}

echo "<div style='background: #e6ffe6; padding: 10px; border: 1px solid #green; margin: 10px 0;'>";
echo "<strong>✅ Session Info:</strong><br>";
echo "Leader ID: " . ($_SESSION['leader_id'] ?? 'Not set') . "<br>";
echo "Is Team Leader: " . (($_SESSION['is_team_leader'] ?? false) ? 'Yes' : 'No') . "<br>";
echo "Session ID: " . session_id() . "<br>";
echo "</div>";

// Test the AJAX endpoint with this session
echo "<h3>Testing AJAX Endpoint with Current Session</h3>";

// Simulate the AJAX call
$_GET['action'] = 'check_notifications';
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include 'ajax_followup_notifications.php';
$response = ob_get_clean();

echo "<div style='background: #f0f0f0; padding: 10px; border: 1px solid #ccc; font-family: monospace;'>";
if ($response) {
    $data = json_decode($response, true);
    if ($data) {
        echo "<strong>AJAX Response:</strong><br>";
        echo "Success: " . ($data['success'] ? 'true' : 'false') . "<br>";
        echo "Count: " . ($data['count'] ?? 'N/A') . "<br>";
        echo "Message: " . ($data['message'] ?? 'N/A') . "<br>";
        
        if (isset($data['notifications']) && is_array($data['notifications'])) {
            echo "Notifications:<br>";
            foreach ($data['notifications'] as $i => $notif) {
                echo "  " . ($i + 1) . ". " . ($notif['customer_name'] ?? 'Unknown') . 
                     " - " . ($notif['urgency'] ?? 'unknown') . 
                     " - " . ($notif['display_time'] ?? 'no time') . "<br>";
            }
        }
    } else {
        echo "<strong>Raw Response:</strong><br>" . htmlspecialchars($response);
    }
} else {
    echo "<strong>No response received</strong>";
}
echo "</div>";

echo "<h3>Direct URL Test</h3>";
echo "<p><a href='ajax_followup_notifications.php?action=check_notifications' target='_blank'>Test AJAX endpoint directly</a></p>";

echo "<h3>Test Mark All Read</h3>";
echo "<button onclick='testMarkAllRead()' style='padding: 10px; background: #007bff; color: white; border: none; border-radius: 5px;'>Test Mark All Read</button>";
echo "<div id='markReadResult' style='margin-top: 10px;'></div>";

?>

<script>
function testMarkAllRead() {
    const resultDiv = document.getElementById('markReadResult');
    resultDiv.innerHTML = 'Testing mark all read...';
    
    fetch('ajax_followup_notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'mark_all_read'
        })
    })
    .then(response => response.json())
    .then(data => {
        resultDiv.innerHTML = `
            <div style='background: ${data.success ? '#e6ffe6' : '#ffe6e6'}; padding: 10px; border: 1px solid ${data.success ? 'green' : 'red'}; margin: 10px 0;'>
                <strong>Mark All Read Result:</strong><br>
                Success: ${data.success}<br>
                Message: ${data.message || 'N/A'}
            </div>
        `;
        
        // Test notification count after marking as read
        setTimeout(() => {
            fetch('ajax_followup_notifications.php?action=check_notifications')
                .then(response => response.json())
                .then(data => {
                    resultDiv.innerHTML += `
                        <div style='background: #f0f0f0; padding: 10px; border: 1px solid #ccc; margin: 10px 0;'>
                            <strong>Count after mark all read:</strong> ${data.count || 'N/A'}
                        </div>
                    `;
                });
        }, 500);
    })
    .catch(error => {
        resultDiv.innerHTML = `
            <div style='background: #ffe6e6; padding: 10px; border: 1px solid red; margin: 10px 0;'>
                <strong>Error:</strong> ${error.message}
            </div>
        `;
    });
}
</script>