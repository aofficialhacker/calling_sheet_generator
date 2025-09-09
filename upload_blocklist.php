<?php
require_once 'db_config.php';
require_once 'blocklist_utils.php';
requireAdmin();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Process file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['blocklistFile'])) {
    set_time_limit(300);
    $conn = getDBConnection();
    
    $adminId = $_SESSION['admin_id'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $originalFileName = basename($_FILES['blocklistFile']['name']);
        $tempFile = $_FILES['blocklistFile']['tmp_name'];
        
        // Validate file type
        $allowedExtensions = ['xlsx', 'xls', 'csv'];
        $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedExtensions)) {
            throw new Exception("Invalid file type. Please upload Excel (.xlsx, .xls) or CSV files only.");
        }
        
        // Load the spreadsheet
        $spreadsheet = IOFactory::load($tempFile);
        $worksheet = $spreadsheet->getActiveSheet();
        $dataRows = $worksheet->toArray(null, true, true, false);
        $headerRow = array_shift($dataRows);
        
        if (empty($dataRows)) {
            throw new Exception("The uploaded file contains no data rows.");
        }
        
        // Check row limit
        if (count($dataRows) > 50000) {
            throw new Exception("File contains " . count($dataRows) . " rows. Maximum 50,000 rows allowed for blocklist uploads.");
        }
        
        $columnMap = mapBlocklistColumns($headerRow);
        $batchId = generateBlocklistBatchId($adminId);
        
        $conn->begin_transaction();
        
        $addedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;
        
        foreach ($dataRows as $dataRow) {
            if (empty(implode('', $dataRow))) continue;
            
            $mobile_no_raw = extractMobileNumber($dataRow, $columnMap);
            if (empty($mobile_no_raw)) {
                $invalidCount++;
                continue;
            }
            
            // Clean mobile number (remove non-digits)
            $mobile_no = preg_replace('/\D/', '', $mobile_no_raw);
            
            // Validate mobile number length
            if (strlen($mobile_no) < 10) {
                $invalidCount++;
                continue;
            }
            
            // Extract notes if available
            $rowNotes = ($columnMap['notes'] !== -1 && isset($dataRow[$columnMap['notes']])) 
                        ? trim($dataRow[$columnMap['notes']]) 
                        : $notes;
            
            // Check if already exists
            if (isMobileNumberBlocked($adminId, $mobile_no)) {
                $duplicateCount++;
                continue;
            }
            
            // Add to blocklist
            if (addToBlocklist($adminId, $mobile_no, $adminId, $batchId, $rowNotes)) {
                $addedCount++;
            }
        }
        
        $conn->commit();
        
        // Prepare success message
        $message = "Blocklist updated successfully! ";
        $message .= "Added: {$addedCount} numbers";
        if ($duplicateCount > 0) {
            $message .= ", Duplicates skipped: {$duplicateCount}";
        }
        if ($invalidCount > 0) {
            $message .= ", Invalid entries skipped: {$invalidCount}";
        }
        
        $_SESSION['flash_message'] = [
            'type' => 'success', 
            'text' => $message
        ];
        
    } catch (Exception $e) {
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        $_SESSION['flash_message'] = [
            'type' => 'danger', 
            'text' => 'Error processing blocklist file: ' . $e->getMessage()
        ];
    } finally {
        if (isset($conn) && $conn->ping()) {
            $conn->close();
        }
        header("Location: manage_blocklist.php");
        exit();
    }
}

// Handle individual number deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_number') {
    $adminId = $_SESSION['admin_id'];
    $mobile_no = $_POST['mobile_no'] ?? '';
    
    if (removeFromBlocklist($adminId, $mobile_no)) {
        $_SESSION['flash_message'] = [
            'type' => 'success', 
            'text' => 'Number removed from blocklist successfully.'
        ];
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'danger', 
            'text' => 'Failed to remove number from blocklist.'
        ];
    }
    
    header("Location: manage_blocklist.php");
    exit();
}

// Handle batch deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_batch') {
    $adminId = $_SESSION['admin_id'];
    $batch_id = $_POST['batch_id'] ?? '';
    
    $deletedCount = deleteBlocklistBatch($adminId, $batch_id);
    
    if ($deletedCount > 0) {
        $_SESSION['flash_message'] = [
            'type' => 'success', 
            'text' => "Batch deleted successfully. {$deletedCount} numbers removed from blocklist."
        ];
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'danger', 
            'text' => 'No numbers found in the specified batch.'
        ];
    }
    
    header("Location: manage_blocklist.php");
    exit();
}

// Handle manual number addition
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_single') {
    $adminId = $_SESSION['admin_id'];
    $mobile_no = $_POST['single_mobile_no'] ?? '';
    $notes = $_POST['single_notes'] ?? '';
    
    if (empty($mobile_no)) {
        $_SESSION['flash_message'] = [
            'type' => 'danger', 
            'text' => 'Please enter a mobile number.'
        ];
    } else {
        $clean_mobile = preg_replace('/\D/', '', $mobile_no);
        
        if (strlen($clean_mobile) < 10) {
            $_SESSION['flash_message'] = [
                'type' => 'danger', 
                'text' => 'Please enter a valid mobile number with at least 10 digits.'
            ];
        } else {
            if (isMobileNumberBlocked($adminId, $clean_mobile)) {
                $_SESSION['flash_message'] = [
                    'type' => 'warning', 
                    'text' => 'This number is already in the blocklist.'
                ];
            } else {
                if (addToBlocklist($adminId, $clean_mobile, $adminId, null, $notes)) {
                    $_SESSION['flash_message'] = [
                        'type' => 'success', 
                        'text' => 'Number added to blocklist successfully.'
                    ];
                } else {
                    $_SESSION['flash_message'] = [
                        'type' => 'danger', 
                        'text' => 'Failed to add number to blocklist.'
                    ];
                }
            }
        }
    }
    
    header("Location: manage_blocklist.php");
    exit();
}

// This should not be reached directly
header("Location: manage_blocklist.php");
exit();
?>