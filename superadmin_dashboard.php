<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();

// Get filter parameters
$admin_filter = $_GET['admin'] ?? '';
$caller_filter = $_GET['caller'] ?? '';
$product_filter = $_GET['product'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Build WHERE clauses based on filters
$where_conditions = [
    // Only include records that have been processed by callers (have disposition or connectivity marked)
    "((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))"
];
$params = [];
$types = '';

if ($admin_filter) {
    $where_conditions[] = "fb.admin_id = ?";
    $params[] = $admin_filter;
    $types .= 's';
}

if ($caller_filter) {
    $where_conditions[] = "fcl.finqy_id = ?";
    $params[] = $caller_filter;
    $types .= 's';
}

if ($product_filter) {
    $where_conditions[] = "fb.product_code = ?";
    $params[] = $product_filter;
    $types .= 's';
}

// Add date range filter
if ($from_date && $to_date) {
    $where_conditions[] = "DATE(fcl.processed_at) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= 'ss';
}

$where_clause = "WHERE " . implode(' AND ', $where_conditions);

// Fetch overall performance metrics
$overall_query = "
    SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause
";

$stmt = $conn->prepare($overall_query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$overall_metrics = $stmt->get_result()->fetch_assoc();

// Calculate conversion rate
$conversion_rate = $overall_metrics['total_calls'] > 0 ? 
    round(($overall_metrics['conversions'] / $overall_metrics['total_calls']) * 100, 2) : 0;

$connected_rate = $overall_metrics['total_calls'] > 0 ? 
    round(($overall_metrics['connected_calls'] / $overall_metrics['total_calls']) * 100, 2) : 0;

// Fetch connectivity breakdown (Connected vs Non-connected)
$connectivity_query = "
    SELECT 
        CASE 
            WHEN fcl.connectivity IN ('Y', 'Yes') THEN 'Connected' 
            WHEN fcl.connectivity IN ('N', 'No') THEN 'Not Connected'
            WHEN fcl.connectivity IS NULL OR fcl.connectivity = '' THEN 'Not Connected'
            ELSE 'Not Connected'
        END as connectivity_status,
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause
    GROUP BY connectivity_status 
    ORDER BY count DESC
";

$stmt = $conn->prepare($connectivity_query);
if ($stmt === false) {
    die("Error preparing connectivity query: " . $conn->error);
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$connectivity_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch disposition breakdown for connected calls
$connected_disposition_query = "
    SELECT 
        COALESCE(fcl.disposition, 'No Disposition') as disposition, 
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause AND fcl.connectivity IN ('Y', 'Yes')
    GROUP BY COALESCE(fcl.disposition, 'No Disposition')
    HAVING count > 0
    ORDER BY count DESC
";

$stmt = $conn->prepare($connected_disposition_query);
if ($stmt === false) {
    die("Error preparing connected disposition query: " . $conn->error);
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$connected_disposition_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch disposition breakdown for non-connected calls
$not_connected_disposition_query = "
    SELECT 
        COALESCE(fcl.disposition, 'No Disposition') as disposition, 
        COUNT(*) as count
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause AND (fcl.connectivity IN ('N', 'No') OR fcl.connectivity IS NULL OR fcl.connectivity = '')
    GROUP BY COALESCE(fcl.disposition, 'No Disposition')
    HAVING count > 0
    ORDER BY count DESC
";

$stmt = $conn->prepare($not_connected_disposition_query);
if ($stmt === false) {
    die("Error preparing not connected disposition query: " . $conn->error);
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$not_connected_disposition_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Debug: Add data validation
$debug_info = [
    'connectivity_count' => count($connectivity_breakdown),
    'connected_disposition_count' => count($connected_disposition_breakdown),
    'not_connected_disposition_count' => count($not_connected_disposition_breakdown),
    'total_records' => $overall_metrics['total_calls']
];

// Fetch time slot performance
$slot_query = "
    SELECT 
        slot,
        COUNT(*) as total_calls,
        SUM(CASE WHEN connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions
    FROM final_call_logs fcl 
    JOIN file_batches fb ON fcl.batch_id = fb.id 
    $where_clause AND slot IS NOT NULL
    GROUP BY slot 
    ORDER BY slot
";

$stmt = $conn->prepare($slot_query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$slot_performance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch unit insights
$unit_query = "
    SELECT 
        v.vendor_name as unit_name,
        COUNT(fcl.id) as leads_provided,
        SUM(CASE WHEN fcl.connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN fcl.connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as connected_rate,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM vendors v
    LEFT JOIN file_batches fb ON v.vendor_id = fb.vendor_id
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    WHERE ((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))
    GROUP BY v.vendor_id, v.vendor_name
    HAVING leads_provided > 0
    ORDER BY conversion_rate DESC
";

$unit_insights = $conn->query($unit_query)->fetch_all(MYSQLI_ASSOC);

// Fetch admin performance
$admin_query = "
    SELECT 
        au.name as admin_name,
        fb.admin_id,
        COUNT(fcl.id) as calls_made,
        SUM(CASE WHEN fcl.connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN fcl.connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as connected_rate,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM admin_users au
    JOIN file_batches fb ON au.admin_id = fb.admin_id
    JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    WHERE ((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))
    GROUP BY au.admin_id, au.name
    ORDER BY conversion_rate DESC
";

$admin_performance = $conn->query($admin_query)->fetch_all(MYSQLI_ASSOC);

// Fetch top and bottom performers
$caller_performance_query = "
    SELECT 
        c.caller_name,
        c.finqy_id,
        au.name as admin_name,
        COUNT(fcl.id) as calls_made,
        SUM(CASE WHEN fcl.connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) as connected_calls,
        SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) as conversions,
        ROUND((SUM(CASE WHEN fcl.connectivity IN ('Y', 'Yes') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as connected_rate,
        ROUND((SUM(CASE WHEN fcl.disposition IN ('Interested', 'Call Back', 'More Info') THEN 1 ELSE 0 END) * 100.0 / COUNT(fcl.id)), 2) as conversion_rate
    FROM callers c
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    JOIN admin_users au ON acm.admin_id = au.admin_id
    JOIN final_call_logs fcl ON c.finqy_id = fcl.finqy_id
    JOIN file_batches fb ON fcl.batch_id = fb.id
    WHERE ((fcl.disposition IS NOT NULL AND fcl.disposition != '') OR (fcl.connectivity IS NOT NULL AND fcl.connectivity != ''))
    GROUP BY c.finqy_id, c.caller_name, au.name
    HAVING calls_made >= 10
    ORDER BY conversion_rate DESC
";

$caller_performance = $conn->query($caller_performance_query)->fetch_all(MYSQLI_ASSOC);

// Get filter options
$admins = $conn->query("SELECT admin_id, name FROM admin_users WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT DISTINCT product_code, product_name FROM products ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

// Get telecallers based on admin selection
if ($admin_filter) {
    $callers_query = "
        SELECT c.finqy_id, c.caller_name 
        FROM callers c
        JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
        WHERE acm.admin_id = ? AND c.is_active = 1
        ORDER BY c.caller_name
    ";
    $stmt = $conn->prepare($callers_query);
    $stmt->bind_param('s', $admin_filter);
    $stmt->execute();
    $callers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $callers = $conn->query("SELECT finqy_id, caller_name FROM callers WHERE is_active = 1 ORDER BY caller_name")->fetch_all(MYSQLI_ASSOC);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - Calling Sheet Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-metric {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .card-metric-success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        .card-metric-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .card-metric-info {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }
        .metric-value {
            font-size: 2.5rem;
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
        .metric-card-interactive {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .metric-card-interactive:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .table-hover tbody tr {
            transition: all 0.2s ease;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,0.1);
            transform: scale(1.01);
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-graph-up-arrow me-2"></i>Super Admin Dashboard</h1>
                    <div>
                        <a href="superadmin_panel.php" class="btn btn-outline-primary">
                            <i class="bi bi-house-door me-1"></i>Back to Panel
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">Admin</label>
                                <select name="admin" id="admin-select" class="form-select" onchange="updateTelecallers()">
                                    <option value="">All Admins</option>
                                    <?php foreach($admins as $admin): ?>
                                        <option value="<?= $admin['admin_id'] ?>" <?= $admin_filter == $admin['admin_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($admin['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">Telecaller</label>
                                <select name="caller" id="caller-select" class="form-select">
                                    <option value="">All Callers</option>
                                    <?php foreach($callers as $caller): ?>
                                        <option value="<?= $caller['finqy_id'] ?>" <?= $caller_filter == $caller['finqy_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($caller['caller_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">Product</label>
                                <select name="product" class="form-select">
                                    <option value="">All Products</option>
                                    <?php foreach($products as $product): ?>
                                        <option value="<?= $product['product_code'] ?>" <?= $product_filter == $product['product_code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($product['product_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?= $from_date ?>">
                            </div>
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?= $to_date ?>">
                            </div>
                            <div class="col-lg-2 col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-funnel me-1"></i>Filter
                                </button>
                                <a href="?" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Overall Performance Overview -->
                <h3><i class="bi bi-bar-chart me-2"></i>Overall Performance Overview</h3>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card card-metric metric-card-interactive" onclick="showMetricDetails('Total Calls', '<?= number_format($overall_metrics['total_calls']) ?>', 'All calls made across the selected time period and filters')">
                            <div class="card-body text-center">
                                <i class="bi bi-telephone-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= number_format($overall_metrics['total_calls']) ?></div>
                                <div>Total Calls Made</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-metric-success metric-card-interactive" onclick="showMetricDetails('Connected Calls', '<?= number_format($overall_metrics['connected_calls']) ?> (<?= $connected_rate ?>%)', 'Calls where connection was established with the customer')">
                            <div class="card-body text-center">
                                <i class="bi bi-check-circle-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= number_format($overall_metrics['connected_calls']) ?></div>
                                <div>Connected Calls (<?= $connected_rate ?>%)</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-metric-info metric-card-interactive" onclick="showMetricDetails('Conversion Rate', '<?= $conversion_rate ?>%', 'Percentage of calls that resulted in positive outcomes (Interested, Call Back, More Info)')">
                            <div class="card-body text-center">
                                <i class="bi bi-star-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= $conversion_rate ?>%</div>
                                <div>Conversion Rate</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-metric-warning metric-card-interactive" onclick="showMetricDetails('Total Conversions', '<?= number_format($overall_metrics['conversions']) ?>', 'Total number of successful conversions (Interested, Call Back, More Info)')">
                            <div class="card-body text-center">
                                <i class="bi bi-trophy-fill fs-1 mb-2"></i>
                                <div class="metric-value"><?= number_format($overall_metrics['conversions']) ?></div>
                                <div>Total Conversions</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
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

                <!-- Time Slot Performance (Line Chart) -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-graph-up me-2"></i>Time Slot Performance Trend</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="slotChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unit Insights -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-building me-2"></i>Unit Insights</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Unit</th>
                                                <th>Leads Provided</th>
                                                <th>Conversions</th>
                                                <th>Conversion Rate</th>
                                                <th>Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($unit_insights as $unit): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($unit['unit_name']) ?></td>
                                                <td><?= number_format($unit['leads_provided']) ?></td>
                                                <td><?= number_format($unit['conversions']) ?></td>
                                                <td><?= $unit['conversion_rate'] ?>%</td>
                                                <td>
                                                    <div class="progress">
                                                        <div class="progress-bar" style="width: <?= min($unit['conversion_rate'], 100) ?>%"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admin Performance -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-people me-2"></i>Admin Performance</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Admin</th>
                                                <th>Calls</th>
                                                <th>Conv. Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($admin_performance as $admin): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($admin['admin_name']) ?></td>
                                                <td><?= number_format($admin['calls_made']) ?></td>
                                                <td>
                                                    <span class="badge <?= $admin['conversion_rate'] >= 10 ? 'bg-success' : ($admin['conversion_rate'] >= 5 ? 'bg-warning' : 'bg-danger') ?>">
                                                        <?= $admin['conversion_rate'] ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top/Bottom Performers -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="bi bi-award me-2"></i>Telecaller Performance</h5>
                            </div>
                            <div class="card-body">
                                <h6 class="text-success">Top 5 Performers</h6>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm">
                                        <tbody>
                                            <?php 
                                            $top_performers = array_slice($caller_performance, 0, 5);
                                            foreach($top_performers as $idx => $caller): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-success"><?= $idx + 1 ?></span>
                                                    <?= htmlspecialchars($caller['caller_name']) ?>
                                                </td>
                                                <td><?= $caller['conversion_rate'] ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <h6 class="text-danger">Bottom 5 Performers</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <tbody>
                                            <?php 
                                            $bottom_performers = array_slice(array_reverse($caller_performance), 0, 5);
                                            foreach($bottom_performers as $idx => $caller): ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($caller['caller_name']) ?>
                                                    <small class="text-muted">(<?= htmlspecialchars($caller['admin_name']) ?>)</small>
                                                </td>
                                                <td><?= $caller['conversion_rate'] ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Function to show metric details
        function showMetricDetails(title, value, description) {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${title}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3">
                                <h2 class="text-primary">${value}</h2>
                            </div>
                            <p>${description}</p>
                            <small class="text-muted">Click on charts and tables for more detailed insights.</small>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            const bootstrapModal = new bootstrap.Modal(modal);
            bootstrapModal.show();
            modal.addEventListener('hidden.bs.modal', () => {
                document.body.removeChild(modal);
            });
        }

        function updateTelecallers() {
            const adminSelect = document.getElementById('admin-select');
            const callerSelect = document.getElementById('caller-select');
            const selectedAdmin = adminSelect.value;

            // Clear current options except the first one
            callerSelect.innerHTML = '<option value="">All Callers</option>';

            // If no admin selected, we'll need to fetch all callers via AJAX or reload
            // For simplicity, let's trigger a form submission to refresh the page
            if (selectedAdmin === '') {
                // Show all callers - trigger form submission
                document.querySelector('form').submit();
            } else {
                // Show only selected admin's callers - trigger form submission
                document.querySelector('form').submit();
            }
        }

        // Debug information
        console.log('Dashboard Debug Info:', <?= json_encode($debug_info) ?>);
        console.log('Connectivity Data:', <?= json_encode($connectivity_breakdown) ?>);
        console.log('Connected Disposition Data:', <?= json_encode($connected_disposition_breakdown) ?>);
        console.log('Not Connected Disposition Data:', <?= json_encode($not_connected_disposition_breakdown) ?>);

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
                    onHover: (event, activeElements) => {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    },
                    onClick: (event, activeElements) => {
                        if (activeElements.length > 0) {
                            const element = activeElements[0];
                            const label = element.chart.data.labels[element.index];
                            const value = element.chart.data.datasets[0].data[element.index];
                            alert(`${title}\n${label}: ${value} records`);
                        }
                    }
                }
            });
        }

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

        // Time Slot Performance Line Chart
        const slotCtx = document.getElementById('slotChart').getContext('2d');
        const slotData = <?= json_encode($slot_performance) ?>;
        
        if (slotData && slotData.length > 0) {
            const slotChart = new Chart(slotCtx, {
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
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20
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
                                        const connRate = slot.total_calls > 0 ? 
                                            ((slot.connected_calls / slot.total_calls) * 100).toFixed(1) : 0;
                                        const convRate = slot.total_calls > 0 ? 
                                            ((slot.conversions / slot.total_calls) * 100).toFixed(1) : 0;
                                        return [`Connection Rate: ${connRate}%`, `Conversion Rate: ${convRate}%`];
                                    }
                                }
                            }
                        }
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    onHover: (event, activeElements) => {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    },
                    onClick: (event, activeElements) => {
                        if (activeElements.length > 0) {
                            const element = activeElements[0];
                            const slotIndex = element.index;
                            const slot = slotData[slotIndex];
                            const connRate = slot.total_calls > 0 ? 
                                ((slot.connected_calls / slot.total_calls) * 100).toFixed(1) : 0;
                            const convRate = slot.total_calls > 0 ? 
                                ((slot.conversions / slot.total_calls) * 100).toFixed(1) : 0;
                            alert(`Slot ${slot.slot} Performance:\n` +
                                  `Total Calls: ${slot.total_calls}\n` +
                                  `Connected: ${slot.connected_calls} (${connRate}%)\n` +
                                  `Conversions: ${slot.conversions} (${convRate}%)`);
                        }
                    }
                }
            });
        } else {
            slotCtx.font = "16px Arial";
            slotCtx.fillStyle = "#6c757d";
            slotCtx.textAlign = "center";
            slotCtx.fillText("No slot performance data available", slotCtx.canvas.width / 2, slotCtx.canvas.height / 2);
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>