-- Call History & Redistribution System Database Setup
-- This script creates the necessary tables and fields for tracking call attempts
-- and enabling safe redistribution without data loss

-- Create call_history table to track all call attempts
CREATE TABLE IF NOT EXISTS call_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_record_id VARCHAR(50) NOT NULL COMMENT 'References final_call_logs.id',
    finqy_id VARCHAR(50) NOT NULL COMMENT 'Caller who made this attempt',
    attempt_number INT NOT NULL DEFAULT 1 COMMENT 'Sequential attempt counter for this record',
    batch_id VARCHAR(50) NOT NULL COMMENT 'Which batch this attempt belongs to',
    disposition VARCHAR(50) NULL COMMENT 'Disposition marked by caller',
    slot INT NULL COMMENT 'Time slot marked by caller',
    connectivity VARCHAR(10) NULL COMMENT 'Connectivity status marked',
    attempt_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this attempt was made',
    notes TEXT NULL COMMENT 'Additional notes or comments',
    is_original_attempt BOOLEAN DEFAULT FALSE COMMENT 'TRUE if this was the first attempt on this record',
    redistribution_batch_ref VARCHAR(100) NULL COMMENT 'Reference to redistribution batch if applicable',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_original_record (original_record_id),
    INDEX idx_caller (finqy_id),
    INDEX idx_batch (batch_id),
    INDEX idx_attempt_date (attempt_date),
    INDEX idx_disposition (disposition),
    
    FOREIGN KEY (batch_id) REFERENCES file_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Tracks all call attempts for performance comparison and audit trail';

-- Add tracking fields to final_call_logs table
ALTER TABLE final_call_logs 
ADD COLUMN IF NOT EXISTS original_caller_id VARCHAR(50) NULL COMMENT 'First caller who worked on this record' AFTER finqy_id,
ADD COLUMN IF NOT EXISTS redistribution_count INT DEFAULT 0 COMMENT 'How many times this record has been redistributed' AFTER original_caller_id,
ADD COLUMN IF NOT EXISTS last_updated_by VARCHAR(50) NULL COMMENT 'Last caller who updated this record' AFTER redistribution_count,
ADD COLUMN IF NOT EXISTS is_redistributed BOOLEAN DEFAULT FALSE COMMENT 'Whether this record has been redistributed' AFTER last_updated_by,
ADD COLUMN IF NOT EXISTS redistribution_reason VARCHAR(100) NULL COMMENT 'Reason for redistribution (Follow Up, Not Interested, etc.)' AFTER is_redistributed,
ADD COLUMN IF NOT EXISTS last_attempt_date DATETIME NULL COMMENT 'Date of last call attempt' AFTER redistribution_reason;

-- Add indexes for performance
ALTER TABLE final_call_logs 
ADD INDEX IF NOT EXISTS idx_original_caller (original_caller_id),
ADD INDEX IF NOT EXISTS idx_last_updated_by (last_updated_by),
ADD INDEX IF NOT EXISTS idx_redistribution_count (redistribution_count),
ADD INDEX IF NOT EXISTS idx_is_redistributed (is_redistributed),
ADD INDEX IF NOT EXISTS idx_last_attempt_date (last_attempt_date);

-- Create view for easy caller performance comparison
CREATE OR REPLACE VIEW caller_performance_comparison AS
SELECT 
    ch.original_record_id,
    fcl.mobile_no,
    fcl.name,
    fcl.batch_id,
    fb.product_code,
    ch.finqy_id as caller_id,
    c.caller_name,
    ch.attempt_number,
    ch.disposition,
    ch.slot,
    ch.connectivity,
    ch.attempt_date,
    ch.is_original_attempt,
    CASE 
        WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 'Positive'
        WHEN ch.disposition IN ('Not Interested', 'DND', 'Wrong Number') THEN 'Negative'
        WHEN ch.disposition IN ('Follow Up', 'Busy', 'No Response') THEN 'Follow Required'
        ELSE 'Other'
    END as disposition_category
