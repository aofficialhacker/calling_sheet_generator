<?php
require_once 'db_config.php';
require_once 'masking_utils.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Filter parameters
$filterLeader = $_GET['leader'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterBucket = $_GET['bucket'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterOverdue = $_GET['overdue'] ?? '';
$sortBy = $_GET['sort'] ?? 'follow_up_datetime';
$sortOrder = $_GET['order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Build query conditions
$conditions = ["tl.admin_id = ?"];
$params = [$adminId];
$paramTypes = "s";

if ($filterLeader) {
    $conditions[] = "fs.leader_id = ?";
    $params[] = $filterLeader;
    $paramTypes .= "s";
}

if ($filterStatus) {
    $conditions[] = "fs.status = ?";
    $params[] = $filterStatus;
    $paramTypes .= "s";
}

if ($filterBucket) {
    $conditions[] = "fs.bucket_id = ?";
    $params[] = $filterBucket;
    $paramTypes .= "i";
}

if ($filterDateFrom) {
    $conditions[] = "DATE(fs.follow_up_datetime) >= ?";
    $params[] = $filterDateFrom;
    $paramTypes .= "s";
}

if ($filterDateTo) {
    $conditions[] = "DATE(fs.follow_up_datetime) <= ?";
    $params[] = $filterDateTo;
    $paramTypes .= "s";
}

if ($filterOverdue === 'yes') {
    $conditions[] = "fs.status = 'scheduled' AND fs.follow_up_datetime < NOW()";
} elseif ($filterOverdue === 'no') {
    $conditions[] = "(fs.status != 'scheduled' OR fs.follow_up_datetime >= NOW())";
}

$whereClause = implode(" AND ", $conditions);

// Validate sort parameters
$allowedSorts = ['follow_up_datetime', 'completed_at', 'delay_minutes', 'leader_name', 'status'];
$sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'follow_up_datetime';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Main query to get follow-up history
$query = "
    SELECT 
        fs.*,
        tl.leader_name,
        tl.leader_id,
        db.bucket_name,
        fcl.name as customer_name,
        fcl.mobile_no as customer_mobile,
        p.product_name,
        b.original_filename,
        CASE 
            WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() 
            THEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW())
            ELSE NULL 
        END as current_overdue_minutes,
        CASE 
            WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN
                CASE 
                    WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 60 THEN 'Recently Overdue'
                    WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 1440 THEN 'Overdue (< 1 day)'
                    WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 10080 THEN 'Overdue (< 1 week)'
                    ELSE 'Severely Overdue (> 1 week)'
                END
            ELSE NULL
        END as overdue_severity
    FROM follow_up_schedules fs
    JOIN team_leaders tl ON fs.leader_id = tl.leader_id
    JOIN disposition_buckets db ON fs.bucket_id = db.id
    JOIN final_call_logs fcl ON fs.lead_id = fcl.id
    JOIN file_batches b ON fcl.batch_id = b.id
    JOIN products p ON b.product_code = p.product_code
    WHERE $whereClause
    ORDER BY fs.$sortBy $sortOrder
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    // Query preparation failed
    $followups = [];
    $totalCount = 0;
    $totalPages = 0;
} else {
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $followups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
}

// Get total count for pagination
$countQuery = "
    SELECT COUNT(*) as total
    FROM follow_up_schedules fs
    JOIN team_leaders tl ON fs.leader_id = tl.leader_id
    JOIN disposition_buckets db ON fs.bucket_id = db.id
    JOIN final_call_logs fcl ON fs.lead_id = fcl.id
    JOIN file_batches b ON fcl.batch_id = b.id
    JOIN products p ON b.product_code = p.product_code
    WHERE $whereClause
";

if (!isset($totalCount)) {
    $stmt = $conn->prepare($countQuery);
    if ($stmt === false) {
        $totalCount = 0;
        $totalPages = 0;
    } else {
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalCount = $result ? $result->fetch_assoc()['total'] : 0;
        $stmt->close();
        $totalPages = ceil($totalCount / $limit);
    }
}

// Get team leaders for filter dropdown
$teamLeaders = [];
$leadersQuery = "
    SELECT tl.leader_id, tl.leader_name 
    FROM team_leaders tl 
    WHERE tl.admin_id = ? AND tl.is_active = 1 
    ORDER BY tl.leader_name
";
$stmt = $conn->prepare($leadersQuery);
if ($stmt) {
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $teamLeaders[] = $row;
        }
    }
    $stmt->close();
}

