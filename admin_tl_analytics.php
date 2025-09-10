<?php
/**
 * Admin Relationship Manager (Team Leader) Analytics Dashboard
 * Comprehensive analytics for Team Leader performance specific to an admin
 */

require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Date range and filter parameters
$date_range = $_GET['date_range'] ?? '30';
$selected_rm = $_GET['tl_id'] ?? '';
$disposition_filter = $_GET['disposition'] ?? '';
$performance_view = $_GET['view'] ?? 'overview';

// Get admin's team leaders for filter
$tls_sql = "
    SELECT tl.leader_id, tl.leader_name, tl.finqy_id, c.caller_name 
    FROM team_leaders tl 
    LEFT JOIN callers c ON tl.finqy_id = c.finqy_id
    WHERE tl.admin_id = ? AND tl.is_active = 1 
    ORDER BY tl.leader_name
";
$tls_stmt = $conn->prepare($tls_sql);
$tls_stmt->bind_param("s", $adminId);
$tls_stmt->execute();
$team_leaders = $tls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tls_stmt->close();

// Get available dispositions for filter
$dispositions_sql = "
    SELECT DISTINCT tla.new_disposition 
    FROM team_leader_actions tla 
    JOIN team_leaders tl ON tla.leader_id = tl.leader_id 
    WHERE tl.admin_id = ? AND tla.action_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    ORDER BY tla.new_disposition
";
$disp_stmt = $conn->prepare($dispositions_sql);
$disp_stmt->bind_param("s", $adminId);
$disp_stmt->execute();
$available_dispositions = $disp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$disp_stmt->close();

// Build WHERE clauses
$where_conditions = ["tl.admin_id = ?"];
$params = [$adminId];
$param_types = "s";

if ($date_range !== 'all') {
    $where_conditions[] = "tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params[] = (int)$date_range;
    $param_types .= "i";
}

if ($selected_rm) {
    $where_conditions[] = "tl.leader_id = ?";
    $params[] = $selected_rm;
    $param_types .= "s";
}

