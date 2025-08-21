-- Update team_leader_actions table to add user_agent and session_id columns
-- Run this script to add the required columns for tracking team leader actions

ALTER TABLE team_leader_actions 
ADD COLUMN user_agent TEXT DEFAULT NULL,
ADD COLUMN session_id VARCHAR(128) DEFAULT NULL;

-- Add index for better performance on session queries
ALTER TABLE team_leader_actions 
ADD INDEX idx_session_id (session_id),
ADD INDEX idx_action_date (action_date);