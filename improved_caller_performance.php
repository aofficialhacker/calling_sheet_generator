<?php
require_once __DIR__ . '/session_manager.php';
SessionManager::start();

if (!isset($_SESSION['finqy_id']) || !isset($_SESSION['caller_name'])) {
    header('Location: caller_panel.php');
    exit();
}

require_once 'db_config.php';

$conn = getDBConnection();
$finqy_id = $_SESSION['finqy_id'];

// Get caller details
$caller_stmt = $conn->prepare("SELECT caller_name FROM lv_callers WHERE finqy_id = ?");
if ($caller_stmt === false) {
    $caller_name = 'Unknown Caller';
} else {
    $caller_stmt->bind_param("s", $finqy_id);
    $caller_stmt->execute();
    $caller_result = $caller_stmt->get_result()->fetch_assoc();
    $caller_name = $caller_result['caller_name'] ?? 'Unknown Caller';
    $caller_stmt->close();
}

// Get basic performance statistics from lv_final_call_logs (current work)
$basic_stats_sql = "
    SELECT 
        COUNT(*) as total_records_worked,
        COUNT(*) as total_attempts,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info', 'Hot Lead') THEN 1 ELSE 0 END) as positive_outcomes,
        SUM(CASE WHEN disposition IN ('Not Interested', 'DND', 'Wrong Number', 'Invalid') THEN 1 ELSE 0 END) as negative_outcomes,
        SUM(CASE WHEN disposition IN ('Follow Up', 'Busy', 'No Response', 'Callback') THEN 1 ELSE 0 END) as follow_up_required,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls
    FROM lv_final_call_logs 
    WHERE finqy_id = ? 
    AND ((disposition IS NOT NULL AND disposition != '') OR (connectivity IS NOT NULL AND connectivity != ''))
";

$basic_stats_stmt = $conn->prepare($basic_stats_sql);
if ($basic_stats_stmt === false) {
    $basic_stats = ['total_records_worked' => 0, 'total_attempts' => 0, 'positive_outcomes' => 0, 'negative_outcomes' => 0, 'follow_up_required' => 0, 'connected_calls' => 0];
} else {
    $basic_stats_stmt->bind_param("s", $finqy_id);
    $basic_stats_stmt->execute();
    $basic_stats = $basic_stats_stmt->get_result()->fetch_assoc();
    $basic_stats_stmt->close();
}

// Get re-attempt performance statistics from lv_call_history (follow-up attempts)
$reattempt_stats_sql = "
    SELECT 
        COUNT(DISTINCT ch.original_record_id) as reattempt_records,
        COUNT(ch.id) as reattempt_attempts,
        AVG(ch.attempt_number) as avg_attempts_per_record,
        SUM(CASE WHEN ch.attempt_number > 1 THEN 1 ELSE 0 END) as reattempts_made,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as reattempt_positive
    FROM lv_call_history ch
    WHERE ch.finqy_id = ?
";

$reattempt_stats_stmt = $conn->prepare($reattempt_stats_sql);
if ($reattempt_stats_stmt === false) {
    $reattempt_stats = ['reattempt_records' => 0, 'reattempt_attempts' => 0, 'avg_attempts_per_record' => 0, 'reattempts_made' => 0, 'reattempt_positive' => 0];
} else {
    $reattempt_stats_stmt->bind_param("s", $finqy_id);
    $reattempt_stats_stmt->execute();
    $reattempt_stats = $reattempt_stats_stmt->get_result()->fetch_assoc();
    $reattempt_stats_stmt->close();
}

// Combine stats for display
$stats = [
    'total_records_worked' => $basic_stats['total_records_worked'] + $reattempt_stats['reattempt_records'],
    'total_attempts' => $basic_stats['total_attempts'] + $reattempt_stats['reattempt_attempts'],
    'positive_outcomes' => $basic_stats['positive_outcomes'] + $reattempt_stats['reattempt_positive'],
    'negative_outcomes' => $basic_stats['negative_outcomes'],
    'follow_up_required' => $basic_stats['follow_up_required'],
    'reattempts_made' => $reattempt_stats['reattempts_made'],
    'connected_calls' => $basic_stats['connected_calls'],
    'avg_attempts_per_record' => $reattempt_stats['avg_attempts_per_record'] ?: 1
];

// Calculate conversion rates
$total_attempts = $stats['total_attempts'] ?: 1;
$positive_rate = round(($stats['positive_outcomes'] / $total_attempts) * 100, 1);
$negative_rate = round(($stats['negative_outcomes'] / $total_attempts) * 100, 1);
$follow_up_rate = round(($stats['follow_up_required'] / $total_attempts) * 100, 1);

