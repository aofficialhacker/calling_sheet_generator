<?php
require_once 'db_config.php';

// Get a sample batch_id
$conn = getDBConnection();
$result = $conn->query("SELECT id FROM file_batches ORDER BY upload_time DESC LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $batch_id = $row['id'];
    $_GET['batch_id'] = $batch_id;
    echo "Generating PDF for batch_id: $batch_id\n";
    include 'generate_pdf.php';
    echo "PDF generated as test.pdf\n";
} else {
    echo "No batches found in the database.\n";
}
$conn->close();
?>
