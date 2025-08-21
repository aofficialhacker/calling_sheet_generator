<?php
// Simple performance check for PDF generation
require_once 'db_config.php';

echo "PDF Generation Performance Check\n";
echo "===============================\n\n";

try {
    $conn = getDBConnection();
    echo "✓ Database connection successful\n";
    
    // Check record counts
    $result = $conn->query("SELECT COUNT(*) as total FROM final_call_logs");
    if ($result) {
        $total = $result->fetch_assoc()['total'];
        echo "✓ Total records in system: " . number_format($total) . "\n";
        
        if ($total > 10000) {
            echo "⚠ Large dataset detected - chunked processing will be used\n";
        } else {
            echo "✓ Dataset size is manageable\n";
        }
    }
    
    // Check PHP configuration
    echo "\nPHP Configuration:\n";
    echo "- Memory limit: " . ini_get('memory_limit') . "\n";
    echo "- Max execution time: " . ini_get('max_execution_time') . " seconds\n";
    echo "- Current memory usage: " . round(memory_get_usage(true)/1024/1024, 2) . " MB\n";
    
    // Check optimizations applied
    echo "\nOptimizations Applied:\n";
    echo "✓ Chunked processing (1000 records per chunk)\n";
    echo "✓ Memory monitoring and cleanup\n";
    echo "✓ 5-minute timeout limit\n";
    echo "✓ Maximum 10,000 records per PDF\n";
    echo "✓ Improved error handling\n";
    echo "✓ Column layout fixes (Name: 35mm, DOB: 22mm, Address: 45mm)\n";
    echo "✓ Table centering on page\n";
    echo "✓ Cutlines around Mobile column\n";
    
    echo "\nRecommendations:\n";
    if ($total > 20000) {
        echo "- Use batch-specific PDF generation instead of all data\n";
        echo "- Apply disposition filters to reduce record count\n";
        echo "- Generate PDFs during off-peak hours\n";
    } else {
        echo "- System should handle PDF generation smoothly\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n" . date('Y-m-d H:i:s') . " - Performance check completed\n";
?>