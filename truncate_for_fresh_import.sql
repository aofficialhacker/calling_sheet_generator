-- Complete truncate script for fresh data import
-- Removes all data except superadmin login and system configurations

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Show counts before truncation
SELECT 'BEFORE TRUNCATION - Record Counts:' as status;
SELECT 
    'admin_users' as table_name, COUNT(*) as records FROM admin_users
UNION ALL
SELECT 'callers', COUNT(*) FROM callers
UNION ALL
SELECT 'team_leaders', COUNT(*) FROM team_leaders
UNION ALL
SELECT 'final_call_logs', COUNT(*) FROM final_call_logs
UNION ALL
SELECT 'file_batches', COUNT(*) FROM file_batches;

-- Remove all admin users except superadmin
DELETE FROM admin_users WHERE designation != 'superadmin';

-- Remove all callers
TRUNCATE TABLE callers;

-- Remove all team leaders
TRUNCATE TABLE team_leaders;

-- Data tables - safe to truncate for fresh data import
TRUNCATE TABLE final_call_logs;
TRUNCATE TABLE file_batches;
TRUNCATE TABLE call_history;
TRUNCATE TABLE admin_caller_mapping;
TRUNCATE TABLE admin_download_history;
TRUNCATE TABLE admin_download_limits;
TRUNCATE TABLE admin_download_tracking;
TRUNCATE TABLE download_tracking;
TRUNCATE TABLE team_leader_actions;
TRUNCATE TABLE team_leader_view_logs;
TRUNCATE TABLE team_leader_logins;
TRUNCATE TABLE follow_up_notifications;
TRUNCATE TABLE follow_up_schedules;
TRUNCATE TABLE notification_read_status;
TRUNCATE TABLE security_violations;
TRUNCATE TABLE vendor_requests;
TRUNCATE TABLE vendors;
TRUNCATE TABLE corp_leader;
TRUNCATE TABLE corporate_connector;
TRUNCATE TABLE corporate_user_permission;
TRUNCATE TABLE first_register;
TRUNCATE TABLE disposition_buckets;

-- Reset auto-increment counters
ALTER TABLE admin_users AUTO_INCREMENT = 2;  -- Start from 2 since superadmin is id=1
ALTER TABLE callers AUTO_INCREMENT = 1;
ALTER TABLE team_leaders AUTO_INCREMENT = 1;
ALTER TABLE final_call_logs AUTO_INCREMENT = 1;
ALTER TABLE file_batches AUTO_INCREMENT = 1;
ALTER TABLE call_history AUTO_INCREMENT = 1;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Show remaining record counts for verification
SELECT 'AFTER TRUNCATION - Remaining Records:' as status;
SELECT 
    'admin_users' as table_name, COUNT(*) as records FROM admin_users
UNION ALL
SELECT 'callers', COUNT(*) FROM callers
UNION ALL
SELECT 'team_leaders', COUNT(*) FROM team_leaders
UNION ALL
SELECT 'disposition_codes', COUNT(*) FROM disposition_codes
UNION ALL
SELECT 'products', COUNT(*) FROM products
UNION ALL
SELECT 'final_call_logs', COUNT(*) FROM final_call_logs
UNION ALL
SELECT 'file_batches', COUNT(*) FROM file_batches;

-- Show remaining admin user
SELECT 'REMAINING ADMIN USER:' as status;
SELECT id, admin_id, username, designation FROM admin_users;