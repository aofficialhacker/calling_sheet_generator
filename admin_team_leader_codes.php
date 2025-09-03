<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];
$message = '';
$messageType = '';

// Handle manual code refresh request
if ($_POST && isset($_POST['refresh_code'])) {
    $leaderId = $_POST['leader_id'];
    
    // Verify this team leader belongs to the current admin
    $stmt = $conn->prepare("SELECT leader_id FROM team_leaders WHERE leader_id = ? AND admin_id = ? AND is_active = 1");
    $stmt->bind_param("ss", $leaderId, $adminId);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        // Force refresh the code
        $codeInfo = refreshTeamLeaderCode($leaderId, $conn, true);
        
        if ($codeInfo['code']) {
            $message = "Access code refreshed successfully for Team Leader $leaderId. The team leader will be automatically logged out and must re-login with the new code.";
            $messageType = "success";
        } else {
            $message = "Error refreshing access code";
            $messageType = "danger";
        }
    } else {
        $message = "Invalid team leader or unauthorized access";
        $messageType = "danger";
    }
    $stmt->close();
}

// Get all active team leaders for this admin with their current codes
$teamLeaders = [];
$stmt = $conn->prepare("
    SELECT tl.leader_id, tl.leader_name, tl.username, tl.access_code, tl.code_generated_at, 
           tl.last_login, c.caller_name, c.finqy_id,
           (SELECT COUNT(*) FROM team_leader_actions WHERE leader_id = tl.leader_id) as total_actions,
           (SELECT COUNT(*) FROM team_leader_logins WHERE leader_id = tl.leader_id AND login_status = 'success' AND login_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as today_logins
    FROM team_leaders tl
    JOIN callers c ON tl.finqy_id = c.finqy_id
    WHERE tl.admin_id = ? AND tl.is_active = 1
    ORDER BY tl.leader_name
");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Refresh code if needed (auto-refresh expired codes)
    $codeInfo = refreshTeamLeaderCode($row['leader_id'], $conn);
    $row['current_code'] = $codeInfo['code'];
    $row['code_expires_at'] = $codeInfo['expires_at'];
    
    // Calculate time remaining for code expiry using the correct expires_at from refreshTeamLeaderCode
    if ($codeInfo['expires_at']) {
        $expiryTime = strtotime($codeInfo['expires_at']);
        $timeRemaining = $expiryTime - time();
        $row['time_remaining_seconds'] = max(0, $timeRemaining);
        $row['time_remaining_formatted'] = gmdate("H:i:s", max(0, $timeRemaining));
    } else {
        $row['time_remaining_seconds'] = 0;
        $row['time_remaining_formatted'] = "00:00:00";
    }
    
    $teamLeaders[] = $row;
}
$stmt->close();

// Get summary statistics
$stats = [];
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_active_leaders,
        COUNT(CASE WHEN last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as logged_in_today,
        COUNT(CASE WHEN access_code IS NOT NULL THEN 1 END) as with_active_codes
    FROM team_leaders 
    WHERE admin_id = ? AND is_active = 1
");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Leader Access Codes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .access-code {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            font-weight: bold;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            border: 2px solid #28a745;
            display: inline-block;
            min-width: 100px;
            text-align: center;
        }
        .access-code.expiring {
            border-color: #ffc107;
            background: #fff3cd;
        }
        .access-code.expired {
            border-color: #dc3545;
            background: #f8d7da;
            color: #721c24;
        }
        .countdown-timer {
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        .leader-row {
            transition: all 0.3s ease;
        }
        .leader-row:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-key-fill me-2"></i>Team Leader Access Codes
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-4 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Active Team Leaders</h6>
                                        <h2 class="mb-0"><?= $stats['total_active_leaders'] ?></h2>
                                    </div>
                                    <i class="bi bi-people-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Logged In Today</h6>
                                        <h2 class="mb-0"><?= $stats['logged_in_today'] ?></h2>
                                    </div>
                                    <i class="bi bi-calendar-check-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>With Active Codes</h6>
                                        <h2 class="mb-0"><?= $stats['with_active_codes'] ?></h2>
                                    </div>
                                    <i class="bi bi-key-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team Leaders List -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul me-2"></i>Team Leader Access Codes
                            <span class="badge bg-primary"><?= count($teamLeaders) ?></span>
                        </h5>
                        <small class="text-muted">Codes automatically refresh every 4 hours</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($teamLeaders)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-people display-4 opacity-25"></i>
                                <p class="mt-3">No active team leaders found.</p>
                                <a href="manage_team_leaders.php" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-2"></i>Create Team Leader
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Leader Info</th>
                                            <th>Access Code</th>
                                            <th>Code Expires</th>
                                            <th>Last Login</th>
                                            <th>Activity</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teamLeaders as $leader): ?>
                                            <tr class="leader-row" data-leader-id="<?= $leader['leader_id'] ?>" data-expiry="<?= $leader['time_remaining_seconds'] ?>">
                                                <td>
                                                    <div>
                                                        <strong><?= htmlspecialchars($leader['leader_name']) ?></strong>
                                                        <br>
                                                        <span class="badge bg-primary"><?= $leader['leader_id'] ?></span>
                                                        <span class="badge bg-secondary"><?= $leader['username'] ?></span>
                                                        <br>
                                                        <small class="text-muted"><?= htmlspecialchars($leader['caller_name']) ?> (<?= $leader['finqy_id'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $codeClass = 'access-code';
                                                    if ($leader['time_remaining_seconds'] <= 0) {
                                                        $codeClass .= ' expired';
                                                    } elseif ($leader['time_remaining_seconds'] <= 3600) { // Less than 1 hour
                                                        $codeClass .= ' expiring';
                                                    }
                                                    ?>
                                                    <div class="<?= $codeClass ?>" onclick="copyToClipboard('<?= $leader['current_code'] ?>')">
                                                        <?= $leader['current_code'] ?: 'N/A' ?>
                                                    </div>
                                                    <?php if ($leader['current_code']): ?>
                                                        <small class="text-muted d-block">Click to copy</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="countdown-timer" data-expiry="<?= $leader['time_remaining_seconds'] ?>">
                                                        <?= $leader['time_remaining_formatted'] ?>
                                                    </div>
                                                    <small class="text-muted d-block">
                                                        <?= $leader['code_generated_at'] ? date('d-M H:i', strtotime($leader['code_generated_at'])) : 'Not set' ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($leader['last_login']): ?>
                                                        <?= date('d-M-Y H:i', strtotime($leader['last_login'])) ?>
                                                        <br>
                                                        <span class="badge bg-<?= $leader['today_logins'] > 0 ? 'success' : 'secondary' ?>">
                                                            <?= $leader['today_logins'] ?> today
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Never</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?= $leader['total_actions'] ?> actions</span>
                                                </td>
                                                <td>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="leader_id" value="<?= $leader['leader_id'] ?>">
                                                        <button type="submit" name="refresh_code" 
                                                                class="btn btn-sm btn-outline-primary"
                                                                onclick="return confirm('Generate a new access code for <?= addslashes($leader['leader_name']) ?>?')">
                                                            <i class="bi bi-arrow-clockwise"></i> Refresh Code
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Instructions -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>How Access Codes Work</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>For Team Leaders:</h6>
                                <ul class="small">
                                    <li>Enter your <strong>username</strong> and <strong>password</strong></li>
                                    <li>Enter the current <strong>6-character access code</strong> shown above</li>
                                    <li>Complete the time-based authentication as usual</li>
                                    <li>Access codes change automatically every 4 hours</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>For Admins:</h6>
                                <ul class="small">
                                    <li>Share the current access code with your team leaders</li>
                                    <li>Codes refresh automatically - no manual action needed</li>
                                    <li>Use "Refresh Code" to generate a new code immediately</li>
                                    <li>Monitor login activity and code usage</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Copy code to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                // Show toast notification
                const toast = document.createElement('div');
                toast.className = 'toast position-fixed bottom-0 end-0 m-3';
                toast.innerHTML = `
                    <div class="toast-header">
                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                        <strong class="me-auto">Copied!</strong>
                    </div>
                    <div class="toast-body">
                        Access code copied to clipboard: <strong>${text}</strong>
                    </div>
                `;
                document.body.appendChild(toast);
                const bsToast = new bootstrap.Toast(toast);
                bsToast.show();
                
                // Remove toast after it hides
                toast.addEventListener('hidden.bs.toast', function() {
                    document.body.removeChild(toast);
                });
            });
        }

        // Update countdown timers
        function updateCountdowns() {
            document.querySelectorAll('.countdown-timer').forEach(function(timer) {
                let seconds = parseInt(timer.getAttribute('data-expiry'));
                
                if (seconds > 0) {
                    seconds--;
                    timer.setAttribute('data-expiry', seconds);
                    
                    const hours = Math.floor(seconds / 3600);
                    const minutes = Math.floor((seconds % 3600) / 60);
                    const secs = seconds % 60;
                    
                    timer.textContent = 
                        String(hours).padStart(2, '0') + ':' +
                        String(minutes).padStart(2, '0') + ':' +
                        String(secs).padStart(2, '0');
                        
                    // Update access code styling based on time remaining
                    const row = timer.closest('tr');
                    const accessCode = row.querySelector('.access-code');
                    
                    if (seconds <= 0) {
                        accessCode.className = 'access-code expired';
                        timer.textContent = 'EXPIRED';
                        timer.style.color = '#dc3545';
                    } else if (seconds <= 3600) { // Less than 1 hour
                        accessCode.className = 'access-code expiring';
                    }
                } else {
                    timer.textContent = 'EXPIRED';
                    timer.style.color = '#dc3545';
                }
            });
        }

        // Update every second
        setInterval(updateCountdowns, 1000);
        
        // Auto-refresh page every 5 minutes to get updated codes
        setTimeout(() => location.reload(), 300000);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>