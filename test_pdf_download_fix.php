<?php
// Test the fixed PDF download functionality
session_start();

// Mock admin session for testing
$_SESSION['is_admin'] = true;
$_SESSION['admin_id'] = 'TEST01';

// Simulate a simple PDF generation request
$_GET['batch_id'] = 'LIV01B001'; // Use a test batch ID

// Capture any output
ob_start();

// Include the main PDF generation
try {
    include 'generate_pdf.php';
} catch (Exception $e) {
    ob_end_clean();
    echo '<h2>PDF Generation Test Failed</h2>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>This test checks if the PDF download functionality works correctly.</p>';
    echo '<button onclick="history.back()">Go Back</button>';
}
?>