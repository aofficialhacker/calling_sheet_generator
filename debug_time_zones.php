<?php
require_once 'db_config.php';
requireAdmin();

echo "<h3>Time Zone Debug Information</h3>";
echo "<strong>Current Server Time:</strong><br>";
echo "PHP time(): " . time() . " (" . date('Y-m-d H:i:s', time()) . ")<br>";
echo "PHP date(): " . date('Y-m-d H:i:s') . "<br>";
echo "PHP timezone: " . date_default_timezone_get() . "<br><br>";

$conn = getDBConnection();

// Get database time
$result = $conn->query("SELECT NOW() as db_time, UNIX_TIMESTAMP(NOW()) as db_timestamp");
$dbTime = $result->fetch_assoc();
echo "<strong>Database Time:</strong><br>";
echo "DB NOW(): " . $dbTime['db_time'] . "<br>";
echo "DB UNIX_TIMESTAMP: " . $dbTime['db_timestamp'] . "<br>";
echo "Time difference: " . (time() - $dbTime['db_timestamp']) . " seconds<br><br>";

// Check a specific team leader's code
$adminId = $_SESSION['admin_id'];
$stmt = $conn->prepare("
    SELECT leader_id, leader_name, access_code, code_generated_at, 
           UNIX_TIMESTAMP(code_generated_at) as generated_timestamp,
           UNIX_TIMESTAMP(NOW()) as current_timestamp,
           (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(code_generated_at)) as age_seconds,
           (14400 - (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(code_generated_at))) as remaining_seconds
    FROM team_leaders 
    WHERE admin_id = ? AND is_active = 1 
    LIMIT 1
");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();

if ($leader = $result->fetch_assoc()) {
    echo "<strong>Sample Team Leader: " . $leader['leader_name'] . "</strong><br>";
    echo "Code Generated At: " . $leader['code_generated_at'] . "<br>";
    echo "Generated Timestamp: " . $leader['generated_timestamp'] . "<br>";
    echo "Current DB Timestamp: " . $leader['current_timestamp'] . "<br>";
    echo "Age in seconds: " . $leader['age_seconds'] . "<br>";
    echo "Remaining seconds (DB calc): " . $leader['remaining_seconds'] . "<br>";
    echo "Remaining seconds (PHP calc): " . (strtotime($leader['code_generated_at']) + 14400 - time()) . "<br>";
    
    // Test the refreshTeamLeaderCode function
    echo "<br><strong>refreshTeamLeaderCode result:</strong><br>";
    $codeInfo = refreshTeamLeaderCode($leader['leader_id'], $conn);
    echo "Code: " . $codeInfo['code'] . "<br>";
    echo "Expires at: " . $codeInfo['expires_at'] . "<br>";
    
    if ($codeInfo['expires_at']) {
        $expiryTime = strtotime($codeInfo['expires_at']);
        $timeRemaining = $expiryTime - time();
        echo "Expiry timestamp: " . $expiryTime . "<br>";
        echo "Current timestamp: " . time() . "<br>";
        echo "Time remaining: " . $timeRemaining . " seconds<br>";
        echo "Formatted: " . gmdate("H:i:s", max(0, $timeRemaining)) . "<br>";
    }
}

$stmt->close();
$conn->close();

echo "<br><a href='admin_team_leader_codes.php'>Back to Access Codes</a>";
?>