<?php
require_once 'db_config.php';
session_start();

// Check if telecaller is logged in (you might need to adjust this based on your auth system)
// For now, assuming we get finqy_id from session or parameter
$finqyId = $_SESSION['finqy_id'] ?? $_GET['finqy_id'] ?? null;

if (!$finqyId) {
    die("Error: Telecaller ID not found. Please login properly.");
}

$conn = getDBConnection();

// Get telecaller information
$caller_query = "SELECT caller_name, mobile_no FROM callers WHERE finqy_id = ?";
$stmt = $conn->prepare($caller_query);
$stmt->bind_param('s', $finqyId);
$stmt->execute();
$caller_info = $stmt->get_result()->fetch_assoc();

if (!$caller_info) {
    die("Error: Telecaller not found.");
}

// Get filter parameters
$date_filter = $_GET['date'] ?? date('Y-m-d');
$month_filter = $_GET['month'] ?? date('Y-m');

// Build WHERE clauses based on filters - only caller-marked data
$where_conditions = [
    "fcl.finqy_id = ?",
    "((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))"
];
$params = [$finqyId];
$types = 's';

if ($date_filter && $date_filter !== date('Y-m-d')) {
    $where_conditions[] = "DATE(fcl.processed_at) = ?";
    $params[] = $date_filter;
    $types .= 's';
} elseif ($month_filter && $month_filter !== date('Y-m')) {
    $where_conditions[] = "DATE_FORMAT(fcl.processed_at, '%Y-%m') = ?";
    $params[] = $month_filter;
    $types .= 's';
}

$where_clause = "WHERE " . implode(' AND ', $where_conditions);

// Fetch today's performance
$today_query = "
    SELECT 
        COUNT(*) as calls_made,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM final_call_logs fcl 
    WHERE fcl.finqy_id = ? AND DATE(fcl.processed_at) = CURDATE() AND fcl.processed_at IS NOT NULL
";

$stmt = $conn->prepare($today_query);
$stmt->bind_param('s', $finqyId);
$stmt->execute();
$today_metrics = $stmt->get_result()->fetch_assoc();

// Calculate today's rates
$today_connected_rate = $today_metrics['calls_made'] > 0 ? 
    round(($today_metrics['connected_calls'] / $today_metrics['calls_made']) * 100, 2) : 0;
$today_conversion_rate = $today_metrics['calls_made'] > 0 ? 
    round(($today_metrics['conversions'] / $today_metrics['calls_made']) * 100, 2) : 0;

// Fetch overall performance based on filters
$overall_query = "
    SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM final_call_logs fcl 
    $where_clause AND fcl.processed_at IS NOT NULL
";

