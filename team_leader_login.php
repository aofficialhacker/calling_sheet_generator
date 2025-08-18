<?php
session_start();
require_once 'db_config.php';

$message = '';
$messageType = '';
$showAuthForm = false;
$pendingLeaderId = '';

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
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        // Get leader details
        $stmt = $conn->prepare("SELECT * FROM team_leaders WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $leader = $result->fetch_assoc();
            
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
                    // Store in session for second factor (no DB token needed)
                    $_SESSION['pending_leader_id'] = $leader['leader_id'];
                    $_SESSION['pending_leader_name'] = $leader['leader_name'];
                    $_SESSION['pending_finqy_id'] = $leader['finqy_id'];
                    $_SESSION['pending_admin_id'] = $leader['admin_id'];
                    $_SESSION['auth_time'] = time(); // Store time for expiry check
                    
                    $showAuthForm = true;
                    $pendingLeaderId = $leader['leader_id'];
                    $message = "Authentication code generated. You have 10 minutes to enter the code.";
                    $messageType = "success";
                    
                    // Log pending login attempt
                    $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status) VALUES (?, ?, ?, 'pending')");
                    $stmt->bind_param("sss", $leader['leader_id'], $ipAddress, $userAgent);
                    $stmt->execute();
                } else {
                    // Log failed login attempt
                    $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status) VALUES (?, ?, ?, 'failed')");
                    $stmt->bind_param("sss", $leader['leader_id'], $ipAddress, $userAgent);
                    $stmt->execute();
                    
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
    
    if (isset($_POST['verify_auth'])) {
        $authCode = strtoupper(trim($_POST['auth_code']));
        $leaderId = $_SESSION['pending_leader_id'] ?? '';
        $authTime = $_SESSION['auth_time'] ?? 0;
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        
        // Check if session exists and hasn't expired (10 minutes)
        if ($leaderId && $authTime && (time() - $authTime) <= 600) {
                
            // Simple time-based code: last 6 digits of current timestamp + leader_id hash
            $currentTime = time();
            $timeWindow = floor($currentTime / 300); // 5-minute windows
            $expectedCode = strtoupper(substr(hash('sha256', $leaderId . $timeWindow), -6));
            
            // Also check previous time window (for clock drift tolerance)
            $prevTimeWindow = $timeWindow - 1;
            $prevExpectedCode = strtoupper(substr(hash('sha256', $leaderId . $prevTimeWindow), -6));
            
            if ($authCode === $expectedCode || $authCode === $prevExpectedCode) {
                // Successful login
                $sessionId = session_id();
                
                // Update login info
                $stmt = $conn->prepare("UPDATE team_leaders SET last_login = NOW(), login_attempts = 0 WHERE leader_id = ?");
                $stmt->bind_param("s", $leaderId);
                $stmt->execute();
                
                // Log successful login
                $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status, session_id) VALUES (?, ?, ?, 'success', ?)");
                $stmt->bind_param("ssss", $leaderId, $ipAddress, $_SERVER['HTTP_USER_AGENT'], $sessionId);
                $stmt->execute();
                
                // Set session variables from stored pending data
                $_SESSION['is_team_leader'] = true;
                $_SESSION['leader_id'] = $_SESSION['pending_leader_id'];
                $_SESSION['leader_name'] = $_SESSION['pending_leader_name'];
                $_SESSION['finqy_id'] = $_SESSION['pending_finqy_id'];
                $_SESSION['admin_id'] = $_SESSION['pending_admin_id'];
                
                // Clear pending session data
                unset($_SESSION['pending_leader_id']);
                unset($_SESSION['pending_leader_name']);
                unset($_SESSION['pending_finqy_id']);
                unset($_SESSION['pending_admin_id']);
                unset($_SESSION['auth_time']);
                
                header("Location: team_leader_dashboard.php");
                exit();
            } else {
                // Log failed verification
                $stmt = $conn->prepare("INSERT INTO team_leader_logins (leader_id, ip_address, user_agent, login_status) VALUES (?, ?, ?, 'failed')");
                $stmt->bind_param("sss", $leaderId, $ipAddress, $_SERVER['HTTP_USER_AGENT']);
                $stmt->execute();
                
                $message = "Invalid authentication code. Please try again.";
                $messageType = "danger";
                $showAuthForm = true;
                $pendingLeaderId = $leaderId;
            }
        } else {
            $message = "Authentication session expired. Please login again.";
            $messageType = "warning";
            // Clear all pending session data
            unset($_SESSION['pending_leader_id']);
            unset($_SESSION['pending_leader_name']);
            unset($_SESSION['pending_finqy_id']);
            unset($_SESSION['pending_admin_id']);
            unset($_SESSION['auth_time']);
        }
    }
}

