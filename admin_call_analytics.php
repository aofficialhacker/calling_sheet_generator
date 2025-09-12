<?php
/**
 * Admin Call Analytics Dashboard
 * Comprehensive analytics for call performance, redistributions, and conversion insights
 */

require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Date range filter
$date_range = $_GET['date_range'] ?? '30';
$selected_caller = $_GET['caller_id'] ?? '';
$selected_product = $_GET['product_code'] ?? '';

// Get admin's callers for filter
$callers_sql = "
    SELECT c.finqy_id, c.caller_name 
    FROM callers c 
    JOIN admin_caller_mapping acm ON CAST(c.finqy_id AS CHAR) = CAST(acm.finqy_id AS CHAR) 
    WHERE acm.admin_id = ? AND c.is_active = 1 
    ORDER BY c.caller_name
";
$callers_stmt = $conn->prepare($callers_sql);
$callers_stmt->bind_param("s", $adminId);
$callers_stmt->execute();
$callers = $callers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$callers_stmt->close();

// Get admin's products for filter
$products_sql = "
    SELECT DISTINCT fb.product_code 
    FROM file_batches fb 
    WHERE fb.admin_id = ? 
    ORDER BY fb.product_code
";
$products_stmt = $conn->prepare($products_sql);
$products_stmt->bind_param("s", $adminId);
$products_stmt->execute();
$products = $products_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$products_stmt->close();

// Build WHERE clauses for filters
$where_conditions = ["fb.admin_id = ?"];
$params = [$adminId];
$param_types = "s";

if ($date_range !== 'all') {
    $where_conditions[] = "ch.attempt_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params[] = (int)$date_range;
    $param_types .= "i";
}

if ($selected_caller) {
    $where_conditions[] = "ch.finqy_id = ?";
    $params[] = $selected_caller;
    $param_types .= "s";
}

if ($selected_product) {
    $where_conditions[] = "fb.product_code = ?";
    $params[] = $selected_product;
    $param_types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Overall Statistics
$overall_stats_sql = "
    SELECT 
        COUNT(DISTINCT ch.original_record_id) as total_records,
        COUNT(ch.id) as total_attempts,
        COUNT(DISTINCT ch.finqy_id) as active_callers,
        AVG(ch.attempt_number) as avg_attempts_per_record,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as positive_outcomes,
        SUM(CASE WHEN ch.disposition IN ('Not Interested', 'DND', 'Wrong Number') THEN 1 ELSE 0 END) as negative_outcomes,
        SUM(CASE WHEN ch.disposition IN ('Follow Up', 'Busy', 'No Response') THEN 1 ELSE 0 END) as follow_up_required,
        SUM(CASE WHEN ch.attempt_number > 1 THEN 1 ELSE 0 END) as total_reattempts
    FROM call_history ch
    JOIN file_batches fb ON CAST(ch.batch_id AS CHAR) = CAST(fb.id AS CHAR)
    $where_clause
";

$overall_stmt = $conn->prepare($overall_stats_sql);
if ($overall_stmt === false) {
    echo "<div class='error'>Database query error: " . htmlspecialchars($conn->error) . "</div>";
    echo "<div class='info'>Make sure the call_history table exists. Run the database migration first.</div>";
    exit;
}
if ($param_types) $overall_stmt->bind_param($param_types, ...$params);
$overall_stmt->execute();
$overall_stats = $overall_stmt->get_result()->fetch_assoc();
$overall_stmt->close();

// Calculate rates
$total_attempts = $overall_stats['total_attempts'] ?: 1;
$positive_rate = round(($overall_stats['positive_outcomes'] / $total_attempts) * 100, 1);
$reattempt_rate = round(($overall_stats['total_reattempts'] / $total_attempts) * 100, 1);

// Caller Performance Comparison
$caller_performance_sql = "
    SELECT 
        ch.finqy_id,
        MAX(c.caller_name) as caller_name,
        COUNT(DISTINCT ch.original_record_id) as records_worked,
        COUNT(ch.id) as total_attempts,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as positive_outcomes,
        ROUND((SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) / COUNT(ch.id)) * 100, 1) as success_rate,
        SUM(CASE WHEN ch.attempt_number > 1 THEN 1 ELSE 0 END) as reattempts_made
    FROM call_history ch
    JOIN file_batches fb ON CAST(ch.batch_id AS CHAR) = CAST(fb.id AS CHAR)
    LEFT JOIN callers c ON CAST(ch.finqy_id AS CHAR) = CAST(c.finqy_id AS CHAR)
    $where_clause
    GROUP BY ch.finqy_id
    ORDER BY success_rate DESC, total_attempts DESC
";

