<?php
require_once 'db_config.php';
require_once __DIR__ . '/session_manager.php';
SessionManager::start();

$message = '';
$messageType = '';
$showAuthForm = false;
$pendingLeaderId = '';

// Handle logout reasons
if (isset($_GET['reason'])) {
    switch ($_GET['reason']) {
        case 'code_refreshed':
            $message = 'Your admin has refreshed your access code. Please enter the new 6-character code to log in again.';
            $messageType = 'info';
            break;
        case 'multi_device':
            $message = 'You are already logged in from another device. Please logout from the other device first or wait for the session to expire.';
            $messageType = 'warning';
            break;
        case 'not_logged_in':
            $message = 'Please log in to access the Relationship Manager portal.';
            $messageType = 'info';
            break;
        case 'invalid_user':
            $message = 'Invalid user session. Please log in again.';
            $messageType = 'danger';
            break;
    }
}

// Check if already logged in
if (isset($_SESSION['is_team_leader']) && $_SESSION['is_team_leader']) {
    header("Location: team_leader_dashboard.php");
    exit();
}

$conn = getDBConnection();

if ($_POST) {
    if (isset($_POST['login'])) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $accessCode = strtoupper(trim($_POST['access_code']));
        $ipAddress = getRealIpAddress();
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        // Get leader details
        $stmt = $conn->prepare("SELECT * FROM team_leaders WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $leader = $result->fetch_assoc();
            
            // Clean up stale sessions (older than 24 hours)
            if (!empty($leader['active_session_id']) && !empty($leader['last_login'])) {
                $lastLogin = new DateTime($leader['last_login']);
                $now = new DateTime();
                $hoursDiff = $now->diff($lastLogin)->h + ($now->diff($lastLogin)->days * 24);
                
                if ($hoursDiff > 24) {
                    // Clear stale session
                    $stmt = $conn->prepare("UPDATE team_leaders SET active_session_id = NULL WHERE leader_id = ?");
                    if ($stmt) {
                        $stmt->bind_param("s", $leader['leader_id']);
                        $stmt->execute();
                        $leader['active_session_id'] = null; // Update local copy
                    }
                }
            }
            
            // Check for too many failed attempts (max 5 attempts per hour)
            $stmt = $conn->prepare("SELECT COUNT(*) as failed_count FROM team_leader_logins 
                                   WHERE leader_id = ? AND login_status = 'failed' 
                                   AND login_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->bind_param("s", $leader['leader_id']);
            $stmt->execute();
            $failedAttempts = $stmt->get_result()->fetch_assoc()['failed_count'];
            
            if ($failedAttempts >= 5) {
                $message = "Account temporarily locked due to too many failed attempts. Try again after 1 hour.";
                $messageType = "danger";
            } else {
                // Verify password
                if (password_verify($password, $leader['password'])) {
                    // Verify admin access code
                    if (validateTeamLeaderAccessCode($leader['leader_id'], $accessCode, $conn)) {
                        // Check if already logged in from another device
                        if (!empty($leader['active_session_id'])) {
                            // Log failed login attempt due to multi-device
                            $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status) VALUES (?, ?, ?, 'failed - multi-device')");
                            if ($stmt) {
                                $stmt->bind_param("sss", $leader['leader_id'], $ipAddress, $userAgent);
                                $stmt->execute();
                            }
                            
                            $message = "You are already logged in from another device. Please logout from the other device first or wait for the session to expire.";
                            $messageType = "warning";
                        } else {
                            // Direct login - no additional security code needed
                            session_regenerate_id(true);
                            $sessionId = session_id();
                            
                            // Update login info and set active session ID
                            $stmt = $conn->prepare("UPDATE team_leaders SET last_login = NOW(), active_session_id = ?, login_attempts = 0 WHERE leader_id = ?");
                            if ($stmt) {
                                $stmt->bind_param("ss", $sessionId, $leader['leader_id']);
                                $stmt->execute();
                            }
                            
                            // Log successful login
                            $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status, session_id) VALUES (?, ?, ?, 'success', ?)");
                            if ($stmt) {
                                $stmt->bind_param("ssss", $leader['leader_id'], $ipAddress, $userAgent, $sessionId);
                                $stmt->execute();
                            }
                            
                            // Set session variables
                            $_SESSION['is_team_leader'] = true;
                            $_SESSION['leader_id'] = $leader['leader_id'];
                            $_SESSION['leader_name'] = $leader['leader_name'];
                            $_SESSION['finqy_id'] = $leader['finqy_id'];
                            $_SESSION['admin_id'] = $leader['admin_id'];
                            $_SESSION['active_session_id'] = $sessionId;
                            
                            header("Location: team_leader_dashboard.php");
                            exit();
                        }
                    } else {
                        // Log failed login attempt
                        $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status) VALUES (?, ?, ?, 'failed')");
                        if ($stmt) {
                            $stmt->bind_param("sss", $leader['leader_id'], $ipAddress, $userAgent);
                            $stmt->execute();
                        }
                        
                        $message = "Invalid access code. Please contact your admin for the current code.";
                        $messageType = "danger";
                    }
                } else {
                    // Log failed login attempt
                    $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status) VALUES (?, ?, ?, 'failed')");
                    if ($stmt) {
                        $stmt->bind_param("sss", $leader['leader_id'], $ipAddress, $userAgent);
                        $stmt->execute();
                    }
                    
                    $message = "Invalid username or password.";
                    $messageType = "danger";
                }
            }
        } else {
            $message = "Invalid username or password.";
            $messageType = "danger";
        }
        $stmt->close();
    }
    
}


$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relationship Manager Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 450px;
            width: 100%;
        }
        .auth-code-display {
            font-family: 'Courier New', monospace;
            font-size: 2rem;
            font-weight: bold;
            color: #28a745;
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 20px 0;
            border: 2px dashed #28a745;
        }
        .security-info {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="bi bi-shield-lock-fill text-primary" style="font-size: 3rem;"></i>
                <h2 class="mt-3">Relationship Manager Portal</h2>
                <p class="text-muted">Secure Access with Time-based Authentication</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                    <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'x-circle' : 'info-circle') ?> me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

                <!-- Login Form -->
                <form method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="bi bi-person-fill me-2"></i>Username
                        </label>
                        <input type="text" name="username" id="username" class="form-control form-control-lg" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock-fill me-2"></i>Password
                        </label>
                        <input type="password" name="password" id="password" class="form-control form-control-lg" required>
                    </div>
                    <div class="mb-4">
                        <label for="access_code" class="form-label">
                            <i class="bi bi-key-fill me-2"></i>Access Code
                            <small class="text-muted">(Get from your Admin)</small>
                        </label>
                        <input type="text" name="access_code" id="access_code" class="form-control form-control-lg text-center" 
                               maxlength="6" pattern="[0-9A-Za-z]{6}" required 
                               placeholder="6-character code" style="text-transform: uppercase;">
                        <small class="form-text text-muted">
                            <i class="bi bi-info-circle me-1"></i>This code changes every 4 hours. Contact your admin if you don't have it.
                        </small>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Login
                    </button>
                </form>

            <div class="security-info">
                <h6><i class="bi bi-info-circle me-2"></i>2-Factor Authentication:</h6>
                <ul class="mb-0 small">
                    <li><strong>Step 1:</strong> Username & Password verification</li>
                    <li><strong>Step 2:</strong> Admin access code (changes every 4 hours)</li>
                    <li>IP address logging and account lockout protection</li>
                    <li>Enhanced session monitoring and security</li>
                </ul>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>