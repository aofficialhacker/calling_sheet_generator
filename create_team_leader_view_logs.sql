-- Create team_leader_view_logs table if it doesn't exist
CREATE TABLE IF NOT EXISTS team_leader_view_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leader_id VARCHAR(20) NOT NULL,
    lead_id VARCHAR(50) NOT NULL,
    customer_name VARCHAR(255),
    mobile_number VARCHAR(15),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    session_id VARCHAR(255),
    FOREIGN KEY (leader_id) REFERENCES team_leaders(leader_id),
    FOREIGN KEY (lead_id) REFERENCES final_call_logs(id),
    INDEX idx_leader_timestamp (leader_id, timestamp),
    INDEX idx_timestamp (timestamp)
);