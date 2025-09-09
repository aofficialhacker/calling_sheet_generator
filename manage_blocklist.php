<?php
require_once 'db_config.php';
require_once 'blocklist_utils.php';
requireAdmin();

$adminId = $_SESSION['admin_id'];

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';

// Get blocklist data
$numbers = getBlocklistNumbers($adminId, $limit, $offset, $search);
$totalNumbers = getBlocklistCount($adminId, $search);
$totalPages = ceil($totalNumbers / $limit);
$stats = getBlocklistStats($adminId);

// Get unique batches for batch deletion modal only
$conn = getDBConnection();
$batchStmt = $conn->prepare("SELECT DISTINCT batch_id FROM blocklist_numbers WHERE admin_id = ? AND batch_id IS NOT NULL ORDER BY batch_id DESC");
$batchStmt->bind_param("s", $adminId);
$batchStmt->execute();
$batches = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$batchStmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Blocklist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stats-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px; }
        .stats-card .card-body { padding: 1.5rem; }
        .table th { background-color: #f8f9fa; border-top: none; }
        .btn-group-sm > .btn { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-shield-exclamation me-2"></i>Manage Blocklist</h1>
                </div>

                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['flash_message']['text']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['flash_message']); ?>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-shield-exclamation fs-1 mb-2"></i>
                                <h3 class="mb-1"><?= number_format($stats['total_blocked']) ?></h3>
                                <p class="mb-0">Total Blocked Numbers</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-layers fs-1 mb-2"></i>
                                <h3 class="mb-1"><?= $stats['total_batches'] ?></h3>
                                <p class="mb-0">Upload Batches</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar3 fs-1 mb-2"></i>
                                <h5 class="mb-1"><?= $stats['latest_upload'] ? date('M j, Y', strtotime($stats['latest_upload'])) : 'Never' ?></h5>
                                <p class="mb-0">Latest Upload</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload and Add Sections -->
                <div class="row mb-4">
                    <!-- Upload Excel -->
                    <div class="col-md-8">
                        <div class="card shadow-sm">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-cloud-upload me-2"></i>Upload Blocklist Excel</h5>
                            </div>
                            <div class="card-body">
                                <form action="upload_blocklist.php" method="post" enctype="multipart/form-data" id="upload-form">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label for="blocklistFile" class="form-label"><strong>Select Excel File</strong></label>
                                            <input class="form-control" type="file" id="blocklistFile" name="blocklistFile" 
                                                   accept=".xlsx,.xls,.csv" required>
                                            <small class="text-muted">Supported: .xlsx, .xls, .csv (max 50,000 rows)</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="notes" class="form-label"><strong>Notes (Optional)</strong></label>
                                            <input type="text" class="form-control" id="notes" name="notes" 
                                                   placeholder="e.g., DND numbers">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-danger">
                                                <i class="bi bi-upload me-2"></i>Upload Blocklist
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Add Single Number -->
                    <div class="col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Single Number</h5>
                            </div>
                            <div class="card-body">
                                <form action="upload_blocklist.php" method="post">
                                    <input type="hidden" name="action" value="add_single">
                                    <div class="mb-3">
                                        <label for="single_mobile_no" class="form-label"><strong>Mobile Number</strong></label>
                                        <input type="text" class="form-control" id="single_mobile_no" name="single_mobile_no" 
                                               placeholder="9876543210" required pattern="[0-9+\-\s()]+">
                                    </div>
                                    <div class="mb-3">
                                        <label for="single_notes" class="form-label">Notes</label>
                                        <input type="text" class="form-control" id="single_notes" name="single_notes" 
                                               placeholder="Optional">
                                    </div>
                                    <button type="submit" class="btn btn-warning btn-sm w-100">
                                        <i class="bi bi-plus me-1"></i>Add to Blocklist
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-md-8">
                                <label for="search" class="form-label">Search Numbers</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="search" name="search" 
                                           value="<?= htmlspecialchars($search) ?>" placeholder="Search by number, notes, or batch ID">
                                    <button class="btn btn-outline-secondary" type="button" id="searchBtn">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-secondary" id="clearFilters">
                                    <i class="bi bi-x-circle me-1"></i>Clear Search
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Blocklist Table -->
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Blocked Numbers (<?= number_format($totalNumbers) ?>)</h5>
                        <?php if ($totalNumbers > 0): ?>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#confirmDeleteModal">
                                    <i class="bi bi-trash me-1"></i>Delete Selected Batch
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($numbers)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-shield-check text-success" style="font-size: 3rem;"></i>
                                <h5 class="mt-3">No blocked numbers found</h5>
                                <p class="text-muted">Upload an Excel file or add numbers manually to start blocking.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Mobile Number</th>
                                            <th>Upload Date</th>
                                            <th>Batch ID</th>
                                            <th>Notes</th>
                                            <th width="80">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($numbers as $number): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($number['mobile_no']) ?></strong>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y g:i A', strtotime($number['upload_date'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($number['batch_id']): ?>
                                                        <span class="badge bg-secondary"><?= htmlspecialchars($number['batch_id']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Manual</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?= htmlspecialchars($number['notes'] ?? '') ?></small>
                                                </td>
                                                <td>
                                                    <form action="upload_blocklist.php" method="post" style="display: inline;" 
                                                          onsubmit="return confirm('Remove this number from blocklist?')">
                                                        <input type="hidden" name="action" value="delete_number">
                                                        <input type="hidden" name="mobile_no" value="<?= htmlspecialchars($number['mobile_no']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav class="mt-3">
                                    <ul class="pagination justify-content-center">
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Batch Delete Confirmation Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Batch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form action="upload_blocklist.php" method="post" id="deleteBatchForm">
                        <input type="hidden" name="action" value="delete_batch">
                        <div class="mb-3">
                            <label for="batch_to_delete" class="form-label">Select Batch to Delete</label>
                            <select class="form-select" name="batch_id" id="batch_to_delete" required>
                                <option value="">Choose batch...</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                        <?= htmlspecialchars($batch['batch_id']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            This will permanently remove all numbers from the selected batch.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="deleteBatchForm" class="btn btn-danger">Delete Batch</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Search functionality
            const searchInput = document.getElementById('search');
            const searchBtn = document.getElementById('searchBtn');
            const clearFilters = document.getElementById('clearFilters');

            function performSearch() {
                const search = searchInput.value.trim();
                let url = '?';
                if (search) url += 'search=' + encodeURIComponent(search);
                window.location.href = url || 'manage_blocklist.php';
            }

            searchBtn.addEventListener('click', performSearch);
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });

            clearFilters.addEventListener('click', function() {
                window.location.href = 'manage_blocklist.php';
            });

            // Form validation
            document.getElementById('upload-form').addEventListener('submit', function(e) {
                const fileInput = document.getElementById('blocklistFile');
                if (fileInput.files.length === 0) {
                    e.preventDefault();
                    alert('Please select a file to upload.');
                    return;
                }

                // Show loading indication
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                submitBtn.disabled = true;
            });
        });
    </script>
</body>
</html>