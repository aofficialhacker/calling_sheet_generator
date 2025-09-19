<?php
require_once 'db_config.php';
require_once 'masking_utils.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Check if this is a superadmin
$isSuperadmin = false;
$adminCheckQuery = "SELECT designation FROM lv_admin_users WHERE admin_id = ?";
$adminCheckStmt = $conn->prepare($adminCheckQuery);
if ($adminCheckStmt) {
    $adminCheckStmt->bind_param("s", $adminId);
    $adminCheckStmt->execute();
    $adminResult = $adminCheckStmt->get_result();
    if ($adminResult && $row = $adminResult->fetch_assoc()) {
        $isSuperadmin = ($row['designation'] === 'superadmin');
    }
    $adminCheckStmt->close();
}

// Get filter parameters
$batch_filter = $_GET['batch_id'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';
$telecaller_filter = $_GET['telecaller'] ?? 'all';

// Build query conditions based on user type
if ($isSuperadmin) {
    // For superadmin: show follow-ups from all batches
    $whereConditions = ['1=1']; // Always true condition
    $queryParams = [];
    $paramTypes = '';
} else {
    // For regular admin: use caller mapping
    $whereConditions = ['acm.admin_id = ?'];
    $queryParams = [$adminId];
    $paramTypes = 's';
}

// Batch filter
if ($batch_filter !== 'all') {
    $whereConditions[] = 'fcl.batch_id = ?';
    $queryParams[] = $batch_filter;
    $paramTypes .= 's';
}

// Status filter
if ($status_filter !== 'all') {
    switch ($status_filter) {
        case 'pending':
            $whereConditions[] = 'DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) >= CURDATE()';
            break;
        case 'overdue':
            $whereConditions[] = 'DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE()';
            break;
        case 'today':
            $whereConditions[] = 'DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE()';
            break;
    }
}

// Date filter
if ($date_filter !== 'all') {
    switch ($date_filter) {
        case 'this_week':
            $whereConditions[] = 'DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
            break;
        case 'next_week':
            $whereConditions[] = 'DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)';
            break;
    }
}

// Telecaller filter
if ($telecaller_filter !== 'all') {
    $whereConditions[] = 'fcl.finqy_id = ?';
    $queryParams[] = $telecaller_filter;
    $paramTypes .= 's';
}

$whereClause = implode(' AND ', $whereConditions);

// Build different queries based on user type
if ($isSuperadmin) {
    // Superadmin query - no lv_admin_caller_mapping needed
    $query = "
        SELECT 
            fcl.id,
            fcl.name,
            fcl.mobile_no,
            fcl.follow_day,
            fcl.follow_slot,
            fcl.disposition,
            fcl.processed_at,
            fcl.finqy_id,
            DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) as follow_up_date,
            fb.id as batch_id,
            fb.original_filename as batch_name,
            fb.product_code,
            c.caller_name,
            DATEDIFF(DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY), CURDATE()) as days_from_now,
            CASE 
                WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE() THEN 'overdue'
                WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE() THEN 'today'
                ELSE 'pending'
            END as follow_status
        FROM lv_final_call_logs fcl
        JOIN lv_file_batches fb ON fcl.batch_id = fb.id
        LEFT JOIN lv_callers c ON fcl.finqy_id = c.finqy_id
        WHERE $whereClause
        AND fcl.disposition = 'Follow Up'
        AND fcl.follow_day IS NOT NULL
        AND fcl.follow_day > 0
        ORDER BY follow_up_date ASC, fb.original_filename, fcl.name
    ";
} else {
    // Regular admin query - with lv_admin_caller_mapping
    $query = "
        SELECT 
            fcl.id,
            fcl.name,
            fcl.mobile_no,
            fcl.follow_day,
            fcl.follow_slot,
            fcl.disposition,
            fcl.processed_at,
            fcl.finqy_id,
            DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) as follow_up_date,
            fb.id as batch_id,
            fb.original_filename as batch_name,
            fb.product_code,
            c.caller_name,
            DATEDIFF(DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY), CURDATE()) as days_from_now,
            CASE 
                WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE() THEN 'overdue'
                WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE() THEN 'today'
                ELSE 'pending'
            END as follow_status
        FROM lv_final_call_logs fcl
        JOIN lv_file_batches fb ON fcl.batch_id = fb.id
        JOIN lv_admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
        LEFT JOIN lv_callers c ON fcl.finqy_id = c.finqy_id
        WHERE $whereClause
        AND fcl.disposition = 'Follow Up'
        AND fcl.follow_day IS NOT NULL
        AND fcl.follow_day > 0
        ORDER BY follow_up_date ASC, fb.original_filename, fcl.name
    ";
}

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Query preparation failed: " . $conn->error);
}

