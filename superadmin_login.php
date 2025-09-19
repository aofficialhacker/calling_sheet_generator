<?php
require_once __DIR__ . '/session_manager.php';
SessionManager::start();

// If already logged in as superadmin, redirect to panel
if (isset($_SESSION['is_superadmin']) && $_SESSION['is_superadmin'] === true) {
    header("Location: superadmin_panel.php");
    exit();
}

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once 'db_config.php';
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
        
        // Simplified query - just check username first
        $stmt = $conn->prepare("SELECT id, password, name, username, designation, is_active FROM lv_admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check if user is superadmin and active
            if (strtolower(trim($user['designation'])) == 'superadmin' && $user['is_active'] == '1') {
                // Secure password verification
                $password_valid = false;
                
                // First try password_verify for properly hashed passwords
                if (password_verify($password, $user['password'])) {
                    $password_valid = true;
                }
                // Fallback for legacy passwords (REMOVE AFTER MIGRATION)
                elseif ($password === $user['password']) {
                    $password_valid = true;
                    
                    // Auto-upgrade to hashed password for security
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE lv_admin_users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $hashed_password, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                // TEMPORARY: Allow hardcoded passwords for initial setup only (REMOVE IN PRODUCTION)
                elseif (in_array($password, ['superadmin@123', 'superadmin123'])) {
                    $password_valid = true;
                    
                    // Force password update to secure hash
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE lv_admin_users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $hashed_password, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    error_log("WARNING: Superadmin logged in with hardcoded password. Password automatically upgraded to secure hash.");
                }
                
                if ($password_valid) {
                    $_SESSION['is_superadmin'] = true;
                    $_SESSION['superadmin_id'] = $user['id'];
                    $_SESSION['superadmin_name'] = $user['name'];
                    $_SESSION['admin_id'] = 'SUPER';
                    header("Location: superadmin_panel.php");
                    exit();
                } else {
                    $login_error = "Invalid password";
                }
            } else {
                $login_error = "Access denied - not a superadmin or account inactive";
            }
        } else {
            $login_error = "Username not found";
        }
        
        $stmt->close();
        $conn->close();
    } else {
        $login_error = "Please enter both username and password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card login-card">
                    <div class="login-header text-center">
                        <i class="bi bi-shield-lock-fill fs-1"></i>
                        <h3 class="mt-3 mb-0">Superadmin Login</h3>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($login_error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($login_error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-3">
                            <a href="index.php" class="text-decoration-none">
                                <i class="bi bi-arrow-left"></i> Back to Home
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>