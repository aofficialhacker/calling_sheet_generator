<?php
session_start();
header('Content-Type: application/json');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// Check authentication
if (!isset($_SESSION['finqy_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication error. Please log in again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['markedSheet'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$uploadDir = 'marked_uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$targetFile = $uploadDir . uniqid() . '-' . basename($_FILES['markedSheet']['name']);

if (move_uploaded_file($_FILES['markedSheet']['tmp_name'], $targetFile)) {
    $command = "python gemini_omr_parser.py " . escapeshellarg($targetFile);
    $output = shell_exec($command);
    @unlink($targetFile); // Clean up the image immediately

    $json_check = json_decode($output, true);
    if ($json_check && isset($json_check['error'])) {
        echo json_encode(['success' => false, 'message' => 'Python script failed: ' . htmlspecialchars($json_check['error'])]);
        exit();
    }

    $lines = explode("\n", trim($output));
    if (count($lines) < 2) { // Must have header + at least one data row
         echo json_encode(['success' => false, 'message' => 'AI processing finished, but no valid data rows were detected.']);
         exit();
    }

    $headers = str_getcsv(array_shift($lines));
    $results = [];

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT name FROM final_call_logs WHERE mobile_no = ?");

    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $row_data = str_getcsv($line);
        if (count($row_data) === count($headers)) {
            $parsed_data = array_combine($headers, $row_data);
            $mobile_no = preg_replace('/\D/', '', $parsed_data['mobile_no'] ?? '');

            if (!empty($mobile_no)) {
                $stmt->bind_param("s", $mobile_no);
                $stmt->execute();
                $res = $stmt->get_result();
                $customer = $res->fetch_assoc();
                
                $parsed_data['customer_name'] = $customer['name'] ?? 'Unknown Number';
                $parsed_data['mobile_no'] = $mobile_no; // Ensure it's clean
                $results[] = $parsed_data;
            }
        }
    }
    $stmt->close();
    $conn->close();
    
    if (empty($results)) {
        echo json_encode(['success' => false, 'message' => 'AI could not read any valid mobile numbers from the sheet.']);
        exit();
    }

    echo json_encode(['success' => true, 'data' => $results]);

} else {
    echo json_encode(['success' => false, 'message' => 'File upload error.']);
}