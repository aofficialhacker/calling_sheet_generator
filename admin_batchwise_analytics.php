<?php
/**
 * Admin Batchwise Analytics Dashboard
 * Comprehensive analytics for batch performance, unit quality analysis, and data-driven insights
 */

require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Date range filter
$date_range = $_GET['date_range'] ?? '90';
$selected_unit = $_GET['unit_id'] ?? '';
$selected_product = $_GET['product_code'] ?? '';

// Get admin's units (vendors) for filter
$units_sql = "
    SELECT DISTINCT v.vendor_id, v.vendor_name 
    FROM vendors v 
    JOIN file_batches fb ON v.vendor_id = fb.vendor_id 
    WHERE fb.admin_id = ? AND v.is_approved = 1
    ORDER BY v.vendor_name
";
$units_stmt = $conn->prepare($units_sql);
$units_stmt->bind_param("s", $adminId);
$units_stmt->execute();
$units = $units_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$units_stmt->close();

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

// Build WHERE clauses for filters - ensure vendor belongs to current admin
$where_conditions = ["fb.admin_id = ?", "v.admin_id = ?"];
$params = [$adminId, $adminId];
$param_types = "ss";

if ($date_range !== 'all') {
    $where_conditions[] = "fb.upload_time >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params[] = (int)$date_range;
    $param_types .= "i";
}

if ($selected_unit) {
    $where_conditions[] = "v.vendor_id = ?";
    $params[] = $selected_unit;
    $param_types .= "s";
}

if ($selected_product) {
    $where_conditions[] = "fb.product_code = ?";
    $params[] = $selected_product;
    $param_types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Unit Performance Overview - Fixed to group by vendor_id to avoid duplicates
$unit_performance_sql = "
    SELECT 
        v.vendor_id,
        v.vendor_name,
        COUNT(DISTINCT fb.id) as total_batches,
        COUNT(DISTINCT fb.product_code) as product_diversity,
        COUNT(fcl.id) as total_records,
        SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) as processed_records,
        COALESCE(ROUND(
            (SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) / NULLIF(COUNT(fcl.id), 0)) * 100, 1), 0) as processing_rate,
        COUNT(DISTINCT fcl.last_updated_by) as active_callers,
        COALESCE(AVG(DATEDIFF(NOW(), fb.upload_time)), 1) as avg_batch_age_days,
        MIN(fb.upload_time) as first_batch_date,
        MAX(fb.upload_time) as last_batch_date
    FROM vendors v
    JOIN file_batches fb ON v.vendor_id = fb.vendor_id AND v.admin_id = fb.admin_id
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    WHERE v.admin_id = ? AND v.is_approved = 1
    GROUP BY v.vendor_id, v.vendor_name
    HAVING total_records > 0
    ORDER BY total_records DESC, processing_rate DESC
";

$unit_perf_stmt = $conn->prepare($unit_performance_sql);
if ($unit_perf_stmt === false) {
    error_log("Unit performance query error: " . $conn->error);
    $unit_performance = [];
} else {
    // Debug: Log the exact SQL being executed
    error_log("EXECUTING SQL QUERY: " . str_replace('?', "'$adminId'", $unit_performance_sql));
    
    $unit_perf_stmt->bind_param("s", $adminId);
    $unit_perf_stmt->execute();
    
    // Get raw results and log them immediately
    $raw_results = $unit_perf_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    error_log("RAW DATABASE RESULTS COUNT: " . count($raw_results));
    error_log("RAW DATABASE RESULTS: " . json_encode(array_map(function($r) {
        return ['id' => $r['vendor_id'], 'name' => $r['vendor_name'], 'records' => $r['total_records']];
    }, $raw_results)));
    
    // Apply explicit deduplication by vendor_id to prevent any possible duplicates
    $unique_vendors = [];
    foreach ($raw_results as $vendor) {
        $vendor_id = $vendor['vendor_id'];
        if (!isset($unique_vendors[$vendor_id])) {
            $unique_vendors[$vendor_id] = $vendor;
        } else {
            // Log if we find duplicates (this should not happen with proper GROUP BY)
            error_log("DUPLICATE VENDOR DETECTED AND REMOVED: " . $vendor_id . " - " . $vendor['vendor_name']);
        }
    }
    
    $unit_performance = array_values($unique_vendors);
    error_log("AFTER DEDUPLICATION COUNT: " . count($unit_performance));
    
    $unit_perf_stmt->close();
}

