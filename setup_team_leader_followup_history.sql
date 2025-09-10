-- Setup Team Leader Follow-up History Tracking System
-- This script enhances the existing follow-up system with delay tracking and admin visibility

USE caller_sheet3;

-- Add completion tracking fields to follow_up_schedules table if not exists
SET @column_exists_completed_at = (
    SELECT COUNT(*)
    FROM information_schema.columns 
    WHERE table_schema = 'caller_sheet3' 
    AND table_name = 'follow_up_schedules' 
    AND column_name = 'completed_at'
);

SET @sql_completed_at = IF(@column_exists_completed_at = 0, 
    'ALTER TABLE follow_up_schedules ADD COLUMN completed_at TIMESTAMP NULL AFTER status',
    'SELECT "Column completed_at already exists" as message'
);

PREPARE stmt FROM @sql_completed_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add delay tracking field
SET @column_exists_delay_minutes = (
    SELECT COUNT(*)
    FROM information_schema.columns 
    WHERE table_schema = 'caller_sheet3' 
    AND table_name = 'follow_up_schedules' 
    AND column_name = 'delay_minutes'
);

SET @sql_delay_minutes = IF(@column_exists_delay_minutes = 0, 
    'ALTER TABLE follow_up_schedules ADD COLUMN delay_minutes INT NULL COMMENT "Delay in minutes from scheduled time to completion" AFTER completed_at',
    'SELECT "Column delay_minutes already exists" as message'
);

PREPARE stmt FROM @sql_delay_minutes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add completed_by field to track which admin/system marked as completed
SET @column_exists_completed_by = (
    SELECT COUNT(*)
    FROM information_schema.columns 
    WHERE table_schema = 'caller_sheet3' 
    AND table_name = 'follow_up_schedules' 
    AND column_name = 'completed_by'
);

SET @sql_completed_by = IF(@column_exists_completed_by = 0, 
    'ALTER TABLE follow_up_schedules ADD COLUMN completed_by VARCHAR(50) NULL COMMENT "User ID who marked as completed" AFTER delay_minutes',
    'SELECT "Column completed_by already exists" as message'
);

PREPARE stmt FROM @sql_completed_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create indexes for performance optimization
ALTER TABLE follow_up_schedules 
ADD INDEX IF NOT EXISTS idx_completed_at (completed_at),
ADD INDEX IF NOT EXISTS idx_delay_minutes (delay_minutes),
ADD INDEX IF NOT EXISTS idx_leader_status_date (leader_id, status, follow_up_datetime);

-- Create view for follow-up performance analytics
CREATE OR REPLACE VIEW vw_followup_performance AS
SELECT 
    fs.leader_id,
    tl.leader_name,
    tl.admin_id,
    fs.bucket_id,
    db.bucket_name,
    COUNT(*) as total_followups,
    COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_followups,
    COUNT(CASE WHEN fs.status = 'cancelled' THEN 1 END) as cancelled_followups,
    COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_followups,
    COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime >= NOW() THEN 1 END) as pending_followups,
    ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
    ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / COUNT(*)), 2) as completion_rate,
    ROUND((COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) * 100.0 / COUNT(*)), 2) as overdue_rate,
    DATE(fs.created_at) as activity_date
FROM follow_up_schedules fs
JOIN team_leaders tl ON fs.leader_id = tl.leader_id
JOIN disposition_buckets db ON fs.bucket_id = db.id
GROUP BY fs.leader_id, tl.leader_name, tl.admin_id, fs.bucket_id, db.bucket_name, DATE(fs.created_at);

-- Create view for overdue follow-ups summary
CREATE OR REPLACE VIEW vw_overdue_followups AS
SELECT 
    fs.id,
    fs.schedule_id,
    fs.leader_id,
    tl.leader_name,
    tl.admin_id,
    fs.follow_up_datetime,
    fs.disposition_name,
    db.bucket_name,
    fcl.name as customer_name,
    fcl.mobile_no as customer_mobile,
    TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) as overdue_minutes,
    CASE 
        WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 60 THEN 'Recently Overdue'
        WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 1440 THEN 'Overdue (< 1 day)'
        WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 10080 THEN 'Overdue (< 1 week)'
        ELSE 'Severely Overdue (> 1 week)'
    END as overdue_severity,
    fs.remarks
FROM follow_up_schedules fs
JOIN team_leaders tl ON fs.leader_id = tl.leader_id
JOIN disposition_buckets db ON fs.bucket_id = db.id
JOIN final_call_logs fcl ON fs.lead_id = fcl.id
WHERE fs.status = 'scheduled' AND fs.follow_up_datetime < NOW()
ORDER BY fs.follow_up_datetime ASC;

-- Create trigger to automatically calculate delay when marking as completed
DELIMITER $$

DROP TRIGGER IF EXISTS tr_followup_completion_delay$$

CREATE TRIGGER tr_followup_completion_delay
    BEFORE UPDATE ON follow_up_schedules
    FOR EACH ROW
