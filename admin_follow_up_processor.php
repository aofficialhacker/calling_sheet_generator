<?php
/**
 * Admin Follow-up Processor
 * Creates follow-up schedules and notifications for admin when telecaller data becomes due
 * Run this as a cron job every hour or integrate into existing notification system
 */

require_once 'db_config.php';

// Log function
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] ADMIN FOLLOW-UP: $message" . PHP_EOL;
    
    // Log to file if possible
    $logFile = __DIR__ . '/logs/admin_followup_processor.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also output for command line
    echo $logEntry;
}

try {
    logMessage("Starting admin follow-up processor");
    
    $conn = getDBConnection();
    
    // Find follow-ups that are due today but haven't been processed into schedules yet
    $stmt = $conn->prepare("
        SELECT 
            fcl.id as log_id,
            fcl.follow_day,
            fcl.follow_slot,
            fcl.processed_at,
            fcl.disposition,
            fcl.name as customer_name,
            fcl.mobile_no,
            fcl.finqy_id,
            fb.admin_id,
            fb.original_filename as batch_name,
            DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) as follow_up_date,
            au.username as admin_name
        FROM final_call_logs fcl
        JOIN file_batches fb ON fcl.batch_id = fb.id
        JOIN admin_users au ON fb.admin_id = au.admin_id
        LEFT JOIN follow_up_schedules fs ON fcl.id = fs.lead_id
        WHERE fcl.follow_day IS NOT NULL 
        AND fcl.follow_day > 0
        AND fcl.processed_at IS NOT NULL
        AND fcl.disposition IS NOT NULL
        AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE()
        AND fs.lead_id IS NULL  -- Not already in follow_up_schedules
        ORDER BY fb.admin_id, fcl.processed_at
    ");
    
    if (!$stmt) {
        logMessage("Failed to prepare query: " . $conn->error, 'ERROR');
        exit(1);
    }
    
    $stmt->execute();
    $results = $stmt->get_result();
    $followUps = $results->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $processedCount = 0;
    $notificationCount = 0;
    
    foreach ($followUps as $followUp) {
        try {
            $logId = $followUp['log_id'];
            $adminId = $followUp['admin_id'];
            $followUpDate = $followUp['follow_up_date'];
            $followSlot = $followUp['follow_slot'] ?? 1;
            $customerName = $followUp['customer_name'];
            $adminEmail = $adminId . '@company.com'; // Default email format
            $batchName = $followUp['batch_name'];
            
            // Calculate follow-up datetime (default to 9 AM if no slot specified)
            $slotTimes = [
                1 => '09:00:00',
                2 => '11:00:00', 
                3 => '14:00:00',
                4 => '16:00:00'
            ];
            $followUpTime = $slotTimes[$followSlot] ?? '09:00:00';
            $followUpDatetime = $followUpDate . ' ' . $followUpTime;
            
            // Create follow-up schedule record for admin tracking
            $scheduleId = 'AFS' . date('YmdHis') . substr(uniqid(), -4);
            
            // Create follow-up schedule record for admin tracking
            $stmt = $conn->prepare("
                INSERT INTO follow_up_schedules (
                    schedule_id, lead_id, leader_id, disposition_name, bucket_id,
                    follow_up_datetime, status, remarks
                ) VALUES (?, ?, ?, ?, 1, ?, 'scheduled', 'Auto-created from telecaller follow-up data')
            ");
            
            $stmt->bind_param("sssss", 
                $scheduleId, 
                $logId, 
                $adminId,
                $followUp['disposition'],
                $followUpDatetime
            );
            
            if ($stmt->execute()) {
                logMessage("Created follow-up schedule $scheduleId for admin $adminId, lead: {$customerName}");
                $processedCount++;
                
                // Create notification for admin
                $notificationId = 'AFN' . date('YmdHis') . substr(uniqid(), -4);
                $notificationTime = date('Y-m-d H:i:s', strtotime($followUpDatetime . ' -30 minutes')); // 30 minutes before
                
                $stmt2 = $conn->prepare("
                    INSERT INTO follow_up_notifications (
                        id, schedule_id, notification_type, scheduled_time, 
                        status, recipient_email, subject, message
                    ) VALUES (?, ?, 'admin_follow_up', ?, 'pending', ?, ?, ?)
                ");
                
                $subject = "Follow-up Due: {$customerName} ({$batchName})";
                $message = "Follow-up scheduled for {$followUpDatetime}\nCustomer: {$customerName}\nMobile: {$followUp['mobile_no']}\nOriginal Disposition: {$followUp['disposition']}\nBatch: {$batchName}";
                
                $stmt2->bind_param("ssssss", 
                    $notificationId,
                    $scheduleId, 
                    $notificationTime,
                    $adminEmail,
                    $subject,
                    $message
                );
                
                if ($stmt2->execute()) {
                    logMessage("Created notification $notificationId for admin follow-up");
                    $notificationCount++;
                } else {
                    logMessage("Failed to create notification: " . $stmt2->error, 'ERROR');
                }
                $stmt2->close();
                
            } else {
                logMessage("Failed to create follow-up schedule: " . $stmt->error, 'ERROR');
            }
            $stmt->close();
            
        } catch (Exception $e) {
            logMessage("Error processing follow-up for log ID {$followUp['log_id']}: " . $e->getMessage(), 'ERROR');
        }
    }
    
    logMessage("Admin follow-up processing completed. Processed: $processedCount schedules, Created: $notificationCount notifications");
    
    // Also check for overdue follow-ups that haven't been actioned
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as overdue_count,
            fb.admin_id,
            au.username as admin_name
        FROM final_call_logs fcl
        JOIN file_batches fb ON fcl.batch_id = fb.id
        JOIN admin_users au ON fb.admin_id = au.admin_id
        WHERE fcl.follow_day IS NOT NULL 
        AND fcl.follow_day > 0
        AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE()
        AND fcl.disposition NOT IN ('Completed', 'Closed', 'Cancelled')
        GROUP BY fb.admin_id
        HAVING overdue_count > 0
    ");
    
    if (!$stmt) {
        logMessage("Failed to prepare overdue query: " . $conn->error, 'ERROR');
    } else {
        $stmt->execute();
        $overdueResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        foreach ($overdueResults as $overdue) {
            logMessage("ALERT: Admin {$overdue['admin_name']} has {$overdue['overdue_count']} overdue follow-ups", 'WARNING');
            
            // Could send email alert here if needed
            // sendOverdueAlert($overdue['admin_email'], $overdue['overdue_count']);
        }
    }
    
} catch (Exception $e) {
    logMessage("Fatal error in admin follow-up processor: " . $e->getMessage(), 'ERROR');
    exit(1);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

logMessage("Admin follow-up processor finished");
?>