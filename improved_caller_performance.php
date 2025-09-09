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
$caller_stmt = $conn->prepare("SELECT caller_name FROM callers WHERE finqy_id = ?");
if ($caller_stmt === false) {
    $caller_name = 'Unknown Caller';
} else {
    $caller_stmt->bind_param("s", $finqy_id);
    $caller_stmt->execute();
    $caller_result = $caller_stmt->get_result()->fetch_assoc();
    $caller_name = $caller_result['caller_name'] ?? 'Unknown Caller';
    $caller_stmt->close();
}

// Get performance statistics
$stats_sql = "
    SELECT 
        COUNT(DISTINCT ch.original_record_id) as total_records_worked,
        COUNT(ch.id) as total_attempts,
        AVG(ch.attempt_number) as avg_attempts_per_record,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as positive_outcomes,
        SUM(CASE WHEN ch.disposition IN ('Not Interested', 'DND', 'Wrong Number') THEN 1 ELSE 0 END) as negative_outcomes,
        SUM(CASE WHEN ch.disposition IN ('Follow Up', 'Busy', 'No Response') THEN 1 ELSE 0 END) as follow_up_required,
        SUM(CASE WHEN ch.attempt_number > 1 THEN 1 ELSE 0 END) as reattempts_made
    FROM call_history ch
    WHERE ch.finqy_id = ?
";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt === false) {
    $stats = ['total_records_worked' => 0, 'total_attempts' => 0, 'positive_outcomes' => 0, 'avg_attempts_per_record' => 0, 'reattempts_made' => 0, 'negative_outcomes' => 0, 'follow_up_required' => 0];
} else {
    $stats_stmt->bind_param("s", $finqy_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
}

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
    FROM call_history ch
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

// Get disposition breakdown
$disposition_breakdown_sql = "
    SELECT 
        ch.disposition,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM call_history WHERE finqy_id = ?)) * 100, 1) as percentage
    FROM call_history ch
    WHERE ch.finqy_id = ?
    GROUP BY ch.disposition
    ORDER BY count DESC
    LIMIT 10
";

$disposition_stmt = $conn->prepare($disposition_breakdown_sql);
if ($disposition_stmt === false) {
    $disposition_breakdown = [];
} else {
    $disposition_stmt->bind_param("ss", $finqy_id, $finqy_id);
    $disposition_stmt->execute();
    $disposition_breakdown = $disposition_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $disposition_stmt->close();
}

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
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-people metric-icon text-primary"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($stats['total_records_worked']) ?></h3>
                        <p class="text-muted mb-0">Records Worked</p>
                        <small class="text-primary">Total leads handled</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-telephone metric-icon text-success"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($stats['total_attempts']) ?></h3>
                        <p class="text-muted mb-0">Total Attempts</p>
                        <small class="text-success">Calls made</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-trophy metric-icon text-warning"></i>
                        <h3 class="mt-2 mb-1"><?= $positive_rate ?>%</h3>
                        <p class="text-muted mb-0">Success Rate</p>
                        <small class="text-warning">Positive outcomes</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card performance-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-arrow-repeat metric-icon text-info"></i>
                        <h3 class="mt-2 mb-1"><?= number_format($stats['reattempts_made']) ?></h3>
                        <p class="text-muted mb-0">Re-attempts</p>
                        <small class="text-info">Follow-up calls</small>
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