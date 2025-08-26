<?php
require_once 'db_config.php';
require_once 'download_counter.php';

// Check if superadmin is logged in
if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied</div>';
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$adminId = $input['admin_id'] ?? '';

if (!$adminId) {
    echo '<div class="alert alert-danger">Invalid admin ID</div>';
    exit();
}

$conn = getDBConnection();
$downloadCounter = new DownloadCounter($conn);

// Get detailed usage statistics
try {
    $stmt = $conn->prepare("
        SELECT dt.disposition, dt.batch_id, dt.product_code, dt.caller_id,
               dt.download_count, dt.first_download_at, dt.last_download_at,
               COALESCE(au.download_limit, 5) as download_limit,
               fb.original_filename, fb.product_code as batch_product,
               c.name as caller_name,
               p.product_name
        FROM download_tracking dt
        JOIN admin_users au ON dt.admin_id = au.admin_id
        LEFT JOIN file_batches fb ON dt.batch_id = fb.id
        LEFT JOIN callers c ON dt.caller_id = c.caller_id
        LEFT JOIN products p ON dt.product_code = p.product_code
        WHERE dt.admin_id = ?
        ORDER BY dt.last_download_at DESC
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param("s", $adminId);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute statement: " . $stmt->error);
    }
    
    $usage_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    error_log("Error in ajax_get_admin_usage.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">Error loading usage data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit();
}

if (empty($usage_data)) {
    echo '<div class="alert alert-info">No download history found for this admin.</div>';
    exit();
}

$limit = $usage_data[0]['download_limit'] ?? 5;
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="alert alert-primary">
            <strong>Current Download Limit:</strong> <?= $limit ?> downloads per disposition per batch combination
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-sm">
        <thead class="table-dark">
            <tr>
                <th>Disposition</th>
                <th>Batch</th>
                <th>Product</th>
                <th>Caller</th>
                <th>Downloads</th>
                <th>Status</th>
                <th>First Download</th>
                <th>Last Download</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($usage_data as $usage): ?>
            <tr class="<?= $usage['download_count'] >= $limit ? 'table-danger' : ($usage['download_count'] >= $limit * 0.8 ? 'table-warning' : '') ?>">
                <td>
                    <span class="badge bg-secondary"><?= htmlspecialchars($usage['disposition']) ?></span>
                </td>
                <td>
                    <?php if ($usage['batch_id']): ?>
                        <strong><?= htmlspecialchars($usage['batch_id']) ?></strong>
                        <?php if ($usage['original_filename']): ?>
                            <br><small class="text-muted"><?= htmlspecialchars(substr($usage['original_filename'], 0, 30)) ?>...</small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">All Batches</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($usage['product_code']): ?>
                        <span class="badge bg-info"><?= htmlspecialchars($usage['product_name'] ?: $usage['product_code']) ?></span>
                    <?php else: ?>
                        <span class="text-muted">All Products</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($usage['caller_id']): ?>
                        <?= htmlspecialchars($usage['caller_name'] ?: $usage['caller_id']) ?>
                    <?php else: ?>
                        <span class="text-muted">All Callers</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?= $usage['download_count'] >= $limit ? 'danger' : 'success' ?> fs-6">
                        <?= $usage['download_count'] ?> / <?= $limit ?>
                    </span>
                </td>
                <td>
                    <?php if ($usage['download_count'] >= $limit): ?>
                        <span class="badge bg-danger">LIMIT REACHED</span>
                    <?php elseif ($usage['download_count'] >= $limit * 0.8): ?>
                        <span class="badge bg-warning">NEAR LIMIT</span>
                    <?php else: ?>
                        <span class="badge bg-success">ACTIVE</span>
                    <?php endif; ?>
                </td>
                <td>
                    <small><?= date('d-M-Y H:i', strtotime($usage['first_download_at'])) ?></small>
                </td>
                <td>
                    <small><?= date('d-M-Y H:i', strtotime($usage['last_download_at'])) ?></small>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="alert alert-info">
            <h6>Legend:</h6>
            <ul class="mb-0">
                <li><span class="badge bg-success">ACTIVE</span> - Can still download</li>
                <li><span class="badge bg-warning">NEAR LIMIT</span> - 80% of limit reached</li>
                <li><span class="badge bg-danger">LIMIT REACHED</span> - Cannot download this combination anymore</li>
            </ul>
        </div>
    </div>
</div>

<?php $conn->close(); ?>