-- Setup Bucket-Based Disposition System with Calendar Functionality
-- Run this script to create the necessary tables and update existing ones

USE caller_sheet3;

-- Create disposition_buckets table
CREATE TABLE IF NOT EXISTS disposition_buckets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    has_calendar_enabled TINYINT(1) DEFAULT 0,
    created_by VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_bucket_active (is_active),
    INDEX idx_bucket_created (created_at)
);

-- Add bucket_id column to team_leader_dispositions table if not exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns 
    WHERE table_schema = 'caller_sheet3' 
    AND table_name = 'team_leader_dispositions' 
    AND column_name = 'bucket_id'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE team_leader_dispositions ADD COLUMN bucket_id INT NULL AFTER description, ADD INDEX idx_bucket_id (bucket_id), ADD FOREIGN KEY (bucket_id) REFERENCES disposition_buckets(id) ON DELETE SET NULL',
    'SELECT "Column bucket_id already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create follow_up_schedules table
CREATE TABLE IF NOT EXISTS follow_up_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id VARCHAR(50) NOT NULL UNIQUE,
    lead_id VARCHAR(50) NOT NULL,
    leader_id VARCHAR(20) NOT NULL,
    disposition_name VARCHAR(100) NOT NULL,
    bucket_id INT NOT NULL,
    follow_up_datetime DATETIME NOT NULL,
    status ENUM('scheduled', 'completed', 'cancelled', 'overdue') DEFAULT 'scheduled',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leader_id (leader_id),
    INDEX idx_lead_id (lead_id),
    INDEX idx_bucket_id (bucket_id),
    INDEX idx_follow_up_datetime (follow_up_datetime),
    INDEX idx_status (status),
    FOREIGN KEY (bucket_id) REFERENCES disposition_buckets(id) ON DELETE CASCADE
);

-- Create follow_up_notifications table
CREATE TABLE IF NOT EXISTS follow_up_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    notification_type ENUM('immediate', '1_hour', '1_day') DEFAULT 'immediate',
    scheduled_time DATETIME NOT NULL,
    sent_at TIMESTAMP NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    next_attempt DATETIME NULL,
    attempt_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schedule_id (schedule_id),
    INDEX idx_scheduled_time (scheduled_time),
    INDEX idx_status (status),
    FOREIGN KEY (schedule_id) REFERENCES follow_up_schedules(id) ON DELETE CASCADE
);

-- Insert default buckets
INSERT IGNORE INTO disposition_buckets (bucket_name, description, has_calendar_enabled, created_by) VALUES
('Follow Up', 'General follow-up dispositions requiring future contact', 1, 'SYSTEM'),
('Immediate Action', 'Dispositions requiring immediate action without scheduling', 0, 'SYSTEM'),
('Closure', 'Final dispositions that close the lead', 0, 'SYSTEM'),
('Payment Related', 'Payment processing and related activities', 1, 'SYSTEM');

-- Update existing dispositions to assign them to appropriate buckets
UPDATE team_leader_dispositions 
SET bucket_id = (SELECT id FROM disposition_buckets WHERE bucket_name = 'Follow Up' LIMIT 1)
WHERE disposition_name LIKE '%Follow%' OR disposition_name LIKE '%Callback%' OR disposition_name LIKE '%Reschedule%';

UPDATE team_leader_dispositions 
SET bucket_id = (SELECT id FROM disposition_buckets WHERE bucket_name = 'Payment Related' LIMIT 1)
WHERE disposition_name LIKE '%Payment%' OR disposition_name LIKE '%Proceed%';

UPDATE team_leader_dispositions 
SET bucket_id = (SELECT id FROM disposition_buckets WHERE bucket_name = 'Closure' LIMIT 1)
WHERE disposition_name LIKE '%Not Interested%' OR disposition_name LIKE '%Reject%' OR disposition_name LIKE '%Close%';

UPDATE team_leader_dispositions 
SET bucket_id = (SELECT id FROM disposition_buckets WHERE bucket_name = 'Immediate Action' LIMIT 1)
WHERE bucket_id IS NULL;

-- Create indexes for better performance (ignore errors if they already exist)
SET @sql = 'CREATE INDEX idx_tl_actions_new_disposition ON team_leader_actions(new_disposition)';
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_name = 'team_leader_actions' AND index_name = 'idx_tl_actions_new_disposition' AND table_schema = DATABASE()) > 0, 'SELECT "Index already exists"', @sql);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = 'CREATE INDEX idx_tl_actions_action_date ON team_leader_actions(action_date)';
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_name = 'team_leader_actions' AND index_name = 'idx_tl_actions_action_date' AND table_schema = DATABASE()) > 0, 'SELECT "Index already exists"', @sql);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add triggers to maintain data integrity
DELIMITER $$

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS after_followup_insert$$
DROP TRIGGER IF EXISTS before_followup_status_check$$

-- Trigger to create notifications when a follow-up is scheduled
CREATE TRIGGER after_followup_insert 
AFTER INSERT ON follow_up_schedules
FOR EACH ROW
BEGIN
    -- Create immediate notification (at the scheduled time)
    INSERT INTO follow_up_notifications (schedule_id, notification_type, scheduled_time)
    VALUES (NEW.id, 'immediate', NEW.follow_up_datetime);
    
    -- Create 1-hour before notification
    INSERT INTO follow_up_notifications (schedule_id, notification_type, scheduled_time)
    VALUES (NEW.id, '1_hour', DATE_SUB(NEW.follow_up_datetime, INTERVAL 1 HOUR));
END$$

-- Trigger to update follow-up status when overdue
CREATE TRIGGER before_followup_status_check
BEFORE UPDATE ON follow_up_schedules
FOR EACH ROW
BEGIN
    IF NEW.status = 'scheduled' AND NEW.follow_up_datetime < NOW() THEN
        SET NEW.status = 'overdue';
    END IF;
END$$

DELIMITER ;

-- Create a view for easy follow-up reporting
CREATE OR REPLACE VIEW follow_up_summary AS
SELECT 
    fs.id,
    fs.schedule_id,
    fs.lead_id,
    fs.leader_id,
    tl.name as leader_name,
    fs.disposition_name,
    db.bucket_name,
    fs.follow_up_datetime,
    fs.status,
    fs.remarks,
    fs.created_at,
    fcl.name as lead_name,
    fcl.mobile_no as lead_mobile,
    p.product_name
FROM follow_up_schedules fs
JOIN disposition_buckets db ON fs.bucket_id = db.id
LEFT JOIN team_leaders tl ON fs.leader_id = tl.id
LEFT JOIN final_call_logs fcl ON fs.lead_id = fcl.id
LEFT JOIN file_batches fb ON fcl.batch_id = fb.id
LEFT JOIN products p ON fb.product_code = p.product_code;

-- Update table comments for documentation
ALTER TABLE disposition_buckets COMMENT = 'Buckets for organizing team leader dispositions with optional calendar functionality';
ALTER TABLE follow_up_schedules COMMENT = 'Scheduled follow-ups for calendar-enabled disposition buckets';
ALTER TABLE follow_up_notifications COMMENT = 'Notification schedule for follow-up reminders';

SELECT 'Bucket system setup completed successfully!' as message;