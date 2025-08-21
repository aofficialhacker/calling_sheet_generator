-- Update team_leaders table to add access code functionality
-- Run this script to add the required columns for 4-hour expiring access codes

ALTER TABLE team_leaders 
ADD COLUMN access_code VARCHAR(6) DEFAULT NULL,
ADD COLUMN code_generated_at TIMESTAMP DEFAULT NULL;

-- Generate initial access codes for existing team leaders
UPDATE team_leaders 
SET access_code = UPPER(SUBSTRING(MD5(CONCAT(leader_id, UNIX_TIMESTAMP())), 1, 6)),
    code_generated_at = NOW()
WHERE is_active = 1;