$caller_perf_stmt = $conn->prepare($caller_performance_sql);
if ($caller_perf_stmt === false) {
    echo "<div class='error'>Caller performance query error: " . htmlspecialchars($conn->error) . "</div>";
    $caller_performance = [];
} else {
    if ($param_types) $caller_perf_stmt->bind_param($param_types, ...$params);
    $caller_perf_stmt->execute();
    $caller_performance = $caller_perf_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $caller_perf_stmt->close();
}

// Redistribution Analysis
$redistribution_sql = "
    SELECT 
        fcl.id as record_id,
        fcl.mobile_no,
        fcl.name,
        fcl.redistribution_count,
        MAX(oc.caller_name) as original_caller,
        MAX(lc.caller_name) as current_caller,
        COUNT(ch.id) as total_attempts,
        MAX(ch.attempt_date) as last_attempt,
        GROUP_CONCAT(DISTINCT ch.disposition ORDER BY ch.attempt_date) as disposition_history
    FROM final_call_logs fcl
    JOIN file_batches fb ON CAST(fcl.batch_id AS CHAR) = CAST(fb.id AS CHAR)
    LEFT JOIN callers oc ON CAST(fcl.original_caller_id AS CHAR) = CAST(oc.finqy_id AS CHAR)
    LEFT JOIN callers lc ON CAST(fcl.last_updated_by AS CHAR) = CAST(lc.finqy_id AS CHAR)
    LEFT JOIN call_history ch ON CAST(fcl.id AS CHAR) = CAST(ch.original_record_id AS CHAR)
    WHERE fb.admin_id = ? AND fcl.redistribution_count > 0
    GROUP BY fcl.id, fcl.mobile_no, fcl.name, fcl.redistribution_count
    ORDER BY fcl.redistribution_count DESC, last_attempt DESC
    LIMIT 20
";

$redistribution_stmt = $conn->prepare($redistribution_sql);
if ($redistribution_stmt === false) {
    echo "<div class='error'>Redistribution query error: " . htmlspecialchars($conn->error) . "</div>";
    $redistributions = [];
} else {
    $redistribution_stmt->bind_param("s", $adminId);
    $redistribution_stmt->execute();
    $redistributions = $redistribution_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $redistribution_stmt->close();
}

