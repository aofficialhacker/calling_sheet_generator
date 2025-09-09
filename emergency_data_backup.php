<?php
/**
 * Emergency Data Backup Script
 * Backs up all existing data to call_history table before any updates
 */

require_once 'db_config.php';

if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isSuperadmin()) {
    die("Error: Only superadmin can run emergency backup.");
}

$conn = getDBConnection();

echo "<!DOCTYPE html><html><head><title>Emergency Data Backup</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style></head><body>";
echo "<h1>🚨 Emergency Data Backup</h1>";

try {
    // Step 1: Check existing data
    $existing_data = $conn->query("SELECT COUNT(*) FROM final_call_logs WHERE processed_at IS NOT NULL AND finqy_id IS NOT NULL")->fetch_row()[0];
    $history_data = $conn->query("SELECT COUNT(*) FROM call_history")->fetch_row()[0];
    
    echo "<div class='info'>Records in final_call_logs: " . number_format($existing_data) . "</div>";
    echo "<div class='info'>Records in call_history: " . number_format($history_data) . "</div>";
    
    if ($existing_data > 0 && $history_data == 0) {
        echo "<h3>Creating Emergency Backup...</h3>";
        
        // Get all records that need backup
        $backup_query = "
            SELECT id, finqy_id, batch_id, slot, disposition, connectivity, processed_at
            FROM final_call_logs 
            WHERE processed_at IS NOT NULL AND finqy_id IS NOT NULL
        ";
        
        $result = $conn->query($backup_query);
        $backed_up = 0;
        
        // Insert each record individually to avoid SQL errors
        $insert_stmt = $conn->prepare("
            INSERT INTO call_history (
                original_record_id, finqy_id, attempt_number, batch_id,
                slot, disposition, connectivity, attempt_date, is_original_attempt
            ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, TRUE)
        ");
        
        while ($row = $result->fetch_assoc()) {
            $insert_stmt->bind_param("ssssisss",
                $row['id'],
                $row['finqy_id'], 
                $row['batch_id'],
                $row['slot'],
                $row['disposition'],
                $row['connectivity'],
                $row['processed_at']
            );
            
            if ($insert_stmt->execute()) {
                $backed_up++;
            }
        }
        
        $insert_stmt->close();
        
        echo "<div class='success'>✅ Backed up $backed_up records to call_history</div>";
        
        // Update tracking fields
        $conn->query("
            UPDATE final_call_logs 
            SET data_backup_confirmed = TRUE, total_attempts = 1
            WHERE processed_at IS NOT NULL AND finqy_id IS NOT NULL
        ");
        
        echo "<div class='success'>✅ Updated tracking fields</div>";
        
    } else if ($history_data > 0) {
        echo "<div class='info'>✅ Data is already backed up</div>";
    } else {
        echo "<div class='info'>ℹ No data needs backup</div>";
    }
    
    // Verify the backup
    $final_history_count = $conn->query("SELECT COUNT(*) FROM call_history")->fetch_row()[0];
    echo "<div class='success'><h3>Backup Complete!</h3></div>";
    echo "<div class='success'>Total records in call_history: " . number_format($final_history_count) . "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Backup Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$conn->close();

echo "<p><a href='simple_preservation_test.php'>→ Test System Now</a></p>";
echo "</body></html>";
?>