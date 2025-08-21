<?php
// Simple PDF test to isolate the download issue
require 'vendor/autoload.php';

use TCPDF;

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Clear any existing output
if (ob_get_level()) {
    ob_end_clean();
}

try {
    echo "Starting PDF test...\n";
    
    // Create simple TCPDF instance
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Test');
    $pdf->SetTitle('Simple Test PDF');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    
    echo "PDF object created...\n";
    
    // Add a page
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'PDF Generation Test', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Ln(5);
    $pdf->Cell(0, 10, 'This is a simple test PDF to verify generation works.', 0, 1, 'L');
    $pdf->Cell(0, 10, 'Generated at: ' . date('Y-m-d H:i:s'), 0, 1, 'L');
    
    echo "Content added to PDF...\n";
    
    // Test if we can get PDF string
    $pdfString = $pdf->Output('', 'S');
    $pdfSize = strlen($pdfString);
    
    echo "PDF generated successfully. Size: $pdfSize bytes\n";
    
    // If running via web browser, output the PDF
    if (!php_sapi_name() === 'cli') {
        // Clear output buffer again
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="test_pdf.pdf"');
        header('Content-Length: ' . $pdfSize);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        // Output PDF
        echo $pdfString;
        exit;
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
}
?>