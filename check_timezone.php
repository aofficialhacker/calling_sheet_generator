<?php
require_once 'db_config.php';

echo "<h2>Timezone Debug Information</h2>";

// PHP timezone info
echo "<h3>PHP Settings:</h3>";
echo "PHP Default Timezone: " . date_default_timezone_get() . "<br>";
echo "Current PHP Time: " . date('Y-m-d H:i:s T') . "<br>";
echo "PHP Timestamp: " . time() . "<br>";

// Database timezone info
$conn = getDBConnection();
$result = $conn->query("SELECT @@system_time_zone as system_tz, @@session.time_zone as session_tz, NOW() as db_time, UNIX_TIMESTAMP(NOW()) as db_timestamp");
$dbInfo = $result->fetch_assoc();

echo "<h3>MySQL Settings:</h3>";
echo "MySQL System Timezone: " . $dbInfo['system_tz'] . "<br>";
echo "MySQL Session Timezone: " . $dbInfo['session_tz'] . "<br>";
echo "MySQL NOW(): " . $dbInfo['db_time'] . "<br>";
echo "MySQL Timestamp: " . $dbInfo['db_timestamp'] . "<br>";

echo "<h3>Difference Analysis:</h3>";
$timeDiff = time() - $dbInfo['db_timestamp'];
echo "Time difference (PHP - MySQL): " . $timeDiff . " seconds<br>";
echo "Time difference in hours: " . round($timeDiff / 3600, 2) . " hours<br>";

if ($timeDiff != 0) {
    echo "<div style='color: red;'><strong>⚠️ TIMEZONE MISMATCH DETECTED!</strong></div>";
    echo "This explains why your access codes show " . (4 + round($timeDiff / 3600, 2)) . " hours instead of 4 hours.<br>";
} else {
    echo "<div style='color: green;'><strong>✅ Timezones are synchronized</strong></div>";
}

// Test with a sample team leader
echo "<h3>Sample Access Code Test:</h3>";
$stmt = $conn->prepare("SELECT leader_id, access_code, code_generated_at FROM lv_team_leaders WHERE access_code IS NOT NULL LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();

if ($leader = $result->fetch_assoc()) {
    $generatedTime = strtotime($leader['code_generated_at']);
    $currentTime = time();
    $dbCurrentTime = $dbInfo['db_timestamp'];
    
    echo "Leader ID: " . $leader['leader_id'] . "<br>";
    echo "Code Generated At: " . $leader['code_generated_at'] . "<br>";
    echo "Generated Timestamp (PHP strtotime): " . $generatedTime . "<br>";
    echo "Current Time (PHP): " . $currentTime . "<br>";
    echo "Current Time (MySQL): " . $dbCurrentTime . "<br>";
    
    $agePhp = $currentTime - $generatedTime;
    $remainingPhp = 14400 - $agePhp;
    
    echo "<br><strong>Age calculation (PHP method):</strong><br>";
    echo "Code age: " . $agePhp . " seconds<br>";
    echo "Remaining time: " . $remainingPhp . " seconds<br>";
    echo "Remaining formatted: " . gmdate("H:i:s", max(0, $remainingPhp)) . "<br>";
}

$stmt->close();
$conn->close();

echo "<br><a href='admin_team_leader_codes.php'>Test Access Codes Page</a>";
?>