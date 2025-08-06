<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Fetch performance data for callers mapped to this admin
$perf_sql = "SELECT 
                fcl.finqy_id,
                c.caller_name,
                COUNT(fcl.id) as total_calls,
                SUM(CASE WHEN fcl.connectivity = 'Yes' THEN 1 ELSE 0 END) as connected,
                SUM(CASE WHEN fcl.disposition = 'Interested' THEN 1 ELSE 0 END) as interested,
                MAX(fcl.processed_at) as last_activity
             FROM final_call_logs fcl
             JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
             JOIN callers c ON fcl.finqy_id = c.finqy_id
             WHERE acm.admin_id = ?
             GROUP BY fcl.finqy_id, c.caller_name 
             ORDER BY total_calls DESC";
$stmt = $conn->prepare($perf_sql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$performance_data = $stmt->get_result();

// Fetch overall stats for this admin
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM admin_caller_mapping WHERE admin_id = ?) as caller_count,
    (SELECT COUNT(*) FROM file_batches WHERE admin_id = ?) as batch_count,
    (SELECT COUNT(*) FROM final_call_logs WHERE batch_id IN (SELECT id FROM file_batches WHERE admin_id = ?)) as total_records";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("sss", $adminId, $adminId, $adminId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

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
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stat-card { border: none; border-radius: 10px; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
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
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card bg-primary text-white shadow">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Callers</h6><h2 class="mb-0"><?= $stats['caller_count'] ?? 0 ?></h2></div>
                                <i class="bi bi-telephone-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-success text-white shadow">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Batches</h6><h2 class="mb-0"><?= $stats['batch_count'] ?? 0 ?></h2></div>
                                <i class="bi bi-stack fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-info text-white shadow">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Records</h6><h2 class="mb-0"><?= number_format($stats['total_records'] ?? 0) ?></h2></div>
                                <i class="bi bi-server fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-12">
                        <div class="card shadow-sm">
                            <div class="card-header">Caller Performance</div>
                            <div class="card-body">
                                <canvas id="callerPerformanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caller Performance Table -->
                <div class="card shadow-sm">
                    <div class="card-header"><h4 class="mb-0">Caller Leaderboard</h4></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Caller</th><th>Total Logged</th><th>Connected</th><th>Interested</th><th>Last Activity</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($performance_data && $performance_data->num_rows > 0): ?>
                                        <?php while($row = $performance_data->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($row['caller_name']) ?></strong> (<?= htmlspecialchars($row['finqy_id']) ?>)</td>
                                            <td><?= (int)$row['total_calls'] ?></td>
                                            <td class="text-success fw-bold"><?= (int)$row['connected'] ?></td>
                                            <td class="text-info fw-bold"><?= (int)$row['interested'] ?></td>
                                            <td><?= $row['last_activity'] ? date('d-M-Y H:i', strtotime($row['last_activity'])) : 'N/A' ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">No caller performance data available yet.</td></tr>
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
    const performanceData = <?= json_encode($performance_data->fetch_all(MYSQLI_ASSOC)) ?>;
    if (performanceData.length > 0) {
        const ctx = document.getElementById('callerPerformanceChart').getContext('2d');
        const labels = performanceData.map(d => d.caller_name);
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Calls',
                    data: performanceData.map(d => d.total_calls),
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }, {
                    label: 'Connected',
                    data: performanceData.map(d => d.connected),
                    backgroundColor: 'rgba(75, 192, 192, 0.6)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }, {
                    label: 'Interested',
                    data: performanceData.map(d => d.interested),
                    backgroundColor: 'rgba(255, 159, 64, 0.6)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: { y: { beginAtZero: true } },
                responsive: true,
                plugins: { legend: { position: 'top' } }
            }
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
