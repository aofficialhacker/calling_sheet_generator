<?php
// This script assumes a session is already started by the parent page.
if (!defined('DB_HOST')) {
    // Define constants if not already defined (for standalone access check)
    require_once 'db_config.php';
}
$conn = getDBConnection();
$pending_requests_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM vendor_requests WHERE admin_id = ? AND status = 'pending'");
$stmt->bind_param("s", $_SESSION['admin_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $pending_requests_count = $result->fetch_assoc()['count'];
}
$stmt->close();
$conn->close();

// Determine active page
$active_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="col-md-3 col-lg-2 d-md-block sidebar">
    <div class="position-sticky">
        <div class="text-center py-3 mb-4 border-bottom">
            <i class="bi bi-person-badge-fill fs-2"></i>
            <h5 class="mt-2">Admin Panel</h5>
            <small><?= htmlspecialchars($_SESSION['admin_name']) ?></small>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'admin_panel.php' ? 'active' : '' ?>" href="admin_panel.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'admin_dashboard.php' ? 'active' : '' ?>" href="admin_dashboard.php">
                    <i class="bi bi-graph-up-arrow me-2"></i>Analytics
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'upload_batch.php' ? 'active' : '' ?>" href="upload_batch.php">
                    <i class="bi bi-cloud-upload-fill me-2"></i>Upload Batch
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'manage_batches.php' ? 'active' : '' ?>" href="manage_batches.php">
                    <i class="bi bi-stack me-2"></i>View Batches
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link <?= $active_page == 'allocation.php' ? 'active' : '' ?>" href="allocation.php">
                    <i class="bi bi-distribute-vertical me-2"></i>Allocation
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link position-relative <?= $active_page == 'request_vendor.php' ? 'active' : '' ?>" href="request_vendor.php">
                    <i class="bi bi-shop me-2"></i>Units
                    <?php if ($pending_requests_count > 0): ?>
                        <span class="badge bg-warning rounded-pill position-absolute top-0 start-100 translate-middle">
                            <?= $pending_requests_count ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item mt-4">
                <a class="nav-link text-danger" href="logout.php?type=admin">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>
</nav>
