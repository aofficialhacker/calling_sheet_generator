<?php
/**
 * Automated Session Fix Script
 * This script will automatically update all PHP files to use SessionManager
 */

set_time_limit(300); // 5 minutes max execution time

echo "<h2>🔧 Automated Session Fix Tool</h2>\n";

$baseDir = __DIR__;
$backupDir = $baseDir . '/session_fix_backup_' . date('Y-m-d_H-i-s');

// Create backup directory
if (!mkdir($backupDir, 0755, true)) {
    die("❌ Failed to create backup directory: $backupDir\n");
}

echo "📁 Created backup directory: " . basename($backupDir) . "<br>\n";

// Priority files that MUST be fixed for production
$priorityFiles = [
    'superadmin_login.php',
    'team_leader_login.php', 
    'admin_panel.php',
    'superadmin_panel.php',
    'team_leader_dashboard.php',
    'telecaller_dashboard.php',
    'save_final_log.php',
    'generate_pdf.php',
    'logout.php'
];

// Files to skip (test files, debug files, etc.)
$skipFiles = [
    'fix_session_warnings.php',
    'auto_fix_sessions.php',
    'session_manager.php',
    'config.php',
    'security.php',
    'deploy_production.php',
    'test_connection.php'
];

$phpFiles = glob($baseDir . '/*.php');
$fixedCount = 0;
$skippedCount = 0;
$errorCount = 0;

echo "<h3>🔄 Processing Files...</h3>\n";

foreach ($phpFiles as $file) {
    $filename = basename($file);
    
    // Skip certain files
    if (in_array($filename, $skipFiles)) {
        echo "<span style='color: gray;'>⏭️ Skipped: {$filename}</span><br>\n";
        $skippedCount++;
        continue;
    }
    
    // Skip files that start with test_ or debug_
    if (preg_match('/^(test_|debug_|Cxampp)/', $filename)) {
        echo "<span style='color: gray;'>⏭️ Skipped test/debug file: {$filename}</span><br>\n";
        $skippedCount++;
        continue;
    }
    
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // Check if file needs fixing
    $needsFix = false;
    
    // Check for direct session_start() calls
    if (preg_match('/session_start\(\)/', $content) && !strpos($content, 'SessionManager::start()')) {
        $needsFix = true;
    }
    
    if (!$needsFix) {
        continue;
    }
    
    // Create backup
    $backupFile = $backupDir . '/' . $filename;
    if (!copy($file, $backupFile)) {
        echo "<span style='color: red;'>❌ Failed to backup: {$filename}</span><br>\n";
        $errorCount++;
        continue;
    }
    
    $isPriority = in_array($filename, $priorityFiles);
    
    try {
        // Fix the session_start() calls
        $fixed = false;
        
        // Pattern 1: session_start() at the beginning of file
        if (preg_match('/^<\?php\s*\n?session_start\(\);/', $content)) {
            $content = preg_replace(
                '/^(<\?php\s*\n?)session_start\(\);/',
                '$1require_once __DIR__ . \'/session_manager.php\';' . "\n" . 'SessionManager::start();',
                $content
            );
            $fixed = true;
        }
        // Pattern 2: session_start() after other includes
        elseif (preg_match('/session_start\(\);/', $content)) {
            // Check if SessionManager is already included
            if (!strpos($content, 'session_manager.php')) {
                // Add SessionManager include before session_start
                $content = preg_replace(
                    '/session_start\(\);/',
                    'require_once __DIR__ . \'/session_manager.php\';' . "\n" . 'SessionManager::start();',
                    $content,
                    1 // Replace only the first occurrence
                );
            } else {
                // Just replace session_start with SessionManager::start
                $content = preg_replace('/session_start\(\);/', 'SessionManager::start();', $content, 1);
            }
            $fixed = true;
        }
        
        // Special handling for some files that might need different fixes
        if (strpos($filename, 'db_config.php') !== false) {
            // Remove session_start from db_config.php as it should not start sessions
            $content = preg_replace('/.*session_start\(\);.*\n?/', '', $content);
            $fixed = true;
        }
        
        if ($fixed) {
            // Write the fixed content
            if (file_put_contents($file, $content)) {
                $statusColor = $isPriority ? 'green' : 'blue';
                $priorityMark = $isPriority ? ' 🔥 PRIORITY' : '';
                echo "<span style='color: {$statusColor};'>✅ Fixed: {$filename}{$priorityMark}</span><br>\n";
                $fixedCount++;
            } else {
                echo "<span style='color: red;'>❌ Failed to write: {$filename}</span><br>\n";
                $errorCount++;
            }
        }
        
    } catch (Exception $e) {
        echo "<span style='color: red;'>❌ Error fixing {$filename}: " . $e->getMessage() . "</span><br>\n";
        $errorCount++;
        
        // Restore from backup on error
        copy($backupFile, $file);
    }
}

