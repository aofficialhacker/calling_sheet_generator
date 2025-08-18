<?php
// View PDF debug log
$logFile = __DIR__ . '/pdf_debug.log';

echo "<h2>PDF Generation Debug Log</h2>";

if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    if (!empty($logContent)) {
        echo "<pre style='background: #f5f5f5; padding: 15px; border: 1px solid #ddd; max-height: 400px; overflow-y: auto;'>";
        echo htmlspecialchars($logContent);
        echo "</pre>";
        
        echo "<br>";
        echo "<a href='view_pdf_log.php?clear=1' style='padding: 5px 10px; background: #dc3545; color: white; text-decoration: none; border-radius: 3px;'>Clear Log</a>";
        echo " ";
        echo "<a href='view_pdf_log.php' style='padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 3px;'>Refresh</a>";
    } else {
        echo "<p>Log file is empty.</p>";
    }
    
    if (isset($_GET['clear'])) {
        file_put_contents($logFile, '');
        echo "<p style='color: green;'>Log cleared!</p>";
        echo "<meta http-equiv='refresh' content='1;url=view_pdf_log.php'>";
    }
} else {
    echo "<p>No log file found. Try generating a PDF first.</p>";
}

echo "<br><br>";
echo "<h3>Test PDF Generation:</h3>";
echo "<a href='debug_pdf.php' style='padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;'>Debug Available Batches</a>";
echo " ";
echo "<a href='test_minimal_pdf.php' style='padding: 10px 20px; background: #17a2b8; color: white; text-decoration: none; border-radius: 5px;'>Test Minimal PDF</a>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
</style>