<?php
require_once __DIR__ . '/session_manager.php';
SessionManager::start();
require_once 'db_config.php';

$type = $_GET['type'] ?? '';

// Clear active session ID for team leaders
if ($type === 'team_leader' && isset($_SESSION['leader_id'])) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE lv_team_leaders SET active_session_id = NULL WHERE leader_id = ?");
    $stmt->bind_param("s", $_SESSION['leader_id']);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect based on logout type
switch($type) {
    case 'superadmin':
        header("Location: superadmin_login.php");
        break;
    case 'admin':
        header("Location: admin_login.php");
        break;
    case 'caller':
        header("Location: caller_panel.php");
        break;
    case 'team_leader':
        header("Location: team_leader_login.php");
        break;
    default:
        header("Location: index.php");
        break;
}
exit();