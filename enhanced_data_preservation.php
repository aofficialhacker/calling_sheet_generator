<?php
/**
 * Enhanced Data Preservation Analysis & Implementation Plan
 * Addresses complete data preservation beyond just slot column
 */

require_once 'db_config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    die("Please log in as admin to view this analysis.");
}

$conn = getDBConnection();

echo "<!DOCTYPE html><html><head><title>Complete Data Preservation Analysis</title>";
echo "<style>
    body{font-family:Arial,sans-serif;margin:20px;} 
    .success{color:green;} .error{color:red;} .info{color:blue;} .warning{color:orange;}
    .section{border:1px solid #ddd;padding:20px;margin:15px 0;border-radius:8px;}
    .data-field{background:#f8f9fa;padding:10px;margin:5px 0;border-left:4px solid #007bff;}
    .risk-high{border-left-color:#dc3545;} .risk-medium{border-left-color:#ffc107;} .risk-low{border-left-color:#28a745;}
    .solution{background:#e8f5e9;padding:15px;border-radius:5px;margin:10px 0;}
    .code-block{background:#f1f1f1;padding:15px;border-radius:5px;font-family:monospace;white-space:pre-wrap;}
</style></head><body>";

echo "<h1>🔒 Complete Data Preservation Analysis</h1>";
echo "<div class='info'><strong>Problem:</strong> When leads are redistributed or re-attempted, ALL previously marked data (not just slot) should be preserved to prevent data loss and enable performance comparison.</div>";

// Analyze current data fields that could be lost
echo "<div class='section'>";
echo "<h2>📊 Data Fields at Risk of Loss</h2>";

$at_risk_fields = [
    'slot' => ['risk' => 'high', 'description' => 'Time slot when customer was contacted', 'example' => '3 (12-1pm)'],
    'disposition' => ['risk' => 'high', 'description' => 'Call outcome (Interested, Follow Up, etc.)', 'example' => 'Follow Up'],
    'connectivity' => ['risk' => 'medium', 'description' => 'Connection status (Yes/No)', 'example' => 'Yes'],
    'processed_at' => ['risk' => 'high', 'description' => 'Timestamp when call was made', 'example' => '2024-01-15 14:30:00'],
    'finqy_id' => ['risk' => 'high', 'description' => 'Which caller worked on this lead', 'example' => 'CALLER001'],
    'notes' => ['risk' => 'medium', 'description' => 'Any additional notes from caller', 'example' => 'Customer interested but wants callback next week'],
];

foreach ($at_risk_fields as $field => $info) {
    $risk_class = "risk-{$info['risk']}";
    echo "<div class='data-field $risk_class'>";
    echo "<strong>{$field}</strong> - {$info['description']}<br>";
    echo "<small>Example: {$info['example']} | Risk Level: <strong>" . ucfirst($info['risk']) . "</strong></small>";
    echo "</div>";
}

echo "</div>";

// Current system problems
echo "<div class='section'>";
echo "<h2>⚠️ Current System Problems</h2>";

echo "<h4>Scenario 1: Same Caller Re-attempt</h4>";
echo "<div class='warning'>";
echo "1. Caller A marks lead: slot=3, disposition='Follow Up', processed_at='2024-01-15 14:30'<br>";
echo "2. Admin gives same lead back to Caller A<br>";
echo "3. Caller A uploads again: slot=5, disposition='Interested', processed_at='2024-01-20 10:15'<br>";
echo "<strong>PROBLEM:</strong> Original slot=3, disposition='Follow Up', processed_at='2024-01-15 14:30' are LOST forever!";
echo "</div>";

echo "<h4>Scenario 2: Cross-Caller Redistribution</h4>";
echo "<div class='warning'>";
echo "1. Caller A marks lead: slot=2, disposition='Not Interested', processed_at='2024-01-15 09:45'<br>";
echo "2. Admin redistributes to Caller B<br>";
echo "3. Caller B uploads: slot=7, disposition='Interested', processed_at='2024-01-22 16:20'<br>";
echo "<strong>PROBLEM:</strong> Caller A's work (slot=2, 'Not Interested', '2024-01-15 09:45') is LOST! Cannot compare performance.";
echo "</div>";

echo "</div>";

// Enhanced solution
echo "<div class='section'>";
echo "<h2>✅ Complete Data Preservation Solution</h2>";

echo "<div class='solution'>";
echo "<h4>🎯 Enhanced Approach: Triple-Layer Data Protection</h4>";
echo "<strong>Layer 1:</strong> call_history table stores EVERY attempt with ALL fields<br>";
echo "<strong>Layer 2:</strong> final_call_logs shows CURRENT state + tracks original caller<br>";
echo "<strong>Layer 3:</strong> PDF generation can show ORIGINAL, CURRENT, or BLANK data based on mode";
echo "</div>";

echo "<h4>📋 Implementation Components</h4>";

$components = [
    'Enhanced call_history table' => 'Store ALL marked fields for every attempt (slot, disposition, connectivity, notes, timestamps)',
    'Smart upload detection' => 'Detect re-attempts vs redistributions and preserve ALL previous data',
    'Flexible PDF generation' => 'Generate PDFs with ORIGINAL data, CURRENT data, or BLANK fields based on admin choice',
    'Complete audit trail' => 'Track every single change to every field by every caller',
    'Performance comparison' => 'Compare ALL fields across different attempts and callers',
    'Data recovery system' => 'Ability to restore previous states if needed'
];

echo "<ul>";
foreach ($components as $component => $description) {
    echo "<li><strong>{$component}:</strong> {$description}</li>";
}
echo "</ul>";

echo "</div>";

// Database design for complete preservation
echo "<div class='section'>";
echo "<h2>🗄️ Enhanced Database Design</h2>";

echo "<h4>Updated call_history Table Structure</h4>";
echo "<div class='code-block'>";
echo "CREATE TABLE call_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_record_id VARCHAR(50) NOT NULL,
    finqy_id VARCHAR(50) NOT NULL,
    attempt_number INT NOT NULL,
    batch_id VARCHAR(50) NOT NULL,
    
    -- ALL MARKED DATA FIELDS PRESERVED --
    slot INT NULL,                    -- Time slot marked
    disposition VARCHAR(50) NULL,     -- Call outcome  
    connectivity VARCHAR(10) NULL,    -- Connection status
    notes TEXT NULL,                  -- Any additional notes
    call_duration INT NULL,           -- How long the call lasted
    customer_response TEXT NULL,      -- Customer's specific response
    
    -- METADATA --
    attempt_date DATETIME NOT NULL,   -- When this attempt was made
    is_original_attempt BOOLEAN DEFAULT FALSE,
    data_source VARCHAR(20) DEFAULT 'upload',  -- 'upload', 'manual', 'system'
    
    -- AUDIT FIELDS --
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_original_record (original_record_id),
    INDEX idx_caller (finqy_id),
    INDEX idx_attempt_date (attempt_date)
);";
echo "</div>";

echo "<h4>Enhanced final_call_logs Tracking</h4>";
echo "<div class='code-block'>";
echo "-- Additional tracking fields in final_call_logs
ALTER TABLE final_call_logs ADD COLUMN:
- original_data_preserved BOOLEAN DEFAULT TRUE  -- Confirms original data is in history
- last_preserved_at DATETIME                    -- When data was last backed up to history
- data_integrity_hash VARCHAR(255)              -- Hash to verify no data corruption
- total_attempts INT DEFAULT 0                  -- Quick count of attempts
- first_attempt_date DATETIME                   -- When first worked on
- data_change_log JSON                          -- Summary of what changed when";
echo "</div>";

echo "</div>";

// Implementation priority
echo "<div class='section'>";
echo "<h2>🚀 Implementation Priority & Action Plan</h2>";

echo "<h4>🔥 CRITICAL (Immediate Action Required)</h4>";
echo "<ol>";
echo "<li><strong>Update call_history table</strong> to store ALL marked fields (slot, disposition, connectivity, notes, etc.)</li>";
echo "<li><strong>Enhance save_final_log.php</strong> to preserve ALL data before any update</li>";
echo "<li><strong>Create backup mechanism</strong> that runs BEFORE any data modification</li>";
echo "<li><strong>Test data recovery</strong> to ensure no information can ever be lost</li>";
echo "</ol>";

echo "<h4>📊 HIGH PRIORITY (Next Phase)</h4>";
echo "<ol>";
echo "<li><strong>Enhanced PDF modes</strong>: Original data view, Current data view, Blank redistribution view</li>";
echo "<li><strong>Complete audit interface</strong> showing all changes to every field</li>";
echo "<li><strong>Data integrity verification</strong> to detect any corruption or loss</li>";
echo "<li><strong>Recovery tools</strong> for admins to restore previous states if needed</li>";
echo "</ol>";

echo "</div>";

// Quick test of current data preservation
echo "<div class='section'>";
echo "<h2>🔍 Current Data Preservation Status</h2>";

try {
    // Check if we have any data in call_history
    $history_check = $conn->query("SELECT COUNT(*) as count FROM call_history");
    $history_count = $history_check->fetch_assoc()['count'];
    
    if ($history_count > 0) {
        echo "<div class='success'>✅ Call history tracking is active: {$history_count} attempts recorded</div>";
        
        // Check what fields are being preserved
        $sample = $conn->query("SELECT * FROM call_history ORDER BY attempt_date DESC LIMIT 1")->fetch_assoc();
        if ($sample) {
            echo "<h4>Sample Preserved Data:</h4>";
            echo "<ul>";
            foreach (['slot', 'disposition', 'connectivity', 'attempt_date', 'finqy_id'] as $field) {
                $value = $sample[$field] ?? 'NULL';
                $status = $value !== null ? '✅' : '❌';
                echo "<li>{$status} <strong>{$field}:</strong> " . htmlspecialchars($value) . "</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<div class='warning'>⚠️ No call history data yet - preservation will activate when callers upload results</div>";
    }
    
    // Check for any data that might have been lost
    $lost_data_check = $conn->query("
        SELECT COUNT(*) as potentially_lost 
        FROM final_call_logs 
        WHERE finqy_id IS NOT NULL 
        AND processed_at IS NOT NULL 
        AND id NOT IN (SELECT DISTINCT original_record_id FROM call_history WHERE original_record_id IS NOT NULL)
    ");
    $potentially_lost = $lost_data_check->fetch_assoc()['potentially_lost'];
    
    if ($potentially_lost > 0) {
        echo "<div class='error'>⚠️ Found {$potentially_lost} records that may have lost historical data</div>";
        echo "<div class='info'>💡 Run migration to backup existing data before it's overwritten</div>";
    } else {
        echo "<div class='success'>✅ No data loss detected in current system</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>Error checking data preservation: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";

echo "<div class='section'>";
echo "<h2>🎯 Next Steps</h2>";
echo "<div class='solution'>";
echo "<h4>Immediate Action Required:</h4>";
echo "<ol>";
echo "<li><a href='implement_complete_preservation.php' style='background:#dc3545;color:white;padding:10px;text-decoration:none;border-radius:5px;'>🚨 Implement Complete Data Preservation</a></li>";
echo "<li><a href='test_data_preservation.php' style='background:#28a745;color:white;padding:10px;text-decoration:none;border-radius:5px;'>✅ Test Data Preservation</a></li>";
echo "<li><a href='admin_call_analytics.php' style='background:#007bff;color:white;padding:10px;text-decoration:none;border-radius:5px;'>📊 View Enhanced Analytics</a></li>";
echo "</ol>";
echo "</div>";
echo "</div>";

$conn->close();
echo "</body></html>";
?>