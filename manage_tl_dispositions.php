<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();
$superadminId = $_SESSION['superadmin_id'];
$message = '';
$messageType = '';

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_disposition'])) {
        $dispositionName = trim($_POST['disposition_name']);
        $description = trim($_POST['description']);
        
        // Check if disposition already exists
        $stmt = $conn->prepare("SELECT id FROM team_leader_dispositions WHERE disposition_name = ?");
        $stmt->bind_param("s", $dispositionName);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $message = "Disposition with this name already exists.";
            $messageType = "danger";
        } else {
            // Create new disposition
            $stmt = $conn->prepare("INSERT INTO team_leader_dispositions (disposition_name, description, created_by) VALUES (?, ?, ?)");
            $createdBy = 'SUPER'; // Use 'SUPER' for superadmin created dispositions
            $stmt->bind_param("sss", $dispositionName, $description, $createdBy);
            
            if ($stmt->execute()) {
                $message = "Team Leader disposition created successfully!";
                $messageType = "success";
            } else {
                $message = "Error creating disposition: " . $stmt->error;
                $messageType = "danger";
            }
        }
        $stmt->close();
    }
    
    if (isset($_POST['update_disposition'])) {
        $dispositionId = $_POST['disposition_id'];
        $dispositionName = trim($_POST['disposition_name']);
        $description = trim($_POST['description']);
        
        $stmt = $conn->prepare("UPDATE team_leader_dispositions SET disposition_name = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $dispositionName, $description, $dispositionId);
        
        if ($stmt->execute()) {
            $message = "Disposition updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating disposition: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
    
    if (isset($_POST['toggle_status'])) {
        $dispositionId = $_POST['disposition_id'];
        $newStatus = $_POST['new_status'];
        
        $stmt = $conn->prepare("UPDATE team_leader_dispositions SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $dispositionId);
        
        if ($stmt->execute()) {
            $message = $newStatus ? "Disposition activated successfully!" : "Disposition deactivated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating disposition status.";
            $messageType = "danger";
        }
        $stmt->close();
    }
    
    if (isset($_POST['delete_disposition'])) {
        $dispositionId = $_POST['disposition_id'];
        
        // Check if disposition is being used
        $stmt = $conn->prepare("SELECT COUNT(*) as usage_count FROM team_leader_actions WHERE new_disposition = (SELECT disposition_name FROM team_leader_dispositions WHERE id = ?)");
        $stmt->bind_param("i", $dispositionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $usage = $result->fetch_assoc();
        $stmt->close();
        
        if ($usage['usage_count'] > 0) {
            $message = "Cannot delete disposition: it is being used in " . $usage['usage_count'] . " team leader actions.";
            $messageType = "danger";
        } else {
            $stmt = $conn->prepare("DELETE FROM team_leader_dispositions WHERE id = ?");
            $stmt->bind_param("i", $dispositionId);
            
            if ($stmt->execute()) {
                $message = "Team Leader disposition deleted successfully!";
                $messageType = "success";
            } else {
                $message = "Error deleting disposition: " . $stmt->error;
                $messageType = "danger";
            }
            $stmt->close();
        }
    }
}

// Get all dispositions
$dispositions = [];
$stmt = $conn->prepare("
    SELECT d.*, 
           (SELECT COUNT(*) FROM team_leader_actions WHERE new_disposition = d.disposition_name) as usage_count
    FROM team_leader_dispositions d
    ORDER BY d.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $dispositions[] = $row;
}
$stmt->close();

// Get usage statistics
$stats = [];
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_dispositions,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_dispositions,
        (SELECT COUNT(*) FROM team_leader_actions) as total_actions_taken
    FROM team_leader_dispositions
");
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
    <title>Team Leader Dispositions Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .disposition-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .disposition-card.inactive {
            border-left-color: #6c757d;
            background-color: #f8f9fa;
            opacity: 0.7;
        }
        .usage-badge {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-tags-fill me-2"></i>Team Leader Dispositions</h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-4 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Total Dispositions</h6>
                                        <h2 class="mb-0"><?= $stats['total_dispositions'] ?></h2>
                                    </div>
                                    <i class="bi bi-tags-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Active Dispositions</h6>
                                        <h2 class="mb-0"><?= $stats['active_dispositions'] ?></h2>
                                    </div>
                                    <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Actions Taken</h6>
                                        <h2 class="mb-0"><?= number_format($stats['total_actions_taken']) ?></h2>
                                    </div>
                                    <i class="bi bi-activity fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Create Disposition Form -->
                <div class="card stat-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle-fill me-2"></i>Create New Team Leader Disposition</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-4">
                                <label for="disposition_name" class="form-label">Disposition Name</label>
                                <input type="text" name="disposition_name" id="disposition_name" class="form-control" required 
                                       placeholder="e.g., Follow Up Required">
                            </div>
                            <div class="col-md-6">
                                <label for="description" class="form-label">Description</label>
                                <input type="text" name="description" id="description" class="form-control" 
                                       placeholder="Brief description of when to use this disposition">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" name="create_disposition" class="btn btn-primary w-100">
                                    <i class="bi bi-plus-circle me-1"></i>Create
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Existing Dispositions -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul me-2"></i>All Team Leader Dispositions
                            <span class="badge bg-primary"><?= count($dispositions) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($dispositions)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-tags display-4 opacity-25"></i>
                                <p class="mt-3">No dispositions created yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($dispositions as $disposition): ?>
                                    <div class="col-12">
                                        <div class="card disposition-card <?= $disposition['is_active'] ? '' : 'inactive' ?>">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-6">
                                                        <h6 class="card-title mb-2">
                                                            <?= htmlspecialchars($disposition['disposition_name']) ?>
                                                            <?php if ($disposition['is_active']): ?>
                                                                <span class="badge bg-success ms-2">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary ms-2">Inactive</span>
                                                            <?php endif; ?>
                                                            <span class="badge bg-info usage-badge ms-1">
                                                                <?= $disposition['usage_count'] ?> uses
                                                            </span>
                                                        </h6>
                                                        <p class="text-muted mb-0">
                                                            <?= $disposition['description'] ? htmlspecialchars($disposition['description']) : 'No description provided' ?>
                                                        </p>
                                                        <small class="text-muted">
                                                            Created: <?= date('d-M-Y H:i', strtotime($disposition['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <div class="col-md-6 text-end">
                                                        <div class="btn-group">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editDisposition(<?= $disposition['id'] ?>, '<?= addslashes($disposition['disposition_name']) ?>', '<?= addslashes($disposition['description']) ?>')">
                                                                <i class="bi bi-pencil"></i> Edit
                                                            </button>
                                                            
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="disposition_id" value="<?= $disposition['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $disposition['is_active'] ? 0 : 1 ?>">
                                                                <button type="submit" name="toggle_status" 
                                                                        class="btn btn-sm btn-<?= $disposition['is_active'] ? 'warning' : 'success' ?>"
                                                                        onclick="return confirm('Are you sure you want to <?= $disposition['is_active'] ? 'deactivate' : 'activate' ?> this disposition?')">
                                                                    <i class="bi bi-<?= $disposition['is_active'] ? 'pause' : 'play' ?>-fill"></i> 
                                                                    <?= $disposition['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                                </button>
                                                            </form>
                                                            
                                                            <button type="button" class="btn btn-sm btn-danger" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#deleteTLDispositionModal"
                                                                    data-id="<?= $disposition['id'] ?>"
                                                                    data-name="<?= htmlspecialchars($disposition['disposition_name']) ?>"
                                                                    data-description="<?= htmlspecialchars($disposition['description']) ?>"
                                                                    data-usage="<?= $disposition['usage_count'] ?>">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </div>
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

    <!-- Edit Disposition Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-fill me-2"></i>Edit Disposition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="disposition_id" id="editDispositionId">
                        
                        <div class="mb-3">
                            <label for="editDispositionName" class="form-label">Disposition Name</label>
                            <input type="text" name="disposition_name" id="editDispositionName" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_disposition" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Update Disposition
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Disposition Modal -->
    <div class="modal fade" id="deleteTLDispositionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash-fill me-2"></i>Delete Team Leader Disposition</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="disposition_id" id="deleteTLDispositionId">
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Warning!</strong> This action cannot be undone.
                        </div>
                        
                        <p>Are you sure you want to delete this Team Leader disposition?</p>
                        
                        <div class="bg-light p-3 rounded">
                            <strong>Name:</strong> <span id="deleteTLNameDisplay"></span><br>
                            <strong>Description:</strong> <span id="deleteTLDescriptionDisplay"></span><br>
                            <strong>Current Usage:</strong> <span id="deleteTLUsageDisplay" class="badge bg-info"></span> team leader actions
                        </div>
                        
                        <div id="deleteTLUsageWarning" class="alert alert-danger mt-3" style="display: none;">
                            <i class="bi bi-x-circle-fill me-2"></i>
                            <strong>Cannot Delete:</strong> This disposition is currently being used in team leader actions. 
                            Please ensure it's not in use before deleting.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_disposition" id="deleteTLConfirmBtn" class="btn btn-danger">
                            <i class="bi bi-trash-fill me-2"></i>Delete Disposition
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editDisposition(id, name, description) {
            document.getElementById('editDispositionId').value = id;
            document.getElementById('editDispositionName').value = name;
            document.getElementById('editDescription').value = description;
            
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }

        // Handle delete modal data
        document.getElementById('deleteTLDispositionModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const dispositionId = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const description = button.getAttribute('data-description');
            const usage = parseInt(button.getAttribute('data-usage'));
            
            document.getElementById('deleteTLDispositionId').value = dispositionId;
            document.getElementById('deleteTLNameDisplay').textContent = name;
            document.getElementById('deleteTLDescriptionDisplay').textContent = description || 'No description';
            document.getElementById('deleteTLUsageDisplay').textContent = usage;
            
            // Show warning if disposition is in use
            const warningDiv = document.getElementById('deleteTLUsageWarning');
            const deleteBtn = document.getElementById('deleteTLConfirmBtn');
            
            if (usage > 0) {
                warningDiv.style.display = 'block';
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = '<i class="bi bi-x-circle-fill me-2"></i>Cannot Delete';
            } else {
                warningDiv.style.display = 'none';
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="bi bi-trash-fill me-2"></i>Delete Disposition';
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>