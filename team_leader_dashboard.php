<?php
require_once 'db_config.php';
require_once 'masking_utils.php';
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
        .view-btn {
            min-width: 120px;
            position: relative;
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
        .customer-name, .customer-mobile {
            font-family: monospace;
            font-weight: 600;
        }
        .customer-name.unmasked, .customer-mobile.unmasked {
            color: #28a745;
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
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
                                                            <span class="customer-name" data-lead-id="<?= $lead['id'] ?>"
                                                                  data-original="<?= htmlspecialchars($lead['name']) ?>">
                                                                <?= htmlspecialchars(getDisplayName($lead['name'], $lead['id'])) ?>
                                                            </span>
                                                            <?php if ($lead['action_id']): ?>
                                                                <span class="badge bg-success ms-2">Processed</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Pending</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <div class="small text-muted">
                                                            <i class="bi bi-telephone me-1"></i>
                                                            <span class="customer-mobile" data-lead-id="<?= $lead['id'] ?>"
                                                                  data-original="<?= htmlspecialchars($lead['mobile_no']) ?>">
                                                                <?= htmlspecialchars(getDisplayMobile($lead['mobile_no'], $lead['id'])) ?>
                                                            </span><br>
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
                                                            <div class="btn-group-vertical d-grid gap-2">
                                                                <button type="button" class="btn btn-info btn-sm view-btn" 
                                                                        data-lead-id="<?= $lead['id'] ?>" 
                                                                        data-bs-toggle="tooltip" title="Authenticate to view customer details">
                                                                    <i class="bi bi-eye-fill me-1"></i>View
                                                                    <span class="timer-display" style="display: none;"></span>
                                                                </button>
                                                                <button type="button" class="btn btn-primary action-btn" 
                                                                        onclick="openActionModal('<?= $lead['id'] ?>')">
                                                                    <i class="bi bi-telephone-fill me-1"></i>Take Action
                                                                </button>
                                                            </div>
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

    <script src="js/security-protection.js"></script>
    <script>
        let currentViewTimer = null;
        let currentUnmaskedLeadId = null;
        
        function openActionModal(leadId) {
            // Get current display values (masked or unmasked) from the DOM
            const nameElement = document.querySelector(`.customer-name[data-lead-id="${leadId}"]`);
            const mobileElement = document.querySelector(`.customer-mobile[data-lead-id="${leadId}"]`);
            
            const displayName = nameElement ? nameElement.textContent : 'Unknown';
            const displayMobile = mobileElement ? mobileElement.textContent : 'Unknown';
            
            document.getElementById('modalLeadId').value = leadId;
            document.getElementById('modalCustomerName').textContent = displayName;
            document.getElementById('modalPhone').textContent = displayMobile;
            
            const modal = new bootstrap.Modal(document.getElementById('actionModal'));
            modal.show();
        }
        
        // View button functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Handle view button clicks
            document.querySelectorAll('.view-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const leadId = this.getAttribute('data-lead-id');
                    
                    // If this lead is already unmasked, just refresh the timer
                    if (currentUnmaskedLeadId === leadId) {
                        showMessage('Data is already visible. Timer refreshed.', 'info');
                        return;
                    }
                    
                    // Open authentication modal
                    openViewAuthModal(leadId);
                });
            });
            
            // Handle view authentication
            document.getElementById('submitViewAuth').addEventListener('click', function() {
                const accessCode = document.getElementById('viewAccessCode').value.trim();
                const leadId = document.getElementById('viewAuthModal').getAttribute('data-lead-id');
                
                if (!accessCode) {
                    showViewAuthError('Please enter your access code');
                    return;
                }
                
                authenticateAndView(leadId, accessCode);
            });
            
            // Handle Enter key in access code field
            document.getElementById('viewAccessCode').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('submitViewAuth').click();
                }
            });
        });
        
        function openViewAuthModal(leadId) {
            const modal = document.getElementById('viewAuthModal');
            modal.setAttribute('data-lead-id', leadId);
            
            // Clear previous values
            document.getElementById('viewAccessCode').value = '';
            document.getElementById('viewAuthAlert').style.display = 'none';
            
            const viewModal = new bootstrap.Modal(modal);
            viewModal.show();
            
            // Focus on access code field after modal is shown
            modal.addEventListener('shown.bs.modal', function() {
                document.getElementById('viewAccessCode').focus();
            }, { once: true });
        }
        
        function authenticateAndView(leadId, accessCode) {
            const submitBtn = document.getElementById('submitViewAuth');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying...';
            submitBtn.disabled = true;
            
            fetch('team_leader_auth_view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    lead_id: leadId,
                    access_code: accessCode
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('viewAuthModal')).hide();
                    
                    // Unmask the data
                    unmaskLeadData(leadId, data.data.name, data.data.mobile);
                    
                    // Start timer
                    startUnmaskTimer(leadId, data.data.remaining_time);
                    
                    showMessage('Customer details visible for 1 minute', 'success');
                } else {
                    showViewAuthError(data.message);
                }
            })
            .catch(error => {
                showViewAuthError('An error occurred. Please try again.');
            })
            .finally(() => {
                // Restore button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        function unmaskLeadData(leadId, name, mobile) {
            // Clear any existing unmasked data
            maskAllData();
            
            // Set current unmasked lead
            currentUnmaskedLeadId = leadId;
            
            // Update display elements
            const nameElement = document.querySelector(`.customer-name[data-lead-id="${leadId}"]`);
            const mobileElement = document.querySelector(`.customer-mobile[data-lead-id="${leadId}"]`);
            const viewButton = document.querySelector(`.view-btn[data-lead-id="${leadId}"]`);
            
            if (nameElement) {
                nameElement.textContent = name;
                nameElement.classList.add('unmasked');
            }
            
            if (mobileElement) {
                mobileElement.textContent = mobile;
                mobileElement.classList.add('unmasked');
            }
            
            if (viewButton) {
                viewButton.classList.add('unmasked');
                viewButton.innerHTML = '<i class="bi bi-eye-slash-fill me-1"></i>Viewing<span class="timer-display ms-2"></span>';
            }
        }
        
        function maskAllData() {
            // Remove unmasked styling and restore masked data
            document.querySelectorAll('.customer-name.unmasked, .customer-mobile.unmasked').forEach(element => {
                const original = element.getAttribute('data-original');
                const leadId = element.getAttribute('data-lead-id');
                
                if (element.classList.contains('customer-name')) {
                    element.textContent = maskName(original);
                } else if (element.classList.contains('customer-mobile')) {
                    element.textContent = maskMobile(original);
                }
                
                element.classList.remove('unmasked');
            });
            
            // Reset view buttons
            document.querySelectorAll('.view-btn.unmasked').forEach(button => {
                button.classList.remove('unmasked');
                button.innerHTML = '<i class="bi bi-eye-fill me-1"></i>View<span class="timer-display" style="display: none;"></span>';
            });
            
            currentUnmaskedLeadId = null;
        }
        
        function startUnmaskTimer(leadId, duration) {
            // Clear any existing timer
            if (currentViewTimer) {
                clearInterval(currentViewTimer);
            }
            
            let remaining = duration;
            const viewButton = document.querySelector(`.view-btn[data-lead-id="${leadId}"]`);
            const timerDisplay = viewButton?.querySelector('.timer-display');
            
            if (timerDisplay) {
                timerDisplay.style.display = 'inline';
            }
            
            currentViewTimer = setInterval(() => {
                remaining--;
                
                if (timerDisplay) {
                    timerDisplay.textContent = `(${remaining}s)`;
                }
                
                if (remaining <= 0) {
                    clearInterval(currentViewTimer);
                    maskAllData();
                    showMessage('View timer expired. Data is now masked.', 'warning');
                }
            }, 1000);
        }
        
        function showViewAuthError(message) {
            const alert = document.getElementById('viewAuthAlert');
            alert.textContent = message;
            alert.style.display = 'block';
        }
        
        function showMessage(message, type) {
            // Create and show a toast message
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            toast.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Client-side masking functions (matching PHP)
        function maskName(name) {
            if (!name) return name;
            
            const words = name.trim().split(' ');
            return words.map(word => {
                if (word.length <= 2) return word;
                return word[0] + '*'.repeat(word.length - 2) + word.slice(-1);
            }).join(' ');
        }
        
        function maskMobile(mobile) {
            if (!mobile) return mobile;
            
            const digits = mobile.replace(/[^0-9]/g, '');
            if (digits.length < 4) return mobile;
            
            if (digits.length === 10) {
                return digits.substring(0, 2) + 'X'.repeat(6) + digits.substring(8);
            } else if (digits.length > 10) {
                const maskLength = digits.length - 4;
                return digits.substring(0, 2) + 'X'.repeat(maskLength) + digits.substring(digits.length - 2);
            } else {
                return digits[0] + 'X'.repeat(digits.length - 2) + digits.slice(-1);
            }
        }
        
        // Check if security is disabled for this session
        <?php if (!isset($_SESSION['security_disabled'])): ?>
        // Initialize security protection with relaxed settings for View functionality
        const securityProtection = new SecurityProtection({
            watermarkText: 'CONFIDENTIAL - TEAM LEADER PORTAL',
            userId: '<?= htmlspecialchars($leaderId) ?>',
            sessionId: '<?= session_id() ?>',
            logEndpoint: 'security_log.php',
            maxViolations: 8,
            enableBlurOnFocus: false, // Disable blur for better usability
            enableTabSwitchDetection: false, // Reduce false positives
            enableScreenRecordingDetection: false, // Reduce false positives
            enableDevToolsBlocking: false, // Disable to prevent View functionality issues
            violationCallback: function(violation) {
                if (violation.violationCount >= 8) {
                    alert('Multiple security violations detected. Please contact your administrator.');
                    setTimeout(() => {
                        window.location.href = 'logout.php?type=team_leader&reason=security_violation';
                    }, 3000);
                }
            }
        });
        <?php else: ?>
        // Security protection disabled for this session
        <?php endif; ?>
        
        // Enhanced session monitoring
        let sessionActive = true;
        let lastActivity = Date.now();
        
        // Track user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, () => {
                lastActivity = Date.now();
            }, { capture: true, passive: true });
        });
        
        // Check for inactivity
        setInterval(() => {
            const inactiveTime = Date.now() - lastActivity;
            if (inactiveTime > 1800000) { // 30 minutes
                alert('Session expired due to inactivity');
                window.location.href = 'logout.php?type=team_leader&reason=inactivity';
            }
        }, 60000); // Check every minute
        
        // Auto-refresh every 5 minutes
        setTimeout(() => location.reload(), 300000);
        
        // Prevent page unload without proper logout
        window.addEventListener('beforeunload', (e) => {
            if (sessionActive) {
                e.preventDefault();
                e.returnValue = 'Are you sure you want to leave? Always use the logout button for security.';
                return e.returnValue;
            }
        });
        
        // Mark session as properly ending when using logout link
        document.querySelector('a[href*="logout.php"]')?.addEventListener('click', () => {
            sessionActive = false;
        });
        
        // Clear timers and mask data on page unload
        window.addEventListener('beforeunload', () => {
            if (currentViewTimer) {
                clearInterval(currentViewTimer);
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>