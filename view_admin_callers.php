<?php
require_once 'db_config.php';
requireSuperadmin();

$adminId = $_GET['id'] ?? '';
if (empty($adminId)) {
    header("Location: manage_admins.php");
    exit();
}

$conn = getDBConnection();

// Get admin details
$adminStmt = $conn->prepare("SELECT admin_id, name FROM admin_users WHERE admin_id = ?");
$adminStmt->bind_param("s", $adminId);
$adminStmt->execute();
$adminData = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$adminData) {
    header("Location: manage_admins.php");
    exit();
}

// Get all callers mapped to this admin
$callersSql = "
    SELECT c.finqy_id, c.caller_name, c.caller_type, c.mobile_no, c.is_active,
           COUNT(DISTINCT fcl.id) as total_calls,
           MAX(fcl.processed_at) as last_activity
    FROM callers c
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    LEFT JOIN final_call_logs fcl ON c.finqy_id = fcl.finqy_id
    WHERE acm.admin_id = ?
    GROUP BY c.finqy_id, c.caller_name, c.caller_type, c.mobile_no, c.is_active
    ORDER BY 
        CASE c.caller_type 
            WHEN 'partner' THEN 1 
            WHEN 'connector' THEN 2 
            WHEN 'team' THEN 3 
        END, 
        c.caller_name
";
$stmt = $conn->prepare($callersSql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$callers = $stmt->get_result();

// Get summary stats
$statsSql = "
    SELECT 
        SUM(CASE WHEN c.caller_type = 'partner' THEN 1 ELSE 0 END) as partner_count,
        SUM(CASE WHEN c.caller_type = 'connector' THEN 1 ELSE 0 END) as connector_count,
        SUM(CASE WHEN c.caller_type = 'team' THEN 1 ELSE 0 END) as team_count,
        COUNT(*) as total_count
    FROM callers c
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id
    WHERE acm.admin_id = ?
";
$statsStmt = $conn->prepare($statsSql);
$statsStmt->bind_param("s", $adminId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Callers - <?= htmlspecialchars($adminData['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .type-badge { font-size: 0.875rem; padding: 0.25rem 0.75rem; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2">
                    <i class="bi bi-people-fill me-2"></i>Callers for Admin: <?= htmlspecialchars($adminData['name']) ?>
                    <span class="badge bg-primary"><?= htmlspecialchars($adminData['admin_id']) ?></span>
                </h1>
            </div>
            <a href="manage_admins.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Admins
            </a>
        </div>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Total Callers</h5>
                        <p class="display-4 mb-0"><?= $stats['total_count'] ?? 0 ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Partners</h5>
                        <p class="display-4 mb-0 text-primary"><?= $stats['partner_count'] ?? 0 ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Connectors</h5>
                        <p class="display-4 mb-0 text-warning"><?= $stats['connector_count'] ?? 0 ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Team Members</h5>
                        <p class="display-4 mb-0 text-success"><?= $stats['team_count'] ?? 0 ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Callers Table -->
        <div class="card shadow-sm">
            <div class="card-header">
                <h4 class="mb-0">Caller Details</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Refercode (FinqyID)</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Mobile</th>
                                <th>Total Calls</th>
                                <th>Last Activity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($callers && $callers->num_rows > 0): ?>
                                <?php while($caller = $callers->fetch_assoc()): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($caller['finqy_id']) ?></code></td>
                                        <td><?= htmlspecialchars($caller['caller_name']) ?></td>
                                        <td>
                                            <?php
                                            $typeClass = '';
                                            switch($caller['caller_type']) {
                                                case 'partner': $typeClass = 'bg-primary'; break;
                                                case 'connector': $typeClass = 'bg-warning'; break;
                                                case 'team': $typeClass = 'bg-success'; break;
                                            }
                                            ?>
                                            <span class="badge type-badge <?= $typeClass ?>">
                                                <?= ucfirst($caller['caller_type']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($caller['mobile_no'] ?? 'N/A') ?></td>
                                        <td><?= $caller['total_calls'] ?></td>
                                        <td>
                                            <?= $caller['last_activity'] ? date('d-M-Y H:i', strtotime($caller['last_activity'])) : 'Never' ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $caller['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $caller['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No callers mapped to this admin</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>