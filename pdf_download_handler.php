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

$productFilter = $_GET['product_filter'] ?? '';
$scope = $_GET['scope'] ?? '';
$batchScope = $_GET['batch_scope'] ?? '';
$batchId = $_GET['batch_id'] ?? null;
$productCode = $_GET['product_code'] ?? null;
$callerId = $_GET['caller_id'] ?? null;

// Validate required parameters
if (!$disposition && !$batchId) {
    die("Error: No valid disposition or batch ID provided.");
}

// Build counter tracking parameters
$counterBatchId = null;
$counterProductCode = null;
$counterCallerId = $callerId;

// Determine counter parameters based on scope and filters
switch ($productFilter) {
    case 'product-wise':
        $counterProductCode = $productCode;
        
        if ($batchScope === 'batch-wise') {
            $counterBatchId = $batchId;
        }
        // If 'all-batches', counterBatchId stays null (applies to all batches of the product)
        break;
        
    case 'all-products':
        // counterProductCode stays null (applies to all products)
        
        if ($scope === 'batch-wise') {
            $counterBatchId = $batchId;
        }
        // If 'all-batch', counterBatchId stays null (applies to all batches)
        break;
}

// Check download limits if disposition is provided
if ($disposition) {
    if (!$downloadCounter->canDownload($adminId, $disposition, $counterBatchId, $counterProductCode, $counterCallerId)) {
        $limit = $downloadCounter->getAdminDownloadLimit($adminId);
        $usage = $downloadCounter->getCurrentUsage($adminId, $disposition, $counterBatchId, $counterProductCode, $counterCallerId);
        
        $scopeDescription = buildScopeDescription($productFilter, $scope, $batchScope, $batchId, $productCode, $callerId);
        
        http_response_code(429); // Too Many Requests
        die("Download limit reached! You have exceeded your limit of {$limit} downloads for disposition '{$disposition}' with the following scope: {$scopeDescription}. Current usage: {$usage}/{$limit}.");
    }
}

// Handle "All Batches" exclusions for disposition-based downloads
$excludedBatches = [];
if ($disposition && (($productFilter === 'all-products' && $scope === 'all-batch') || 
                    ($productFilter === 'product-wise' && $batchScope === 'all-batches'))) {
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

function buildScopeDescription($productFilter, $scope, $batchScope, $batchId, $productCode, $callerId) {
    $parts = [];
    
    if ($productFilter === 'product-wise') {
        $parts[] = "Product: " . ($productCode ?: 'N/A');
        if ($batchScope === 'batch-wise') {
            $parts[] = "Batch: " . ($batchId ?: 'N/A');
        } else {
            $parts[] = "All Batches for selected product";
        }
    } elseif ($productFilter === 'all-products') {
        $parts[] = "All Products";
        if ($scope === 'batch-wise') {
            $parts[] = "Batch: " . ($batchId ?: 'N/A');
        } else {
            $parts[] = "All Batches";
        }
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