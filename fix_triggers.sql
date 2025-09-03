USE caller_sheet3;

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS after_followup_insert;
DROP TRIGGER IF EXISTS before_followup_status_check;

-- Create trigger to generate notifications when follow-up is scheduled
DELIMITER $$

CREATE TRIGGER after_followup_insert 
AFTER INSERT ON follow_up_schedules
FOR EACH ROW
BEGIN
    -- Create immediate notification (at the scheduled time)
    INSERT INTO follow_up_notifications (schedule_id, notification_type, scheduled_time)
    VALUES (NEW.id, 'immediate', NEW.follow_up_datetime);
    
    -- Create 1-hour before notification (only if scheduled time is more than 1 hour away)
    IF NEW.follow_up_datetime > DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN
        INSERT INTO follow_up_notifications (schedule_id, notification_type, scheduled_time)
        VALUES (NEW.id, '1_hour', DATE_SUB(NEW.follow_up_datetime, INTERVAL 1 HOUR));
    END IF;
END$$

DELIMITER ;

-- Now manually create notifications for existing follow-up schedules
INSERT INTO follow_up_notifications (schedule_id, notification_type, scheduled_time)
SELECT id, 'immediate', follow_up_datetime
FROM follow_up_schedules 
WHERE status = 'scheduled';

SELECT 'Triggers created and existing follow-ups processed' as status;