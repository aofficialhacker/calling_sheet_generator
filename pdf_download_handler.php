<?php
/**
 * PDF Download Handler with Counter Enforcement
 * This file handles the download counter logic before calling generate_pdf.php
 */

require_once 'db_config.php';
require_once 'download_counter.php';

// Start session and check authentication
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    http_response_code(403);
    die("Error: Access denied. Please log in as an admin.");
}

$conn = getDBConnection();
$downloadCounter = new DownloadCounter($conn);
$adminId = $_SESSION['admin_id'];

// Parse request parameters
$disposition = null;
$dispositions = [];

if (isset($_GET['disposition'])) {
    if (is_array($_GET['disposition'])) {
        $dispositions = $_GET['disposition'];
        $disposition = $dispositions[0]; // Use first disposition for counter tracking
    } else {
        $disposition = $_GET['disposition'];
        $dispositions = [$disposition];
    }
}

// Parse parameters from the simplified interface
$scope = $_GET['scope'] ?? '';
$batchId = !empty($_GET['batch_id']) ? $_GET['batch_id'] : null;
$productCode = !empty($_GET['product_code']) ? $_GET['product_code'] : null;
$callerId = !empty($_GET['caller_id']) ? $_GET['caller_id'] : null;

// Validate required parameters
if (!$disposition && !$batchId) {
    die("Error: No valid disposition or batch ID provided.");
}

// Build counter tracking parameters based on the simplified scope logic
$counterBatchId = null;
$counterProductCode = null;
$counterCallerId = $callerId;

// Map the simplified scope to counter parameters
switch ($scope) {
    case 'batch-wise':
        // Specific batch selected
        $counterBatchId = $batchId;
        // Don't set product code for counter - batch-specific tracking
        break;
        
    case 'product-wise':
        // Specific product, all batches
        $counterProductCode = $productCode;
        // counterBatchId stays null (applies to all batches of the product)
        break;
        
    case 'all-product':
    default:
        // All products, all batches
        // Both stay null (applies to everything)
        break;
}

// Set legacy parameters for backward compatibility with generate_pdf.php
$productFilter = '';
$batchScope = '';

switch ($scope) {
    case 'batch-wise':
        $productFilter = 'all-products';
        $batchScope = 'batch-wise';
        break;
    case 'product-wise':
        $productFilter = 'product-wise';
        $batchScope = 'all-batches';
        break;
    case 'all-product':
        $productFilter = 'all-products';
        $batchScope = 'all-batch';
        break;
}

// Check download limits if disposition is provided
if ($disposition) {
    if (!$downloadCounter->canDownload($adminId, $disposition, $counterBatchId, $counterProductCode, $counterCallerId)) {
        $limit = $downloadCounter->getAdminDownloadLimit($adminId);
        $usage = $downloadCounter->getCurrentUsage($adminId, $disposition, $counterBatchId, $counterProductCode, $counterCallerId);
        
        $scopeDescription = buildScopeDescription($scope, $batchId, $productCode, $callerId);
        
        http_response_code(429); // Too Many Requests
        die("Download limit reached! You have exceeded your limit of {$limit} downloads for disposition '{$disposition}' with the following scope: {$scopeDescription}. Current usage: {$usage}/{$limit}.");
    }
}

// Handle "All Batches" exclusions for disposition-based downloads
$excludedBatches = [];
if ($disposition && ($scope === 'all-product' || $scope === 'product-wise')) {
    $excludedBatches = $downloadCounter->getExcludedBatches($adminId, $disposition);
    
    if (!empty($excludedBatches)) {
        // Add excluded batches to the request for generate_pdf.php to handle
        $_GET['excluded_batches'] = implode(',', $excludedBatches);
    }
}

// Record the download attempt
if ($disposition) {
    $downloadCounter->recordDownload($adminId, $disposition, $counterBatchId, $counterProductCode, $counterCallerId);
}

// Forward to generate_pdf.php with modified parameters
include 'generate_pdf.php';

function buildScopeDescription($scope, $batchId, $productCode, $callerId) {
    $parts = [];
    
    switch ($scope) {
        case 'batch-wise':
            $parts[] = "Batch: " . ($batchId ?: 'N/A');
            break;
            
        case 'product-wise':
            $parts[] = "Product: " . ($productCode ?: 'N/A');
            $parts[] = "All Batches for selected product";
            break;
            
        case 'all-product':
        default:
            $parts[] = "All Products";
            $parts[] = "All Batches";
            break;
    }
    
    if ($callerId) {
        $parts[] = "Caller: " . $callerId;
    } else {
        $parts[] = "All Callers";
    }
    
    return implode(', ', $parts);
}

$conn->close();
?>