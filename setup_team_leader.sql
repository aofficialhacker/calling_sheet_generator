-- Team Leader System Setup

-- 1. Create team_leaders table
CREATE TABLE IF NOT EXISTS team_leaders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leader_id VARCHAR(20) UNIQUE NOT NULL,
    leader_name VARCHAR(100) NOT NULL,
    finqy_id VARCHAR(20) NOT NULL,
    admin_id VARCHAR(20) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    auth_token VARCHAR(255),
    token_expires TIMESTAMP NULL,
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (finqy_id) REFERENCES callers(finqy_id),
    FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id)
);

-- 2. Create team_leader_logins table for IP tracking
CREATE TABLE IF NOT EXISTS team_leader_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leader_id VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    login_status ENUM('success', 'failed') DEFAULT 'success',
    session_id VARCHAR(255),
    FOREIGN KEY (leader_id) REFERENCES team_leaders(leader_id)
);

-- 3. Create team_leader_dispositions table (managed by superadmin)
CREATE TABLE IF NOT EXISTS team_leader_dispositions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    disposition_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active BOOLEAN DEFAULT 1,
    created_by VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admin_users(admin_id)
);

-- 4. Create team_leader_actions table to track actions on interested leads
CREATE TABLE IF NOT EXISTS team_leader_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_id VARCHAR(50) UNIQUE NOT NULL,
    leader_id VARCHAR(20) NOT NULL,
    lead_id VARCHAR(50) NOT NULL,
    original_disposition VARCHAR(50),
    new_disposition VARCHAR(100),
    remarks TEXT,
    action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (leader_id) REFERENCES team_leaders(leader_id),
    FOREIGN KEY (lead_id) REFERENCES final_call_logs(id),
    FOREIGN KEY (new_disposition) REFERENCES team_leader_dispositions(disposition_name)
);

-- Insert default team leader dispositions
-- Note: Replace 'SUPER' with the actual admin_id of your superadmin from admin_users table
INSERT IGNORE INTO team_leader_dispositions (disposition_name, description, created_by) VALUES
('Not Interested', 'Customer is not interested in the product/service', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('Call Back Later', 'Customer requested to be called back later', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('Wrong Number', 'The number does not belong to the intended customer', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('No Response', 'Customer did not answer the call', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('Busy', 'Customer was busy and could not talk', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('Interested - Proceed to Payment', 'Customer is interested and ready for payment process', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('Need More Information', 'Customer needs more details before deciding', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1)),
('Already Purchased', 'Customer has already purchased similar product/service', (SELECT admin_id FROM admin_users WHERE designation = 'superadmin' LIMIT 1));