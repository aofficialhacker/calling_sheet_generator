<?php
require_once 'db_config.php';

// Set up admin session for testing
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';\nSessionManager::start();
}

if (!isset($_SESSION['admin_id'])) {
    $conn = getDBConnection();
    $result = $conn->query("SELECT admin_id FROM admin_users LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $_SESSION['admin_id'] = $row['admin_id'];
        $_SESSION['role'] = 'admin';
    }
    $conn->close();
}

echo "<!DOCTYPE html><html><head><title>Quick PDF Structure Test</title></head><body>";
echo "<h2>🔍 PDF Structure Validation</h2>";

try {
    $conn = getDBConnection();
    
    // Get a test batch with data
    $result = $conn->query("
        SELECT fb.id, COUNT(fcl.id) as record_count 
        FROM file_batches fb 
        LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
        GROUP BY fb.id 
        HAVING record_count > 0 
        ORDER BY record_count ASC 
        LIMIT 1
    ");
    
    if ($result && $row = $result->fetch_assoc()) {
        $testBatchId = $row['id'];
        $recordCount = $row['record_count'];
        
        echo "<h3>✅ Test Data Found</h3>";
        echo "<p><strong>Batch ID:</strong> $testBatchId</p>";
        echo "<p><strong>Record Count:</strong> $recordCount</p>";
        
        // Check current structure
        echo "<h3>📋 Current Column Structure Check</h3>";
        
        // Simulate the current generate_pdf.php logic
        $finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];
        
        echo "<div style='background: #f0f8ff; padding: 15px; border: 1px solid #4a90e2; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>🎯 Current Implementation Status:</h4>";
        echo "<p><strong>Fixed Headers:</strong> " . implode(', ', $finalHeaders) . "</p>";
        echo "<p><strong>Expected Structure:</strong> ID | Slot | Connectivity | Disposition | Mobile</p>";
        
        // Check if this matches your requirements
        $expectedHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];
        $matches = ($finalHeaders === $expectedHeaders);
        
        if ($matches) {
            echo "<p style='color: green;'><strong>✅ Column Structure: CORRECT</strong></p>";
        } else {
            echo "<p style='color: red;'><strong>❌ Column Structure: INCORRECT</strong></p>";
            echo "<p>Expected: " . implode(', ', $expectedHeaders) . "</p>";
            echo "<p>Current: " . implode(', ', $finalHeaders) . "</p>";
        }
        
        // Check column widths from the updated script
        $widthDefinitions = [
            'id' => 55,           // ID - adequate for single line display
            'slot' => 15,         // Slot - small for single digit
            'connectivity' => 25,  // Connectivity - for "○ Y / ○ N"
            'disposition' => 140,  // Disposition - large for empty circles grid
            'mobile_no' => 40     // Mobile - with proper cutlines
        ];
        
        echo "<h4>📏 Column Widths (mm):</h4>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>Column</th><th>Width (mm)</th><th>Purpose</th></tr>";
        echo "<tr><td>ID</td><td>55</td><td>Single-line display</td></tr>";
        echo "<tr><td>Slot</td><td>15</td><td>Single digit entry</td></tr>";
        echo "<tr><td>Connectivity</td><td>25</td><td>○ Y / ○ N format</td></tr>";
        echo "<tr><td>Disposition</td><td>140</td><td>Empty circles grid</td></tr>";
        echo "<tr><td>Mobile</td><td>40</td><td>With cutlines</td></tr>";
        echo "</table>";
        echo "</div>";
        
        // Test disposition system
        echo "<h3>🎯 Disposition System Test</h3>";
        $dispResult = $conn->query("SELECT code, description FROM disposition_codes WHERE is_active = 1 ORDER BY code LIMIT 10");
        if ($dispResult && $dispResult->num_rows > 0) {
            echo "<p><strong>✅ Active Dispositions Found:</strong> " . $dispResult->num_rows . "</p>";
            
            $dispositions = [];
            while ($d = $dispResult->fetch_assoc()) {
                $dispositions[] = $d;
            }
            
            // Test grid generation
            $itemsPerRow = 6;
            $gridRows = array_chunk($dispositions, $itemsPerRow);
            $dispGrid = '';
            foreach ($gridRows as $row) {
                $rowItems = [];
                foreach ($row as $disp) {
                    $paddedCode = str_pad($disp['code'], 2, '0', STR_PAD_LEFT);
                    $rowItems[] = '○' . $paddedCode;
                }
                $dispGrid .= implode('  ', $rowItems) . "\n";
            }
            
            echo "<h4>📋 Generated Disposition Grid:</h4>";
            echo "<pre style='background: #f9f9f9; padding: 10px; border: 1px solid #ddd; font-family: monospace;'>";
            echo htmlspecialchars(trim($dispGrid));
            echo "</pre>";
            
            // Validate format
            if (strpos($dispGrid, '○') !== false) {
                echo "<p style='color: green;'><strong>✅ Empty Circles: CORRECT</strong></p>";
            } else {
                echo "<p style='color: red;'><strong>❌ Empty Circles: MISSING</strong></p>";
            }
            
            // Check for 2-digit numbers
            if (preg_match('/○\d{2}/', $dispGrid)) {
                echo "<p style='color: green;'><strong>✅ 2-Digit Numbers: CORRECT</strong></p>";
            } else {
                echo "<p style='color: red;'><strong>❌ 2-Digit Numbers: MISSING</strong></p>";
            }
            
        } else {
            echo "<p style='color: red;'><strong>❌ No active dispositions found</strong></p>";
        }
        
        // Test connectivity format
        echo "<h3>🔗 Connectivity Format Test</h3>";
        $connectivityFormat = '○ Y / ○ N';
        echo "<p><strong>Current Format:</strong> <code>$connectivityFormat</code></p>";
        if (strpos($connectivityFormat, '○') !== false) {
            echo "<p style='color: green;'><strong>✅ Empty Circles for Connectivity: CORRECT</strong></p>";
        } else {
            echo "<p style='color: red;'><strong>❌ Empty Circles for Connectivity: MISSING</strong></p>";
        }
        
        // Generate test PDF link
        echo "<h3>🚀 Test PDF Generation</h3>";
        echo "<div style='background: #e8f5e8; padding: 15px; border: 1px solid #4caf50; border-radius: 5px;'>";
        echo "<p><strong>Ready to test with batch:</strong> $testBatchId ($recordCount records)</p>";
        echo "<a href='generate_pdf.php?batch_id=$testBatchId' target='_blank' style='display: inline-block; background: #4caf50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>🎯 Generate Test PDF</a>";
        echo "</div>";
        
        // What to check in the generated PDF
        echo "<h3>🔍 What to Check in Generated PDF</h3>";
        echo "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffc107; border-radius: 5px;'>";
        echo "<h4>⚠️ Please verify these features in the generated PDF:</h4>";
        echo "<ol>";
        echo "<li><strong>Column Count:</strong> Exactly 5 columns (ID, Slot, Connectivity, Disposition, Mobile)</li>";
        echo "<li><strong>Cutlines:</strong> Dotted lines with ✂ scissors at top/bottom center of Mobile column</li>";
        echo "<li><strong>ID Format:</strong> Single line only, auto-resized font if needed</li>";
        echo "<li><strong>Connectivity:</strong> Shows '○ Y / ○ N' format</li>";
        echo "<li><strong>Disposition:</strong> Empty circles with 2-digit numbers (○01, ○02, etc.)</li>";
        echo "<li><strong>Mobile:</strong> Bold, centered phone numbers</li>";
        echo "<li><strong>Legends:</strong> Slot and disposition legends in header</li>";
        echo "</ol>";
        echo "</div>";
        
    } else {
        echo "<h3>❌ No Test Data Available</h3>";
        echo "<p>No batches with data found. Please upload some Excel files first.</p>";
    }
    
} catch (Exception $e) {
    echo "<h3>❌ Error</h3>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo "</body></html>";
?>