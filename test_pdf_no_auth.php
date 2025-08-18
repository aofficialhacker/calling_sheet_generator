<?php
// Test PDF generation without authentication requirements
require 'vendor/autoload.php';
require_once 'db_config.php';

// Manually start session and set admin status for testing
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Force admin status for testing (REMOVE THIS IN PRODUCTION)
$_SESSION['is_admin'] = true;
$_SESSION['admin_id'] = 'test_admin';

echo "<h2>PDF Test (No Auth Required)</h2>";

$conn = getDBConnection();

// Get the first available batch
$result = $conn->query("SELECT fb.id, COUNT(fcl.id) as record_count 
                       FROM file_batches fb 
                       LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
                       GROUP BY fb.id 
                       HAVING record_count > 0 
                       ORDER BY fb.id DESC LIMIT 1");

if ($result && $row = $result->fetch_assoc()) {
    $batch_id = $row['id'];
    $record_count = $row['record_count'];
    
    echo "<p>Found batch: <strong>$batch_id</strong> with <strong>$record_count</strong> records</p>";
    echo "<a href='generate_pdf.php?batch_id=" . urlencode($batch_id) . "' target='_blank' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Test PDF Generation</a>";
    echo "<br><br>";
    echo "<a href='view_pdf_log.php' style='padding: 5px 10px; background: #28a745; color: white; text-decoration: none; border-radius: 3px;'>View Debug Log</a>";
    
} else {
    echo "<p>No batches with data found.</p>";
}

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
</style>