if ($disposition_filter) {
    $where_conditions[] = "tla.new_disposition = ?";
    $params[] = $disposition_filter;
    $param_types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Admin's team leader summary - Fixed to avoid Cartesian product
$summary_sql = "
    SELECT 
        (SELECT COUNT(DISTINCT tl.leader_id) FROM team_leaders tl WHERE tl.admin_id = ? AND tl.is_active = 1) as total_team_leaders,
        
        (SELECT COUNT(DISTINCT tll.leader_id) 
         FROM team_leader_logins tll 
         JOIN team_leaders tl ON tll.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND tl.is_active = 1 AND tll.login_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as active_today,
        
        (SELECT COUNT(DISTINCT tll.leader_id) 
         FROM team_leader_logins tll 
         JOIN team_leaders tl ON tll.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND tl.is_active = 1 AND tll.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as active_this_week,
        
        (SELECT COUNT(*) 
         FROM team_leader_logins tll 
         JOIN team_leaders tl ON tll.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND tl.is_active = 1 AND tll.login_status = 'success' AND DATE(tll.login_time) = CURDATE()) as successful_logins_today,
        
        (SELECT COUNT(*) 
         FROM team_leader_logins tll 
         JOIN team_leaders tl ON tll.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND tl.is_active = 1 AND tll.login_status = 'failed' AND DATE(tll.login_time) = CURDATE()) as failed_logins_today,
        
        (SELECT COUNT(*) 
         FROM team_leader_actions tla 
         JOIN team_leaders tl ON tla.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND tl.is_active = 1 AND tla.action_date >= CURDATE()) as actions_today,
        
        (SELECT COUNT(*) 
         FROM team_leader_view_logs tvl 
         JOIN team_leaders tl ON tvl.leader_id = tl.leader_id 
         WHERE tl.admin_id = ? AND tl.is_active = 1 AND tvl.timestamp >= CURDATE()) as data_accesses_today
";

$summary_stmt = $conn->prepare($summary_sql);
if ($summary_stmt === false) {
    error_log("Summary SQL Error: " . $conn->error);
    $summary = [
        'total_team_leaders' => 0,
        'active_today' => 0,
        'active_this_week' => 0,
        'successful_logins_today' => 0,
        'failed_logins_today' => 0,
        'actions_today' => 0,
        'data_accesses_today' => 0
    ];
} else {
    $summary_stmt->bind_param("sssssss", $adminId, $adminId, $adminId, $adminId, $adminId, $adminId, $adminId);
    $summary_stmt->execute();
    $summary = $summary_stmt->get_result()->fetch_assoc();
    $summary_stmt->close();
}

// Individual team leader performance - Fixed to avoid Cartesian product
$individual_performance_sql = "
    SELECT 
        tl.leader_id,
        tl.leader_name,
        c.caller_name,
        COUNT(tla.id) as total_actions,
        COUNT(CASE WHEN tla.new_disposition = 'Interested - Proceed to Payment' THEN 1 END) as payment_conversions,
        COUNT(CASE WHEN tla.new_disposition IN ('Interested - Proceed to Payment', 'Need More Information', 'Call Back Later') THEN 1 END) as positive_outcomes,
        COUNT(CASE WHEN tla.new_disposition = 'Not Interested' THEN 1 END) as not_interested,
        ROUND((COUNT(CASE WHEN tla.new_disposition = 'Interested - Proceed to Payment' THEN 1 END) / NULLIF(COUNT(tla.id), 0)) * 100, 1) as conversion_rate,
        ROUND((COUNT(CASE WHEN tla.new_disposition IN ('Interested - Proceed to Payment', 'Need More Information', 'Call Back Later') THEN 1 END) / NULLIF(COUNT(tla.id), 0)) * 100, 1) as positive_rate,
        COUNT(DISTINCT DATE(tla.action_date)) as active_days,
        MAX(tla.action_date) as last_activity,
        (SELECT COUNT(DISTINCT DATE(tll.login_time)) 
         FROM team_leader_logins tll 
         WHERE tll.leader_id = tl.leader_id AND tll.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as login_days_week
    FROM team_leaders tl
    LEFT JOIN callers c ON tl.finqy_id = c.finqy_id
    LEFT JOIN team_leader_actions tla ON tl.leader_id = tla.leader_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
    WHERE tl.admin_id = ? AND tl.is_active = 1
    " . ($selected_rm ? "AND tl.leader_id = '$selected_rm'" : "") . "
    GROUP BY tl.leader_id, tl.leader_name, c.caller_name
    ORDER BY conversion_rate DESC, total_actions DESC
";

$perf_stmt = $conn->prepare($individual_performance_sql);
if ($perf_stmt === false) {
    error_log("Individual Performance SQL Error: " . $conn->error);
    $individual_performance = [];
} else {
    $range = $date_range === 'all' ? 365 : (int)$date_range;
    $perf_stmt->bind_param("is", $range, $adminId);
    $perf_stmt->execute();
    $individual_performance = $perf_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $perf_stmt->close();
}

// Disposition trends over time
$trends_sql = "
    SELECT 
        DATE(tla.action_date) as action_date,
        tla.new_disposition,
        COUNT(*) as count
    FROM team_leader_actions tla
    JOIN team_leaders tl ON tla.leader_id = tl.leader_id
    $where_clause
    GROUP BY DATE(tla.action_date), tla.new_disposition
    ORDER BY action_date DESC
    LIMIT 100
";

$trends_stmt = $conn->prepare($trends_sql);
if ($trends_stmt === false) {
    error_log("Trends SQL Error: " . $conn->error);
    $trends_data = [];
} else {
    if ($param_types) $trends_stmt->bind_param($param_types, ...$params);
    $trends_stmt->execute();
    $trends_data = $trends_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trends_stmt->close();
}

// Recent detailed activities - Fixed with proper column names and LEFT JOIN
$activities_sql = "
    SELECT 
        tla.action_date,
        tl.leader_name,
        COALESCE(fcl.name, 'Unknown Customer') as customer_name,
        COALESCE(fcl.mobile_no, 'No Contact') as mobile_number,
        tla.original_disposition,
        tla.new_disposition,
        tla.remarks,
        tla.ip_address,
        tla.lead_id
    FROM team_leader_actions tla
    JOIN team_leaders tl ON tla.leader_id = tl.leader_id
    LEFT JOIN final_call_logs fcl ON tla.lead_id = fcl.id
    $where_clause
    ORDER BY tla.action_date DESC
    LIMIT 50
";

$activities_stmt = $conn->prepare($activities_sql);
if ($activities_stmt === false) {
    error_log("Activities SQL Prepare Error: " . $conn->error);
    error_log("Activities SQL Query: " . $activities_sql);
    $recent_activities = []; // Fallback to empty array
} else {
    if ($param_types) $activities_stmt->bind_param($param_types, ...$params);
    
    if (!$activities_stmt->execute()) {
        error_log("Activities SQL Execute Error: " . $activities_stmt->error);
        $recent_activities = [];
    } else {
        $result = $activities_stmt->get_result();
        if ($result === false) {
            error_log("Activities SQL Result Error: " . $activities_stmt->error);
            $recent_activities = [];
        } else {
            $recent_activities = $result->fetch_all(MYSQLI_ASSOC);
            error_log("Activities Query returned " . count($recent_activities) . " records for admin: " . $adminId);
        }
    }
    $activities_stmt->close();
}

// Performance benchmarks - FIXED: Only compare Team Leaders with other Team Leaders
$benchmarks_sql = "
    SELECT 
        ROUND(AVG(CASE WHEN total_actions > 0 THEN (payment_conversions / total_actions) * 100 ELSE 0 END), 1) as avg_conversion_rate,
        ROUND(AVG(CASE WHEN total_actions > 0 THEN total_actions ELSE 0 END), 0) as avg_actions,
        MAX(CASE WHEN total_actions > 0 THEN (payment_conversions / total_actions) * 100 ELSE 0 END) as best_conversion_rate,
        MAX(total_actions) as max_actions,
        COUNT(CASE WHEN total_actions > 0 THEN 1 END) as active_team_leaders_count
    FROM (
        SELECT 
            tl.leader_id,
            COUNT(tla.id) as total_actions,
            COUNT(CASE WHEN tla.new_disposition = 'Interested - Proceed to Payment' THEN 1 END) as payment_conversions
        FROM team_leaders tl
        LEFT JOIN team_leader_actions tla ON tl.leader_id = tla.leader_id AND tla.action_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
        WHERE tl.admin_id = ? AND tl.is_active = 1
        GROUP BY tl.leader_id
    ) as perf_data
    WHERE total_actions > 0
";

$bench_stmt = $conn->prepare($benchmarks_sql);
if ($bench_stmt === false) {
    error_log("Benchmarks SQL Error: " . $conn->error);
    $benchmarks = ['avg_conversion_rate' => 0, 'avg_actions' => 0, 'best_conversion_rate' => 0, 'max_actions' => 0, 'active_team_leaders_count' => 0];
} else {
    $bench_stmt->bind_param("is", $range, $adminId);
    $bench_stmt->execute();
    $benchmarks = $bench_stmt->get_result()->fetch_assoc();
    $bench_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relationship Manager Analytics - <?= htmlspecialchars($_SESSION['admin_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 12px 30px rgba(0,0,0,0.15); 
        }
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .performance-row {
            transition: all 0.2s ease;
        }
        .performance-row:hover {
            background-color: #f8f9fa;
            transform: translateX(2px);
        }
        .conversion-meter {
            width: 80px;
            height: 80px;
            position: relative;
            display: inline-block;
        }
        .conversion-circle {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: conic-gradient(from 0deg, #28a745 0%, #28a745 var(--percentage), #e9ecef var(--percentage), #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .conversion-inner {
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }
        .activity-card {
            background: linear-gradient(135deg, #36d1dc 0%, #5b86e5 100%);
            color: white;
        }
        .benchmark-indicator {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            position: relative;
        }
        .benchmark-bar {
            height: 100%;
            border-radius: 4px;
            position: relative;
        }
        .benchmark-marker {
            position: absolute;
            top: -2px;
            width: 2px;
            height: 12px;
            background: #dc3545;
        }
        .performance-badge {
            font-size: 0.85rem;
            padding: 0.4rem 0.6rem;
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
                        <i class="bi bi-people-fill me-2 text-primary"></i>Relationship Manager Analytics
                        <small class="text-muted">Your Team Performance</small>
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="?view=overview<?= $selected_rm ? '&tl_id=' . $selected_rm : '' ?><?= $date_range !== '30' ? '&date_range=' . $date_range : '' ?>" 
                               class="btn btn-<?= $performance_view === 'overview' ? 'primary' : 'outline-primary' ?> btn-sm">Overview</a>
                            <a href="?view=detailed<?= $selected_rm ? '&tl_id=' . $selected_rm : '' ?><?= $date_range !== '30' ? '&date_range=' . $date_range : '' ?>" 
                               class="btn btn-<?= $performance_view === 'detailed' ? 'primary' : 'outline-primary' ?> btn-sm">Detailed</a>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Your RMs</h6>
                                        <h2 class="mb-0"><?= $summary['total_team_leaders'] ?></h2>
                                        <small><?= $summary['active_today'] ?> active today</small>
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
                                        <h6 class="card-title text-white-50">Actions Today</h6>
                                        <h2 class="mb-0"><?= $summary['actions_today'] ?></h2>
                                        <small><?= $summary['active_this_week'] ?> active this week</small>
                                    </div>
                                    <i class="bi bi-lightning-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Login Success</h6>
                                        <h2 class="mb-0 text-success"><?= $summary['successful_logins_today'] ?></h2>
                                        <small class="text-warning"><?= $summary['failed_logins_today'] ?> failed</small>
                                    </div>
                                    <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title text-white-50">Data Access</h6>
                                        <h2 class="mb-0"><?= $summary['data_accesses_today'] ?></h2>
                                        <small>secure unmaskings</small>
                                    </div>
                                    <i class="bi bi-eye-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <h6 class="card-title text-white"><i class="bi bi-funnel-fill me-2"></i>Filter & Analyze</h6>
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="view" value="<?= htmlspecialchars($performance_view) ?>">
                            <div class="col-md-2">
                                <label class="form-label text-white-50">Date Range</label>
                                <select name="date_range" class="form-select">
                                    <option value="7" <?= $date_range === '7' ? 'selected' : '' ?>>Last 7 days</option>
                                    <option value="30" <?= $date_range === '30' ? 'selected' : '' ?>>Last 30 days</option>
                                    <option value="90" <?= $date_range === '90' ? 'selected' : '' ?>>Last 90 days</option>
                                    <option value="all" <?= $date_range === 'all' ? 'selected' : '' ?>>All time</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-white-50">Relationship Manager</label>
                                <select name="tl_id" class="form-select">
                                    <option value="">All RMs</option>
                                    <?php foreach ($team_leaders as $tl): ?>
                                        <option value="<?= $tl['leader_id'] ?>" <?= $selected_rm === $tl['leader_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tl['leader_name']) ?> <?= $tl['caller_name'] ? '(' . htmlspecialchars($tl['caller_name']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-white-50">Disposition</label>
                                <select name="disposition" class="form-select">
                                    <option value="">All Dispositions</option>
                                    <?php foreach ($available_dispositions as $disp): ?>
                                        <option value="<?= $disp['new_disposition'] ?>" <?= $disposition_filter === $disp['new_disposition'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($disp['new_disposition']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-light me-2">
                                    <i class="bi bi-search"></i> Apply
                                </button>
                                <a href="admin_tl_analytics.php" class="btn btn-outline-light">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($performance_view === 'overview'): ?>
                    <!-- Team Performance Overview -->
                    <div class="row g-4 mb-4">
                        <!-- Individual Performance Cards -->
                        <div class="col-12">
                            <div class="card stat-card">
                                <div class="card-header bg-white border-0">
                                    <h5 class="mb-0">
                                        <i class="bi bi-graph-up-arrow text-success me-2"></i>Team Performance Overview
                                        <small class="text-muted">Last <?= $date_range === 'all' ? 'All Time' : $date_range . ' days' ?></small>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($individual_performance)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-people display-4 text-muted opacity-25"></i>
                                            <p class="text-muted mt-3">No team leader performance data available for the selected period.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="row g-4">
                                            <?php foreach ($individual_performance as $tl): ?>
                                                <div class="col-lg-6 col-xl-4">
                                                    <div class="card performance-row h-100">
                                                        <div class="card-body">
                                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                                <div>
                                                                    <h6 class="card-title mb-1"><?= htmlspecialchars($tl['leader_name']) ?></h6>
                                                                    <small class="text-muted"><?= htmlspecialchars($tl['caller_name'] ?? 'No caller assigned') ?></small>
                                                                </div>
                                                                <div class="conversion-meter" style="--percentage: <?= $tl['conversion_rate'] ?>%">
                                                                    <div class="conversion-circle">
                                                                        <div class="conversion-inner">
                                                                            <?= $tl['conversion_rate'] ?>%
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row g-2 mb-3">
                                                                <div class="col-4">
                                                                    <div class="text-center">
                                                                        <div class="h5 mb-0 text-primary"><?= $tl['total_actions'] ?></div>
                                                                        <small class="text-muted">Actions</small>
                                                                    </div>
                                                                </div>
                                                                <div class="col-4">
                                                                    <div class="text-center">
                                                                        <div class="h5 mb-0 text-success"><?= $tl['payment_conversions'] ?></div>
                                                                        <small class="text-muted">Conversions</small>
                                                                    </div>
                                                                </div>
                                                                <div class="col-4">
                                                                    <div class="text-center">
                                                                        <div class="h5 mb-0 text-info"><?= $tl['positive_outcomes'] ?></div>
                                                                        <small class="text-muted">Positive</small>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                                <span class="performance-badge badge <?= $tl['conversion_rate'] >= ($benchmarks['avg_conversion_rate'] ?? 0) ? 'bg-success' : 'bg-warning' ?>">
                                                                    <?= $tl['conversion_rate'] >= ($benchmarks['avg_conversion_rate'] ?? 0) ? 'Above Average' : 'Below Average' ?>
                                                                </span>
                                                                <small class="text-muted">
                                                                    <?= $tl['active_days'] ?> active days
                                                                </small>
                                                            </div>

                                                            <div class="benchmark-indicator">
                                                                <div class="benchmark-bar bg-<?= $tl['conversion_rate'] >= 20 ? 'success' : ($tl['conversion_rate'] >= 10 ? 'warning' : 'danger') ?>" 
                                                                     style="width: <?= min(100, $tl['conversion_rate'] * 5) ?>%"></div>
                                                                <?php if ($benchmarks['avg_conversion_rate']): ?>
                                                                    <div class="benchmark-marker" style="left: <?= min(100, $benchmarks['avg_conversion_rate'] * 5) ?>%"></div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <small class="text-muted">Performance vs. Team Leader average</small>

                                                            <?php if ($tl['last_activity']): ?>
                                                                <div class="mt-2">
                                                                    <small class="text-muted">
                                                                        <i class="bi bi-clock me-1"></i>Last activity: <?= date('M d, H:i', strtotime($tl['last_activity'])) ?>
                                                                    </small>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <!-- Team Benchmarks -->
                                        <?php if ($benchmarks['avg_conversion_rate']): ?>
                                            <div class="alert alert-info mt-4" role="alert">
                                                <div class="row text-center">
                                                    <div class="col-md-3">
                                                        <strong>Team Leader Average Conversion</strong>
                                                        <div class="h4 text-primary"><?= $benchmarks['avg_conversion_rate'] ?>%</div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <strong>Best Team Leader</strong>
                                                        <div class="h4 text-success"><?= $benchmarks['best_conversion_rate'] ?>%</div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <strong>Average Actions</strong>
                                                        <div class="h4 text-info"><?= $benchmarks['avg_actions'] ?></div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <strong>Top Actions</strong>
                                                        <div class="h4 text-warning"><?= $benchmarks['max_actions'] ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Detailed Analytics View -->
                    <div class="row g-4">
                        <!-- Recent Activities -->
                        <div class="col-12">
                            <div class="card activity-card">
                                <div class="card-header border-0">
                                    <h5 class="mb-0 text-white">
                                        <i class="bi bi-activity me-2"></i>Recent RM Activities
                                        <span class="badge bg-white text-dark ms-2"><?= count($recent_activities) ?></span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_activities)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-activity display-4 text-white opacity-25"></i>
                                            <p class="text-white mt-3">No recent activities found for the selected criteria.</p>
                                            <div class="alert alert-info mt-3" role="alert">
                                                <strong>Applied Filters:</strong><br>
                                                • Date Range: <?= $date_range === 'all' ? 'All time' : 'Last ' . $date_range . ' days' ?><br>
                                                • RM: <?= $selected_rm ? (array_search($selected_rm, array_column($team_leaders, 'leader_id')) !== false ? htmlspecialchars($team_leaders[array_search($selected_rm, array_column($team_leaders, 'leader_id'))]['leader_name']) : 'Selected RM') : 'All RMs' ?><br>
                                                • Disposition: <?= $disposition_filter ? htmlspecialchars($disposition_filter) : 'All Dispositions' ?>
                                            </div>
                                            <a href="admin_tl_analytics.php?view=detailed" class="btn btn-outline-light btn-sm">
                                                <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Date & Time</th>
                                                        <th>RM</th>
                                                        <th>Customer</th>
                                                        <th>Contact</th>
                                                        <th>Original → New</th>
                                                        <th>Remarks</th>
                                                        <th>IP</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_activities as $activity): ?>
                                                        <tr>
                                                            <td>
                                                                <div><?= date('M d, Y', strtotime($activity['action_date'])) ?></div>
                                                                <small class="text-muted"><?= date('H:i:s', strtotime($activity['action_date'])) ?></small>
                                                            </td>
                                                            <td>
                                                                <strong><?= htmlspecialchars($activity['leader_name']) ?></strong>
                                                            </td>
                                                            <td>
                                                                <?= htmlspecialchars($activity['customer_name']) ?>
                                                            </td>
                                                            <td>
                                                                <code class="text-light"><?= $activity['mobile_number'] && $activity['mobile_number'] !== 'No Contact' && strlen($activity['mobile_number']) >= 4 ? htmlspecialchars(substr($activity['mobile_number'], 0, 2) . 'XXXXXX' . substr($activity['mobile_number'], -2)) : htmlspecialchars($activity['mobile_number']) ?></code>
                                                            </td>
                                                            <td>
                                                                <small class="text-muted"><?= htmlspecialchars($activity['original_disposition']) ?></small>
                                                                <br>
                                                                <span class="badge bg-<?= strpos($activity['new_disposition'], 'Payment') !== false ? 'success' : (strpos($activity['new_disposition'], 'Not') !== false ? 'danger' : 'info') ?>">
                                                                    <?= htmlspecialchars($activity['new_disposition']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <small><?= htmlspecialchars(substr($activity['remarks'] ?? 'No remarks', 0, 50)) ?></small>
                                                            </td>
                                                            <td>
                                                                <code class="text-light"><?= htmlspecialchars($activity['ip_address']) ?></code>
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
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>