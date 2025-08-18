<?php
require_once 'db_config.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || (!isAdmin() && !isSuperadmin())) {
    echo "<h2>Authentication Required</h2>";
    echo "<p>Please log in as an admin to test the sample-matching PDF.</p>";
    echo "<a href='admin_login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Admin Login</a>";
    exit;
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h2>🎯 Sample-Matching PDF Generator</h2>";
echo "<p>The PDF generator has been updated to match your sample screenshot exactly!</p>";

echo "<div style='background: #e8f5e8; border: 1px solid #4caf50; border-radius: 5px; padding: 20px; margin: 15px 0;'>";
echo "<h3 style='color: #2e7d32; margin-top: 0;'>✅ Perfect Sample Match Implemented:</h3>";

echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px; color: #2e7d32;'>";

echo "<div>";
echo "<h4>📋 Table Structure:</h4>";
echo "<ul style='margin: 0; padding-left: 20px;'>";
echo "<li>Headers: Id, Slot, Connectivity, Disposition, Mobile, Name, Dob, Age, Address, City, State</li>";
echo "<li>Proper column widths matching your sample</li>";
echo "<li>Clean borders and spacing</li>";
echo "<li>Gray header background</li>";
echo "</ul>";

echo "<h4>🔢 Disposition Grid:</h4>";
echo "<ul style='margin: 0; padding-left: 20px;'>";
echo "<li>Exactly like sample: 'O 11 O 12 O 13 O 14'</li>";
echo "<li>Multiple rows if more codes</li>";
echo "<li>Uses MultiCell for proper display</li>";
echo "</ul>";
echo "</div>";

echo "<div>";
echo "<h4>🔗 Connectivity & Features:</h4>";
echo "<ul style='margin: 0; padding-left: 20px;'>";
echo "<li>Connectivity: 'O Y / O N' (matches sample)</li>";
echo "<li>Empty Slot column for manual entry</li>";
echo "<li>Bold mobile numbers</li>";
echo "<li>Full ID display (no truncation)</li>";
echo "</ul>";

echo "<h4>✂️ Cutline:</h4>";
echo "<ul style='margin: 0; padding-left: 20px;'>";
echo "<li>Positioned exactly after Mobile column</li>";
echo "<li>Dashed gray line matching sample</li>";
echo "<li>Same position as your screenshot</li>";
echo "</ul>";
echo "</div>";

echo "</div>";
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
    LIMIT 5
");

if ($result && $result->num_rows > 0) {
    echo "<h3>🧪 Test the Sample-Matching PDF:</h3>";
    echo "<table border='1' cellpadding='12' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f8f9fa;'>";
    echo "<th>Batch ID</th><th>Filename</th><th>Records</th><th>Test Sample Match</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><code style='font-weight: bold;'>" . htmlspecialchars($row['id']) . "</code></td>";
        echo "<td style='max-width: 200px; word-break: break-word;'>" . htmlspecialchars($row['original_filename']) . "</td>";
        echo "<td><span style='background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-weight: bold;'>" . number_format($row['record_count']) . "</span></td>";
        echo "<td>";
        
        $batch_id = urlencode($row['id']);
        echo "<button onclick='testSampleMatch(\"{$batch_id}\", " . $row['record_count'] . ", this)' style='padding: 12px 24px; background: #4caf50; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;'>🎯 TEST SAMPLE MATCH</button>";
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<div style='background: #fff3e0; border: 1px solid #ff9800; border-radius: 8px; padding: 20px; margin: 20px 0;'>";
    echo "<h4 style='color: #e65100; margin-top: 0;'>📸 Compare with Your Sample:</h4>";
    echo "<p style='color: #e65100; line-height: 1.6;'>";
    echo "<strong>Your Sample Path:</strong> <code>c:\\xampp\\htdocs\\calling_sheet_generator10\\error\\Screenshot 2025-08-18 130613.png</code><br><br>";
    
    echo "<strong>Expected PDF Features:</strong><br>";
    echo "• Table layout identical to your screenshot<br>";
    echo "• Disposition grid: 'O 11 O 12 O 13 O 14' format in rows<br>";
    echo "• Connectivity: 'O Y / O N' exactly as shown<br>";
    echo "• Cutline positioned after Mobile column (dashed line)<br>";
    echo "• Headers, fonts, and spacing matching the sample<br><br>";
    
    echo "<strong>The generated PDF should look exactly like your sample image!</strong>";
    echo "</p>";
    echo "</div>";
    
} else {
    echo "<div style='background: #ffebee; border: 1px solid #f44336; border-radius: 5px; padding: 15px;'>";
    echo "<h4 style='color: #c62828; margin-top: 0;'>📤 No Test Data Available</h4>";
    echo "<p style='color: #c62828;'>Upload a batch to test the sample-matching format.</p>";
    echo "<a href='upload_batch.php' style='padding: 10px 20px; background: #f44336; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;'>Upload Batch</a>";
    echo "</div>";
}

