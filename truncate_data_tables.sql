-- Truncate script to clear data tables while preserving login and configuration tables
-- Run this script to prepare for fresh data import

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Data tables - safe to truncate for fresh data import
TRUNCATE TABLE `final_call_logs`;
TRUNCATE TABLE `file_batches`;
TRUNCATE TABLE `call_history`;
TRUNCATE TABLE `admin_caller_mapping`;
TRUNCATE TABLE `admin_download_history`;
TRUNCATE TABLE `admin_download_limits`;
TRUNCATE TABLE `admin_download_tracking`;
TRUNCATE TABLE `download_tracking`;
TRUNCATE TABLE `team_leader_actions`;
TRUNCATE TABLE `team_leader_view_logs`;
TRUNCATE TABLE `team_leader_logins`;
TRUNCATE TABLE `follow_up_notifications`;
TRUNCATE TABLE `follow_up_schedules`;
TRUNCATE TABLE `notification_read_status`;
TRUNCATE TABLE `security_violations`;
TRUNCATE TABLE `vendor_requests`;
TRUNCATE TABLE `vendors`;
TRUNCATE TABLE `corp_leader`;
TRUNCATE TABLE `corporate_connector`;
TRUNCATE TABLE `corporate_user_permission`;
TRUNCATE TABLE `first_register`;
TRUNCATE TABLE `disposition_buckets`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Show remaining record counts for verification
SELECT 'admin_users' as table_name, COUNT(*) as records FROM admin_users
UNION ALL
SELECT 'callers', COUNT(*) FROM callers
UNION ALL  
SELECT 'team_leaders', COUNT(*) FROM team_leaders
UNION ALL
SELECT 'disposition_codes', COUNT(*) FROM disposition_codes
UNION ALL
SELECT 'team_leader_dispositions', COUNT(*) FROM team_leader_dispositions
UNION ALL
SELECT 'products', COUNT(*) FROM products
UNION ALL
SELECT 'blocklist_numbers', COUNT(*) FROM blocklist_numbers
UNION ALL
SELECT 'final_call_logs', COUNT(*) FROM final_call_logs
UNION ALL
SELECT 'file_batches', COUNT(*) FROM file_batches;