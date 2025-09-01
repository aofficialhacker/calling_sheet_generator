<?php
require_once 'db_config.php';
requireSuperadmin();

$superadminId = $_SESSION['superadmin_id'];

// Get database connection for form processing
$conn = getDBConnection();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_limit':
                $adminId = $_POST['admin_id'];
                $newLimit = max(0, (int)$_POST['download_limit']);
                
                try {
                    // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing records
                    $stmt = $conn->prepare("
                        INSERT INTO admin_download_limits (admin_id, download_limit, created_by)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            download_limit = VALUES(download_limit),
                            updated_at = CURRENT_TIMESTAMP
                    ");
                    
                    $stmt->bind_param("sis", $adminId, $newLimit, $superadminId);
                    
                    if ($stmt->execute()) {
                        $message = "Download limit updated successfully for admin: " . htmlspecialchars($adminId) . " (Limit: $newLimit)";
                        $messageType = 'success';
                    } else {
                        $message = "Failed to update download limit.";
                        $messageType = 'danger';
                    }
                    $stmt->close();
                    
                } catch (Exception $e) {
                    $message = "Error: " . $e->getMessage();
                    $messageType = 'danger';
                }
                break;
                
            case 'reset_usage':
                $adminId = $_POST['admin_id'];
                $disposition = $_POST['disposition'];
                
                try {
                    // Reset download tracking for specific admin/disposition
                    if ($disposition === 'ALL') {
                        $stmt = $conn->prepare("DELETE FROM download_tracking WHERE admin_id = ?");
                        $stmt->bind_param("s", $adminId);
                        $resetType = "all dispositions";
                    } else {
                        $stmt = $conn->prepare("DELETE FROM download_tracking WHERE admin_id = ? AND disposition = ?");
                        $stmt->bind_param("ss", $adminId, $disposition);
                        $resetType = "disposition: " . htmlspecialchars($disposition);
                    }
                    
                    if ($stmt->execute()) {
                        $affected = $stmt->affected_rows;
                        $message = "Reset $affected download records for admin " . htmlspecialchars($adminId) . " ($resetType)";
                        $messageType = 'info';
                    } else {
                        $message = "Failed to reset download usage.";
                        $messageType = 'danger';
                    }
                    $stmt->close();
                    
                } catch (Exception $e) {
                    $message = "Error: " . $e->getMessage();
                    $messageType = 'danger';
                }
                break;
        }
    }
}

