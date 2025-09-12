-- Script to remove all admin users except superadmin
-- This will keep only the superadmin accounts for fresh data import

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Show current admin users before deletion
SELECT 'BEFORE CLEANUP:' as status;
SELECT id, admin_id, username, designation FROM admin_users ORDER BY designation;

-- Delete all admin users except superadmin/Superadmin designations
-- Keep users with designation 'superadmin' or 'Superadmin'
-- First, clean up dependent records that reference admin_users
-- Clean up team_leaders that reference non-superladmin admins
DELETE FROM team_leaders 
WHERE admin_id IN (
    SELECT admin_id FROM admin_users 
    WHERE designation NOT IN ('superadmin', 'Superadmin')
);

DELETE FROM admin_users 
WHERE designation NOT IN ('superadmin', 'Superadmin');

-- Also delete related data for removed admin users
-- Clean up admin_caller_mapping for non-existent admins
DELETE acm FROM admin_caller_mapping acm
LEFT JOIN admin_users au ON acm.admin_id = au.admin_id
WHERE au.admin_id IS NULL;

-- Clean up admin_download_history for non-existent admins  
DELETE adh FROM admin_download_history adh
LEFT JOIN admin_users au ON adh.admin_id = au.admin_id
WHERE au.admin_id IS NULL;

-- Clean up admin_download_limits for non-existent admins
DELETE adl FROM admin_download_limits adl  
LEFT JOIN admin_users au ON adl.admin_id = au.admin_id
WHERE au.admin_id IS NULL;

-- Clean up admin_download_tracking for non-existent admins
DELETE adt FROM admin_download_tracking adt
LEFT JOIN admin_users au ON adt.admin_id = au.admin_id  
WHERE au.admin_id IS NULL;

-- Show remaining admin users after cleanup
SELECT 'AFTER CLEANUP:' as status;
SELECT id, admin_id, username, designation FROM admin_users ORDER BY designation;

-- Show count of remaining records
SELECT 'SUMMARY:' as status;
SELECT 
    (SELECT COUNT(*) FROM admin_users) as remaining_admin_users,
    (SELECT COUNT(*) FROM admin_caller_mapping) as remaining_caller_mappings,
    (SELECT COUNT(*) FROM admin_download_history) as remaining_download_history,
    (SELECT COUNT(*) FROM admin_download_limits) as remaining_download_limits,
    (SELECT COUNT(*) FROM admin_download_tracking) as remaining_download_tracking;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;