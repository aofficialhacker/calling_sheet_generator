<?php
require_once 'db_config.php';
require_once __DIR__ . '/session_manager.php';
SessionManager::start();

// Check if telecaller is logged in
$finqyId = $_SESSION['finqy_id'] ?? $_GET['finqy_id'] ?? null;

if (!$finqyId) {
    die("Error: Telecaller ID not found. Please login properly.");
}

$conn = getDBConnection();

// Get telecaller information
$caller_query = "SELECT caller_name, mobile_no FROM lv_callers WHERE finqy_id = ?";
$stmt = $conn->prepare($caller_query);
$stmt->bind_param('s', $finqyId);
$stmt->execute();
$caller_info = $stmt->get_result()->fetch_assoc();

if (!$caller_info) {
    die("Error: Telecaller not found.");
}

// Get filter parameters
$from_date_filter = $_GET['from_date'] ?? date('Y-m-01');
$to_date_filter = $_GET['to_date'] ?? date('Y-m-d');


// Build WHERE clauses based on filters - only caller-marked data
$where_conditions = [
    "fcl.finqy_id = ?",
    "((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))"
];
$params = [$finqyId];
$types = 's';

// Add date range filter if both dates are present
if (!empty($from_date_filter) && !empty($to_date_filter)) {
    $where_conditions[] = "DATE(fcl.processed_at) BETWEEN ? AND ?";
    $params[] = $from_date_filter;
    $params[] = $to_date_filter;
    $types .= 'ss';
}

$where_clause = "WHERE " . implode(' AND ', $where_conditions);

// Fetch today's performance
$today_query = "
    SELECT 
        COUNT(*) as calls_made,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM lv_final_call_logs fcl 
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

// Fetch time slot analysis
$slot_query = "
    SELECT 
        slot,
        COUNT(*) as total_calls,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as conversion_rate
    FROM lv_final_call_logs fcl 
    $where_clause AND fcl.processed_at IS NOT NULL AND slot IS NOT NULL
    GROUP BY slot
    ORDER BY CAST(slot AS UNSIGNED)
";

