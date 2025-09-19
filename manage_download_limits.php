<?php
require_once 'db_config.php';
require_once 'download_counter.php';
requireSuperadmin();

$conn = getDBConnection();
$downloadCounter = new DownloadCounter($conn);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_limit') {
        $adminId = $_POST['admin_id'];
        $newLimit = (int)$_POST['download_limit'];
        $notes = $_POST['notes'] ?? '';
        
        if ($downloadCounter->updateAdminLimit($adminId, $newLimit, $_SESSION['superadmin_id'], $notes)) {
            $success_message = "Download limit updated successfully for admin: " . htmlspecialchars($adminId);
        } else {
            $error_message = "Failed to update download limit. Please try again.";
        }
    }
}

// Fetch all admin users with their current limits and usage
try {
    // First check if download_limit column exists
    $check_column = $conn->query("SHOW COLUMNS FROM lv_admin_users LIKE 'download_limit'");
    if ($check_column->num_rows == 0) {
        // Add the column if it doesn't exist
        $conn->query("ALTER TABLE lv_admin_users ADD COLUMN download_limit INT DEFAULT 5 COMMENT 'Maximum downloads allowed per disposition per batch'");
    }
    
    $stmt = $conn->prepare("
        SELECT au.admin_id, au.name, au.username, 
               COALESCE(au.download_limit, 5) as download_limit,
               adl.set_by_superadmin, adl.notes, adl.updated_at,
               COUNT(dt.id) as total_tracked_downloads,
               SUM(dt.download_count) as total_downloads
        FROM lv_admin_users au
        LEFT JOIN lv_admin_download_limits adl ON au.admin_id = adl.admin_id
        LEFT JOIN lv_download_tracking dt ON au.admin_id = dt.admin_id
        WHERE au.is_active = 1
        GROUP BY au.admin_id, au.name, au.username, au.download_limit, 
                 adl.set_by_superadmin, adl.notes, adl.updated_at
        ORDER BY au.name
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute statement: " . $stmt->error);
    }
    
    $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    error_log("Error in manage_download_limits.php: " . $e->getMessage());
    
    // Fallback to simpler query if complex query fails
    $stmt = $conn->prepare("
        SELECT admin_id, name, username, 
               COALESCE(download_limit, 5) as download_limit
        FROM lv_admin_users 
        WHERE is_active = 1 
        ORDER BY name
    ");
    
    if ($stmt && $stmt->execute()) {
        $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        // Add default values for missing columns
        foreach ($admins as &$admin) {
            $admin['set_by_superadmin'] = null;
            $admin['notes'] = null;
            $admin['updated_at'] = null;
            $admin['total_tracked_downloads'] = 0;
            $admin['total_downloads'] = 0;
        }
    } else {
        die("Database error: Unable to fetch admin data. Please check your database configuration.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Download Limits</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .table-responsive { border-radius: 0.75rem; overflow: hidden; }
        .limit-input { width: 80px; }
        .usage-badge { font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-download me-2"></i>Manage Admin Download Limits</h1>
                </div>
                
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?= $success_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Download Limit Management Card -->
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Admin Download Limits Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="alert alert-info" role="alert">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    <strong>How Download Limits Work:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Each admin can download a specific disposition (e.g., "Follow Up") for each batch a limited number of times</li>
                                        <li>Once the limit is reached for a specific batch, that batch is excluded from "All Batches" downloads</li>
                                        <li>Limits apply to all filter combinations: Status + Product + Batch + Caller</li>
                                        <li>Default limit is 5 downloads per disposition per batch</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Admin</th>
                                        <th>Email/Username</th>
                                        <th>Current Limit</th>
                                        <th>Total Downloads</th>
                                        <th>Unique Combinations</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($admins as $admin): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($admin['name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($admin['admin_id']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($admin['username']) ?></td>
                                        <td>
                                            <span class="badge bg-secondary fs-6"><?= $admin['download_limit'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info usage-badge"><?= $admin['total_downloads'] ?: 0 ?> downloads</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning usage-badge"><?= $admin['total_tracked_downloads'] ?: 0 ?> combinations</span>
                                        </td>
                                        <td>
                                            <?php if ($admin['updated_at']): ?>
                                                <small><?= date('d-M-Y H:i', strtotime($admin['updated_at'])) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Default</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary edit-limit-btn" 
                                                    data-admin-id="<?= htmlspecialchars($admin['admin_id']) ?>"
                                                    data-admin-name="<?= htmlspecialchars($admin['name']) ?>"
                                                    data-current-limit="<?= $admin['download_limit'] ?>"
                                                    data-notes="<?= htmlspecialchars($admin['notes'] ?? '') ?>">
                                                <i class="bi bi-pencil-fill"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-info view-usage-btn"
                                                    data-admin-id="<?= htmlspecialchars($admin['admin_id']) ?>"
                                                    data-admin-name="<?= htmlspecialchars($admin['name']) ?>">
                                                <i class="bi bi-eye-fill"></i> View Usage
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Edit Limit Modal -->
    <div class="modal fade" id="editLimitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Download Limit</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_limit">
                        <input type="hidden" name="admin_id" id="edit-admin-id">
                        
                        <div class="mb-3">
                            <label class="form-label">Admin Name</label>
                            <input type="text" class="form-control" id="edit-admin-name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label" for="edit-download-limit">Download Limit per Disposition per Batch</label>
                            <input type="number" class="form-control" name="download_limit" id="edit-download-limit" 
                                   min="1" max="100" required>
                            <div class="form-text">Number of times an admin can download the same disposition for the same batch</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label" for="edit-notes">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" id="edit-notes" rows="3" 
                                      placeholder="Add any notes about this limit change..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Limit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Usage Details Modal -->
    <div class="modal fade" id="usageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Download Usage Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="usage-content">
                        <div class="text-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle edit limit button
        document.querySelectorAll('.edit-limit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const adminId = this.dataset.adminId;
                const adminName = this.dataset.adminName;
                const currentLimit = this.dataset.currentLimit;
                const notes = this.dataset.notes;
                
                document.getElementById('edit-admin-id').value = adminId;
                document.getElementById('edit-admin-name').value = adminName;
                document.getElementById('edit-download-limit').value = currentLimit;
                document.getElementById('edit-notes').value = notes;
                
                new bootstrap.Modal(document.getElementById('editLimitModal')).show();
            });
        });
        
        // Handle view usage button
        document.querySelectorAll('.view-usage-btn').forEach(button => {
            button.addEventListener('click', function() {
                const adminId = this.dataset.adminId;
                const adminName = this.dataset.adminName;
                
                document.querySelector('#usageModal .modal-title').textContent = `Download Usage: ${adminName}`;
                
                // Load usage data via AJAX
                fetch('ajax_get_admin_usage.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_id: adminId })
                })
                .then(response => response.text())
                .then(data => {
                    document.getElementById('usage-content').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('usage-content').innerHTML = 
                        '<div class="alert alert-danger">Error loading usage data</div>';
                });
                
                new bootstrap.Modal(document.getElementById('usageModal')).show();
            });
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>