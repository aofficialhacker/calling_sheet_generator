<?php
session_start();

// Secure this page: if not logged in, redirect to the login panel.
if (!isset($_SESSION['finqy_id'])) {
    header("Location: caller_panel.php");
    exit();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("DB Connection Failed."); }

$finqy_id = $_SESSION['finqy_id'];

// --- Query for Stats ---
// 1. Total Calls Logged
$total_stmt = $conn->prepare("SELECT COUNT(*) as total FROM final_call_logs WHERE finqy_id = ?");
$total_stmt->bind_param("s", $finqy_id);
$total_stmt->execute();
$total_calls = $total_stmt->get_result()->fetch_assoc()['total'] ?? 0;

// 2. Disposition Breakdown
$dispo_stmt = $conn->prepare("SELECT disposition, COUNT(*) as count FROM final_call_logs WHERE finqy_id = ? AND disposition IS NOT NULL GROUP BY disposition ORDER BY count DESC");
$dispo_stmt->bind_param("s", $finqy_id);
$dispo_stmt->execute();
$dispositions = $dispo_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Recent Activity
$recent_stmt = $conn->prepare("SELECT processed_at, name, mobile_no, disposition FROM final_call_logs WHERE finqy_id = ? ORDER BY processed_at DESC LIMIT 10");
$recent_stmt->bind_param("s", $finqy_id);
$recent_stmt->execute();
$recent_logs = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
</head>
<body>
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-bar-chart-line-fill me-2"></i>My Performance</h1>
            <span class="text-muted">Stats for <?= htmlspecialchars($_SESSION['caller_name']) ?> (<?= htmlspecialchars($finqy_id) ?>)</span>
        </div>
        <a href="caller_panel.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Panel</a>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card text-center text-bg-primary shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Total Calls Logged</h5>
                    <p class="card-text display-4 fw-bold"><?= $total_calls ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header">Disposition Breakdown</div>
                <div class="card-body">
                    <?php if (empty($dispositions)): ?>
                        <p class="text-muted">No dispositions logged yet.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                        <?php foreach($dispositions as $dispo): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= htmlspecialchars($dispo['disposition']) ?>
                                <span class="badge bg-primary rounded-pill"><?= $dispo['count'] ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity Table -->
    <div class="card mt-4 shadow-sm">
        <div class="card-header">
            <h3 class="h5 mb-0">Recent Activity (Last 10)</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr><th>Time</th><th>Customer Name</th><th>Mobile No</th><th>Disposition</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_logs)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No recent activity.</td></tr>
                        <?php else: ?>
                            <?php foreach($recent_logs as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['processed_at']) ?></td>
                                <td><?= htmlspecialchars($log['name']) ?></td>
                                <td><?= htmlspecialchars($log['mobile_no']) ?></td>
                                <td><?= htmlspecialchars($log['disposition']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>