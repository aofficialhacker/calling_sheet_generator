<?php
// Test script to debug PDF download issues
require_once "db_config.php";

// Start session to simulate admin login  
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';\nSessionManager::start();
}

// Simulate admin session for testing
$_SESSION["admin_id"] = "SN01"; // suresh.negi@finqy.ai
$_SESSION["role"] = "admin";

echo "<h2>PDF Download Debug Test</h2>";
echo "<p>Testing with admin_id: " . $_SESSION["admin_id"] . "</p>";

// Test parameter mapping issue
echo "<h3>Parameter Mapping Issue Found</h3>";
echo "<p>JavaScript sends <strong>scope</strong> but handler expects <strong>product_filter</strong></p>";
echo "<p>This mismatch causes the handler to fail silently.</p>";
?>