// Get re-attempt performance (comparing 1st vs 2nd+ attempts)
$reattempt_performance_sql = "
    SELECT 
        ch.attempt_number,
        COUNT(*) as attempts,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as successful,
        ROUND((SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as success_rate
    FROM lv_call_history ch
    WHERE ch.finqy_id = ?
    GROUP BY ch.attempt_number
    ORDER BY ch.attempt_number
    LIMIT 5
";

$reattempt_stmt = $conn->prepare($reattempt_performance_sql);
if ($reattempt_stmt === false) {
    $reattempt_performance = [];
} else {
    $reattempt_stmt->bind_param("s", $finqy_id);
    $reattempt_stmt->execute();
    $reattempt_performance = $reattempt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reattempt_stmt->close();
}

// Get disposition breakdown from both tables
$basic_disposition_sql = "
    SELECT 
        disposition,
        COUNT(*) as count,
        'lv_final_call_logs' as source
    FROM lv_final_call_logs 
    WHERE finqy_id = ? 
    AND disposition IS NOT NULL AND disposition != ''
    GROUP BY disposition
";

$reattempt_disposition_sql = "
    SELECT 
        disposition,
        COUNT(*) as count,
        'lv_call_history' as source
    FROM lv_call_history 
    WHERE finqy_id = ?
    AND disposition IS NOT NULL AND disposition != ''
    GROUP BY disposition
";

$disposition_breakdown = [];

// Get basic dispositions
$basic_disp_stmt = $conn->prepare($basic_disposition_sql);
if ($basic_disp_stmt !== false) {
    $basic_disp_stmt->bind_param("s", $finqy_id);
    $basic_disp_stmt->execute();
    $basic_dispositions = $basic_disp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $basic_disp_stmt->close();
    
    foreach ($basic_dispositions as $disp) {
        $disposition_breakdown[$disp['disposition']] = $disp['count'];
    }
}

// Get reattempt dispositions and add to the breakdown
$reattempt_disp_stmt = $conn->prepare($reattempt_disposition_sql);
if ($reattempt_disp_stmt !== false) {
    $reattempt_disp_stmt->bind_param("s", $finqy_id);
    $reattempt_disp_stmt->execute();
    $reattempt_dispositions = $reattempt_disp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reattempt_disp_stmt->close();
    
    foreach ($reattempt_dispositions as $disp) {
        if (isset($disposition_breakdown[$disp['disposition']])) {
            $disposition_breakdown[$disp['disposition']] += $disp['count'];
        } else {
            $disposition_breakdown[$disp['disposition']] = $disp['count'];
        }
    }
}

// Convert to array format with percentages
$total_dispositions = array_sum($disposition_breakdown);
$disposition_breakdown_formatted = [];
foreach ($disposition_breakdown as $disposition => $count) {
    $percentage = $total_dispositions > 0 ? round(($count / $total_dispositions) * 100, 1) : 0;
    $disposition_breakdown_formatted[] = [
        'disposition' => $disposition,
        'count' => $count,
        'percentage' => $percentage
    ];
}

// Sort by count descending
usort($disposition_breakdown_formatted, function($a, $b) {
    return $b['count'] - $a['count'];
});

$disposition_breakdown = array_slice($disposition_breakdown_formatted, 0, 10);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .performance-card { transition: all 0.3s ease; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .performance-card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .metric-icon { font-size: 2.5rem; opacity: 0.8; }
        .progress-ring { width: 120px; height: 120px; }
        .disposition-item { 
            background: white; 
            border-radius: 8px; 
            padding: 15px; 
            margin-bottom: 10px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        .disposition-item:hover { 
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
            transform: translateX(5px);
        }
        .disposition-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .disposition-progress {
            height: 100%;
            transition: width 0.3s ease;
        }
        .stat-badge { font-size: 0.85rem; padding: 8px 12px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-1"><i class="bi bi-person-circle text-primary me-2"></i>My Performance Dashboard</h1>
                <p class="text-muted mb-0">Welcome back, <strong><?= htmlspecialchars($caller_name) ?></strong>! Here's your call performance overview.</p>
            </div>
            <div>
                <a href="caller_panel.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-2"></i>Back to Panel</a>
                <a href="?action=logout" class="btn btn-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
            </div>
        </div>

        <!-- Key Performance Metrics -->
        <div class="row mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-people metric-icon text-primary"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($basic_stats['total_records_worked']) ?></h3>
                        <p class="text-muted mb-0">Records Worked</p>
                        <small class="text-primary">Current calls</small>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-telephone metric-icon text-success"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($stats['total_attempts']) ?></h3>
                        <p class="text-muted mb-0">Total Attempts</p>
                        <small class="text-success">All calls made</small>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle metric-icon text-info"></i>
                        <h3 class="mt-2 mb-1"><?= $basic_stats['total_attempts'] > 0 ? round(($stats['connected_calls'] / $basic_stats['total_attempts']) * 100, 1) : 0 ?>%</h3>
                        <p class="text-muted mb-0">Connected</p>
                        <small class="text-info"><?= $stats['connected_calls'] ?> calls</small>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-trophy metric-icon text-warning"></i>
                        <h3 class="mt-2 mb-1"><?= $positive_rate ?>%</h3>
                        <p class="text-muted mb-0">Success Rate</p>
                        <small class="text-warning"><?= $stats['positive_outcomes'] ?> positive</small>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-arrow-repeat metric-icon text-secondary"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($reattempt_stats['reattempt_records']) ?></h3>
                        <p class="text-muted mb-0">Follow-up Records</p>
                        <small class="text-secondary">Re-attempted</small>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-clock-history metric-icon text-danger"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($stats['follow_up_required']) ?></h3>
                        <p class="text-muted mb-0">Pending Follow-up</p>
                        <small class="text-danger">Need callback</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Re-attempt Performance Chart -->
            <div class="col-lg-6 mb-4">
                <div class="card performance-card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-bar-chart-steps me-2 text-primary"></i>Performance by Attempt</h5>
                        <small class="text-muted">How your success rate changes with follow-ups</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reattempt_performance)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2">No performance data available yet.</p>
                                <p class="small text-muted">Start making calls to see your performance trends!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach($reattempt_performance as $perf): ?>
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3">
                                    <span class="badge bg-primary rounded-pill" style="width: 60px;">
                                        Attempt <?= $perf['attempt_number'] ?>
                                    </span>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small><strong><?= $perf['attempts'] ?> calls</strong></small>
                                        <small class="text-success"><strong><?= $perf['success_rate'] ?>% success</strong></small>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?= $perf['success_rate'] ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-lightbulb me-2"></i>
                                <strong>Pro Tip:</strong> Higher success rates on follow-up attempts show great lead nurturing skills!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Disposition Breakdown -->
            <div class="col-lg-6 mb-4">
                <div class="card performance-card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-pie-chart me-2 text-success"></i>Call Outcomes</h5>
                        <small class="text-muted">Breakdown of your call dispositions</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($disposition_breakdown)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-graph-up text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2">No disposition data available yet.</p>
                                <p class="small text-muted">Start making calls to see your outcome patterns!</p>
                            </div>
                        <?php else: ?>
                            <?php 
                            $colors = ['success', 'primary', 'warning', 'info', 'secondary', 'danger'];
                            foreach($disposition_breakdown as $index => $disp): 
                                $color = $colors[$index % count($colors)];
                                
                                // Determine outcome type
                                $outcome_class = 'secondary';
                                if (in_array($disp['disposition'], ['Interested', 'Callback', 'Hot Lead'])) {
                                    $outcome_class = 'success';
                                } elseif (in_array($disp['disposition'], ['Not Interested', 'DND', 'Wrong Number'])) {
                                    $outcome_class = 'danger';  
                                } elseif (in_array($disp['disposition'], ['Follow Up', 'Busy', 'No Response'])) {
                                    $outcome_class = 'warning';
                                }
                            ?>
                            <div class="disposition-item">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-<?= $outcome_class ?> me-2"><?= $disp['count'] ?></span>
                                        <strong><?= htmlspecialchars($disp['disposition']) ?></strong>
                                    </div>
                                    <span class="stat-badge badge bg-light text-dark"><?= $disp['percentage'] ?>%</span>
                                </div>
                                <div class="disposition-bar">
                                    <div class="disposition-progress bg-<?= $outcome_class ?>" style="width: <?= $disp['percentage'] ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="mt-3">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="badge bg-success w-100 p-2">
                                            <div><?= $positive_rate ?>%</div>
                                            <small>Positive</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="badge bg-warning w-100 p-2">
                                            <div><?= $follow_up_rate ?>%</div>
                                            <small>Follow-up</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="badge bg-danger w-100 p-2">
                                            <div><?= $negative_rate ?>%</div>
                                            <small>Negative</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Summary -->
        <div class="row">
            <div class="col-12">
                <div class="card performance-card">
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h6 class="text-muted">Average Attempts per Lead</h6>
                                <h4 class="text-primary"><?= round($stats['avg_attempts_per_record'], 1) ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Positive Outcomes</h6>
                                <h4 class="text-success"><?= number_format($stats['positive_outcomes']) ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Follow-ups Required</h6>
                                <h4 class="text-warning"><?= number_format($stats['follow_up_required']) ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Total Re-attempts</h6>
                                <h4 class="text-info"><?= number_format($stats['reattempts_made']) ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>