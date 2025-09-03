<?php
// Test notification system directly
session_start();
$_SESSION['leader_id'] = 'TL001';
$_SESSION['is_team_leader'] = true;

require_once 'db_config.php';

$conn = getDBConnection();
$leaderId = 'TL001';

echo "Testing notification system for Team Leader: $leaderId\n\n";

// Test the same query from ajax_followup_notifications.php
echo "Step 1: Check all follow-ups for leader...\n";
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM follow_up_schedules WHERE leader_id = ? AND status = 'scheduled'");
$stmt->bind_param("s", $leaderId);
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc()['count'];
echo "Found $count scheduled follow-ups for leader $leaderId\n\n";
$stmt->close();

echo "Step 2: Check time condition...\n";
$stmt = $conn->prepare("
    SELECT fs.id, fs.follow_up_datetime, 
           TIMESTAMPDIFF(MINUTE, NOW(), fs.follow_up_datetime) as minutes_until_due,
           CASE WHEN fs.follow_up_datetime <= DATE_ADD(NOW(), INTERVAL 15 MINUTE) THEN 'YES' ELSE 'NO' END as within_15min
    FROM follow_up_schedules fs
    WHERE fs.leader_id = ? AND fs.status = 'scheduled'
    ORDER BY fs.follow_up_datetime
");
$stmt->bind_param("s", $leaderId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']}, Time: {$row['follow_up_datetime']}, Minutes: {$row['minutes_until_due']}, Within 15min: {$row['within_15min']}\n";
}
$stmt->close();

echo "\nStep 3: Full query with JOINs (overdue or due within 1 hour)...\n";
$stmt = $conn->prepare("
    SELECT fs.*, 
           fcl.name as customer_name,
           fcl.mobile_no as customer_mobile,
           db.bucket_name,
           TIMESTAMPDIFF(MINUTE, NOW(), fs.follow_up_datetime) as minutes_until_due
    FROM follow_up_schedules fs
    JOIN final_call_logs fcl ON fs.lead_id = fcl.id
    JOIN disposition_buckets db ON fs.bucket_id = db.id
    WHERE fs.leader_id = ? 
    AND fs.status = 'scheduled'
    AND (fs.follow_up_datetime <= NOW() OR fs.follow_up_datetime <= DATE_ADD(NOW(), INTERVAL 4 HOUR))
    ORDER BY fs.follow_up_datetime ASC
");

$stmt->bind_param("s", $leaderId);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $minutesUntil = (int)$row['minutes_until_due'];
    $urgency = 'low';
    
    if ($minutesUntil <= 0) {
        $urgency = 'critical'; // Overdue
    } elseif ($minutesUntil <= 15) {
        $urgency = 'high'; // Due in 15 minutes
    } elseif ($minutesUntil <= 60) {
        $urgency = 'medium'; // Due in 1 hour
    }
    
    $row['urgency'] = $urgency;
    $row['display_time'] = date('H:i', strtotime($row['follow_up_datetime']));
    $row['display_date'] = date('d-M-Y', strtotime($row['follow_up_datetime']));
    
    $notifications[] = $row;
}
$stmt->close();

echo "Found " . count($notifications) . " notifications:\n\n";

foreach ($notifications as $notification) {
    echo "- Customer: " . $notification['customer_name'] . "\n";
    echo "  Scheduled: " . $notification['follow_up_datetime'] . "\n";
    echo "  Minutes until due: " . $notification['minutes_until_due'] . "\n";
    echo "  Urgency: " . $notification['urgency'] . "\n";
    echo "  Disposition: " . $notification['disposition_name'] . "\n\n";
}

echo "JSON Output:\n";
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications)
], JSON_PRETTY_PRINT);

$conn->close();
?>