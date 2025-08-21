<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Filter parameters
$filterLeader = $_GET['leader'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterDate = $_GET['date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Build query conditions
$conditions = ["tl.admin_id = ?"];
$params = [$adminId];
$paramTypes = "s";

if ($filterLeader) {
    $conditions[] = "tl.leader_id = ?";
    $params[] = $filterLeader;
    $paramTypes .= "s";
}

if ($filterType) {
    if ($filterType === 'login_success') {
        $conditions[] = "tll.login_status = 'success'";
    } elseif ($filterType === 'login_failed') {
        $conditions[] = "tll.login_status = 'failed'";
    } elseif ($filterType === 'actions') {
        // We'll handle this separately in a union query
    }
}

if ($filterDate) {
    $conditions[] = "DATE(COALESCE(tll.login_time, tla.action_date)) = ?";
    $params[] = $filterDate;
    $paramTypes .= "s";
}

$whereClause = implode(" AND ", $conditions);

// Execute queries separately to avoid UNION parameter issues
$activities = [];

if ($filterType === 'actions' || !$filterType) {
    // Get action activities
    $actionQuery = "
        SELECT 
            'action' as activity_type,
            tl.leader_id,
            tl.leader_name,
            c.caller_name,
            'completed' as status,
            COALESCE(tla.ip_address, '') as ip_address,
            COALESCE(tla.user_agent, '') as user_agent,
            tla.action_date as timestamp,
            COALESCE(tla.session_id, '') as session_id,
            fcl.name as lead_name,
            tla.new_disposition as disposition
        FROM team_leaders tl
        JOIN callers c ON tl.finqy_id = c.finqy_id
        JOIN team_leader_actions tla ON tl.leader_id = tla.leader_id
        JOIN final_call_logs fcl ON tla.lead_id = fcl.id
        WHERE $whereClause
        ORDER BY tla.action_date DESC
    ";
    
    if ($filterType === 'actions') {
        $actionQuery .= " LIMIT $limit OFFSET $offset";
    }
    
    $stmt = $conn->prepare($actionQuery);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $actionResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $activities = array_merge($activities, $actionResults);
        $stmt->close();
    }
}

if ($filterType !== 'actions') {
    // Get login activities
    $loginQuery = "
        SELECT 
            'login' as activity_type,
            tl.leader_id,
            tl.leader_name,
            c.caller_name,
            tll.login_status as status,
            COALESCE(tll.ip_address, '') as ip_address,
            COALESCE(tll.user_agent, '') as user_agent,
            tll.login_time as timestamp,
            COALESCE(tll.session_id, '') as session_id,
            NULL as lead_name,
            NULL as disposition
        FROM team_leaders tl
        JOIN callers c ON tl.finqy_id = c.finqy_id
        JOIN team_leader_logins tll ON tl.leader_id = tll.leader_id
        WHERE $whereClause
    ";
    
    if ($filterType) {
        $loginQuery .= " ORDER BY tll.login_time DESC LIMIT $limit OFFSET $offset";
    } else {
        $loginQuery .= " ORDER BY tll.login_time DESC";
    }
    
    $stmt = $conn->prepare($loginQuery);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $loginResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $activities = array_merge($activities, $loginResults);
        $stmt->close();
    }
}

// Sort combined results by timestamp
if (!$filterType) {
    usort($activities, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // Apply pagination to combined results
    $totalCount = count($activities);
    $activities = array_slice($activities, $offset, $limit);
} else {
    // For filtered results, get total count
    $countQuery = "";
    if ($filterType === 'actions') {
        $countQuery = "
            SELECT COUNT(*) as total
            FROM team_leaders tl
            JOIN team_leader_actions tla ON tl.leader_id = tla.leader_id
            WHERE $whereClause
        ";
    } else {
        $countQuery = "
            SELECT COUNT(*) as total
            FROM team_leaders tl
            JOIN team_leader_logins tll ON tl.leader_id = tll.leader_id
            WHERE $whereClause
        ";
    }
    
    $stmt = $conn->prepare($countQuery);
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $totalCount = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
}

$totalPages = ceil($totalCount / $limit);

// Get team leaders for filter dropdown
$teamLeaders = [];
$stmt = $conn->prepare("
    SELECT tl.leader_id, tl.leader_name 
    FROM team_leaders tl 
    WHERE tl.admin_id = ? AND tl.is_active = 1 
    ORDER BY tl.leader_name
");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $teamLeaders[] = $row;
}
$stmt->close();

// Get summary statistics
$stats = [];
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT tll.leader_id) as active_leaders_today,
        COUNT(CASE WHEN tll.login_status = 'success' AND DATE(tll.login_time) = CURDATE() THEN 1 END) as successful_logins_today,
        COUNT(CASE WHEN tll.login_status = 'failed' AND DATE(tll.login_time) = CURDATE() THEN 1 END) as failed_logins_today,
        (SELECT COUNT(*) FROM team_leader_actions tla 
         JOIN team_leaders tl ON tla.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND DATE(tla.action_date) = CURDATE()) as actions_today
    FROM team_leader_logins tll
    JOIN team_leaders tl ON tll.leader_id = tl.leader_id
    WHERE tl.admin_id = ? AND DATE(tll.login_time) = CURDATE()
