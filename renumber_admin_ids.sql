-- Script to renumber existing admin_users IDs to start from 1
-- WARNING: This changes primary key values, use with caution

-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Show current admin users
SELECT 'BEFORE RENUMBERING:' as status;
SELECT id, admin_id, username, designation FROM admin_users ORDER BY id;

-- Create temporary table to hold the renumbered data
CREATE TEMPORARY TABLE temp_admin_users AS 
SELECT admin_id, username, password, name, designation, multi_status_selection, 
       is_active, created_at, download_limit
FROM admin_users 
ORDER BY id;

-- Truncate the original table (this resets auto-increment)
TRUNCATE TABLE admin_users;

-- Insert data back with new sequential IDs starting from 1
INSERT INTO admin_users (admin_id, username, password, name, designation, 
                        multi_status_selection, is_active, created_at, download_limit)
SELECT admin_id, username, password, name, designation, multi_status_selection, 
       is_active, created_at, download_limit
FROM temp_admin_users;

-- Drop temporary table
DROP TEMPORARY TABLE temp_admin_users;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Show renumbered admin users
SELECT 'AFTER RENUMBERING:' as status;
SELECT id, admin_id, username, designation FROM admin_users ORDER BY id;