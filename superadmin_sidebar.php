<?php
// This script assumes a session is already started by the parent page.
if (!defined('DB_HOST')) {
    require_once 'db_config.php';
}
$sidebar_conn = getDBConnection();

// Get pending vendor requests count
$pending_requests_count = 0;
$stmt = $sidebar_conn->prepare("SELECT COUNT(*) as count FROM vendor_requests WHERE status = 'pending'");
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $pending_requests_count = $result->fetch_assoc()['count'];
}
$stmt->close();
$sidebar_conn->close();

// Determine active page
$active_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="col-md-3 col-lg-2 d-md-block sidebar">
    <div class="position-sticky">
        <div class="text-center py-3 mb-4 border-bottom">
            <i class="bi bi-shield-fill-check fs-2"></i>
            <h5 class="mt-2">Superadmin Panel</h5>
            <small><?= htmlspecialchars($_SESSION['superadmin_name']) ?></small>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'superadmin_panel.php' ? 'active' : '' ?>" href="superadmin_panel.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'superadmin_dashboard.php' ? 'active' : '' ?>" href="superadmin_dashboard.php">
                    <i class="bi bi-graph-up-arrow me-2"></i>Analytics Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link position-relative <?= $active_page == 'manage_admins.php' ? 'active' : '' ?>" href="manage_admins.php">
                    <i class="bi bi-people-fill me-2"></i>Manage Admins
                    <?php if ($pending_requests_count > 0): ?>
                        <span class="notification-badge"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'manage_products.php' ? 'active' : '' ?>" href="manage_products.php">
                    <i class="bi bi-box-seam me-2"></i>Products
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'manage_dispositions.php' ? 'active' : '' ?>" href="manage_dispositions.php">
                    <i class="bi bi-list-check me-2"></i>Caller Dispositions
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'manage_buckets.php' ? 'active' : '' ?>" href="manage_buckets.php">
                    <i class="bi bi-collection-fill me-2"></i>Disposition Buckets
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'manage_tl_dispositions.php' ? 'active' : '' ?>" href="manage_tl_dispositions.php">
                    <i class="bi bi-tags-fill me-2"></i>Relationship Manager Dispositions
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'superadmin_tl_analytics.php' ? 'active' : '' ?>" href="superadmin_tl_analytics.php">
                    <i class="bi bi-graph-down-arrow me-2"></i>Relationship Manager Analytics
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'superadmin_download_limits.php' ? 'active' : '' ?>" href="superadmin_download_limits.php">
                    <i class="bi bi-download me-2"></i>Download Limits
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_page == 'vendor_requests.php' ? 'active' : '' ?>" href="vendor_requests.php">
                    <i class="bi bi-bell-fill me-2"></i>Unit Requests
                    <?php if ($pending_requests_count > 0): ?>
                        <span class="badge bg-danger ms-2"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
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

<style>
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
.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
}
</style>