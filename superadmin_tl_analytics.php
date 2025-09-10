<?php
/**
 * Superadmin Relationship Manager (Team Leader) Analytics Dashboard
 * Comprehensive system-wide analytics for Team Leader performance and activity
 */

require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();

// Date range filter
$date_range = $_GET['date_range'] ?? '30';
$selected_admin = $_GET['admin_id'] ?? '';
$selected_tl = $_GET['tl_id'] ?? '';
$activity_type = $_GET['activity_type'] ?? '';

// Get all admins for filter
$admins_sql = "SELECT admin_id, name as admin_name FROM admin_users WHERE designation != 'superadmin' ORDER BY name";
$admins_stmt = $conn->prepare($admins_sql);
if ($admins_stmt === false) {
    error_log("Admins SQL Error: " . $conn->error);
    $admins = [];
} else {
    $admins_stmt->execute();
    $admins = $admins_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $admins_stmt->close();
}

// Get all team leaders for filter
$tls_sql = "
    SELECT tl.leader_id, tl.leader_name, tl.admin_id, au.name as admin_name 
    FROM team_leaders tl 
    JOIN admin_users au ON tl.admin_id = au.admin_id 
    WHERE tl.is_active = 1 
    ORDER BY au.name, tl.leader_name
";
$tls_stmt = $conn->prepare($tls_sql);
if ($tls_stmt === false) {
    error_log("Team Leaders SQL Error: " . $conn->error);
    $team_leaders = [];
} else {
    $tls_stmt->execute();
    $team_leaders = $tls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $tls_stmt->close();
}

// Build WHERE clauses for filters
$where_conditions = [];
$params = [];
$param_types = "";

if ($date_range !== 'all') {
    $where_conditions[] = "DATE(tl_activity.activity_date) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params[] = (int)$date_range;
    $param_types .= "i";
}

if ($selected_admin) {
    $where_conditions[] = "tl.admin_id = ?";
    $params[] = $selected_admin;
    $param_types .= "s";
}

if ($selected_tl) {
    $where_conditions[] = "tl.leader_id = ?";
    $params[] = $selected_tl;
    $param_types .= "s";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// System-wide statistics
$stats_sql = "
    SELECT 
        COUNT(DISTINCT tl.leader_id) as total_team_leaders,
        COUNT(DISTINCT tl.admin_id) as active_admins,
        COUNT(DISTINCT CASE WHEN tll.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN tl.leader_id END) as active_this_week,
        COUNT(DISTINCT CASE WHEN tll.login_time >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN tl.leader_id END) as active_today,
        COUNT(CASE WHEN tll.login_status = 'success' AND DATE(tll.login_time) = CURDATE() THEN 1 END) as successful_logins_today,
        COUNT(CASE WHEN tll.login_status = 'failed' AND DATE(tll.login_time) = CURDATE() THEN 1 END) as failed_logins_today,
        (SELECT COUNT(*) FROM team_leader_actions WHERE DATE(action_date) = CURDATE()) as actions_today,
        (SELECT COUNT(*) FROM team_leader_view_logs WHERE DATE(timestamp) = CURDATE()) as data_accesses_today
    FROM team_leaders tl
    LEFT JOIN team_leader_logins tll ON tl.leader_id = tll.leader_id
    WHERE tl.is_active = 1
";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt === false) {
    error_log("System Stats SQL Error: " . $conn->error);
    $system_stats = [
        'total_team_leaders' => 0,
        'active_admins' => 0,
        'active_this_week' => 0,
        'active_today' => 0,
        'successful_logins_today' => 0,
        'failed_logins_today' => 0,
        'actions_today' => 0,
        'data_accesses_today' => 0
    ];
} else {
    $stats_stmt->execute();
    $system_stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
}

// Top performing team leaders
$top_performers_sql = "
    SELECT 
        tl.leader_id,
        tl.leader_name,
        au.name as admin_name,
        COUNT(tla.id) as total_actions,
        COUNT(CASE WHEN tla.new_disposition = 'Interested - Proceed to Payment' THEN 1 END) as payment_conversions,
        COUNT(CASE WHEN tla.new_disposition IN ('Interested - Proceed to Payment', 'Need More Information') THEN 1 END) as positive_outcomes,
        ROUND((COUNT(CASE WHEN tla.new_disposition = 'Interested - Proceed to Payment' THEN 1 END) / NULLIF(COUNT(tla.id), 0)) * 100, 1) as conversion_rate
    FROM team_leaders tl
    JOIN admin_users au ON tl.admin_id = au.admin_id
    LEFT JOIN team_leader_actions tla ON tl.leader_id = tla.leader_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
    WHERE tl.is_active = 1
    GROUP BY tl.leader_id, tl.leader_name, au.name
    HAVING total_actions > 0
    ORDER BY conversion_rate DESC, total_actions DESC
    LIMIT 10
