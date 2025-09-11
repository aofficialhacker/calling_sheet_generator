<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Calculate follow-ups due today and overdue
$followup_query = "
    SELECT 
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE() THEN 1 END) as followups_due_today,
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE() THEN 1 END) as overdue_followups,
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as upcoming_followups
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    WHERE fb.admin_id = ? 
    AND fcl.disposition IS NOT NULL 
    AND fcl.follow_day IS NOT NULL 
    AND fcl.follow_day > 0
    AND fcl.processed_at IS NOT NULL
";

$followup_stmt = $conn->prepare($followup_query);
$followup_stmt->bind_param("s", $adminId);
$followup_stmt->execute();
$followup_data = $followup_stmt->get_result()->fetch_assoc();
$followups_due_today = $followup_data['followups_due_today'];
$overdue_followups = $followup_data['overdue_followups'];
$upcoming_followups = $followup_data['upcoming_followups'];
$followup_stmt->close();

// Get filter parameters
$caller_filter = $_GET['caller'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Build WHERE clauses based on filters
$where_conditions = [
    "fb.admin_id = ?",
    // Only include records that have been processed by callers (have disposition or connectivity marked)
    "((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))"
];
$params = [$adminId];
$types = 's';

if ($caller_filter) {
    $where_conditions[] = "fcl.finqy_id = ?";
    $params[] = $caller_filter;
    $types .= 's';
}

// Add date range filter
if ($from_date && $to_date) {
    $where_conditions[] = "DATE(fcl.processed_at) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= 'ss';
}

$where_clause = "WHERE " . implode(' AND ', $where_conditions);

// Fetch team performance metrics
$team_query = "
    SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN dc.category = 'connected' THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    LEFT JOIN disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    $where_clause
";

$stmt = $conn->prepare($team_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$team_metrics = $stmt->get_result()->fetch_assoc();

// Calculate rates
$connected_rate = $team_metrics['total_calls'] > 0 ? 
    round(($team_metrics['connected_calls'] / $team_metrics['total_calls']) * 100, 2) : 0;
$conversion_rate = $team_metrics['total_calls'] > 0 ? 
    round(($team_metrics['conversions'] / $team_metrics['total_calls']) * 100, 2) : 0;

// First get total count for percentage calculation
$total_count_query = "
    SELECT COUNT(*) as total_count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause AND fcl.processed_at IS NOT NULL
";

$stmt = $conn->prepare($total_count_query);
if ($stmt === false) {
    die("Error preparing total count query: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_count = $stmt->get_result()->fetch_assoc()['total_count'];
$stmt->close();

// Fetch disposition breakdown for team
$disposition_query = "
    SELECT 
        disposition, 
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause AND fcl.processed_at IS NOT NULL AND disposition IS NOT NULL AND disposition != ''
    GROUP BY disposition 
    ORDER BY count DESC
";

$stmt = $conn->prepare($disposition_query);
if ($stmt === false) {
    die("Error preparing disposition query: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$disposition_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate percentages manually
$disposition_breakdown = [];
foreach ($disposition_results as $row) {
    $percentage = $total_count > 0 ? round(($row['count'] * 100.0) / $total_count, 2) : 0;
    $disposition_breakdown[] = [
        'disposition' => $row['disposition'],
        'count' => $row['count'],
        'percentage' => $percentage
    ];
}

// Fetch connectivity breakdown (Connected vs Non-connected) for admin team
$connectivity_query = "
    SELECT 
        CASE 
            WHEN dc.category = 'connected' THEN 'Connected' 
            ELSE 'Not Connected'
        END as connectivity_status,
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    LEFT JOIN disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    $where_clause
    GROUP BY connectivity_status 
    ORDER BY count DESC
";

$stmt = $conn->prepare($connectivity_query);
if ($stmt === false) {
    die("Error preparing connectivity query: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$connectivity_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch disposition breakdown for connected calls
$connected_disposition_query = "
    SELECT 
        COALESCE(disposition, 'No Disposition') as disposition, 
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    LEFT JOIN disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    $where_clause AND dc.category = 'connected'
    GROUP BY COALESCE(disposition, 'No Disposition')
    HAVING count > 0
    ORDER BY count DESC
";

$stmt = $conn->prepare($connected_disposition_query);
if ($stmt === false) {
    die("Error preparing connected disposition query: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$connected_disposition_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch disposition breakdown for non-connected calls
$not_connected_disposition_query = "
    SELECT 
        COALESCE(disposition, 'No Disposition') as disposition, 
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    LEFT JOIN disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    $where_clause AND (dc.category != 'connected' OR dc.category IS NULL)
    GROUP BY COALESCE(disposition, 'No Disposition')
    HAVING count > 0
    ORDER BY count DESC
";

$stmt = $conn->prepare($not_connected_disposition_query);
if ($stmt === false) {
    die("Error preparing not connected disposition query: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$not_connected_disposition_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch telecaller performance (leaderboard)
$telecaller_query = "
    SELECT 
        c.caller_name,
        c.finqy_id,
        COUNT(fcl.id) as calls_made,
        SUM(CASE WHEN dc.category = 'connected' THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN dc.category = 'connected' THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as connected_rate,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM callers c
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    JOIN final_call_logs fcl ON c.finqy_id = fcl.finqy_id
    JOIN file_batches fb ON fcl.batch_id = fb.id
    LEFT JOIN disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    $where_clause
    GROUP BY c.finqy_id, c.caller_name
    ORDER BY conversion_rate DESC, conversions DESC
";

$stmt = $conn->prepare($telecaller_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$telecaller_performance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get top and bottom performers
$top_performers = array_slice($telecaller_performance, 0, 3);
$bottom_performers = array_slice(array_reverse($telecaller_performance), 0, 3);

// Fetch time slot analysis for team
$slot_query = "
    SELECT 
        slot,
        COUNT(*) as total_calls,
        SUM(CASE WHEN dc.category = 'connected' THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as conversion_rate
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    LEFT JOIN disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    $where_clause AND slot IS NOT NULL
    GROUP BY slot 
    ORDER BY conversion_rate DESC, slot
";

$stmt = $conn->prepare($slot_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$slot_analysis = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch unit insights for this admin
$unit_query = "
    SELECT 
        v.vendor_name as unit_name,
        COUNT(fcl.id) as leads_provided,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM vendors v
    JOIN file_batches fb ON v.vendor_id = fb.vendor_id
    JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    WHERE fb.admin_id = ? AND fcl.processed_at IS NOT NULL
    GROUP BY v.vendor_id, v.vendor_name
    HAVING leads_provided > 0
    ORDER BY conversion_rate DESC
";

$stmt = $conn->prepare($unit_query);
$stmt->bind_param('s', $adminId);
$stmt->execute();
$unit_insights = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 7-day trend removed as requested

// Get filter options - only callers assigned to this admin
$callers_query = "
    SELECT c.finqy_id, c.caller_name 
    FROM callers c
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    WHERE acm.admin_id = ? AND c.is_active = 1
    ORDER BY c.caller_name
";

$stmt = $conn->prepare($callers_query);
$stmt->bind_param('s', $adminId);
$stmt->execute();
$callers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Calling Sheet Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-metric {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .card-metric-success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        .card-metric-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .card-metric-info {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .filter-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }
        .leaderboard-card {
            border-left: 4px solid #28a745;
        }
        .performance-card {
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-speedometer2 me-2"></i>Admin Dashboard</h1>
                    <div>
                        <a href="manage_batches.php" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-left me-1"></i>Back to Batches
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Telecaller</label>
                                <select name="caller" class="form-select">
                                    <option value="">All Team Members</option>
                                    <?php foreach($callers as $caller): ?>
                                        <option value="<?= $caller['finqy_id'] ?>" <?= $caller_filter == $caller['finqy_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($caller['caller_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?= $from_date ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?= $to_date ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-funnel me-1"></i>Filter
                                </button>
                                <a href="?" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Follow-up Notifications -->
                <?php if ($followups_due_today > 0 || $overdue_followups > 0): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <!-- Today's Follow-ups -->
                        <?php if ($followups_due_today > 0): ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-calendar-check fs-4 me-3"></i>
                                <div class="flex-grow-1">
                                    <h5 class="alert-heading mb-1">Follow-ups Due Today!</h5>
                                    <p class="mb-2">You have <strong><?= $followups_due_today ?></strong> follow-up<?= $followups_due_today > 1 ? 's' : '' ?> scheduled for today.</p>
                                    <div class="d-flex gap-2">
                                        <a href="admin_follow_up_manager.php?filter=today" class="btn btn-info btn-sm">
                                            <i class="bi bi-list-check me-1"></i>View Today's Follow-ups
                                        </a>
                                        <a href="manage_batches.php" class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-download me-1"></i>Download Sheet
                                        </a>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Overdue Follow-ups -->
                        <?php if ($overdue_followups > 0): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle fs-4 me-3"></i>
                                <div class="flex-grow-1">
                                    <h5 class="alert-heading mb-1">Overdue Follow-ups!</h5>
                                    <p class="mb-2">You have <strong><?= $overdue_followups ?></strong> overdue follow-up<?= $overdue_followups > 1 ? 's' : '' ?> that need immediate attention.</p>
                                    <div class="d-flex gap-2">
                                        <a href="admin_follow_up_manager.php?filter=overdue" class="btn btn-danger btn-sm">
                                            <i class="bi bi-exclamation-circle me-1"></i>View Overdue Follow-ups
                                        </a>
                                        <button class="btn btn-warning btn-sm" onclick="redistributeOverdueFollowups()">
                                            <i class="bi bi-arrow-repeat me-1"></i>Redistribute Overdue
                                        </button>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Follow-up Summary Cards -->
                <?php if ($upcoming_followups > 0 || $followups_due_today > 0 || $overdue_followups > 0): ?>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-triangle-fill text-danger fs-1 mb-2"></i>
                                <div class="h3 text-danger"><?= $overdue_followups ?></div>
                                <div>Overdue Follow-ups</div>
                                <small class="text-muted">Need immediate action</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar-check-fill text-warning fs-1 mb-2"></i>
                                <div class="h3 text-warning"><?= $followups_due_today ?></div>
                                <div>Due Today</div>
                                <small class="text-muted">Scheduled for today</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar3 text-info fs-1 mb-2"></i>
                                <div class="h3 text-info"><?= $upcoming_followups ?></div>
                                <div>This Week</div>
                                <small class="text-muted">Due in next 7 days</small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Team Performance -->
                <h3><i class="bi bi-people-fill me-2"></i>Team Performance</h3>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card card-metric">
                            <div class="card-body text-center">
                                <i class="bi bi-telephone-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= number_format($team_metrics['total_calls']) ?></div>
                                <div>Total Calls Made</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-metric-success">
                            <div class="card-body text-center">
                                <i class="bi bi-check-circle-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= $connected_rate ?>%</div>
                                <div>Connected Calls</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-metric-info">
                            <div class="card-body text-center">
                                <i class="bi bi-star-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= $conversion_rate ?>%</div>
                                <div>Conversion Rate</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-metric-warning">
                            <div class="card-body text-center">
                                <i class="bi bi-trophy-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= number_format($team_metrics['conversions']) ?></div>
                                <div>Total Conversions</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- 1. Connected vs Non-connected Chart -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-pie-chart me-2"></i>Connected vs Non-connected</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="connectivityChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Connected Calls Disposition -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-pie-chart me-2"></i>Connected Disposition</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="connectedDispositionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Non-connected Calls Disposition -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-pie-chart me-2"></i>Non-connected Disposition</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="notConnectedDispositionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Time Slot Performance (Line Chart) -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-graph-up me-2"></i>Best Performing Slots</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="slotPerformanceChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Telecaller Performance Section -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card leaderboard-card">
                            <div class="card-header bg-success text-white">
                                <h5><i class="bi bi-award me-2"></i>Top 3 Performers</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach($top_performers as $idx => $performer): ?>
                                <div class="d-flex align-items-center mb-2">
                                    <span class="badge bg-warning text-dark me-2"><?= $idx + 1 ?></span>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($performer['caller_name']) ?></strong>
                                        <br><small class="text-muted"><?= $performer['calls_made'] ?> calls</small>
                                    </div>
                                    <span class="badge bg-success"><?= $performer['conversion_rate'] ?>%</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="card performance-card">
                            <div class="card-header bg-danger text-white">
                                <h5><i class="bi bi-exclamation-triangle me-2"></i>Bottom 3 (Coaching)</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach($bottom_performers as $performer): ?>
                                <div class="d-flex align-items-center mb-2">
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($performer['caller_name']) ?></strong>
                                        <br><small class="text-muted"><?= $performer['calls_made'] ?> calls</small>
                                    </div>
                                    <span class="badge bg-warning text-dark"><?= $performer['conversion_rate'] ?>%</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unit Insights -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-building me-2"></i>Unit Insights</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Unit</th>
                                                <th>Leads Provided</th>
                                                <th>Conversions</th>
                                                <th>Conversion Rate</th>
                                                <th>Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($unit_insights as $unit): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($unit['unit_name']) ?></td>
                                                <td><?= number_format($unit['leads_provided']) ?></td>
                                                <td><?= number_format($unit['conversions']) ?></td>
                                                <td><?= $unit['conversion_rate'] ?>%</td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" style="width: <?= min($unit['conversion_rate'], 100) ?>%">
                                                            <?= $unit['conversion_rate'] ?>%
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
                    </div>

                    <!-- 7-day trend chart removed as requested -->
                </div>

                <!-- Telecaller Performance Table -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-table me-2"></i>Detailed Telecaller Performance</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Rank</th>
                                                <th>Telecaller</th>
                                                <th>Calls Made</th>
                                                <th>Connected</th>
                                                <th>Connected %</th>
                                                <th>Conversions</th>
                                                <th>Conversion %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($telecaller_performance as $idx => $caller): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?= $idx < 3 ? 'bg-success' : ($idx >= count($telecaller_performance) - 3 ? 'bg-danger' : 'bg-secondary') ?>">
                                                        <?= $idx + 1 ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($caller['caller_name']) ?></td>
                                                <td><?= number_format($caller['calls_made']) ?></td>
                                                <td><?= number_format($caller['connected_calls']) ?></td>
                                                <td><?= $caller['connected_rate'] ?>%</td>
                                                <td><?= number_format($caller['conversions']) ?></td>
                                                <td>
                                                    <span class="badge <?= $caller['conversion_rate'] >= 10 ? 'bg-success' : ($caller['conversion_rate'] >= 5 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                                        <?= $caller['conversion_rate'] ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Helper function to create interactive pie chart
        function createInteractivePieChart(ctx, data, labels, colors, title) {
            if (!data || data.length === 0) {
                // Show "No Data" message
                ctx.font = "16px Arial";
                ctx.fillStyle = "#6c757d";
                ctx.textAlign = "center";
                ctx.fillText("No data available", ctx.canvas.width / 2, ctx.canvas.height / 2);
                return null;
            }

            return new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderColor: colors.map(color => color),
                        borderWidth: 2,
                        hoverBorderWidth: 4,
                        hoverBorderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 10
                                },
                                usePointStyle: true,
                                padding: 8
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: true,
                        mode: 'point'
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
                    }
                }
            });
        }

        // 1. Connected vs Non-connected Chart
        const connectivityCtx = document.getElementById('connectivityChart').getContext('2d');
        const connectivityData = <?= json_encode(array_column($connectivity_breakdown, 'count')) ?>;
        const connectivityLabels = <?= json_encode(array_column($connectivity_breakdown, 'connectivity_status')) ?>;
        const connectivityChart = createInteractivePieChart(
            connectivityCtx, 
            connectivityData, 
            connectivityLabels, 
            ['#28a745', '#dc3545'],
            'Connectivity Breakdown'
        );

        // 2. Connected Calls Disposition Chart
        const connectedDispositionCtx = document.getElementById('connectedDispositionChart').getContext('2d');
        const connectedData = <?= json_encode(array_column($connected_disposition_breakdown, 'count')) ?>;
        const connectedLabels = <?= json_encode(array_column($connected_disposition_breakdown, 'disposition')) ?>;
        const connectedColors = ['#28a745', '#17a2b8', '#6f42c1', '#20c997', '#e83e8c', '#007bff', '#795548', '#009688'];
        const connectedDispositionChart = createInteractivePieChart(
            connectedDispositionCtx, 
            connectedData, 
            connectedLabels, 
            connectedColors,
            'Connected Call Dispositions'
        );

        // 3. Non-connected Calls Disposition Chart
        const notConnectedDispositionCtx = document.getElementById('notConnectedDispositionChart').getContext('2d');
        const notConnectedData = <?= json_encode(array_column($not_connected_disposition_breakdown, 'count')) ?>;
        const notConnectedLabels = <?= json_encode(array_column($not_connected_disposition_breakdown, 'disposition')) ?>;
        const notConnectedColors = ['#dc3545', '#6c757d', '#f8d7da', '#ffeeba', '#ffcccc', '#d6d8db', '#fef2f2', '#ffeeee'];
        const notConnectedDispositionChart = createInteractivePieChart(
            notConnectedDispositionCtx, 
            notConnectedData, 
            notConnectedLabels, 
            notConnectedColors,
            'Non-connected Call Dispositions'
        );

        // 7-day trend chart JavaScript removed as requested

        // Slot Performance Line Chart
        const slotPerformanceCtx = document.getElementById('slotPerformanceChart').getContext('2d');
        const slotData = <?= json_encode($slot_analysis) ?>;
        
        if (slotData && slotData.length > 0) {
            const slotPerformanceChart = new Chart(slotPerformanceCtx, {
                type: 'line',
                data: {
                    labels: slotData.map(slot => `Slot ${slot.slot}`),
                    datasets: [
                        {
                            label: 'Total Calls',
                            data: slotData.map(slot => parseInt(slot.total_calls)),
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        },
                        {
                            label: 'Connected Calls',
                            data: slotData.map(slot => parseInt(slot.connected_calls)),
                            borderColor: 'rgba(75, 192, 192, 1)',
                            backgroundColor: 'rgba(75, 192, 192, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        },
                        {
                            label: 'Conversions',
                            data: slotData.map(slot => parseInt(slot.conversions)),
                            borderColor: 'rgba(255, 99, 132, 1)',
                            backgroundColor: 'rgba(255, 99, 132, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                afterLabel: function(context) {
                                    if (context.datasetIndex === 0) {
                                        const slotIndex = context.dataIndex;
                                        const slot = slotData[slotIndex];
                                        const convRate = slot.conversion_rate;
                                        return `Conversion Rate: ${convRate}%`;
                                    }
                                }
                            }
                        }
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    }
                }
            });
        } else {
            slotPerformanceCtx.font = "16px Arial";
            slotPerformanceCtx.fillStyle = "#6c757d";
            slotPerformanceCtx.textAlign = "center";
            slotPerformanceCtx.fillText("No slot performance data available", slotPerformanceCtx.canvas.width / 2, slotPerformanceCtx.canvas.height / 2);
        }
    </script>

    <script>
        // Function to redistribute overdue follow-ups
        function redistributeOverdueFollowups() {
            if (!confirm('Are you sure you want to redistribute all overdue follow-ups to available telecallers?')) {
                return;
            }
            
            fetch('ajax_redistribute_followups.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'redistribute_overdue'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Successfully redistributed ' + data.count + ' overdue follow-ups!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while redistributing follow-ups.');
            });
        }
        
        // Auto-refresh follow-up notifications every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000); // 5 minutes
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>