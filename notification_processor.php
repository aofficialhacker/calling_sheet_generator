<?php
/**
 * Background notification processor for follow-up reminders
 * This script should be run as a cron job every 5-15 minutes
 * 
 * Cron job example:
 * */5 * * * * /usr/bin/php /path/to/calling_sheet_generator11/notification_processor.php >> /var/log/followup_notifications.log 2>&1
 */

require_once 'db_config.php';

// Log function
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message" . PHP_EOL;
    
    // Log to file if possible
    $logFile = __DIR__ . '/logs/notification_processor.log';
    if (is_writable(dirname($logFile)) || is_writable($logFile)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    // Also output for command line
    echo $logEntry;
}

// Email notification function (placeholder - implement with your email service)
function sendEmailNotification($to, $subject, $message) {
    // This is a placeholder. Implement with your preferred email service
    // Examples: PHPMailer, SendGrid, AWS SES, etc.
    
    logMessage("EMAIL NOTIFICATION (placeholder): To: $to, Subject: $subject");
    return true; // Return true if email sent successfully
}

try {
    logMessage("Starting notification processor");
    
    $conn = getDBConnection();
    
    // Process immediate notifications (due now or overdue)
    $stmt = $conn->prepare("
        SELECT fn.*, fs.*, fcl.name as customer_name, fcl.mobile_no,
               tl.name as leader_name, tl.email as leader_email,
               db.bucket_name
        FROM follow_up_notifications fn
        JOIN follow_up_schedules fs ON fn.schedule_id = fs.id
        JOIN final_call_logs fcl ON fs.lead_id = fcl.id
        JOIN team_leaders tl ON fs.leader_id = tl.id
        JOIN disposition_buckets db ON fs.bucket_id = db.id
        WHERE fn.status = 'pending'
        AND fn.scheduled_time <= NOW()
        AND fs.status = 'scheduled'
        ORDER BY fn.scheduled_time ASC
        LIMIT 50
    ");
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $processedCount = 0;
    $errorCount = 0;
    
    foreach ($notifications as $notification) {
        try {
            $scheduleId = $notification['schedule_id'];
            $notificationId = $notification['id'];
            $customerName = $notification['customer_name'];
            $customerMobile = $notification['customer_mobile'];
            $leaderName = $notification['leader_name'];
            $leaderEmail = $notification['leader_email'];
            $bucketName = $notification['bucket_name'];
            $dispositionName = $notification['disposition_name'];
            $followUpTime = $notification['follow_up_datetime'];
            $notificationType = $notification['notification_type'];
            
            // Prepare notification message
            $timeFormatted = date('d-M-Y H:i', strtotime($followUpTime));
            $subject = "Follow-up Reminder: {$customerName}";
            
            $emailBody = "
Dear {$leaderName},

This is a reminder for your scheduled follow-up:

Customer Details:
- Name: {$customerName}
- Mobile: {$customerMobile}
- Disposition: {$dispositionName}
- Bucket: {$bucketName}
- Scheduled Time: {$timeFormatted}

Please contact the customer as scheduled.

Best regards,
Calling Sheet System
            ";
            
            // Send notification (email)
            $emailSent = false;
            if ($leaderEmail) {
                $emailSent = sendEmailNotification($leaderEmail, $subject, $emailBody);
            }
            
            // Update notification status
            $newStatus = $emailSent ? 'sent' : 'failed';
            $sentAt = $emailSent ? date('Y-m-d H:i:s') : null;
            $nextAttempt = null;
            
            // If failed, schedule next attempt (up to 3 attempts)
            if (!$emailSent && $notification['attempt_count'] < 3) {
                $newStatus = 'pending';
                $nextAttempt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            }
            
            $stmt = $conn->prepare("
                UPDATE follow_up_notifications 
                SET status = ?, sent_at = ?, next_attempt = ?, attempt_count = attempt_count + 1
                WHERE id = ?
            ");
            $stmt->bind_param("sssi", $newStatus, $sentAt, $nextAttempt, $notificationId);
            $stmt->execute();
            $stmt->close();
            
            if ($emailSent) {
                logMessage("Notification sent successfully for schedule ID: $scheduleId");
                $processedCount++;
            } else {
                logMessage("Failed to send notification for schedule ID: $scheduleId", 'WARNING');
                $errorCount++;
            }
            
        } catch (Exception $e) {
            logMessage("Error processing notification ID {$notification['id']}: " . $e->getMessage(), 'ERROR');
            $errorCount++;
            
            // Update notification as failed
            try {
                $stmt = $conn->prepare("
                    UPDATE follow_up_notifications 
                    SET status = 'failed', attempt_count = attempt_count + 1
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $notification['id']);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $updateError) {
                logMessage("Failed to update notification status: " . $updateError->getMessage(), 'ERROR');
            }
        }
    }
    
    // Update overdue follow-ups
    $stmt = $conn->prepare("
        UPDATE follow_up_schedules 
        SET status = 'overdue' 
        WHERE status = 'scheduled' 
        AND follow_up_datetime < NOW() - INTERVAL 1 HOUR
    ");
    $stmt->execute();
    $overdueUpdated = $stmt->affected_rows;
    $stmt->close();
    
    if ($overdueUpdated > 0) {
        logMessage("Updated $overdueUpdated follow-ups to overdue status");
    }
    
    // Clean up old completed notifications (older than 30 days)
    $stmt = $conn->prepare("
        DELETE FROM follow_up_notifications 
        WHERE status IN ('sent', 'failed') 
        AND (sent_at < NOW() - INTERVAL 30 DAY OR created_at < NOW() - INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $cleanedUp = $stmt->affected_rows;
    $stmt->close();
    
    if ($cleanedUp > 0) {
        logMessage("Cleaned up $cleanedUp old notification records");
    }
    
    logMessage("Notification processing completed. Processed: $processedCount, Errors: $errorCount");
    
} catch (Exception $e) {
    logMessage("Fatal error in notification processor: " . $e->getMessage(), 'ERROR');
    exit(1);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

logMessage("Notification processor finished");
?>