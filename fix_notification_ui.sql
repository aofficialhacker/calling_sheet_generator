USE caller_sheet3;

-- Create table to track read notifications
CREATE TABLE IF NOT EXISTS notification_read_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leader_id VARCHAR(50) NOT NULL,
    schedule_id VARCHAR(50) NOT NULL,
    marked_read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_read (leader_id, schedule_id),
    FOREIGN KEY (leader_id) REFERENCES team_leaders(leader_id) ON DELETE CASCADE
);

-- Create index for faster lookups
CREATE INDEX idx_leader_read ON notification_read_status(leader_id, marked_read_at);

SELECT 'Notification read status table created' as status;