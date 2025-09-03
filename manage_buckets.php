<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();
$superadminId = $_SESSION['superadmin_id'];
$message = '';
$messageType = '';

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_bucket'])) {
        $bucketName = trim($_POST['bucket_name']);
        $description = trim($_POST['description']);
        $hasCalendar = isset($_POST['has_calendar_enabled']) ? 1 : 0;
        
        // Check if bucket already exists
        $stmt = $conn->prepare("SELECT id FROM disposition_buckets WHERE bucket_name = ?");
        $stmt->bind_param("s", $bucketName);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $message = "Bucket with this name already exists.";
            $messageType = "danger";
        } else {
            // Create new bucket
            $stmt = $conn->prepare("INSERT INTO disposition_buckets (bucket_name, description, has_calendar_enabled, created_by) VALUES (?, ?, ?, ?)");
            $createdBy = 'SUPER';
            $stmt->bind_param("ssis", $bucketName, $description, $hasCalendar, $createdBy);
            
            if ($stmt->execute()) {
                $message = "Disposition bucket created successfully!";
                $messageType = "success";
            } else {
                $message = "Error creating bucket: " . $stmt->error;
                $messageType = "danger";
            }
        }
        $stmt->close();
    }
    
    if (isset($_POST['update_bucket'])) {
        $bucketId = $_POST['bucket_id'];
        $bucketName = trim($_POST['bucket_name']);
        $description = trim($_POST['description']);
        $hasCalendar = isset($_POST['has_calendar_enabled']) ? 1 : 0;
        
        // Check if another bucket with same name exists (excluding current)
        $stmt = $conn->prepare("SELECT id FROM disposition_buckets WHERE bucket_name = ? AND id != ?");
        $stmt->bind_param("si", $bucketName, $bucketId);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $message = "Another bucket with this name already exists.";
            $messageType = "danger";
        } else {
            $stmt = $conn->prepare("UPDATE disposition_buckets SET bucket_name = ?, description = ?, has_calendar_enabled = ? WHERE id = ?");
            $stmt->bind_param("ssii", $bucketName, $description, $hasCalendar, $bucketId);
            
            if ($stmt->execute()) {
                $message = "Bucket updated successfully!";
                $messageType = "success";
            } else {
                $message = "Error updating bucket.";
                $messageType = "danger";
            }
        }
        $stmt->close();
    }
    
    if (isset($_POST['toggle_status'])) {
        $bucketId = $_POST['bucket_id'];
        $newStatus = $_POST['new_status'];
        
        $stmt = $conn->prepare("UPDATE disposition_buckets SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $bucketId);
        
        if ($stmt->execute()) {
            $message = $newStatus ? "Bucket activated successfully!" : "Bucket deactivated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating bucket status.";
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// Get all buckets with statistics
$buckets = [];

// First check if follow_up_schedules table exists
$tableExists = false;
$checkTable = $conn->query("SHOW TABLES LIKE 'follow_up_schedules'");
if ($checkTable && $checkTable->num_rows > 0) {
    $tableExists = true;
}

if ($tableExists) {
    // Full query with follow-up statistics
    $sql = "
        SELECT db.*, 
               COUNT(DISTINCT tld.id) as disposition_count,
               COUNT(DISTINCT CASE WHEN tld.is_active = 1 THEN tld.id END) as active_dispositions,
               COUNT(DISTINCT fs.id) as scheduled_followups,
               COUNT(DISTINCT CASE WHEN fs.status = 'scheduled' THEN fs.id END) as pending_followups
        FROM disposition_buckets db
        LEFT JOIN team_leader_dispositions tld ON db.id = tld.bucket_id
        LEFT JOIN follow_up_schedules fs ON db.id = fs.bucket_id
        GROUP BY db.id, db.bucket_name, db.description, db.has_calendar_enabled, db.created_by, db.created_at, db.updated_at, db.is_active
        ORDER BY db.created_at DESC
    ";
} else {
    // Simplified query without follow-up statistics
    $sql = "
        SELECT db.*, 
               COUNT(DISTINCT tld.id) as disposition_count,
               COUNT(DISTINCT CASE WHEN tld.is_active = 1 THEN tld.id END) as active_dispositions,
               0 as scheduled_followups,
               0 as pending_followups
        FROM disposition_buckets db
        LEFT JOIN team_leader_dispositions tld ON db.id = tld.bucket_id
        GROUP BY db.id, db.bucket_name, db.description, db.has_calendar_enabled, db.created_by, db.created_at, db.updated_at, db.is_active
        ORDER BY db.created_at DESC
    ";
}

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $buckets[] = $row;
    }
    $stmt->close();
} else {
    // Fallback: basic query without joins
    $stmt = $conn->prepare("SELECT * FROM disposition_buckets ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Add default statistics
            $row['disposition_count'] = 0;
            $row['active_dispositions'] = 0;
            $row['scheduled_followups'] = 0;
            $row['pending_followups'] = 0;
            $buckets[] = $row;
        }
        $stmt->close();
    }
}