// Check for pending auth session
if (isset($_SESSION['pending_leader_id']) && isset($_SESSION['auth_time'])) {
    // Check if session hasn't expired (10 minutes)
    if ((time() - $_SESSION['auth_time']) <= 600) {
        $showAuthForm = true;
        $pendingLeaderId = $_SESSION['pending_leader_id'];
    } else {
        // Session expired, clear pending data
        unset($_SESSION['pending_leader_id']);
        unset($_SESSION['pending_leader_name']);
        unset($_SESSION['pending_finqy_id']);
        unset($_SESSION['pending_admin_id']);
        unset($_SESSION['auth_time']);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Leader Login</title>
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
                <h2 class="mt-3">Team Leader Portal</h2>
                <p class="text-muted">Secure Access with Time-based Authentication</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                    <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'x-circle' : 'info-circle') ?> me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$showAuthForm): ?>
                <!-- Login Form -->
                <form method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="bi bi-person-fill me-2"></i>Username
                        </label>
                        <input type="text" name="username" id="username" class="form-control form-control-lg" required>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock-fill me-2"></i>Password
                        </label>
                        <input type="password" name="password" id="password" class="form-control form-control-lg" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Login
                    </button>
                </form>
            <?php else: ?>
                <!-- Authentication Code Form -->
                <div class="text-center">
                    <h4 class="text-success mb-3">
                        <i class="bi bi-key-fill me-2"></i>Authentication Required
                    </h4>
                    <p class="mb-4">Enter the current authentication code for Team Leader: <strong><?= $pendingLeaderId ?></strong></p>
                    
                    <!-- Display current auth code -->
                    <div class="auth-code-display" id="authCode">
                        Loading...
                    </div>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="auth_code" class="form-label">
                                <i class="bi bi-shield-check me-2"></i>Authentication Code
                            </label>
                            <input type="text" name="auth_code" id="auth_code" class="form-control form-control-lg text-center" 
                                   maxlength="6" pattern="[0-9A-Fa-f]{6}" required
                                   placeholder="Enter 6-character code" style="text-transform: uppercase;">
                        </div>
                        <button type="submit" name="verify_auth" class="btn btn-success btn-lg w-100">
                            <i class="bi bi-check-circle-fill me-2"></i>Verify & Login
                        </button>
                    </form>
                    
                    <div class="mt-3">
                        <a href="team_leader_login.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Login
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="security-info">
                <h6><i class="bi bi-info-circle me-2"></i>Security Features:</h6>
                <ul class="mb-0 small">
                    <li>Time-based authentication codes (changes every 5 minutes)</li>
                    <li>IP address logging for all login attempts</li>
                    <li>Account lockout after 5 failed attempts</li>
                    <li>Session timeout after inactivity</li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($showAuthForm): ?>
        <script>
            function generateAuthCode() {
                const leaderId = '<?= $pendingLeaderId ?>';
                const currentTime = Math.floor(Date.now() / 1000);
                const timeWindow = Math.floor(currentTime / 300); // 5-minute windows
                
                // Simple client-side code generation for display (matches server logic)
                const crypto = window.crypto || window.msCrypto;
                if (crypto && crypto.subtle) {
                    const encoder = new TextEncoder();
                    const data = encoder.encode(leaderId + timeWindow);
                    
                    crypto.subtle.digest('SHA-256', data).then(hashBuffer => {
                        const hashArray = Array.from(new Uint8Array(hashBuffer));
                        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
                        const code = hashHex.slice(-6).toUpperCase();
                        document.getElementById('authCode').textContent = code;
                    });
                } else {
                    // Fallback for older browsers
                    document.getElementById('authCode').textContent = 'Use browser with crypto support';
                }
            }
            
            // Generate code on page load and refresh every 30 seconds
            generateAuthCode();
            setInterval(generateAuthCode, 30000);
            
            // Add countdown timer
            function updateCountdown() {
                const currentTime = Math.floor(Date.now() / 1000);
                const timeInWindow = currentTime % 300;
                const timeLeft = 300 - timeInWindow;
                
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                
                document.title = `Team Leader Login - Code expires in ${minutes}:${seconds.toString().padStart(2, '0')}`;
            }
            
            updateCountdown();
            setInterval(updateCountdown, 1000);
        </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>