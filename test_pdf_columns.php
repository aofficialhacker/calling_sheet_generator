<?php
require_once 'db_config.php';

$conn = getDBConnection();

// Get a sample batch
$result = $conn->query("SELECT id FROM file_batches ORDER BY upload_time DESC LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $batch_id = $row['id'];
    echo "<h2>Testing PDF Generation for Batch: $batch_id</h2>";
    
    // Check what columns have data
    $sql = "SELECT * FROM final_call_logs WHERE batch_id = ? LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $batch_id);
    $stmt->execute();
    $data = $stmt->get_result();
    
    echo "<h3>Sample Data:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr>";
    
    // Expected column order
    $expectedOrder = ['id', 'slot', 'connectivity', 'disposition', 'name', 'mobile_no'];
    
    if ($data && $firstRow = $data->fetch_assoc()) {
        // Show headers in expected order first
        foreach ($expectedOrder as $col) {
            echo "<th style='background-color: #ddd;'>$col</th>";
        }
        
        // Then show other columns
        foreach ($firstRow as $key => $value) {
            if (!in_array($key, $expectedOrder) && !empty($value)) {
                echo "<th>$key</th>";
            }
        }
        echo "</tr>";
        
        // Show first row data
        echo "<tr>";
        foreach ($expectedOrder as $col) {
            $value = $firstRow[$col] ?? '';
            if ($col == 'dob' || $col == 'expiry') {
                // Format dates
                if (!empty($value) && $value !== '0000-00-00') {
                    $value = date('d-m-Y', strtotime($value));
                }
            }
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        foreach ($firstRow as $key => $value) {
            if (!in_array($key, $expectedOrder) && !empty($value)) {
                if ($key == 'dob' || $key == 'expiry') {
                    // Format dates
                    if (!empty($value) && $value !== '0000-00-00') {
                        $value = date('d-m-Y', strtotime($value));
                    }
                }
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><br>";
    echo "<a href='generate_pdf.php?batch_id=$batch_id' target='_blank' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Generate PDF for this Batch</a>";
    
} else {
    echo "No batches found in the database.";
}

$conn->close();
?>