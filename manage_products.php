<?php
require_once 'db_config.php';
requireSuperadmin();

$conn = getDBConnection();
$message = '';
$error = '';

// Handle product operations
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create':
            $productCode = strtoupper(trim($_POST['product_code'] ?? ''));
            $productName = trim($_POST['product_name'] ?? '');
            
            if ($productCode && $productName) {
                $stmt = $conn->prepare("INSERT INTO products (product_code, product_name, created_by) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $productCode, $productName, $_SESSION['superadmin_id']);
                
                if ($stmt->execute()) {
                    $message = "Product created successfully.";
                } else {
                    $error = "Failed to create product. Code may already exist.";
                }
                $stmt->close();
            } else {
                $error = "Product code and name are required.";
            }
            break;
            
        case 'toggle_status':
            $productId = $_POST['product_id'] ?? 0;
            $currentStatus = $_POST['current_status'] ?? 0;
            $newStatus = $currentStatus ? 0 : 1;
            
            $stmt = $conn->prepare("UPDATE products SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $newStatus, $productId);
            
            if ($stmt->execute()) {
                $message = "Product status updated.";
            }
            $stmt->close();
            break;
            
        case 'update':
            $productId = $_POST['product_id'] ?? 0;
            $productName = trim($_POST['product_name'] ?? '');
            
            if ($productId && $productName) {
                $stmt = $conn->prepare("UPDATE products SET product_name = ? WHERE id = ?");
                $stmt->bind_param("si", $productName, $productId);
                
                if ($stmt->execute()) {
                    $message = "Product updated successfully.";
                }
                $stmt->close();
            }
            break;
    }
}

// Fetch all products
$products = $conn->query("
    SELECT p.*, 
           COUNT(DISTINCT fb.id) as batch_count
    FROM products p
    LEFT JOIN file_batches fb ON p.product_code = fb.product_code
    GROUP BY p.id
    ORDER BY p.created_at DESC
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - Superadmin Panel</title>
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
                            <a class="nav-link active" href="manage_products.php">
                                <i class="bi bi-box-seam me-2"></i>Products
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_dispositions.php">
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
                    <h1 class="h2"><i class="bi bi-box-seam me-2"></i>Manage Products</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createProductModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Product
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

                <!-- Products Table -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th>Batches Used</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($products && $products->num_rows > 0): ?>
                                        <?php while($product = $products->fetch_assoc()): ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($product['product_code']) ?></code></td>
                                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                                <td><?= $product['batch_count'] ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                        <input type="hidden" name="current_status" value="<?= $product['is_active'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $product['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                                                            <?= $product['is_active'] ? 'Active' : 'Inactive' ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td><?= date('d-M-Y', strtotime($product['created_at'])) ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info text-white" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editProductModal"
                                                            data-id="<?= $product['id'] ?>"
                                                            data-name="<?= htmlspecialchars($product['product_name']) ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No products found</td>
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

    <!-- Create Product Modal -->
    <div class="modal fade" id="createProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="product_code" class="form-label">Product Code</label>
                            <input type="text" class="form-control" id="product_code" name="product_code" 
                                   required maxlength="50" placeholder="e.g., MOTOR, HEALTH">
                            <small class="text-muted">Use uppercase letters without spaces</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="product_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="product_name" name="product_name" 
                                   required maxlength="255" placeholder="e.g., Motor Insurance">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="product_id" id="edit_product_id">
                        
                        <div class="mb-3">
                            <label for="edit_product_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="edit_product_name" name="product_name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle edit modal data
        document.getElementById('editProductModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const productId = button.getAttribute('data-id');
            const productName = button.getAttribute('data-name');
            
            document.getElementById('edit_product_id').value = productId;
            document.getElementById('edit_product_name').value = productName;
        });
    </script>
</body>
</html>