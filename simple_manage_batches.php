<?php
// Simple version to test basic functionality
require_once 'db_config.php';
requireAdmin();

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>TESTING: Simple Manage Batches</h1>";
echo "<p>If you can see this text, PHP is working.</p>";

try {
    $conn = getDBConnection();
    $adminId = $_SESSION['admin_id'];
    
    echo "<p><strong>Admin ID:</strong> " . htmlspecialchars($adminId) . "</p>";
    
    // Test database connection
    $test_query = $conn->query("SELECT COUNT(*) as count FROM admin_users WHERE admin_id = '$adminId'");
    if ($test_query) {
        $admin_exists = $test_query->fetch_assoc()['count'];
        echo "<p><strong>Admin exists in DB:</strong> " . ($admin_exists ? 'Yes' : 'No') . "</p>";
    }
    
    // Test batch query
    $batch_query = $conn->query("SELECT COUNT(*) as count FROM file_batches WHERE admin_id = '$adminId'");
    if ($batch_query) {
        $batch_count = $batch_query->fetch_assoc()['count'];
        echo "<p><strong>Batches found:</strong> $batch_count</p>";
    }
    
    // Get actual batches
    $stmt = $conn->prepare("SELECT id, original_filename, product_code FROM file_batches WHERE admin_id = ? LIMIT 5");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h2>Your Batches:</h2>";
    if ($result->num_rows > 0) {
        echo "<ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>ID: " . htmlspecialchars($row['id']) . " - File: " . htmlspecialchars($row['original_filename']) . " - Product: " . htmlspecialchars($row['product_code']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No batches found for your admin account.</p>";
    }
    
} catch (Exception $e) {
    echo "<p><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Simple Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="alert alert-success">
        <h4>HTML Section Test</h4>
        <p>If you can see this green box, HTML rendering is working.</p>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h5>Test Dropdowns</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">Status Filter</label>
                    <select class="form-select">
                        <option>Test Status 1</option>
                        <option>Test Status 2</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Product Filter</label>
                    <select class="form-select">
                        <option>Test Product 1</option>
                        <option>Test Product 2</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Batch Filter</label>
                    <select class="form-select">
                        <option>Test Batch 1</option>
                        <option>Test Batch 2</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <p class="mt-3"><a href="manage_batches.php" class="btn btn-primary">Back to Original Page</a></p>
</body>
</html>