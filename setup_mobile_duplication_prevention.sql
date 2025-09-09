-- Mobile Number Duplication Prevention Setup
-- This script adds necessary database optimizations for mobile number duplicate checking

USE caller_sheet3;

-- Add index on mobile_no for fast duplicate checking across the entire system
-- This index will significantly improve performance when checking for duplicates
ALTER TABLE final_call_logs 
ADD INDEX IF NOT EXISTS idx_mobile_no_system_wide (mobile_no);

-- Add composite index for mobile_no with batch_id for better query performance
ALTER TABLE final_call_logs 
ADD INDEX IF NOT EXISTS idx_mobile_batch (mobile_no, batch_id);

-- Add index on upload_time for better duplicate detail queries
ALTER TABLE file_batches 
ADD INDEX IF NOT EXISTS idx_upload_time (upload_time);

-- Optimize existing mobile_no index if it exists (the production script may have created it)
-- This ensures we have the right index structure
ALTER TABLE final_call_logs 
DROP INDEX IF EXISTS idx_mobile_no;

-- Add the optimized mobile_no index
ALTER TABLE final_call_logs 
ADD INDEX idx_mobile_no_optimized (mobile_no);

-- Create a view for easy duplicate analysis (optional, for admin use)
CREATE OR REPLACE VIEW mobile_duplicate_analysis AS
SELECT 
    fcl.mobile_no,
    COUNT(*) as occurrence_count,
    GROUP_CONCAT(DISTINCT fcl.batch_id ORDER BY fb.upload_time) as batch_ids,
    GROUP_CONCAT(DISTINCT fb.admin_id ORDER BY fb.upload_time) as admin_ids,
    GROUP_CONCAT(DISTINCT fb.original_filename ORDER BY fb.upload_time SEPARATOR ' | ') as filenames,
    MIN(fb.upload_time) as first_upload,
    MAX(fb.upload_time) as last_upload
FROM final_call_logs fcl
JOIN file_batches fb ON fcl.batch_id = fb.id
GROUP BY fcl.mobile_no
HAVING occurrence_count > 1
ORDER BY occurrence_count DESC, first_upload ASC;

-- Display setup completion message
SELECT 
    'Mobile duplication prevention database setup completed!' as Status,
    DATABASE() as CurrentDatabase,
    NOW() as SetupTime;

-- Show current duplicate statistics
SELECT 
    'Current system statistics:' as Info,
    (SELECT COUNT(*) FROM final_call_logs) as total_records,
    (SELECT COUNT(DISTINCT mobile_no) FROM final_call_logs) as unique_mobile_numbers,
    (SELECT COUNT(*) - COUNT(DISTINCT mobile_no) FROM final_call_logs) as current_duplicates;

-- Show index information
SHOW INDEX FROM final_call_logs WHERE Key_name LIKE '%mobile%';