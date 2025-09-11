<?php
require_once __DIR__ . '/session_manager.php';
SessionManager::start();
header('Content-Type: application/json');

require_once 'db_config.php'; // Use centralized db config

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

// Generate unique filename with finqy_id and timestamp
$timestamp = date('YmdHis');
$finqyId = $_SESSION['finqy_id'];
$fileExt = pathinfo($_FILES['markedSheet']['name'], PATHINFO_EXTENSION);
$targetFile = $uploadDir . $finqyId . '_' . $timestamp . '.' . $fileExt;

if (move_uploaded_file($_FILES['markedSheet']['tmp_name'], $targetFile)) {
    // Log the upload
    error_log("Image uploaded successfully: " . $targetFile);
    
    // Step 1: Check for blur before processing
    $blurCommand = "python blur_detector.py " . escapeshellarg($targetFile) . " 2>&1";
    $blurOutput = shell_exec($blurCommand);
    
    // Parse blur detection result
    $blurResult = json_decode(trim($blurOutput), true);
    
    if (!$blurResult) {
        // Clean up and return error
        @unlink($targetFile);
        echo json_encode(['success' => false, 'message' => 'Error checking image quality. Please try again.']);
        exit();
    }
    
    if (isset($blurResult['error'])) {
        // Clean up and return error
        @unlink($targetFile);
        echo json_encode(['success' => false, 'message' => 'Image quality check failed: ' . htmlspecialchars($blurResult['error'])]);
        exit();
    }
    
    // Check if image is too blurry
    if ($blurResult['is_blurry'] === true) {
        // Clean up the blurry image
        @unlink($targetFile);
        
        $qualityMsg = "Image quality: " . strtoupper($blurResult['quality']) . " (Score: " . $blurResult['laplacian_variance'] . ")";
        echo json_encode([
            'success' => false, 
            'message' => 'Image is too blurry to process accurately. Please retake the photo with better focus and lighting.',
            'blur_details' => [
                'quality' => $blurResult['quality'],
                'score' => $blurResult['laplacian_variance'],
                'recommendation' => $blurResult['recommendation']
            ],
            'quality_info' => $qualityMsg
        ]);
        exit();
    }
    
    // Log blur detection success
    error_log("Image quality check passed for $finqyId - Quality: " . $blurResult['quality'] . " (Score: " . $blurResult['laplacian_variance'] . ")");
    
    // Step 2: Process the image with Gemini AI (only if not blurry)
    $command = "python gemini_omr_parser.py " . escapeshellarg($targetFile) . " 2>&1";
    $output = shell_exec($command);
    
    // Log the Python output for debugging
    error_log("Python output for $finqyId: " . $output);
    
    // Clean up the image after processing
    @unlink($targetFile);

    // Check if output is a JSON error
    $json_check = json_decode($output, true);
    if ($json_check && isset($json_check['error'])) {
        echo json_encode(['success' => false, 'message' => 'AI Processing Error: ' . htmlspecialchars($json_check['error'])]);
        exit();
    }

    // Parse CSV output
    $lines = explode("\n", trim($output));
    if (count($lines) < 2) { // Must have header + at least one data row
        echo json_encode(['success' => false, 'message' => 'AI could not detect any marked data on the sheet. Please ensure the image is clear and properly marked.']);
        exit();
    }

    $headers = str_getcsv(array_shift($lines));
    $results = [];

    $conn = getDBConnection();

    // --- CHANGE: Prepare statement to fetch customer details using the record ID ---
    $stmt = $conn->prepare("SELECT name, mobile_no FROM final_call_logs WHERE id = ?");
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Database query preparation failed.']);
        exit();
    }


    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        
        $row_data = str_getcsv($line);
        if (count($row_data) === count($headers)) {
            $parsed_data = array_combine($headers, $row_data);
            
            // --- CHANGE: Use 'record_id' from the CSV as the primary identifier ---
            $record_id = trim($parsed_data['record_id'] ?? '');

            if (!empty($record_id)) {
                // Fetch customer name and mobile number using the record_id
                $stmt->bind_param("s", $record_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $customer = $res->fetch_assoc();
                
                // Populate the data for the results table
                $parsed_data['customer_name'] = $customer['name'] ?? 'Not Found';
                $parsed_data['mobile_no'] = $customer['mobile_no'] ?? 'N/A';
                // The record_id is already in $parsed_data from the CSV
                
                // Validate the data - at least one mark should be present to be considered a valid entry
                if (!empty($parsed_data['disposition_code']) || !empty($parsed_data['slot']) || !empty($parsed_data['follow_day']) || !empty($parsed_data['follow_slot'])) {
                    $results[] = $parsed_data;
                }
            }
        }
    }
    
    $stmt->close();
    $conn->close();
    
    if (empty($results)) {
        echo json_encode(['success' => false, 'message' => 'AI could not read any valid entries from the sheet. Please ensure marks are clear and visible.']);
        exit();
    }

    // Log successful processing
    error_log("Successfully processed " . count($results) . " entries for " . $finqyId);
    
    echo json_encode(['success' => true, 'data' => $results]);

} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please try again.']);
}
