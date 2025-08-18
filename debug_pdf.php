<?php
// Debug PDF generation issues
require_once 'db_config.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || (!isAdmin() && !isSuperadmin())) {
    echo "<h2>Authentication Required</h2>";
    echo "<p>Please log in as an admin to access this page.</p>";
    echo "<a href='admin_login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Admin Login</a>";
    exit;
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h2>PDF Generation Debug</h2>";

// Check for batches
$result = $conn->query("
    SELECT fb.id, fb.original_filename, COUNT(fcl.id) as record_count 
    FROM file_batches fb 
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
    WHERE fb.admin_id = '{$adminId}'
    GROUP BY fb.id 
    ORDER BY fb.upload_time DESC 
    LIMIT 5
");

if ($result && $result->num_rows > 0) {
    echo "<h3>Available Batches:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Batch ID</th><th>Filename</th><th>Record Count</th><th>Test Links</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['original_filename']) . "</td>";
        echo "<td>" . number_format($row['record_count']) . "</td>";
        echo "<td>";
        
        if ($row['record_count'] > 0) {
            echo "<a href='generate_pdf.php?batch_id=" . urlencode($row['id']) . "' target='_blank' style='padding: 3px 8px; background: #007bff; color: white; text-decoration: none; border-radius: 3px; margin-right: 5px; font-size: 12px;'>Test PDF</a>";
            echo "<a href='debug_batch.php?batch_id=" . urlencode($row['id']) . "' target='_blank' style='padding: 3px 8px; background: #28a745; color: white; text-decoration: none; border-radius: 3px; font-size: 12px;'>Debug Data</a>";
        } else {
            echo "<span style='color: #666; font-style: italic;'>No data</span>";
        }
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show first batch details for debugging
    $result->data_seek(0);
    $firstBatch = $result->fetch_assoc();
    
    if ($firstBatch['record_count'] > 0) {
        echo "<h3>Sample Data from Batch: " . htmlspecialchars($firstBatch['id']) . "</h3>";
        
        $sampleQuery = "SELECT * FROM final_call_logs WHERE batch_id = ? LIMIT 3";
        $sampleStmt = $conn->prepare($sampleQuery);
        $sampleStmt->bind_param("s", $firstBatch['id']);
        $sampleStmt->execute();
        $sampleResult = $sampleStmt->get_result();
        
        if ($sampleResult->num_rows > 0) {
            echo "<table border='1' cellpadding='3' style='font-size: 12px;'>";
            $firstRow = true;
            while ($sampleRow = $sampleResult->fetch_assoc()) {
                if ($firstRow) {
                    echo "<tr>";
                    foreach (array_keys($sampleRow) as $column) {
                        echo "<th style='background: #f0f0f0;'>" . htmlspecialchars($column) . "</th>";
                    }
                    echo "</tr>";
                    $firstRow = false;
                }
                
                echo "<tr>";
                foreach ($sampleRow as $value) {
                    echo "<td>" . htmlspecialchars(substr($value ?? '', 0, 20)) . (strlen($value ?? '') > 20 ? '...' : '') . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        $sampleStmt->close();
    }
    
} else {
    echo "<p style='color: orange;'>No batches found for this admin. Please upload a batch first.</p>";
    echo "<a href='upload_batch.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Upload New Batch</a>";
}

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 5px; text-align: left; }
th { background-color: #f2f2f2; font-weight: bold; }
</style>