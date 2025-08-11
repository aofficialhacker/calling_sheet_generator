<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();
$message = '';
$error = '';

// Handle disposition operations
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create':
            $code = trim($_POST['code'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? '';
            
            if ($code && $description && $category) {
                $stmt = $conn->prepare("INSERT INTO disposition_codes (code, description, category, created_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $code, $description, $category, $_SESSION['superadmin_id']);
                
                if ($stmt->execute()) {
                    $message = "Disposition code created successfully.";
                } else {
                    $error = "Failed to create disposition code. Code may already exist.";
                }
                $stmt->close();
            } else {
                $error = "All fields are required.";
            }
            break;
            
        case 'toggle_status':
            $dispositionId = $_POST['disposition_id'] ?? 0;
            $currentStatus = $_POST['current_status'] ?? 0;
            $newStatus = $currentStatus ? 0 : 1;
            
            $stmt = $conn->prepare("UPDATE disposition_codes SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $newStatus, $dispositionId);
            
            if ($stmt->execute()) {
                $message = "Disposition status updated.";
            }
            $stmt->close();
            break;
            
        case 'update':
            $dispositionId = $_POST['disposition_id'] ?? 0;
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? '';
            
            if ($dispositionId && $description && $category) {
                $stmt = $conn->prepare("UPDATE disposition_codes SET description = ?, category = ? WHERE id = ?");
                $stmt->bind_param("ssi", $description, $category, $dispositionId);
                
                if ($stmt->execute()) {
                    $message = "Disposition updated successfully.";
                }
                $stmt->close();
            }
            break;
    }
}

// Fetch all dispositions
$dispositions = $conn->query("
    SELECT dc.*, 
           COUNT(DISTINCT fcl.id) as usage_count
    FROM disposition_codes dc
    LEFT JOIN final_call_logs fcl ON dc.description = fcl.disposition
    GROUP BY dc.id
    ORDER BY dc.category, dc.code
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Dispositions - Superadmin Panel</title>
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
        .category-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
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
                            <a class="nav-link" href="manage_admins.php">
                                <i class="bi bi-people-fill me-2"></i>Manage Admins
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_products.php">
                                <i class="bi bi-box-seam me-2"></i>Products
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="manage_dispositions.php">
                                <i class="bi bi-list-check me-2"></i>Dispositions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="vendor_requests.php">
                                <i class="bi bi-bell-fill me-2"></i>Unit Requests
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

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-list-check me-2"></i>Manage Disposition Codes</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDispositionModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Disposition
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

                <!-- Dispositions Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Usage Count</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($dispositions && $dispositions->num_rows > 0): ?>
                                        <?php while($disposition = $dispositions->fetch_assoc()): ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($disposition['code']) ?></code></td>
                                                <td><?= htmlspecialchars($disposition['description']) ?></td>
                                                <td>
                                                    <span class="badge category-badge <?= $disposition['category'] == 'connected' ? 'bg-success' : 'bg-warning' ?>">
                                                        <?= ucfirst($disposition['category']) ?>
                                                    </span>
                                                </td>
                                                <td><?= number_format($disposition['usage_count']) ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="disposition_id" value="<?= $disposition['id'] ?>">
                                                        <input type="hidden" name="current_status" value="<?= $disposition['is_active'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $disposition['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                                                            <?= $disposition['is_active'] ? 'Active' : 'Inactive' ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-info text-white" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editDispositionModal"
                                                            data-id="<?= $disposition['id'] ?>"
                                                            data-code="<?= htmlspecialchars($disposition['code']) ?>"
                                                            data-description="<?= htmlspecialchars($disposition['description']) ?>"
                                                            data-category="<?= $disposition['category'] ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No disposition codes found</td>
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

    <!-- Create Disposition Modal -->
    <div class="modal fade" id="createDispositionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Disposition Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="code" class="form-label">Code</label>
                            <input type="text" class="form-control" id="code" name="code" 
                                   required maxlength="10" placeholder="e.g., 11, 12, 21">
                            <small class="text-muted">Use numeric codes (e.g., 11-17 for connected, 21-26 for not connected)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   required maxlength="255" placeholder="e.g., Interested, Not Interested">
                        </div>
                        
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <option value="">-- Select Category --</option>
                                <option value="connected">Connected (Y)</option>
                                <option value="not_connected">Not Connected (N)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Disposition</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Disposition Modal -->
    <div class="modal fade" id="editDispositionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Disposition Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="disposition_id" id="edit_disposition_id">
                        
                        <div class="mb-3">
                            <label for="edit_code" class="form-label">Code</label>
                            <input type="text" class="form-control" id="edit_code" disabled>
                            <small class="text-muted">Code cannot be changed after creation</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="edit_description" name="description" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Category</label>
                            <select class="form-select" id="edit_category" name="category" required>
                                <option value="connected">Connected (Y)</option>
                                <option value="not_connected">Not Connected (N)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Disposition</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle edit modal data
        document.getElementById('editDispositionModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const dispositionId = button.getAttribute('data-id');
            const code = button.getAttribute('data-code');
            const description = button.getAttribute('data-description');
            const category = button.getAttribute('data-category');
            
            document.getElementById('edit_disposition_id').value = dispositionId;
            document.getElementById('edit_code').value = code;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_category').value = category;
        });
    </script>
</body>
</html>