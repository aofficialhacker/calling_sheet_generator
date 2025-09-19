<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Check admin exists
if (empty($adminId)) {
    die("Error: Admin ID not found in session. Please login again.");
}

// Get admin's multi-status setting
$multiStatusEnabled = 0;
try {
    $multiStatusStmt = $conn->prepare("SELECT multi_status_selection FROM lv_admin_users WHERE admin_id = ?");
    $multiStatusStmt->bind_param("s", $adminId);
    $multiStatusStmt->execute();
    $multiStatusResult = $multiStatusStmt->get_result()->fetch_assoc();
    $multiStatusEnabled = $multiStatusResult['multi_status_selection'] ?? 0;
    $multiStatusStmt->close();
} catch (Exception $e) {
    // Continue with default value
}

// Get batches data
$batches_data = [];
try {
    $stmt = $conn->prepare("SELECT id, original_filename, upload_time, product_code FROM lv_file_batches WHERE admin_id = ? ORDER BY upload_time DESC");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Get record count
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM lv_final_call_logs WHERE batch_id = ?");
        $count_stmt->bind_param("s", $row['id']);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $row['record_count'] = $count_result->fetch_assoc()['count'];
        $count_stmt->close();
        
        // Get product name (with fallback)
        $row['product_name'] = $row['product_code']; // Default fallback
        try {
            $prod_stmt = $conn->prepare("SELECT product_name FROM lv_products WHERE product_code = ?");
            if ($prod_stmt) {
                $prod_stmt->bind_param("s", $row['product_code']);
                $prod_stmt->execute();
                $prod_result = $prod_stmt->get_result();
                $prod_row = $prod_result->fetch_assoc();
                if ($prod_row && $prod_row['product_name']) {
                    $row['product_name'] = $prod_row['product_name'];
                }
                $prod_stmt->close();
            }
        } catch (Exception $e) {
            // Use product_code as fallback
        }
        
        $batches_data[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching batches: " . $e->getMessage());
}

// Get dispositions (exclude Interested)
$dispositions = [];
try {
    $disp_result = $conn->query("SELECT DISTINCT code, description FROM lv_disposition_codes WHERE is_active = 1 AND description NOT LIKE '%Interested%' ORDER BY code");
    if ($disp_result) {
        while ($disp = $disp_result->fetch_assoc()) {
            $dispositions[] = $disp;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching dispositions: " . $e->getMessage());
}

// Get callers (optional - may not exist)
$callers = [];
try {
    // Check if callers table exists first
    $table_check = $conn->query("SHOW TABLES LIKE 'callers'");
    if ($table_check && $table_check->num_rows > 0) {
        $callers_stmt = $conn->prepare("SELECT caller_id, name FROM lv_callers WHERE admin_id = ? AND is_active = 1 ORDER BY name");
        if ($callers_stmt) {
            $callers_stmt->bind_param("s", $adminId);
            $callers_stmt->execute();
            $callers_result = $callers_stmt->get_result();
            while ($caller = $callers_result->fetch_assoc()) {
                $callers[] = $caller;
            }
            $callers_stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error fetching callers: " . $e->getMessage());
    // Continue without callers - not critical
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Batches - Fixed</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1050; display: none; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; animation: spin 1.5s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="text-white mt-3">Generating PDF, please wait...</p></div>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-stack me-2"></i>View Batches</h1>
                </div>

                <!-- Advanced Filter & Download Section -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Advanced Download Filters</h5>
                    </div>
                    <div class="card-body">
                        <form id="download-filter-form">
                            <!-- First Row -->
                            <div class="row g-3">
                                <!-- Status Filter -->
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Filter by Status</strong></label>
                                    <?php if ($multiStatusEnabled): ?>
                                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="select-all-status" value="">
                                                <label class="form-check-label fw-bold" for="select-all-status">Select All</label>
                                            </div>
                                            <hr class="my-1">
                                            <?php foreach($dispositions as $dispo): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input status-checkbox" type="checkbox" 
                                                           id="status-<?= $dispo['code'] ?>" 
                                                           value="<?= htmlspecialchars($dispo['description']) ?>">
                                                    <label class="form-check-label" for="status-<?= $dispo['code'] ?>">
                                                        <?= htmlspecialchars($dispo['code']) ?> - <?= htmlspecialchars($dispo['description']) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <select class="form-select" id="single-status-select">
                                            <option value="">-- Select Status --</option>
                                            <?php foreach($dispositions as $dispo): ?>
                                                <option value="<?= htmlspecialchars($dispo['description']) ?>">
                                                    <?= htmlspecialchars($dispo['code']) ?> - <?= htmlspecialchars($dispo['description']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Caller Filter -->
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Caller Filter</strong></label>
                                    <select class="form-select" id="caller-filter">
                                        <option value="">All Callers</option>
                                        <?php if (!empty($callers)): ?>
                                            <?php foreach($callers as $caller): ?>
                                                <option value="<?= htmlspecialchars($caller['caller_id']) ?>">
                                                    <?= htmlspecialchars($caller['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option disabled>No callers assigned</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Second Row - Product and Batch Selection -->
                            <div class="row g-3 mt-3" style="background-color: #e3f2fd; padding: 15px; border-radius: 8px; border-left: 4px solid #2196f3;">
                                <div class="col-12">
                                    <h6 class="text-primary mb-3"><i class="bi bi-filter-circle me-2"></i>Product and Batch Selection</h6>
                                </div>
                                
                                <!-- Product Selection -->
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Select Product</strong></label>
                                    <select class="form-select" id="product-select">
                                        <option value="">All Products</option>
                                        <?php 
                                        $unique_products = [];
                                        foreach($batches_data as $batch) {
                                            $unique_products[$batch['product_code']] = $batch['product_name'];
                                        }
                                        foreach($unique_products as $code => $name): ?>
                                            <option value="<?= htmlspecialchars($code) ?>">
                                                <?= htmlspecialchars($name) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Filter by product type</small>
                                </div>
                                
                                <!-- Batch Selection -->
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Select Batch</strong></label>
                                    <select class="form-select" id="batch-select">
                                        <option value="">All Batches</option>
                                        <?php foreach($batches_data as $batch): ?>
                                            <option value="<?= htmlspecialchars($batch['id']) ?>" data-product="<?= htmlspecialchars($batch['product_code']) ?>">
                                                <?= htmlspecialchars($batch['id']) ?> - <?= htmlspecialchars($batch['product_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Choose specific batch</small>
                                </div>
                                
                                <!-- Download Scope -->
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Download Scope</strong></label>
                                    <select class="form-select" id="download-scope" required>
                                        <option value="">-- Select Scope --</option>
                                        <option value="batch-wise">Selected Batch Only</option>
                                        <option value="all-batch">All Batches</option>
                                        <option value="product-wise">Selected Product Only</option>
                                        <option value="all-product">All Products</option>
                                    </select>
                                    <small class="text-muted">Define download scope</small>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <button type="button" class="btn btn-outline-secondary" id="reset-filters">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Reset Filters
                                </button>
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-download me-2"></i>Generate & Download PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Batches Table -->
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-table me-2"></i>Your Uploaded Batches (<?= count($batches_data) ?> total)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($batches_data) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Batch No</th>
                                            <th>Product</th>
                                            <th>Original Filename</th>
                                            <th>Records</th>
                                            <th>Uploaded On</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($batches_data as $row): ?>
                                        <tr>
                                            <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($row['id']) ?></span></td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product_name']) ?></span></td>
                                            <td title="<?= htmlspecialchars($row['original_filename']) ?>"><?= htmlspecialchars(substr($row['original_filename'], 0, 30)) . (strlen($row['original_filename']) > 30 ? '...' : '') ?></td>
                                            <td><span class="badge bg-success"><?= htmlspecialchars($row['record_count']) ?></span></td>
                                            <td><?= date('d-M-Y H:i', strtotime($row['upload_time'])) ?></td>
                                            <td>
                                                <a href="pdf_download_handler.php?batch_id=<?= $row['id'] ?>" class="btn btn-danger btn-sm download-pdf-btn" title="Download PDF for this batch">
                                                    <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <h5><i class="bi bi-exclamation-triangle me-2"></i>No batches found</h5>
                                <p>No batches have been uploaded for your admin account yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const multiStatusEnabled = <?= json_encode($multiStatusEnabled) ?>;
    const productSelect = document.getElementById('product-select');
    const batchSelect = document.getElementById('batch-select');
    const downloadScope = document.getElementById('download-scope');
    const downloadForm = document.getElementById('download-filter-form');
    const resetButton = document.getElementById('reset-filters');
    
    // Handle product selection changes - filter batches
    productSelect.addEventListener('change', function() {
        const selectedProduct = this.value;
        const batchOptions = batchSelect.querySelectorAll('option[data-product]');
        
        batchOptions.forEach(option => {
            if (selectedProduct === '' || option.getAttribute('data-product') === selectedProduct) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
        
        batchSelect.value = '';
    });
    
    // Handle multi-status selection if enabled
    if (multiStatusEnabled) {
        const selectAllStatus = document.getElementById('select-all-status');
        const statusCheckboxes = document.querySelectorAll('.status-checkbox');
        
        selectAllStatus.addEventListener('change', function() {
            statusCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        
        statusCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(statusCheckboxes).every(cb => cb.checked);
                const noneChecked = Array.from(statusCheckboxes).every(cb => !cb.checked);
                
                selectAllStatus.checked = allChecked;
                selectAllStatus.indeterminate = !allChecked && !noneChecked;
            });
        });
    }
    
    // Handle form submission
    downloadForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Validate status selection
        let selectedStatuses = [];
        if (multiStatusEnabled) {
            const checkedBoxes = document.querySelectorAll('.status-checkbox:checked');
            selectedStatuses = Array.from(checkedBoxes).map(cb => cb.value);
            if (selectedStatuses.length === 0) {
                alert('Please select at least one status.');
                return;
            }
        } else {
            const singleStatus = document.getElementById('single-status-select').value;
            if (!singleStatus) {
                alert('Please select a status.');
                return;
            }
            selectedStatuses = [singleStatus];
        }
        
        // Validate scope selection
        const scope = downloadScope.value;
        if (!scope) {
            alert('Please select a download scope.');
            return;
        }
        
        // Build URL parameters
        let url = 'pdf_download_handler.php?';
        const params = new URLSearchParams();
        
        // Add status parameters
        if (selectedStatuses.length === 1) {
            params.append('disposition', selectedStatuses[0]);
        } else {
            selectedStatuses.forEach((status, index) => {
                params.append(`disposition[${index}]`, status);
            });
        }
        
        params.append('scope', scope);
        
        // Handle different scopes
        const selectedProduct = productSelect.value;
        const selectedBatch = batchSelect.value;
        
        if (scope === 'batch-wise') {
            if (!selectedBatch) {
                alert('Please select a batch for batch-wise download.');
                return;
            }
            params.append('batch_id', selectedBatch);
        } else if (scope === 'product-wise') {
            if (!selectedProduct) {
                alert('Please select a product for product-wise download.');
                return;
            }
            params.append('product_code', selectedProduct);
        }
        
        // Add filters
        if (selectedProduct) {
            params.append('product_code', selectedProduct);
        }
        if (selectedBatch) {
            params.append('batch_id', selectedBatch);
        }
        
        // Add caller filter
        const callerFilter = document.getElementById('caller-filter').value;
        if (callerFilter) {
            params.append('caller_id', callerFilter);
        }
        
        // Add download token and start download
        const downloadToken = new Date().getTime();
        params.append('download_token', downloadToken);
        
        url += params.toString();
        startPdfDownload(url);
    });
    
    // Handle reset
    resetButton.addEventListener('click', function() {
        downloadForm.reset();
        
        if (multiStatusEnabled) {
            document.querySelectorAll('.status-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('select-all-status').checked = false;
            document.getElementById('select-all-status').indeterminate = false;
        }
        
        // Show all batch options
        const batchOptions = batchSelect.querySelectorAll('option[data-product]');
        batchOptions.forEach(option => {
            option.style.display = 'block';
        });
    });
    
    // PDF download function
    const startPdfDownload = function(url) {
        const loadingOverlay = document.getElementById('loading-overlay');
        loadingOverlay.style.display = 'flex';
        
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
        iframe.src = url;
        
        setTimeout(() => {
            loadingOverlay.style.display = 'none';
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        }, 3000);
    };

    // Handle existing batch PDF downloads
    document.querySelectorAll('.download-pdf-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            startPdfDownload(this.href);
        });
    });
});
</script>
</body>
</html>