$stmt = $conn->prepare($slot_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$slot_analysis = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Find best performing slot
$best_slot_label = '';
if (!empty($slot_analysis)) {
    $best_slot_data = array_reduce($slot_analysis, function ($a, $b) {
        return ($a['conversion_rate'] ?? -1) > ($b['conversion_rate'] ?? -1) ? $a : $b;
    });
    if ($best_slot_data) {
        $best_slot_label = 'Slot ' . $best_slot_data['slot'];
    }
}


// Fetch connectivity breakdown (Connected vs Non-connected)
$connectivity_query = "
    SELECT 
        CASE 
            WHEN connectivity IN ('Y', 'Yes') THEN 'Connected' 
            ELSE 'Not Connected'
        END as connectivity_status,
        COUNT(*) as count
    FROM lv_final_call_logs fcl 
    $where_clause
    GROUP BY connectivity_status 
    ORDER BY count DESC
";

$stmt = $conn->prepare($connectivity_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$connectivity_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch disposition breakdown for connected calls
$connected_disposition_query = "
    SELECT 
        COALESCE(disposition, 'No Disposition') as disposition, 
        COUNT(*) as count
    FROM lv_final_call_logs fcl 
    $where_clause AND connectivity IN ('Y', 'Yes') AND disposition IS NOT NULL AND disposition != ''
    GROUP BY COALESCE(disposition, 'No Disposition')
    HAVING count > 0
    ORDER BY count DESC
";

$stmt = $conn->prepare($connected_disposition_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$connected_disposition_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch disposition breakdown for non-connected calls
$not_connected_disposition_query = "
    SELECT 
        COALESCE(disposition, 'No Disposition') as disposition, 
        COUNT(*) as count
    FROM lv_final_call_logs fcl 
    $where_clause AND (connectivity IN ('N', 'No') OR connectivity IS NULL OR connectivity = '') AND disposition IS NOT NULL AND disposition != ''
    GROUP BY COALESCE(disposition, 'No Disposition')
    HAVING count > 0
    ORDER BY count DESC
";

$stmt = $conn->prepare($not_connected_disposition_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$not_connected_disposition_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// Get team ranking
$team_ranking_query = "
    SELECT 
        c.finqy_id,
        c.caller_name,
        COUNT(fcl.id) as calls_made,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM lv_callers c
    JOIN lv_admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    LEFT JOIN lv_final_call_logs fcl ON c.finqy_id = fcl.finqy_id
    WHERE acm.admin_id = (SELECT admin_id FROM lv_admin_caller_mapping WHERE finqy_id = ? LIMIT 1) 
        AND ((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))
        AND fcl.processed_at IS NOT NULL
        AND DATE(fcl.processed_at) BETWEEN ? AND ?
    GROUP BY c.finqy_id, c.caller_name
    HAVING calls_made > 0
    ORDER BY conversion_rate DESC, conversions DESC
";

$stmt = $conn->prepare($team_ranking_query);
$stmt->bind_param('sss', $finqyId, $from_date_filter, $to_date_filter);
$stmt->execute();
$team_ranking_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Find current caller's rank
$caller_rank = 'N/A';
$total_team_members = count($team_ranking_data);
if ($total_team_members > 0) {
    foreach ($team_ranking_data as $index => $member) {
        if ($member['finqy_id'] === $finqyId) {
            $caller_rank = $index + 1;
            break;
        }
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
        body { background-color: #f8f9fa; }
        .metric-value { font-size: 2.2rem; font-weight: bold; }
        .metric-small { font-size: 1.8rem; font-weight: bold; }
        .chart-container { position: relative; height: 350px; }
        .filter-card { background: #ffffff; border: 1px solid #e9ecef; }
        .performance-insight { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .card { box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: none; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="bi bi-person-circle me-2"></i>My Dashboard</h1>
                <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($caller_info['caller_name']) ?>!</p>
            </div>
            <div>
                <a href="caller_panel.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left me-1"></i>Go Back
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="finqy_id" value="<?= htmlspecialchars($finqyId) ?>">
                    <div class="col-md-4">
                        <label for="from_date" class="form-label">From</label>
                        <input type="date" id="from_date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date_filter) ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="to_date" class="form-label">To</label>
                        <input type="date" id="to_date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date_filter) ?>">
                    </div>
                    <div class="col-md-4">
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
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-telephone-fill fs-1 mb-2 text-primary"></i>
                        <div class="metric-value"><?= number_format($today_metrics['calls_made']) ?></div>
                        <div>Calls Made</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle-fill fs-1 mb-2 text-success"></i>
                        <div class="metric-value"><?= $today_connected_rate ?>%</div>
                        <div>Connected Calls</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-star-fill fs-1 mb-2 text-warning"></i>
                        <div class="metric-value"><?= $today_conversion_rate ?>%</div>
                        <div>Conversion Rate</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card performance-insight h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-trophy-fill fs-1 mb-2"></i>
                        <div class="metric-small">#<?= $caller_rank ?> of <?= $total_team_members ?></div>
                        <div>My Team Rank</div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Pie Charts Row -->
        <h3><i class="bi bi-pie-chart me-2"></i>Call Analysis (Filtered)</h3>
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header"><h6><i class="bi bi-link-45deg me-2"></i>Connectivity</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="connectivityChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header"><h6><i class="bi bi-check-circle me-2"></i>Connected Disposition</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="connectedDispositionChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header"><h6><i class="bi bi-x-circle me-2"></i>Non-connected Disposition</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="notConnectedDispositionChart"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Time Slot Performance (Line Chart) -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><h5><i class="bi bi-graph-up me-2"></i>Time Slot Performance</h5></div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="slotLineChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Helper function to display a message on a canvas
    function showNoDataMessage(ctx) {
        const canvas = ctx.canvas;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.font = "16px Arial";
        ctx.fillStyle = "#6c757d";
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillText("No data available for the selected period", canvas.width / 2, canvas.height / 2);
        ctx.restore();
    }

    // Time Slot Performance Line Chart
    const slotLineCtx = document.getElementById('slotLineChart').getContext('2d');
    const slotData = <?= json_encode($slot_analysis) ?>;
    const bestSlotLabel = '<?= $best_slot_label ?>';
    
    if (slotData && slotData.length > 0) {
        const labels = slotData.map(slot => `Slot ${slot.slot}`);
        const bestSlotIndex = labels.indexOf(bestSlotLabel);

        // Style the best performing slot differently
        const pointRadii = labels.map((_, i) => i === bestSlotIndex ? 8 : 5);
        const pointHoverRadii = labels.map((_, i) => i === bestSlotIndex ? 10 : 7);
        const pointBackgroundColors = labels.map((_, i) => i === bestSlotIndex ? 'gold' : 'rgba(255, 99, 132, 1)');
        const pointBorderColors = labels.map((_, i) => i === bestSlotIndex ? '#c8a000' : 'rgba(255, 99, 132, 1)');


        new Chart(slotLineCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total Calls',
                        data: slotData.map(slot => parseInt(slot.total_calls)),
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    },
                    {
                        label: 'Conversions',
                        data: slotData.map(slot => parseInt(slot.conversions)),
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: pointRadii,
                        pointHoverRadius: pointHoverRadii,
                        pointBackgroundColor: pointBackgroundColors,
                        pointBorderColor: pointBorderColors
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 20 }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            afterLabel: function(context) {
                                const slotIndex = context.dataIndex;
                                const slot = slotData[slotIndex];
                                if(slot) {
                                    let label = `Conversion Rate: ${slot.conversion_rate}%`;
                                    if(context.chart.data.labels[slotIndex] === bestSlotLabel) {
                                        label += ' (Best)';
                                    }
                                    return label;
                                }
                                return null;
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                onHover: (event, elements) => {
                    event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                }
            }
        });
    } else {
        showNoDataMessage(slotLineCtx);
    }

    // Helper function to create interactive pie chart
    function createInteractivePieChart(ctx, data, labels, colors) {
        if (!data || data.length === 0 || data.every(item => item === 0)) {
            showNoDataMessage(ctx);
            return null;
        }

        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: colors, borderWidth: 2 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // 1. Connected vs Non-connected Chart
    const connectivityCtx = document.getElementById('connectivityChart').getContext('2d');
    const connectivityData = <?= json_encode(array_column($connectivity_breakdown, 'count')) ?>;
    const connectivityLabels = <?= json_encode(array_column($connectivity_breakdown, 'connectivity_status')) ?>;
    createInteractivePieChart(connectivityCtx, connectivityData, connectivityLabels, ['#28a745', '#dc3545']);

    // 2. Connected Calls Disposition Chart
    const connectedDispositionCtx = document.getElementById('connectedDispositionChart').getContext('2d');
    const connectedData = <?= json_encode(array_column($connected_disposition_breakdown, 'count')) ?>;
    const connectedLabels = <?= json_encode(array_column($connected_disposition_breakdown, 'disposition')) ?>;
    createInteractivePieChart(connectedDispositionCtx, connectedData, connectedLabels, ['#28a745', '#007bff', '#6f42c1', '#ff6b35', '#20c997']);

    // 3. Non-connected Calls Disposition Chart
    const notConnectedDispositionCtx = document.getElementById('notConnectedDispositionChart').getContext('2d');
    const notConnectedData = <?= json_encode(array_column($not_connected_disposition_breakdown, 'count')) ?>;
    const notConnectedLabels = <?= json_encode(array_column($not_connected_disposition_breakdown, 'disposition')) ?>;
    createInteractivePieChart(notConnectedDispositionCtx, notConnectedData, notConnectedLabels, ['#dc3545', '#6c757d', '#ffc107', '#e74c3c']);

    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
