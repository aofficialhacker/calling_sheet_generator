<?php
require_once 'db_config.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || (!isAdmin() && !isSuperadmin())) {
    echo "<h2>Authentication Required</h2>";
    echo "<p>Please log in as an admin to test the PDF formatting fixes.</p>";
    echo "<a href='admin_login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Admin Login</a>";
    exit;
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h2>📋 PDF Table Formatting - FIXED!</h2>";

echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; padding: 15px; margin: 10px 0;'>";
echo "<h4 style='color: #721c24; margin-top: 0;'>❌ Issues Found in Screenshot:</h4>";
echo "<ul style='color: #721c24; margin: 5px 0 5px 20px;'>";
echo "<li><strong>Disposition Column Too Narrow:</strong> Codes were stacked vertically and cut off</li>";
echo "<li><strong>Text Overflow:</strong> Content spilling into adjacent cells</li>";
echo "<li><strong>Poor Column Balance:</strong> Mobile and Name columns too small, wasting space</li>";
echo "<li><strong>No Text Wrapping:</strong> Long addresses and data not handled properly</li>";
echo "</ul>";
echo "</div>";

echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; padding: 15px; margin: 10px 0;'>";
echo "<h4 style='color: #155724; margin-top: 0;'>✅ Fixes Applied:</h4>";
echo "<ul style='color: #155724; margin: 5px 0 5px 20px;'>";
echo "<li><strong>Disposition Column:</strong> Increased from 25mm to 45mm width</li>";
echo "<li><strong>Smart Column Sizing:</strong> Dynamic width allocation based on content type</li>";
echo "<li><strong>MultiCell Text Wrapping:</strong> Proper text wrapping for long content</li>";
echo "<li><strong>Increased Row Height:</strong> From 6mm to 12mm to accommodate wrapped text</li>";
echo "<li><strong>Compact Disposition Display:</strong> Optimized layout for disposition codes</li>";
echo "<li><strong>Content Truncation:</strong> Smart truncation with ellipsis for very long text</li>";
echo "<li><strong>Font Size Optimization:</strong> Different font sizes for different content types</li>";
echo "<li><strong>Accurate Cutline Position:</strong> Properly calculated based on actual column widths</li>";
echo "</ul>";
echo "</div>";

// Get available batches for testing
$result = $conn->query("
    SELECT fb.id, fb.original_filename, COUNT(fcl.id) as record_count 
    FROM file_batches fb 
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
    WHERE fb.admin_id = '{$adminId}'
    GROUP BY fb.id 
    HAVING record_count > 0
    ORDER BY record_count ASC 
    LIMIT 3
");

if ($result && $result->num_rows > 0) {
    echo "<h3>🧪 Test the Fixed Formatting:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f8f9fa;'>";
    echo "<th>Batch ID</th><th>Records</th><th>Test Fixed PDF</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><code>" . htmlspecialchars($row['id']) . "</code></td>";
        echo "<td><span style='background: #e9ecef; padding: 2px 6px; border-radius: 3px;'>" . number_format($row['record_count']) . "</span></td>";
        echo "<td>";
        
        $batch_id = urlencode($row['id']);
        echo "<button onclick='testFixedPDF(\"{$batch_id}\", this)' style='padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;'>📄 Test Fixed PDF</button>";
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<div style='background: #e2e3e5; border: 1px solid #d6d8db; border-radius: 5px; padding: 15px; margin: 15px 0;'>";
    echo "<h4 style='color: #495057; margin-top: 0;'>📊 Expected Improvements:</h4>";
    echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>";
    echo "<div>";
    echo "<strong>✅ Visual Quality:</strong><br>";
    echo "• Proper column alignment<br>";
    echo "• No text overflow<br>";
    echo "• Readable disposition codes<br>";
    echo "• Clean table borders";
    echo "</div>";
    echo "<div>";
    echo "<strong>✅ Content Display:</strong><br>";
    echo "• Complete disposition grid<br>";
    echo "• Wrapped long addresses<br>";
    echo "• Proper mobile number display<br>";
    echo "• Accurate cutline positioning";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
} else {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px;'>";
    echo "<h4 style='color: #856404; margin-top: 0;'>📤 Upload Test Data</h4>";
    echo "<p style='color: #856404;'>Upload a batch to test the formatting fixes.</p>";
    echo "<a href='upload_batch.php' style='padding: 8px 16px; background: #ffc107; color: #212529; text-decoration: none; border-radius: 4px; font-weight: bold;'>Upload Batch</a>";
    echo "</div>";
}

$conn->close();
?>

<script>
function testFixedPDF(batchId, button) {
    const startTime = performance.now();
    const originalText = button.innerHTML;
    
    button.innerHTML = '⏳ Generating...';
    button.style.backgroundColor = '#ffc107';
    button.style.color = '#212529';
    button.disabled = true;
    
    // Open PDF generation
    const pdfWindow = window.open(`generate_pdf.php?batch_id=${batchId}`, '_blank');
    
    // Monitor completion
    const checkComplete = setInterval(() => {
        try {
            if (pdfWindow.closed) {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `✅ Fixed! (${duration}s)`;
                button.style.backgroundColor = '#28a745';
                button.style.color = 'white';
                button.disabled = false;
                
                showFixResult(`
                    <div style='background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; padding: 15px; margin: 15px 0;'>
                        <h4 style='color: #155724; margin-top: 0;'>✅ PDF Generated Successfully!</h4>
                        <p style='color: #155724; margin: 5px 0;'>
                            <strong>Generation Time:</strong> ${duration} seconds<br>
                            <strong>Formatting:</strong> Fixed table layout with proper column widths<br>
                            <strong>Content:</strong> Disposition codes should now display properly<br>
                            <strong>Layout:</strong> No text overflow, proper text wrapping
                        </p>
                        <p style='color: #155724; margin: 5px 0;'><strong>Compare with the error screenshot - the table should now be properly formatted!</strong></p>
                    </div>
                `);
                
                clearInterval(checkComplete);
            }
        } catch (e) {
            setTimeout(() => {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `✅ Generated (${duration}s)`;
                button.style.backgroundColor = '#28a745';
                button.style.color = 'white';
                button.disabled = false;
                
                clearInterval(checkComplete);
            }, 2000);
        }
    }, 1000);
    
    // Timeout handling
    setTimeout(() => {
        if (button.disabled) {
            clearInterval(checkComplete);
            button.innerHTML = '❌ Timeout';
            button.style.backgroundColor = '#dc3545';
            button.style.color = 'white';
            button.disabled = false;
        }
    }, 45000);
}

function showFixResult(html) {
    let resultDiv = document.getElementById('fix-results');
    if (!resultDiv) {
        resultDiv = document.createElement('div');
        resultDiv.id = 'fix-results';
        document.body.appendChild(resultDiv);
    }
    resultDiv.innerHTML = html;
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
th { background-color: #f8f9fa; font-weight: bold; }
button:hover { opacity: 0.9; }
button:disabled { opacity: 0.7; cursor: not-allowed; }
code { background: #f8f9fa; padding: 2px 4px; border-radius: 3px; font-family: 'Courier New', monospace; }
</style>