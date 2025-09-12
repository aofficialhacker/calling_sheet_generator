-- Script to truncate team leader dispositions

-- Show current team leader dispositions count
SELECT 'BEFORE TRUNCATION:' as status;
SELECT COUNT(*) as total_tl_dispositions FROM team_leader_dispositions;

-- Truncate team leader dispositions table
TRUNCATE TABLE team_leader_dispositions;

-- Reset auto-increment counter
ALTER TABLE team_leader_dispositions AUTO_INCREMENT = 1;

-- Show after truncation
SELECT 'AFTER TRUNCATION:' as status;
SELECT COUNT(*) as total_tl_dispositions FROM team_leader_dispositions;