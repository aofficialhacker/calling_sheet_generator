-- Database optimization for 10,000+ row PDF generation
-- Run this SQL script to optimize database performance

-- Add indexes for faster PDF generation queries
ALTER TABLE final_call_logs ADD INDEX IF NOT EXISTS idx_batch_id (batch_id);
ALTER TABLE final_call_logs ADD INDEX IF NOT EXISTS idx_disposition (disposition);
ALTER TABLE final_call_logs ADD INDEX IF NOT EXISTS idx_batch_disposition (batch_id, disposition);
ALTER TABLE final_call_logs ADD INDEX IF NOT EXISTS idx_id_batch (id, batch_id);

-- Optimize table for MyISAM if not already InnoDB
-- ALTER TABLE final_call_logs ENGINE=InnoDB;

-- Optimize MySQL settings for bulk operations (add to my.cnf)
-- innodb_buffer_pool_size = 1G
-- key_buffer_size = 512M
-- sort_buffer_size = 2M
-- read_buffer_size = 2M
-- max_allowed_packet = 64M
-- tmp_table_size = 64M
-- max_heap_table_size = 64M

-- For immediate session optimization (already added to PHP code):
SET SESSION sql_buffer_result = 1;
SET SESSION sort_buffer_size = 2097152;
SET SESSION read_buffer_size = 2097152;