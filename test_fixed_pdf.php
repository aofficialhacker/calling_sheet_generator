<?php
// Test the fixed PDF generation with a small dataset
require_once 'db_config.php';
require 'vendor/autoload.php';

use TCPDF;

// Start session and check admin (simplified for testing)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Mock admin session for testing
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['admin_id'] = 'test_admin';
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing fixed PDF generation...\n";

try {
    $conn = getDBConnection();
    echo "✓ Database connection successful\n";
    
    // Get a small test dataset
    $testSql = "SELECT fcl.id, fcl.mobile_no, fcl.name, fcl.dob, fcl.address 
                FROM final_call_logs fcl 
                JOIN file_batches fb ON fcl.batch_id = fb.id 
                LIMIT 5";
    
    $result = $conn->query($testSql);
    
    if (!$result || $result->num_rows === 0) {
        die("No test data found\n");
    }
    
    echo "✓ Found " . $result->num_rows . " test records\n";
    
    // Test PDF creation with corrected order
    $finalHeaders = ['id', 'mobile_no', 'name', 'dob', 'address'];
    
    // Setup column data FIRST
    $columnData = [];
    foreach ($finalHeaders as $header) {
        switch ($header) {
            case 'id':
                $columnData[] = ['header' => 'Id', 'width' => 20];
                break;
            case 'mobile_no':
                $columnData[] = ['header' => 'Mobile', 'width' => 25];
                break;
            case 'name':
                $columnData[] = ['header' => 'Name', 'width' => 35];
                break;
            case 'dob':
                $columnData[] = ['header' => 'DOB', 'width' => 22];
                break;
            case 'address':
                $columnData[] = ['header' => 'Address', 'width' => 45];
                break;
        }
    }
    
    echo "✓ Column data setup complete\n";
    
    // NOW create PDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Test');
    $pdf->SetTitle('Test PDF');
    
    // Calculate margins with proper column data
    $pageWidth = 297;
    $currentTotal = array_sum(array_column($columnData, 'width'));
    $leftMargin = ($pageWidth - $currentTotal) / 2;
    
    echo "✓ Column total: {$currentTotal}mm, Left margin: {$leftMargin}mm\n";
    
    $pdf->SetMargins($leftMargin, 20, $leftMargin);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    
    echo "✓ PDF initialized successfully\n";
    
    // Add headers
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    foreach ($columnData as $col) {
        $pdf->Cell($col['width'], 7, $col['header'], 1, 0, 'C', true);
    }
    $pdf->Ln();
    
    echo "✓ Headers added\n";
    
    // Add test data
    $pdf->SetFont('helvetica', '', 7);
    $rowCount = 0;
    while ($row = $result->fetch_assoc()) {
        foreach ($finalHeaders as $i => $header) {
            $content = $row[$header] ?? '';
            if ($header === 'address' && strlen($content) > 30) {
                $content = substr($content, 0, 30) . '..';
            }
            $pdf->Cell($columnData[$i]['width'], 10, $content, 1, 0, 'L');
        }
        $pdf->Ln();
        $rowCount++;
    }
    
    echo "✓ Added $rowCount data rows\n";
    
    // Test PDF output
    $pdfString = $pdf->Output('', 'S');
    $pdfSize = strlen($pdfString);
    
    echo "✓ PDF generated successfully! Size: $pdfSize bytes\n";
    
    // Save test file
    file_put_contents('test_output.pdf', $pdfString);
    echo "✓ Test PDF saved as 'test_output.pdf'\n";
    
    echo "\n🎯 PDF generation fix is working!\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>