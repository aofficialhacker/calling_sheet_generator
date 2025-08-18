<?php
// Minimal PDF test to isolate the issue
require_once 'db_config.php';
require 'vendor/autoload.php';

use Mpdf\Mpdf;

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>Minimal PDF Test</h2>";

try {
    
    echo "1. mPDF library loaded successfully<br>";
    
    // Test basic mPDF functionality
    $mpdf = new Mpdf(['format' => 'A4-L']);
    echo "2. mPDF instance created successfully<br>";
    
    $html = '<h1>Test PDF</h1><p>This is a minimal test PDF.</p>';
    $mpdf->WriteHTML($html);
    echo "3. HTML written to mPDF successfully<br>";
    
    // Test if we can generate the PDF without downloading
    $pdfOutput = $mpdf->Output('', 'S'); // Return as string
    echo "4. PDF generated successfully. Size: " . strlen($pdfOutput) . " bytes<br>";
    
    echo "<br><a href='test_minimal_pdf.php?download=1' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Test Download</a>";
    
    // If download parameter is set, try to download
    if (isset($_GET['download'])) {
        echo "<br><br>5. Attempting download...<br>";
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="test.pdf"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        // Output PDF
        echo $pdfOutput;
        exit;
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Stack trace: <pre>" . $e->getTraceAsString() . "</pre></p>";
}
?>