";

$performers_stmt = $conn->prepare($top_performers_sql);
if ($performers_stmt === false) {
    error_log("Top Performers SQL Error: " . $conn->error);
    $top_performers = [];
} else {
    $range = $date_range === 'all' ? 365 : (int)$date_range;
    $performers_stmt->bind_param("i", $range);
    $performers_stmt->execute();
    $top_performers = $performers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $performers_stmt->close();
}

// Disposition breakdown
$disposition_sql = "
    SELECT 
        tla.new_disposition,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM team_leader_actions WHERE action_date >= DATE_SUB(NOW(), INTERVAL ? DAY))) * 100, 1) as percentage
    FROM team_leader_actions tla
    JOIN team_leaders tl ON tla.leader_id = tl.leader_id
    WHERE tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
    " . ($selected_admin ? "AND tl.admin_id = '$selected_admin'" : "") . "
    " . ($selected_tl ? "AND tl.leader_id = '$selected_tl'" : "") . "
    GROUP BY tla.new_disposition
    ORDER BY count DESC
";

$disposition_stmt = $conn->prepare($disposition_sql);
if ($disposition_stmt === false) {
    error_log("Disposition SQL Error: " . $conn->error);
    $dispositions = [];
} else {
    $range = $date_range === 'all' ? 365 : (int)$date_range;
    $disposition_stmt->bind_param("ii", $range, $range);
    $disposition_stmt->execute();
    $dispositions = $disposition_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $disposition_stmt->close();
}

