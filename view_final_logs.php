<?php
// DB constants remain the same
define('DB_HOST', 'localhost'); define('DB_USER', 'root'); define('DB_PASS', '123456'); define('DB_NAME', 'caller_sheet');
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }

// The query is unchanged, but we will display the new column
$result = $conn->query("SELECT * FROM final_call_logs ORDER BY processed_at DESC");
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
                    <th>Processed By (FinqyID)</th> <!-- New Column -->
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
                        <td><?= htmlspecialchars($row['processed_at']) ?></td>
                        <td><strong><?= htmlspecialchars($row['finqy_id']) ?></strong></td> <!-- New Column Data -->
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                        <td><?= htmlspecialchars($row['connectivity']) ?></td>
                        <td><?= htmlspecialchars($row['disposition']) ?></td>
                        <td><?= htmlspecialchars($row['source_filename']) ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="7" class="text-center">No records found.</td></tr> <!-- Colspan updated to 7 -->
                <?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
</body>
</html>
<?php $conn->close(); ?>