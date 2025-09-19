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
    echo "<p>Please log in as an admin to access this page.</p>";
    echo "<a href='admin_login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Admin Login</a>";
    echo " ";
    echo "<a href='superadmin_login.php' style='padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;'>Superadmin Login</a>";
    exit;
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h2>PDF Generation Method Comparison</h2>";
echo "<p>Test different PDF generation approaches to find the fastest and most reliable method for your data.</p>";

// Get available batches
$result = $conn->query("
    SELECT fb.id, fb.original_filename, COUNT(fcl.id) as record_count 
    FROM lv_file_batches fb 
    LEFT JOIN lv_final_call_logs fcl ON fb.id = fcl.batch_id 
    WHERE fb.admin_id = '{$adminId}'
    GROUP BY fb.id 
    HAVING record_count > 0
    ORDER BY record_count DESC 
    LIMIT 10
");

if ($result && $result->num_rows > 0) {
    echo "<h3>Available Test Batches:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f0f0f0;'>";
    echo "<th>Batch ID</th><th>Filename</th><th>Records</th><th>Method Comparison</th>";
    echo "</tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['original_filename']) . "</td>";
        echo "<td>" . number_format($row['record_count']) . "</td>";
        echo "<td>";
        
        $batch_id = urlencode($row['id']);
        
        // Method 1: Original mPDF (HTML-based)
        echo "<div style='margin: 2px 0;'>";
        echo "<strong>Original (mPDF + HTML):</strong> ";
        echo "<button onclick='testMethod(\"generate_pdf.php?batch_id={$batch_id}\", \"Original\", this)' style='padding: 3px 8px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; margin-left: 5px;'>Test</button>";
        echo "</div>";
        
        // Method 2: TCPDF (Direct table)
        echo "<div style='margin: 2px 0;'>";
        echo "<strong>TCPDF (Direct):</strong> ";
        echo "<button onclick='testMethod(\"generate_pdf_tcpdf.php?batch_id={$batch_id}\", \"TCPDF\", this)' style='padding: 3px 8px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer; margin-left: 5px;'>Test</button>";
        echo "</div>";
        
        // Method 3: PhpSpreadsheet PDF export
        echo "<div style='margin: 2px 0;'>";
        echo "<strong>PhpSpreadsheet:</strong> ";
        echo "<button onclick='testMethod(\"generate_pdf_spreadsheet.php?batch_id={$batch_id}\", \"PhpSpreadsheet\", this)' style='padding: 3px 8px; background: #ffc107; color: black; border: none; border-radius: 3px; cursor: pointer; margin-left: 5px;'>Test</button>";
        echo "</div>";
        
        // Method 4: Simple FPDF
        echo "<div style='margin: 2px 0;'>";
        echo "<strong>Simple FPDF:</strong> ";
        echo "<button onclick='testMethod(\"generate_pdf_simple.php?batch_id={$batch_id}\", \"Simple\", this)' style='padding: 3px 8px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer; margin-left: 5px;'>Test</button>";
        echo "</div>";
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Performance Comparison Results:</h3>";
    echo "<div id='results' style='background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; border-radius: 5px; min-height: 100px;'>";
    echo "<p>Click test buttons above to compare performance of different methods.</p>";
    echo "</div>";
    
} else {
    echo "<p>No batches with data found. Please upload a batch first.</p>";
    echo "<a href='upload_batch.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Upload New Batch</a>";
}

$conn->close();
?>

<script>
let testResults = [];

function testMethod(url, methodName, button) {
    const startTime = performance.now();
    const originalText = button.textContent;
    
    button.textContent = 'Testing...';
    button.style.backgroundColor = '#6c757d';
    button.disabled = true;
    
    // Open in new window/tab to trigger download
    const testWindow = window.open(url, '_blank');
    
    // Monitor completion (approximate)
    const checkComplete = setInterval(() => {
        try {
            if (testWindow.closed) {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                recordResult(methodName, duration, 'Success');
                
                button.textContent = `✓ ${duration}s`;
                button.style.backgroundColor = '#28a745';
                button.disabled = false;
                
                clearInterval(checkComplete);
            }
        } catch (e) {
            // Handle cross-origin restrictions
            setTimeout(() => {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                recordResult(methodName, duration, 'Completed');
                
                button.textContent = `✓ ${duration}s`;
                button.style.backgroundColor = '#28a745';
                button.disabled = false;
                
                clearInterval(checkComplete);
            }, 2000);
        }
    }, 1000);
    
    // Timeout after 60 seconds
    setTimeout(() => {
        clearInterval(checkComplete);
        if (button.disabled) {
            recordResult(methodName, '60+', 'Timeout');
            button.textContent = 'Timeout';
            button.style.backgroundColor = '#dc3545';
            button.disabled = false;
        }
    }, 60000);
}

function recordResult(method, duration, status) {
    testResults.push({
        method: method,
        duration: duration,
        status: status,
        timestamp: new Date().toLocaleTimeString()
    });
    
    updateResultsDisplay();
}

function updateResultsDisplay() {
    const resultsDiv = document.getElementById('results');
    
    let html = '<h4>Test Results:</h4>';
    html += '<table border="1" style="border-collapse: collapse; width: 100%;">';
    html += '<tr><th>Method</th><th>Duration</th><th>Status</th><th>Time</th></tr>';
    
    testResults.forEach(result => {
        html += `<tr>
            <td><strong>${result.method}</strong></td>
            <td>${result.duration}s</td>
            <td>${result.status}</td>
            <td>${result.timestamp}</td>
        </tr>`;
    });
    
    html += '</table>';
    
    if (testResults.length > 0) {
        html += '<br><h5>Performance Analysis:</h5>';
        
        const successResults = testResults.filter(r => r.status === 'Success' || r.status === 'Completed');
        if (successResults.length > 1) {
            const fastest = successResults.reduce((prev, current) => {
                return (parseFloat(prev.duration) < parseFloat(current.duration)) ? prev : current;
            });
            html += `<p><strong>Fastest Method:</strong> ${fastest.method} (${fastest.duration}s)</p>`;
        }
        
        html += '<p><strong>Recommendations:</strong></p>';
        html += '<ul>';
        html += '<li><strong>TCPDF:</strong> Usually fastest for large datasets, direct table generation</li>';
        html += '<li><strong>Simple FPDF:</strong> Lightest memory usage, good for very large files</li>';
        html += '<li><strong>Original mPDF:</strong> Best formatting but slower for large datasets</li>';
        html += '<li><strong>PhpSpreadsheet:</strong> Good Excel compatibility but may be slower</li>';
        html += '</ul>';
    }
    
    resultsDiv.innerHTML = html;
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.4; }
table { border-collapse: collapse; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; font-weight: bold; }
button:hover { opacity: 0.9; }
button:disabled { opacity: 0.6; cursor: not-allowed; }
</style>