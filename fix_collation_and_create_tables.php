<?php
/**
 * Fix Database Collation Issues and Create Missing Tables
 * Resolves collation mismatch errors and ensures all tables exist
 */

require_once 'db_config.php';

if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isAdmin() && !isSuperadmin()) {
    die("Error: Only admin/superadmin can run database fixes.");
}

$conn = getDBConnection();

echo "<!DOCTYPE html><html><head><title>Database Collation Fix</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style></head><body>";
echo "<h1>🔧 Database Collation Fix & Table Creation</h1>";

try {
    // Step 1: Check current database collation
    echo "<h3>Step 1: Checking Database Collation</h3>";
    $db_info = $conn->query("SELECT @@character_set_database, @@collation_database")->fetch_row();
    echo "<div class='info'>Current database collation: {$db_info[1]}</div>";
    
    // Step 2: Set consistent collation for session
    echo "<h3>Step 2: Setting Session Collation</h3>";
    $conn->query("SET collation_connection = utf8mb4_unicode_ci");
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<div class='success'>✓ Session collation set to utf8mb4_unicode_ci</div>";
    
    // Step 3: Check if call_history table exists
    echo "<h3>Step 3: Checking Tables</h3>";
    $table_check = $conn->query("SHOW TABLES LIKE 'call_history'");
    
    if ($table_check && $table_check->num_rows > 0) {
        echo "<div class='info'>call_history table already exists</div>";
        
        // Check and fix table collation
        $table_info = $conn->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'caller_sheet3' AND TABLE_NAME = 'call_history'")->fetch_row();
        echo "<div class='info'>call_history table collation: {$table_info[0]}</div>";
        
        if ($table_info[0] !== 'utf8mb4_unicode_ci') {
            echo "<div class='info'>Fixing table collation...</div>";
            $conn->query("ALTER TABLE call_history CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "<div class='success'>✓ call_history table collation fixed</div>";
        }
        
    } else {
        echo "<div class='info'>Creating call_history table...</div>";
        
        // Create call_history table with proper collation
        $create_table_sql = "
        CREATE TABLE call_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_record_id VARCHAR(50) NOT NULL COLLATE utf8mb4_unicode_ci,
            finqy_id VARCHAR(50) NOT NULL COLLATE utf8mb4_unicode_ci,
            attempt_number INT NOT NULL DEFAULT 1,
            batch_id VARCHAR(50) NOT NULL COLLATE utf8mb4_unicode_ci,
            disposition VARCHAR(50) NULL COLLATE utf8mb4_unicode_ci,
            slot INT NULL,
            connectivity VARCHAR(10) NULL COLLATE utf8mb4_unicode_ci,
            attempt_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes TEXT NULL COLLATE utf8mb4_unicode_ci,
            is_original_attempt BOOLEAN DEFAULT FALSE,
            INDEX idx_original_record (original_record_id),
            INDEX idx_finqy_id (finqy_id),
            INDEX idx_batch_id (batch_id),
            INDEX idx_attempt_date (attempt_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        if ($conn->query($create_table_sql)) {
            echo "<div class='success'>✓ call_history table created successfully</div>";
        } else {
            echo "<div class='error'>❌ Error creating table: " . $conn->error . "</div>";
        }
    }
    
    // Step 4: Check and fix all related tables' collation
    echo "<h3>Step 4: Checking Related Tables</h3>";
    
    $tables_to_fix = ['final_call_logs', 'file_batches', 'callers'];
    
    foreach ($tables_to_fix as $table) {
        $table_info = $conn->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'caller_sheet3' AND TABLE_NAME = '$table'");
        if ($table_info && $table_info->num_rows > 0) {
            $collation = $table_info->fetch_row()[0];
            echo "<div class='info'>$table table collation: {$collation}</div>";
            
            if ($collation !== 'utf8mb4_unicode_ci') {
                echo "<div class='info'>Fixing $table table collation...</div>";
                $conn->query("ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                echo "<div class='success'>✓ $table table collation fixed</div>";
            } else {
                echo "<div class='success'>✓ $table table collation is already correct</div>";
            }
        } else {
            echo "<div class='info'>$table table not found or not accessible</div>";
        }
    }
    
    // Step 5: Add missing columns to final_call_logs if they don't exist
    echo "<h3>Step 5: Adding Missing Tracking Columns</h3>";
    
    $tracking_columns = [
        'data_backup_confirmed' => 'BOOLEAN DEFAULT FALSE',
        'total_attempts' => 'INT DEFAULT 1',
        'first_attempt_date' => 'DATETIME NULL',
        'original_caller_id' => 'VARCHAR(50) NULL COLLATE utf8mb4_unicode_ci',
        'last_updated_by' => 'VARCHAR(50) NULL COLLATE utf8mb4_unicode_ci',
        'is_redistributed' => 'BOOLEAN DEFAULT FALSE',
        'redistribution_count' => 'INT DEFAULT 0'
    ];
    
    foreach ($tracking_columns as $column => $definition) {
        $column_check = $conn->query("SHOW COLUMNS FROM final_call_logs LIKE '$column'");
        if ($column_check->num_rows == 0) {
            $add_column_sql = "ALTER TABLE final_call_logs ADD COLUMN $column $definition";
            if ($conn->query($add_column_sql)) {
                echo "<div class='success'>✓ Added column: $column</div>";
            } else {
                echo "<div class='error'>❌ Error adding column $column: " . $conn->error . "</div>";
            }
        } else {
            echo "<div class='info'>Column $column already exists</div>";
        }
    }
    
    // Step 6: Verify everything is working
    echo "<h3>Step 6: Final Verification</h3>";
    
    // Test query with proper collation
    $test_query = "
        SELECT COUNT(*) as total_records
        FROM final_call_logs fcl
        WHERE fcl.processed_at IS NOT NULL
    ";
    
    $test_result = $conn->query($test_query);
    if ($test_result) {
        $total = $test_result->fetch_assoc()['total_records'];
        echo "<div class='success'>✓ Database queries working properly</div>";
        echo "<div class='info'>Total processed records: $total</div>";
    }
    
    echo "<div class='success'><h3>🎉 Database Fix Complete!</h3></div>";
    echo "<div class='info'>You can now access the analytics dashboards:</div>";
    echo "<ul>";
    echo "<li><a href='admin_call_analytics.php'>Admin Call Analytics</a></li>";
    echo "<li><a href='caller_performance.php'>Caller Performance Dashboard</a></li>";
    echo "<li><a href='system_status_summary.php'>System Status Summary</a></li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<div class='error'><h2>❌ Fix Error</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}

$conn->close();
echo "</body></html>";
?>