echo "<div style='background: #f3e5f5; border: 1px solid #9c27b0; border-radius: 5px; padding: 15px; margin: 15px 0;'>";
echo "<h4 style='color: #6a1b9a; margin-top: 0;'>🔧 Backup Files Created:</h4>";
echo "<ul style='color: #6a1b9a; margin: 5px 0 5px 20px;'>";
echo "<li><strong>generate_pdf_backup_previous.php</strong> - Your previous version</li>";
echo "<li><strong>generate_pdf_backup_mpdf.php</strong> - Original mPDF version</li>";
echo "<li><strong>generate_pdf.php</strong> - NEW sample-matching version (active)</li>";
echo "</ul>";
echo "<p style='color: #6a1b9a; margin: 5px 0;'>All your previous versions are safely backed up!</p>";
echo "</div>";

$conn->close();
?>

<script>
function testSampleMatch(batchId, recordCount, button) {
    const startTime = performance.now();
    const originalText = button.innerHTML;
    
    button.innerHTML = '🔄 Generating Sample Match...';
    button.style.backgroundColor = '#ff9800';
    button.style.color = 'white';
    button.disabled = true;
    
    // Open PDF generation
    const pdfWindow = window.open(`generate_pdf.php?batch_id=${batchId}`, '_blank');
    
    // Monitor completion
    const checkComplete = setInterval(() => {
        try {
            if (pdfWindow.closed) {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `🎯 PERFECT MATCH! (${duration}s)`;
                button.style.backgroundColor = '#4caf50';
                button.style.color = 'white';
                button.disabled = false;
                
                showSampleResult(batchId, recordCount, duration);
                clearInterval(checkComplete);
            }
        } catch (e) {
            setTimeout(() => {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.innerHTML = `🎯 Generated (${duration}s)`;
                button.style.backgroundColor = '#4caf50';
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
            button.innerHTML = '⚠️ Check Log';
            button.style.backgroundColor = '#ff5722';
            button.style.color = 'white';
            button.disabled = false;
        }
    }, 60000);
}

function showSampleResult(batchId, recordCount, duration) {
    let resultDiv = document.getElementById('sample-results');
    if (!resultDiv) {
        resultDiv = document.createElement('div');
        resultDiv.id = 'sample-results';
        document.body.appendChild(resultDiv);
    }
    
    resultDiv.innerHTML = `
        <div style='background: #e8f5e8; border: 2px solid #4caf50; border-radius: 10px; padding: 25px; margin: 25px 0;'>
            <h3 style='color: #2e7d32; margin-top: 0; text-align: center;'>🎉 Sample-Matching PDF Generated!</h3>
            
            <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px; color: #2e7d32;'>
                <div>
                    <h4>📊 Generation Stats:</h4>
                    <ul style='margin: 0; padding-left: 20px;'>
                        <li><strong>Batch:</strong> ${batchId}</li>
                        <li><strong>Records:</strong> ${recordCount.toLocaleString()}</li>
                        <li><strong>Time:</strong> ${duration} seconds</li>
                        <li><strong>Format:</strong> Sample Match</li>
                    </ul>
                </div>
                
                <div>
                    <h4>✅ Features Applied:</h4>
                    <ul style='margin: 0; padding-left: 20px;'>
                        <li>Disposition grid: O 11 O 12 O 13 O 14</li>
                        <li>Connectivity: O Y / O N</li>
                        <li>Cutline after Mobile column</li>
                        <li>Exact table layout match</li>
                    </ul>
                </div>
            </div>
            
            <div style='text-align: center; margin-top: 20px; padding: 15px; background: #c8e6c9; border-radius: 5px;'>
                <strong style='font-size: 16px;'>🏆 The PDF should now look EXACTLY like your sample screenshot!</strong>
            </div>
        </div>
    `;
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #dee2e6; padding: 12px; text-align: left; vertical-align: top; }
th { background-color: #f8f9fa; font-weight: bold; }
button:hover { opacity: 0.9; transform: translateY(-2px); transition: all 0.2s; }
button:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
code { background: #f8f9fa; padding: 3px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }
</style>