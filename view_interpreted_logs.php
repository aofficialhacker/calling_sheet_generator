<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }
$result = $conn->query("SELECT * FROM call_logs ORDER BY log_timestamp DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Interpreted Data Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Interpreted (AI) Data Logs</h1><a href="index.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
    <div class="card"><div class="card-body"><div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark" style="position: sticky; top: 0;"><tr><th>ID</th><th>Timestamp</th><th>Source File</th><th>Row on Sheet</th><th>Connectivity</th><th>Disposition</th></tr></thead>
            <tbody>
                <?php if ($result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['log_timestamp']) ?></td>
                        <td><?= htmlspecialchars($row['original_filename']) ?></td>
                        <td><?= htmlspecialchars($row['row_index_on_sheet']) ?></td>
                        <td><?= htmlspecialchars($row['connectivity']) ?></td>
                        <td><?= htmlspecialchars($row['disposition']) ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
</body>
</html>
<?php $conn->close(); ?>