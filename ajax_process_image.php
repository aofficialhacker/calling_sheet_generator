<?php
require_once __DIR__ . '/session_manager.php';
SessionManager::start();
header('Content-Type: application/json');

require_once 'db_config.php'; // Use centralized db config

/**
 * Investigate where a missing record might exist in the database
 */
function investigateMissingRecord($conn, $record_id) {
    $investigation = [
        'found_in_lv_call_history' => false,
        'found_in_other_tables' => false,
        'possible_batch_mismatch' => false,
        'suggestions' => []
    ];
    
    // Check if record exists in lv_call_history table
    $history_stmt = $conn->prepare("SELECT COUNT(*) as count FROM lv_call_history WHERE original_record_id = ?");
    if ($history_stmt) {
        $history_stmt->bind_param("s", $record_id);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result()->fetch_assoc();
        $investigation['found_in_lv_call_history'] = $history_result['count'] > 0;
        $history_stmt->close();
    }
    
    // Check for similar record IDs (possible batch mismatch)
    $batch_prefix = substr($record_id, 0, -3); // Remove last 3 digits
    $similar_stmt = $conn->prepare("SELECT COUNT(*) as count FROM lv_final_call_logs WHERE id LIKE ?");
    if ($similar_stmt) {
        $pattern = $batch_prefix . '%';
        $similar_stmt->bind_param("s", $pattern);
        $similar_stmt->execute();
        $similar_result = $similar_stmt->get_result()->fetch_assoc();
        $investigation['possible_batch_mismatch'] = $similar_result['count'] > 0;
        $similar_stmt->close();
    }
    
    // Generate suggestions based on findings
    if ($investigation['found_in_lv_call_history']) {
        $investigation['suggestions'][] = "Record exists in lv_call_history - may have been processed previously";
    }
    if ($investigation['possible_batch_mismatch']) {
        $investigation['suggestions'][] = "Similar records found - check if correct batch was uploaded";
    }
    if (!$investigation['found_in_lv_call_history'] && !$investigation['possible_batch_mismatch']) {
        $investigation['suggestions'][] = "Record may not have been imported during batch upload";
    }
    
    return $investigation;
}

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
    
    // Step 2: Process the image with Basic Gemini AI (proven more accurate)
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
    $missing_records = [];
    $total_extracted = 0;

    $conn = getDBConnection();

    // --- CHANGE: Prepare statement to fetch customer details using the record ID ---
    $stmt = $conn->prepare("SELECT name, mobile_no FROM lv_final_call_logs WHERE id = ?");
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
                $total_extracted++; // Count all extracted records
                
                // Fetch customer name and mobile number using the record_id
                $stmt->bind_param("s", $record_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $customer = $res->fetch_assoc();
                
                if ($customer) {
                    // Populate the data for the results table
                    $parsed_data['customer_name'] = $customer['name'];
                    $parsed_data['mobile_no'] = $customer['mobile_no'];
                    // The record_id is already in $parsed_data from the CSV
                    
                    // Include all records with valid record_id - let the caller panel handle empty fields
                    $results[] = $parsed_data;
                } else {
                    // Investigate where this record might exist
                    $investigation_result = investigateMissingRecord($conn, $record_id);
                    
                    // Track missing records for detailed reporting
                    $missing_records[] = [
                        'record_id' => $record_id,
                        'disposition_code' => $parsed_data['disposition_code'] ?? 'N/A',
                        'slot' => $parsed_data['slot'] ?? 'N/A',
                        'investigation' => $investigation_result
                    ];
                    error_log("MISSING RECORD: $record_id not found in lv_final_call_logs table - OCR extracted but DB lookup failed. Investigation: " . json_encode($investigation_result));
                }
            }
        }
    }
    
    $stmt->close();
    $conn->close();
    
    // Enhanced error handling for processing results
    if (empty($results)) {
        $failure_message = 'No valid entries could be processed from the sheet.';
        
        if ($total_extracted > 0 && $missing_count > 0) {
            // All extracted records were missing from database
            $failure_message = "Critical Error: All $total_extracted extracted entries are missing from the database. ";
            $failure_message .= "Missing records: " . implode(', ', array_column($missing_records, 'record_id')) . ". ";
            $failure_message .= "This indicates a serious data integrity issue - please contact system administrator.";
            
            echo json_encode([
                'success' => false, 
                'message' => $failure_message,
                'extraction_summary' => [
                    'total_extracted' => $total_extracted,
                    'successfully_processed' => 0,
                    'missing_from_database' => $missing_count,
                    'missing_records' => $missing_records
                ],
                'critical_error' => true
            ]);
        } else {
            // OCR failed to extract data
            echo json_encode(['success' => false, 'message' => 'AI could not detect any marked data on the sheet. Please ensure the image is clear and properly marked.']);
        }
        exit();
    }

    // Enhanced logging and user feedback
    $processed_count = count($results);
    $missing_count = count($missing_records);
    
    if ($missing_count > 0) {
        error_log("OCR PROCESSING DISCREPANCY for $finqyId: Extracted $total_extracted entries, processed $processed_count, missing $missing_count");
        error_log("Missing records: " . implode(', ', array_column($missing_records, 'record_id')));
        
        // Determine severity of the issue
        $missing_percentage = round(($missing_count / $total_extracted) * 100);
        $severity = $missing_percentage > 50 ? 'critical' : ($missing_percentage > 20 ? 'high' : 'medium');
        
        // Return detailed feedback about missing records
        echo json_encode([
            'success' => true, 
            'data' => $results,
            'extraction_summary' => [
                'total_extracted' => $total_extracted,
                'successfully_processed' => $processed_count,
                'missing_from_database' => $missing_count,
                'missing_records' => $missing_records,
                'missing_percentage' => $missing_percentage,
                'severity' => $severity
            ],
            'warning' => "Processing Incomplete: $missing_count out of $total_extracted extracted entries ($missing_percentage%) were not found in the database.",
            'warning_details' => "Missing records: " . implode(', ', array_column($missing_records, 'record_id')) . ". These may need to be re-imported or indicate data integrity issues.",
            'has_missing_records' => true
        ]);
    } else {
        error_log("Successfully processed $processed_count out of $total_extracted entries for $finqyId (no missing records)");
        echo json_encode([
            'success' => true, 
            'data' => $results,
            'extraction_summary' => [
                'total_extracted' => $total_extracted,
                'successfully_processed' => $processed_count,
                'missing_from_database' => 0
            ],
            'has_missing_records' => false
        ]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please try again.']);
}
