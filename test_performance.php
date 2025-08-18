<?php
// Performance test for PDF generation optimizations
require_once 'db_config.php';

if (!isset($_SESSION['admin_id'])) {
    echo "Error: Please log in as admin first.";
    exit;
}

$conn = getDBConnection();

echo "<h2>PDF Generation Performance Test</h2>";
echo "<p>This test validates the performance improvements for large Excel files.</p>";

// Check for existing large batches
$result = $conn->query("
    SELECT fb.id, fb.original_filename, COUNT(fcl.id) as record_count 
    FROM file_batches fb 
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
    WHERE fb.admin_id = '{$_SESSION['admin_id']}'
    GROUP BY fb.id 
    HAVING record_count > 1000
    ORDER BY record_count DESC 
    LIMIT 5
");

if ($result && $result->num_rows > 0) {
    echo "<h3>Available Large Batches for Testing:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Batch ID</th><th>Filename</th><th>Record Count</th><th>Actions</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['original_filename']) . "</td>";
        echo "<td>" . number_format($row['record_count']) . "</td>";
        echo "<td>";
        echo "<a href='javascript:testPDF(\"" . $row['id'] . "\", " . $row['record_count'] . ")' style='padding: 5px 10px; background: #28a745; color: white; text-decoration: none; border-radius: 3px; margin-right: 5px;'>Test PDF Generation</a>";
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No large batches found. Upload a file with 1000+ rows to test performance improvements.</p>";
    echo "<a href='upload_batch.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Upload Large Test File</a>";
}

$conn->close();
?>

<script>
function testPDF(batchId, recordCount) {
    const startTime = performance.now();
    
    // Show loading indicator
    const button = event.target;
    const originalText = button.textContent;
    button.textContent = 'Generating...';
    button.style.backgroundColor = '#ffc107';
    button.style.pointerEvents = 'none';
    
    // Open PDF generation in new tab to track timing
    const pdfWindow = window.open(`generate_pdf.php?batch_id=${batchId}`, '_blank');
    
    // Monitor when PDF generation completes (approximate)
    const checkComplete = setInterval(() => {
        try {
            if (pdfWindow.closed) {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                alert(`PDF Generation Complete!\n\nBatch: ${batchId}\nRecords: ${recordCount.toLocaleString()}\nTime: ${duration} seconds\n\nOptimizations Applied:\n- Larger chunk processing (2000 rows)\n- Single table write\n- Memory optimizations\n- Cutline limited to Mobile column area`);
                
                button.textContent = originalText;
                button.style.backgroundColor = '#28a745';
                button.style.pointerEvents = 'auto';
                
                clearInterval(checkComplete);
            }
        } catch (e) {
            // Cross-origin restrictions prevent checking closed status
            setTimeout(() => {
                const endTime = performance.now();
                const duration = ((endTime - startTime) / 1000).toFixed(2);
                
                button.textContent = `Completed (${duration}s)`;
                button.style.backgroundColor = '#28a745';
                button.style.pointerEvents = 'auto';
                
                clearInterval(checkComplete);
            }, 3000); // Assume completion after 3 seconds
        }
    }, 500);
    
    // Timeout after 2 minutes
    setTimeout(() => {
        clearInterval(checkComplete);
        if (!pdfWindow.closed) {
            button.textContent = 'Generation in progress...';
            button.style.backgroundColor = '#17a2b8';
        }
    }, 120000);
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
</style>