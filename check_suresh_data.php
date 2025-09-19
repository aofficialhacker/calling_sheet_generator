<?php
/**
 * Specific data check for suresh.negi@finqy.ai admin
 */
require_once 'db_config.php';

$conn = getDBConnection();

echo "<html><head><title>Suresh Admin Data Check</title></head><body>";
echo "<h1>Data Check for suresh.negi@finqy.ai</h1>";

// Find the admin record
$admin_query = "SELECT admin_id, username, email, is_active FROM lv_admin_users WHERE email = 'suresh.negi@finqy.ai' OR username LIKE '%suresh%'";
$admin_result = $conn->query($admin_query);

if ($admin_result && $admin_result->num_rows > 0) {
    echo "<h2>1. Admin Details</h2>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Admin ID</th><th>Username</th><th>Email</th><th>Active</th></tr>";
    
    $suresh_admin = null;
    while ($admin = $admin_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($admin['admin_id']) . "</td>";
        echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
        echo "<td>" . htmlspecialchars($admin['email']) . "</td>";
        echo "<td>" . ($admin['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
        
        if ($admin['email'] === 'suresh.negi@finqy.ai') {
            $suresh_admin = $admin;
        }
    }
    echo "</table>";
    
    if ($suresh_admin) {
        $adminId = $suresh_admin['admin_id'];
        echo "<p><strong>Using Admin ID:</strong> {$adminId}</p>";
        
        // Check lv_vendors for this admin
        echo "<h2>2. Vendors for Admin {$adminId}</h2>";
        $vendor_query = "SELECT vendor_id, vendor_name, is_approved FROM lv_vendors WHERE admin_id = ? ORDER BY vendor_id";
        $vendor_stmt = $conn->prepare($vendor_query);
        $vendor_stmt->bind_param("s", $adminId);
        $vendor_stmt->execute();
        $lv_vendors = $vendor_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $vendor_stmt->close();
        
        if (count($lv_vendors) > 0) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Vendor ID</th><th>Vendor Name</th><th>Approved</th></tr>";
            foreach ($lv_vendors as $vendor) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($vendor['vendor_id']) . "</td>";
                echo "<td>" . htmlspecialchars($vendor['vendor_name']) . "</td>";
                echo "<td>" . ($vendor['is_approved'] ? '✓ Yes' : '✗ No') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color:red;'>❌ <strong>NO VENDORS FOUND</strong> for this admin</p>";
        }
        
        // Check file batches for this admin
        echo "<h2>3. File Batches for Admin {$adminId}</h2>";
        $batch_query = "SELECT id, vendor_id, product_code, original_filename, upload_time FROM lv_file_batches WHERE admin_id = ? ORDER BY upload_time DESC LIMIT 20";
        $batch_stmt = $conn->prepare($batch_query);
        $batch_stmt->bind_param("s", $adminId);
        $batch_stmt->execute();
        $batches = $batch_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $batch_stmt->close();
        
        if (count($batches) > 0) {
            echo "<p style='color:green;'>✓ Found " . count($batches) . " file batches</p>";
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Batch ID</th><th>Vendor ID</th><th>Product</th><th>Filename</th><th>Upload Time</th></tr>";
            foreach ($batches as $batch) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($batch['id']) . "</td>";
                echo "<td>" . htmlspecialchars($batch['vendor_id']) . "</td>";
                echo "<td>" . htmlspecialchars($batch['product_code']) . "</td>";
                echo "<td>" . htmlspecialchars($batch['original_filename']) . "</td>";
                echo "<td>" . $batch['upload_time'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color:red;'>❌ <strong>NO FILE BATCHES FOUND</strong> for this admin</p>";
        }
        
        // Check call logs for this admin's batches
        echo "<h2>4. Call Logs Summary</h2>";
        $logs_query = "
            SELECT 
                COUNT(*) as total_logs,
                COUNT(DISTINCT fcl.batch_id) as batches_with_logs,
                SUM(CASE WHEN fcl.status = 'fresh' THEN 1 ELSE 0 END) as fresh_records,
                SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) as processed_records
            FROM lv_final_call_logs fcl 
            JOIN lv_file_batches fb ON fcl.batch_id = fb.id 
            WHERE fb.admin_id = ?
        ";
        $logs_stmt = $conn->prepare($logs_query);
        $logs_stmt->bind_param("s", $adminId);
        $logs_stmt->execute();
        $logs_summary = $logs_stmt->get_result()->fetch_assoc();
        $logs_stmt->close();
        
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Metric</th><th>Count</th></tr>";
        echo "<tr><td>Total Call Logs</td><td>" . number_format($logs_summary['total_logs']) . "</td></tr>";
        echo "<tr><td>Batches with Logs</td><td>" . $logs_summary['batches_with_logs'] . "</td></tr>";
        echo "<tr><td>Fresh (Unprocessed)</td><td>" . number_format($logs_summary['fresh_records']) . "</td></tr>";
        echo "<tr><td>Processed</td><td>" . number_format($logs_summary['processed_records']) . "</td></tr>";
        echo "</table>";
        
        // Test the actual monthly trends query
        echo "<h2>5. Monthly Trends Query Test</h2>";
        $monthly_query = "
            SELECT 
                COALESCE(v.vendor_id, fb.vendor_id) as vendor_id,
                COALESCE(v.vendor_name, fb.vendor_id) as vendor_name,
                DATE_FORMAT(fb.upload_time, '%Y-%m') as month_year,
                COUNT(DISTINCT fb.id) as batches_uploaded,
                COALESCE(COUNT(fcl.id), 0) as records_received,
                COALESCE(SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END), 0) as records_processed
            FROM lv_file_batches fb
            LEFT JOIN lv_vendors v ON fb.vendor_id = v.vendor_id AND v.admin_id = fb.admin_id
            LEFT JOIN lv_final_call_logs fcl ON fb.id = fcl.batch_id
            WHERE fb.admin_id = ? AND (v.is_approved = 1 OR v.is_approved IS NULL)
            GROUP BY COALESCE(v.vendor_id, fb.vendor_id), COALESCE(v.vendor_name, fb.vendor_id), DATE_FORMAT(fb.upload_time, '%Y-%m')
            ORDER BY month_year DESC, batches_uploaded DESC
            LIMIT 12
        ";
        
        $monthly_stmt = $conn->prepare($monthly_query);
        $monthly_stmt->bind_param("s", $adminId);
        $monthly_stmt->execute();
        $monthly_results = $monthly_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $monthly_stmt->close();
        
        if (count($monthly_results) > 0) {
            echo "<p style='color:green;'>✅ <strong>SUCCESS!</strong> Monthly trends query returned " . count($monthly_results) . " results</p>";
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Vendor</th><th>Month</th><th>Batches</th><th>Records</th><th>Processed</th></tr>";
            foreach ($monthly_results as $result) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($result['vendor_name']) . "</td>";
                echo "<td>" . htmlspecialchars($result['month_year']) . "</td>";
                echo "<td>" . $result['batches_uploaded'] . "</td>";
                echo "<td>" . $result['records_received'] . "</td>";
                echo "<td>" . $result['records_processed'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color:red;'>❌ Monthly trends query returned NO RESULTS</p>";
        }
        
        // Final diagnosis
        echo "<h2>6. Final Diagnosis</h2>";
        if (count($batches) == 0) {
            echo "<div style='background:#ffebee; padding:15px; border-left:4px solid #f44336;'>";
            echo "<h3 style='color:#c62828; margin-top:0;'>ROOT CAUSE IDENTIFIED</h3>";
            echo "<p><strong>The admin suresh.negi@finqy.ai has NO FILE BATCHES uploaded.</strong></p>";
            echo "<p>This is why the monthly performance trends are blank.</p>";
            echo "<h4>Solutions:</h4>";
            echo "<ol>";
            echo "<li><strong>Upload batches:</strong> Use the batch upload functionality in the admin panel</li>";
            echo "<li><strong>Create lv_vendors first:</strong> Set up lv_vendors before uploading batches</li>";
            echo "<li><strong>Check upload permissions:</strong> Ensure the admin can access upload features</li>";
            echo "</ol>";
            echo "</div>";
        } elseif (count($monthly_results) > 0) {
            echo "<div style='background:#e8f5e8; padding:15px; border-left:4px solid #4caf50;'>";
            echo "<h3 style='color:#2e7d32; margin-top:0;'>DATA FOUND!</h3>";
            echo "<p>The monthly trends query is working and returning data.</p>";
            echo "<p>If the chart is still blank, it might be a JavaScript or rendering issue.</p>";
            echo "</div>";
        } else {
            echo "<div style='background:#fff3e0; padding:15px; border-left:4px solid #ff9800;'>";
            echo "<h3 style='color:#ef6c00; margin-top:0;'>DATA EXISTS BUT QUERY FAILS</h3>";
            echo "<p>File batches exist but the monthly trends query is not returning results.</p>";
            echo "<p>This could be due to vendor approval issues or JOIN conditions.</p>";
            echo "</div>";
        }
        
    } else {
        echo "<p style='color:red;'>Could not find admin with exact email suresh.negi@finqy.ai</p>";
    }
    
} else {
    echo "<p style='color:red;'>No admin found with email suresh.negi@finqy.ai or username containing 'suresh'</p>";
}

$conn->close();
echo "</body></html>";
?>