// Admin-wise performance - Fixed to avoid Cartesian product
$admin_performance_sql = "
    SELECT 
        au.admin_id,
        au.name as admin_name,
        
        (SELECT COUNT(DISTINCT tl.leader_id) 
         FROM team_leaders tl 
         WHERE tl.admin_id = au.admin_id AND tl.is_active = 1) as team_leaders_count,
        
        (SELECT COUNT(*) 
         FROM team_leader_actions tla 
         JOIN team_leaders tl ON tla.leader_id = tl.leader_id 
         WHERE tl.admin_id = au.admin_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)) as total_actions,
        
        (SELECT COUNT(*) 
         FROM team_leader_actions tla 
         JOIN team_leaders tl ON tla.leader_id = tl.leader_id 
         WHERE tl.admin_id = au.admin_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY) AND tla.new_disposition = 'Interested - Proceed to Payment') as conversions,
        
        ROUND((SELECT COUNT(*) FROM team_leader_actions tla JOIN team_leaders tl ON tla.leader_id = tl.leader_id WHERE tl.admin_id = au.admin_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY) AND tla.new_disposition = 'Interested - Proceed to Payment') / 
              NULLIF((SELECT COUNT(*) FROM team_leader_actions tla JOIN team_leaders tl ON tla.leader_id = tl.leader_id WHERE tl.admin_id = au.admin_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)), 0) * 100, 1) as conversion_rate,
        
        (SELECT COUNT(DISTINCT tll.leader_id) 
         FROM team_leader_logins tll 
         JOIN team_leaders tl ON tll.leader_id = tl.leader_id 
         WHERE tl.admin_id = au.admin_id AND tll.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as active_this_week
    
    FROM admin_users au
    WHERE au.designation != 'superadmin'
    ORDER BY conversion_rate DESC, total_actions DESC
";

$admin_perf_stmt = $conn->prepare($admin_performance_sql);
if ($admin_perf_stmt === false) {
    error_log("Admin Performance SQL Error: " . $conn->error);
    $admin_performance = [];
} else {
    $range = $date_range === 'all' ? 365 : (int)$date_range;
    $admin_perf_stmt->bind_param("iiii", $range, $range, $range, $range);
    $admin_perf_stmt->execute();
    $admin_performance = $admin_perf_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $admin_perf_stmt->close();
}

// Recent security events
$security_events_sql = "
    SELECT 
        tl.leader_name,
        au.name as admin_name,
        tll.ip_address,
        tll.login_status,
        tll.login_time,
        tll.user_agent
    FROM team_leader_logins tll
    JOIN team_leaders tl ON tll.leader_id = tl.leader_id
    JOIN admin_users au ON tl.admin_id = au.admin_id
    WHERE tll.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    " . ($selected_admin ? "AND tl.admin_id = '$selected_admin'" : "") . "
    ORDER BY tll.login_time DESC
    LIMIT 50
";

$security_stmt = $conn->prepare($security_events_sql);
if ($security_stmt === false) {
    error_log("Security Events SQL Error: " . $conn->error);
    $security_events = [];
} else {
    $security_stmt->execute();
    $security_events = $security_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $security_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relationship Manager Analytics - Superadmin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #1e3c72 0%, #2a5298 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 12px 35px rgba(0,0,0,0.15); 
        }
        .performance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .security-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .conversion-badge {
            font-size: 0.9rem;
            padding: 0.5rem 0.8rem;
        }
        .admin-row {
            transition: background-color 0.2s;
        }
        .admin-row:hover {
            background-color: #f8f9fa;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .progress-ring {
            width: 60px;
            height: 60px;
        }
        .metric-icon {
            font-size: 2.5rem;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-people-fill me-2 text-primary"></i>Relationship Manager Analytics
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-outline-primary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- System Overview Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Total RMs</h6>
                                        <h2 class="mb-0"><?= $system_stats['total_team_leaders'] ?></h2>
                                        <small>across <?= $system_stats['active_admins'] ?> admins</small>
                                    </div>
                                    <i class="bi bi-people-fill metric-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Active Today</h6>
                                        <h2 class="mb-0"><?= $system_stats['active_today'] ?></h2>
                                        <small><?= $system_stats['active_this_week'] ?> this week</small>
                                    </div>
                                    <i class="bi bi-activity metric-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Actions Today</h6>
                                        <h2 class="mb-0"><?= $system_stats['actions_today'] ?></h2>
                                        <small><?= $system_stats['data_accesses_today'] ?> data accesses</small>
                                    </div>
                                    <i class="bi bi-lightning-fill metric-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Security Events</h6>
                                        <h2 class="mb-0 text-success"><?= $system_stats['successful_logins_today'] ?></h2>
                                        <small class="text-danger"><?= $system_stats['failed_logins_today'] ?> failed</small>
                                    </div>
                                    <i class="bi bi-shield-check metric-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card performance-card mb-4">
                    <div class="card-body">
                        <h6 class="card-title text-white"><i class="bi bi-funnel-fill me-2"></i>Analytics Filters</h6>
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label text-white-50">Date Range</label>
                                <select name="date_range" class="form-select">
                                    <option value="7" <?= $date_range === '7' ? 'selected' : '' ?>>Last 7 days</option>
                                    <option value="30" <?= $date_range === '30' ? 'selected' : '' ?>>Last 30 days</option>
                                    <option value="90" <?= $date_range === '90' ? 'selected' : '' ?>>Last 90 days</option>
                                    <option value="365" <?= $date_range === '365' ? 'selected' : '' ?>>Last year</option>
                                    <option value="all" <?= $date_range === 'all' ? 'selected' : '' ?>>All time</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-white-50">Admin</label>
                                <select name="admin_id" class="form-select">
                                    <option value="">All Admins</option>
                                    <?php foreach ($admins as $admin): ?>
                                        <option value="<?= $admin['admin_id'] ?>" <?= $selected_admin === $admin['admin_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($admin['admin_name']) ?> (<?= $admin['admin_id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-white-50">Relationship Manager</label>
                                <select name="tl_id" class="form-select">
                                    <option value="">All RMs</option>
                                    <?php foreach ($team_leaders as $tl): ?>
                                        <option value="<?= $tl['leader_id'] ?>" <?= $selected_tl === $tl['leader_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tl['leader_name']) ?> (<?= $tl['admin_name'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-white-50">Activity</label>
                                <select name="activity_type" class="form-select">
                                    <option value="">All Activities</option>
                                    <option value="actions" <?= $activity_type === 'actions' ? 'selected' : '' ?>>Lead Actions</option>
                                    <option value="logins" <?= $activity_type === 'logins' ? 'selected' : '' ?>>Logins</option>
                                    <option value="data_access" <?= $activity_type === 'data_access' ? 'selected' : '' ?>>Data Access</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-light me-2">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="superadmin_tl_analytics.php" class="btn btn-outline-light">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <!-- Top Performers -->
                    <div class="col-lg-6">
                        <div class="card stat-card">
                            <div class="card-header bg-white border-0">
                                <h5 class="mb-0">
                                    <i class="bi bi-trophy-fill text-warning me-2"></i>Top Performing RMs
                                    <span class="badge bg-primary ms-2"><?= count($top_performers) ?></span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($top_performers)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-trophy display-4 text-muted opacity-25"></i>
                                        <p class="text-muted mt-3">No performance data available for the selected period.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Rank</th>
                                                    <th>RM Name</th>
                                                    <th>Admin</th>
                                                    <th>Actions</th>
                                                    <th>Conversion</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($top_performers as $index => $performer): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge <?= $index < 3 ? 'bg-warning' : 'bg-secondary' ?>"><?= $index + 1 ?></span>
                                                        </td>
                                                        <td>
                                                            <strong><?= htmlspecialchars($performer['leader_name']) ?></strong>
                                                            <br><small class="text-muted"><?= $performer['leader_id'] ?></small>
                                                        </td>
                                                        <td>
                                                            <small><?= htmlspecialchars($performer['admin_name']) ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= $performer['total_actions'] ?></span>
                                                            <small class="text-muted d-block"><?= $performer['positive_outcomes'] ?> positive</small>
                                                        </td>
                                                        <td>
                                                            <span class="conversion-badge badge <?= $performer['conversion_rate'] >= 20 ? 'bg-success' : ($performer['conversion_rate'] >= 10 ? 'bg-warning' : 'bg-danger') ?>">
                                                                <?= $performer['conversion_rate'] ?>%
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Disposition Breakdown -->
                    <div class="col-lg-6">
                        <div class="card stat-card">
                            <div class="card-header bg-white border-0">
                                <h5 class="mb-0">
                                    <i class="bi bi-pie-chart-fill text-success me-2"></i>Disposition Breakdown
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($dispositions)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-pie-chart display-4 text-muted opacity-25"></i>
                                        <p class="text-muted mt-3">No disposition data available.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($dispositions as $disposition): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div>
                                                <strong><?= htmlspecialchars($disposition['new_disposition']) ?></strong>
                                                <div class="progress" style="width: 200px; height: 8px;">
                                                    <div class="progress-bar bg-<?= 
                                                        strpos($disposition['new_disposition'], 'Payment') !== false ? 'success' : 
                                                        (strpos($disposition['new_disposition'], 'Not') !== false ? 'danger' : 'info') 
                                                    ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= $disposition['percentage'] ?>%">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <strong><?= $disposition['count'] ?></strong>
                                                <small class="text-muted d-block"><?= $disposition['percentage'] ?>%</small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admin Performance Comparison -->
                <div class="card stat-card mb-4">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-building text-primary me-2"></i>Admin Performance Comparison
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Admin</th>
                                        <th>Team Leaders</th>
                                        <th>Total Actions</th>
                                        <th>Conversions</th>
                                        <th>Conversion Rate</th>
                                        <th>Active This Week</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_performance as $admin): ?>
                                        <tr class="admin-row">
                                            <td>
                                                <strong><?= htmlspecialchars($admin['admin_name']) ?></strong>
                                                <br><small class="text-muted"><?= $admin['admin_id'] ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $admin['team_leaders_count'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $admin['total_actions'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?= $admin['conversions'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $admin['conversion_rate'] >= 15 ? 'bg-success' : ($admin['conversion_rate'] >= 8 ? 'bg-warning' : 'bg-danger') ?>">
                                                    <?= $admin['conversion_rate'] ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?= $admin['active_this_week'] ?></span>
                                            </td>
                                            <td>
                                                <div class="progress" style="width: 100px; height: 20px;">
                                                    <div class="progress-bar bg-<?= $admin['conversion_rate'] >= 15 ? 'success' : ($admin['conversion_rate'] >= 8 ? 'warning' : 'danger') ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= min(100, $admin['conversion_rate'] * 5) ?>%">
                                                        <small><?= $admin['conversion_rate'] ?>%</small>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Security Events -->
                <div class="card security-card">
                    <div class="card-header border-0">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-shield-exclamation me-2"></i>Recent Security Events
                            <span class="badge bg-white text-dark ms-2"><?= count($security_events) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($security_events)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-shield-check display-4 text-white opacity-25"></i>
                                <p class="text-white mt-3">No recent security events.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-dark table-hover">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>RM</th>
                                            <th>Admin</th>
                                            <th>Status</th>
                                            <th>IP Address</th>
                                            <th>User Agent</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($security_events, 0, 20) as $event): ?>
                                            <tr>
                                                <td>
                                                    <small><?= date('M d, H:i', strtotime($event['login_time'])) ?></small>
                                                </td>
                                                <td>
                                                    <small><?= htmlspecialchars($event['leader_name']) ?></small>
                                                </td>
                                                <td>
                                                    <small><?= htmlspecialchars($event['admin_name']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $event['login_status'] === 'success' ? 'bg-success' : 'bg-danger' ?>">
                                                        <?= ucfirst($event['login_status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <code class="text-light"><?= htmlspecialchars($event['ip_address']) ?></code>
                                                </td>
                                                <td>
                                                    <small title="<?= htmlspecialchars($event['user_agent']) ?>">
                                                        <?= htmlspecialchars(substr($event['user_agent'], 0, 30)) ?>...
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>