// Get summary statistics
$stats = [];
if ($tableExists) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_buckets,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_buckets,
            SUM(CASE WHEN has_calendar_enabled = 1 THEN 1 ELSE 0 END) as calendar_enabled_buckets,
            (SELECT COUNT(*) FROM follow_up_schedules WHERE status = 'scheduled') as total_scheduled_followups
        FROM disposition_buckets
    ");
} else {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_buckets,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_buckets,
            SUM(CASE WHEN has_calendar_enabled = 1 THEN 1 ELSE 0 END) as calendar_enabled_buckets,
            0 as total_scheduled_followups
        FROM disposition_buckets
    ");
}

if ($stmt) {
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    // Fallback default stats
    $stats = [
        'total_buckets' => count($buckets),
        'active_buckets' => 0,
        'calendar_enabled_buckets' => 0,
        'total_scheduled_followups' => 0
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disposition Buckets Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .bucket-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .bucket-card.inactive {
            border-left-color: #6c757d;
            background-color: #f8f9fa;
            opacity: 0.7;
        }
        .bucket-card.calendar-enabled {
            border-left-color: #28a745;
        }
        .badge-calendar {
            background: linear-gradient(45deg, #28a745, #20c997);
        }
        .modal-header {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'superadmin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-collection-fill me-2"></i>Disposition Buckets Management</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBucketModal">
                        <i class="bi bi-plus-circle me-1"></i>Create New Bucket
                    </button>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Total Buckets</h6>
                                        <h2 class="mb-0"><?= $stats['total_buckets'] ?></h2>
                                    </div>
                                    <i class="bi bi-collection-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Active Buckets</h6>
                                        <h2 class="mb-0"><?= $stats['active_buckets'] ?></h2>
                                    </div>
                                    <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Calendar Enabled</h6>
                                        <h2 class="mb-0"><?= $stats['calendar_enabled_buckets'] ?></h2>
                                    </div>
                                    <i class="bi bi-calendar-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6>Scheduled Follow-ups</h6>
                                        <h2 class="mb-0"><?= $stats['total_scheduled_followups'] ?></h2>
                                    </div>
                                    <i class="bi bi-clock-fill fs-1 opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Buckets List -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0">
                            <i class="bi bi-collection me-2"></i>All Disposition Buckets
                            <span class="badge bg-primary"><?= count($buckets) ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($buckets)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-collection display-4 opacity-25"></i>
                                <p class="mt-3">No buckets created yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($buckets as $bucket): ?>
                                    <div class="col-12">
                                        <div class="card bucket-card <?= $bucket['is_active'] ? '' : 'inactive' ?> <?= $bucket['has_calendar_enabled'] ? 'calendar-enabled' : '' ?>">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-8">
                                                        <div class="d-flex align-items-center mb-2">
                                                            <h6 class="card-title mb-0 me-3">
                                                                <?= htmlspecialchars($bucket['bucket_name']) ?>
                                                            </h6>
                                                            <?php if ($bucket['is_active']): ?>
                                                                <span class="badge bg-success me-2">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary me-2">Inactive</span>
                                                            <?php endif; ?>
                                                            <?php if ($bucket['has_calendar_enabled']): ?>
                                                                <span class="badge badge-calendar me-2">
                                                                    <i class="bi bi-calendar-check me-1"></i>Calendar Enabled
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-muted mb-2">
                                                            <?= $bucket['description'] ? htmlspecialchars($bucket['description']) : 'No description provided' ?>
                                                        </p>
                                                        <div class="d-flex gap-3">
                                                            <small class="text-info">
                                                                <i class="bi bi-tags-fill me-1"></i><?= $bucket['disposition_count'] ?> Dispositions 
                                                                (<?= $bucket['active_dispositions'] ?> active)
                                                            </small>
                                                            <?php if ($bucket['has_calendar_enabled']): ?>
                                                                <small class="text-warning">
                                                                    <i class="bi bi-clock-fill me-1"></i><?= $bucket['pending_followups'] ?>/<?= $bucket['scheduled_followups'] ?> Follow-ups
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            Created: <?= date('d-M-Y H:i', strtotime($bucket['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <div class="col-md-4 text-end">
                                                        <div class="btn-group-vertical gap-2">
                                                            <button class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editBucket(<?= htmlspecialchars(json_encode($bucket)) ?>)">
                                                                <i class="bi bi-pencil-fill me-1"></i>Edit
                                                            </button>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="bucket_id" value="<?= $bucket['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $bucket['is_active'] ? 0 : 1 ?>">
                                                                <button type="submit" name="toggle_status" 
                                                                        class="btn btn-sm btn-<?= $bucket['is_active'] ? 'warning' : 'success' ?> w-100"
                                                                        onclick="return confirm('Are you sure you want to <?= $bucket['is_active'] ? 'deactivate' : 'activate' ?> this bucket?')">
                                                                    <i class="bi bi-<?= $bucket['is_active'] ? 'pause' : 'play' ?>-fill me-1"></i>
                                                                    <?= $bucket['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Create Bucket Modal -->
    <div class="modal fade" id="createBucketModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create New Disposition Bucket</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="bucket_name" class="form-label">Bucket Name *</label>
                            <input type="text" name="bucket_name" id="bucket_name" class="form-control" required 
                                   placeholder="e.g., Follow Up, Payment Processing">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3" 
                                      placeholder="Brief description of this bucket's purpose"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="has_calendar_enabled" id="has_calendar_enabled" class="form-check-input">
                                <label for="has_calendar_enabled" class="form-check-label">
                                    <i class="bi bi-calendar-check me-1"></i>Enable Calendar Functionality
                                    <small class="form-text text-muted d-block">
                                        When enabled, Team Leaders can schedule follow-up dates and times for dispositions in this bucket.
                                    </small>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_bucket" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Create Bucket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Bucket Modal -->
    <div class="modal fade" id="editBucketModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Disposition Bucket</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="bucket_id" id="edit_bucket_id">
                        <div class="mb-3">
                            <label for="edit_bucket_name" class="form-label">Bucket Name *</label>
                            <input type="text" name="bucket_name" id="edit_bucket_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="has_calendar_enabled" id="edit_has_calendar_enabled" class="form-check-input">
                                <label for="edit_has_calendar_enabled" class="form-check-label">
                                    <i class="bi bi-calendar-check me-1"></i>Enable Calendar Functionality
                                    <small class="form-text text-muted d-block">
                                        When enabled, Team Leaders can schedule follow-up dates and times for dispositions in this bucket.
                                    </small>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_bucket" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i>Update Bucket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editBucket(bucket) {
            document.getElementById('edit_bucket_id').value = bucket.id;
            document.getElementById('edit_bucket_name').value = bucket.bucket_name;
            document.getElementById('edit_description').value = bucket.description || '';
            document.getElementById('edit_has_calendar_enabled').checked = bucket.has_calendar_enabled == 1;
            
            new bootstrap.Modal(document.getElementById('editBucketModal')).show();
        }
    </script>
</body>
</html>