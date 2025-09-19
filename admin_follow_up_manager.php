<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Get filter parameter
$filter = $_GET['filter'] ?? 'today';

// Build query based on filter
$whereCondition = '';
$params = [$adminId];
$types = 's';

switch ($filter) {
    case 'overdue':
        $whereCondition = 'AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE()';
        break;
    case 'today':
        $whereCondition = 'AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE()';
        break;
    case 'tomorrow':
        $whereCondition = 'AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
        break;
    case 'week':
        $whereCondition = 'AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        break;
    default:
        $whereCondition = 'AND DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) >= CURDATE()';
}

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
        fcl.remarks,
        DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) as follow_up_date,
        fb.batch_name,
        c.caller_name,
        DATEDIFF(DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY), CURDATE()) as days_from_now
    FROM lv_final_call_logs fcl
    JOIN lv_file_batches fb ON fcl.batch_id = fb.id
    LEFT JOIN lv_callers c ON fcl.finqy_id = c.finqy_id
    WHERE fb.admin_id = ?
    AND fcl.follow_day IS NOT NULL 
    AND fcl.follow_day > 0
    AND fcl.processed_at IS NOT NULL
    $whereCondition
    ORDER BY DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) ASC, fcl.follow_slot ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$followups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get summary counts
$summaryQuery = "
    SELECT 
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) < CURDATE() THEN 1 END) as overdue,
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = CURDATE() THEN 1 END) as today,
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 END) as tomorrow,
        COUNT(CASE WHEN DATE_ADD(fcl.processed_at, INTERVAL fcl.follow_day DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week
    FROM lv_final_call_logs fcl
    JOIN lv_file_batches fb ON fcl.batch_id = fb.id
    WHERE fb.admin_id = ?
    AND fcl.follow_day IS NOT NULL 
    AND fcl.follow_day > 0
    AND fcl.processed_at IS NOT NULL
";

$stmt = $conn->prepare($summaryQuery);
$stmt->bind_param('s', $adminId);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-up Manager - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <style>
        .overdue-row { background-color: #fff2f2; }
        .today-row { background-color: #fff8e7; }
        .upcoming-row { background-color: #f0f8ff; }
        .filter-tabs .nav-link.active { background-color: #0d6efd; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-calendar-check me-2"></i>Follow-up Manager</h1>
                    <a href="admin_dashboard.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                    </a>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-triangle-fill text-danger fs-2"></i>
                                <div class="h4 text-danger"><?= $summary['overdue'] ?></div>
                                <div>Overdue</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar-check-fill text-warning fs-2"></i>
                                <div class="h4 text-warning"><?= $summary['today'] ?></div>
                                <div>Today</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar-plus text-info fs-2"></i>
                                <div class="h4 text-info"><?= $summary['tomorrow'] ?></div>
                                <div>Tomorrow</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar3 text-success fs-2"></i>
                                <div class="h4 text-success"><?= $summary['this_week'] ?></div>
                                <div>This Week</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <ul class="nav nav-tabs mb-4 filter-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?= $filter === 'overdue' ? 'active' : '' ?>" 
                           href="?filter=overdue">
                            <i class="bi bi-exclamation-triangle me-1"></i>Overdue (<?= $summary['overdue'] ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $filter === 'today' ? 'active' : '' ?>" 
                           href="?filter=today">
                            <i class="bi bi-calendar-check me-1"></i>Today (<?= $summary['today'] ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $filter === 'tomorrow' ? 'active' : '' ?>" 
                           href="?filter=tomorrow">
                            <i class="bi bi-calendar-plus me-1"></i>Tomorrow (<?= $summary['tomorrow'] ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $filter === 'week' ? 'active' : '' ?>" 
                           href="?filter=week">
                            <i class="bi bi-calendar3 me-1"></i>This Week (<?= $summary['this_week'] ?>)
                        </a>
                    </li>
                </ul>

                <!-- Action Buttons -->
                <?php if ($filter === 'overdue' && count($followups) > 0): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-warning">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <div class="flex-grow-1">
                                    <strong>Overdue Follow-ups Detected!</strong>
                                    These follow-ups need immediate attention.
                                </div>
                                <button class="btn btn-danger btn-sm" onclick="redistributeOverdueFollowups()">
                                    <i class="bi bi-arrow-repeat me-1"></i>Redistribute All
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Follow-ups Table -->
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-list-check me-2"></i>Follow-ups 
                            <?php 
                            $filterLabels = [
                                'overdue' => 'Overdue',
                                'today' => 'Due Today', 
                                'tomorrow' => 'Due Tomorrow',
                                'week' => 'Due This Week'
                            ];
                            echo '(' . ($filterLabels[$filter] ?? 'All') . ')';
                            ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($followups)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-calendar-check fs-1 text-muted"></i>
                            <h5 class="text-muted mt-2">No follow-ups found</h5>
                            <p class="text-muted">No follow-ups match the selected filter criteria.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th>Original Caller</th>
                                        <th>Batch</th>
                                        <th>Disposition</th>
                                        <th>Follow-up Date</th>
                                        <th>Slot</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($followups as $followup): 
                                        $rowClass = '';
                                        $statusBadge = '';
                                        $daysFromNow = (int)$followup['days_from_now'];
                                        
                                        if ($daysFromNow < 0) {
                                            $rowClass = 'overdue-row';
                                            $statusBadge = '<span class="badge bg-danger">Overdue (' . abs($daysFromNow) . ' days)</span>';
                                        } elseif ($daysFromNow === 0) {
                                            $rowClass = 'today-row';
                                            $statusBadge = '<span class="badge bg-warning">Due Today</span>';
                                        } else {
                                            $rowClass = 'upcoming-row';
                                            $statusBadge = '<span class="badge bg-info">Due in ' . $daysFromNow . ' days</span>';
                                        }
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($followup['name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="font-monospace"><?= htmlspecialchars($followup['mobile_no']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($followup['caller_name'] ?? 'Unknown') ?>
                                            <?php if ($followup['finqy_id']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($followup['finqy_id']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($followup['batch_name']) ?></td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($followup['disposition']) ?></span>
                                        </td>
                                        <td>
                                            <?= date('d-M-Y', strtotime($followup['follow_up_date'])) ?>
                                        </td>
                                        <td>
                                            Slot <?= $followup['follow_slot'] ?? '1' ?>
                                        </td>
                                        <td>
                                            <?= $statusBadge ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary btn-sm" 
                                                        onclick="viewDetails('<?= $followup['id'] ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-warning btn-sm" 
                                                        onclick="reassignFollowup('<?= $followup['id'] ?>')">
                                                    <i class="bi bi-person-plus"></i>
                                                </button>
                                                <?php if ($daysFromNow < 0): ?>
                                                <button class="btn btn-outline-danger btn-sm" 
                                                        onclick="markAsUrgent('<?= $followup['id'] ?>')">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function redistributeOverdueFollowups() {
            if (!confirm('Are you sure you want to redistribute all overdue follow-ups to available telecallers?')) {
                return;
            }
            
            fetch('ajax_redistribute_followups.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'redistribute_overdue'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Successfully redistributed ' + data.count + ' overdue follow-ups!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while redistributing follow-ups.');
            });
        }

        function viewDetails(followupId) {
            // Open follow-up details in a modal or new window
            alert('View details for follow-up ID: ' + followupId);
            // Implementation depends on your requirements
        }

        function reassignFollowup(followupId) {
            // Show modal to reassign to different telecaller
            alert('Reassign follow-up ID: ' + followupId);
            // Implementation depends on your requirements
        }

        function markAsUrgent(followupId) {
            // Mark as urgent priority
            alert('Mark as urgent: ' + followupId);
            // Implementation depends on your requirements
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>