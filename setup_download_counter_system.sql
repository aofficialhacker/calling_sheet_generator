-- Download Counter Management System Setup
-- This SQL creates the necessary tables for tracking download limits and usage

-- Add download_limit column to admin_users table (default 5 downloads per disposition per batch)
ALTER TABLE admin_users 
ADD COLUMN download_limit INT DEFAULT 5 COMMENT 'Maximum downloads allowed per disposition per batch';

-- Create table to track download usage
CREATE TABLE IF NOT EXISTS download_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(10) NOT NULL,
    disposition VARCHAR(100) NOT NULL,
    batch_id VARCHAR(50) NULL COMMENT 'NULL means all batches restriction',
    product_code VARCHAR(50) NULL,
    caller_id VARCHAR(50) NULL,
    download_count INT DEFAULT 0,
    first_download_at TIMESTAMP NULL,
    last_download_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE,
    UNIQUE KEY unique_tracking (admin_id, disposition, batch_id, product_code, caller_id),
    INDEX idx_admin_disposition (admin_id, disposition),
    INDEX idx_batch_tracking (batch_id),
    INDEX idx_product_tracking (product_code)
) COMMENT = 'Tracks download usage against limits set by superadmin';

-- Create table for superadmin to manage admin download limits
CREATE TABLE IF NOT EXISTS admin_download_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(10) NOT NULL,
    download_limit INT NOT NULL DEFAULT 5,
    set_by_superadmin VARCHAR(50) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE,
    UNIQUE KEY unique_admin_limit (admin_id)
) COMMENT = 'Superadmin managed download limits per admin';

-- Insert default limits for existing admins
INSERT INTO admin_download_limits (admin_id, download_limit, set_by_superadmin, notes)
SELECT admin_id, 5, 'system_default', 'Initial system setup - default limit'
FROM admin_users 
WHERE admin_id NOT IN (SELECT admin_id FROM admin_download_limits);

-- Update admin_users table with the limits from admin_download_limits
UPDATE admin_users au
JOIN admin_download_limits adl ON au.admin_id = adl.admin_id
SET au.download_limit = adl.download_limit;