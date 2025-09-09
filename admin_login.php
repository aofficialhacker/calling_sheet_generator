<?php
require_once 'db_config.php'; // This includes security initialization
require_once 'session_manager.php';

// Start session safely with security configuration
SessionManager::start();

// If already logged in as admin, redirect to panel
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header("Location: admin_panel.php");
    exit();
}

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        $conn = getDBConnection();
        
        // Check in admin_users table
        $stmt = $conn->prepare("
            SELECT admin_id, username, password, name, multi_status_selection 
            FROM admin_users 
            WHERE username = ? AND is_active = 1 AND admin_id != 'SUPER'
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            
            // Secure password verification with backwards compatibility
            $password_valid = false;
            
            // First try password_verify for properly hashed passwords
            if (password_verify($password, $admin['password'])) {
                $password_valid = true;
            }
            // Fallback: direct comparison for legacy plaintext passwords (TEMPORARY - should be removed after migration)
            elseif ($password === $admin['password']) {
                $password_valid = true;
                
                // Auto-upgrade to hashed password for security
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE admin_users SET password = ? WHERE admin_id = ?");
                $update_stmt->bind_param("ss", $hashed_password, $admin['admin_id']);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            if ($password_valid) {
                $_SESSION['is_admin'] = true;
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['multi_status_selection'] = $admin['multi_status_selection'];
                
                header("Location: admin_panel.php");
                exit();
            } else {
                $login_error = "Invalid username or password";
            }
        } else {
            $login_error = "Invalid username or password";
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
    <title>Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background-color: #28a745;
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
                        <i class="bi bi-person-badge-fill fs-1"></i>
                        <h3 class="mt-3 mb-0">Admin Login</h3>
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
                                    <input type="text" class="form-control" id="username" name="username" required autofocus>
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
                                <button type="submit" class="btn btn-success btn-lg">
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