// Debug: Log the unit list and admin context
error_log("ADMIN CONTEXT: admin_id=" . $adminId);
error_log("UNIT PERFORMANCE QUERY RESULTS: " . json_encode(array_map(function($u) { 
    return ['id' => $u['vendor_id'], 'name' => $u['vendor_name'], 'records' => $u['total_records']]; 
}, $unit_performance)));

// Additional validation: Check for duplicates in results
$vendor_names = array_column($unit_performance, 'vendor_name');
$vendor_name_counts = array_count_values($vendor_names);
$duplicates = array_filter($vendor_name_counts, function($count) { return $count > 1; });
if (!empty($duplicates)) {
    error_log("DUPLICATE VENDOR NAMES DETECTED: " . json_encode($duplicates));
}

// Calculate Quality Scores for each unit
foreach ($unit_performance as $index => &$unit) {
    // Initialize defaults for null values - ensure all keys exist
    $unit['vendor_id'] = $unit['vendor_id'] ?? 'Unknown';
    $unit['vendor_name'] = $unit['vendor_name'] ?? 'Unknown Unit';
    $unit['total_batches'] = intval($unit['total_batches'] ?? 0);
    $unit['product_diversity'] = intval($unit['product_diversity'] ?? 0);
    $unit['total_records'] = intval($unit['total_records'] ?? 0);
    $unit['processed_records'] = intval($unit['processed_records'] ?? 0);
    $unit['processing_rate'] = floatval($unit['processing_rate'] ?? 0);
    $unit['active_callers'] = intval($unit['active_callers'] ?? 0);
    $unit['avg_batch_age_days'] = floatval($unit['avg_batch_age_days'] ?? 1);
    $unit['first_batch_date'] = $unit['first_batch_date'] ?? null;
    $unit['last_batch_date'] = $unit['last_batch_date'] ?? null;
    
    // Initialize quality fields with defaults
    $unit['quality_score'] = 0;
    $unit['quality_grade'] = 'N/A';
    $unit['quality_color'] = 'secondary';
    
    $quality_score = 0;
    
    // Processing Rate (30% weight) - handle division by zero
    $processing_rate = floatval($unit['processing_rate']);
    $processing_score = min(($processing_rate / 80) * 30, 30);
    
    // Product Diversity (20% weight)
    $diversity = intval($unit['product_diversity']);
    $diversity_score = min(($diversity / 5) * 20, 20);
    
    // Volume Score (25% weight)
    $records = intval($unit['total_records']);
    $volume_score = min(($records / 1000) * 25, 25);
    
    // Consistency Score based on batch frequency (25% weight)
    $batches = intval($unit['total_batches']);
    $age_days = floatval($unit['avg_batch_age_days']);
    $batch_frequency = $batches / max(1, $age_days / 30);
    $consistency_score = min($batch_frequency * 5, 25);
    
    $quality_score = round($processing_score + $diversity_score + $volume_score + $consistency_score, 1);
    $unit['quality_score'] = min($quality_score, 100);
    
    // Quality Grade
    if ($unit['quality_score'] >= 80) {
        $unit['quality_grade'] = 'A+';
        $unit['quality_color'] = 'success';
    } elseif ($unit['quality_score'] >= 70) {
        $unit['quality_grade'] = 'A';
        $unit['quality_color'] = 'primary';
    } elseif ($unit['quality_score'] >= 60) {
        $unit['quality_grade'] = 'B';
        $unit['quality_color'] = 'info';
    } elseif ($unit['quality_score'] >= 50) {
        $unit['quality_grade'] = 'C';
        $unit['quality_color'] = 'warning';
    } else {
        $unit['quality_grade'] = 'D';
        $unit['quality_color'] = 'danger';
    }
}

// Clean up the reference to prevent potential issues
unset($unit);