FROM call_history ch
JOIN final_call_logs fcl ON ch.original_record_id = fcl.id
JOIN file_batches fb ON ch.batch_id = fb.id
LEFT JOIN callers c ON ch.finqy_id = c.finqy_id
ORDER BY ch.original_record_id, ch.attempt_number;

-- Create view for redistribution tracking
CREATE OR REPLACE VIEW redistribution_tracking AS
SELECT 
    fcl.id as record_id,
    fcl.mobile_no,
    fcl.name,
    fcl.batch_id,
    fb.product_code,
    fcl.original_caller_id,
    oc.caller_name as original_caller_name,
    fcl.redistribution_count,
    fcl.last_updated_by,
    lc.caller_name as last_caller_name,
    fcl.redistribution_reason,
    fcl.last_attempt_date,
    COUNT(ch.id) as total_attempts,
    GROUP_CONCAT(DISTINCT ch.disposition ORDER BY ch.attempt_date) as all_dispositions,
    GROUP_CONCAT(DISTINCT c.caller_name ORDER BY ch.attempt_date) as all_callers
FROM final_call_logs fcl
JOIN file_batches fb ON fcl.batch_id = fb.id
LEFT JOIN callers oc ON fcl.original_caller_id = oc.finqy_id
LEFT JOIN callers lc ON fcl.last_updated_by = lc.finqy_id
LEFT JOIN call_history ch ON fcl.id = ch.original_record_id
LEFT JOIN callers c ON ch.finqy_id = c.finqy_id
WHERE fcl.is_redistributed = TRUE
GROUP BY fcl.id
ORDER BY fcl.redistribution_count DESC, fcl.last_attempt_date DESC;

-- Insert initial data for existing records (one-time migration)
-- This creates history entries for all existing records
INSERT IGNORE INTO call_history (
    original_record_id, 
    finqy_id, 
    attempt_number, 
    batch_id, 
    disposition, 
    slot, 
    connectivity, 
    attempt_date, 
    is_original_attempt
)
SELECT 
    id as original_record_id,
    COALESCE(finqy_id, 'UNKNOWN') as finqy_id,
    1 as attempt_number,
    batch_id,
    disposition,
    slot,
    connectivity,
    COALESCE(processed_at, NOW()) as attempt_date,
    TRUE as is_original_attempt
FROM final_call_logs 
WHERE finqy_id IS NOT NULL OR disposition IS NOT NULL;

-- Update original_caller_id for existing records
UPDATE final_call_logs fcl
SET original_caller_id = fcl.finqy_id,
    last_updated_by = fcl.finqy_id,
    last_attempt_date = fcl.processed_at
WHERE original_caller_id IS NULL 
AND finqy_id IS NOT NULL;

-- Create stored procedure for safe record redistribution
DELIMITER //
CREATE PROCEDURE RedistributeRecord(
    IN p_record_id VARCHAR(50),
    IN p_reason VARCHAR(100),
    IN p_admin_id VARCHAR(50)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Update redistribution tracking
    UPDATE final_call_logs 
    SET redistribution_count = redistribution_count + 1,
        is_redistributed = TRUE,
        redistribution_reason = p_reason
    WHERE id = p_record_id;
    
    -- Log the redistribution action
    INSERT INTO call_history (
        original_record_id,
        finqy_id,
        attempt_number,
        batch_id,
        notes,
        attempt_date
    )
    SELECT 
        id,
        p_admin_id,
        (SELECT MAX(attempt_number) + 1 FROM call_history WHERE original_record_id = p_record_id),
        batch_id,
        CONCAT('Record redistributed by admin. Reason: ', p_reason),
        NOW()
    FROM final_call_logs 
    WHERE id = p_record_id;
    
    COMMIT;
END //
DELIMITER ;

-- Success message
SELECT 'Call History & Redistribution System setup completed successfully!' as Status;