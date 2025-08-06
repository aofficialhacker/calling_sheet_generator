<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();
$message = '';
$error = '';

// Handle admin creation with hierarchical mapping
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create_admin') {
        $username = $_POST['username'] ?? '';
        
        // Fetch user details from corporate_user_permission
        $stmt = $conn->prepare("
            SELECT id, username, password, name, designation 
            FROM corporate_user_permission 
            WHERE username = ? 
            AND designation IN ('agency_development_manager', 'branch_manager', 'zonal_manager')
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $corpUser = $result->fetch_assoc();
            $corpUserId = $corpUser['id'];
            
            // Check if admin already exists
            $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $error = "Admin user already exists with this username.";
            } else {
                $conn->begin_transaction();
                try {
                    // Generate unique admin ID
                    $adminId = generateAdminId($corpUser['name'], $conn);
                    
                    // Insert new admin
                    $insertStmt = $conn->prepare("
                        INSERT INTO admin_users (admin_id, username, password, name, designation, multi_status_selection) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $multiStatus = isset($_POST['multi_status']) ? 1 : 0;
                    $insertStmt->bind_param("sssssi", $adminId, $username, $corpUser['password'], 
                                           $corpUser['name'], $corpUser['designation'], $multiStatus);
                    $insertStmt->execute();
                    $insertStmt->close();
                    
                    // Create default 5 vendors for the new admin
                    for ($i = 1; $i <= 5; $i++) {
                        $vendorId = generateVendorId($conn);
                        $vendorName = "Default Vendor $i";
                        
                        $vendorStmt = $conn->prepare("INSERT INTO vendors (vendor_id, vendor_name, admin_id, is_approved) VALUES (?, ?, ?, 1)");
                        $vendorStmt->bind_param("sss", $vendorId, $vendorName, $adminId);
                        $vendorStmt->execute();
                        $vendorStmt->close();
                    }
                    
                    // Now map partners, connectors, and teams as callers
                    $callerCount = 0;
                    
                    // 1. Find all partners connected to this admin (ADM/BM/ZM)
                    $partnerStmt = $conn->prepare("
                        SELECT id, refercode, rname as name, mobile_no as mobile 
                        FROM first_register 
                        WHERE addedBy = ?
                    ");
                    if ($partnerStmt === false) {
                        throw new Exception("Failed to prepare partner query: " . $conn->error);
                    }
                    $partnerStmt->bind_param("i", $corpUserId);
                    $partnerStmt->execute();
                    $partners = $partnerStmt->get_result();
                    
                    while ($partner = $partners->fetch_assoc()) {
                        // Insert partner as caller
                        $callerStmt = $conn->prepare("
                            INSERT INTO callers (finqy_id, caller_name, mobile_no, caller_type, is_active) 
                            VALUES (?, ?, ?, 'partner', 1)
                            ON DUPLICATE KEY UPDATE caller_name = VALUES(caller_name), mobile_no = VALUES(mobile_no)
                        ");
                        if ($callerStmt === false) {
                            throw new Exception("Failed to prepare caller insert: " . $conn->error);
                        }
                        $finqyId = $partner['refercode'];
                        $callerName = $partner['name'];
                        $mobileNo = $partner['mobile'];
                        $callerStmt->bind_param("sss", $finqyId, $callerName, $mobileNo);
                        $callerStmt->execute();
                        $callerStmt->close();
                        
                        // Map to admin
                        $mapStmt = $conn->prepare("
                            INSERT INTO admin_caller_mapping (admin_id, finqy_id) 
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id)
                        ");
                        $mapStmt->bind_param("ss", $adminId, $finqyId);
                        $mapStmt->execute();
                        $mapStmt->close();
                        $callerCount++;
                        
                        // 2. Find all connectors connected to this partner
                        $connectorStmt = $conn->prepare("
                            SELECT id, refercode, rname as name, mobile_no as mobile 
                            FROM corporate_connector 
                            WHERE master_refercode = ?
                        ");
                        if ($connectorStmt === false) {
                            throw new Exception("Failed to prepare connector query: " . $conn->error);
                        }
                        $connectorStmt->bind_param("s", $partner['refercode']);
                        $connectorStmt->execute();
                        $connectors = $connectorStmt->get_result();
                        
                        while ($connector = $connectors->fetch_assoc()) {
                            // Insert connector as caller
                            $callerStmt = $conn->prepare("
                                INSERT INTO callers (finqy_id, caller_name, mobile_no, caller_type, is_active) 
                                VALUES (?, ?, ?, 'connector', 1)
                                ON DUPLICATE KEY UPDATE caller_name = VALUES(caller_name), mobile_no = VALUES(mobile_no)
                            ");
                            if ($callerStmt === false) {
                                throw new Exception("Failed to prepare connector caller insert: " . $conn->error);
                            }
                            $finqyId = $connector['refercode'];
                            $callerName = $connector['name'];
                            $mobileNo = $connector['mobile'];
                            $callerStmt->bind_param("sss", $finqyId, $callerName, $mobileNo);
                            $callerStmt->execute();
                            $callerStmt->close();
                            
                            // Map to admin
                            $mapStmt = $conn->prepare("
                                INSERT INTO admin_caller_mapping (admin_id, finqy_id) 
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id)
                            ");
                            $mapStmt->bind_param("ss", $adminId, $finqyId);
                            $mapStmt->execute();
                            $mapStmt->close();
                            $callerCount++;
                            
                            // 3. Find all teams connected to this connector
                            $teamStmt = $conn->prepare("
                                SELECT id, refercode, username as name, mobile 
                                FROM corp_leader 
                                WHERE leader_of = ?
                            ");
                            if ($teamStmt === false) {
                                throw new Exception("Failed to prepare team query: " . $conn->error);
                            }
                            $teamStmt->bind_param("s", $connector['refercode']);
                            $teamStmt->execute();
                            $teams = $teamStmt->get_result();
                            
                            while ($team = $teams->fetch_assoc()) {
                                // Insert team member as caller
                                $callerStmt = $conn->prepare("
                                    INSERT INTO callers (finqy_id, caller_name, mobile_no, caller_type, is_active) 
                                    VALUES (?, ?, ?, 'team', 1)
                                    ON DUPLICATE KEY UPDATE caller_name = VALUES(caller_name), mobile_no = VALUES(mobile_no)
                                ");
                                if ($callerStmt === false) {
                                    throw new Exception("Failed to prepare team caller insert: " . $conn->error);
                                }
                                $finqyId = $team['refercode'];
                                $callerName = $team['name'];
                                $mobileNo = $team['mobile'];
                                $callerStmt->bind_param("sss", $finqyId, $callerName, $mobileNo);
                                $callerStmt->execute();
                                $callerStmt->close();
                                
                                // Map to admin
                                $mapStmt = $conn->prepare("
                                    INSERT INTO admin_caller_mapping (admin_id, finqy_id) 
                                    VALUES (?, ?)
                                    ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id)
                                ");
                                $mapStmt->bind_param("ss", $adminId, $finqyId);
                                $mapStmt->execute();
                                $mapStmt->close();
                                $callerCount++;
                            }
                            $teamStmt->close();
                        }
                        $connectorStmt->close();
                    }
                    $partnerStmt->close();
                    
                    $conn->commit();
                    $message = "Admin user created successfully with ID: $adminId, 5 default vendors, and $callerCount callers mapped from hierarchy.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to create admin user: " . $e->getMessage();
                }
            }
            $checkStmt->close();
        } else {
            $error = "User not found in corporate permissions or doesn't have required designation.";
        }
        $stmt->close();
    } elseif ($_POST['action'] == 'toggle_status') {
        $adminId = $_POST['admin_id'] ?? '';
        $currentStatus = $_POST['current_status'] ?? 0;
        $newStatus = $currentStatus ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE admin_users SET is_active = ? WHERE admin_id = ? AND admin_id != 'SUPER'");
        $stmt->bind_param("is", $newStatus, $adminId);
        if ($stmt->execute()) {
            $message = "Admin status updated successfully.";
        }
        $stmt->close();
    } elseif ($_POST['action'] == 'toggle_multi_status') {
        $adminId = $_POST['admin_id'] ?? '';
        $currentStatus = $_POST['current_multi_status'] ?? 0;
        $newStatus = $currentStatus ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE admin_users SET multi_status_selection = ? WHERE admin_id = ? AND admin_id != 'SUPER'");
        $stmt->bind_param("is", $newStatus, $adminId);
        if ($stmt->execute()) {
            $message = "Multi-status selection updated successfully.";
        }
        $stmt->close();
    }
}

// Fetch corporate users for dropdown
$corpUsers = $conn->query("
    SELECT username, name, designation 
    FROM corporate_user_permission 
    WHERE designation IN ('agency_development_manager', 'branch_manager', 'zonal_manager')
    AND username NOT IN (SELECT username FROM admin_users)
    ORDER BY name
");

// Fetch all admins with enhanced stats
$admins = $conn->query("
    SELECT au.*, 
           (SELECT COUNT(*) FROM vendors WHERE admin_id = au.admin_id) as vendor_count,
           (SELECT COUNT(DISTINCT fcl.finqy_id) 
            FROM final_call_logs fcl 
            JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
            WHERE acm.admin_id = au.admin_id) as active_caller_count,
           (SELECT COUNT(*) FROM admin_caller_mapping WHERE admin_id = au.admin_id) as total_caller_count,
           (SELECT COUNT(*) FROM file_batches WHERE admin_id = au.admin_id) as batch_count
    FROM admin_users au 
    WHERE admin_id != 'SUPER' 
    ORDER BY created_at DESC
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - Superadmin Panel</title>
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky">
                    <div class="text-center py-3 mb-4 border-bottom">
                        <i class="bi bi-shield-fill-check fs-2"></i>
                        <h5 class="mt-2">Superadmin Panel</h5>
                        <small><?= htmlspecialchars($_SESSION['superadmin_name']) ?></small>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="superadmin_panel.php">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="manage_admins.php">
                                <i class="bi bi-people-fill me-2"></i>Manage Admins
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_products.php">
                                <i class="bi bi-box-seam me-2"></i>Products
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_dispositions.php">
                                <i class="bi bi-list-check me-2"></i>Dispositions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="vendor_requests.php">
                                <i class="bi bi-bell-fill me-2"></i>Vendor Requests
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="global_performance.php">
                                <i class="bi bi-graph-up me-2"></i>Performance
                            </a>
                        </li>
                        <li class="nav-item mt-4">
                            <a class="nav-link text-danger" href="logout.php?type=superadmin">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-people-fill me-2"></i>Manage Admin Users</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAdminModal">
                        <i class="bi bi-plus-circle me-2"></i>Create Admin
                    </button>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Admin ID</th>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Designation</th>
                                        <th>Vendors</th>
                                        <th>Callers (Active/Total)</th>
                                        <th>Batches</th>
                                        <th>Multi-Status</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($admins && $admins->num_rows > 0): ?>
                                        <?php while($admin = $admins->fetch_assoc()): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= htmlspecialchars($admin['admin_id']) ?></span></td>
                                                <td><?= htmlspecialchars($admin['name']) ?></td>
                                                <td><?= htmlspecialchars($admin['username']) ?></td>
                                                <td><?= htmlspecialchars($admin['designation']) ?></td>
                                                <td><?= $admin['vendor_count'] ?></td>
                                                <td><?= $admin['active_caller_count'] ?>/<?= $admin['total_caller_count'] ?></td>
                                                <td><?= $admin['batch_count'] ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_multi_status">
                                                        <input type="hidden" name="admin_id" value="<?= $admin['admin_id'] ?>">
                                                        <input type="hidden" name="current_multi_status" value="<?= $admin['multi_status_selection'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $admin['multi_status_selection'] ? 'btn-success' : 'btn-secondary' ?>">
                                                            <?= $admin['multi_status_selection'] ? 'Enabled' : 'Disabled' ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="admin_id" value="<?= $admin['admin_id'] ?>">
                                                        <input type="hidden" name="current_status" value="<?= $admin['is_active'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $admin['is_active'] ? 'btn-success' : 'btn-danger' ?>">
                                                            <?= $admin['is_active'] ? 'Active' : 'Inactive' ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <a href="admin_details.php?id=<?= $admin['admin_id'] ?>" class="btn btn-sm btn-info text-white" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="view_admin_callers.php?id=<?= $admin['admin_id'] ?>" class="btn btn-sm btn-warning" title="View Callers">
                                                        <i class="bi bi-people"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted">No admin users found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="createAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_admin">
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Select Corporate User (ADM/BM/ZM)</label>
                            <select class="form-select" id="username" name="username" required>
                                <option value="">-- Select User --</option>
                                <?php if ($corpUsers && $corpUsers->num_rows > 0): ?>
                                    <?php while($user = $corpUsers->fetch_assoc()): ?>
                                        <option value="<?= htmlspecialchars($user['username']) ?>">
                                            <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['designation']) ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted">This will automatically map all partners, connectors, and teams under this admin as callers.</small>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="multi_status" name="multi_status">
                            <label class="form-check-label" for="multi_status">
                                Enable multi-status selection in dropdown
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Admin</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>