<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_limit') {
        $adminId = $_POST['admin_id'];
        $newLimit = (int)$_POST['download_limit'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            // Ensure download_limit column exists
            $conn->query("ALTER TABLE lv_admin_users ADD COLUMN IF NOT EXISTS download_limit INT DEFAULT 5");
            
            // Update the limit
            $stmt = $conn->prepare("UPDATE lv_admin_users SET download_limit = ? WHERE admin_id = ?");
            $stmt->bind_param("is", $newLimit, $adminId);
            
            if ($stmt->execute()) {
                $message = "Download limit updated successfully for admin: " . htmlspecialchars($adminId);
                $messageType = 'success';
            } else {
                $message = "Failed to update download limit.";
                $messageType = 'danger';
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Get all admins with basic info
try {
    // Ensure the download_limit column exists
    $conn->query("ALTER TABLE lv_admin_users ADD COLUMN IF NOT EXISTS download_limit INT DEFAULT 5");
    
    $stmt = $conn->prepare("
        SELECT admin_id, name, username, 
               COALESCE(download_limit, 5) as download_limit,
               is_active
        FROM lv_admin_users 
        WHERE is_active = 1 
        ORDER BY name
    ");
    
    $stmt->execute();
    $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Download Limits (Simple)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-download me-2"></i>Manage Download Limits</h1>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i><?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Admin Download Limits</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Admin ID</th>
                                        <th>Name</th>
                                        <th>Username/Email</th>
                                        <th>Current Limit</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($admins as $admin): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($admin['admin_id']) ?></td>
                                        <td><?= htmlspecialchars($admin['name']) ?></td>
                                        <td><?= htmlspecialchars($admin['username']) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= $admin['download_limit'] ?></span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary edit-btn" 
                                                    data-admin-id="<?= htmlspecialchars($admin['admin_id']) ?>"
                                                    data-admin-name="<?= htmlspecialchars($admin['name']) ?>"
                                                    data-current-limit="<?= $admin['download_limit'] ?>">
                                                <i class="bi bi-pencil"></i> Edit
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
    
    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
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
                            <label class="form-label">Admin</label>
                            <input type="text" class="form-control" id="edit-admin-name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Download Limit</label>
                            <input type="number" class="form-control" name="download_limit" id="edit-limit" 
                                   min="1" max="100" required>
                            <div class="form-text">Maximum downloads per disposition per batch</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit-admin-id').value = this.dataset.adminId;
            document.getElementById('edit-admin-name').value = this.dataset.adminName;
            document.getElementById('edit-limit').value = this.dataset.currentLimit;
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        });
    });
    </script>
</body>
</html>