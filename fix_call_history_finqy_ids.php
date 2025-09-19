<?php
/**
 * Fix Call History finqy_id Values
 * Updates lv_call_history records that have finqy_id = '0' with correct values from lv_final_call_logs
 */

require_once 'db_config.php';

$conn = getDBConnection();

echo "<h2>Fixing Call History finqy_id Values</h2>\n";

// First, check current status
echo "<h3>Current Status:</h3>\n";
$status_query = "SELECT COUNT(*) as total_records, 
                 COUNT(CASE WHEN finqy_id = '0' THEN 1 END) as zero_finqy_ids,
                 COUNT(CASE WHEN finqy_id != '0' AND finqy_id != '' THEN 1 END) as valid_finqy_ids
                 FROM lv_call_history";
$result = $conn->query($status_query);
$status = $result->fetch_assoc();

echo "Total lv_call_history records: " . $status['total_records'] . "<br>\n";
echo "Records with finqy_id = '0': " . $status['zero_finqy_ids'] . "<br>\n";  
echo "Records with valid finqy_id: " . $status['valid_finqy_ids'] . "<br><br>\n";

if ($status['zero_finqy_ids'] > 0) {
    echo "<h3>Fixing Records:</h3>\n";
    
    // Get records that need fixing
    $fix_query = "SELECT ch.id, ch.original_record_id, ch.finqy_id as old_finqy_id, fcl.finqy_id as correct_finqy_id
                  FROM lv_call_history ch
                  JOIN lv_final_call_logs fcl ON CAST(ch.original_record_id AS CHAR) = CAST(fcl.id AS CHAR)
                  WHERE ch.finqy_id = '0' AND fcl.finqy_id IS NOT NULL AND fcl.finqy_id != ''";
    
    $result = $conn->query($fix_query);
    
    if ($result && $result->num_rows > 0) {
        $fixed_count = 0;
        $conn->begin_transaction();
        
        try {
            while ($row = $result->fetch_assoc()) {
                $update_sql = "UPDATE lv_call_history SET finqy_id = ? WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("si", $row['correct_finqy_id'], $row['id']);
                
                if ($stmt->execute()) {
                    $fixed_count++;
                    echo "Fixed record ID {$row['id']}: '{$row['old_finqy_id']}' -> '{$row['correct_finqy_id']}'<br>\n";
                } else {
                    echo "Failed to fix record ID {$row['id']}: " . $stmt->error . "<br>\n";
                }
                $stmt->close();
            }
            
            $conn->commit();
            echo "<br><strong>Successfully fixed $fixed_count records!</strong><br><br>\n";
            
        } catch (Exception $e) {
            $conn->rollback();
            echo "<br><strong>Error during update: " . $e->getMessage() . "</strong><br><br>\n";
        }
    } else {
        echo "No records found that can be fixed automatically.<br><br>\n";
    }
    
    // Check final status
    echo "<h3>Final Status:</h3>\n";
    $result = $conn->query($status_query);
    $final_status = $result->fetch_assoc();
    
    echo "Total lv_call_history records: " . $final_status['total_records'] . "<br>\n";
    echo "Records with finqy_id = '0': " . $final_status['zero_finqy_ids'] . "<br>\n";
    echo "Records with valid finqy_id: " . $final_status['valid_finqy_ids'] . "<br>\n";
    
    if ($final_status['zero_finqy_ids'] == 0) {
        echo "<br><strong style='color: green;'>✅ All lv_call_history records now have valid finqy_id values!</strong><br>\n";
    } else {
        echo "<br><strong style='color: orange;'>⚠️ " . $final_status['zero_finqy_ids'] . " records still have finqy_id = '0'</strong><br>\n";
        echo "These may be records where the original lv_final_call_logs entry has been deleted or has no finqy_id.<br>\n";
    }
} else {
    echo "<strong style='color: green;'>✅ All lv_call_history records already have valid finqy_id values!</strong><br>\n";
}

$conn->close();
?>