// Re-attempt Success Analysis
$reattempt_analysis_sql = "
    SELECT 
        ch.attempt_number,
        COUNT(*) as attempts,
        SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) as successful,
        ROUND((SUM(CASE WHEN ch.disposition IN ('Interested', 'Callback', 'Hot Lead') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as success_rate
    FROM call_history ch
    JOIN file_batches fb ON CAST(ch.batch_id AS CHAR) = CAST(fb.id AS CHAR)
    $where_clause
    GROUP BY ch.attempt_number
    ORDER BY ch.attempt_number
    LIMIT 5
";

$reattempt_stmt = $conn->prepare($reattempt_analysis_sql);
if ($reattempt_stmt === false) {
    echo "<div class='error'>Re-attempt query error: " . htmlspecialchars($conn->error) . "</div>";
    $reattempt_analysis = [];
} else {
if ($param_types) $reattempt_stmt->bind_param($param_types, ...$params);
    $reattempt_stmt->execute();
    $reattempt_analysis = $reattempt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reattempt_stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Analytics Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .metric-card { transition: transform 0.2s; border-left: 4px solid; }
        .metric-card:hover { transform: translateY(-2px); }
        .metric-card.success { border-left-color: #28a745; }
        .metric-card.info { border-left-color: #17a2b8; }
        .metric-card.warning { border-left-color: #ffc107; }
        .metric-card.primary { border-left-color: #007bff; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-graph-up me-2"></i>Call Analytics Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-primary" onclick="exportAnalytics()">
                                <i class="bi bi-download me-1"></i>Export Data
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Analytics Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Date Range</label>
                                <select name="date_range" class="form-select">
                                    <option value="7" <?= $date_range == '7' ? 'selected' : '' ?>>Last 7 days</option>
                                    <option value="30" <?= $date_range == '30' ? 'selected' : '' ?>>Last 30 days</option>
                                    <option value="90" <?= $date_range == '90' ? 'selected' : '' ?>>Last 90 days</option>
                                    <option value="all" <?= $date_range == 'all' ? 'selected' : '' ?>>All time</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Caller</label>
                                <select name="caller_id" class="form-select">
                                    <option value="">All Callers</option>
                                    <?php foreach($callers as $caller): ?>
                                    <option value="<?= htmlspecialchars($caller['finqy_id']) ?>" <?= $selected_caller == $caller['finqy_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($caller['caller_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Product</label>
                                <select name="product_code" class="form-select">
                                    <option value="">All Products</option>
                                    <?php foreach($products as $product): ?>
                                    <option value="<?= htmlspecialchars($product['product_code']) ?>" <?= $selected_product == $product['product_code'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($product['product_code']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary d-block w-100">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Overview Metrics -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card metric-card success h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-telephone-fill text-success" style="font-size: 2.5rem;"></i>
                                <h3 class="mt-2"><?= number_format($overall_stats['total_attempts']) ?></h3>
                                <p class="mb-0">Total Attempts</p>
                                <small class="text-muted"><?= number_format($overall_stats['total_records']) ?> unique records</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card metric-card info h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-check-circle-fill text-info" style="font-size: 2.5rem;"></i>
                                <h3 class="mt-2"><?= $positive_rate ?>%</h3>
                                <p class="mb-0">Success Rate</p>
                                <small class="text-muted"><?= number_format($overall_stats['positive_outcomes']) ?> positive outcomes</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card metric-card warning h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-arrow-repeat text-warning" style="font-size: 2.5rem;"></i>
                                <h3 class="mt-2"><?= $reattempt_rate ?>%</h3>
                                <p class="mb-0">Re-attempt Rate</p>
                                <small class="text-muted"><?= number_format($overall_stats['total_reattempts']) ?> follow-up calls</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card metric-card primary h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-people-fill text-primary" style="font-size: 2.5rem;"></i>
                                <h3 class="mt-2"><?= number_format($overall_stats['active_callers']) ?></h3>
                                <p class="mb-0">Active Callers</p>
                                <small class="text-muted">Avg <?= number_format($overall_stats['avg_attempts_per_record'], 1) ?> attempts/record</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Caller Performance -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Caller Performance Comparison</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($caller_performance)): ?>
                                    <div class="alert alert-info">No performance data available for the selected filters.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Caller</th>
                                                    <th>Records</th>
                                                    <th>Attempts</th>
                                                    <th>Success Rate</th>
                                                    <th>Re-attempts</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($caller_performance as $perf): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($perf['caller_name'] ?: $perf['finqy_id']) ?></strong>
                                                    </td>
                                                    <td><?= number_format($perf['records_worked']) ?></td>
                                                    <td><?= number_format($perf['total_attempts']) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $perf['success_rate'] >= 30 ? 'success' : ($perf['success_rate'] >= 15 ? 'warning' : 'danger') ?>">
                                                            <?= $perf['success_rate'] ?>%
                                                        </span>
                                                    </td>
                                                    <td><?= number_format($perf['reattempts_made']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Re-attempt Analysis -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Re-attempt Success Analysis</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($reattempt_analysis)): ?>
                                    <p class="text-muted">No re-attempt data available.</p>
                                <?php else: ?>
                                    <?php foreach($reattempt_analysis as $analysis): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <strong>Attempt <?= $analysis['attempt_number'] ?></strong>
                                            <br>
                                            <small class="text-muted"><?= number_format($analysis['attempts']) ?> attempts</small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?= $analysis['success_rate'] >= 25 ? 'success' : ($analysis['success_rate'] >= 15 ? 'warning' : 'secondary') ?> fs-6">
                                                <?= $analysis['success_rate'] ?>%
                                            </span>
                                            <br>
                                            <small class="text-muted"><?= $analysis['successful'] ?> successful</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <hr>
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="bi bi-lightbulb me-1"></i>
                                            <strong>Insight:</strong> Re-attempts often show improved conversion rates as callers build relationships.
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Redistribution Analysis -->
                <?php if (!empty($redistributions)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-share me-2"></i>Redistribution Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Lead</th>
                                        <th>Redistributions</th>
                                        <th>Original Caller</th>
                                        <th>Current Caller</th>
                                        <th>Total Attempts</th>
                                        <th>Disposition History</th>
                                        <th>Last Activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($redistributions as $redist): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars(substr($redist['mobile_no'], 0, 4)) ?>****</strong>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($redist['name'] ?: 'N/A') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning"><?= $redist['redistribution_count'] ?>x</span>
                                        </td>
                                        <td><?= htmlspecialchars($redist['original_caller'] ?: 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($redist['current_caller'] ?: 'None') ?></td>
                                        <td><?= number_format($redist['total_attempts']) ?></td>
                                        <td>
                                            <small><?= htmlspecialchars($redist['disposition_history'] ?: 'N/A') ?></small>
                                        </td>
                                        <td>
                                            <small><?= $redist['last_attempt'] ? date('M j, Y', strtotime($redist['last_attempt'])) : 'N/A' ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportAnalytics() {
            // Create export URL with current filters
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('export_analytics.php?' + params.toString(), '_blank');
        }
    </script>
</body>
</html>