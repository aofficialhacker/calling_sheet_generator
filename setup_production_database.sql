-- Production Database Setup Script
-- Run this script to prepare the database for production deployment

-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS caller_sheet3 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE caller_sheet3;

-- Create dedicated database user for the application (replace with actual secure password)
-- DROP USER IF EXISTS 'callflow_app'@'localhost';
-- CREATE USER 'callflow_app'@'localhost' IDENTIFIED BY 'your_secure_password_here';

-- Grant minimal required privileges to the application user
-- GRANT SELECT, INSERT, UPDATE, DELETE ON caller_sheet3.* TO 'callflow_app'@'localhost';
-- GRANT CREATE TEMPORARY TABLES ON caller_sheet3.* TO 'callflow_app'@'localhost';

-- Ensure all password fields are ready for hashed passwords
ALTER TABLE admin_users MODIFY COLUMN password VARCHAR(255) NOT NULL COMMENT 'Hashed password using password_hash()';
ALTER TABLE team_leaders MODIFY COLUMN password VARCHAR(255) NOT NULL COMMENT 'Hashed password using password_hash()';

-- Add security audit columns if they don't exist
ALTER TABLE admin_users 
ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL COMMENT 'Last successful login time',
ADD COLUMN IF NOT EXISTS failed_login_attempts INT DEFAULT 0 COMMENT 'Failed login attempt counter',
ADD COLUMN IF NOT EXISTS account_locked_until TIMESTAMP NULL COMMENT 'Account lockout expiry time',
ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When password was last changed',
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation time',
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last account update';

-- Add indexes for performance
ALTER TABLE admin_users 
ADD INDEX IF NOT EXISTS idx_username (username),
ADD INDEX IF NOT EXISTS idx_active (is_active),
ADD INDEX IF NOT EXISTS idx_last_login (last_login);

ALTER TABLE team_leaders
ADD INDEX IF NOT EXISTS idx_username (username),
ADD INDEX IF NOT EXISTS idx_active (is_active),
ADD INDEX IF NOT EXISTS idx_admin_id (admin_id),
ADD INDEX IF NOT EXISTS idx_leader_id (leader_id);

ALTER TABLE final_call_logs
ADD INDEX IF NOT EXISTS idx_batch_id (batch_id),
ADD INDEX IF NOT EXISTS idx_finqy_id (finqy_id),
ADD INDEX IF NOT EXISTS idx_mobile_no (mobile_no),
ADD INDEX IF NOT EXISTS idx_processed_at (processed_at);

-- Create security logging table
CREATE TABLE IF NOT EXISTS security_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL COMMENT 'login_attempt, logout, password_change, etc.',
    user_type VARCHAR(20) NOT NULL COMMENT 'admin, team_leader, telecaller, superadmin',
    user_id VARCHAR(50) NOT NULL COMMENT 'User identifier',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP address of user',
    user_agent TEXT COMMENT 'Browser user agent',
    success BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether the action was successful',
    details JSON COMMENT 'Additional event details',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_event_type (event_type),
    INDEX idx_user_type (user_type),
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_created_at (created_at),
    INDEX idx_success (success)
) ENGINE=InnoDB COMMENT='Security event logging for audit purposes';

-- Create rate limiting table
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(100) NOT NULL COMMENT 'IP address or user ID',
    action_type VARCHAR(50) NOT NULL COMMENT 'login, api_call, upload, etc.',
    attempt_count INT DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP NULL COMMENT 'When the block expires',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_rate_limit (identifier, action_type),
    INDEX idx_identifier (identifier),
    INDEX idx_action_type (action_type),
    INDEX idx_window_start (window_start),
    INDEX idx_blocked_until (blocked_until)
) ENGINE=InnoDB COMMENT='Rate limiting tracking';

