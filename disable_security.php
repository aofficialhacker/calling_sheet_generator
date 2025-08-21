<?php
// Emergency security disable script
// Use this only for testing/debugging
session_start();

if (isset($_GET['disable']) && $_GET['disable'] === 'all') {
    $_SESSION['security_disabled'] = true;
    echo "Security protection disabled for this session.";
    echo "<br><a href='team_leader_dashboard.php'>Go to Dashboard</a>";
    echo "<br><a href='team_leader_history.php'>Go to History</a>";
} elseif (isset($_GET['enable'])) {
    unset($_SESSION['security_disabled']);
    echo "Security protection re-enabled.";
    echo "<br><a href='team_leader_dashboard.php'>Go to Dashboard</a>";
} else {
    echo "<h3>Security Control Panel</h3>";
    echo "<a href='?disable=all'>Disable Security Protection</a><br>";
    echo "<a href='?enable=1'>Enable Security Protection</a><br>";
    echo "<br>Current status: " . (isset($_SESSION['security_disabled']) ? "DISABLED" : "ENABLED");
}
?>