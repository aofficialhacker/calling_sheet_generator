<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Fetch batches created by this admin
$batches_sql = "SELECT b.id, b.product_code, p.product_name 
                FROM file_batches b
                JOIN products p ON b.product_code = p.product_code
                WHERE b.admin_id = ?
                ORDER BY b.upload_time DESC";
$stmt = $conn->prepare($batches_sql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$batches_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all active products for the modal dropdown
$products_sql = "SELECT id, product_code, product_name FROM products WHERE is_active = 1";
$all_products = $conn->query($products_sql)->fetch_all(MYSQLI_ASSOC);

$stmt->close();
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
                        <p>Select a batch and click "Allocate" to copy its data to a different product. This creates a new batch without affecting the original.</p>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Batch No</th>
                                        <th>Current Product</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($batches_data)): ?>
                                        <?php foreach($batches_data as $row): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['id']) ?></span></td>
                                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td>
                                                <button class="btn btn-info btn-sm text-white allocate-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#allocationModal"
                                                        data-batch-id="<?= htmlspecialchars($row['id']) ?>"
                                                        data-product-code="<?= htmlspecialchars($row['product_code']) ?>">
                                                    <i class="bi bi-files me-1"></i> Allocate
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted">No batches available to allocate. Upload a batch first.</td></tr>
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
                                <?php foreach($all_products as $product): ?>
                                    <option value="<?= $product['id'] ?>" data-product-code="<?= $product['product_code'] ?>">
                                        <?= htmlspecialchars($product['product_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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

    allocationModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const batchId = button.getAttribute('data-batch-id');
        const currentProductCode = button.getAttribute('data-product-code');

        // Set the batch ID in the modal title and hidden input
        modalBatchIdSpan.textContent = batchId;
        originalBatchIdInput.value = batchId;

        // Reset and filter the dropdown
        targetProductSelect.value = '';
        Array.from(targetProductSelect.options).forEach(option => {
            if (option.value) { // Don't hide the placeholder
                if (option.getAttribute('data-product-code') === currentProductCode) {
                    option.style.display = 'none'; // Hide the current product
                } else {
                    option.style.display = 'block'; // Show other products
                }
            }
        });
    });
});
</script>
</body>
</html>