BEGIN
    -- If status is being changed to 'completed' and wasn't already completed
    IF NEW.status = 'completed' AND OLD.status != 'completed' THEN
        -- Set completion timestamp
        SET NEW.completed_at = NOW();
        
        -- Calculate delay in minutes (positive = late, negative = early)
        SET NEW.delay_minutes = TIMESTAMPDIFF(MINUTE, NEW.follow_up_datetime, NOW());
    END IF;
END$$

DELIMITER ;

-- Update existing completed follow-ups to calculate delay retroactively
UPDATE follow_up_schedules 
SET delay_minutes = TIMESTAMPDIFF(MINUTE, follow_up_datetime, updated_at),
    completed_at = updated_at
WHERE status = 'completed' AND delay_minutes IS NULL AND completed_at IS NULL;

-- Create summary statistics table for quick admin dashboard access
CREATE TABLE IF NOT EXISTS followup_admin_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(20) NOT NULL,
    stats_date DATE NOT NULL,
    total_leaders INT DEFAULT 0,
    active_leaders_today INT DEFAULT 0,
    total_followups INT DEFAULT 0,
    completed_followups INT DEFAULT 0,
    overdue_followups INT DEFAULT 0,
    avg_delay_minutes DECIMAL(10,2) DEFAULT 0,
    completion_rate DECIMAL(5,2) DEFAULT 0,
    overdue_rate DECIMAL(5,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_admin_date (admin_id, stats_date),
    INDEX idx_admin_id (admin_id),
    INDEX idx_stats_date (stats_date)
);

-- Insert initial statistics for today
INSERT INTO followup_admin_stats (admin_id, stats_date, total_leaders, active_leaders_today, total_followups, completed_followups, overdue_followups, avg_delay_minutes, completion_rate, overdue_rate)
SELECT 
    tl.admin_id,
    CURDATE() as stats_date,
    COUNT(DISTINCT tl.leader_id) as total_leaders,
    COUNT(DISTINCT CASE WHEN DATE(fs.created_at) = CURDATE() THEN tl.leader_id END) as active_leaders_today,
    COUNT(fs.id) as total_followups,
    COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_followups,
    COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_followups,
    ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
    ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(fs.id), 0)), 2) as completion_rate,
    ROUND((COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) * 100.0 / NULLIF(COUNT(fs.id), 0)), 2) as overdue_rate
FROM team_leaders tl
LEFT JOIN follow_up_schedules fs ON tl.leader_id = fs.leader_id
WHERE tl.is_active = 1
GROUP BY tl.admin_id
ON DUPLICATE KEY UPDATE
    total_leaders = VALUES(total_leaders),
    active_leaders_today = VALUES(active_leaders_today),
    total_followups = VALUES(total_followups),
    completed_followups = VALUES(completed_followups),
    overdue_followups = VALUES(overdue_followups),
    avg_delay_minutes = VALUES(avg_delay_minutes),
    completion_rate = VALUES(completion_rate),
    overdue_rate = VALUES(overdue_rate);

-- Create a procedure to refresh daily statistics
DELIMITER $$

DROP PROCEDURE IF EXISTS RefreshFollowupStats$$

CREATE PROCEDURE RefreshFollowupStats(IN p_admin_id VARCHAR(20))
BEGIN
    INSERT INTO followup_admin_stats (admin_id, stats_date, total_leaders, active_leaders_today, total_followups, completed_followups, overdue_followups, avg_delay_minutes, completion_rate, overdue_rate)
    SELECT 
        tl.admin_id,
        CURDATE() as stats_date,
        COUNT(DISTINCT tl.leader_id) as total_leaders,
        COUNT(DISTINCT CASE WHEN DATE(fs.created_at) = CURDATE() THEN tl.leader_id END) as active_leaders_today,
        COUNT(fs.id) as total_followups,
        COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_followups,
        COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_followups,
        ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
        ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(fs.id), 0)), 2) as completion_rate,
        ROUND((COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) * 100.0 / NULLIF(COUNT(fs.id), 0)), 2) as overdue_rate
    FROM team_leaders tl
    LEFT JOIN follow_up_schedules fs ON tl.leader_id = fs.leader_id
    WHERE tl.is_active = 1 AND (p_admin_id IS NULL OR tl.admin_id = p_admin_id)
    GROUP BY tl.admin_id
    ON DUPLICATE KEY UPDATE
        total_leaders = VALUES(total_leaders),
        active_leaders_today = VALUES(active_leaders_today),
        total_followups = VALUES(total_followups),
        completed_followups = VALUES(completed_followups),
        overdue_followups = VALUES(overdue_followups),
        avg_delay_minutes = VALUES(avg_delay_minutes),
        completion_rate = VALUES(completion_rate),
        overdue_rate = VALUES(overdue_rate);
END$$

DELIMITER ;

-- Grant necessary permissions (if needed)
-- GRANT SELECT, INSERT, UPDATE ON caller_sheet3.* TO 'your_app_user'@'localhost';

SELECT 'Team Leader Follow-up History Tracking System setup completed successfully!' as message;