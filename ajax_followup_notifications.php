<?php
require_once 'db_config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Enable CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check if user is authenticated as team leader
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isset($_SESSION['leader_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get pending notifications for the team leader
        $action = $_GET['action'] ?? '';
        
        if ($action === 'check_notifications') {
            // Get follow-ups that are overdue or due within 8 hours, excluding read notifications
            $stmt = $conn->prepare("
                SELECT fs.*, 
                       fcl.name as customer_name,
                       fcl.mobile_no as customer_mobile,
                       db.bucket_name,
                       TIMESTAMPDIFF(MINUTE, NOW(), fs.follow_up_datetime) as minutes_until_due
                FROM follow_up_schedules fs
                JOIN final_call_logs fcl ON fs.lead_id = fcl.id
                JOIN disposition_buckets db ON fs.bucket_id = db.id
                LEFT JOIN notification_read_status nrs ON nrs.leader_id = fs.leader_id AND nrs.schedule_id = fs.schedule_id
                WHERE fs.leader_id = ? 
                AND fs.status = 'scheduled'
                AND (fs.follow_up_datetime <= NOW() OR fs.follow_up_datetime <= DATE_ADD(NOW(), INTERVAL 8 HOUR))
                AND nrs.id IS NULL
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
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'count' => count($notifications)
            ]);
            
        } elseif ($action === 'get_summary') {
            // Get summary of today's follow-ups
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_today,
                    COUNT(CASE WHEN follow_up_datetime < NOW() THEN 1 END) as overdue_today,
                    COUNT(CASE WHEN follow_up_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN 1 END) as due_next_hour
                FROM follow_up_schedules 
                WHERE leader_id = ? 
                AND status = 'scheduled'
                AND DATE(follow_up_datetime) = CURDATE()
            ");
            $stmt->bind_param("s", $leaderId);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'summary' => $summary
            ]);
            
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        if ($action === 'quick_update_status') {
            $scheduleId = $input['schedule_id'] ?? '';
            $newStatus = $input['status'] ?? '';
            $remarks = $input['remarks'] ?? '';
            
            if (!in_array($newStatus, ['completed', 'cancelled'])) {
                throw new Exception('Invalid status');
            }
            
            // Update the follow-up status
            $stmt = $conn->prepare("
                UPDATE follow_up_schedules 
                SET status = ?, 
                    remarks = CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] Quick update: ', ?, IF(? != '', CONCAT(' - ', ?), ''))
                WHERE id = ? AND leader_id = ?
            ");
            $stmt->bind_param("ssssss", $newStatus, $newStatus, $remarks, $remarks, $scheduleId, $leaderId);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Follow-up status updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'No follow-up found or unauthorized'
                ]);
            }
            $stmt->close();
            
        } elseif ($action === 'mark_all_read') {
            // Mark all current notifications as read
            $stmt = $conn->prepare("
                INSERT IGNORE INTO notification_read_status (leader_id, schedule_id)
                SELECT fs.leader_id, fs.schedule_id
                FROM follow_up_schedules fs
                LEFT JOIN notification_read_status nrs ON nrs.leader_id = fs.leader_id AND nrs.schedule_id = fs.schedule_id
                WHERE fs.leader_id = ? 
                AND fs.status = 'scheduled'
                AND (fs.follow_up_datetime <= NOW() OR fs.follow_up_datetime <= DATE_ADD(NOW(), INTERVAL 8 HOUR))
                AND nrs.id IS NULL
            ");
            $stmt->bind_param("s", $leaderId);
            $stmt->execute();
            
            $markedCount = $stmt->affected_rows;
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => "Marked $markedCount notifications as read"
            ]);
            
        } elseif ($action === 'snooze_notification') {
            $scheduleId = $input['schedule_id'] ?? '';
            $snoozeMinutes = (int)($input['snooze_minutes'] ?? 15);
            
            // Validate snooze time (5-60 minutes)
            if ($snoozeMinutes < 5 || $snoozeMinutes > 60) {
                $snoozeMinutes = 15;
            }
            
            // Update the follow-up time
            $stmt = $conn->prepare("
                UPDATE follow_up_schedules 
                SET follow_up_datetime = DATE_ADD(follow_up_datetime, INTERVAL ? MINUTE),
                    remarks = CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] Snoozed for ', ?, ' minutes')
                WHERE id = ? AND leader_id = ? AND status = 'scheduled'
            ");
            $stmt->bind_param("iiss", $snoozeMinutes, $snoozeMinutes, $scheduleId, $leaderId);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => "Follow-up snoozed for $snoozeMinutes minutes"
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unable to snooze notification'
                ]);
            }
            $stmt->close();
            
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>