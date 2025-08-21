<?php
// Debug PDF generation with a real test
require_once 'db_config.php';

// Start session properly
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isAdmin() && !isSuperadmin()) {
    die("Access denied. Please login as admin first.");
}

echo "<!DOCTYPE html><html><head><title>PDF Generation Debug</title></head><body>";
echo "<h2>🔧 PDF Generation Debug Tool</h2>";

try {
    $conn = getDBConnection();
    $adminId = $_SESSION['admin_id'];
    
    echo "<h3>📊 System Check</h3>";
    
    // Check for available batches
    $batchQuery = "SELECT fb.id, fb.batch_name, COUNT(fcl.id) as record_count 
                   FROM file_batches fb 
                   LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
                   WHERE fb.admin_id = ? 
                   GROUP BY fb.id, fb.batch_name 
                   ORDER BY record_count ASC 
                   LIMIT 5";
    
    $stmt = $conn->prepare($batchQuery);
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($batches)) {
        echo "<p>❌ No batches found for your admin account.</p>";
        echo "<p>Please upload some data first.</p>";
    } else {
        echo "<p>✅ Found " . count($batches) . " batches for testing</p>";
        
        echo "<h3>🧪 Test PDF Generation</h3>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Batch ID</th><th>Batch Name</th><th>Records</th><th>Action</th></tr>";
        
        foreach ($batches as $batch) {
            $recordCount = $batch['record_count'];
            $batchId = htmlspecialchars($batch['id']);
            $batchName = htmlspecialchars($batch['batch_name']);
            
            echo "<tr>";
            echo "<td>$batchId</td>";
            echo "<td>$batchName</td>";
            echo "<td>" . number_format($recordCount) . "</td>";
            
            if ($recordCount > 0) {
                echo "<td>";
                echo "<button onclick=\"testPDF('$batchId')\">🔍 Debug PDF</button> ";
                echo "<a href='generate_pdf.php?batch_id=$batchId' target='_blank'>📄 Generate PDF</a>";
                echo "</td>";
            } else {
                echo "<td>No records</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<h3>📝 Error Logs</h3>";
    echo "<div id='logOutput' style='background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto;'>";
    
    $logFile = ini_get('error_log');
    if ($logFile && file_exists($logFile)) {
        $logs = file_get_contents($logFile);
        $pdfLogs = array_filter(explode("\n", $logs), function($line) {
            return strpos($line, 'PDF Generation:') !== false;
        });
        
        if (!empty($pdfLogs)) {
            echo "<pre>" . htmlspecialchars(implode("\n", array_slice($pdfLogs, -20))) . "</pre>";
        } else {
            echo "<p>No PDF generation logs found</p>";
        }
    } else {
        echo "<p>Error log not accessible</p>";
    }
    
    echo "</div>";
    
    echo "<h3>⚙️ Configuration</h3>";
    echo "<ul>";
    echo "<li><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</li>";
    echo "<li><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . " seconds</li>";
    echo "<li><strong>PHP Version:</strong> " . PHP_VERSION . "</li>";
    echo "<li><strong>TCPDF Available:</strong> " . (class_exists('TCPDF') ? "✅ Yes" : "❌ No") . "</li>";
    echo "</ul>";
    
    // JavaScript for testing
    echo "<script>";
    echo "function testPDF(batchId) {";
    echo "  const logDiv = document.getElementById('logOutput');";
    echo "  logDiv.innerHTML = '<p>🔄 Testing PDF generation for batch ' + batchId + '...</p>';";
    echo "  ";
    echo "  // Try to generate PDF and check for success";
    echo "  const testWindow = window.open('generate_pdf.php?batch_id=' + batchId, '_blank');";
    echo "  ";
    echo "  // Set a timeout to check if download started";
    echo "  setTimeout(function() {";
    echo "    if (testWindow && !testWindow.closed) {";
    echo "      logDiv.innerHTML += '<p>✅ PDF generation appears to be working - download window opened</p>';";
    echo "    } else {";
    echo "      logDiv.innerHTML += '<p>❌ PDF generation may have failed - check server logs</p>';";
    echo "    }";
    echo "  }, 3000);";
    echo "}";
    echo "</script>";
    
} catch (Exception $e) {
    echo "<div style='background: #ffe6e6; padding: 10px; border: 1px solid #ff9999;'>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "<hr>";
echo "<h3>💡 Troubleshooting Tips</h3>";
echo "<ul>";
echo "<li>If PDF generation hangs, check the error logs above</li>";
echo "<li>Try generating PDFs for smaller batches first</li>";
echo "<li>Ensure you're using a modern browser with good PDF support</li>";
echo "<li>Check that pop-up blockers aren't preventing the download</li>";
echo "<li>Monitor memory and execution time limits</li>";
echo "</ul>";

echo "</body></html>";
?>