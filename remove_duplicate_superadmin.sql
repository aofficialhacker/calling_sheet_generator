-- Script to remove duplicate superladmin (keep only id=1)

-- Show current admin users
SELECT 'BEFORE REMOVAL:' as status;
SELECT id, admin_id, username, designation FROM admin_users ORDER BY id;

-- Remove the superladmin with id=2 (SUPER)
DELETE FROM admin_users WHERE id = 2;

-- Show remaining admin user
SELECT 'AFTER REMOVAL:' as status;
SELECT id, admin_id, username, designation FROM admin_users ORDER BY id;