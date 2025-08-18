<?php
require_once 'db_config.php';
requireTeamLeader();

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$leaderName = $_SESSION['leader_name'];
$adminId = $_SESSION['admin_id'];

// Get interested leads that haven't been processed by this team leader yet
$interestedLeads = [];
$stmt = $conn->prepare("
    SELECT fcl.id, fcl.name, fcl.mobile_no, fcl.status, fcl.disposition, fcl.processed_at,
           p.product_name, b.original_filename,
           tla.id as action_id, tla.new_disposition, tla.action_date,
           c.caller_name as original_caller_name
    FROM final_call_logs fcl
    JOIN file_batches b ON fcl.batch_id = b.id
    JOIN products p ON b.product_code = p.product_code
    JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
    JOIN callers c ON fcl.finqy_id = c.finqy_id
    LEFT JOIN team_leader_actions tla ON fcl.id = tla.lead_id AND tla.leader_id = ?
    WHERE acm.admin_id = ? AND fcl.disposition = 'Interested'
    ORDER BY 
        CASE WHEN tla.id IS NULL THEN 0 ELSE 1 END,
        fcl.processed_at DESC
");
$stmt->bind_param("ss", $leaderId, $adminId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $interestedLeads[] = $row;
}
$stmt->close();

// Get available dispositions for team leader
$dispositions = [];
$stmt = $conn->prepare("SELECT * FROM team_leader_dispositions WHERE is_active = 1 ORDER BY disposition_name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $dispositions[] = $row;
}
$stmt->close();

// Get team leader stats
$stats = [];
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT fcl.id) as total_interested_leads,
        COUNT(DISTINCT tla.id) as processed_leads,
        COUNT(DISTINCT CASE WHEN tla.new_disposition = 'Interested - Proceed to Payment' THEN tla.id END) as payment_ready,
        COUNT(DISTINCT CASE WHEN DATE(tla.action_date) = CURDATE() THEN tla.id END) as today_processed
    FROM final_call_logs fcl
    JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
    LEFT JOIN team_leader_actions tla ON fcl.id = tla.lead_id AND tla.leader_id = ?
    WHERE acm.admin_id = ? AND fcl.disposition = 'Interested'
