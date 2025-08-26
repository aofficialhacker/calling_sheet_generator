ALTER TABLE team_leaders
ADD COLUMN active_session_id VARCHAR(255) NULL DEFAULT NULL AFTER last_login;