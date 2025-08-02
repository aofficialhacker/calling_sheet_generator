<?php
session_start();

// Logic: Secure this page. If the user is not logged in as a caller, redirect them to the login panel.
// Why: This prevents unauthorized access to performance data.
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

// --- QUERIES FOR DASHBOARD METRICS ---

// Logic: A single, efficient query to get all key stats at once. This part is correct and remains.
$stats_sql = "
    SELECT
        COUNT(*) as total_calls,
        SUM(CASE WHEN DATE(processed_at) = CURDATE() THEN 1 ELSE 0 END) as today_calls,
        SUM(CASE WHEN processed_at >= CURDATE() - INTERVAL 6 DAY THEN 1 ELSE 0 END) as week_calls,
        MAX(processed_at) as last_activity
    FROM final_call_logs
    WHERE finqy_id = ?
";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("s", $finqy_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();


// Logic: Get the breakdown of call dispositions. This part is correct and remains.
$dispo_stmt = $conn->prepare("SELECT disposition, COUNT(*) as count FROM final_call_logs WHERE finqy_id = ? AND disposition IS NOT NULL GROUP BY disposition ORDER BY count DESC");
$dispo_stmt->bind_param("s", $finqy_id);
$dispo_stmt->execute();
$dispositions = $dispo_stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// --- CHANGE START: More robust logic for building the daily activity chart ---
// Logic:
// Step 1: Fetch raw call counts only for days that had activity in the last week. This query is simpler and faster.
$raw_daily_sql = "
    SELECT 
        DATE(processed_at) AS call_date, 
        COUNT(id) AS call_count
    FROM final_call_logs
    WHERE finqy_id = ? AND processed_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY call_date
";
$raw_daily_stmt = $conn->prepare($raw_daily_sql);
$raw_daily_stmt->bind_param("s", $finqy_id);
$raw_daily_stmt->execute();
$result = $raw_daily_stmt->get_result();

// Step 2: Map the results into a lookup array for easy access (e.g., ['2024-08-02' => 4]).
$calls_by_date = [];
while($row = $result->fetch_assoc()) {
    $calls_by_date[$row['call_date']] = $row['call_count'];
}
$raw_daily_stmt->close();

// Step 3: Build the final, complete 7-day chart data in PHP. This is more reliable than a complex SQL join.
// Why: This guarantees we have an entry for all 7 days, even those with zero calls, preventing the chart from failing to render.
$daily_counts = [];
for ($i = 6; $i >= 0; $i--) {
    // Create a DateTime object for each of the last 7 days.
    $date_obj = new DateTime("-{$i} days");
    $date_key = $date_obj->format('Y-m-d'); // Key for our lookup array.
    
    // Build the final array element for the chart.
    $daily_counts[] = [
        'day_name'   => $date_obj->format('D'), // e.g., 'Sat', 'Sun'
        'call_count' => $calls_by_date[$date_key] ?? 0 // Use the count from our lookup array, or default to 0 if not found.
    ];
}

// Step 4: Calculate the maximum calls in a single day for chart scaling.
$max_daily_calls = max(array_column($daily_counts, 'call_count')) ?: 1; // Prevent division by zero if all counts are 0.
// --- CHANGE END ---

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
    <style>
        .chart-container {
            display: flex;
            justify-content: space-around;
            height: 250px;
            padding: 10px;
            border-top: 1px solid #eee;
            align-items: flex-end; /* Align bars to the bottom */
        }
        .chart-bar-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-grow: 1;
            text-align: center;
        }
        .chart-bar {
            width: 60%;
            background-color: #3498db;
            border-radius: 5px 5px 0 0;
            transition: height 0.5s ease-out;
            position: relative;
            display: flex;
            justify-content: center;
            overflow: hidden;
        }
        .bar-label {
            font-size: 0.9em;
            font-weight: bold;
            color: #fff;
            padding-top: 5px;
        }
        .day-label {
            margin-top: 8px;
            font-size: 0.8em;
            color: #6c757d;
            font-weight: bold;
        }
        .card-body.chart-body { padding: 0.5rem; }
    </style>
</head>
<body class="bg-light">
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-bar-chart-line-fill me-2"></i>My Performance</h1>
            <span class="text-muted">Stats for <?= htmlspecialchars($_SESSION['caller_name']) ?> (<?= htmlspecialchars($finqy_id) ?>)</span>
        </div>
        <a href="caller_panel.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Panel</a>
    </div>

    <!-- KPI Stats Cards -->
    <div class="row g-4">
        <div class="col-lg-3 col-md-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Total Calls Logged</h6>
                    <p class="display-5 fw-bold text-primary"><?= (int)($stats['total_calls'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Calls Today</h6>
                    <p class="display-5 fw-bold text-success"><?= (int)($stats['today_calls'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Last 7 Days</h6>
                    <p class="display-5 fw-bold text-info"><?= (int)($stats['week_calls'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body d-flex flex-column justify-content-center">
                    <h6 class="card-subtitle mb-2 text-muted">Last Activity</h6>
                    <p class="h5 fw-normal mt-2">
                        <?= $stats['last_activity'] ? date('d M, H:i', strtotime($stats['last_activity'])) : 'N/A' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Dashboard Layout with Chart and Dispositions -->
    <div class="row g-4 mt-2">
        <!-- Disposition Breakdown Card -->
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h3 class="h5 mb-0">Disposition Breakdown</h3></div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <?php if (empty($dispositions)): ?>
                        <p class="text-muted text-center mt-3">No dispositions logged yet.</p>
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

        <!-- Daily Activity Chart Card -->
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h3 class="h5 mb-0">Daily Activity (Last 7 Days)</h3></div>
                <div class="card-body chart-body">
                    <div class="chart-container">
                        <?php foreach ($daily_counts as $day): ?>
                        <div class="chart-bar-wrapper">
                            <div class="chart-bar" style="height: <?= ($day['call_count'] / $max_daily_calls) * 100 ?>%;">
                                <?php if ($day['call_count'] > 0): ?>
                                    <div class="bar-label"><?= $day['call_count'] ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="day-label"><?= $day['day_name'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>