<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

echo "<h1>Debug Batches Data</h1>";
echo "<p>Admin ID: " . htmlspecialchars($adminId) . "</p>";

// Check if admin exists and is active
$adminCheck = $conn->prepare("SELECT admin_id, name, is_active FROM admin_users WHERE admin_id = ?");
$adminCheck->bind_param("s", $adminId);
$adminCheck->execute();
$adminResult = $adminCheck->get_result()->fetch_assoc();

if ($adminResult) {
    echo "<p>Admin found: " . htmlspecialchars($adminResult['name']) . " (Active: " . ($adminResult['is_active'] ? 'Yes' : 'No') . ")</p>";
} else {
    echo "<p><strong>ERROR:</strong> Admin not found in database!</p>";
}

// Check batches
$stmt = $conn->prepare("SELECT id, original_filename, upload_time, product_code FROM file_batches WHERE admin_id = ? ORDER BY upload_time DESC LIMIT 10");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();

echo "<h2>Batches for this admin:</h2>";
if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Filename</th><th>Product Code</th><th>Upload Time</th><th>Record Count</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        // Get record count
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM final_call_logs WHERE batch_id = ?");
        $count_stmt->bind_param("s", $row['id']);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $record_count = $count_result->fetch_assoc()['count'];
        $count_stmt->close();
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['original_filename']) . "</td>";
        echo "<td>" . htmlspecialchars($row['product_code']) . "</td>";
        echo "<td>" . htmlspecialchars($row['upload_time']) . "</td>";
        echo "<td>" . $record_count . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No batches found for this admin.</p>";
    
    // Check what admin IDs exist
    echo "<h3>All admin IDs in file_batches table:</h3>";
    $allAdmins = $conn->query("SELECT DISTINCT admin_id FROM file_batches LIMIT 10");
    if ($allAdmins && $allAdmins->num_rows > 0) {
        echo "<ul>";
        while ($admin = $allAdmins->fetch_assoc()) {
            echo "<li>" . htmlspecialchars($admin['admin_id']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No batches found in the entire database!</p>";
    }
}

$conn->close();
?>