echo "<hr>\n";
echo "<h3>📊 Summary</h3>\n";
echo "<div style='background: #e7f3ff; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;'>\n";
echo "<strong>Results:</strong><br>\n";
echo "✅ Files Fixed: <strong>{$fixedCount}</strong><br>\n";
echo "⏭️ Files Skipped: <strong>{$skippedCount}</strong><br>\n";
echo "❌ Errors: <strong>{$errorCount}</strong><br>\n";
echo "📁 Backup Location: <strong>" . basename($backupDir) . "</strong><br>\n";
echo "</div>\n";

if ($fixedCount > 0) {
    echo "<div style='background: #d1edff; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;'>\n";
    echo "<h4>🎉 Session fixes applied successfully!</h4>\n";
    echo "<p><strong>Next Steps:</strong></p>\n";
    echo "<ol>\n";
    echo "<li>Test your main application pages (login, dashboards, etc.)</li>\n";
    echo "<li>Check that sessions work properly without warnings</li>\n";
    echo "<li>If any issues occur, restore from backup: <code>" . basename($backupDir) . "</code></li>\n";
    echo "<li>Once confirmed working, you can delete the backup directory</li>\n";
    echo "</ol>\n";
    echo "</div>\n";
}

if ($errorCount > 0) {
    echo "<div style='background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;'>\n";
    echo "<h4>⚠️ Some files had errors</h4>\n";
    echo "<p>Please check the files that showed errors and fix them manually if needed.</p>\n";
    echo "</div>\n";
}

// Show current status of important files
echo "<h3>📋 Priority Files Status Check</h3>\n";
foreach ($priorityFiles as $filename) {
    $fullPath = $baseDir . '/' . $filename;
    if (file_exists($fullPath)) {
        $content = file_get_contents($fullPath);
        $usesSessionManager = strpos($content, 'SessionManager::start()') !== false;
        $usesDirectStart = preg_match('/session_start\(\)/', $content);
        
        echo "<div style='margin: 5px 0; padding: 5px; background: " . ($usesSessionManager ? '#d4edda' : '#f8d7da') . ";'>\n";
        echo "<strong>{$filename}</strong>: ";
        
        if ($usesSessionManager) {
            echo "<span style='color: green;'>✅ Now uses SessionManager</span>";
        } elseif ($usesDirectStart) {
            echo "<span style='color: red;'>❌ Still uses direct session_start()</span>";
        } else {
            echo "<span style='color: orange;'>⚠️ No session management detected</span>";
        }
        echo "</div>\n";
    } else {
        echo "<div style='margin: 5px 0; padding: 5px; background: #e2e3e5;'>\n";
        echo "<strong>{$filename}</strong>: <span style='color: gray;'>File not found</span>\n";
        echo "</div>\n";
    }
}

?>

<style>
body { 
    font-family: Arial, sans-serif; 
    max-width: 1200px; 
    margin: 20px auto; 
    padding: 20px; 
    line-height: 1.6;
}
h2 { 
    color: #333; 
    border-bottom: 2px solid #007bff; 
    padding-bottom: 10px; 
}
h3 {
    color: #495057;
    margin-top: 30px;
}
hr {
    margin: 30px 0;
    border: none;
    border-top: 2px solid #dee2e6;
}
</style>