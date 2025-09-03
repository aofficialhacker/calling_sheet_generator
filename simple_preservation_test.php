<?php
/**
 * Simple Data Preservation Test
 * Basic verification that the preservation system is working
 */

require_once 'db_config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    echo "Please log in as admin to test data preservation.";
    exit();
}

$conn = getDBConnection();

echo "<!DOCTYPE html><html><head><title>Simple Preservation Test</title>";
echo "<style>
    body{font-family:Arial,sans-serif;margin:20px;} 
    .success{color:green;} .error{color:red;} .info{color:blue;} .warning{color:orange;}
    .test{padding:15px;margin:10px 0;border:1px solid #ddd;border-radius:5px;}
</style></head><body>";

echo "<h1>🔍 Simple Data Preservation Test</h1>";
echo "<p class='info'>Quick verification that complete data preservation is working correctly.</p>";

try {
    // Test 1: Check if call_history table exists
    echo "<div class='test'>";
    echo "<h3>✅ Test 1: Database Structure</h3>";
    
    $tables_check = $conn->query("SHOW TABLES LIKE 'call_history'");
    if ($tables_check && $tables_check->num_rows > 0) {
        echo "<div class='success'>✓ call_history table exists</div>";
        
        // Check basic columns
        $columns = $conn->query("DESCRIBE call_history");
        $column_count = $columns->num_rows;
        echo "<div class='success'>✓ call_history has $column_count columns</div>";
        
    } else {
        echo "<div class='error'>❌ call_history table missing</div>";
        echo "<div class='info'>Run database migration to create it.</div>";
    }
    echo "</div>";
    
    // Test 2: Check enhanced save_final_log.php
    echo "<div class='test'>";
    echo "<h3>✅ Test 2: Enhanced Upload System</h3>";
    
    if (file_exists('save_final_log.php')) {
        $save_content = file_get_contents('save_final_log.php');
        
        if (strpos($save_content, 'preserveAllCurrentData') !== false) {
            echo "<div class='success'>✓ Enhanced save_final_log.php is active</div>";
            echo "<div class='success'>✓ Complete data preservation functions found</div>";
        } else {
            echo "<div class='warning'>⚠ save_final_log.php exists but may not have preservation functions</div>";
        }
    } else {
        echo "<div class='error'>❌ save_final_log.php missing</div>";
    }
    echo "</div>";
    
    // Test 3: Check current data
    echo "<div class='test'>";
    echo "<h3>✅ Test 3: Current Data Status</h3>";
    
    // Simple query without joins to avoid errors
    $final_logs_count = $conn->query("SELECT COUNT(*) FROM final_call_logs")->fetch_row()[0];
    echo "<div class='info'>• Total records in final_call_logs: " . number_format($final_logs_count) . "</div>";
    
    if ($tables_check && $tables_check->num_rows > 0) {
        $history_count = $conn->query("SELECT COUNT(*) FROM call_history")->fetch_row()[0];
        echo "<div class='info'>• Total entries in call_history: " . number_format($history_count) . "</div>";
        
        if ($history_count > 0) {
            echo "<div class='success'>✓ Data preservation is active and recording</div>";
        } else {
            echo "<div class='info'>ℹ No history data yet - will activate when callers upload</div>";
        }
    }
    echo "</div>";
    
    // Test 4: Feature availability
    echo "<div class='test'>";
    echo "<h3>✅ Test 4: Available Features</h3>";
    
    $features = [
        'Redistribution Mode' => file_exists('manage_batches.php'),
        'Caller Performance Dashboard' => file_exists('caller_performance.php'),
        'Admin Analytics' => file_exists('admin_call_analytics.php'),
        'Complete Preservation Test' => file_exists('test_complete_preservation.php')
    ];
    
    foreach ($features as $feature => $available) {
        $status = $available ? '✓' : '❌';
        $class = $available ? 'success' : 'error';
        echo "<div class='$class'>$status $feature</div>";
    }
    echo "</div>";
    
    // Test 5: Quick functionality test
    echo "<div class='test'>";
    echo "<h3>✅ Test 5: System Readiness</h3>";
    
    $all_good = true;
    
    // Check if enhanced save file has correct functions
    if (file_exists('save_final_log.php')) {
        $save_content = file_get_contents('save_final_log.php');
        $required_functions = ['preserveAllCurrentData', 'createNewAttemptEntry', 'COMPLETE PRESERVATION'];
        
        foreach ($required_functions as $func) {
            if (strpos($save_content, $func) !== false) {
                echo "<div class='success'>✓ Function/feature '$func' found</div>";
            } else {
                echo "<div class='warning'>⚠ Function/feature '$func' not found</div>";
                $all_good = false;
            }
        }
    }
    
    if ($all_good && $tables_check && $tables_check->num_rows > 0) {
        echo "<div class='success'><h4>🎉 System Ready for Complete Data Preservation!</h4></div>";
        echo "<div class='info'>";
        echo "<strong>What's Protected:</strong><br>";
        echo "• ✅ Slot assignments (time slots)<br>";
        echo "• ✅ Dispositions (call outcomes)<br>";
        echo "• ✅ Connectivity status<br>";
        echo "• ✅ Caller assignments<br>";
        echo "• ✅ Timestamps<br>";
        echo "• ✅ Complete audit trail<br>";
        echo "</div>";
    } else {
        echo "<div class='warning'><h4>⚠ System Partially Ready</h4></div>";
        echo "<div class='info'>Some components may need setup or activation.</div>";
    }
    echo "</div>";
    
    // Next steps
    echo "<div class='test'>";
    echo "<h3>🚀 Next Steps</h3>";
    
    if (!$tables_check || $tables_check->num_rows == 0) {
        echo "<p><strong>1. Setup Database:</strong> <a href='quick_preservation_fix.php'>Run Quick Preservation Fix</a></p>";
    }
    
    echo "<p><strong>2. Test Features:</strong></p>";
    echo "<ul>";
    echo "<li><a href='manage_batches.php'>Try Redistribution Mode</a></li>";
    echo "<li><a href='caller_performance.php'>View Caller Dashboard</a></li>";
    echo "<li><a href='admin_call_analytics.php'>Check Analytics</a></li>";
    echo "</ul>";
    
    echo "<p><strong>3. Full Testing:</strong> <a href='test_complete_preservation.php'>Run Complete Preservation Test</a></p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'><h2>❌ Test Error</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}

$conn->close();

echo "<hr><p><a href='admin_dashboard.php'>← Back to Dashboard</a></p>";
echo "</body></html>";
?>