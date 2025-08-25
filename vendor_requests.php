<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();
$message = '';
$error = '';

// Handle vendor request actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $requestId = $_POST['request_id'] ?? 0;
    $action = $_POST['action'];
    
    if ($requestId && in_array($action, ['approve', 'reject'])) {
        // Get request details
        $stmt = $conn->prepare("SELECT admin_id, vendor_name, is_additional FROM vendor_requests WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $request = $result->fetch_assoc();
            
            $conn->begin_transaction();
            try {
                if ($action == 'approve') {
                    // Generate vendor ID based on whether it's an additional request
                    $vendorId = generateVendorId($conn, $request['is_additional']);
                    
                    if ($vendorId === null) {
                        throw new Exception("Unable to generate vendor ID. Maximum limit reached.");
                    }
                    
                    // Create vendor
                    $vendorStmt = $conn->prepare("INSERT INTO vendors (vendor_id, vendor_name, admin_id, is_approved) VALUES (?, ?, ?, 1)");
                    $vendorStmt->bind_param("sss", $vendorId, $request['vendor_name'], $request['admin_id']);
                    $vendorStmt->execute();
                    $vendorStmt->close();
                    
                    $newStatus = 'approved';
                    $message = "Unit request approved and unit created with ID: $vendorId";
                } else {
                    $newStatus = 'rejected';
                    $message = "Unit request rejected.";
                }
                
                // Update request status
                $updateStmt = $conn->prepare("UPDATE vendor_requests SET status = ?, processed_at = NOW(), processed_by = ? WHERE id = ?");
                $updateStmt->bind_param("sii", $newStatus, $_SESSION['superadmin_id'], $requestId);
                $updateStmt->execute();
                $updateStmt->close();
                
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to process request: " . $e->getMessage();
            }
        } else {
            $error = "Request not found or already processed.";
        }
        $stmt->close();
    }
}

// Fetch pending requests
$pendingRequests = $conn->query("
    SELECT vr.*, au.name as admin_name, au.admin_id as admin_code,
           (SELECT COUNT(*) FROM vendor_requests WHERE admin_id = vr.admin_id) as total_admin_requests
    FROM vendor_requests vr
    JOIN admin_users au ON vr.admin_id = au.admin_id
    WHERE vr.status = 'pending'
    ORDER BY vr.requested_at DESC
");

// Fetch processed requests
$processedRequests = $conn->query("
    SELECT vr.*, au.name as admin_name, au.admin_id as admin_code,
           sau.name as processed_by_name,
           v.vendor_id
    FROM vendor_requests vr
    JOIN admin_users au ON vr.admin_id = au.admin_id
    LEFT JOIN admin_users sau ON vr.processed_by = sau.id
    LEFT JOIN vendors v ON v.vendor_name = vr.vendor_name AND v.admin_id = vr.admin_id
    WHERE vr.status != 'pending'
    ORDER BY vr.processed_at DESC
    LIMIT 50
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Requests - Superadmin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .status-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'superadmin_sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-bell-fill me-2"></i>Unit Requests</h1>
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

                <!-- Pending Requests -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Pending Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($pendingRequests && $pendingRequests->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Admin</th>
                                            <th>Admin ID</th>
                                            <th>Unit Name</th>
                                            <th>Type</th>
                                            <th>Admin's Total</th>
                                            <th>Requested</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($request = $pendingRequests->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($request['admin_name']) ?></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($request['admin_code']) ?></span></td>
                                                <td><strong><?= htmlspecialchars($request['vendor_name']) ?></strong></td>
                                                <td>
                                                    <?php if ($request['is_additional']): ?>
                                                        <span class="badge bg-info">Additional</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-primary">Default</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $request['total_admin_requests'] >= 4 ? 'bg-danger' : 'bg-secondary' ?>">
                                                        <?= $request['total_admin_requests'] ?>/4
                                                    </span>
                                                </td>
                                                <td><?= date('d-M-Y H:i', strtotime($request['requested_at'])) ?></td>
                                                <td>
                                                    <?php if ($request['total_admin_requests'] <= 4): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn btn-sm btn-success">
                                                                <i class="bi bi-check-circle"></i> Approve
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-danger">Limit Exceeded</span>
                                                    <?php endif; ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-x-circle"></i> Reject
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No pending unit requests</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Processed Requests -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-check2-square me-2"></i>Processed Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($processedRequests && $processedRequests->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Admin</th>
                                            <th>Admin ID</th>
                                            <th>Unit Name</th>
                                            <th>Unit ID</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Processed By</th>
                                            <th>Processed At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($request = $processedRequests->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($request['admin_name']) ?></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($request['admin_code']) ?></span></td>
                                                <td><?= htmlspecialchars($request['vendor_name']) ?></td>
                                                <td>
                                                    <?php if ($request['vendor_id']): ?>
                                                        <span class="badge bg-primary"><?= htmlspecialchars($request['vendor_id']) ?></span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($request['is_additional']): ?>
                                                        <span class="badge bg-info">Additional</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-primary">Default</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge status-badge <?= $request['status'] == 'approved' ? 'bg-success' : 'bg-danger' ?>">
                                                        <?= ucfirst($request['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($request['processed_by_name'] ?? 'System') ?></td>
                                                <td><?= date('d-M-Y H:i', strtotime($request['processed_at'])) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No processed requests yet</p>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>