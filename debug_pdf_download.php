<?php
// Debug PDF download issues
require 'vendor/autoload.php';
require_once 'db_config.php';

// Mock authentication for testing
session_start();
$_SESSION['is_admin'] = true;
$_SESSION['admin_id'] = 'TEST01';

echo "<h2>PDF Download Debug Test</h2>\n";
echo "<p>Testing PDF generation and download process...</p>\n";

// Check if we have data to work with
try {
    $conn = getDBConnection();
    $result = $conn->query("SELECT COUNT(*) as total FROM final_call_logs");
    $totalRecords = $result->fetch_assoc()['total'];
    echo "<p>✓ Database connected. Total records: $totalRecords</p>\n";
    
    if ($totalRecords == 0) {
        echo "<p>✗ No data available for PDF generation</p>\n";
        exit;
    }
    
    // Test with a small batch first
    $result = $conn->query("SELECT * FROM final_call_logs LIMIT 1");
    if ($result->num_rows > 0) {
        $sample = $result->fetch_assoc();
        echo "<p>✓ Sample data available</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p>✗ Database error: " . $e->getMessage() . "</p>\n";
    exit;
}

// Test headers
echo "<p>Testing download headers...</p>\n";

if (headers_sent($file, $line)) {
    echo "<p>✗ Headers already sent in $file on line $line</p>\n";
} else {
    echo "<p>✓ Headers not sent yet - can set PDF headers</p>\n";
}

// Create a simple test PDF
echo "<p>Creating test PDF...</p>\n";

try {
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Test PDF Generator');
    $pdf->SetTitle('Download Test PDF');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'PDF Download Test', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'This PDF was generated successfully at: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Cell(0, 10, 'If you can see this, PDF generation is working.', 0, 1);
    
    echo "<p>✓ PDF created successfully</p>\n";
    
    // Test download
    echo "<p>Testing PDF download...</p>\n";
    echo "<p><a href='#' onclick='downloadTest()'>Click here to test download</a></p>\n";
    
} catch (Exception $e) {
    echo "<p>✗ PDF creation failed: " . $e->getMessage() . "</p>\n";
    exit;
}

echo "<script>
function downloadTest() {
    // Clear the page and trigger download
    document.body.innerHTML = '<h3>Downloading PDF...</h3><p>If download doesn\\'t start, there may be a browser issue.</p>';
    
    // Trigger the actual PDF download
    window.location.href = 'download_test_pdf.php';
}
</script>";

$conn->close();
?>