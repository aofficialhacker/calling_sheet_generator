<?php
require_once 'db_config.php';
require_once 'masking_utils.php';
requireTeamLeader();

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$leaderName = $_SESSION['leader_name'];
$adminId = $_SESSION['admin_id'];

// Handle status updates
if ($_POST && isset($_POST['update_status'])) {
    $scheduleId = $_POST['schedule_id'];
    $newStatus = $_POST['new_status'];
    $remarks = trim($_POST['remarks']);
    
    $stmt = $conn->prepare("
        UPDATE lv_follow_up_schedules 
        SET status = ?, remarks = CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] Status: ', ?, IF(? != '', CONCAT(' - ', ?), ''))
        WHERE id = ? AND leader_id = ?
    ");
    $stmt->bind_param("ssssss", $newStatus, $newStatus, $remarks, $remarks, $scheduleId, $leaderId);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Follow-up status updated successfully!";
        $_SESSION['messageType'] = "success";
    } else {
        $_SESSION['message'] = "Error updating status.";
        $_SESSION['messageType'] = "danger";
    }
    $stmt->close();
}

// Handle rescheduling
if ($_POST && isset($_POST['reschedule'])) {
    $scheduleId = $_POST['schedule_id'];
    $newDate = $_POST['new_date'];
    $newTime = $_POST['new_time'];
    $rescheduleRemarks = trim($_POST['reschedule_remarks']);
    
    $newDatetime = $newDate . ' ' . $newTime . ':00';
    
    if (strtotime($newDatetime) > (time() + 60)) {
        $stmt = $conn->prepare("
            UPDATE lv_follow_up_schedules 
            SET follow_up_datetime = ?, 
                remarks = CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] Rescheduled to: ', ?, IF(? != '', CONCAT(' - ', ?), ''))
            WHERE id = ? AND leader_id = ?
        ");
        $stmt->bind_param("ssssss", $newDatetime, $newDatetime, $rescheduleRemarks, $rescheduleRemarks, $scheduleId, $leaderId);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Follow-up rescheduled successfully!";
            $_SESSION['messageType'] = "success";
        } else {
            $_SESSION['message'] = "Error rescheduling follow-up.";
            $_SESSION['messageType'] = "danger";
        }
        $stmt->close();
    } else {
        $_SESSION['message'] = "New follow-up time must be in the future.";
        $_SESSION['messageType'] = "danger";
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';

// Build WHERE clause based on filters
$whereConditions = ["fs.leader_id = ?"];
$params = [$leaderId];
$paramTypes = "s";

if ($statusFilter !== 'all') {
    $whereConditions[] = "fs.status = ?";
    $params[] = $statusFilter;
    $paramTypes .= "s";
}

if ($dateFilter !== 'all') {
    switch ($dateFilter) {
        case 'today':
            $whereConditions[] = "DATE(fs.follow_up_datetime) = CURDATE()";
            break;
        case 'tomorrow':
            $whereConditions[] = "DATE(fs.follow_up_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'week':
            $whereConditions[] = "fs.follow_up_datetime BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'overdue':
            $whereConditions[] = "fs.follow_up_datetime < NOW() AND fs.status = 'scheduled'";
            break;
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Get follow-up schedules with masked data
$followUps = [];
$stmt = $conn->prepare("
    SELECT fs.*, 
           fcl.name as customer_name, 
           fcl.mobile_no as customer_mobile,
           db.bucket_name,
           p.product_name,
           b.original_filename
    FROM lv_follow_up_schedules fs
    JOIN lv_final_call_logs fcl ON fs.lead_id = fcl.id
    JOIN lv_disposition_buckets db ON fs.bucket_id = db.id
    JOIN lv_file_batches b ON fcl.batch_id = b.id
    JOIN lv_products p ON b.product_code = p.product_code
    WHERE $whereClause
    ORDER BY fs.follow_up_datetime ASC
");
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Apply data masking to customer details
    $row['customer_name_masked'] = maskName($row['customer_name']);
    $row['customer_mobile_masked'] = maskMobile($row['customer_mobile']);
    $followUps[] = $row;
}
$stmt->close();

// Get summary statistics
$stats = [];
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_scheduled,
        COUNT(CASE WHEN status = 'scheduled' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
        COUNT(CASE WHEN status = 'scheduled' AND follow_up_datetime < NOW() THEN 1 END) as overdue,
        COUNT(CASE WHEN DATE(follow_up_datetime) = CURDATE() AND status = 'scheduled' THEN 1 END) as due_today,
        COUNT(CASE WHEN DATE(follow_up_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status = 'scheduled' THEN 1 END) as due_tomorrow
    FROM lv_follow_up_schedules 
    WHERE leader_id = ?
");
$stmt->bind_param("s", $leaderId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-up Calendar - Relationship Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { 
            min-height: 100vh; 
            background: linear-gradient(180deg, #6f42c1 0%, #5a2d91 100%); 
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
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            margin-bottom: 1rem;
        }
        .followup-card.overdue { border-left-color: #dc3545; }
        .followup-card.due-today { border-left-color: #ffc107; }
        .followup-card.completed { border-left-color: #28a745; opacity: 0.8; }
        .followup-card.cancelled { border-left-color: #6c757d; opacity: 0.6; }
        .status-badge.scheduled { background-color: #007bff; }
        .status-badge.completed { background-color: #28a745; }
        .status-badge.cancelled { background-color: #6c757d; }
        .status-badge.overdue { background-color: #dc3545; }
        .filter-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .customer-name, .customer-mobile {
            font-family: monospace;
            font-weight: 600;
        }
        .customer-name.unmasked, .customer-mobile.unmasked {
            color: #28a745;
            animation: pulse-green 2s infinite;
        }
        .view-btn.unmasked {
            background-color: #28a745 !important;
            border-color: #28a745 !important;
        }
        .view-btn.unmasked:hover {
            background-color: #218838 !important;
            border-color: #1e7e34 !important;
        }
        .timer-display {
            font-size: 0.8rem;
            font-weight: bold;
        }
        @keyframes pulse-green {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body data-role="team-leader">
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky">
                    <div class="text-center py-3 mb-4 border-bottom">
                        <i class="bi bi-person-badge-fill fs-2"></i>
                        <h5 class="mt-2">Relationship Manager</h5>
                        <small><?= htmlspecialchars($leaderName) ?></small>
                        <br><small class="text-muted"><?= $leaderId ?></small>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="team_leader_dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="team_leader_history.php">
                                <i class="bi bi-clock-history me-2"></i>Action History
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="follow_up_calendar.php">
                                <i class="bi bi-calendar-check me-2"></i>Follow-up Calendar
                            </a>
                        </li>
                        <li class="nav-item mt-4">
                            <a class="nav-link text-danger" href="logout.php?type=team_leader">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-calendar-check me-2 text-primary"></i>Follow-up Calendar</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-<?= $_SESSION['messageType'] ?> alert-dismissible fade show">
                        <?= htmlspecialchars($_SESSION['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['message'], $_SESSION['messageType']); ?>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-2 col-md-4">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?= $stats['total_scheduled'] ?></h3>
                                <small>Total Scheduled</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?= $stats['pending'] ?></h3>
                                <small>Pending</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?= $stats['overdue'] ?></h3>
                                <small>Overdue</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?= $stats['due_today'] ?></h3>
                                <small>Due Today</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?= $stats['due_tomorrow'] ?></h3>
                                <small>Due Tomorrow</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?= $stats['completed'] ?></h3>
                                <small>Completed</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card stat-card filter-card text-white mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Status Filter</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date Filter</label>
                                <select name="date" class="form-select">
                                    <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Dates</option>
                                    <option value="overdue" <?= $dateFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                    <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Due Today</option>
                                    <option value="tomorrow" <?= $dateFilter === 'tomorrow' ? 'selected' : '' ?>>Due Tomorrow</option>
                                    <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-light w-100">
                                    <i class="bi bi-funnel me-1"></i>Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Follow-ups List -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-calendar-event me-2"></i>Follow-up Schedule
                            <span class="badge bg-primary"><?= count($followUps) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($followUps)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-calendar-x display-4 opacity-25"></i>
                                <p class="mt-3">No follow-ups found matching the selected criteria.</p>
                                <a href="team_leader_dashboard.php" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>Schedule Follow-ups
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($followUps as $followUp): 
                                $isOverdue = strtotime($followUp['follow_up_datetime']) < time() && $followUp['status'] === 'scheduled';
                                $isDueToday = date('Y-m-d', strtotime($followUp['follow_up_datetime'])) === date('Y-m-d');
                                $cardClass = $followUp['status'];
                                if ($isOverdue) $cardClass = 'overdue';
                                elseif ($isDueToday && $followUp['status'] === 'scheduled') $cardClass = 'due-today';
                            ?>
                                <div class="card followup-card <?= $cardClass ?>">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-6">
                                                <h6 class="card-title mb-2">
                                                    <i class="bi bi-person-fill me-1"></i>
                                                    <span class="customer-name" data-follow-up-id="<?= $followUp['id'] ?>">
                                                        <?= htmlspecialchars($followUp['customer_name_masked']) ?>
                                                    </span>
                                                    <span class="badge status-badge <?= $followUp['status'] ?> ms-2">
                                                        <?= ucfirst($followUp['status']) ?>
                                                    </span>
                                                    <?php if ($isOverdue): ?>
                                                        <span class="badge bg-danger ms-1">OVERDUE</span>
                                                    <?php elseif ($isDueToday): ?>
                                                        <span class="badge bg-warning ms-1">DUE TODAY</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <div class="small mb-2">
                                                    <strong><i class="bi bi-telephone me-1"></i></strong> 
                                                    <span class="customer-mobile" data-follow-up-id="<?= $followUp['id'] ?>">
                                                        <?= htmlspecialchars($followUp['customer_mobile_masked']) ?>
                                                    </span><br>
                                                    <strong><i class="bi bi-calendar-event me-1"></i></strong> <?= date('d-M-Y H:i', strtotime($followUp['follow_up_datetime'])) ?><br>
                                                    <strong><i class="bi bi-tag me-1"></i></strong> <?= htmlspecialchars($followUp['disposition_name']) ?><br>
                                                    <strong><i class="bi bi-collection me-1"></i></strong> <?= htmlspecialchars($followUp['bucket_name']) ?>
                                                </div>
                                                <?php if ($followUp['remarks']): ?>
                                                    <small class="text-muted">
                                                        <i class="bi bi-chat-text me-1"></i>
                                                        <?= nl2br(htmlspecialchars($followUp['remarks'])) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-6 text-end">
                                                <div class="btn-group-vertical d-grid gap-2">
                                                    <button type="button" class="btn btn-info btn-sm view-btn" 
                                                            data-follow-up-id="<?= $followUp['id'] ?>" 
                                                            data-bs-toggle="tooltip" title="Authenticate to view customer details">
                                                        <i class="bi bi-eye-fill me-1"></i>View Details
                                                        <span class="timer-display" style="display: none;"></span>
                                                    </button>
                                                    <?php if ($followUp['status'] === 'scheduled'): ?>
                                                        <button class="btn btn-success btn-sm" 
                                                                onclick="updateStatus(<?= $followUp['id'] ?>, 'completed')">
                                                            <i class="bi bi-check-circle me-1"></i>Mark Completed
                                                        </button>
                                                        <button class="btn btn-warning btn-sm" 
                                                                onclick="openRescheduleModal(<?= htmlspecialchars(json_encode($followUp)) ?>)">
                                                            <i class="bi bi-calendar-plus me-1"></i>Reschedule
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-sm" 
                                                                onclick="updateStatus(<?= $followUp['id'] ?>, 'cancelled')">
                                                            <i class="bi bi-x-circle me-1"></i>Cancel
                                                        </button>
                                                    <?php else: ?>
                                                        <small class="text-muted">
                                                            Final Status: <strong><?= ucfirst($followUp['status']) ?></strong>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Follow-up Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="schedule_id" id="statusScheduleId">
                        <input type="hidden" name="new_status" id="newStatus">
                        
                        <div class="alert alert-info" id="statusMessage"></div>
                        
                        <div class="mb-3">
                            <label for="statusRemarks" class="form-label">Additional Remarks (Optional)</label>
                            <textarea name="remarks" id="statusRemarks" class="form-control" rows="3" 
                                      placeholder="Add any notes about this status update..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-primary" id="confirmStatusBtn">
                            Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="modal fade" id="rescheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reschedule Follow-up</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="schedule_id" id="rescheduleScheduleId">
                        
                        <div class="alert alert-warning">
                            <strong>Customer:</strong> <span id="rescheduleCustomerName"></span><br>
                            <strong>Current Time:</strong> <span id="rescheduleCurrentTime"></span>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label for="new_date" class="form-label">New Date</label>
                                <input type="date" name="new_date" id="new_date" class="form-control" 
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="new_time" class="form-label">New Time</label>
                                <input type="time" name="new_time" id="new_time" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <label for="reschedule_remarks" class="form-label">Reason for Rescheduling</label>
                            <textarea name="reschedule_remarks" id="reschedule_remarks" class="form-control" rows="3" 
                                      placeholder="Why is this follow-up being rescheduled?"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reschedule" class="btn btn-warning">
                            <i class="bi bi-calendar-plus me-1"></i>Reschedule
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Authentication Modal -->
    <div class="modal fade" id="viewAuthModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-lock-fill me-2"></i>Authenticate to View</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="viewAuthAlert" class="alert alert-danger" style="display: none;"></div>
                    
                    <div class="text-center mb-3">
                        <i class="bi bi-eye-fill text-info" style="font-size: 2rem;"></i>
                        <p class="mt-2 mb-0">Enter your access code to view customer details</p>
                        <small class="text-muted">Data will be visible for 1 minute only</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="viewAccessCode" class="form-label">
                            <i class="bi bi-key-fill me-1"></i>Access Code
                        </label>
                        <input type="text" id="viewAccessCode" class="form-control form-control-lg text-center" 
                               maxlength="6" pattern="[0-9A-Za-z]{6}" 
                               placeholder="6-digit code" style="text-transform: uppercase;">
                        <small class="form-text text-muted">
                            Same code used for login authentication
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="submitViewAuth" class="btn btn-info">
                        <i class="bi bi-unlock-fill me-1"></i>View Details
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/followup-notifications.js"></script>
    <script>
        // View authentication variables
        let currentViewTimer = null;
        let currentUnmaskedFollowUpId = null;
        function updateStatus(scheduleId, status) {
            document.getElementById('statusScheduleId').value = scheduleId;
            document.getElementById('newStatus').value = status;
            
            const statusMessage = document.getElementById('statusMessage');
            const confirmBtn = document.getElementById('confirmStatusBtn');
            
            if (status === 'completed') {
                statusMessage.innerHTML = '<i class="bi bi-check-circle me-2"></i>Mark this follow-up as <strong>completed</strong>?';
                statusMessage.className = 'alert alert-success';
                confirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Mark Completed';
                confirmBtn.className = 'btn btn-success';
            } else if (status === 'cancelled') {
                statusMessage.innerHTML = '<i class="bi bi-x-circle me-2"></i>Cancel this follow-up? This action cannot be undone.';
                statusMessage.className = 'alert alert-danger';
                confirmBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Cancel Follow-up';
                confirmBtn.className = 'btn btn-danger';
            }
            
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }
        
        function openRescheduleModal(followUp) {
            document.getElementById('rescheduleScheduleId').value = followUp.id;
            document.getElementById('rescheduleCustomerName').textContent = followUp.customer_name;
            document.getElementById('rescheduleCurrentTime').textContent = 
                new Date(followUp.follow_up_datetime).toLocaleString();
            
            // Set minimum date and time
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('new_date').min = today;
            document.getElementById('new_date').value = today;
            
            const now = new Date();
            now.setMinutes(now.getMinutes() + 2);
            document.getElementById('new_time').value = now.toTimeString().slice(0, 5);
            
            new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
        }
        
        // Handle date change for time validation in reschedule modal
        document.getElementById('new_date').addEventListener('change', function() {
            const newTime = document.getElementById('new_time');
            const today = new Date().toISOString().split('T')[0];
            
            if (this.value === today) {
                const now = new Date();
                now.setMinutes(now.getMinutes() + 2);
                const minTime = now.toTimeString().slice(0, 5);
                newTime.min = minTime;
                
                if (newTime.value && newTime.value < minTime) {
                    newTime.value = minTime;
                }
            } else {
                newTime.min = '';
            }
        });

        // Initialize tooltips and view functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Handle view button clicks
            document.querySelectorAll('.view-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const followUpId = this.getAttribute('data-follow-up-id');
                    
                    if (currentUnmaskedFollowUpId === followUpId) {
                        showMessage('Data is already visible. Timer refreshed.', 'info');
                        return;
                    }
                    
                    openViewAuthModal(followUpId);
                });
            });
            
            // Handle view authentication
            document.getElementById('submitViewAuth').addEventListener('click', function() {
                const accessCode = document.getElementById('viewAccessCode').value.trim();
                const followUpId = document.getElementById('viewAuthModal').getAttribute('data-follow-up-id');
                
                if (!accessCode) {
                    showViewAuthError('Please enter your access code');
                    return;
                }
                
                authenticateAndView(followUpId, accessCode);
            });
            
            // Handle Enter key in access code field
            document.getElementById('viewAccessCode').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('submitViewAuth').click();
                }
            });
        });

        function openViewAuthModal(followUpId) {
            document.getElementById('viewAuthModal').setAttribute('data-follow-up-id', followUpId);
            document.getElementById('viewAccessCode').value = '';
            document.getElementById('viewAuthAlert').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('viewAuthModal'));
            modal.show();
        }

        function showViewAuthError(message) {
            const alert = document.getElementById('viewAuthAlert');
            alert.textContent = message;
            alert.style.display = 'block';
        }

        async function authenticateAndView(followUpId, accessCode) {
            try {
                const response = await fetch('followup_auth_view.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'access_code=' + encodeURIComponent(accessCode) + '&follow_up_id=' + followUpId
                });

                const data = await response.json();
                
                if (data.success) {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('viewAuthModal')).hide();
                    
                    // Unmask data
                    unmaskFollowUpData(followUpId, data.customer_name, data.customer_mobile);
                    
                    // Start timer
                    startViewTimer(followUpId);
                    
                    showMessage('Customer details revealed for 1 minute', 'success');
                } else {
                    showViewAuthError(data.message || 'Authentication failed');
                }
            } catch (error) {
                console.error('Authentication error:', error);
                showViewAuthError('Connection error. Please try again.');
            }
        }

        function unmaskFollowUpData(followUpId, customerName, customerMobile) {
            const nameElement = document.querySelector(`.customer-name[data-follow-up-id="${followUpId}"]`);
            const mobileElement = document.querySelector(`.customer-mobile[data-follow-up-id="${followUpId}"]`);
            
            if (nameElement) {
                nameElement.textContent = customerName;
                nameElement.classList.add('unmasked');
            }
            
            if (mobileElement) {
                mobileElement.textContent = customerMobile;
                mobileElement.classList.add('unmasked');
            }
            
            // Update button state
            const viewBtn = document.querySelector(`.view-btn[data-follow-up-id="${followUpId}"]`);
            if (viewBtn) {
                viewBtn.classList.add('unmasked');
                viewBtn.innerHTML = '<i class="bi bi-eye-fill me-1"></i>Visible <span class="timer-display"></span>';
            }
            
            currentUnmaskedFollowUpId = followUpId;
        }

        function startViewTimer(followUpId) {
            if (currentViewTimer) {
                clearInterval(currentViewTimer);
            }
            
            let timeLeft = 60; // 1 minute in seconds
            const timerDisplay = document.querySelector(`.view-btn[data-follow-up-id="${followUpId}"] .timer-display`);
            
            if (timerDisplay) {
                timerDisplay.style.display = 'inline';
            }
            
            currentViewTimer = setInterval(() => {
                timeLeft--;
                
                if (timerDisplay) {
                    timerDisplay.textContent = `(${timeLeft}s)`;
                }
                
                if (timeLeft <= 0) {
                    clearInterval(currentViewTimer);
                    maskFollowUpData(followUpId);
                }
            }, 1000);
        }

        function maskFollowUpData(followUpId) {
            // Re-mask the data
            location.reload(); // Simple approach - reload page to restore masked state
        }

        function showMessage(message, type = 'info') {
            // Create toast notification
            const toastId = 'toast-' + Date.now();
            const toastHtml = `
                <div class="toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} border-0" 
                     id="${toastId}" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            let toastContainer = document.getElementById('toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'toast-container';
                toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
                toastContainer.style.zIndex = '1055';
                document.body.appendChild(toastContainer);
            }
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: 3000
            });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });
        }
    </script>
</body>
</html>