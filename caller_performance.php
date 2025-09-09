<?php
/**
 * Caller Performance Dashboard
 * Shows individual caller's attempt history and performance metrics
 */

require_once __DIR__ . '/session_manager.php';
SessionManager::start();
require_once 'db_config.php';

// Check if caller is logged in
if (!isset($_SESSION['finqy_id'])) {
    header("Location: caller_login.php");
    exit();
}

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
        SUM(CASE WHEN ch.attempt_number = 1 THEN 1 ELSE 0 END) as original_attempts,
        SUM(CASE WHEN ch.attempt_number > 1 THEN 1 ELSE 0 END) as re_attempts,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as positive_outcomes,
        SUM(CASE WHEN ch.disposition IN ('Not Interested', 'DND', 'Wrong Number') THEN 1 ELSE 0 END) as negative_outcomes,
        SUM(CASE WHEN ch.disposition IN ('Follow Up', 'Busy', 'No Response') THEN 1 ELSE 0 END) as follow_up_required
    FROM call_history ch
    WHERE ch.finqy_id = ?
";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt === false) {
    $stats = ['total_records_worked' => 0, 'total_attempts' => 0, 'positive_outcomes' => 0, 'avg_attempts_per_record' => 0, 'reattempts_made' => 0];
} else {
    $stats_stmt->bind_param("s", $finqy_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
}

// Calculate conversion rates
$total_attempts = $stats['total_attempts'] ?: 1; // Prevent division by zero
$positive_rate = round(($stats['positive_outcomes'] / $total_attempts) * 100, 1);
$negative_rate = round(($stats['negative_outcomes'] / $total_attempts) * 100, 1);
$follow_up_rate = round(($stats['follow_up_required'] / $total_attempts) * 100, 1);

// Get recent activity (last 30 days) - NO CUSTOMER DATA
$recent_activity_sql = "
    SELECT 
        ch.original_record_id,
        ch.attempt_number,
        ch.disposition,
        ch.slot,
        ch.attempt_date,
        fb.product_code,
        CASE 
            WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 'success'
            WHEN ch.disposition IN ('Not Interested', 'DND', 'Wrong Number') THEN 'danger'
            WHEN ch.disposition IN ('Follow Up', 'Busy', 'No Response') THEN 'warning'
            ELSE 'secondary'
        END as outcome_class
    FROM call_history ch
    JOIN file_batches fb ON ch.batch_id COLLATE utf8mb4_unicode_ci = fb.id COLLATE utf8mb4_unicode_ci
    WHERE ch.finqy_id = ?
    AND ch.attempt_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY ch.attempt_date DESC
    LIMIT 50
";

$recent_stmt = $conn->prepare($recent_activity_sql);
if ($recent_stmt === false) {
    echo "<div class='error'>Recent activity query error: " . htmlspecialchars($conn->error) . "</div>";
    $recent_activities = [];
} else {
    $recent_stmt->bind_param("s", $finqy_id);
    $recent_stmt->execute();
    $recent_activities = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
}

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
    <title>Performance Dashboard - <?= htmlspecialchars($caller_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .stats-card { transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-2px); }
        .performance-badge { font-size: 0.9rem; }
        .attempt-badge { font-size: 0.8rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="caller_panel.php">
                <i class="bi bi-graph-up me-2"></i><?= htmlspecialchars($caller_name) ?> - Performance Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="caller_panel.php">
                    <i class="bi bi-arrow-left me-1"></i>Back to Panel
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Performance Overview -->
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="bi bi-speedometer2 me-2"></i>Your Performance Overview</h2>
                <p class="text-muted">Track your calling performance and see improvements over time</p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100 border-primary">
                    <div class="card-body text-center">
                        <i class="bi bi-telephone-fill text-primary" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?= number_format($stats['total_attempts']) ?></h3>
                        <p class="text-muted mb-0">Total Attempts</p>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100 border-info">
                    <div class="card-body text-center">
                        <i class="bi bi-people-fill text-info" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?= number_format($stats['total_records_worked']) ?></h3>
                        <p class="text-muted mb-0">Unique Leads Worked</p>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100 border-success">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?= $positive_rate ?>%</h3>
                        <p class="text-muted mb-0">Success Rate</p>
                        <small class="text-success"><?= $stats['positive_outcomes'] ?> positive outcomes</small>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100 border-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-arrow-repeat text-warning" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?= number_format($stats['re_attempts']) ?></h3>
                        <p class="text-muted mb-0">Re-attempts Made</p>
                        <small class="text-warning">Following up on leads</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Recent Activity -->
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Activity (Last 30 Days)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activities)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>No recent activity found. Start making calls to see your performance data!
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Record ID</th>
                                            <th>Attempt #</th>
                                            <th>Disposition</th>
                                            <th>Slot</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_activities as $activity): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($activity['original_record_id']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($activity['product_code'] ?? 'N/A') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge attempt-badge <?= $activity['attempt_number'] > 1 ? 'bg-warning' : 'bg-primary' ?>">
                                                    <?= $activity['attempt_number'] ?><?= $activity['attempt_number'] > 1 ? ' (Re-attempt)' : '' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge performance-badge bg-<?= $activity['outcome_class'] ?>">
                                                    <?= htmlspecialchars($activity['disposition']) ?>
                                                </span>
                                            </td>
                                            <td><?= $activity['slot'] ?: 'N/A' ?></td>
                                            <td>
                                                <small><?= date('M j, Y H:i', strtotime($activity['attempt_date'])) ?></small>
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

            <!-- Performance Insights -->
            <div class="col-lg-4 mb-4">
                <!-- Re-attempt Performance -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Re-attempt Performance</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reattempt_performance)): ?>
                            <p class="text-muted">No attempt data available yet.</p>
                        <?php else: ?>
                            <?php foreach($reattempt_performance as $perf): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Attempt <?= $perf['attempt_number'] ?>:</span>
                                <div>
                                    <span class="badge bg-primary"><?= $perf['attempts'] ?> calls</span>
                                    <span class="badge bg-success"><?= $perf['success_rate'] ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <hr>
                            <small class="text-muted">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Insight:</strong> Higher success rates on re-attempts show improved lead nurturing skills.
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Disposition Breakdown -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Disposition Breakdown</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($disposition_breakdown)): ?>
                            <p class="text-muted">No disposition data available yet.</p>
                        <?php else: ?>
                            <?php foreach($disposition_breakdown as $disp): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small"><?= htmlspecialchars($disp['disposition']) ?>:</span>
                                <div>
                                    <span class="badge bg-secondary"><?= $disp['count'] ?></span>
                                    <span class="small text-muted"><?= $disp['percentage'] ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Tips -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Performance Tips</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6><i class="bi bi-target me-2"></i>Improve Success Rate</h6>
                                <ul class="small">
                                    <li>Focus on quality over quantity</li>
                                    <li>Personalize your approach</li>
                                    <li>Listen actively to customer needs</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6><i class="bi bi-clock me-2"></i>Optimize Timing</h6>
                                <ul class="small">
                                    <li>Track which time slots work best</li>
                                    <li>Follow up at appropriate intervals</li>
                                    <li>Respect customer preferences</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6><i class="bi bi-graph-up me-2"></i>Follow-up Strategy</h6>
                                <ul class="small">
                                    <li>Re-attempts often have higher success rates</li>
                                    <li>Use different approaches on follow-ups</li>
                                    <li>Keep detailed notes for context</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-light mt-5 py-3">
        <div class="container text-center">
            <small class="text-muted">
                Performance data updates in real-time as you upload your calling results.
            </small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>