-- Add Follow-up Columns to final_call_logs table
-- This script adds follow_day and follow_slot columns for follow-up scheduling

USE caller_sheet3;

-- Add new columns for follow-up scheduling
ALTER TABLE final_call_logs 
ADD COLUMN IF NOT EXISTS follow_day INT NULL COMMENT 'Days to add for follow-up date (1-9 days from call date)',
ADD COLUMN IF NOT EXISTS follow_slot INT NULL COMMENT 'Preferred slot for follow-up call (1-8 time slots)';

-- Add indexes for performance on follow-up queries
ALTER TABLE final_call_logs 
ADD INDEX IF NOT EXISTS idx_follow_day (follow_day),
ADD INDEX IF NOT EXISTS idx_follow_slot (follow_slot);

-- Add composite index for follow-up date calculations
ALTER TABLE final_call_logs 
ADD INDEX IF NOT EXISTS idx_follow_up_scheduling (processed_at, follow_day, disposition);

-- Display success message
SELECT 
    'Follow-up columns added successfully!' as Status,
    'Added follow_day and follow_slot columns to final_call_logs table' as Details,
    'Added performance indexes for follow-up queries' as Additional_Info,
    NOW() as Completed_At;

-- Verify the new columns exist
DESCRIBE final_call_logs;