<?php
define('DB_HOST', 'localhost'); define('DB_USER', 'root'); define('DB_PASS', '123456'); define('DB_NAME', 'caller_sheet');
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }

// UPDATED: Query now joins with file_batches to get the batch ID for display
$sql = "SELECT f.*, b.id as batch_display_id 
        FROM final_call_logs f
        LEFT JOIN file_batches b ON f.batch_id = b.id
        ORDER BY f.processed_at DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Final Call Logs</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"></head>
<body>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Final Interpreted Logs (Admin View)</h1>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Dashboard</a>
    </div>
    <div class="card shadow-sm"><div class="card-body"><div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark" style="position: sticky; top: 0;">
                <tr>
                    <th>Processed At</th>
                    <th>Batch Name</th> <!-- New Column -->
                    <th>Processed By (FinqyID)</th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Connectivity</th>
                    <th>Disposition</th>
                    <th>Source File</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('d-M-Y H:i', strtotime($row['processed_at']))) ?></td>
                        <!-- New Column Data -->
                        <td>
                            <?php if (!empty($row['batch_display_id'])): ?>
                                <span class="badge bg-primary">DB<?= htmlspecialchars($row['batch_display_id']) ?></span>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($row['finqy_id']) ?></strong></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                        <td><?= htmlspecialchars($row['connectivity']) ?></td>
                        <td><?= htmlspecialchars($row['disposition']) ?></td>
                        <td><?= htmlspecialchars($row['source_filename']) ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <!-- Colspan updated to 8 -->
                    <tr><td colspan="8" class="text-center">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
</body>
</html>
<?php $conn->close(); ?>