// Log array state after quality score processing
error_log("AFTER QUALITY PROCESSING COUNT: " . count($unit_performance));
error_log("AFTER QUALITY PROCESSING: " . json_encode(array_map(function($u) {
    return ['id' => $u['vendor_id'], 'name' => $u['vendor_name']];
}, $unit_performance)));

// Monthly Performance Trends
$monthly_trends_sql = "
    SELECT 
        v.vendor_id,
        v.vendor_name,
        DATE_FORMAT(fb.upload_time, '%Y-%m') as month_year,
        COUNT(DISTINCT fb.id) as batches_uploaded,
        COUNT(fcl.id) as records_received,
        SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) as records_processed,
        ROUND((SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) / COUNT(fcl.id)) * 100, 1) as monthly_processing_rate
    FROM vendors v
    JOIN file_batches fb ON v.vendor_id = fb.vendor_id
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    $where_clause
    GROUP BY v.vendor_id, v.vendor_name, DATE_FORMAT(fb.upload_time, '%Y-%m')
    ORDER BY month_year DESC, records_received DESC
    LIMIT 50
";

$monthly_stmt = $conn->prepare($monthly_trends_sql);
if ($param_types) $monthly_stmt->bind_param($param_types, ...$params);
$monthly_stmt->execute();
$monthly_trends = $monthly_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monthly_stmt->close();

// Batch Quality Analysis
$batch_quality_sql = "
    SELECT 
        fb.id as batch_id,
        fb.original_filename,
        fb.upload_time,
        fb.product_code,
        v.vendor_name,
        COUNT(fcl.id) as total_records,
        SUM(CASE WHEN fcl.mobile_no IS NOT NULL AND fcl.mobile_no != '' THEN 1 ELSE 0 END) as valid_mobile_records,
        SUM(CASE WHEN fcl.name IS NOT NULL AND fcl.name != '' THEN 1 ELSE 0 END) as named_records,
        SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) as processed_records,
        ROUND((SUM(CASE WHEN fcl.mobile_no IS NOT NULL AND fcl.mobile_no != '' THEN 1 ELSE 0 END) / COUNT(fcl.id)) * 100, 1) as mobile_completeness,
        ROUND((SUM(CASE WHEN fcl.name IS NOT NULL AND fcl.name != '' THEN 1 ELSE 0 END) / COUNT(fcl.id)) * 100, 1) as name_completeness,
        ROUND((SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) / COUNT(fcl.id)) * 100, 1) as processing_completeness
    FROM file_batches fb
    JOIN vendors v ON fb.vendor_id = v.vendor_id
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    $where_clause
    GROUP BY fb.id, fb.original_filename, fb.upload_time, fb.product_code, v.vendor_name
    ORDER BY fb.upload_time DESC
    LIMIT 20
";

$batch_stmt = $conn->prepare($batch_quality_sql);
if ($param_types) $batch_stmt->bind_param($param_types, ...$params);
$batch_stmt->execute();
$batch_quality = $batch_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$batch_stmt->close();

// Calculate batch quality scores
foreach ($batch_quality as &$batch) {
    $data_quality = ($batch['mobile_completeness'] + $batch['name_completeness']) / 2;
    $processing_quality = $batch['processing_completeness'];
    $volume_factor = min($batch['total_records'] / 100, 1) * 10;
    
    $batch['batch_quality_score'] = round(($data_quality * 0.4) + ($processing_quality * 0.5) + ($volume_factor * 0.1), 1);
    
    if ($batch['batch_quality_score'] >= 80) {
        $batch['batch_quality_grade'] = 'Excellent';
        $batch['batch_quality_color'] = 'success';
    } elseif ($batch['batch_quality_score'] >= 70) {
        $batch['batch_quality_grade'] = 'Good';
        $batch['batch_quality_color'] = 'primary';
    } elseif ($batch['batch_quality_score'] >= 60) {
        $batch['batch_quality_grade'] = 'Average';
        $batch['batch_quality_color'] = 'info';
    } elseif ($batch['batch_quality_score'] >= 50) {
        $batch['batch_quality_grade'] = 'Below Average';
        $batch['batch_quality_color'] = 'warning';
    } else {
        $batch['batch_quality_grade'] = 'Poor';
        $batch['batch_quality_color'] = 'danger';
    }
}

