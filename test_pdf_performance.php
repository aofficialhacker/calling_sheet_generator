<?php
// Performance test for PDF generation fixes
require_once 'db_config.php';

// Check admin session for security
session_start();
if (!isAdmin() && !isSuperadmin()) {
    die("Access denied. Admin login required.");
}

echo "<!DOCTYPE html><html><head><title>PDF Performance Test</title></head><body>";
echo "<h2>PDF Generation Performance Test</h2>";

try {
    $conn = getDBConnection();
    $adminId = $_SESSION['admin_id'];
    
    // Test data size analysis
    $sizeQuery = "SELECT 
        COUNT(*) as total_records,
        COUNT(DISTINCT batch_id) as total_batches,
        MIN(created_at) as oldest_record,
        MAX(created_at) as newest_record
        FROM final_call_logs fcl 
        JOIN file_batches fb ON fcl.batch_id = fb.id 
        WHERE fb.admin_id = ?";
    
    $stmt = $conn->prepare($sizeQuery);
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    echo "<h3>📊 Data Analysis</h3>";
    echo "<ul>";
    echo "<li><strong>Total Records:</strong> " . number_format($stats['total_records']) . "</li>";
    echo "<li><strong>Total Batches:</strong> " . number_format($stats['total_batches']) . "</li>";
    echo "<li><strong>Date Range:</strong> " . $stats['oldest_record'] . " to " . $stats['newest_record'] . "</li>";
    echo "</ul>";
    
    // Test different batch sizes for performance
    echo "<h3>📈 Performance Recommendations</h3>";
    
    if ($stats['total_records'] > 20000) {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0;'>";
        echo "<strong>⚠️ Large Dataset Warning:</strong><br>";
        echo "Your dataset has " . number_format($stats['total_records']) . " records. ";
        echo "PDF generation has been optimized with the following limits:<br>";
        echo "<ul>";
        echo "<li>Maximum 10,000 records per PDF (configurable)</li>";
        echo "<li>1,000 records processed per chunk</li>";
        echo "<li>5-minute timeout limit</li>";
        echo "<li>Memory monitoring and cleanup</li>";
        echo "</ul>";
        echo "</div>";
    }
    
    // Test small batch
    $testBatches = [];
    $batchQuery = "SELECT fb.id, fb.batch_name, COUNT(fcl.id) as record_count 
                   FROM file_batches fb 
                   LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
                   WHERE fb.admin_id = ? 
                   GROUP BY fb.id, fb.batch_name 
                   ORDER BY record_count ASC 
                   LIMIT 3";
    
    $stmt = $conn->prepare($batchQuery);
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h3>🧪 Test Batches (Recommended for Testing)</h3>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr><th>Batch ID</th><th>Batch Name</th><th>Records</th><th>Test PDF</th><th>Expected Time</th></tr>";
    
    while ($batch = $result->fetch_assoc()) {
        $recordCount = $batch['record_count'];
        $expectedTime = "< 30 seconds";
        $recommendation = "✅ Fast";
        
        if ($recordCount > 5000) {
            $expectedTime = "1-2 minutes";
            $recommendation = "⚠️ Medium";
        } elseif ($recordCount > 10000) {
            $expectedTime = "2-5 minutes";
            $recommendation = "🔶 Slow";
        }
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($batch['id']) . "</td>";
        echo "<td>" . htmlspecialchars($batch['batch_name']) . "</td>";
        echo "<td>" . number_format($recordCount) . "</td>";
        echo "<td><a href='generate_pdf.php?batch_id=" . urlencode($batch['id']) . "' target='_blank'>Generate PDF</a></td>";
        echo "<td>$expectedTime $recommendation</td>";
        echo "</tr>";
    }
    echo "</table>";
    $stmt->close();
    
    echo "<h3>⚡ Performance Optimizations Applied</h3>";
    echo "<ul>";
    echo "<li><strong>Chunked Processing:</strong> Data is processed in 1,000 record chunks</li>";
    echo "<li><strong>Memory Management:</strong> Automatic garbage collection and memory monitoring</li>";
    echo "<li><strong>Timeout Protection:</strong> 5-minute maximum execution time</li>";
    echo "<li><strong>Record Limits:</strong> Maximum 10,000 records per PDF to prevent browser timeouts</li>";
    echo "<li><strong>Error Handling:</strong> Graceful handling of memory/timeout issues</li>";
    echo "<li><strong>Progress Tracking:</strong> Internal logging for debugging</li>";
    echo "</ul>";
    
    echo "<h3>💡 Usage Tips</h3>";
    echo "<ul>";
    echo "<li><strong>For Large Datasets:</strong> Use disposition filters to reduce record count</li>";
    echo "<li><strong>Batch-wise Generation:</strong> Generate PDFs for individual batches rather than all data</li>";
    echo "<li><strong>Date Filtering:</strong> Use date ranges to limit data scope</li>";
    echo "<li><strong>Browser Timeout:</strong> If download doesn't start in 5 minutes, try smaller data set</li>";
    echo "</ul>";
    
    // Memory usage info
    $memoryUsage = memory_get_usage(true) / 1024 / 1024;
    $memoryLimit = ini_get('memory_limit');
    
    echo "<h3>🖥️ Current System Status</h3>";
    echo "<ul>";
    echo "<li><strong>Memory Usage:</strong> " . round($memoryUsage, 2) . " MB</li>";
    echo "<li><strong>Memory Limit:</strong> $memoryLimit</li>";
    echo "<li><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . " seconds</li>";
    echo "<li><strong>PHP Version:</strong> " . PHP_VERSION . "</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;'>";
    echo "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>Note:</strong> The PDF generation has been optimized to handle large datasets efficiently. ";
echo "If you experience any issues, try generating PDFs for smaller batches or use filters to reduce the data size.</p>";
echo "</body></html>";
?>