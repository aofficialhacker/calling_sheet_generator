<?php
/**
 * Session Warnings Fix Script
 * This script will identify and help fix session warning issues in PHP files
 */

echo "<h2>🔧 Session Warnings Fix Tool</h2>\n";

$baseDir = __DIR__;
$phpFiles = glob($baseDir . '/*.php');
$issuesFound = [];
$fixedFiles = [];

echo "<h3>Scanning PHP files for session issues...</h3>\n";

foreach ($phpFiles as $file) {
    $filename = basename($file);
    
    // Skip certain files
    if (in_array($filename, ['fix_session_warnings.php', 'session_manager.php', 'config.php', 'security.php', 'deploy_production.php'])) {
        continue;
    }
    
    $content = file_get_contents($file);
    
    // Look for direct session_start() calls
    if (preg_match('/session_start\(\)/', $content)) {
        // Check if it already uses SessionManager
        if (!strpos($content, 'SessionManager::start()')) {
            $issuesFound[] = [
                'file' => $filename,
                'issue' => 'Uses session_start() directly instead of SessionManager::start()',
                'line' => null
            ];
        }
    }
    
    // Look for session ini_set calls after session might be started
    if (preg_match_all('/ini_set\([\'"]session\.[^\'"]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
            $issuesFound[] = [
                'file' => $filename,
                'issue' => 'Contains session ini_set call that might run after session is active',
                'line' => $line
            ];
        }
    }
}

if (empty($issuesFound)) {
    echo "✅ <strong>No session issues found!</strong><br>\n";
} else {
    echo "❌ <strong>Found " . count($issuesFound) . " potential session issues:</strong><br><br>\n";
    
    foreach ($issuesFound as $issue) {
        echo "<div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 10px 0;'>\n";
        echo "<strong>File:</strong> {$issue['file']}<br>\n";
        echo "<strong>Issue:</strong> {$issue['issue']}<br>\n";
        if ($issue['line']) {
            echo "<strong>Line:</strong> {$issue['line']}<br>\n";
        }
        echo "</div>\n";
    }
    
    echo "<br><h3>🔨 How to Fix These Issues:</h3>\n";
    echo "<div style='background: #e7f3ff; border-left: 4px solid #007bff; padding: 15px;'>\n";
    echo "<h4>For files with direct session_start() calls:</h4>\n";
    echo "<p>Replace this:</p>\n";
    echo "<pre style='background: #f8f9fa; padding: 10px;'>session_start();</pre>\n";
    echo "<p>With this:</p>\n";
    echo "<pre style='background: #f8f9fa; padding: 10px;'>require_once __DIR__ . '/session_manager.php';
SessionManager::start();</pre>\n";
    
    echo "<h4>For files with session ini_set calls:</h4>\n";
    echo "<p>Move session configuration to be done BEFORE any session_start() call, or use the SessionManager which handles this automatically.</p>\n";
    echo "</div>\n";
}

echo "<br><h3>📁 Quick File Status:</h3>\n";
$importantFiles = [
    'admin_login.php' => 'Admin login page',
    'superadmin_login.php' => 'Superadmin login page', 
    'team_leader_login.php' => 'Team leader login page',
    'caller_panel.php' => 'Caller panel page',
    'admin_panel.php' => 'Admin dashboard',
    'superadmin_panel.php' => 'Superadmin dashboard',
    'team_leader_dashboard.php' => 'Team leader dashboard'
];

foreach ($importantFiles as $file => $desc) {
    $fullPath = $baseDir . '/' . $file;
    if (file_exists($fullPath)) {
        $content = file_get_contents($fullPath);
        $usesSessionManager = strpos($content, 'SessionManager::start()') !== false;
        $usesDirectStart = preg_match('/session_start\(\)/', $content);
        
        echo "<div style='margin: 5px 0;'>\n";
        echo "<strong>{$file}</strong> ({$desc}): ";
        
        if ($usesSessionManager) {
            echo "<span style='color: green;'>✅ Uses SessionManager</span>";
        } elseif ($usesDirectStart) {
            echo "<span style='color: red;'>❌ Uses direct session_start()</span>";
        } else {
            echo "<span style='color: orange;'>⚠️ No session management detected</span>";
        }
        echo "<br>\n";
        echo "</div>\n";
    } else {
        echo "<div style='margin: 5px 0;'>\n";
        echo "<strong>{$file}</strong>: <span style='color: gray;'>File not found</span><br>\n";
        echo "</div>\n";
    }
}

?>

<style>
body { 
    font-family: Arial, sans-serif; 
    max-width: 1000px; 
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
pre {
    overflow-x: auto;
    white-space: pre-wrap;
}
</style>