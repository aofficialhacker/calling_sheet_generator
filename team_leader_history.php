<?php
require_once 'db_config.php';
require_once 'masking_utils.php';
requireTeamLeader();

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$leaderName = $_SESSION['leader_name'];

// Get all actions taken by this team leader
$actions = [];
$stmt = $conn->prepare("
    SELECT tla.*, fcl.name, fcl.mobile_no,
           p.product_name, c.caller_name as original_caller,
           tld.bucket_id, db.bucket_name
    FROM lv_team_leader_actions tla
    JOIN lv_final_call_logs fcl ON tla.lead_id = fcl.id
    JOIN lv_file_batches b ON fcl.batch_id = b.id
    JOIN lv_products p ON b.product_code = p.product_code
    JOIN lv_callers c ON fcl.finqy_id = c.finqy_id
    LEFT JOIN lv_team_leader_dispositions tld ON tla.new_disposition = tld.disposition_name
    LEFT JOIN lv_disposition_buckets db ON tld.bucket_id = db.id
    WHERE tla.leader_id = ?
    ORDER BY tla.action_date DESC
");
$stmt->bind_param("s", $leaderId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $actions[] = $row;
}
$stmt->close();

// Get action statistics
$stats = [];
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_actions,
        COUNT(DISTINCT DATE(tla.action_date)) as active_days,
        COUNT(CASE WHEN db.bucket_name = 'Interested' THEN 1 END) as payment_ready,
        COUNT(CASE WHEN DATE(tla.action_date) = CURDATE() THEN 1 END) as today_actions
    FROM lv_team_leader_actions tla
    LEFT JOIN lv_team_leader_dispositions tld ON tla.new_disposition = tld.disposition_name
    LEFT JOIN lv_disposition_buckets db ON tld.bucket_id = db.id
    WHERE tla.leader_id = ?
");
$stmt->bind_param("s", $leaderId);
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
    <title>Action History - Relationship Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { 
            min-height: 100vh; 
            background: linear-gradient(180deg, #6f42c1 0%, #5a2d91 100%); 
            color: white; 
        }
        .sidebar .nav-link { 
            color: rgba(255,255,255,0.8); 
            padding: 0.75rem 1rem; 
            margin: 0.25rem 0; 
            border-radius: 0.5rem; 
            transition: all 0.3s; 
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            color: white; 
            background-color: rgba(255,255,255,0.1); 
        }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .action-card {
            border-left: 4px solid #28a745;
            transition: all 0.3s;
        }
        .action-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .disposition-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        .timeline-item {
            position: relative;
            padding-left: 2rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 1rem;
            width: 0.5rem;
            height: 0.5rem;
            background: #007bff;
            border-radius: 50%;
        }
        .timeline-item::after {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 1.5rem;
            width: 2px;
            height: calc(100% - 1rem);
            background: #dee2e6;
        }
        .timeline-item:last-child::after {
            display: none;
        }
    </style>
</head>
<body data-role="team-leader">
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky">
                    <div class="text-center py-3 mb-4 border-bottom">
                        <i class="bi bi-person-badge-fill fs-2"></i>
                        <h5 class="mt-2">Relationship Manager</h5>
                        <small><?= htmlspecialchars($leaderName) ?></small>
                        <br><small class="text-muted"><?= $leaderId ?></small>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="team_leader_dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="team_leader_history.php">
                                <i class="bi bi-clock-history me-2"></i>Action History
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="follow_up_calendar.php">
                                <i class="bi bi-calendar-check me-2"></i>Follow-up Calendar
                            </a>
                        </li>
                        <li class="nav-item mt-4">
                            <a class="nav-link text-danger" href="logout.php?type=team_leader">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-clock-history me-2 text-info"></i>Action History</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Total Actions</h6>
                                        <h2 class="mb-0"><?= $stats['total_actions'] ?></h2>
                                    </div>
                                    <i class="bi bi-activity fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Payment Ready</h6>
                                        <h2 class="mb-0"><?= $stats['payment_ready'] ?></h2>
                                    </div>
                                    <i class="bi bi-credit-card-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Active Days</h6>
                                        <h2 class="mb-0"><?= $stats['active_days'] ?></h2>
                                    </div>
                                    <i class="bi bi-calendar-check-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Today's Actions</h6>
                                        <h2 class="mb-0"><?= $stats['today_actions'] ?></h2>
                                    </div>
                                    <i class="bi bi-calendar-day-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action History -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-list-task me-2"></i>All Actions Taken
                            <span class="badge bg-primary"><?= count($actions) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($actions)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-clock-history display-4 opacity-25"></i>
                                <p class="mt-3">No actions taken yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($actions as $action): ?>
                                    <div class="timeline-item mb-4">
                                        <div class="card action-card">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-8">
                                                        <h6 class="card-title mb-2">
                                                            <i class="bi bi-person me-1"></i><?= htmlspecialchars(maskName($action['name'])) ?>
                                                        </h6>
                                                        <div class="mb-2">
                                                            <span class="badge disposition-badge <?= $action['bucket_name'] == 'Interested' ? 'bg-success' : 'bg-secondary' ?>">
                                                                <?= htmlspecialchars($action['new_disposition']) ?>
                                                            </span>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars(maskMobile($action['mobile_no'])) ?><br>
                                                            <i class="bi bi-box me-1"></i><?= htmlspecialchars($action['product_name']) ?><br>
                                                            <i class="bi bi-person-badge me-1"></i>Original caller: <?= htmlspecialchars($action['original_caller']) ?>
                                                        </div>
                                                        <?php if ($action['remarks']): ?>
                                                            <div class="mt-2">
                                                                <small class="text-muted">
                                                                    <i class="bi bi-chat-text me-1"></i>
                                                                    <strong>Notes:</strong> <?= htmlspecialchars($action['remarks']) ?>
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-4 text-end">
                                                        <div class="small">
                                                            <strong>Action ID:</strong><br>
                                                            <code><?= htmlspecialchars($action['action_id']) ?></code><br>
                                                            <strong>Date:</strong><br>
                                                            <?= date('d-M-Y H:i', strtotime($action['action_date'])) ?><br>
                                                        </div>
                                                        <?php if ($action['bucket_name'] == 'Interested'): ?>
                                                            <div class="mt-2">
                                                                <a href="payment_request.php?lead_id=<?= $action['lead_id'] ?>" 
                                                                   class="btn btn-sm btn-success">
                                                                    <i class="bi bi-credit-card me-1"></i>Payment
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="js/security-protection.js"></script>
    <script>
        // Check if security is disabled for this session
        <?php if (!isset($_SESSION['security_disabled'])): ?>
        // Initialize security protection for history page with relaxed settings
        const securityProtection = new SecurityProtection({
            watermarkText: 'CONFIDENTIAL - TEAM LEADER HISTORY',
            userId: '<?= htmlspecialchars($leaderId) ?>',
            sessionId: '<?= session_id() ?>',
            logEndpoint: 'security_log.php',
            maxViolations: 8,
            enableBlurOnFocus: true,
            enableTabSwitchDetection: true,
            enableScreenRecordingDetection: true,
            enableDevToolsBlocking: true,
            violationCallback: function(violation) {
                if (violation.violationCount >= 8) {
                    alert('Multiple security violations detected. Please contact your administrator.');
                    setTimeout(() => {
                        window.location.href = 'logout.php?type=team_leader&reason=security_violation';
                    }, 3000);
                }
            }
        });
        <?php endif; ?>
        
        // Enhanced session monitoring
        let sessionActive = true;
        let lastActivity = Date.now();
        
        // Track user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, () => {
                lastActivity = Date.now();
            }, { capture: true, passive: true });
        });
        
        // Check for inactivity
        setInterval(() => {
            const inactiveTime = Date.now() - lastActivity;
            if (inactiveTime > 1800000) { // 30 minutes
                alert('Session expired due to inactivity');
                window.location.href = 'logout.php?type=team_leader&reason=inactivity';
            }
        }, 60000); // Check every minute
        
        // Auto-refresh every 10 minutes
        setTimeout(() => location.reload(), 600000);
        
        // Prevent page unload without proper logout
        window.addEventListener('beforeunload', (e) => {
            if (sessionActive) {
                e.preventDefault();
                e.returnValue = 'Are you sure you want to leave? Always use the logout button for security.';
                return e.returnValue;
            }
        });
        
        // Mark session as properly ending when using logout link
        document.querySelector('a[href*="logout.php"]')?.addEventListener('click', () => {
            sessionActive = false;
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/followup-notifications.js"></script>
</body>
</html>