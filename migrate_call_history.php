<?php
/**
 * Database Migration Script for Call History & Redistribution System
 * Run this script once to set up the call history tracking system
 */

require_once 'db_config.php';

// Start session for admin authentication
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

// Check if user is superadmin for this migration
if (!isSuperadmin()) {
    die("Error: Only superadmin can run database migrations. Please log in as superadmin.");
}

$conn = getDBConnection();

echo "<!DOCTYPE html><html><head><title>Call History Migration</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style></head><body>";
echo "<h1>Call History & Redistribution System Migration</h1>";

$migration_success = true;

try {
    echo "<div class='info'>Starting database migration...</div><br>";
    
    // Step 1: Create lv_call_history table
    echo "<strong>Step 1: Creating lv_call_history table...</strong><br>";
    $sql_create_table = "
    CREATE TABLE IF NOT EXISTS lv_call_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_record_id VARCHAR(50) NOT NULL COMMENT 'References lv_final_call_logs.id',
        finqy_id VARCHAR(50) NOT NULL COMMENT 'Caller who made this attempt',
        attempt_number INT NOT NULL DEFAULT 1 COMMENT 'Sequential attempt counter for this record',
        batch_id VARCHAR(50) NOT NULL COMMENT 'Which batch this attempt belongs to',
        disposition VARCHAR(50) NULL COMMENT 'Disposition marked by caller',
        slot INT NULL COMMENT 'Time slot marked by caller',
        connectivity VARCHAR(10) NULL COMMENT 'Connectivity status marked',
        attempt_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this attempt was made',
        notes TEXT NULL COMMENT 'Additional notes or comments',
        is_original_attempt BOOLEAN DEFAULT FALSE COMMENT 'TRUE if this was the first attempt on this record',
        redistribution_batch_ref VARCHAR(100) NULL COMMENT 'Reference to redistribution batch if applicable',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_original_record (original_record_id),
        INDEX idx_caller (finqy_id),
        INDEX idx_batch (batch_id),
        INDEX idx_attempt_date (attempt_date),
        INDEX idx_disposition (disposition),
        
        FOREIGN KEY (batch_id) REFERENCES lv_file_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
    COMMENT='Tracks all call attempts for performance comparison and audit trail'";
    
    if ($conn->query($sql_create_table) === TRUE) {
        echo "<div class='success'>✓ lv_call_history table created successfully</div><br>";
    } else {
        throw new Exception("Error creating lv_call_history table: " . $conn->error);
    }
    
    // Step 2: Add tracking fields to lv_final_call_logs
    echo "<strong>Step 2: Adding tracking fields to lv_final_call_logs table...</strong><br>";
    
    $tracking_fields = [
        "ADD COLUMN original_caller_id VARCHAR(50) NULL COMMENT 'First caller who worked on this record' AFTER finqy_id",
        "ADD COLUMN redistribution_count INT DEFAULT 0 COMMENT 'How many times this record has been redistributed' AFTER original_caller_id",
        "ADD COLUMN last_updated_by VARCHAR(50) NULL COMMENT 'Last caller who updated this record' AFTER redistribution_count",
        "ADD COLUMN is_redistributed BOOLEAN DEFAULT FALSE COMMENT 'Whether this record has been redistributed' AFTER last_updated_by",
        "ADD COLUMN redistribution_reason VARCHAR(100) NULL COMMENT 'Reason for redistribution (Follow Up, Not Interested, etc.)' AFTER is_redistributed",
        "ADD COLUMN last_attempt_date DATETIME NULL COMMENT 'Date of last call attempt' AFTER redistribution_reason"
    ];
    
    foreach ($tracking_fields as $field_sql) {
        try {
            $conn->query("ALTER TABLE lv_final_call_logs $field_sql");
            echo "<div class='success'>✓ Added field: " . explode(' ', trim($field_sql))[2] . "</div>";
        } catch (Exception $e) {
            // Field might already exist, which is fine
            if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                echo "<div class='error'>⚠ Warning adding field: " . $e->getMessage() . "</div>";
            } else {
                echo "<div class='info'>ℹ Field already exists: " . explode(' ', trim($field_sql))[2] . "</div>";
            }
        }
    }
    echo "<br>";
    
    // Step 3: Add indexes for performance
    echo "<strong>Step 3: Adding performance indexes...</strong><br>";
    
    $indexes = [
        "ADD INDEX idx_original_caller (original_caller_id)",
        "ADD INDEX idx_last_updated_by (last_updated_by)", 
        "ADD INDEX idx_redistribution_count (redistribution_count)",
        "ADD INDEX idx_is_redistributed (is_redistributed)",
        "ADD INDEX idx_last_attempt_date (last_attempt_date)"
    ];
    
    foreach ($indexes as $index_sql) {
        try {
            $conn->query("ALTER TABLE lv_final_call_logs $index_sql");
            $index_name = explode(' ', trim($index_sql))[2];
            echo "<div class='success'>✓ Added index: $index_name</div>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "<div class='error'>⚠ Warning adding index: " . $e->getMessage() . "</div>";
            } else {
                $index_name = explode(' ', trim($index_sql))[2];
                echo "<div class='info'>ℹ Index already exists: $index_name</div>";
            }
        }
    }
    echo "<br>";
    
    // Step 4: Create views for reporting
    echo "<strong>Step 4: Creating reporting views...</strong><br>";
    
    $view1_sql = "
    CREATE OR REPLACE VIEW caller_performance_comparison AS
    SELECT 
        ch.original_record_id,
        fcl.mobile_no,
        fcl.name,
        fcl.batch_id,
        fb.product_code,
        ch.finqy_id as caller_id,
        c.caller_name,
        ch.attempt_number,
        ch.disposition,
        ch.slot,
        ch.connectivity,
        ch.attempt_date,
        ch.is_original_attempt,
        CASE 
            WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 'Positive'
            WHEN ch.disposition IN ('Not Interested', 'DND', 'Wrong Number') THEN 'Negative'
            WHEN ch.disposition IN ('Follow Up', 'Busy', 'No Response') THEN 'Follow Required'
            ELSE 'Other'
        END as disposition_category
    FROM lv_call_history ch
    JOIN lv_final_call_logs fcl ON ch.original_record_id = fcl.id
    JOIN lv_file_batches fb ON ch.batch_id = fb.id
    LEFT JOIN lv_callers c ON ch.finqy_id = c.finqy_id
    ORDER BY ch.original_record_id, ch.attempt_number";
    
    if ($conn->query($view1_sql) === TRUE) {
        echo "<div class='success'>✓ caller_performance_comparison view created</div>";
    } else {
        throw new Exception("Error creating caller_performance_comparison view: " . $conn->error);
    }
    
    $view2_sql = "
    CREATE OR REPLACE VIEW redistribution_tracking AS
    SELECT 
        fcl.id as record_id,
        fcl.mobile_no,
        fcl.name,
        fcl.batch_id,
        fb.product_code,
        fcl.original_caller_id,
        oc.caller_name as original_caller_name,
        fcl.redistribution_count,
        fcl.last_updated_by,
        lc.caller_name as last_caller_name,
        fcl.redistribution_reason,
        fcl.last_attempt_date,
        COUNT(ch.id) as total_attempts,
        GROUP_CONCAT(DISTINCT ch.disposition ORDER BY ch.attempt_date) as all_dispositions,
        GROUP_CONCAT(DISTINCT c.caller_name ORDER BY ch.attempt_date) as all_callers
    FROM lv_final_call_logs fcl
    JOIN lv_file_batches fb ON fcl.batch_id = fb.id
    LEFT JOIN lv_callers oc ON fcl.original_caller_id = oc.finqy_id
    LEFT JOIN lv_callers lc ON fcl.last_updated_by = lc.finqy_id
    LEFT JOIN lv_call_history ch ON fcl.id = ch.original_record_id
    LEFT JOIN lv_callers c ON ch.finqy_id = c.finqy_id
    WHERE fcl.is_redistributed = TRUE
    GROUP BY fcl.id
    ORDER BY fcl.redistribution_count DESC, fcl.last_attempt_date DESC";
    
    if ($conn->query($view2_sql) === TRUE) {
        echo "<div class='success'>✓ redistribution_tracking view created</div><br>";
    } else {
        throw new Exception("Error creating redistribution_tracking view: " . $conn->error);
    }
    
    // Step 5: Create stored procedure
    echo "<strong>Step 5: Creating stored procedures...</strong><br>";
    
    $procedure_sql = "
    DROP PROCEDURE IF EXISTS RedistributeRecord;
    
    DELIMITER //
    CREATE PROCEDURE RedistributeRecord(
        IN p_record_id VARCHAR(50),
        IN p_reason VARCHAR(100),
        IN p_admin_id VARCHAR(50)
    )
    BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            ROLLBACK;
            RESIGNAL;
        END;
        
        START TRANSACTION;
        
        -- Update redistribution tracking
        UPDATE lv_final_call_logs 
        SET redistribution_count = redistribution_count + 1,
            is_redistributed = TRUE,
            redistribution_reason = p_reason
        WHERE id = p_record_id;
        
        -- Log the redistribution action
        INSERT INTO lv_call_history (
            original_record_id,
            finqy_id,
            attempt_number,
            batch_id,
            notes,
            attempt_date
        )
        SELECT 
            id,
            p_admin_id,
            (SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM lv_call_history WHERE original_record_id = p_record_id),
            batch_id,
            CONCAT('Record redistributed by admin. Reason: ', p_reason),
            NOW()
        FROM lv_final_call_logs 
        WHERE id = p_record_id;
        
        COMMIT;
    END //
    DELIMITER ;";
    
    if ($conn->multi_query($procedure_sql) === TRUE) {
        // Process all results from multi_query
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->next_result());
        
        echo "<div class='success'>✓ RedistributeRecord stored procedure created</div><br>";
    } else {
        throw new Exception("Error creating stored procedure: " . $conn->error);
    }
    
    // Step 6: Migrate existing data
    echo "<strong>Step 6: Migrating existing data to lv_call_history...</strong><br>";
    
    $migrate_sql = "
    INSERT IGNORE INTO lv_call_history (
        original_record_id, 
        finqy_id, 
        attempt_number, 
        batch_id, 
        disposition, 
        slot, 
        connectivity, 
        attempt_date, 
        is_original_attempt
    )
    SELECT 
        id as original_record_id,
        COALESCE(finqy_id, 'UNKNOWN') as finqy_id,
        1 as attempt_number,
        batch_id,
        disposition,
        slot,
        connectivity,
        COALESCE(processed_at, NOW()) as attempt_date,
        TRUE as is_original_attempt
    FROM lv_final_call_logs 
    WHERE finqy_id IS NOT NULL OR disposition IS NOT NULL";
    
    if ($conn->query($migrate_sql) === TRUE) {
        $migrated_count = $conn->affected_rows;
        echo "<div class='success'>✓ Migrated $migrated_count existing records to lv_call_history</div>";
    } else {
        throw new Exception("Error migrating existing data: " . $conn->error);
    }
    
    // Step 7: Update original_caller_id for existing records
    echo "<strong>Step 7: Updating original caller tracking...</strong><br>";
    
    $update_sql = "
    UPDATE lv_final_call_logs fcl
    SET original_caller_id = fcl.finqy_id,
        last_updated_by = fcl.finqy_id,
        last_attempt_date = fcl.processed_at
    WHERE original_caller_id IS NULL 
    AND finqy_id IS NOT NULL";
    
    if ($conn->query($update_sql) === TRUE) {
        $updated_count = $conn->affected_rows;
        echo "<div class='success'>✓ Updated original caller tracking for $updated_count records</div><br>";
    } else {
        throw new Exception("Error updating original caller tracking: " . $conn->error);
    }
    
    echo "<h2 class='success'>🎉 Migration Completed Successfully!</h2>";
    echo "<div class='info'><strong>What's New:</strong><ul>";
    echo "<li>✓ Call history tracking system is now active</li>";
    echo "<li>✓ Redistribution mode available in admin batch management</li>";
    echo "<li>✓ Slot column can be blanked for fresh calling</li>";
    echo "<li>✓ Performance comparison between callers is now possible</li>";
    echo "<li>✓ Complete audit trail of all call attempts</li>";
    echo "</ul></div>";
    
    echo "<div class='info'><strong>Next Steps:</strong><ul>";
    echo "<li>1. Test the redistribution functionality with sample data</li>";
    echo "<li>2. Train admins on new redistribution mode checkbox</li>";
    echo "<li>3. Review caller performance comparison reports</li>";
    echo "<li>4. Monitor redistribution tracking for insights</li>";
    echo "</ul></div>";
    
} catch (Exception $e) {
    $migration_success = false;
    echo "<div class='error'><h2>❌ Migration Failed!</h2>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>Please check your database configuration and try again.</p></div>";
    
    // Try to rollback any partial changes if possible
    try {
        $conn->rollback();
    } catch (Exception $rollback_e) {
        // Rollback might not be available in this context
    }
} finally {
    $conn->close();
}

echo "<br><hr>";
echo "<p><a href='manage_batches.php'>← Back to Batch Management</a></p>";
echo "</body></html>";
?>