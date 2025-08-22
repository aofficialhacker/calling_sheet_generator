<?php
// Standalone PDF download test
require 'vendor/autoload.php';

// Clear any existing output
while (ob_get_level()) ob_end_clean();

try {
    // Create simple test PDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Test PDF Generator');
    $pdf->SetTitle('Download Test PDF');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'PDF Download Test - SUCCESS!', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Generated at: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Cell(0, 10, 'If you can download this PDF, the system is working correctly.', 0, 1);
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'New Features Test:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, '✓ Scissor symbols: ✂', 0, 1);
    $pdf->Cell(0, 6, '✓ Circle symbols: ○01 ○02 ○03', 0, 1);
    $pdf->Cell(0, 6, '✓ Enhanced PDF generation', 0, 1);
    
    $fileName = 'test_download_' . date('Ymd_His') . '.pdf';
    
    // Check headers
    if (headers_sent($file, $line)) {
        throw new Exception("Headers already sent in $file on line $line");
    }
    
    // Set download headers
    header('Content-Type: application/pdf', true);
    header('Content-Disposition: attachment; filename="' . $fileName . '"', true);
    header('Cache-Control: private, max-age=0, must-revalidate', true);
    header('Pragma: public', true);
    
    // Output PDF
    $pdf->Output($fileName, 'D');
    
} catch (Exception $e) {
    // Clear any existing output
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/html', true, 500);
    echo '<!DOCTYPE html><html><head><title>PDF Download Error</title></head><body>';
    echo '<h2>PDF Download Test Failed</h2>';
    echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>This indicates a problem with PDF generation or download headers.</p>';
    echo '<button onclick="history.back()">Go Back</button>';
    echo '</body></html>';
}

exit;
?>