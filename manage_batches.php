<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Debug: Check if admin_id is set
if (empty($adminId)) {
    die("Error: Admin ID not found in session. Please login again.");
}

// Fetch admin's multi-status selection setting
$multiStatusStmt = $conn->prepare("SELECT multi_status_selection FROM admin_users WHERE admin_id = ?");
$multiStatusStmt->bind_param("s", $adminId);
$multiStatusStmt->execute();
$multiStatusResult = $multiStatusStmt->get_result()->fetch_assoc();
$multiStatusEnabled = $multiStatusResult['multi_status_selection'] ?? 0;
$multiStatusStmt->close();

// Simple batch fetching without complex joins
$batches_data = [];
$debug_count = 0;
$existing_admin_ids = [];
$dispositions = null;
$products = null;

try {
    // Fetch batches - simple query first
    $stmt = $conn->prepare("SELECT id, original_filename, upload_time, product_code FROM file_batches WHERE admin_id = ? ORDER BY upload_time DESC");
    $stmt->bind_param("s", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Get record count for each batch
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM final_call_logs WHERE batch_id = ?");
        $count_stmt->bind_param("s", $row['id']);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $row['record_count'] = $count_result->fetch_assoc()['count'];
        $count_stmt->close();
        
        // Get product name
        $prod_stmt = $conn->prepare("SELECT product_name FROM products WHERE product_code = ?");
        $prod_stmt->bind_param("s", $row['product_code']);
        $prod_stmt->execute();
        $prod_result = $prod_stmt->get_result();
        $prod_row = $prod_result->fetch_assoc();
        $row['product_name'] = $prod_row ? $prod_row['product_name'] : $row['product_code'];
        $prod_stmt->close();
        
        $batches_data[] = $row;
    }
    $stmt->close();
    
    // Debug info
    $debug_count = count($batches_data);
    
    // Get existing admin IDs for debug
    $admin_result = $conn->query("SELECT DISTINCT admin_id FROM file_batches LIMIT 5");
    if ($admin_result) {
        while ($row = $admin_result->fetch_assoc()) {
            $existing_admin_ids[] = $row['admin_id'];
        }
    }
    
    // Fetch dispositions
    $dispositions = $conn->query("SELECT DISTINCT code, description FROM disposition_codes WHERE is_active = 1 ORDER BY code");
    
    // Fetch products used by this admin
    $products = $conn->query("SELECT DISTINCT product_code FROM file_batches WHERE admin_id = '{$adminId}'");
    
} catch (Exception $e) {
    error_log("Error in manage_batches.php: " . $e->getMessage());
    die("Database error occurred. Please check the logs.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Batches</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1050; display: none; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; animation: spin 1.5s linear infinite; }
        .loading-text { color: white; margin-top: 20px; font-size: 1.2rem; font-weight: bold; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="loading-text" id="loading-message">Generating PDF, please wait...</p></div>
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
                            <div class="row g-3">
                                <!-- Status Filter -->
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Filter by Status</strong></label>
                                    <?php if ($multiStatusEnabled): ?>
                                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="select-all-status" value="">
                                                <label class="form-check-label fw-bold" for="select-all-status">
                                                    Select All
                                                </label>
                                            </div>
                                            <hr class="my-1">
                                            <?php if ($dispositions && $dispositions->num_rows > 0): ?>
                                                <?php $dispositions->data_seek(0); ?>
                                                <?php while($dispo = $dispositions->fetch_assoc()): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input status-checkbox" type="checkbox" 
                                                               id="status-<?= $dispo['code'] ?>" 
                                                               value="<?= htmlspecialchars($dispo['description']) ?>">
                                                        <label class="form-check-label" for="status-<?= $dispo['code'] ?>">
                                                            <?= htmlspecialchars($dispo['code']) ?> - <?= htmlspecialchars($dispo['description']) ?>
                                                        </label>
                                                    </div>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <select class="form-select" id="single-status-select">
                                            <option value="">-- Select Status --</option>
                                            <?php if ($dispositions && $dispositions->num_rows > 0): ?>
                                                <?php $dispositions->data_seek(0); ?>
                                                <?php while($dispo = $dispositions->fetch_assoc()): ?>
                                                    <option value="<?= htmlspecialchars($dispo['description']) ?>">
                                                        <?= htmlspecialchars($dispo['code']) ?> - <?= htmlspecialchars($dispo['description']) ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Download Scope -->
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Download Scope</strong></label>
                                    <select class="form-select" id="download-scope" required>
                                        <option value="">-- Select Scope --</option>
                                        <option value="batch-wise">Batch-wise (Single Batch)</option>
                                        <option value="all-batch">All Batches</option>
                                        <option value="product-wise">Product-wise (Single Product)</option>
                                        <option value="all-product">All Products</option>
                                    </select>
                                </div>
                                
                                <!-- Specific Selection (Batch/Product) -->
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Specific Selection</strong></label>
                                    
                                    <!-- Batch Selection -->
                                    <select class="form-select" id="batch-select" style="display: none;">
                                        <option value="">-- Select Batch --</option>
                                        <?php foreach($batches_data as $batch): ?>
                                            <option value="<?= htmlspecialchars($batch['id']) ?>">
                                                <?= htmlspecialchars($batch['id']) ?> (<?= htmlspecialchars($batch['product_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <!-- Product Selection -->
                                    <select class="form-select" id="product-select" style="display: none;">
                                        <option value="">-- Select Product --</option>
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
                                    
                                    <div id="scope-info" class="text-muted small mt-1">
                                        Select a download scope first
                                    </div>
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

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
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
                                    <?php if (count($batches_data) > 0): ?>
                                        <?php foreach($batches_data as $row): ?>
                                        <tr>
                                            <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($row['id']) ?></span></td>
                                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td title="<?= htmlspecialchars($row['original_filename']) ?>"><?= htmlspecialchars(substr($row['original_filename'], 0, 30)) . (strlen($row['original_filename']) > 30 ? '...' : '') ?></td>
                                            <td><?= htmlspecialchars($row['record_count']) ?></td>
                                            <td><?= date('d-M-Y H:i', strtotime($row['upload_time'])) ?></td>
                                            <td>
                                                <a href="generate_pdf.php?batch_id=<?= $row['id'] ?>" class="btn btn-danger btn-sm download-pdf-btn" title="Download PDF for this batch">
                                                    <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center text-muted">
                                            No batches have been uploaded yet. <br>
                                            <small>Debug Info: Your Admin ID = "<?= htmlspecialchars($adminId) ?>", 
                                            Your Batch Count = <?= $debug_count ?><br>
                                            Admin IDs in DB: <?= implode(', ', array_map('htmlspecialchars', $existing_admin_ids)) ?></small>
                                        </td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const multiStatusEnabled = <?= json_encode($multiStatusEnabled) ?>;
    const downloadScope = document.getElementById('download-scope');
    const batchSelect = document.getElementById('batch-select');
    const productSelect = document.getElementById('product-select');
    const scopeInfo = document.getElementById('scope-info');
    const downloadForm = document.getElementById('download-filter-form');
    const resetButton = document.getElementById('reset-filters');
    
    // Handle scope selection changes
    downloadScope.addEventListener('change', function() {
        const scope = this.value;
        
        // Hide all specific selects first
        batchSelect.style.display = 'none';
        productSelect.style.display = 'none';
        
        switch(scope) {
            case 'batch-wise':
                batchSelect.style.display = 'block';
                batchSelect.required = true;
                productSelect.required = false;
                scopeInfo.textContent = 'Select a specific batch to download';
                break;
            case 'all-batch':
                batchSelect.required = false;
                productSelect.required = false;
                scopeInfo.textContent = 'All batches will be included';
                break;
            case 'product-wise':
                productSelect.style.display = 'block';
                productSelect.required = true;
                batchSelect.required = false;
                scopeInfo.textContent = 'Select a specific product to download';
                break;
            case 'all-product':
                batchSelect.required = false;
                productSelect.required = false;
                scopeInfo.textContent = 'All products will be included';
                break;
            default:
                batchSelect.required = false;
                productSelect.required = false;
                scopeInfo.textContent = 'Select a download scope first';
        }
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
        let url = 'generate_pdf.php?';
        const params = new URLSearchParams();
        
        // Add status parameters
        if (selectedStatuses.length === 1) {
            params.append('disposition', selectedStatuses[0]);
        } else {
            selectedStatuses.forEach((status, index) => {
                params.append(`disposition[${index}]`, status);
            });
        }
        
        // Add scope parameters
        params.append('scope', scope);
        
        if (scope === 'batch-wise') {
            const batchId = batchSelect.value;
            if (!batchId) {
                alert('Please select a batch.');
                return;
            }
            params.append('batch_id', batchId);
        } else if (scope === 'product-wise') {
            const productCode = productSelect.value;
            if (!productCode) {
                alert('Please select a product.');
                return;
            }
            params.append('product_code', productCode);
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
        
        // Reset multi-status checkboxes
        if (multiStatusEnabled) {
            document.querySelectorAll('.status-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('select-all-status').checked = false;
            document.getElementById('select-all-status').indeterminate = false;
        }
        
        // Reset scope UI
        batchSelect.style.display = 'none';
        productSelect.style.display = 'none';
        batchSelect.required = false;
        productSelect.required = false;
        scopeInfo.textContent = 'Select a download scope first';
    });
    
    // Enhanced PDF download function with proper timing
    const startPdfDownload = function(url) {
        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingMessage = document.getElementById('loading-message');
        
        loadingOverlay.style.display = 'flex';
        loadingMessage.textContent = 'Generating PDF, please wait...';
        
        let downloadDetected = false;
        let startTime = Date.now();
        
        // Create hidden iframe for download detection
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.style.width = '1px';
        iframe.style.height = '1px';
        document.body.appendChild(iframe);
        
        // Monitor for download completion
        const checkDownloadComplete = () => {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            
            if (!downloadDetected) {
                // Update message with elapsed time
                loadingMessage.textContent = `Generating PDF, please wait... (${elapsed}s)`;
                
                // Continue checking
                setTimeout(checkDownloadComplete, 1000);
                
                // Auto-complete after 30 seconds
                if (elapsed >= 30) {
                    downloadDetected = true;
                    loadingMessage.textContent = 'Download should have started. Please check your downloads folder.';
                    setTimeout(() => {
                        loadingOverlay.style.display = 'none';
                        cleanup();
                    }, 3000);
                }
            }
        };
        
        // Cleanup function
        const cleanup = () => {
            if (iframe && iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        };
        
        // Detect download via window focus events
        let windowBlurred = false;
        
        const onBlur = () => {
            windowBlurred = true;
        };
        
        const onFocus = () => {
            if (windowBlurred && !downloadDetected) {
                downloadDetected = true;
                loadingMessage.textContent = 'Download started! Check your downloads folder.';
                setTimeout(() => {
                    loadingOverlay.style.display = 'none';
                    cleanup();
                }, 2000);
            }
        };
        
        // Add event listeners
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        
        // Start the download via iframe
        iframe.src = url;
        
        // Also try direct download as fallback
        setTimeout(() => {
            const link = document.createElement('a');
            link.href = url;
            link.download = '';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }, 1000);
        
        // Start monitoring
        setTimeout(checkDownloadComplete, 1000);
        
        // Cleanup listeners when done
        setTimeout(() => {
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focus', onFocus);
        }, 35000);
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php 
// Close the main connection at the very end
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $conn->close();
    } catch (Error $e) {
        // Connection already closed or invalid, ignore
    }
}
?>
