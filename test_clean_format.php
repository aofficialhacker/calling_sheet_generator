<?php
require_once 'db_config.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || (!isAdmin() && !isSuperadmin())) {
    echo "<h2>Authentication Required</h2>";
    echo "<p>Please log in as an admin to test the clean PDF format.</p>";
    echo "<a href='admin_login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Admin Login</a>";
    exit;
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h2>🎯 Clean PDF Format Test</h2>";
echo "<p>This version creates a clean, properly formatted calling sheet that should match your sample.</p>";

echo "<div style='background: #e3f2fd; border: 1px solid #2196f3; border-radius: 5px; padding: 15px; margin: 10px 0;'>";
echo "<h4 style='color: #1565c0; margin-top: 0;'>📋 Clean Format Features:</h4>";
echo "<ul style='color: #1565c0; margin: 5px 0 5px 20px;'>";
echo "<li><strong>Simple Table Layout:</strong> Clean rows and columns without complex formatting</li>";
echo "<li><strong>Proper Column Widths:</strong> Balanced spacing for all content</li>";
echo "<li><strong>Clear Headers:</strong> Bold headers with gray background</li>";
echo "<li><strong>Full ID Display:</strong> Complete ID numbers, no truncation</li>";
echo "<li><strong>Empty Circles:</strong> 'O Y / O N' for connectivity, 'O' for disposition codes</li>";
echo "<li><strong>Correct Cutline:</strong> Positioned after Mobile column with scissors (X)</li>";
echo "<li><strong>Readable Fonts:</strong> 9pt base font, 10pt headers</li>";
echo "<li><strong>Proper Spacing:</strong> 10mm row height for comfortable reading</li>";
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
    echo "<h3>🧪 Test Clean Format:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f8f9fa;'>";
    echo "<th>Batch ID</th><th>Records</th><th>Actions</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><code>" . htmlspecialchars($row['id']) . "</code></td>";
        echo "<td><span style='background: #e9ecef; padding: 2px 6px; border-radius: 3px;'>" . number_format($row['record_count']) . "</span></td>";
        echo "<td>";
        
        $batch_id = urlencode($row['id']);
        
        // Clean format test
        echo "<button onclick='testCleanPDF(\"{$batch_id}\", this)' style='padding: 8px 16px; background: #2196f3; color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px;'>📋 Clean Format</button>";
        
        // Compare with current
        echo "<button onclick='testCurrentPDF(\"{$batch_id}\", this)' style='padding: 8px 16px; background: #ff9800; color: white; border: none; border-radius: 4px; cursor: pointer;'>🔧 Current Version</button>";
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<div style='background: #fff3e0; border: 1px solid #ff9800; border-radius: 5px; padding: 15px; margin: 15px 0;'>";
    echo "<h4 style='color: #e65100; margin-top: 0;'>💡 Comparison Guide:</h4>";
    echo "<p style='color: #e65100;'>";
    echo "<strong>Clean Format:</strong> Simple, readable layout that should match your sample<br>";
    echo "<strong>Current Version:</strong> The complex version with previous fixes applied<br><br>";
    echo "Test both and let me know which one matches your desired sample format better!";
    echo "</p>";
    echo "</div>";
    
    echo "<div style='background: #f3e5f5; border: 1px solid #9c27b0; border-radius: 5px; padding: 15px; margin: 15px 0;'>";
    echo "<h4 style='color: #4a148c; margin-top: 0;'>❓ Missing Sample File</h4>";
    echo "<p style='color: #4a148c;'>";
    echo "I don't see the sample PDF in the error folder yet. Please upload the correct sample PDF you want me to match to:<br>";
    echo "<code>C:\\xampp\\htdocs\\calling_sheet_generator10\\error images\\</code><br>";
    echo "Name it something like <code>sample_correct.png</code> so I can see exactly what format you need.";
    echo "</p>";
    echo "</div>";
    
} else {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px;'>";
    echo "<h4 style='color: #856404; margin-top: 0;'>📤 Upload Test Data</h4>";
    echo "<p style='color: #856404;'>Upload a batch to test the clean format.</p>";
    echo "<a href='upload_batch.php' style='padding: 8px 16px; background: #ffc107; color: #212529; text-decoration: none; border-radius: 4px; font-weight: bold;'>Upload Batch</a>";
    echo "</div>";
}

$conn->close();
?>

<script>
function testCleanPDF(batchId, button) {
    const startTime = performance.now();
    const originalText = button.innerHTML;
    
    button.innerHTML = '🔄 Generating Clean...';
    button.style.backgroundColor = '#1976d2';
    button.disabled = true;
    
    // Open clean format PDF
    const pdfWindow = window.open(`generate_pdf_clean.php?batch_id=${batchId}`, '_blank');
    
    // Monitor completion
    const checkComplete = setInterval(() => {
        try {
            if (pdfWindow.closed) {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `📋 Clean (${duration}s)`;
                button.style.backgroundColor = '#2196f3';
                button.disabled = false;
                
                showResult('clean', `Clean format generated in ${duration} seconds`);
                clearInterval(checkComplete);
            }
        } catch (e) {
            setTimeout(() => {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `📋 Clean (${duration}s)`;
                button.style.backgroundColor = '#2196f3';
                button.disabled = false;
                clearInterval(checkComplete);
            }, 2000);
        }
    }, 1000);
    
    setTimeout(() => {
        if (button.disabled) {
            clearInterval(checkComplete);
            button.innerHTML = '❌ Timeout';
            button.style.backgroundColor = '#f44336';
            button.disabled = false;
        }
    }, 45000);
}

function testCurrentPDF(batchId, button) {
    const startTime = performance.now();
    const originalText = button.innerHTML;
    
    button.innerHTML = '🔄 Generating Current...';
    button.style.backgroundColor = '#f57c00';
    button.disabled = true;
    
    // Open current PDF
    const pdfWindow = window.open(`generate_pdf.php?batch_id=${batchId}`, '_blank');
    
    const checkComplete = setInterval(() => {
        try {
            if (pdfWindow.closed) {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `🔧 Current (${duration}s)`;
                button.style.backgroundColor = '#ff9800';
                button.disabled = false;
                
                showResult('current', `Current format generated in ${duration} seconds`);
                clearInterval(checkComplete);
            }
        } catch (e) {
            setTimeout(() => {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `🔧 Current (${duration}s)`;
                button.style.backgroundColor = '#ff9800';
                button.disabled = false;
                clearInterval(checkComplete);
            }, 2000);
        }
    }, 1000);
}

function showResult(type, message) {
    let resultDiv = document.getElementById('test-results');
    if (!resultDiv) {
        resultDiv = document.createElement('div');
        resultDiv.id = 'test-results';
        resultDiv.style.cssText = 'margin: 20px 0; padding: 15px; border-radius: 5px; background: #e8f5e8; border: 1px solid #4caf50;';
        document.body.appendChild(resultDiv);
    }
    
    const currentContent = resultDiv.innerHTML;
    resultDiv.innerHTML = currentContent + `<p><strong>${type.toUpperCase()}:</strong> ${message}</p>`;
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #dee2e6; padding: 12px; text-align: left; }
th { background-color: #f8f9fa; font-weight: bold; }
button:hover { opacity: 0.9; transform: translateY(-1px); }
button:disabled { opacity: 0.7; cursor: not-allowed; }
code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }
</style>