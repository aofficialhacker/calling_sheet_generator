-- Blocklist System Setup
-- Creates the necessary table for managing blocked phone numbers

USE caller_sheet3;

-- Create the blocklist_numbers table
CREATE TABLE IF NOT EXISTS blocklist_numbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(50) NOT NULL,
    mobile_no VARCHAR(15) NOT NULL,
    batch_id VARCHAR(100) NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50) NOT NULL,
    notes TEXT NULL,
    INDEX idx_admin_mobile (admin_id, mobile_no),
    INDEX idx_mobile (mobile_no),
    INDEX idx_admin_batch (admin_id, batch_id),
    UNIQUE KEY unique_admin_mobile (admin_id, mobile_no),
    FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE
);

-- Insert sample data for testing (optional)
-- INSERT INTO blocklist_numbers (admin_id, mobile_no, created_by, notes) VALUES
-- ('TEST_ADMIN', '9999999999', 'TEST_ADMIN', 'Test blocked number'),
-- ('TEST_ADMIN', '8888888888', 'TEST_ADMIN', 'Another test blocked number');