// Only bind parameters if there are any
if (!empty($queryParams)) {
    $stmt->bind_param($paramTypes, ...$queryParams);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch all data immediately to prevent result set consumption issues
$followup_data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $followup_data[] = $row;
    }
}
$stmt->close();

// Get batch list for filter dropdown
if ($isSuperadmin) {
    // For superadmin: show all batches
    $batchQuery = "
        SELECT fb.id, fb.original_filename, fb.product_code, fb.upload_time
        FROM lv_file_batches fb
        ORDER BY fb.upload_time DESC
    ";
    $batchStmt = $conn->prepare($batchQuery);
    if (!$batchStmt) {
        die("Error preparing batch query: " . $conn->error);
    }
    $batchStmt->execute();
} else {
    // For regular admin: show only their batches
    $batchQuery = "
        SELECT fb.id, fb.original_filename, fb.product_code, fb.upload_time
        FROM lv_file_batches fb
        WHERE fb.admin_id = ?
        ORDER BY fb.upload_time DESC
    ";
    $batchStmt = $conn->prepare($batchQuery);
    if (!$batchStmt) {
        die("Error preparing batch query: " . $conn->error);
    }
    $batchStmt->bind_param("s", $adminId);
    $batchStmt->execute();
}
$batches = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$batchStmt->close();

// Get telecaller list for filter dropdown
if ($isSuperadmin) {
    // For superadmin: show all telecallers
    $telecallerQuery = "
        SELECT DISTINCT c.finqy_id, c.caller_name
        FROM lv_callers c
        JOIN lv_final_call_logs fcl ON c.finqy_id = fcl.finqy_id
        WHERE fcl.disposition = 'Follow Up'
        AND fcl.follow_day IS NOT NULL
        AND fcl.follow_day > 0
        ORDER BY c.caller_name
    ";
    $telecallerStmt = $conn->prepare($telecallerQuery);
    if (!$telecallerStmt) {
        die("Error preparing telecaller query: " . $conn->error);
    }
    $telecallerStmt->execute();
} else {
    // For regular admin: show only their telecallers
    $telecallerQuery = "
        SELECT DISTINCT c.finqy_id, c.caller_name
        FROM lv_callers c
        JOIN lv_admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
        JOIN lv_final_call_logs fcl ON c.finqy_id = fcl.finqy_id
        WHERE acm.admin_id = ?
        AND fcl.disposition = 'Follow Up'
        AND fcl.follow_day IS NOT NULL
        AND fcl.follow_day > 0
        ORDER BY c.caller_name
    ";
    $telecallerStmt = $conn->prepare($telecallerQuery);
    if (!$telecallerStmt) {
        die("Error preparing telecaller query: " . $conn->error);
    }
    $telecallerStmt->bind_param("s", $adminId);
    $telecallerStmt->execute();
}
$telecallers = $telecallerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$telecallerStmt->close();

