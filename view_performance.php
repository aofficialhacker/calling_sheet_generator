<?php
session_start();
require_once 'db_config.php'; // Use centralized db config

// Secure this page. If the user is not logged in, redirect to the login panel.
if (!isset($_SESSION['finqy_id'])) {
    header("Location: caller_panel.php");
    exit();
}

$conn = getDBConnection();
$finqy_id = $_SESSION['finqy_id'];

// --- ENHANCED QUERIES FOR DASHBOARD METRICS ---

// A single, efficient query to get all key stats at once.
$stats_sql = "
    SELECT
        COUNT(*) as total_calls,
        SUM(CASE WHEN DATE(processed_at) = CURDATE() THEN 1 ELSE 0 END) as today_calls,
        SUM(CASE WHEN processed_at >= CURDATE() - INTERVAL 6 DAY THEN 1 ELSE 0 END) as week_calls,
        SUM(CASE WHEN connectivity = 'Yes' THEN 1 ELSE 0 END) as total_connected,
        SUM(CASE WHEN disposition = 'Interested' THEN 1 ELSE 0 END) as total_interested,
        MAX(processed_at) as last_activity
    FROM final_call_logs
    WHERE finqy_id = ?
";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("s", $finqy_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate rates, avoiding division by zero.
$connectivity_rate = ($stats['total_calls'] > 0) ? ($stats['total_connected'] / $stats['total_calls']) * 100 : 0;
$interest_rate = ($stats['total_connected'] > 0) ? ($stats['total_interested'] / $stats['total_connected']) * 100 : 0;

// Get the breakdown of call dispositions for the doughnut chart.
$dispo_stmt = $conn->prepare("SELECT disposition, COUNT(*) as count FROM final_call_logs WHERE finqy_id = ? AND disposition IS NOT NULL GROUP BY disposition ORDER BY count DESC");
$dispo_stmt->bind_param("s", $finqy_id);
$dispo_stmt->execute();
$dispositions_result = $dispo_stmt->get_result();
$dispositions = [];
while($row = $dispositions_result->fetch_assoc()) {
    $dispositions[] = $row;
}
$dispo_stmt->close();

// Get data for the daily activity bar chart (last 7 days).
$daily_activity_sql = "
    SELECT 
        DATE(processed_at) AS call_date, 
        COUNT(id) AS call_count
    FROM final_call_logs
    WHERE finqy_id = ? AND processed_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY call_date
";
$daily_stmt = $conn->prepare($daily_activity_sql);
$daily_stmt->bind_param("s", $finqy_id);
$daily_stmt->execute();
$result = $daily_stmt->get_result();
$calls_by_date = [];
while($row = $result->fetch_assoc()) {
    $calls_by_date[$row['call_date']] = $row['call_count'];
}
$daily_stmt->close();

// Build the final, complete 7-day chart data in PHP to ensure all days are present.
$daily_chart_data = ['labels' => [], 'data' => []];
for ($i = 6; $i >= 0; $i--) {
    $date_obj = new DateTime("-{$i} days");
    $date_key = $date_obj->format('Y-m-d');
    $daily_chart_data['labels'][] = $date_obj->format('D, M j'); // e.g., 'Wed, Aug 6'
    $daily_chart_data['data'][] = $calls_by_date[$date_key] ?? 0;
}

// Get the last 5 logged calls for the recent activity table.
$recent_logs_sql = "SELECT name, mobile_no, connectivity, disposition, processed_at 
                    FROM final_call_logs 
                    WHERE finqy_id = ? 
                    ORDER BY processed_at DESC 
                    LIMIT 5";
$recent_stmt = $conn->prepare($recent_logs_sql);
$recent_stmt->bind_param("s", $finqy_id);
$recent_stmt->execute();
$recent_logs = $recent_stmt->get_result();
$recent_stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f4f7f6; }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>
<div class="container-fluid mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 px-md-3">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-bar-chart-line-fill me-2"></i>My Performance</h1>
            <span class="text-muted">Stats for <?= htmlspecialchars($_SESSION['caller_name']) ?> (<?= htmlspecialchars($finqy_id) ?>)</span>
        </div>
        <a href="caller_panel.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Panel</a>
    </div>

    <!-- KPI Stats Cards -->
    <div class="row g-4 px-md-3">
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card text-center h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Total Calls Logged</h6>
                    <p class="display-5 fw-bold text-primary"><?= (int)($stats['total_calls'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card text-center h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Calls Today</h6>
                    <p class="display-5 fw-bold text-success"><?= (int)($stats['today_calls'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card text-center h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Connectivity Rate</h6>
                    <p class="display-5 fw-bold text-info"><?= number_format($connectivity_rate, 1) ?>%</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card text-center h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Interest Rate</h6>
                    <p class="display-5 fw-bold text-warning"><?= number_format($interest_rate, 1) ?>%</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-4 mt-2 px-md-3">
        <div class="col-lg-5">
            <div class="card stat-card h-100">
                <div class="card-header bg-white border-0"><h5 class="mb-0">Disposition Breakdown</h5></div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <canvas id="dispositionChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card stat-card h-100">
                <div class="card-header bg-white border-0"><h5 class="mb-0">Daily Activity (Last 7 Days)</h5></div>
                <div class="card-body">
                    <canvas id="dailyActivityChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity Table -->
    <div class="row mt-4 px-md-3">
        <div class="col-12">
            <div class="card stat-card">
                <div class="card-header bg-white border-0"><h5 class="mb-0">Recent Activity</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Mobile No</th>
                                    <th>Connectivity</th>
                                    <th>Disposition</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_logs->num_rows > 0): ?>
                                    <?php while($log = $recent_logs->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($log['name']) ?></td>
                                        <td><?= htmlspecialchars($log['mobile_no']) ?></td>
                                        <td>
                                            <span class="badge <?= $log['connectivity'] == 'Yes' ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' ?>">
                                                <?= htmlspecialchars($log['connectivity']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($log['disposition']) ?></td>
                                        <td><?= date('d M, H:i', strtotime($log['processed_at'])) ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted">No recent activity found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Disposition Breakdown Chart (Doughnut)
    const dispositionData = <?= json_encode($dispositions) ?>;
    if (dispositionData.length > 0) {
        const ctxDispo = document.getElementById('dispositionChart').getContext('2d');
        new Chart(ctxDispo, {
            type: 'doughnut',
            data: {
                labels: dispositionData.map(d => d.disposition),
                datasets: [{
                    data: dispositionData.map(d => d.count),
                    backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6c757d', '#0dcaf0', '#fd7e14'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
    }

    // Daily Activity Chart (Bar)
    const dailyActivityData = <?= json_encode($daily_chart_data) ?>;
    if (dailyActivityData.labels.length > 0) {
        const ctxDaily = document.getElementById('dailyActivityChart').getContext('2d');
        new Chart(ctxDaily, {
            type: 'bar',
            data: {
                labels: dailyActivityData.labels,
                datasets: [{
                    label: 'Calls Logged',
                    data: dailyActivityData.data,
                    backgroundColor: 'rgba(13, 110, 253, 0.6)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>
