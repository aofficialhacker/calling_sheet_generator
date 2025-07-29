<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }
$result = $conn->query("SELECT * FROM initial_data_logs ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Initial Data Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.table-responsive{max-height:80vh;}.extra-data{font-size:0.8em;white-space:pre-wrap;background-color:#f8f9fa;}</style>
</head>
<body>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Source Data Logs</h1><a href="index.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
    <div class="card"><div class="card-body"><div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark" style="position: sticky; top: 0;"><tr><th>ID</th><th>Timestamp</th><th>Source File</th><th>Name</th><th>Mobile</th><th>Policy No.</th><th>Extra Data</th></tr></thead>
            <tbody>
                <?php if ($result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                        <td><?= htmlspecialchars($row['source_filename']) ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                        <td><?= htmlspecialchars($row['policy_number']) ?></td>
                        <td>
                            <?php if (!empty($row['extra_data'])) {
                                $extra = json_decode($row['extra_data'], true);
                                echo '<div class="extra-data">';
                                foreach ($extra as $key => $value) { echo '<strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . htmlspecialchars($value) . "\n"; }
                                echo '</div>';
                            } ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="7" class="text-center">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
</body>
</html>
<?php $conn->close(); ?>