<?php
// Test PDF download functionality without authentication
require 'vendor/autoload.php';

use TCPDF;

// Start output buffering
if (ob_get_level() == 0) ob_start();

// Create simple test PDF
class TestDownloadPDF extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 10, 'PDF Download Test', 0, 1, 'C');
    }
}

try {
    // Clear any existing output
    while (ob_get_level()) ob_end_clean();
    
    $pdf = new TestDownloadPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'This is a test PDF to verify download functionality.', 0, 1);
    $pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1);
    
    $fileName = 'test_download.pdf';
    
    // Check if headers can be sent
    if (headers_sent($file, $line)) {
        die("Headers already sent in $file on line $line");
    }
    
    // Set download headers
    header('Content-Type: application/pdf', true);
    header('Content-Disposition: attachment; filename="' . $fileName . '"', true);
    header('Cache-Control: private, max-age=0, must-revalidate', true);
    header('Pragma: public', true);
    
    // Output PDF
    $pdf->Output($fileName, 'D');
    
} catch (Exception $e) {
    // Clear any output and show error
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/html', true, 500);
    echo '<h2>PDF Download Test Error</h2>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<button onclick="history.back()">Go Back</button>';
}

exit;
?>