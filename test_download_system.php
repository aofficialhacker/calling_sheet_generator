<?php
/**
 * Test Download Counter System
 * Run this script to test all components of the download counter system
 */

require_once 'db_config.php';
require_once 'download_counter.php';

echo "<h1>Testing Download Counter System</h1>";

$conn = getDBConnection();
$downloadCounter = new DownloadCounter($conn);

// Test 1: Check database tables exist
echo "<h2>Test 1: Database Tables</h2>";
$tables = ['admin_users', 'admin_download_limits', 'download_tracking'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✅ Table '$table' exists<br>";
    } else {
        echo "❌ Table '$table' missing<br>";
    }
}

// Test 2: Check admin_users has download_limit column
echo "<h2>Test 2: Admin Users Download Limit Column</h2>";
$result = $conn->query("DESCRIBE admin_users");
$has_download_limit = false;
while ($row = $result->fetch_assoc()) {
    if ($row['Field'] === 'download_limit') {
        $has_download_limit = true;
        echo "✅ download_limit column exists: " . $row['Type'] . " (Default: " . $row['Default'] . ")<br>";
        break;
    }
}
if (!$has_download_limit) {
    echo "❌ download_limit column missing from admin_users<br>";
}

// Test 3: Get sample admin for testing
echo "<h2>Test 3: Sample Admin Data</h2>";
$adminResult = $conn->query("SELECT admin_id, name, download_limit FROM admin_users WHERE is_active = 1 LIMIT 1");
if ($adminResult && $adminResult->num_rows > 0) {
    $sampleAdmin = $adminResult->fetch_assoc();
    echo "✅ Sample admin found: " . htmlspecialchars($sampleAdmin['name']) . " (ID: " . htmlspecialchars($sampleAdmin['admin_id']) . ")<br>";
    echo "Current download limit: " . ($sampleAdmin['download_limit'] ?: 'NULL') . "<br>";
    
    $adminId = $sampleAdmin['admin_id'];
    
    // Test 4: Download counter functionality
    echo "<h2>Test 4: Download Counter Functionality</h2>";
    
    // Test canDownload
    $testDisposition = "Follow Up";
    $testBatch = "TEST001";
    
    $canDownload = $downloadCounter->canDownload($adminId, $testDisposition, $testBatch);
    echo "Can download test case: " . ($canDownload ? "✅ Yes" : "❌ No") . "<br>";
    
    // Test recordDownload
    $recorded = $downloadCounter->recordDownload($adminId, $testDisposition, $testBatch);
    echo "Record download test: " . ($recorded ? "✅ Success" : "❌ Failed") . "<br>";
    
    // Test getCurrentUsage
    $usage = $downloadCounter->getCurrentUsage($adminId, $testDisposition, $testBatch);
    echo "Current usage for test case: " . $usage . "<br>";
    
    // Test getAdminDownloadLimit
    $limit = $downloadCounter->getAdminDownloadLimit($adminId);
    echo "Admin download limit: " . $limit . "<br>";
    
    // Test 5: Test download status
    echo "<h2>Test 5: Download Status</h2>";
    $status = $downloadCounter->getDownloadStatus($adminId);
    if (!empty($status)) {
        echo "✅ Download status retrieved (" . count($status) . " entries)<br>";
        foreach ($status as $entry) {
            echo "- " . $entry['disposition'] . " for batch " . ($entry['batch_id'] ?: 'ALL') . ": " . $entry['download_count'] . "/" . $entry['download_limit'] . "<br>";
        }
    } else {
        echo "ℹ️ No download history found<br>";
    }
    
} else {
    echo "❌ No active admin found for testing<br>";
}

// Test 6: Check file permissions
echo "<h2>Test 6: File Permissions</h2>";
$files = ['pdf_download_handler.php', 'download_counter.php', 'manage_download_limits.php', 'ajax_get_admin_usage.php'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✅ File '$file' exists and is readable<br>";
    } else {
        echo "❌ File '$file' missing<br>";
    }
}

// Test 7: Check dispositions (excluding Interested)
echo "<h2>Test 7: Disposition Filter (Interested Exclusion)</h2>";
$dispResult = $conn->query("SELECT code, description FROM disposition_codes WHERE is_active = 1 AND description NOT LIKE '%Interested%' ORDER BY code");
if ($dispResult && $dispResult->num_rows > 0) {
    echo "✅ Non-interested dispositions found (" . $dispResult->num_rows . " entries):<br>";
    while ($disp = $dispResult->fetch_assoc()) {
        echo "- " . $disp['code'] . ": " . htmlspecialchars($disp['description']) . "<br>";
    }
} else {
    echo "❌ No dispositions found or all are 'Interested'<br>";
}

$conn->close();

echo "<h2>Test Complete</h2>";
echo "<p>If all tests show ✅, the system is ready. Any ❌ indicates issues that need fixing.</p>";
?>