<?php
require_once __DIR__ . '/config.php';

// Database configuration using environment variables
$dbConfig = Config::database();
define('DB_HOST', $dbConfig['host']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['password']);
define('DB_NAME', $dbConfig['database']);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }

// Query now shows the unique row ID and batch ID
$sql = "SELECT f.id as row_id, f.*, b.id as batch_display_id 
        FROM lv_final_call_logs f
        LEFT JOIN lv_file_batches b ON f.batch_id = b.id
        WHERE f.processed_at IS NOT NULL
        ORDER BY f.processed_at DESC
        LIMIT 500";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Final Call Logs</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"></head>
<body>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Final Call Logs</h1>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Dashboard</a>
    </div>
    <div class="card shadow-sm"><div class="card-body"><div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark" style="position: sticky; top: 0;">
                <tr>
                    <th>Row ID</th>
                    <th>Processed At</th>
                    <th>Batch</th>
                    <th>Caller (Refercode)</th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Connectivity</th>
                    <th>Disposition</th>
                    <th>Slot</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($row['row_id']) ?></code></td>
                        <td><?= htmlspecialchars(date('d-M-Y H:i', strtotime($row['processed_at']))) ?></td>
                        <td>
                            <?php if (!empty($row['batch_display_id'])): ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($row['batch_display_id']) ?></span>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($row['finqy_id']) ?></strong></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                        <td>
                            <?php if ($row['connectivity'] == 'Yes'): ?>
                                <span class="badge bg-success">Yes</span>
                            <?php elseif ($row['connectivity'] == 'No'): ?>
                                <span class="badge bg-danger">No</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['disposition'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['slot'] ?? '-') ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="9" class="text-center">No processed records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
</body>
</html>
<?php $conn->close(); ?>