$stmt = $conn->prepare($overall_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$overall_metrics = $stmt->get_result()->fetch_assoc();

// Calculate overall rates
$overall_connected_rate = $overall_metrics['total_calls'] > 0 ? 
    round(($overall_metrics['connected_calls'] / $overall_metrics['total_calls']) * 100, 2) : 0;
$overall_conversion_rate = $overall_metrics['total_calls'] > 0 ? 
    round(($overall_metrics['conversions'] / $overall_metrics['total_calls']) * 100, 2) : 0;

// First get total count for percentage calculation
$total_count_query = "
    SELECT COUNT(*) as total_count
    FROM final_call_logs fcl 
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

// Fetch disposition summary
$disposition_query = "
    SELECT 
        disposition, 
        COUNT(*) as count
    FROM final_call_logs fcl 
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

// Calculate specific disposition percentages for the summary cards
$interested_percentage = 0;
$callback_percentage = 0;
$not_interested_percentage = 0;
$other_percentage = 0;

foreach ($disposition_breakdown as $disp) {
    switch (strtolower($disp['disposition'])) {
        case 'interested':
            $interested_percentage = $disp['percentage'];
            break;
        case 'call back':
            $callback_percentage = $disp['percentage'];
            break;
        case 'not interested':
            $not_interested_percentage = $disp['percentage'];
            break;
        default:
            $other_percentage += $disp['percentage'];
            break;
    }
}

// Fetch time slot analysis
$slot_query = "
    SELECT 
        slot,
        COUNT(*) as total_calls,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as conversion_rate
    FROM final_call_logs fcl 
    $where_clause AND fcl.processed_at IS NOT NULL AND slot IS NOT NULL
    GROUP BY slot 
    ORDER BY slot
";

$stmt = $conn->prepare($slot_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$slot_analysis = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Find best performing slot
$best_slot = null;
$best_conversion_rate = 0;
foreach ($slot_analysis as $slot) {
    if ($slot['conversion_rate'] > $best_conversion_rate && $slot['total_calls'] >= 5) {
        $best_conversion_rate = $slot['conversion_rate'];
        $best_slot = $slot['slot'];
    }
}

// Fetch connectivity breakdown (Connected vs Non-connected) for this caller
$connectivity_query = "
    SELECT 
        CASE 
            WHEN connectivity IN ('Y', 'Yes') THEN 'Connected' 
            WHEN connectivity IN ('N', 'No') THEN 'Not Connected'
            WHEN connectivity IS NULL OR connectivity = '' THEN 'Not Connected'
            ELSE 'Not Connected'
        END as connectivity_status,
        COUNT(*) as count
    FROM final_call_logs fcl 
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
    $where_clause AND connectivity IN ('Y', 'Yes')
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
    $where_clause AND (connectivity IN ('N', 'No') OR connectivity IS NULL OR connectivity = '')
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

// Fetch last 7 days trend
$trend_query = "
    SELECT 
        DATE(fcl.processed_at) as call_date,
        COUNT(*) as total_calls,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM final_call_logs fcl 
    WHERE fcl.finqy_id = ? AND fcl.processed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND fcl.processed_at IS NOT NULL
        AND ((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))
    GROUP BY DATE(fcl.processed_at)
    ORDER BY call_date
";

$stmt = $conn->prepare($trend_query);
$stmt->bind_param('s', $finqyId);
$stmt->execute();
$trend_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get team ranking - find caller's position within their team
$team_ranking_query = "
    SELECT 
        c.finqy_id,
        c.caller_name,
        COUNT(fcl.id) as calls_made,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM callers c
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    JOIN final_call_logs fcl ON c.finqy_id = fcl.finqy_id
    JOIN file_batches fb ON fcl.batch_id = fb.id
    WHERE acm.admin_id = (SELECT admin_id FROM admin_caller_mapping WHERE finqy_id = ?) 
        AND ((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))
        AND fcl.processed_at IS NOT NULL
        AND fcl.processed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY c.finqy_id, c.caller_name
    HAVING calls_made > 0
    ORDER BY conversion_rate DESC, conversions DESC
";

$stmt = $conn->prepare($team_ranking_query);
$stmt->bind_param('s', $finqyId);
$stmt->execute();
$team_ranking_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Find current caller's rank
$caller_rank = 0;
$total_team_members = count($team_ranking_data);
foreach ($team_ranking_data as $index => $member) {
    if ($member['finqy_id'] === $finqyId) {
        $caller_rank = $index + 1;
        break;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telecaller Dashboard - <?= htmlspecialchars($caller_info['caller_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-today {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .card-connected {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        .card-conversion {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }
        .card-interested {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white;
        }
        .card-callback {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #333;
        }
        .card-not-interested {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
            color: #333;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .metric-small {
            font-size: 1.8rem;
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
        .slot-highlight {
            border-left: 4px solid #28a745;
        }
        .performance-insight {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1><i class="bi bi-person-circle me-2"></i>My Dashboard</h1>
                        <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($caller_info['caller_name']) ?>!</p>
                    </div>
                    <div>
                        <a href="javascript:history.back()" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-left me-1"></i>Go Back
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="finqy_id" value="<?= htmlspecialchars($finqyId) ?>">
                            <div class="col-md-4">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control" value="<?= $date_filter ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Month</label>
                                <input type="month" name="month" class="form-control" value="<?= $month_filter ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-funnel me-1"></i>Filter
                                </button>
                                <a href="?finqy_id=<?= htmlspecialchars($finqyId) ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Today's Performance -->
                <h3><i class="bi bi-calendar-day me-2"></i>My Performance Today</h3>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card card-today">
                            <div class="card-body text-center">
                                <i class="bi bi-telephone-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= number_format($today_metrics['calls_made']) ?></div>
                                <div>Calls Made</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-connected">
                            <div class="card-body text-center">
                                <i class="bi bi-check-circle-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= $today_connected_rate ?>%</div>
                                <div>Connected Calls</div>
                                <small><?= number_format($today_metrics['connected_calls']) ?> calls</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-conversion">
                            <div class="card-body text-center">
                                <i class="bi bi-star-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= $today_conversion_rate ?>%</div>
                                <div>Conversion Rate</div>
                                <small><?= number_format($today_metrics['conversions']) ?> conversions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card performance-insight">
                            <div class="card-body text-center">
                                <i class="bi bi-lightbulb-fill fs-1 mb-2"></i>
                                <div class="metric-small">#<?= $caller_rank ?> of <?= $total_team_members ?></div>
                                <div>My Team Rank</div>
                                <small>Based on conversion rate</small>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Pie Charts Row -->
                <h3><i class="bi bi-pie-chart me-2"></i>My Call Analysis</h3>
                <div class="row mb-4">
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

                <div class="row">
                    <!-- Time Slot Analysis -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-clock me-2"></i>Time Slot Analysis</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach($slot_analysis as $slot): ?>
                                <div class="card mb-2 <?= $slot['slot'] == $best_slot ? 'slot-highlight' : '' ?>">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>Slot <?= $slot['slot'] ?></strong>
                                                <?php if ($slot['slot'] == $best_slot): ?>
                                                    <span class="badge bg-success ms-2">Best</span>
                                                <?php endif; ?>
                                                <br><small class="text-muted">
                                                    <?= $slot['total_calls'] ?> calls, <?= $slot['conversions'] ?> conversions
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <strong><?= $slot['conversion_rate'] ?>%</strong>
                                                <div class="progress mt-1" style="width: 100px; height: 8px;">
                                                    <div class="progress-bar <?= $slot['conversion_rate'] >= 10 ? 'bg-success' : ($slot['conversion_rate'] >= 5 ? 'bg-warning' : 'bg-danger') ?>" 
                                                         style="width: <?= min($slot['conversion_rate'] * 10, 100) ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Time Slot Performance (Line Chart) -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-graph-up me-2"></i>Time Slot Performance</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="slotLineChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Disposition Breakdown -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-bar-chart me-2"></i>All Dispositions</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="dispositionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Summary -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-trophy me-2"></i>Performance Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="p-3 border rounded">
                                            <h4 class="text-primary"><?= number_format($overall_metrics['total_calls']) ?></h4>
                                            <small class="text-muted">Total Calls</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="p-3 border rounded">
                                            <h4 class="text-success"><?= number_format($overall_metrics['connected_calls']) ?></h4>
                                            <small class="text-muted">Connected</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="p-3 border rounded">
                                            <h4 class="text-info"><?= $overall_connected_rate ?>%</h4>
                                            <small class="text-muted">Connect Rate</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="p-3 border rounded">
                                            <h4 class="text-warning"><?= $overall_conversion_rate ?>%</h4>
                                            <small class="text-muted">Convert Rate</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 p-3 bg-light rounded">
                                    <h6><i class="bi bi-info-circle me-2"></i>Performance Insights</h6>
                                    <ul class="mb-0 small">
                                        <li>Your best performing slot is Slot <?= $best_slot ?: 'N/A' ?> with <?= $best_conversion_rate ?>% conversion</li>
                                        <li>You rank #<?= $caller_rank ?> out of <?= $total_team_members ?> team members</li>
                                        <li>You've made <?= number_format($today_metrics['calls_made']) ?> calls today</li>
                                        <li>Your overall conversion rate is <?= $overall_conversion_rate ?>%</li>
                                        <?php if ($overall_conversion_rate >= 10): ?>
                                            <li class="text-success">Excellent performance! Keep it up!</li>
                                        <?php elseif ($overall_conversion_rate >= 5): ?>
                                            <li class="text-warning">Good performance, aim for 10%+ conversion</li>
                                        <?php else: ?>
                                            <li class="text-danger">Focus on improving conversion rate</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Time Slot Performance Line Chart
        const slotLineCtx = document.getElementById('slotLineChart').getContext('2d');
        const slotData = <?= json_encode($slot_analysis) ?>;
        
        if (slotData && slotData.length > 0) {
            const slotLineChart = new Chart(slotLineCtx, {
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
            slotLineCtx.font = "16px Arial";
            slotLineCtx.fillStyle = "#6c757d";
            slotLineCtx.textAlign = "center";
            slotLineCtx.fillText("No slot performance data available", slotLineCtx.canvas.width / 2, slotLineCtx.canvas.height / 2);
        }

        // Disposition Breakdown Chart
        const dispositionCtx = document.getElementById('dispositionChart').getContext('2d');
        const dispositionChart = new Chart(dispositionCtx, {
            type: 'horizontalBar',
            data: {
                labels: <?= json_encode(array_column($disposition_breakdown, 'disposition')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($disposition_breakdown, 'count')) ?>,
                    backgroundColor: [
                        '#28a745', '#dc3545', '#ffc107', '#17a2b8', 
                        '#6f42c1', '#fd7e14', '#20c997', '#6c757d'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });

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

        // Debug output
        console.log('Connectivity Data:', <?= json_encode($connectivity_breakdown) ?>);
        console.log('Connected Disposition Data:', <?= json_encode($connected_disposition_breakdown) ?>);
        console.log('Not Connected Disposition Data:', <?= json_encode($not_connected_disposition_breakdown) ?>);

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
        const connectedColors = ['#28a745', '#ffc107', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#6c757d', '#e83e8c'];
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
        const notConnectedColors = ['#dc3545', '#6c757d', '#ffc107', '#fd7e14', '#e83e8c', '#6f42c1', '#20c997', '#17a2b8'];
        const notConnectedDispositionChart = createInteractivePieChart(
            notConnectedDispositionCtx, 
            notConnectedData, 
            notConnectedLabels, 
            notConnectedColors,
            'Non-connected Call Dispositions'
        );
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>