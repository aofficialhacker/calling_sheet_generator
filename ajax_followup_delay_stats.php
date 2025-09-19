<?php
require_once 'db_config.php';
requireAdmin();

// Set JSON content type
header('Content-Type: application/json');

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

try {
    // Get real-time statistics for the admin's team leaders
    $stats = [];
    
    // Overall performance metrics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_followups,
            COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN fs.status = 'cancelled' THEN 1 END) as cancelled_count,
            COUNT(CASE WHEN fs.status = 'scheduled' THEN 1 END) as scheduled_count,
            COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_count,
            ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
            COUNT(DISTINCT fs.leader_id) as active_leaders,
            COUNT(CASE WHEN DATE(fs.follow_up_datetime) = CURDATE() AND fs.status = 'scheduled' THEN 1 END) as due_today,
            COUNT(CASE WHEN DATE(fs.follow_up_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND fs.status = 'scheduled' THEN 1 END) as due_tomorrow,
            ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as completion_rate,
            ROUND((COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as overdue_rate
        FROM lv_follow_up_schedules fs
        JOIN lv_team_leaders tl ON fs.leader_id = tl.leader_id
        WHERE tl.admin_id = ?
    ");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $stats['overall'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Team leader individual performance
    $stmt = $conn->prepare("
        SELECT 
            tl.leader_id,
            tl.leader_name,
            COUNT(*) as total_followups,
            COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_followups,
            COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_followups,
            ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
            ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as completion_rate,
            ROUND((COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as overdue_rate,
            COUNT(CASE WHEN DATE(fs.follow_up_datetime) = CURDATE() AND fs.status = 'scheduled' THEN 1 END) as due_today
        FROM lv_team_leaders tl
        LEFT JOIN lv_follow_up_schedules fs ON tl.leader_id = fs.leader_id
        WHERE tl.admin_id = ? AND tl.is_active = 1
        GROUP BY tl.leader_id, tl.leader_name
        ORDER BY overdue_followups DESC, avg_delay_minutes DESC
    ");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $stats['leaders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Bucket-wise performance
    $stmt = $conn->prepare("
        SELECT 
            db.bucket_name,
            COUNT(*) as total_followups,
            COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_followups,
            COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_followups,
            ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
            ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as completion_rate
        FROM lv_disposition_buckets db
        LEFT JOIN lv_follow_up_schedules fs ON db.id = fs.bucket_id
        LEFT JOIN lv_team_leaders tl ON fs.leader_id = tl.leader_id
        WHERE tl.admin_id = ? OR tl.admin_id IS NULL
        GROUP BY db.id, db.bucket_name
        HAVING total_followups > 0
        ORDER BY overdue_followups DESC
    ");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $stats['buckets'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Recent overdue alerts (most critical)
    $stmt = $conn->prepare("
        SELECT 
            fs.schedule_id,
            tl.leader_name,
            fcl.name as customer_name,
            fs.follow_up_datetime,
            TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) as overdue_minutes,
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 60 THEN 'Recently Overdue'
                WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 1440 THEN 'Overdue (< 1 day)'
                WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 10080 THEN 'Overdue (< 1 week)'
                ELSE 'Severely Overdue (> 1 week)'
            END as severity,
            fs.disposition_name,
            db.bucket_name
        FROM lv_follow_up_schedules fs
        JOIN lv_team_leaders tl ON fs.leader_id = tl.leader_id
        JOIN lv_final_call_logs fcl ON fs.lead_id = fcl.id
        JOIN lv_disposition_buckets db ON fs.bucket_id = db.id
        WHERE tl.admin_id = ? AND fs.status = 'scheduled' AND fs.follow_up_datetime < NOW()
        ORDER BY fs.follow_up_datetime ASC
        LIMIT 10
    ");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $stats['urgent_overdue'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Daily trend (last 7 days)
    $stmt = $conn->prepare("
        SELECT 
            DATE(fs.follow_up_datetime) as date,
            COUNT(*) as total_scheduled,
            COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue,
            ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay
        FROM lv_follow_up_schedules fs
        JOIN lv_team_leaders tl ON fs.leader_id = tl.leader_id
        WHERE tl.admin_id = ? 
        AND DATE(fs.follow_up_datetime) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND DATE(fs.follow_up_datetime) <= CURDATE()
        GROUP BY DATE(fs.follow_up_datetime)
        ORDER BY DATE(fs.follow_up_datetime) DESC
    ");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $stats['daily_trend'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $stats['success'] = true;
    $stats['timestamp'] = date('Y-m-d H:i:s');
    
} catch (Exception $e) {
    $stats = [
        'success' => false,
        'error' => 'Failed to fetch statistics: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

$conn->close();

// Return JSON response
echo json_encode($stats);
exit();
?>