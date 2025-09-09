<?php
// Setup Mobile Duplication Prevention Database
// Run this script once to setup the required database indexes and optimizations

require_once 'db_config.php';

// Only allow admin access for security
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isset($_SESSION['is_admin']) && !isset($_SESSION['is_superadmin'])) {
    die("Unauthorized access. Please login as admin or superadmin.");
}

echo "<h2>Mobile Duplication Prevention Database Setup</h2>";

$conn = getDBConnection();

// Read and execute the SQL setup script
$sql_file = __DIR__ . '/setup_mobile_duplication_prevention.sql';
if (!file_exists($sql_file)) {
    die("SQL setup file not found: {$sql_file}");
}

$sql_content = file_get_contents($sql_file);

// Split SQL commands (basic splitting - works for our script)
$sql_commands = explode(';', $sql_content);

$success_count = 0;
$error_count = 0;
$errors = [];

echo "<h3>Executing Database Setup Commands...</h3>";
echo "<div style='font-family: monospace; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";

foreach ($sql_commands as $command) {
    $command = trim($command);
    
    // Skip empty commands and comments
    if (empty($command) || strpos($command, '--') === 0 || strpos($command, 'USE ') === 0) {
        continue;
    }
    
    try {
        if ($conn->query($command) === TRUE) {
            $success_count++;
            echo "<span style='color: green;'>✓</span> Command executed successfully<br>";
        } else {
            $error_count++;
            $error_msg = "Error: " . $conn->error;
            $errors[] = $error_msg;
            echo "<span style='color: red;'>✗</span> {$error_msg}<br>";
        }
    } catch (Exception $e) {
        $error_count++;
        $error_msg = "Exception: " . $e->getMessage();
        $errors[] = $error_msg;
        echo "<span style='color: red;'>✗</span> {$error_msg}<br>";
    }
}

echo "</div>";

echo "<h3>Setup Summary</h3>";
echo "<p>Successfully executed commands: <strong>{$success_count}</strong></p>";
echo "<p>Failed commands: <strong>{$error_count}</strong></p>";

if ($error_count > 0) {
    echo "<h4>Errors encountered:</h4>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>{$error}</li>";
    }
    echo "</ul>";
}

// Show current index status
echo "<h3>Current Mobile Number Indexes</h3>";
$result = $conn->query("SHOW INDEX FROM final_call_logs WHERE Key_name LIKE '%mobile%'");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Key Name</th><th>Column Name</th><th>Unique</th><th>Index Type</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Key_name']}</td>";
        echo "<td>{$row['Column_name']}</td>";
        echo "<td>" . ($row['Non_unique'] == 0 ? 'Yes' : 'No') . "</td>";
        echo "<td>{$row['Index_type']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No mobile number indexes found.</p>";
}

// Show system statistics
echo "<h3>Current System Statistics</h3>";
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM final_call_logs) as total_records,
    (SELECT COUNT(DISTINCT mobile_no) FROM final_call_logs) as unique_mobile_numbers,
    (SELECT COUNT(*) - COUNT(DISTINCT mobile_no) FROM final_call_logs) as current_duplicates";

$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    echo "<p>Total records: <strong>{$stats['total_records']}</strong></p>";
    echo "<p>Unique mobile numbers: <strong>{$stats['unique_mobile_numbers']}</strong></p>";
    echo "<p>Current duplicates: <strong>{$stats['current_duplicates']}</strong></p>";
}

$conn->close();

if ($error_count == 0) {
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; margin: 10px 0;'>";
    echo "<strong>✓ Database setup completed successfully!</strong><br>";
    echo "The mobile duplication prevention system is now ready to use.";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; margin: 10px 0;'>";
    echo "<strong>⚠ Setup completed with errors!</strong><br>";
    echo "Some commands failed. Please review the errors above and fix them manually if needed.";
    echo "</div>";
}

echo "<p><a href='upload_batch.php'>← Back to Upload Batch</a> | <a href='test_mobile_duplication.php'>Test Duplication System →</a></p>";
?>