// Get buckets for filter dropdown
$buckets = [];
$bucketsQuery = "
    SELECT id, bucket_name 
    FROM disposition_buckets 
    WHERE is_active = 1 
    ORDER BY bucket_name
";
$stmt = $conn->prepare($bucketsQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $buckets[] = $row;
        }
    }
    $stmt->close();
}

// Get summary statistics with error handling
$stats = [];
$statsQuery = "
    SELECT 
        COUNT(*) as total_followups,
        COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN fs.status = 'cancelled' THEN 1 END) as cancelled_count,
        COUNT(CASE WHEN fs.status = 'scheduled' THEN 1 END) as scheduled_count,
        COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) as overdue_count,
        ROUND(AVG(CASE WHEN fs.delay_minutes IS NOT NULL THEN fs.delay_minutes END), 2) as avg_delay_minutes,
        COUNT(DISTINCT fs.leader_id) as active_leaders,
        COUNT(CASE WHEN DATE(fs.follow_up_datetime) = CURDATE() AND fs.status = 'scheduled' THEN 1 END) as due_today,
        ROUND((COUNT(CASE WHEN fs.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as completion_rate,
        ROUND((COUNT(CASE WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as overdue_rate
    FROM follow_up_schedules fs
    JOIN team_leaders tl ON fs.leader_id = tl.leader_id
    WHERE tl.admin_id = ?
";

$stmt = $conn->prepare($statsQuery);
if ($stmt === false) {
    // Query preparation failed - set default stats
    $stats = [
        'total_followups' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0,
        'scheduled_count' => 0,
        'overdue_count' => 0,
        'avg_delay_minutes' => 0,
        'active_leaders' => 0,
        'due_today' => 0,
        'completion_rate' => 0,
        'overdue_rate' => 0
    ];
} else {
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $stats = $result->fetch_assoc();
    } else {
        // Default empty stats
        $stats = [
            'total_followups' => 0,
            'completed_count' => 0,
            'cancelled_count' => 0,
            'scheduled_count' => 0,
            'overdue_count' => 0,
            'avg_delay_minutes' => 0,
            'active_leaders' => 0,
            'due_today' => 0,
            'completion_rate' => 0,
            'overdue_rate' => 0
        ];
    }
    $stmt->close();
}

$conn->close();

// Helper function to format delay minutes
function formatDelay($minutes) {
    if ($minutes === null) return 'N/A';
    
    if ($minutes < 0) {
        return '<span class="text-success">-' . abs($minutes) . 'm (Early)</span>';
    } elseif ($minutes == 0) {
        return '<span class="text-success">On Time</span>';
    } else {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        $delayText = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";
        
        if ($minutes <= 30) {
            return '<span class="text-warning">+' . $delayText . '</span>';
        } elseif ($minutes <= 60) {
            return '<span class="text-danger">+' . $delayText . '</span>';
        } else {
            return '<span class="text-danger fw-bold">+' . $delayText . ' (Late)</span>';
        }
    }
}

// Helper function to get status badge class
function getStatusBadgeClass($status, $isOverdue = false) {
    if ($isOverdue) return 'bg-danger';
    
    switch ($status) {
        case 'completed': return 'bg-success';
        case 'cancelled': return 'bg-secondary';
        case 'scheduled': return 'bg-primary';
        default: return 'bg-secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relationship Manager Follow-up History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { 
            min-height: 100vh; 
            background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); 
            color: white; 
        }
        .sidebar .nav-link { 
            color: rgba(255,255,255,0.8); 
            padding: 0.75rem 1rem; 
            margin: 0.25rem 0; 
            border-radius: 0.5rem; 
            transition: all 0.3s; 
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            color: white; 
            background-color: rgba(255,255,255,0.1); 
        }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .followup-card {
            transition: all 0.3s;
            margin-bottom: 1rem;
        }
        .followup-card.overdue { 
            border-left: 4px solid #dc3545; 
            background-color: #fff5f5;
        }
        .followup-card.due-today { 
            border-left: 4px solid #ffc107; 
            background-color: #fffef5;
        }
        .followup-card.completed { 
            border-left: 4px solid #28a745; 
            background-color: #f8fff8;
        }
        .followup-card.cancelled { 
            border-left: 4px solid #6c757d; 
            opacity: 0.8;
        }
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .table th {
            background-color: #f8f9fa;
            border-top: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,0.05);
        }
        .customer-details {
            font-family: monospace;
            font-size: 0.9rem;
        }
        .sort-link {
            color: inherit;
            text-decoration: none;
        }
        .sort-link:hover {
            color: #0056b3;
            text-decoration: none;
        }
        .sort-active {
            background-color: #e3f2fd;
        }
        .overdue-severe { animation: pulse-red 2s infinite; }
        @keyframes pulse-red {
            0% { background-color: #fff5f5; }
            50% { background-color: #ffe6e6; }
            100% { background-color: #fff5f5; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-calendar-week me-2 text-primary"></i>Relationship Manager Follow-up History
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-primary" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="exportData()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center py-3">
                                <i class="bi bi-calendar-event fs-4"></i>
                                <h4 class="mb-0 mt-2"><?= number_format($stats['total_followups']) ?></h4>
                                <small>Total Follow-ups</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center py-3">
                                <i class="bi bi-check-circle-fill fs-4"></i>
                                <h4 class="mb-0 mt-2"><?= number_format($stats['completed_count']) ?></h4>
                                <small>Completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body text-center py-3">
                                <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                                <h4 class="mb-0 mt-2"><?= number_format($stats['overdue_count']) ?></h4>
                                <small>Overdue</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="card stat-card bg-warning text-dark">
                            <div class="card-body text-center py-3">
                                <i class="bi bi-clock-fill fs-4"></i>
                                <h4 class="mb-0 mt-2"><?= $stats['avg_delay_minutes'] ?? '0' ?>m</h4>
                                <small>Avg Delay</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body text-center py-3">
                                <i class="bi bi-percent fs-4"></i>
                                <h4 class="mb-0 mt-2"><?= $stats['completion_rate'] ?? '0' ?>%</h4>
                                <small>Completion Rate</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body text-center py-3">
                                <i class="bi bi-people-fill fs-4"></i>
                                <h4 class="mb-0 mt-2"><?= number_format($stats['active_leaders']) ?></h4>
                                <small>Active Leaders</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <h6 class="card-title"><i class="bi bi-funnel me-2"></i>Filter Follow-ups</h6>
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Relationship Manager</label>
                                <select name="leader" class="form-select form-select-sm">
                                    <option value="">All Leaders</option>
                                    <?php foreach ($teamLeaders as $leader): ?>
                                        <option value="<?= $leader['leader_id'] ?>" <?= $filterLeader === $leader['leader_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($leader['leader_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <option value="scheduled" <?= $filterStatus === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                    <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Bucket</label>
                                <select name="bucket" class="form-select form-select-sm">
                                    <option value="">All Buckets</option>
                                    <?php foreach ($buckets as $bucket): ?>
                                        <option value="<?= $bucket['id'] ?>" <?= $filterBucket == $bucket['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($bucket['bucket_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">From</label>
                                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDateFrom) ?>">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">To</label>
                                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDateTo) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Overdue</label>
                                <select name="overdue" class="form-select form-select-sm">
                                    <option value="">All</option>
                                    <option value="yes" <?= $filterOverdue === 'yes' ? 'selected' : '' ?>>Overdue Only</option>
                                    <option value="no" <?= $filterOverdue === 'no' ? 'selected' : '' ?>>Not Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-1">
                                    <button type="submit" class="btn btn-light btn-sm flex-fill">
                                        <i class="bi bi-search"></i> Filter
                                    </button>
                                    <a href="admin_tl_followup_history.php" class="btn btn-outline-light btn-sm">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Follow-ups List -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-ul me-2"></i>Follow-up History
                                <span class="badge bg-primary"><?= number_format($totalCount) ?></span>
                            </h5>
                            <small class="text-muted">
                                Page <?= $page ?> of <?= $totalPages ?> 
                                (<?= number_format($totalCount) ?> records)
                            </small>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($followups)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-calendar-x display-4 opacity-25"></i>
                                <p class="mt-3 text-muted">No follow-ups found matching your criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'follow_up_datetime', 'order' => $sortBy === 'follow_up_datetime' && $sortOrder === 'DESC' ? 'ASC' : 'DESC'])) ?>" 
                                                   class="sort-link <?= $sortBy === 'follow_up_datetime' ? 'sort-active' : '' ?>">
                                                    Scheduled Time 
                                                    <?php if ($sortBy === 'follow_up_datetime'): ?>
                                                        <i class="bi bi-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'leader_name', 'order' => $sortBy === 'leader_name' && $sortOrder === 'DESC' ? 'ASC' : 'DESC'])) ?>" 
                                                   class="sort-link <?= $sortBy === 'leader_name' ? 'sort-active' : '' ?>">
                                                    Relationship Manager
                                                    <?php if ($sortBy === 'leader_name'): ?>
                                                        <i class="bi bi-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Customer Details</th>
                                            <th>Disposition & Bucket</th>
                                            <th>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'status', 'order' => $sortBy === 'status' && $sortOrder === 'DESC' ? 'ASC' : 'DESC'])) ?>" 
                                                   class="sort-link <?= $sortBy === 'status' ? 'sort-active' : '' ?>">
                                                    Status
                                                    <?php if ($sortBy === 'status'): ?>
                                                        <i class="bi bi-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'delay_minutes', 'order' => $sortBy === 'delay_minutes' && $sortOrder === 'DESC' ? 'ASC' : 'DESC'])) ?>" 
                                                   class="sort-link <?= $sortBy === 'delay_minutes' ? 'sort-active' : '' ?>">
                                                    Delay
                                                    <?php if ($sortBy === 'delay_minutes'): ?>
                                                        <i class="bi bi-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($followups as $followup): 
                                            $isOverdue = $followup['status'] === 'scheduled' && strtotime($followup['follow_up_datetime']) < time();
                                            $isSeverelyOverdue = $isOverdue && $followup['current_overdue_minutes'] > 10080; // > 1 week
                                            $rowClass = '';
                                            if ($isSeverelyOverdue) $rowClass = 'overdue-severe';
                                            elseif ($isOverdue) $rowClass = 'table-danger';
                                            elseif ($followup['status'] === 'completed') $rowClass = 'table-success';
                                        ?>
                                            <tr class="<?= $rowClass ?>">
                                                <td>
                                                    <div class="fw-bold"><?= date('d-M-Y', strtotime($followup['follow_up_datetime'])) ?></div>
                                                    <small class="text-muted"><?= date('H:i', strtotime($followup['follow_up_datetime'])) ?></small>
                                                    <?php if ($followup['completed_at']): ?>
                                                        <br><small class="text-success">Completed: <?= date('d-M H:i', strtotime($followup['completed_at'])) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?= htmlspecialchars($followup['leader_name']) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($followup['leader_id']) ?></small>
                                                </td>
                                                <td>
                                                    <div class="customer-details">
                                                        <div class="fw-bold"><?= htmlspecialchars(maskName($followup['customer_name'])) ?></div>
                                                        <small class="text-muted"><?= htmlspecialchars(maskMobile($followup['customer_mobile'])) ?></small>
                                                        <br><small class="text-muted"><?= htmlspecialchars($followup['product_name']) ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?= htmlspecialchars($followup['disposition_name']) ?></span>
                                                    <br><small class="text-muted"><?= htmlspecialchars($followup['bucket_name']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?= getStatusBadgeClass($followup['status'], $isOverdue) ?>">
                                                        <?php if ($isOverdue): ?>
                                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>OVERDUE
                                                        <?php else: ?>
                                                            <?= ucfirst($followup['status']) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($followup['overdue_severity']): ?>
                                                        <br><small class="text-danger"><?= htmlspecialchars($followup['overdue_severity']) ?></small>
                                                        <br><small class="text-muted"><?= $followup['current_overdue_minutes'] ?>m overdue</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= formatDelay($followup['delay_minutes']) ?>
                                                    <?php if ($followup['current_overdue_minutes'] && $followup['status'] === 'scheduled'): ?>
                                                        <br><small class="text-danger">Currently: +<?= $followup['current_overdue_minutes'] ?>m</small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($followup['remarks']): ?>
                                                <tr class="<?= $rowClass ?>">
                                                    <td></td>
                                                    <td colspan="5">
                                                        <small class="text-muted">
                                                            <i class="bi bi-chat-text me-1"></i>
                                                            <strong>Remarks:</strong> <?= nl2br(htmlspecialchars($followup['remarks'])) ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="card-footer bg-white">
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                                        <i class="bi bi-chevron-left"></i> Previous
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $totalPages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                                        Next <i class="bi bi-chevron-right"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 2 minutes to update overdue status
        setInterval(() => {
            if (document.hidden === false) { // Only refresh if page is visible
                location.reload();
            }
        }, 120000);

        // Export functionality
        function exportData() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.open('ajax_export_followup_history.php?' + params.toString(), '_blank');
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>