");
$stmt->bind_param("ss", $leaderId, $adminId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$pending_leads = $stats['total_interested_leads'] - $stats['processed_leads'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Leader Dashboard</title>
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
        .lead-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .lead-card.processed {
            border-left-color: #28a745;
            background-color: #f8f9fa;
        }
        .lead-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .action-btn {
            min-width: 120px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky">
                    <div class="text-center py-3 mb-4 border-bottom">
                        <i class="bi bi-person-badge-fill fs-2"></i>
                        <h5 class="mt-2">Team Leader</h5>
                        <small><?= htmlspecialchars($leaderName) ?></small>
                        <br><small class="text-muted"><?= $leaderId ?></small>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="team_leader_dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="team_leader_history.php">
                                <i class="bi bi-clock-history me-2"></i>Action History
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
                    <h1 class="h2"><i class="bi bi-star-fill me-2 text-warning"></i>Interested Leads Dashboard</h1>
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
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Total Interested</h6>
                                        <h2 class="mb-0"><?= $stats['total_interested_leads'] ?></h2>
                                    </div>
                                    <i class="bi bi-star-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Pending Review</h6>
                                        <h2 class="mb-0"><?= $pending_leads ?></h2>
                                    </div>
                                    <i class="bi bi-clock-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Payment Ready</h6>
                                        <h2 class="mb-0"><?= $stats['payment_ready'] ?></h2>
                                    </div>
                                    <i class="bi bi-credit-card-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Today Processed</h6>
                                        <h2 class="mb-0"><?= $stats['today_processed'] ?></h2>
                                    </div>
                                    <i class="bi bi-calendar-check-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Interested Leads -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-list-check me-2"></i>Interested Leads 
                            <span class="badge bg-primary"><?= count($interestedLeads) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($interestedLeads)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-star display-4 opacity-25"></i>
                                <p class="mt-3">No interested leads available for review.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($interestedLeads as $lead): ?>
                                    <div class="col-12">
                                        <div class="card lead-card <?= $lead['action_id'] ? 'processed' : '' ?>">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-6">
                                                        <h6 class="card-title mb-2">
                                                            <?= htmlspecialchars($lead['name']) ?>
                                                            <?php if ($lead['action_id']): ?>
                                                                <span class="badge bg-success ms-2">Processed</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Pending</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <div class="small text-muted">
                                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($lead['mobile_no']) ?><br>
                                                            <i class="bi bi-box me-1"></i><?= htmlspecialchars($lead['product_name']) ?><br>
                                                            <i class="bi bi-person me-1"></i>Called by: <?= htmlspecialchars($lead['original_caller_name']) ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="small">
                                                            <strong>Original Status:</strong> 
                                                            <span class="badge bg-info"><?= htmlspecialchars($lead['disposition']) ?></span><br>
                                                            <strong>Date:</strong> <?= date('d-M-Y H:i', strtotime($lead['processed_at'])) ?><br>
                                                            <?php if ($lead['action_id']): ?>
                                                                <strong>Your Action:</strong><br>
                                                                <span class="badge bg-secondary"><?= htmlspecialchars($lead['new_disposition']) ?></span><br>
                                                                <small class="text-muted"><?= date('d-M-Y H:i', strtotime($lead['action_date'])) ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 text-end">
                                                        <?php if (!$lead['action_id']): ?>
                                                            <button type="button" class="btn btn-primary action-btn" 
                                                                    onclick="openActionModal('<?= $lead['id'] ?>', '<?= addslashes($lead['name']) ?>', '<?= $lead['mobile_no'] ?>')">
                                                                <i class="bi bi-telephone-fill me-1"></i>Take Action
                                                            </button>
                                                        <?php elseif ($lead['new_disposition'] === 'Interested - Proceed to Payment'): ?>
                                                            <a href="payment_request.php?lead_id=<?= $lead['id'] ?>" class="btn btn-success action-btn">
                                                                <i class="bi bi-credit-card-fill me-1"></i>Payment
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-outline-secondary action-btn" disabled>
                                                                <i class="bi bi-check-circle me-1"></i>Completed
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-telephone-fill me-2"></i>Take Action on Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="process_team_leader_action.php">
                    <div class="modal-body">
                        <input type="hidden" name="lead_id" id="modalLeadId">
                        
                        <div class="alert alert-info">
                            <strong>Customer:</strong> <span id="modalCustomerName"></span><br>
                            <strong>Phone:</strong> <span id="modalPhone"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_disposition" class="form-label">Select Disposition</label>
                            <select name="new_disposition" id="new_disposition" class="form-select" required>
                                <option value="">Choose disposition...</option>
                                <?php foreach ($dispositions as $disp): ?>
                                    <option value="<?= htmlspecialchars($disp['disposition_name']) ?>">
                                        <?= htmlspecialchars($disp['disposition_name']) ?>
                                        <?php if ($disp['description']): ?>
                                            - <?= htmlspecialchars($disp['description']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks (Optional)</label>
                            <textarea name="remarks" id="remarks" class="form-control" rows="3" 
                                      placeholder="Add any additional notes about this call..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Submit Action
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openActionModal(leadId, customerName, phone) {
            document.getElementById('modalLeadId').value = leadId;
            document.getElementById('modalCustomerName').textContent = customerName;
            document.getElementById('modalPhone').textContent = phone;
            
            const modal = new bootstrap.Modal(document.getElementById('actionModal'));
            modal.show();
        }
        
        // Auto-refresh every 5 minutes
        setTimeout(() => location.reload(), 300000);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>