// Get all admins with their limits and current usage
try {
    $admins = [];
    
    // Get admin basic info
    $adminStmt = $conn->query("
        SELECT au.admin_id, au.name, au.username, au.is_active,
               adl.download_limit,
               adl.created_at as limit_set_date,
               adl.updated_at as limit_updated_date
        FROM admin_users au
        LEFT JOIN admin_download_limits adl ON au.admin_id = adl.admin_id
        WHERE au.is_active = 1
        ORDER BY au.admin_id
    ");
    
    while ($admin = $adminStmt->fetch_assoc()) {
        $adminId = $admin['admin_id'];
        
        // Get download usage summary
        $usageStmt = $conn->prepare("
            SELECT disposition, SUM(download_count) as total_downloads
            FROM download_tracking 
            WHERE admin_id = ?
            GROUP BY disposition
            ORDER BY total_downloads DESC
        ");
        $usageStmt->bind_param("s", $adminId);
        $usageStmt->execute();
        $usageResult = $usageStmt->get_result();
        
        $usage = [];
        $totalUsage = 0;
        while ($row = $usageResult->fetch_assoc()) {
            $usage[] = $row;
            $totalUsage += $row['total_downloads'];
        }
        $usageStmt->close();
        
        $admin['usage'] = $usage;
        $admin['total_usage'] = $totalUsage;
        $admin['download_limit'] = $admin['download_limit'] ?? 5; // Default if not set
        
        $admins[] = $admin;
    }
    
} catch (Exception $e) {
    $message = "Error loading admin data: " . $e->getMessage();
    $messageType = 'danger';
    $admins = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Limits Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .usage-badge { font-size: 0.8em; margin: 2px; }
        .limit-status.over-limit { background-color: #dc3545; color: white; }
        .limit-status.near-limit { background-color: #ffc107; color: black; }
        .limit-status.under-limit { background-color: #198754; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-download me-2"></i>Download Limits Management</h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Admin Download Limits & Usage</h5>
                        <small class="text-muted">Set and manage download limits for each admin. Limits apply per disposition per filter combination.</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($admins)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No admin users found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Admin ID</th>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Download Limit</th>
                                            <th>Usage Summary</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admins as $admin): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($admin['admin_id']) ?></strong></td>
                                                <td><?= htmlspecialchars($admin['name']) ?></td>
                                                <td><?= htmlspecialchars($admin['username']) ?></td>
                                                <td>
                                                    <form method="post" class="d-flex align-items-center" style="gap: 5px;">
                                                        <input type="hidden" name="action" value="update_limit">
                                                        <input type="hidden" name="admin_id" value="<?= htmlspecialchars($admin['admin_id']) ?>">
                                                        <input type="number" name="download_limit" value="<?= $admin['download_limit'] ?>" min="0" max="100" class="form-control form-control-sm" style="width: 80px;">
                                                        <button type="submit" class="btn btn-sm btn-primary" title="Update Limit">
                                                            <i class="bi bi-check"></i>
                                                        </button>
                                                    </form>
                                                    <?php if ($admin['limit_set_date']): ?>
                                                        <small class="text-muted d-block">Set: <?= date('M d, Y', strtotime($admin['limit_set_date'])) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="mb-1">
                                                        <strong>Total: <?= $admin['total_usage'] ?> downloads</strong>
                                                    </div>
                                                    <?php if (empty($admin['usage'])): ?>
                                                        <span class="badge bg-secondary">No usage</span>
                                                    <?php else: ?>
                                                        <?php foreach ($admin['usage'] as $usage): ?>
                                                            <?php 
                                                            $isOverLimit = $usage['total_downloads'] > $admin['download_limit'];
                                                            $isNearLimit = $usage['total_downloads'] >= ($admin['download_limit'] * 0.8);
                                                            $statusClass = $isOverLimit ? 'over-limit' : ($isNearLimit ? 'near-limit' : 'under-limit');
                                                            ?>
                                                            <span class="badge usage-badge limit-status <?= $statusClass ?>">
                                                                <?= htmlspecialchars($usage['disposition']) ?>: <?= $usage['total_downloads'] ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($admin['usage'])): ?>
                                                        <div class="dropdown">
                                                            <button class="btn btn-sm btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                                <i class="bi bi-arrow-clockwise"></i> Reset
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <li>
                                                                    <form method="post" class="px-3">
                                                                        <input type="hidden" name="action" value="reset_usage">
                                                                        <input type="hidden" name="admin_id" value="<?= htmlspecialchars($admin['admin_id']) ?>">
                                                                        <input type="hidden" name="disposition" value="ALL">
                                                                        <button type="submit" class="btn btn-sm btn-danger w-100" onclick="return confirm('Reset ALL download usage for this admin?')">
                                                                            <i class="bi bi-exclamation-triangle"></i> Reset All
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <?php foreach ($admin['usage'] as $usage): ?>
                                                                    <li>
                                                                        <form method="post" class="px-3 mb-1">
                                                                            <input type="hidden" name="action" value="reset_usage">
                                                                            <input type="hidden" name="admin_id" value="<?= htmlspecialchars($admin['admin_id']) ?>">
                                                                            <input type="hidden" name="disposition" value="<?= htmlspecialchars($usage['disposition']) ?>">
                                                                            <button type="submit" class="btn btn-sm btn-warning w-100" onclick="return confirm('Reset usage for <?= htmlspecialchars($usage['disposition']) ?>?')">
                                                                                <?= htmlspecialchars($usage['disposition']) ?>
                                                                            </button>
                                                                        </form>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No actions needed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0">Legend</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <span class="badge limit-status over-limit">Over Limit</span> - Downloads exceed the limit
                            </div>
                            <div class="col-md-4">
                                <span class="badge limit-status near-limit">Near Limit</span> - Downloads are ≥80% of limit
                            </div>
                            <div class="col-md-4">
                                <span class="badge limit-status under-limit">Under Limit</span> - Downloads are within limit
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Close the main connection
$conn->close();
?>