// Product Performance by Unit
$product_performance_sql = "
    SELECT 
        v.vendor_name,
        fb.product_code,
        COUNT(DISTINCT fb.id) as total_batches,
        COUNT(fcl.id) as total_records,
        SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) as processed_records,
        ROUND((SUM(CASE WHEN fcl.status != 'fresh' THEN 1 ELSE 0 END) / COUNT(fcl.id)) * 100, 1) as processing_rate,
        AVG(DATEDIFF(NOW(), fb.upload_time)) as avg_age_days
    FROM vendors v
    JOIN file_batches fb ON v.vendor_id = fb.vendor_id
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    $where_clause
    GROUP BY v.vendor_name, fb.product_code
    ORDER BY v.vendor_name, processing_rate DESC
";

$product_stmt = $conn->prepare($product_performance_sql);
if ($param_types) $product_stmt->bind_param($param_types, ...$params);
$product_stmt->execute();
$product_performance = $product_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$product_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batchwise Analytics Dashboard</title>
    <!-- Cache busting headers to prevent old cached data -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .metric-card { transition: transform 0.2s; border-left: 4px solid; }
        .metric-card:hover { transform: translateY(-2px); }
        .quality-score { font-size: 1.8rem; font-weight: bold; }
        .quality-badge { font-size: 0.9rem; padding: 0.5rem 0.8rem; }
        .unit-performance-card { border-radius: 12px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .trend-chart { height: 300px; }
        .data-table { font-size: 0.9rem; }
        .performance-indicator { width: 60px; height: 8px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-bar-chart-line me-2"></i>Batchwise Analytics Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-primary" onclick="exportBatchAnalytics()">
                                <i class="bi bi-download me-1"></i>Export Data
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="refreshAnalytics()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
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
                                    <option value="30" <?= $date_range == '30' ? 'selected' : '' ?>>Last 30 days</option>
                                    <option value="90" <?= $date_range == '90' ? 'selected' : '' ?>>Last 90 days</option>
                                    <option value="180" <?= $date_range == '180' ? 'selected' : '' ?>>Last 6 months</option>
                                    <option value="365" <?= $date_range == '365' ? 'selected' : '' ?>>Last year</option>
                                    <option value="all" <?= $date_range == 'all' ? 'selected' : '' ?>>All time</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Unit</label>
                                <select name="unit_id" class="form-select">
                                    <option value="">All Units</option>
                                    <?php foreach($units as $unit): ?>
                                    <option value="<?= htmlspecialchars($unit['vendor_id']) ?>" <?= $selected_unit == $unit['vendor_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($unit['vendor_name']) ?>
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
                                <button type="submit" class="btn btn-primary d-block w-100">Apply Filters</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Debug: Show what data we're working with -->
                <div class="alert alert-info">
                    <small>Debug: Found <?= count($unit_performance) ?> units - 
                    <?php 
                    $debug_units = [];
                    foreach($unit_performance as $unit) {
                        $debug_units[] = $unit['vendor_id'] . ':' . $unit['vendor_name'];
                    }
                    echo implode(', ', $debug_units);
                    ?>
                    </small>
                </div>

                <!-- Unit Performance Overview -->
                <div class="row mb-4">
                    <?php if (empty($unit_performance)): ?>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No batch data available for the selected filters. Upload some batches to see analytics.
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach($unit_performance as $unit): ?>
                            <?php
                            // Safety check: ensure all required keys exist with defaults
                            $unit['vendor_name'] = $unit['vendor_name'] ?? 'Unknown Unit';
                            $unit['vendor_id'] = $unit['vendor_id'] ?? 'Unknown';
                            $unit['quality_color'] = $unit['quality_color'] ?? 'secondary';
                            $unit['quality_grade'] = $unit['quality_grade'] ?? 'N/A';
                            $unit['quality_score'] = $unit['quality_score'] ?? 0;
                            $unit['total_batches'] = $unit['total_batches'] ?? 0;
                            $unit['total_records'] = $unit['total_records'] ?? 0;
                            $unit['processing_rate'] = $unit['processing_rate'] ?? 0;
                            $unit['product_diversity'] = $unit['product_diversity'] ?? 0;
                            $unit['active_callers'] = $unit['active_callers'] ?? 0;
                            ?>
                        <div class="col-lg-4 col-md-6 mb-3">
                            <div class="card unit-performance-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="fw-bold text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($unit['vendor_name']) ?>">
                                                <?= htmlspecialchars($unit['vendor_name']) ?>
                                            </h6>
                                            <small class="text-muted"><?= htmlspecialchars($unit['vendor_id']) ?></small>
                                        </div>
                                        <span class="badge bg-<?= $unit['quality_color'] ?> quality-badge">
                                            <?= $unit['quality_grade'] ?>
                                        </span>
                                    </div>
                                    
                                    <div class="text-center mb-3">
                                        <div class="quality-score text-<?= $unit['quality_color'] ?>">
                                            <?= $unit['quality_score'] ?>%
                                        </div>
                                        <small class="text-muted">Quality Score</small>
                                    </div>
                                    
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <strong class="text-primary"><?= number_format($unit['total_batches']) ?></strong>
                                            <br><small class="text-muted">Batches</small>
                                        </div>
                                        <div class="col-4">
                                            <strong class="text-success"><?= number_format($unit['total_records']) ?></strong>
                                            <br><small class="text-muted">Records</small>
                                        </div>
                                        <div class="col-4">
                                            <strong class="text-info"><?= $unit['processing_rate'] ?>%</strong>
                                            <br><small class="text-muted">Processed</small>
                                        </div>
                                    </div>
                                    
                                    <hr class="my-3">
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="bi bi-calendar-check me-1"></i>
                                            <?= $unit['product_diversity'] ?> products
                                        </small>
                                        <small class="text-muted">
                                            <i class="bi bi-people me-1"></i>
                                            <?= $unit['active_callers'] ?> callers
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="row">
                    <!-- Monthly Trends Chart -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Monthly Performance Trends</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($monthly_trends)): ?>
                                    <div class="alert alert-info">No trend data available for the selected period.</div>
                                <?php else: ?>
                                    <canvas id="trendsChart" class="trend-chart"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Product Performance Summary -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Product Performance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($product_performance)): ?>
                                    <p class="text-muted">No product data available.</p>
                                <?php else: ?>
                                    <?php 
                                    $product_summary = [];
                                    foreach ($product_performance as $prod) {
                                        if (!isset($product_summary[$prod['product_code']])) {
                                            $product_summary[$prod['product_code']] = [
                                                'total_records' => 0,
                                                'total_processed' => 0,
                                                'unit_count' => 0
                                            ];
                                        }
                                        $product_summary[$prod['product_code']]['total_records'] += $prod['total_records'];
                                        $product_summary[$prod['product_code']]['total_processed'] += $prod['processed_records'];
                                        $product_summary[$prod['product_code']]['unit_count']++;
                                    }
                                    ?>
                                    <?php foreach ($product_summary as $product_code => $summary): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <strong><?= htmlspecialchars($product_code) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= $summary['unit_count'] ?> units</small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary">
                                                <?= round(($summary['total_processed'] / max(1, $summary['total_records'])) * 100, 1) ?>%
                                            </span>
                                            <br>
                                            <small class="text-muted"><?= number_format($summary['total_records']) ?> records</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Batch Quality Analysis -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Recent Batch Quality Analysis</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($batch_quality)): ?>
                            <div class="alert alert-info">No batch data available for quality analysis.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover data-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Batch</th>
                                            <th>Unit</th>
                                            <th>Product</th>
                                            <th>Records</th>
                                            <th>Data Quality</th>
                                            <th>Processing</th>
                                            <th>Overall Score</th>
                                            <th>Upload Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($batch_quality as $batch): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars(substr($batch['original_filename'], 0, 20)) ?>...</strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($batch['batch_id']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($batch['vendor_name']) ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($batch['product_code']) ?></span>
                                            </td>
                                            <td><?= number_format($batch['total_records']) ?></td>
                                            <td>
                                                <div class="mb-1">
                                                    <small>Mobile: <?= $batch['mobile_completeness'] ?>%</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar bg-info" style="width: <?= $batch['mobile_completeness'] ?>%"></div>
                                                </div>
                                                <div class="mt-1">
                                                    <small>Name: <?= $batch['name_completeness'] ?>%</small>
                                                </div>
                                                <div class="progress" style="height: 4px;">
                                                    <div class="progress-bar bg-warning" style="width: <?= $batch['name_completeness'] ?>%"></div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $batch['processing_completeness'] >= 70 ? 'success' : ($batch['processing_completeness'] >= 40 ? 'warning' : 'danger') ?>">
                                                    <?= $batch['processing_completeness'] ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $batch['batch_quality_color'] ?> quality-badge">
                                                    <?= $batch['batch_quality_score'] ?>%
                                                </span>
                                                <br>
                                                <small class="text-muted"><?= $batch['batch_quality_grade'] ?></small>
                                            </td>
                                            <td>
                                                <small><?= date('M j, Y', strtotime($batch['upload_time'])) ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recommendations -->
                <?php if (!empty($unit_performance)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Data-Driven Recommendations</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php 
                            $top_unit = !empty($unit_performance) ? $unit_performance[0] : null;
                            $low_performing_units = array_filter($unit_performance, function($unit) {
                                return isset($unit['quality_score']) && $unit['quality_score'] < 60;
                            });
                            ?>
                            
                            <?php if ($top_unit && isset($top_unit['quality_score'])): ?>
                            <div class="col-md-4">
                                <div class="alert alert-success">
                                    <h6><i class="bi bi-trophy me-2"></i>Top Performing Unit</h6>
                                    <strong><?= htmlspecialchars($top_unit['vendor_name'] ?? 'Unknown Unit') ?></strong>
                                    <br>Quality Score: <?= $top_unit['quality_score'] ?? 0 ?>%
                                    <br><small>Consider increasing batch allocation</small>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($low_performing_units)): ?>
                            <div class="col-md-4">
                                <div class="alert alert-warning">
                                    <h6><i class="bi bi-exclamation-triangle me-2"></i>Units Needing Attention</h6>
                                    <?php foreach(array_slice($low_performing_units, 0, 2) as $unit): ?>
                                    <div><?= htmlspecialchars($unit['vendor_name'] ?? 'Unknown Unit') ?> (<?= $unit['quality_score'] ?? 0 ?>%)</div>
                                    <?php endforeach; ?>
                                    <small>Consider performance review</small>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-4">
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-graph-up me-2"></i>Optimization Tips</h6>
                                    <ul class="mb-0" style="font-size: 0.9rem;">
                                        <li>Focus on units with 80%+ quality scores</li>
                                        <li>Monitor monthly processing trends</li>
                                        <li>Ensure data completeness standards</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Monthly Trends Chart
        <?php if (!empty($monthly_trends)): ?>
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        
        // Group data by month
        const monthlyData = {};
        <?php foreach($monthly_trends as $trend): ?>
        const monthKey = '<?= $trend['month_year'] ?>';
        if (!monthlyData[monthKey]) {
            monthlyData[monthKey] = {
                batches: 0,
                records: 0,
                processed: 0
            };
        }
        monthlyData[monthKey].batches += <?= $trend['batches_uploaded'] ?>;
        monthlyData[monthKey].records += <?= $trend['records_received'] ?>;
        monthlyData[monthKey].processed += <?= $trend['records_processed'] ?>;
        <?php endforeach; ?>
        
        const months = Object.keys(monthlyData).sort();
        const batchData = months.map(month => monthlyData[month].batches);
        const recordData = months.map(month => monthlyData[month].records);
        const processedData = months.map(month => monthlyData[month].processed);
        
        new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Batches Uploaded',
                    data: batchData,
                    borderColor: 'rgb(54, 162, 235)',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    tension: 0.1
                }, {
                    label: 'Records Received',
                    data: recordData,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    tension: 0.1
                }, {
                    label: 'Records Processed',
                    data: processedData,
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
        <?php endif; ?>
        
        function exportBatchAnalytics() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('export_batch_analytics.php?' + params.toString(), '_blank');
        }
        
        function refreshAnalytics() {
            window.location.reload();
        }
    </script>
</body>
</html>