-- Create session management table
CREATE TABLE IF NOT EXISTS active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL UNIQUE,
    user_type VARCHAR(20) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_session_id (session_id),
    INDEX idx_user (user_type, user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB COMMENT='Active session tracking for multi-device login prevention';

-- Update existing superadmin password to use proper hashing
-- This will be automatically done by the login system, but we can prepare it here
-- UPDATE admin_users SET password = '$2y$10$example_hash_here' WHERE designation = 'superadmin' AND password IN ('superadmin@123', 'superadmin123');

-- Create stored procedure for security event logging
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS LogSecurityEvent(
    IN p_event_type VARCHAR(50),
    IN p_user_type VARCHAR(20),
    IN p_user_id VARCHAR(50),
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT,
    IN p_success BOOLEAN,
    IN p_details JSON
)
BEGIN
    INSERT INTO security_events (event_type, user_type, user_id, ip_address, user_agent, success, details)
    VALUES (p_event_type, p_user_type, p_user_id, p_ip_address, p_user_agent, p_success, p_details);
    
    -- Clean old events (keep only last 30 days)
    DELETE FROM security_events 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
END //
DELIMITER ;

-- Create stored procedure for rate limiting
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS CheckRateLimit(
    IN p_identifier VARCHAR(100),
    IN p_action_type VARCHAR(50),
    IN p_max_attempts INT,
    IN p_window_minutes INT,
    OUT p_allowed BOOLEAN,
    OUT p_remaining_attempts INT
)
BEGIN
    DECLARE v_current_count INT DEFAULT 0;
    DECLARE v_window_start TIMESTAMP;
    
    -- Check existing rate limit record
    SELECT attempt_count, window_start INTO v_current_count, v_window_start
    FROM rate_limits 
    WHERE identifier = p_identifier AND action_type = p_action_type
    AND window_start > DATE_SUB(NOW(), INTERVAL p_window_minutes MINUTE);
    
    IF v_current_count IS NULL THEN
        -- First attempt in this window
        INSERT INTO rate_limits (identifier, action_type, attempt_count, window_start)
        VALUES (p_identifier, p_action_type, 1, NOW())
        ON DUPLICATE KEY UPDATE 
            attempt_count = 1, 
            window_start = NOW(),
            blocked_until = NULL;
        
        SET p_allowed = TRUE;
        SET p_remaining_attempts = p_max_attempts - 1;
    ELSEIF v_current_count >= p_max_attempts THEN
        -- Rate limit exceeded
        UPDATE rate_limits 
        SET blocked_until = DATE_ADD(v_window_start, INTERVAL p_window_minutes MINUTE)
        WHERE identifier = p_identifier AND action_type = p_action_type;
        
        SET p_allowed = FALSE;
        SET p_remaining_attempts = 0;
    ELSE
        -- Increment attempt count
        UPDATE rate_limits 
        SET attempt_count = attempt_count + 1
        WHERE identifier = p_identifier AND action_type = p_action_type;
        
        SET p_allowed = TRUE;
        SET p_remaining_attempts = p_max_attempts - (v_current_count + 1);
    END IF;
END //
DELIMITER ;

-- Create cleanup procedure for old data
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS CleanupOldData()
BEGIN
    -- Clean old security events (older than 30 days)
    DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Clean expired sessions
    DELETE FROM active_sessions WHERE expires_at < NOW();
    
    -- Clean old rate limit records (older than 24 hours)
    DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
    -- Clean old team leader login logs (older than 90 days)
    DELETE FROM team_leader_logins WHERE login_time < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- Clean old view logs (older than 90 days) 
    DELETE FROM team_leader_view_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);
END //
DELIMITER ;

-- Set up event scheduler for automatic cleanup (if enabled)
-- SET GLOBAL event_scheduler = ON;
-- 
-- CREATE EVENT IF NOT EXISTS daily_cleanup
-- ON SCHEDULE EVERY 1 DAY
-- STARTS CURRENT_TIMESTAMP
-- DO CALL CleanupOldData();

-- Add database configuration validation
DELIMITER //
CREATE FUNCTION IF NOT EXISTS ValidateConfig() RETURNS BOOLEAN
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE config_valid BOOLEAN DEFAULT TRUE;
    DECLARE admin_count INT DEFAULT 0;
    
    -- Check if we have at least one active superadmin
    SELECT COUNT(*) INTO admin_count 
    FROM admin_users 
    WHERE designation = 'superadmin' AND is_active = 1;
    
    IF admin_count = 0 THEN
        SET config_valid = FALSE;
    END IF;
    
    RETURN config_valid;
END //
DELIMITER ;

FLUSH PRIVILEGES;

-- Display setup completion message
SELECT 
    'Production database setup completed!' as Status,
    DATABASE() as CurrentDatabase,
    USER() as CurrentUser,
    NOW() as SetupTime;

-- Show validation results
SELECT 
    CASE WHEN ValidateConfig() = 1 
         THEN '✓ Configuration Valid' 
         ELSE '✗ Configuration Issues Found' 
    END as ValidationResult;