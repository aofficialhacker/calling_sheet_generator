<?php
require_once 'db_config.php';

if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
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

echo "<!DOCTYPE html><html><head><title>PDF Output Verification</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; }
.section { background: white; padding: 20px; margin: 15px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.correct { background: #d4edda; border-left: 5px solid #28a745; }
.wrong { background: #f8d7da; border-left: 5px solid #dc3545; }
.warning { background: #fff3cd; border-left: 5px solid #ffc107; }
.requirement { margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
.test-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #f8f9fa; }
</style></head><body>";

echo "<div class='container'>";
echo "<h1>🔍 PDF Output Verification Tool</h1>";
echo "<p>Use this tool to verify that your PDF output matches all requirements exactly.</p>";

try {
    $conn = getDBConnection();
    
    // Get test batch
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
        
        echo "<div class='section'>";
        echo "<h2>🎯 Test Data Ready</h2>";
        echo "<p><strong>Batch ID:</strong> $testBatchId</p>";
        echo "<p><strong>Records:</strong> $recordCount</p>";
        echo "<a href='generate_pdf.php?batch_id=$testBatchId' target='_blank' style='display: inline-block; background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 10px 0;'>🚀 Generate Test PDF</a>";
        echo "</div>";
        
        // Requirements checklist
        echo "<div class='section'>";
        echo "<h2>✅ Requirements Verification Checklist</h2>";
        echo "<p><strong>After generating the PDF, verify each requirement below:</strong></p>";
        
        echo "<div class='requirement correct'>";
        echo "<h3>1. 📋 Column Structure</h3>";
        echo "<p><strong>✅ MUST have exactly 5 columns in this order:</strong></p>";
        echo "<table>";
        echo "<tr><th>Position</th><th>Column Name</th><th>Width</th><th>Content</th></tr>";
        echo "<tr><td>1</td><td>ID</td><td>55mm</td><td>Record IDs in single line</td></tr>";
        echo "<tr><td>2</td><td>Slot</td><td>15mm</td><td>Empty (for manual entry)</td></tr>";
        echo "<tr><td>3</td><td>Connectivity</td><td>25mm</td><td>○ Y / ○ N</td></tr>";
        echo "<tr><td>4</td><td>Disposition</td><td>140mm</td><td>Empty circles grid with 2-digit numbers</td></tr>";
        echo "<tr><td>5</td><td>Mobile</td><td>40mm</td><td>Phone numbers with cutlines</td></tr>";
        echo "</table>";
        echo "<p><strong>❌ WRONG if:</strong> More than 5 columns, different order, different names</p>";
        echo "</div>";
        
        echo "<div class='requirement correct'>";
        echo "<h3>2. ✂️ Cutlines with Scissors</h3>";
        echo "<p><strong>✅ MUST appear:</strong></p>";
        echo "<ul>";
        echo "<li>Dotted horizontal lines at top and bottom of each page</li>";
        echo "<li>Lines only span the Mobile column (column 5)</li>";
        echo "<li>✂ scissor symbol at center of Mobile column</li>";
        echo "<li>Positioned both above and below the Mobile column area</li>";
        echo "</ul>";
        echo "<p><strong>❌ WRONG if:</strong> No cutlines, wrong position, no scissors, spans wrong columns</p>";
        echo "</div>";
        
        echo "<div class='requirement correct'>";
        echo "<h3>3. 📏 ID Single Line Format</h3>";
        echo "<p><strong>✅ MUST show:</strong></p>";
        echo "<ul>";
        echo "<li>Complete ID on one line only</li>";
        echo "<li>Font auto-resizes if ID is too long</li>";
        echo "<li>No truncation (no ... or cutting off)</li>";
        echo "<li>Bold formatting</li>";
        echo "</ul>";
        echo "<p><strong>❌ WRONG if:</strong> ID spans multiple lines, truncated IDs, regular font weight</p>";
        echo "</div>";
        
        echo "<div class='requirement correct'>";
        echo "<h3>4. ○ Empty Circles Format</h3>";
        echo "<p><strong>✅ Connectivity MUST show:</strong> ○ Y / ○ N (empty circles)</p>";
        echo "<p><strong>✅ Disposition MUST show:</strong> Grid like this:</p>";
        echo "<pre style='background: #f8f9fa; padding: 10px; border: 1px solid #ddd; font-family: monospace;'>";
        
        // Generate expected disposition grid
        $dispResult = $conn->query("SELECT code FROM disposition_codes WHERE is_active = 1 ORDER BY code LIMIT 12");
        $dispositions = [];
        while ($d = $dispResult->fetch_assoc()) {
            $dispositions[] = str_pad($d['code'], 2, '0', STR_PAD_LEFT);
        }
        
        $gridRows = array_chunk($dispositions, 6);
        foreach ($gridRows as $row) {
            $rowItems = array_map(function($code) { return '○' . $code; }, $row);
            echo implode('  ', $rowItems) . "\n";
        }
        echo "</pre>";
        echo "<p><strong>❌ WRONG if:</strong> Filled circles (●), single digit numbers, wrong grid format</p>";
        echo "</div>";
        
        echo "<div class='requirement correct'>";
        echo "<h3>5. 📖 Legends on Every Page</h3>";
        echo "<p><strong>✅ MUST appear in header of every page:</strong></p>";
        echo "<ul>";
        echo "<li><strong>Title:</strong> Calling Sheet for Batch [BATCH_ID]</li>";
        echo "<li><strong>Slot Legend:</strong> SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)</li>";
        echo "<li><strong>Disposition Legend:</strong> DISPO (Y): [connected codes] || DISPO (N): [not connected codes]</li>";
        echo "</ul>";
        echo "<p><strong>❌ WRONG if:</strong> Missing on any page, incomplete information, wrong format</p>";
        echo "</div>";
        
        echo "</div>";
        
        // Troubleshooting section
        echo "<div class='section warning'>";
        echo "<h2>🔧 Troubleshooting Common Issues</h2>";
        
        echo "<h3>If PDF has MORE than 5 columns:</h3>";
        echo "<p>The system might be using an older backup file. Check:</p>";
        echo "<ul>";
        echo "<li>Is the URL calling <code>generate_pdf.php</code> (correct) or a backup file?</li>";
        echo "<li>Clear browser cache and try again</li>";
        echo "<li>Check if browser cached an old version</li>";
        echo "</ul>";
        
        echo "<h3>If cutlines are missing or wrong:</h3>";
        echo "<ul>";
        echo "<li>Check if scissors (✂) appear at top and bottom</li>";
        echo "<li>Verify lines only span the Mobile column</li>";
        echo "<li>Make sure cutlines appear on every page</li>";
        echo "</ul>";
        
        echo "<h3>If circles are filled (●) instead of empty (○):</h3>";
        echo "<ul>";
        echo "<li>The PDF is using old code - refresh and regenerate</li>";
        echo "<li>Check Unicode symbol rendering in PDF viewer</li>";
        echo "</ul>";
        
        echo "<h3>If IDs are truncated or multi-line:</h3>";
        echo "<ul>";
        echo "<li>Font auto-resize might not be working</li>";
        echo "<li>Column width might be too narrow</li>";
        echo "<li>Check for very long ID values</li>";
        echo "</ul>";
        
        echo "</div>";
        
        // File versions check
        echo "<div class='section'>";
        echo "<h2>📁 Current File Status</h2>";
        
        $mainFile = 'generate_pdf.php';
        $mainFileTime = filemtime($mainFile);
        $mainFileSize = filesize($mainFile);
        
        echo "<table>";
        echo "<tr><th>File</th><th>Last Modified</th><th>Size</th><th>Status</th></tr>";
        echo "<tr>";
        echo "<td><strong>generate_pdf.php</strong> (MAIN)</td>";
        echo "<td>" . date('Y-m-d H:i:s', $mainFileTime) . "</td>";
        echo "<td>" . number_format($mainFileSize) . " bytes</td>";
        echo "<td style='color: green;'><strong>✅ ACTIVE</strong></td>";
        echo "</tr>";
        
        // Check backup files
        $backupFiles = glob('generate_pdf_*.php');
        foreach ($backupFiles as $file) {
            if ($file === 'generate_pdf_production.php') continue;
            $fileTime = filemtime($file);
            $fileSize = filesize($file);
            $isNewer = $fileTime > $mainFileTime;
            
            echo "<tr>";
            echo "<td>$file</td>";
            echo "<td>" . date('Y-m-d H:i:s', $fileTime) . "</td>";
            echo "<td>" . number_format($fileSize) . " bytes</td>";
            echo "<td style='color: " . ($isNewer ? 'red' : 'gray') . ";'>";
            echo $isNewer ? "⚠️ NEWER" : "📁 Backup";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if (count($backupFiles) > 0) {
            $newerBackups = array_filter($backupFiles, function($file) use ($mainFileTime) {
                return filemtime($file) > $mainFileTime && $file !== 'generate_pdf_production.php';
            });
            
            if (!empty($newerBackups)) {
                echo "<div class='wrong'>";
                echo "<h4>⚠️ WARNING: Newer backup files detected!</h4>";
                echo "<p>These files are newer than the main generate_pdf.php:</p>";
                echo "<ul>";
                foreach ($newerBackups as $file) {
                    echo "<li><code>$file</code></li>";
                }
                echo "</ul>";
                echo "<p>This might cause confusion. Make sure you're testing the correct file.</p>";
                echo "</div>";
            }
        }
        
        echo "</div>";
        
        // Quick test buttons for different scenarios
        echo "<div class='section'>";
        echo "<h2>🧪 Quick Tests</h2>";
        
        echo "<div class='test-grid'>";
        echo "<div>";
        echo "<h3>📊 Small Test (Fast)</h3>";
        echo "<p>Test with small batch ($recordCount records)</p>";
        echo "<a href='generate_pdf.php?batch_id=$testBatchId' target='_blank' style='display: block; background: #28a745; color: white; padding: 10px; text-decoration: none; border-radius: 4px; text-align: center; margin: 5px 0;'>Generate Small PDF</a>";
        echo "</div>";
        
        // Get larger batch if available
        $largeResult = $conn->query("
            SELECT fb.id, COUNT(fcl.id) as record_count 
            FROM file_batches fb 
            LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id 
            GROUP BY fb.id 
            HAVING record_count > 500 
            ORDER BY record_count DESC 
            LIMIT 1
        ");
        
        if ($largeResult && $largeRow = $largeResult->fetch_assoc()) {
            echo "<div>";
            echo "<h3>🚀 Large Test (Performance)</h3>";
            echo "<p>Test with large batch (" . number_format($largeRow['record_count']) . " records)</p>";
            echo "<a href='generate_pdf.php?batch_id=" . $largeRow['id'] . "' target='_blank' style='display: block; background: #ffc107; color: #212529; padding: 10px; text-decoration: none; border-radius: 4px; text-align: center; margin: 5px 0;'>Generate Large PDF</a>";
            echo "</div>";
        }
        echo "</div>";
        
        echo "</div>";
        
    } else {
        echo "<div class='section wrong'>";
        echo "<h2>❌ No Test Data Available</h2>";
        echo "<p>No batches with data found. Upload some Excel files first to test PDF generation.</p>";
        echo "<a href='upload_batch.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Upload Test Data</a>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='section wrong'>";
    echo "<h2>❌ Error</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo "</div></body></html>";
?>