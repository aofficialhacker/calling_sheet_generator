<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];
$message = '';
$error = '';

// Handle vendor request submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'request_vendor') {
        // Generate a default unit name based on admin ID and timestamp
        $vendorName = 'Unit_' . $adminId . '_' . time();
        
        // Check total requests by this admin (including approved ones)
        $countStmt = $conn->prepare("
            SELECT COUNT(*) as total_requests 
            FROM lv_vendor_requests 
            WHERE admin_id = ?
        ");
        $countStmt->bind_param("s", $adminId);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        
        if ($countResult['total_requests'] >= 4) {
            $error = 'You have reached the maximum limit of 4 unit requests.';
        } else {
            // Insert new request with is_additional flag (no need to check for duplicate auto-generated names)
            $insertStmt = $conn->prepare("
                INSERT INTO lv_vendor_requests (admin_id, vendor_name, is_additional) 
                VALUES (?, ?, 1)
            ");
            $insertStmt->bind_param("ss", $adminId, $vendorName);
            
            if ($insertStmt->execute()) {
                $message = 'Unit request submitted successfully. You will be notified once approved.';
            } else {
                $error = 'Failed to submit unit request.';
            }
            $insertStmt->close();
        }
    }
}

// Fetch existing lv_vendors for this admin
$lv_vendorsStmt = $conn->prepare("
    SELECT vendor_id, vendor_name, is_approved 
    FROM lv_vendors 
    WHERE admin_id = ? 
    ORDER BY vendor_id
");
$lv_vendorsStmt->bind_param("s", $adminId);
$lv_vendorsStmt->execute();
$lv_vendors = $lv_vendorsStmt->get_result();
$lv_vendorsStmt->close();

// Fetch pending requests count
$pendingStmt = $conn->prepare("
    SELECT COUNT(*) as pending_count 
    FROM lv_vendor_requests 
    WHERE admin_id = ? AND status = 'pending'
");
$pendingStmt->bind_param("s", $adminId);
$pendingStmt->execute();
$pendingCount = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
$pendingStmt->close();

// Fetch total requests made
$totalRequestsStmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM lv_vendor_requests 
    WHERE admin_id = ?
");
$totalRequestsStmt->bind_param("s", $adminId);
$totalRequestsStmt->execute();
$totalRequests = $totalRequestsStmt->get_result()->fetch_assoc()['total'];
$totalRequestsStmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-shop me-2"></i>Unit Management</h1>
                    <div>
                        <span class="badge bg-info">Requests: <?= $totalRequests ?>/4</span>
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge bg-warning ms-2">Pending: <?= $pendingCount ?></span>
                        <?php endif; ?>
                    </div>
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

                <!-- Current Vendors -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Your Units</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Unit ID</th>
                                        <th>Unit Name</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($lv_vendors && $lv_vendors->num_rows > 0): ?>
                                        <?php while($vendor = $lv_vendors->fetch_assoc()): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= htmlspecialchars($vendor['vendor_id']) ?></span></td>
                                                <td><?= htmlspecialchars($vendor['vendor_name']) ?></td>
                                                <td>
                                                    <span class="badge <?= $vendor['is_approved'] ? 'bg-success' : 'bg-warning' ?>">
                                                        <?= $vendor['is_approved'] ? 'Approved' : 'Pending' ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">No units assigned yet</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Request New Vendor -->
                <?php if ($totalRequests < 4): ?>
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">Request Additional Unit</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="request_vendor">
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-plus-circle me-2"></i>Submit Unit Request
                                </button>
                            </div>
                            <small class="text-muted mt-2 d-block text-center">
                                You can request up to 4 additional units. Each request requires superadmin approval.<br>
                                Unit names will be automatically generated upon approval.
                            </small>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>You have reached the maximum limit of 4 unit requests.
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>