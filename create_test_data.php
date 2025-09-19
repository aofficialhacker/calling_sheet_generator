<?php
/**
 * Test Data Creation Script
 * Use this to create sample data for testing monthly trends
 * CAUTION: Only use this in development environments!
 */
require_once 'db_config.php';

// Only run in development - add safety check
if ($_SERVER['SERVER_NAME'] !== 'localhost' && strpos($_SERVER['SERVER_NAME'], '127.0.0.1') === false) {
    die("This script can only be run in development environments!");
}

$conn = getDBConnection();

echo "<html><head><title>Test Data Creator</title></head><body>";
echo "<h1>Test Data Creation Tool</h1>";
echo "<p style='color:red;'><strong>WARNING:</strong> This will create test data in your database!</p>";

if ($_POST['create_data'] ?? false) {
    
    $adminId = $_POST['admin_id'] ?? '';
    if (empty($adminId)) {
        echo "<p style='color:red;'>Please select an admin ID!</p>";
    } else {
        echo "<h2>Creating Test Data for Admin: {$adminId}</h2>";
        
        try {
            // Create test vendor if needed
            $vendorId = "V" . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT);
            $vendorName = "Test Vendor " . mt_rand(1, 999);
            
            $vendor_check = $conn->prepare("SELECT COUNT(*) as count FROM lv_vendors WHERE admin_id = ?");
            $vendor_check->bind_param("s", $adminId);
            $vendor_check->execute();
            $vendor_count = $vendor_check->get_result()->fetch_assoc()['count'];
            $vendor_check->close();
            
            if ($vendor_count == 0) {
                $vendor_stmt = $conn->prepare("INSERT INTO lv_vendors (vendor_id, vendor_name, admin_id, is_approved) VALUES (?, ?, ?, 1)");
                $vendor_stmt->bind_param("sss", $vendorId, $vendorName, $adminId);
                $vendor_stmt->execute();
                $vendor_stmt->close();
                echo "<p style='color:green;'>✓ Created test vendor: {$vendorId} - {$vendorName}</p>";
            } else {
                // Use existing vendor
                $existing_vendor = $conn->prepare("SELECT vendor_id, vendor_name FROM lv_vendors WHERE admin_id = ? LIMIT 1");
                $existing_vendor->bind_param("s", $adminId);
                $existing_vendor->execute();
                $vendor_data = $existing_vendor->get_result()->fetch_assoc();
                $existing_vendor->close();
                $vendorId = $vendor_data['vendor_id'];
                echo "<p style='color:blue;'>Using existing vendor: {$vendorId} - {$vendor_data['vendor_name']}</p>";
            }
            
            // Create test batches for the last 6 months
            $created_batches = 0;
            for ($month = 0; $month < 6; $month++) {
                $upload_date = date('Y-m-d H:i:s', strtotime("-{$month} months"));
                $batch_count = mt_rand(1, 3); // 1-3 batches per month
                
                for ($batch = 1; $batch <= $batch_count; $batch++) {
                    $batchId = "TB" . date('Ym', strtotime($upload_date)) . str_pad($batch, 2, '0', STR_PAD_LEFT) . $adminId;
                    $productCode = "PROD" . chr(65 + mt_rand(0, 4)); // PRODA to PRODE
                    $filename = "test_batch_" . date('Y_m', strtotime($upload_date)) . "_{$batch}.xlsx";
                    
                    // Check if batch already exists
                    $batch_check = $conn->prepare("SELECT COUNT(*) as count FROM lv_file_batches WHERE id = ?");
                    $batch_check->bind_param("s", $batchId);
                    $batch_check->execute();
                    $exists = $batch_check->get_result()->fetch_assoc()['count'] > 0;
                    $batch_check->close();
                    
                    if (!$exists) {
                        $batch_stmt = $conn->prepare("INSERT INTO lv_file_batches (id, admin_id, vendor_id, product_code, original_filename, upload_time) VALUES (?, ?, ?, ?, ?, ?)");
                        $batch_stmt->bind_param("ssssss", $batchId, $adminId, $vendorId, $productCode, $filename, $upload_date);
                        $batch_stmt->execute();
                        $batch_stmt->close();
                        
                        // Create some test call logs for this batch
                        $record_count = mt_rand(50, 200);
                        for ($record = 1; $record <= $record_count; $record++) {
                            $finqyId = $batchId . "_" . str_pad($record, 4, '0', STR_PAD_LEFT);
                            $mobile = "98765" . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                            $name = "Test Customer " . $record;
                            $status = mt_rand(1, 10) <= 3 ? 'Called' : 'fresh'; // 30% processed
                            
                            $log_stmt = $conn->prepare("INSERT INTO lv_final_call_logs (finqy_id, batch_id, mobile_no, name, status) VALUES (?, ?, ?, ?, ?)");
                            $log_stmt->bind_param("sssss", $finqyId, $batchId, $mobile, $name, $status);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        
                        $created_batches++;
                        echo "<p style='color:green;'>✓ Created batch {$batchId} with {$record_count} records for " . date('M Y', strtotime($upload_date)) . "</p>";
                    }
                }
            }
            
            echo "<h3 style='color:green;'>Test Data Creation Complete!</h3>";
            echo "<p><strong>Created:</strong> {$created_batches} new batches</p>";
            echo "<p><a href='admin_batchwise_analytics.php' target='_blank'>→ View Analytics Dashboard</a></p>";
            echo "<p><a href='debug_monthly_trends.php' target='_blank'>→ Run Diagnostic Tool</a></p>";
            
        } catch (Exception $e) {
            echo "<p style='color:red;'>Error creating test data: " . $e->getMessage() . "</p>";
        }
    }
    
} else {
    // Show form
    echo "<h2>Select Admin for Test Data Creation</h2>";
    
    $admins_query = "SELECT admin_id, username FROM lv_admin_users WHERE is_active = 1 ORDER BY admin_id";
    $admins_result = $conn->query($admins_query);
    
    if ($admins_result && $admins_result->num_rows > 0) {
        echo "<form method='POST'>";
        echo "<p><label>Select Admin:</label><br>";
        echo "<select name='admin_id' required style='padding: 5px; margin: 5px;'>";
        echo "<option value=''>-- Choose Admin --</option>";
        while ($admin = $admins_result->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($admin['admin_id']) . "'>";
            echo htmlspecialchars($admin['admin_id']) . " - " . htmlspecialchars($admin['username']);
            echo "</option>";
        }
        echo "</select></p>";
        echo "<p><input type='submit' name='create_data' value='Create Test Data' style='padding: 10px 20px; background: #007cba; color: white; border: none; cursor: pointer;'></p>";
        echo "</form>";
        
        echo "<h3>What this will create:</h3>";
        echo "<ul>";
        echo "<li>Test vendor (if none exists for the admin)</li>";
        echo "<li>6 months of sample batches (1-3 per month)</li>";
        echo "<li>50-200 call log records per batch</li>";
        echo "<li>~30% of records marked as processed</li>";
        echo "</ul>";
    } else {
        echo "<p style='color:red;'>No active admins found!</p>";
    }
}

$conn->close();
echo "</body></html>";
?>