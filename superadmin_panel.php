<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();

// Get statistics
$stats = [];

// Total admins
$result = $conn->query("SELECT COUNT(*) as count FROM admin_users WHERE admin_id != 'SUPER'");
$stats['total_admins'] = $result->fetch_assoc()['count'];

// Active admins
$result = $conn->query("SELECT COUNT(*) as count FROM admin_users WHERE admin_id != 'SUPER' AND is_active = 1");
$stats['active_admins'] = $result->fetch_assoc()['count'];

// Total callers
$result = $conn->query("SELECT COUNT(*) as count FROM callers");
$stats['total_callers'] = $result->fetch_assoc()['count'];

// Total batches uploaded today
$result = $conn->query("SELECT COUNT(*) as count FROM file_batches WHERE DATE(upload_time) = CURDATE()");
$stats['today_batches'] = $result->fetch_assoc()['count'];

// Pending vendor requests
$result = $conn->query("SELECT COUNT(*) as count FROM vendor_requests WHERE status = 'pending'");
$stats['pending_requests'] = $result->fetch_assoc()['count'];

// Get recent admin activities
$recent_activities = $conn->query("
    SELECT au.name, au.admin_id, fb.original_filename, fb.upload_time, 
           COUNT(fcl.id) as records
    FROM admin_users au
    JOIN file_batches fb ON au.admin_id = fb.admin_id
    LEFT JOIN final_call_logs fcl ON fb.id = fcl.batch_id
    WHERE au.admin_id != 'SUPER'
    GROUP BY fb.id
    ORDER BY fb.upload_time DESC
    LIMIT 10
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Panel</title>
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
        .stat-card {
            border: none;
            border-radius: 10px;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
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
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-calendar3"></i> <?= date('F j, Y') ?>
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-subtitle mb-2 text-white-50">Total Admins</h6>
                                        <h2 class="mb-0"><?= $stats['total_admins'] ?></h2>
                                    </div>
                                    <i class="bi bi-people-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-subtitle mb-2 text-white-50">Active Admins</h6>
                                        <h2 class="mb-0"><?= $stats['active_admins'] ?></h2>
                                    </div>
                                    <i class="bi bi-person-check-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-subtitle mb-2 text-white-50">Total Callers</h6>
                                        <h2 class="mb-0"><?= $stats['total_callers'] ?></h2>
                                    </div>
                                    <i class="bi bi-telephone-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-subtitle mb-2 text-white-50">Today's Batches</h6>
                                        <h2 class="mb-0"><?= $stats['today_batches'] ?></h2>
                                    </div>
                                    <i class="bi bi-file-earmark-arrow-up-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-activity me-2"></i>Recent Admin Activities</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Admin</th>
                                        <th>Admin ID</th>
                                        <th>File Uploaded</th>
                                        <th>Records</th>
                                        <th>Upload Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_activities && $recent_activities->num_rows > 0): ?>
                                        <?php while($activity = $recent_activities->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($activity['name']) ?></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($activity['admin_id']) ?></span></td>
                                                <td><?= htmlspecialchars($activity['original_filename']) ?></td>
                                                <td><?= number_format($activity['records']) ?></td>
                                                <td><?= date('d-M-Y H:i', strtotime($activity['upload_time'])) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No recent activities</td>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>