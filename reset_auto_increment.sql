-- Script to reset auto-increment IDs after cleanup
-- Run this after the cleanup script to reset ID sequences

-- Reset admin_users auto-increment to start from 1
ALTER TABLE admin_users AUTO_INCREMENT = 1;

-- Reset other tables that might need ID reset
ALTER TABLE callers AUTO_INCREMENT = 1;
ALTER TABLE team_leaders AUTO_INCREMENT = 1;
ALTER TABLE file_batches AUTO_INCREMENT = 1;
ALTER TABLE final_call_logs AUTO_INCREMENT = 1;
ALTER TABLE call_history AUTO_INCREMENT = 1;

-- Show current max IDs to verify reset
SELECT 'Current Max IDs:' as status;
SELECT 
    'admin_users' as table_name,
    COALESCE(MAX(id), 0) as max_id,
    AUTO_INCREMENT as next_auto_increment
FROM admin_users, 
     (SELECT AUTO_INCREMENT FROM information_schema.tables 
      WHERE table_schema = 'caller_sheet3' AND table_name = 'admin_users') as ai

UNION ALL

SELECT 
    'callers',
    COALESCE(MAX(id), 0),
    (SELECT AUTO_INCREMENT FROM information_schema.tables 
     WHERE table_schema = 'caller_sheet3' AND table_name = 'callers')
FROM callers

UNION ALL

SELECT 
    'team_leaders',
    COALESCE(MAX(id), 0),
    (SELECT AUTO_INCREMENT FROM information_schema.tables 
     WHERE table_schema = 'caller_sheet3' AND table_name = 'team_leaders')
FROM team_leaders;