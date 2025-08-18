<?php
// Debug specific batch data
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

$batch_id = $_GET['batch_id'] ?? '';
if (empty($batch_id)) {
    echo "Error: No batch ID provided.";
    exit;
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h2>Batch Debug: " . htmlspecialchars($batch_id) . "</h2>";

// Check batch exists and belongs to admin
$batchQuery = "SELECT * FROM file_batches WHERE id = ? AND admin_id = ?";
$batchStmt = $conn->prepare($batchQuery);
$batchStmt->bind_param("ss", $batch_id, $adminId);
$batchStmt->execute();
$batchResult = $batchStmt->get_result();

if ($batchResult->num_rows == 0) {
    echo "<p style='color: red;'>Batch not found or doesn't belong to current admin.</p>";
    exit;
}

$batchData = $batchResult->fetch_assoc();
echo "<h3>Batch Information:</h3>";
echo "<table border='1' cellpadding='5'>";
foreach ($batchData as $key => $value) {
    echo "<tr><th>" . htmlspecialchars($key) . "</th><td>" . htmlspecialchars($value) . "</td></tr>";
}
echo "</table>";

// Count records
$countQuery = "SELECT COUNT(*) as total FROM final_call_logs WHERE batch_id = ?";
$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param("s", $batch_id);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];

echo "<h3>Record Count: " . number_format($totalRecords) . "</h3>";

if ($totalRecords > 0) {
    // Show sample records
    echo "<h3>Sample Records (First 5):</h3>";
    $sampleQuery = "SELECT id, mobile_no, name, status, disposition FROM final_call_logs WHERE batch_id = ? ORDER BY id LIMIT 5";
    $sampleStmt = $conn->prepare($sampleQuery);
    $sampleStmt->bind_param("s", $batch_id);
    $sampleStmt->execute();
    $sampleResult = $sampleStmt->get_result();
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Mobile</th><th>Name</th><th>Status</th><th>Disposition</th></tr>";
    while ($row = $sampleResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['mobile_no']) . "</td>";
        echo "<td>" . htmlspecialchars($row['name'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['disposition'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Test PDF generation
    echo "<h3>PDF Generation Test:</h3>";
    echo "<p>Click below to test PDF generation for this batch:</p>";
    echo "<a href='generate_pdf.php?batch_id=" . urlencode($batch_id) . "' target='_blank' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Generate PDF</a>";
    
    // Show the query that would be executed
    echo "<h3>SQL Query Preview:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo "SELECT fcl.id, fcl.slot, fcl.connectivity, fcl.disposition, fcl.mobile_no, fcl.name\n";
    echo "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id\n";
    echo "WHERE fb.admin_id = '{$adminId}' AND fcl.batch_id = '{$batch_id}'\n";
    echo "ORDER BY fcl.id";
    echo "</pre>";
} else {
    echo "<p style='color: red;'>No records found in this batch.</p>";
}

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 5px; text-align: left; }
th { background-color: #f2f2f2; font-weight: bold; }
pre { font-size: 14px; }
</style>