<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Fetch batches created by this admin with allocation chain info
$batches_sql = "SELECT b.id, b.product_code, b.original_batch_id, p.product_name,
                (SELECT COUNT(*) FROM final_call_logs WHERE batch_id = b.id) as record_count
                FROM file_batches b
                JOIN products p ON b.product_code = p.product_code
                WHERE b.admin_id = ?
                ORDER BY b.upload_time DESC";
$stmt = $conn->prepare($batches_sql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$batches_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build allocation chains to track which products already have data from each source
$allocation_chains = [];
foreach ($batches_data as $batch) {
    if ($batch['original_batch_id']) {
        // Find the root batch
        $root = $batch['original_batch_id'];
        while (true) {
            $checkStmt = $conn->prepare("SELECT original_batch_id FROM file_batches WHERE id = ?");
            $checkStmt->bind_param("s", $root);
            $checkStmt->execute();
            $parentResult = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            
            if ($parentResult && $parentResult['original_batch_id']) {
                $root = $parentResult['original_batch_id'];
            } else {
                break;
            }
        }
        
        if (!isset($allocation_chains[$root])) {
            $allocation_chains[$root] = [];
        }
        $allocation_chains[$root][] = $batch['product_code'];
    }
}

// Fetch all active products for the modal dropdown
$products_sql = "SELECT id, product_code, product_name FROM products WHERE is_active = 1";
$all_products = $conn->query($products_sql)->fetch_all(MYSQLI_ASSOC);

// Function to get available products for allocation
function getAvailableProducts($batchId, $currentProductCode, $allProducts, $allocationChains, $conn, $adminId) {
    $availableProducts = [];
    
    // Get the root batch for this batch
    $root = $batchId;
    $checkStmt = $conn->prepare("SELECT original_batch_id FROM file_batches WHERE id = ?");
    $checkStmt->bind_param("s", $batchId);
    $checkStmt->execute();
    $batchInfo = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($batchInfo && $batchInfo['original_batch_id']) {
        $root = $batchInfo['original_batch_id'];
        while (true) {
            $checkStmt = $conn->prepare("SELECT original_batch_id FROM file_batches WHERE id = ?");
            $checkStmt->bind_param("s", $root);
            $checkStmt->execute();
            $parentResult = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            
            if ($parentResult && $parentResult['original_batch_id']) {
                $root = $parentResult['original_batch_id'];
            } else {
                break;
            }
        }
    }
    
    // Get all product codes that already have this data
    $excludedProducts = [$currentProductCode];
    if (isset($allocationChains[$root])) {
        $excludedProducts = array_merge($excludedProducts, $allocationChains[$root]);
    }
    if (isset($allocationChains[$batchId])) {
        $excludedProducts = array_merge($excludedProducts, $allocationChains[$batchId]);
    }
    $excludedProducts = array_unique($excludedProducts);
    
    // Check each product for duplicates
    foreach ($allProducts as $product) {
        if (in_array($product['product_code'], $excludedProducts)) {
            continue;
        }
        
        // Check if there are any unique mobile numbers for this product
        $checkSql = "
            SELECT COUNT(DISTINCT fcl1.mobile_no) as unique_count
            FROM final_call_logs fcl1
            WHERE fcl1.batch_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM final_call_logs fcl2
                INNER JOIN file_batches fb ON fcl2.batch_id = fb.id
                WHERE fcl2.mobile_no = fcl1.mobile_no
                AND fb.product_code = ?
                AND fb.admin_id = ?
            )
        ";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("sss", $batchId, $product['product_code'], $adminId);
        $checkStmt->execute();
        $result = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($result['unique_count'] > 0) {
            $product['unique_count'] = $result['unique_count'];
            $availableProducts[] = $product;
        }
    }
    
    return $availableProducts;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Allocation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .loading-spinner { display: none; }
        .loading-spinner.active { display: inline-block; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-distribute-vertical me-2"></i>Batch Allocation</h1>
                </div>

                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['flash_message']['text']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['flash_message']); ?>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">Allocate Batch Data to a New Product</h5>
                    </div>
                    <div class="card-body">
                        <p>Select a batch and click "Allocate" to copy its unique data to a different product. Duplicate mobile numbers will be automatically skipped.</p>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Batch No</th>
                                        <th>Current Product</th>
                                        <th>Records</th>
                                        <th>Source</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($batches_data)): ?>
                                        <?php 
                                        $conn = getDBConnection();
                                        foreach($batches_data as $row): 
                                            $availableProducts = getAvailableProducts(
                                                $row['id'], 
                                                $row['product_code'], 
                                                $all_products, 
                                                $allocation_chains, 
                                                $conn, 
                                                $adminId
                                            );
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['id']) ?></span></td>
                                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td><?= htmlspecialchars($row['record_count']) ?></td>
                                            <td>
                                                <?php if ($row['original_batch_id']): ?>
                                                    <span class="badge bg-info">Allocated from <?= htmlspecialchars($row['original_batch_id']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">Original</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($availableProducts)): ?>
                                                    <button class="btn btn-info btn-sm text-white allocate-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#allocationModal"
                                                            data-batch-id="<?= htmlspecialchars($row['id']) ?>"
                                                            data-product-code="<?= htmlspecialchars($row['product_code']) ?>"
                                                            data-available-products='<?= htmlspecialchars(json_encode($availableProducts)) ?>'>
                                                        <i class="bi bi-files me-1"></i> Allocate
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-secondary btn-sm" disabled title="No unique records to allocate">
                                                        <i class="bi bi-x-circle me-1"></i> No Options
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; 
                                        $conn->close();
                                        ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">No batches available to allocate. Upload a batch first.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Allocation Modal -->
    <div class="modal fade" id="allocationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Allocate Batch <span id="modalBatchId" class="badge bg-secondary"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="process_allocation.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="original_batch_id" id="original_batch_id_input">
                        <div class="mb-3">
                            <label for="target_product_id" class="form-label"><strong>Select New Product to Copy Data To:</strong></label>
                            <select class="form-select" id="target_product_id" name="target_product_id" required>
                                <option value="">-- Select a Product --</option>
                            </select>
                            <small class="text-muted mt-2 d-block">Only products without duplicate data are shown. Number in parentheses shows unique records that will be allocated.</small>
                        </div>
                        <div id="allocation-info" class="alert alert-info d-none">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="allocation-message"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill me-2"></i>Confirm Allocation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const allocationModal = document.getElementById('allocationModal');
    const modalBatchIdSpan = document.getElementById('modalBatchId');
    const originalBatchIdInput = document.getElementById('original_batch_id_input');
    const targetProductSelect = document.getElementById('target_product_id');
    const allocationInfo = document.getElementById('allocation-info');
    const allocationMessage = document.getElementById('allocation-message');

    allocationModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const batchId = button.getAttribute('data-batch-id');
        const availableProducts = JSON.parse(button.getAttribute('data-available-products'));

        // Set the batch ID in the modal title and hidden input
        modalBatchIdSpan.textContent = batchId;
        originalBatchIdInput.value = batchId;

        // Clear and populate the dropdown with available products
        targetProductSelect.innerHTML = '<option value="">-- Select a Product --</option>';
        allocationInfo.classList.add('d-none');
        
        if (availableProducts && availableProducts.length > 0) {
            availableProducts.forEach(product => {
                const option = document.createElement('option');
                option.value = product.id;
                option.textContent = `${product.product_name} (${product.unique_count} unique records)`;
                targetProductSelect.appendChild(option);
            });
        } else {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No products available - all data would be duplicates';
            option.disabled = true;
            targetProductSelect.appendChild(option);
        }
    });

    targetProductSelect.addEventListener('change', function() {
        if (this.value) {
            const selectedOption = this.options[this.selectedIndex];
            const match = selectedOption.textContent.match(/\((\d+) unique records\)/);
            if (match) {
                allocationInfo.classList.remove('d-none');
                allocationMessage.textContent = `${match[1]} unique records will be allocated to the selected product. Duplicate records will be automatically skipped.`;
            }
        } else {
            allocationInfo.classList.add('d-none');
        }
    });
});
</script>
</body>
</html>