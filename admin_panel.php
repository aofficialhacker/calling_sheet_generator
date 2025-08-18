<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// --- ENHANCED QUERIES ---

// 1. Fetch performance data for each caller mapped to this admin
$perf_sql = "SELECT 
                c.caller_name,
                COUNT(fcl.id) as total_calls,
                SUM(CASE WHEN fcl.connectivity = 'Yes' THEN 1 ELSE 0 END) as connected,
                SUM(CASE WHEN fcl.disposition = 'Interested' THEN 1 ELSE 0 END) as interested,
                MAX(fcl.processed_at) as last_activity
             FROM callers c
             LEFT JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
             LEFT JOIN final_call_logs fcl ON c.finqy_id = fcl.finqy_id
             WHERE acm.admin_id = ?
             GROUP BY c.finqy_id, c.caller_name 
             ORDER BY total_calls DESC";
$stmt = $conn->prepare($perf_sql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$performance_data_result = $stmt->get_result();
$performance_data = [];
while($row = $performance_data_result->fetch_assoc()) {
    $performance_data[] = $row;
}
$stmt->close();


// 2. Fetch overall stats for this admin
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM admin_caller_mapping WHERE admin_id = ?) as caller_count,
    (SELECT COUNT(*) FROM file_batches WHERE admin_id = ?) as batch_count,
    (SELECT COUNT(*) FROM final_call_logs fcl JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id WHERE acm.admin_id = ?) as total_records,
    (SELECT COUNT(*) FROM final_call_logs fcl JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id WHERE acm.admin_id = ? AND fcl.connectivity = 'Yes') as total_connected,
    (SELECT COUNT(*) FROM final_call_logs fcl JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id WHERE acm.admin_id = ? AND fcl.disposition = 'Interested') as total_interested
";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("sssss", $adminId, $adminId, $adminId, $adminId, $adminId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate overall connectivity rate
$connectivity_rate = ($stats['total_records'] > 0) ? ($stats['total_connected'] / $stats['total_records']) * 100 : 0;

// 3. Fetch recent batch uploads
$recent_batches_sql = "SELECT b.id, b.original_filename, b.upload_time, p.product_name, 
                              (SELECT COUNT(*) FROM final_call_logs WHERE batch_id = b.id) as record_count
                       FROM file_batches b
                       JOIN products p ON b.product_code = p.product_code
                       WHERE b.admin_id = ?
                       ORDER BY b.upload_time DESC
                       LIMIT 5";
$stmt = $conn->prepare($recent_batches_sql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$recent_batches = $stmt->get_result();
$stmt->close();


$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
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
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                </div>

                <!-- Stat Cards -->
                <div class="row g-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Callers</h6><h2 class="mb-0"><?= $stats['caller_count'] ?? 0 ?></h2></div>
                                <i class="bi bi-telephone-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Connected Calls</h6><h2 class="mb-0"><?= number_format($stats['total_connected'] ?? 0) ?></h2></div>
                                <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Interested Calls</h6><h2 class="mb-0"><?= number_format($stats['total_interested'] ?? 0) ?></h2></div>
                                <i class="bi bi-star-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Connectivity Rate</h6><h2 class="mb-0"><?= number_format($connectivity_rate, 1) ?>%</h2></div>
                                <i class="bi bi-reception-4 fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row g-4 mt-2">
                    <div class="col-lg-4">
                        <div class="card stat-card h-100">
                            <div class="card-header bg-white border-0"><h5 class="mb-0">Overall Outcomes</h5></div>
                            <div class="card-body d-flex justify-content-center align-items-center">
                                <canvas id="outcomeChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="card stat-card h-100">
                            <div class="card-header bg-white border-0"><h5 class="mb-0">Caller Performance</h5></div>
                            <div class="card-body">
                                <canvas id="callerPerformanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caller Leaderboard -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-white border-0"><h4 class="mb-0">Caller Leaderboard</h4></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Caller</th><th>Total Logged</th><th>Connected</th><th>Interested</th><th>Connectivity %</th><th>Interest %</th><th>Last Activity</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($performance_data)): ?>
                                        <?php foreach($performance_data as $row): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($row['caller_name']) ?></strong></td>
                                            <td><?= (int)$row['total_calls'] ?></td>
                                            <td class="text-success fw-bold"><?= (int)$row['connected'] ?></td>
                                            <td class="text-warning fw-bold"><?= (int)$row['interested'] ?></td>
                                            <td><?= $row['total_calls'] > 0 ? number_format(((int)$row['connected'] / (int)$row['total_calls']) * 100, 1) . '%' : '0.0%' ?></td>
                                            <td><?= $row['connected'] > 0 ? number_format(((int)$row['interested'] / (int)$row['connected']) * 100, 1) . '%' : '0.0%' ?></td>
                                            <td><?= $row['last_activity'] ? date('d-M-Y H:i', strtotime($row['last_activity'])) : 'N/A' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center text-muted">No caller performance data available yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                 <!-- Recent Batches -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-white border-0"><h4 class="mb-0">Recent Batch Uploads</h4></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Batch ID</th><th>Product</th><th>Filename</th><th>Records</th><th>Uploaded On</th></tr></thead>
                                <tbody>
                                    <?php if ($recent_batches->num_rows > 0): ?>
                                        <?php while($batch = $recent_batches->fetch_assoc()): ?>
                                            <tr>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($batch['id']) ?></span></td>
                                                <td><?= htmlspecialchars($batch['product_name']) ?></td>
                                                <td><?= htmlspecialchars($batch['original_filename']) ?></td>
                                                <td><?= number_format($batch['record_count']) ?></td>
                                                <td><?= date('d-M-Y H:i', strtotime($batch['upload_time'])) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">No batches have been uploaded yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const performanceData = <?= json_encode($performance_data) ?>;
    const overallStats = <?= json_encode($stats) ?>;

    // 1. Caller Performance Bar Chart
    if (performanceData.length > 0) {
        const ctxPerf = document.getElementById('callerPerformanceChart').getContext('2d');
        new Chart(ctxPerf, {
            type: 'bar',
            data: {
                labels: performanceData.map(d => d.caller_name),
                datasets: [{
                    label: 'Connected',
                    data: performanceData.map(d => d.connected),
                    backgroundColor: 'rgba(25, 135, 84, 0.6)',
                    borderColor: 'rgba(25, 135, 84, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }, {
                    label: 'Interested',
                    data: performanceData.map(d => d.interested),
                    backgroundColor: 'rgba(255, 193, 7, 0.6)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, stacked: true }, x: { stacked: true } },
                plugins: { legend: { position: 'top' } }
            }
        });
    }

    // 2. Overall Outcome Doughnut Chart
    const totalRecords = parseInt(overallStats.total_records || 0);
    const totalConnected = parseInt(overallStats.total_connected || 0);
    const notConnected = totalRecords - totalConnected;

    if (totalRecords > 0) {
        const ctxOutcome = document.getElementById('outcomeChart').getContext('2d');
        new Chart(ctxOutcome, {
            type: 'doughnut',
            data: {
                labels: ['Connected', 'Not Connected'],
                datasets: [{
                    data: [totalConnected, notConnected],
                    backgroundColor: ['rgba(25, 135, 84, 0.7)', 'rgba(220, 53, 69, 0.7)'],
                    hoverOffset: 4
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'top' } } }
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>