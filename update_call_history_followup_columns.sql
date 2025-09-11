-- Update call_history table to include follow-up columns
-- This script adds follow_day and follow_slot columns to match final_call_logs structure

USE caller_sheet3;

-- Add new follow-up columns to call_history table
ALTER TABLE call_history 
ADD COLUMN IF NOT EXISTS follow_day INT NULL COMMENT 'Days for follow-up (preserved from final_call_logs)',
ADD COLUMN IF NOT EXISTS follow_slot INT NULL COMMENT 'Follow-up time slot (preserved from final_call_logs)';

-- Remove connectivity column as it's no longer used
ALTER TABLE call_history 
DROP COLUMN IF EXISTS connectivity;

-- Add indexes for the new columns
ALTER TABLE call_history 
ADD INDEX IF NOT EXISTS idx_follow_day (follow_day),
ADD INDEX IF NOT EXISTS idx_follow_slot (follow_slot);

-- Display success message
SELECT 
    'Call history table updated successfully!' as Status,
    'Added follow_day and follow_slot columns to call_history table' as Details,
    'Removed connectivity column (no longer used)' as Additional_Info,
    NOW() as Completed_At;

-- Verify the new structure
DESCRIBE call_history;