");
$stmt->bind_param("ss", $adminId, $adminId);
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
    <title>Team Leader Activity Monitor</title>
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
        .activity-login { border-left: 4px solid #007bff; }
        .activity-login.success { border-left-color: #28a745; }
        .activity-login.failed { border-left-color: #dc3545; }
        .activity-action { border-left: 4px solid #6f42c1; }
        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .user-agent {
            font-size: 0.8rem;
            color: #6c757d;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .activity-row {
            transition: background-color 0.2s;
        }
        .activity-row:hover {
            background-color: #f8f9fa;
        }
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
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
                        <i class="bi bi-activity me-2"></i>Team Leader Activity Monitor
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Active Today</h6>
                                        <h2 class="mb-0"><?= $stats['active_leaders_today'] ?></h2>
                                    </div>
                                    <i class="bi bi-people-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Successful Logins</h6>
                                        <h2 class="mb-0"><?= $stats['successful_logins_today'] ?></h2>
                                    </div>
                                    <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Failed Logins</h6>
                                        <h2 class="mb-0"><?= $stats['failed_logins_today'] ?></h2>
                                    </div>
                                    <i class="bi bi-x-circle-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Actions Today</h6>
                                        <h2 class="mb-0"><?= $stats['actions_today'] ?></h2>
                                    </div>
                                    <i class="bi bi-lightning-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <h6 class="card-title"><i class="bi bi-funnel me-2"></i>Filter Activities</h6>
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Team Leader</label>
                                <select name="leader" class="form-select">
                                    <option value="">All Team Leaders</option>
                                    <?php foreach ($teamLeaders as $leader): ?>
                                        <option value="<?= $leader['leader_id'] ?>" <?= $filterLeader === $leader['leader_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($leader['leader_name']) ?> (<?= $leader['leader_id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Activity Type</label>
                                <select name="type" class="form-select">
                                    <option value="">All Activities</option>
                                    <option value="login_success" <?= $filterType === 'login_success' ? 'selected' : '' ?>>Successful Logins</option>
                                    <option value="login_failed" <?= $filterType === 'login_failed' ? 'selected' : '' ?>>Failed Logins</option>
                                    <option value="actions" <?= $filterType === 'actions' ? 'selected' : '' ?>>Lead Actions</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-select" value="<?= htmlspecialchars($filterDate) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-light me-2">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="admin_team_leader_activity.php" class="btn btn-outline-light">
                                    <i class="bi bi-x-circle"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Activities List -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-ul me-2"></i>Recent Activities
                                <span class="badge bg-primary"><?= number_format($totalCount) ?></span>
                            </h5>
                            <small class="text-muted">Showing page <?= $page ?> of <?= $totalPages ?></small>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($activities)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-activity display-4 opacity-25"></i>
                                <p class="mt-3 text-muted">No activities found matching your criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Team Leader</th>
                                            <th>Activity</th>
                                            <th>Status/Details</th>
                                            <th>IP Address</th>
                                            <th>User Agent</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activities as $activity): ?>
                                            <tr class="activity-row activity-<?= $activity['activity_type'] ?> <?= $activity['activity_type'] === 'login' ? $activity['status'] : '' ?>">
                                                <td>
                                                    <div class="fw-bold"><?= date('d-M-Y', strtotime($activity['timestamp'])) ?></div>
                                                    <small class="text-muted"><?= date('H:i:s', strtotime($activity['timestamp'])) ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?= htmlspecialchars($activity['leader_name']) ?></div>
                                                    <small class="text-muted"><?= $activity['leader_id'] ?></small>
                                                    <br><small class="text-muted"><?= htmlspecialchars($activity['caller_name']) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($activity['activity_type'] === 'login'): ?>
                                                        <span class="badge bg-<?= $activity['status'] === 'success' ? 'success' : ($activity['status'] === 'failed' ? 'danger' : 'warning') ?>">
                                                            <i class="bi bi-box-arrow-in-right me-1"></i><?= ucfirst($activity['status']) ?> Login
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-purple">
                                                            <i class="bi bi-lightning me-1"></i>Lead Action
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($activity['activity_type'] === 'action' && $activity['lead_name']): ?>
                                                        <div class="small">
                                                            <strong>Customer:</strong> <?= htmlspecialchars($activity['lead_name']) ?><br>
                                                            <strong>Disposition:</strong> 
                                                            <span class="badge bg-info"><?= htmlspecialchars($activity['disposition']) ?></span>
                                                        </div>
                                                    <?php elseif ($activity['session_id']): ?>
                                                        <small class="text-muted">Session: <?= substr($activity['session_id'], 0, 8) ?>...</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="ip-address"><?= htmlspecialchars($activity['ip_address'] ?: 'Unknown') ?></span>
                                                </td>
                                                <td>
                                                    <div class="user-agent" title="<?= htmlspecialchars($activity['user_agent'] ?: 'Unknown') ?>">
                                                        <?= htmlspecialchars(substr($activity['user_agent'] ?: 'Unknown', 0, 50)) ?>
                                                        <?= strlen($activity['user_agent'] ?: '') > 50 ? '...' : '' ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="card-footer bg-white">
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $totalPages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        .bg-purple {
            background-color: #6f42c1 !important;
        }
    </style>
</body>
</html>