// Get summary statistics
if ($isSuperadmin) {
    // For superadmin: show all follow-up statistics
    $summaryQuery = "
        SELECT 
            COUNT(*) as total_followups,
            SUM(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE() THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) > CURDATE() THEN 1 ELSE 0 END) as pending
        FROM lv_final_call_logs fcl
        JOIN lv_file_batches fb ON fcl.batch_id = fb.id
        WHERE fcl.disposition = 'Follow Up'
        AND fcl.follow_day IS NOT NULL
        AND fcl.follow_day > 0
    ";
    $summaryStmt = $conn->prepare($summaryQuery);
    if (!$summaryStmt) {
        die("Error preparing summary query: " . $conn->error);
    }
    $summaryStmt->execute();
} else {
    // For regular admin: show only their follow-up statistics
    $summaryQuery = "
        SELECT 
            COUNT(*) as total_followups,
            SUM(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE() THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) > CURDATE() THEN 1 ELSE 0 END) as pending
        FROM lv_final_call_logs fcl
        JOIN lv_file_batches fb ON fcl.batch_id = fb.id
        JOIN lv_admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
        WHERE acm.admin_id = ? 
        AND fcl.disposition = 'Follow Up'
        AND fcl.follow_day IS NOT NULL
        AND fcl.follow_day > 0
    ";
    $summaryStmt = $conn->prepare($summaryQuery);
    if (!$summaryStmt) {
        die("Error preparing summary query: " . $conn->error);
    }
    $summaryStmt->bind_param("s", $adminId);
    $summaryStmt->execute();
}
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-up Batch View - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding-top: 1rem;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
            margin: 0.25rem 0;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255,255,255,0.1);
        }
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .overdue { background-color: #dc3545; color: white; }
        .today { background-color: #fd7e14; color: white; }
        .pending { background-color: #198754; color: white; }
        .table-hover tbody tr:hover {
            background-color: rgba(0,0,0,0.075);
        }
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-1">
                                <li class="breadcrumb-item"><a href="manage_batches.php">View Batches</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Follow-ups</li>
                            </ol>
                        </nav>
                        <h1 class="h2">
                            <i class="bi bi-calendar-check me-2"></i>Follow-up Batch View
                        </h1>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="manage_batches.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Back to Batches
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="refreshData()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-primary">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Follow-ups</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $summary['total_followups'] ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-calendar-check fa-2x text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-danger">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                            Overdue</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $summary['overdue'] ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-exclamation-triangle fa-2x text-danger"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-warning">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Today</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $summary['today'] ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-calendar-day fa-2x text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-success">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Pending</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $summary['pending'] ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-clock fa-2x text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3 col-lg-2">
                                <label for="batch_id" class="form-label">Batch</label>
                                <select class="form-select" id="batch_id" name="batch_id">
                                    <option value="all">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= $batch['id'] ?>" <?= $batch_filter == $batch['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($batch['original_filename']) ?> (<?= $batch['product_code'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label for="telecaller" class="form-label">Telecaller</label>
                                <select class="form-select" id="telecaller" name="telecaller">
                                    <option value="all">All Telecallers</option>
                                    <?php foreach ($telecallers as $telecaller): ?>
                                        <option value="<?= $telecaller['finqy_id'] ?>" <?= $telecaller_filter == $telecaller['finqy_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($telecaller['caller_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="overdue" <?= $status_filter == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                    <option value="today" <?= $status_filter == 'today' ? 'selected' : '' ?>>Today</option>
                                    <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label for="date" class="form-label">Date Range</label>
                                <select class="form-select" id="date" name="date">
                                    <option value="all" <?= $date_filter == 'all' ? 'selected' : '' ?>>All Dates</option>
                                    <option value="this_week" <?= $date_filter == 'this_week' ? 'selected' : '' ?>>This Week</option>
                                    <option value="next_week" <?= $date_filter == 'next_week' ? 'selected' : '' ?>>Next Week</option>
                                </select>
                            </div>
                            <div class="col-md-12 col-lg-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-light me-2">
                                    <i class="bi bi-funnel me-1"></i>Filter
                                </button>
                                <a href="admin_batch_followups.php" class="btn btn-outline-light">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Follow-up Entries Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Follow-up Entries</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">All entries marked as "Follow Up" by telecallers</small>
                            <small class="text-info">
                                <i class="bi bi-shield-lock me-1"></i>Customer data is masked for privacy
                            </small>
                        </div>
                    </div>
                    <div class="card-body">
                        
                        
                        <?php if (!empty($followup_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Batch</th>
                                            <th>Telecaller</th>
                                            <th>Follow-up Date</th>
                                            <th>Slot</th>
                                            <th>Status</th>
                                            <th>Original Call</th>
                                            <th>Product</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_batch = '';
                                        foreach ($followup_data as $row): 
                                            $status_class = $row['follow_status'];
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?= htmlspecialchars(maskName($row['name'])) ?></div>
                                                    <div class="text-muted small"><?= htmlspecialchars(maskMobile($row['mobile_no'])) ?></div>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?= htmlspecialchars(basename($row['batch_name'], '.xlsx')) ?>
                                                    </div>
                                                    <div class="text-muted small"><?= $row['product_code'] ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($row['caller_name'] ?: $row['finqy_id']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?= date('M d, Y', strtotime($row['follow_up_date'])) ?></div>
                                                    <div class="text-muted small">
                                                        <?= $row['days_from_now'] > 0 ? "In {$row['days_from_now']} days" : 
                                                            ($row['days_from_now'] < 0 ? abs($row['days_from_now']) . " days ago" : "Today") ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">Slot <?= $row['follow_slot'] ?: 'Not Set' ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge status-badge <?= $status_class ?>">
                                                        <?= ucfirst($row['follow_status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small text-muted">
                                                        <?= date('M d, Y', strtotime($row['processed_at'])) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($row['product_code']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                <h4 class="text-muted mt-3">No Follow-up Entries Found</h4>
                                <p class="text-muted">No entries marked as "Follow Up" match your current filters.</p>
                                <a href="admin_batch_followups.php" class="btn btn-primary">Clear Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function refreshData() {
            window.location.reload();
        }
    </script>
</body>
</html>