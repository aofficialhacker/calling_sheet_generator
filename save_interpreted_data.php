<?php
// Maps to convert codes to their full text meaning for the database
const DISPOSITION_MAP = [
    '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up',
    '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing',
    '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy',
];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

// DB Config
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results'])) {
    $results = json_decode($_POST['json_results'], true);
    $filename = $_POST['original_filename'] ?? 'N/A';
    if (json_last_error() === JSON_ERROR_NONE && !empty($results)) {
        $stmt = $conn->prepare("INSERT INTO call_logs (original_filename, row_index_on_sheet, connectivity, disposition) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("siss", $db_filename, $db_row_index, $db_connectivity, $db_disposition);
        $saved_count = 0;
        foreach ($results as $row) {
            $db_filename = $filename;
            $db_row_index = $row['row_index'];
            $db_connectivity = CONNECTIVITY_MAP[$row['connectivity_code']] ?? null;
            $db_disposition = DISPOSITION_MAP[$row['disposition_code']] ?? null;
            if ($db_connectivity || $db_disposition) { if ($stmt->execute()) { $saved_count++; } }
        }
        $message = "Successfully saved " . $saved_count . " records.";
        $stmt->close();
    } else { $error = "Invalid data received."; }
} else { $error = "No data submitted."; }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Save Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="card text-center">
        <div class="card-body">
            <?php if ($message): ?>
                <h3 class="text-success"><?= htmlspecialchars($message) ?></h3>
                <a href="process_with_gemini_python.php" class="btn btn-primary mt-3">Process Another Sheet</a>
            <?php endif; ?>
            <?php if ($error): ?>
                <h3 class="text-danger"><?= htmlspecialchars($error) ?></h3>
                <a href="process_with_gemini_python.php" class="btn btn-secondary mt-3">Try Again</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>