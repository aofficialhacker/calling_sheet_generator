<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// Check admin authentication with debugging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    // Log authentication failure
    $logFile = __DIR__ . '/pdf_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] PDF generation failed - not authenticated\n", FILE_APPEND);
    header("Location: admin_login.php");
    exit();
}

use Mpdf\Mpdf;
use Mpdf\HTMLParserMode;

// --- Optional Download Token (for client-side tracking only) ---
$download_token = $_GET['download_token'] ?? null;

set_time_limit(0);
ini_set('memory_limit', '2048M');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors as they break PDF download
ini_set('log_errors', 1); // Log errors to file instead

$conn = getDBConnection();

// Debug logging function
function debugLog($message) {
    $logFile = __DIR__ . '/pdf_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

debugLog("PDF generation started");

// --- Determine Data Source ---
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle = 'Calling Sheet';
$batch_id = null;
$dispositions = null;
$scope = $_GET['scope'] ?? '';
$product_code = $_GET['product_code'] ?? '';

// Handle different types of requests
if (isset($_GET['disposition'])) {
    // Disposition-based request with new scope options
    
    // Handle both single and multiple dispositions
    if (is_array($_GET['disposition'])) {
        $dispositions = $_GET['disposition'];
    } else {
        $dispositions = [$_GET['disposition']];
    }
    
    // Build filename and title based on scope
    $dispNames = array_map(function($d) { return preg_replace("/[^a-zA-Z0-9]/", "", $d); }, $dispositions);
    
    switch ($scope) {
        case 'batch-wise':
            $batch_id = $_GET['batch_id'];
            $pdfFileName = 'Batch_' . $batch_id . '_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for Batch $batch_id - Status: " . implode(', ', $dispositions);
            break;
        case 'all-batch':
            $pdfFileName = 'AllBatches_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for All Batches - Status: " . implode(', ', $dispositions);
            break;
        case 'product-wise':
            $pdfFileName = 'Product_' . $product_code . '_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for Product $product_code - Status: " . implode(', ', $dispositions);
            break;
        case 'all-product':
            $pdfFileName = 'AllProducts_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for All Products - Status: " . implode(', ', $dispositions);
            break;
        default:
            // Legacy single disposition request
            $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
            $pdfFileName = ucwords($safeDispositionName) . '_Sheet.pdf';
            $pdfTitle = "Calling Sheet for Status: " . htmlspecialchars(implode(', ', $dispositions));
    }
} elseif (isset($_GET['batch_id'])) {
    // Legacy single batch request (no disposition filtering)
    $batch_id = $_GET['batch_id'];
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
} else {
    die("Error: No valid batch ID or disposition provided.");
}

// --- Fetch Dynamic Disposition Codes ---
$dispResult = $conn->query("
    SELECT code, description, category 
    FROM disposition_codes 
    WHERE is_active = 1 
    ORDER BY category, CAST(code AS UNSIGNED), code
");
$dispositionList = [];
$dispLegendY = [];
$dispLegendN = [];
while($d = $dispResult->fetch_assoc()){
    $dispositionList[] = $d;
    if($d['category'] == 'connected') {
        $dispLegendY[] = "{$d['code']}:{$d['description']}";
    } else {
        $dispLegendN[] = "{$d['code']}:{$d['description']}";
    }
}

// Build legends
$dispLegend = '';
if (!empty($dispLegendY)) {
    $dispLegend .= "<strong>DISPO (Y):</strong> " . implode(' | ', $dispLegendY);
}
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "<strong>DISPO (N):</strong> " . implode(' | ', $dispLegendN);
}

$slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Build Database Query Based on Filters ---
$baseSql = "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id ";
$whereClauses = [];
$params = [];
$types = '';

// Add admin filter - only show data from current admin's batches
$adminId = $_SESSION['admin_id'];
$whereClauses[] = "fb.admin_id = ?";
$params[] = $adminId;
$types .= 's';

// Apply batch filters based on scope
switch ($scope) {
    case 'batch-wise':
        if ($batch_id) {
            $whereClauses[] = "fcl.batch_id = ?";
            $params[] = $batch_id;
            $types .= 's';
        }
        break;
    case 'all-batch':
        // No additional batch filter - include all batches for this admin
        break;
    case 'product-wise':
        if ($product_code) {
            $whereClauses[] = "fb.product_code = ?";
            $params[] = $product_code;
            $types .= 's';
        }
        break;
    case 'all-product':
        // No additional product filter - include all products for this admin
        break;
    default:
        // Legacy: if batch_id is specified, filter by it
        if ($batch_id) {
            $whereClauses[] = "fcl.batch_id = ?";
            $params[] = $batch_id;
            $types .= 's';
        }
}

// Apply disposition filters
if ($dispositions && !empty($dispositions)) {
    $placeholders = implode(',', array_fill(0, count($dispositions), '?'));
    $whereClauses[] = "fcl.disposition IN ($placeholders)";
    $params = array_merge($params, $dispositions);
    $types .= str_repeat('s', count($dispositions));
}

if (empty($whereClauses)) {
    die("Error: No criteria selected for PDF generation.");
}
$whereSql = "WHERE " . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// Check total record count for optimization
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// Debug: Check if we have data to process
debugLog("Total records found: $totalRecords");
if ($totalRecords == 0) {
    debugLog("No records found - generating error PDF");
    
    // Function to generate a simple PDF with an error message
    function generateErrorPDF($message, $title, $filename) {
        try {
            $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-P', 'tempDir' => __DIR__ . '/tmp']);
            $mpdf->SetTitle($title);
            $html = '<!DOCTYPE html><html><head><style>
                        body { font-family: sans-serif; text-align: center; padding-top: 50px; }
                        .error-container { border: 2px dashed #dc3545; padding: 20px; margin: 30px; background-color: #f8d7da; }
                        h1 { color: #721c24; }
                        p { font-size: 14px; }
                    </style></head><body>
                    <div class="error-container">
                        <h1>PDF Generation Failed</h1>
                        <p>' . htmlspecialchars($message) . '</p>
                        <p>Please adjust your filters on the previous page and try again.</p>
                    </div>
                    </body></html>';
            $mpdf->WriteHTML($html);
            
            // Clear output buffers and output the PDF
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $mpdf->Output($filename, 'D');
        } catch (Exception $e) {
            debugLog("Error generating error PDF: " . $e->getMessage());
            // Fallback if PDF generation itself fails
            header("Content-Type: text/plain", true, 500);
            die("A critical error occurred while generating the error report PDF.");
        }
        exit;
    }
    
    // Generate the error PDF and exit
    generateErrorPDF(
        "No records found matching the selected criteria. Please check your filters and try again.",
        "No Records Found",
        "Error_No_Records.pdf"
    );
}

// Define columns in the order they should appear (MODIFICATION: mobile_no before name)
// Fixed order: id, slot, mobile_no, name, connectivity, disposition, then dynamic columns
$optionalColumns = ['title','name', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN fcl.`{$column}` IS NOT NULL AND fcl.`{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}

$presenceCheckSql = "SELECT " . implode(', ', $selects) . " " . $fullBaseSql;
$stmt = $conn->prepare($presenceCheckSql);
if ($stmt === false) {
    die("Error preparing statement (presence check): " . $conn->error);
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$columnPresence = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Build final headers with mobile_no before name (MODIFICATION 1)
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

// Add name if it has data
if (!empty($columnPresence["has_title"]) || !empty($columnPresence["has_name"])) {
    if (!empty($columnPresence["has_title"])) {
        $finalHeaders[] = 'title';
    }
    $finalHeaders[] = 'name';
}

// Add remaining optional columns that have data, limiting to 12 total columns (MODIFICATION 4)
$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if ($column !== 'title' && $column !== 'name' && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

// Configure mPDF with optimizations for large datasets
$colCount = count($finalHeaders);
debugLog("Creating mPDF instance with $colCount columns");

try {
    $mpdf = new Mpdf([
        'mode' => 'utf-8', 
        'format' => 'A4-L', 
        'tempDir' => __DIR__ . '/tmp',
        'simpleTables' => true, // Optimization for faster processing
        'packTableData' => true, // Memory optimization
        'setAutoTopMargin' => 'stretch', // Better page handling
        'setAutoBottomMargin' => 'stretch',
        'enableImports' => false, // Disable unnecessary features
        'useFixedNormalLineHeight' => true, // Optimize line height calculations
        'useFixedTextBaseline' => true // Optimize text baseline calculations
    ]);
    $mpdf->SetDisplayMode('fullpage');
    $mpdf->SetTitle($pdfTitle);
    debugLog("mPDF instance created successfully");
} catch (Exception $e) {
    debugLog("Error creating mPDF: " . $e->getMessage());
    die("Error initializing PDF generator. Please check server configuration.");
}


// --- VERTICAL CUTLINE (A4 Landscape) - Limited to Mobile Column Area ---
$cutPosMM = 80;  // Aligned near right edge of "Mobile" column

$cutlineCss = "
<style>
  @page { size: A4-L; }
  .cutline {
    position: fixed;
    top: 35mm;  /* Start after header area */
    left: " . ($cutPosMM - 0.2) . "mm;
    width: 0.4mm;
    height: calc(100% - 50mm);  /* Stop before footer area */
    background: repeating-linear-gradient(
      to bottom,
      #555 0 6px,
      transparent 6px 12px
    );
    z-index: 10;
    clip-path: polygon(0% 0%, 100% 0%, 100% 100%, 0% 100%);  /* Ensure clean boundaries */
  }
  .scissor {
    position: fixed;
    font-family: DejaVu Sans, sans-serif;
    font-size: 12pt;
    line-height: 1;
    z-index: 11;
  }
  .scissor.top    { top: 32mm;  left: " . ($cutPosMM - 3) . "mm; }
  .scissor.bottom { bottom: 15mm; left: " . ($cutPosMM - 3) . "mm; }
</style>
";

$cutlineHtml = '
  <div class="cutline"></div>
  <div class="scissor top">&#9986;</div>
  <div class="scissor bottom">&#9986;</div>
';

$mpdf->WriteHTML($cutlineCss, \Mpdf\HTMLParserMode::HEADER_CSS);
$mpdf->SetHTMLHeader($cutlineHtml);

// Create dynamic disposition grid based on active codes
$dispoGridHtml = '<table class="dispo-grid"><tr>';
$gridCols = 0;
$maxCols = 4; // Maximum columns in disposition grid
foreach ($dispositionList as $index => $disp) {
    if ($gridCols >= $maxCols) {
        $dispoGridHtml .= '</tr><tr>';
        $gridCols = 0;
    }
    $dispoGridHtml .= '<td>○ ' . htmlspecialchars($disp['code']) . '</td>';
    $gridCols++;
}
// Fill remaining cells if needed
while ($gridCols < $maxCols) {
    $dispoGridHtml .= '<td></td>';
    $gridCols++;
}
$dispoGridHtml .= '</tr></table>';

// CSS and HTML Head with optimized layout and cutlines
$html_head = '<html><head><style>
    body { font-family: sans-serif; font-size: 7pt; }
    table.data-table { width: 95%; margin: 0 auto; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; page-break-after: auto; height: 12mm; }
    th, td { border: 1px solid #333; padding: 2mm; text-align: left; vertical-align: middle; word-wrap: break-word; overflow: hidden; }
    thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; font-size: 8pt; }
    .id-col { font-size: 7pt; font-family: monospace; text-align: center; width: 7%; }
    .mobile-col { font-weight: bold; font-family: monospace; text-align: center; width: 9%; border-left: 2px dashed #000 !important; border-right: 2px dashed #000 !important; }
    .name-col { width: 12%; font-size: 7pt; }
    .dob-col { width: 8%; font-size: 7pt; }
    .address-col { width: 15%; font-size: 6pt; }
    .connectivity-col, .slot-cell { text-align: center; width: 6%; }
    .disposition-cell { font-size: 6.5pt; padding: 1px !important; width: 15%; }
    .dispo-grid { border: none !important; width: 100%; table-layout: fixed; }
    .dispo-grid td { border: none !important; padding: 0px 2px; text-align: left; font-size: 6.5pt; white-space: nowrap; }
    @media print { 
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    }
</style></head><body>';

// Create Table Header Row with proper column classes
$tableHeaderHtml = '<thead>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $pdfTitle . '</th></tr>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $slotLegend . '</th></tr>';

if (!empty($dispLegend)) {
    $tableHeaderHtml .= '<tr><th class="legend-cell" colspan="' . $colCount . '">' . $dispLegend . '</th></tr>';
}

$tableHeaderHtml .= '<tr>';
foreach ($finalHeaders as $header) {
    $headerClass = '';
    switch($header) {
        case 'id':
            $headerClass = 'id-col';
            break;
        case 'mobile_no':
            $headerClass = 'mobile-col';
            break;
        case 'name':
            $headerClass = 'name-col';
            break;
        case 'dob':
            $headerClass = 'dob-col';
            break;
        case 'address':
            $headerClass = 'address-col';
            break;
        case 'connectivity':
            $headerClass = 'connectivity-col';
            break;
        case 'disposition':
            $headerClass = 'disposition-cell';
            break;
    }
    $displayHeader = str_replace('_', ' ', ucwords($header));
    if ($header === 'mobile_no') $displayHeader = 'Mobile';
    $tableHeaderHtml .= '<th class="' . $headerClass . '">' . htmlspecialchars($displayHeader) . '</th>';
}
$tableHeaderHtml .= '</tr></thead>';

// Write initial HTML structure to mPDF
$mpdf->WriteHTML($html_head);
$mpdf->SetHTMLFooter('<div style="text-align: right; font-size: 8pt;">Page {PAGENO} of {nbpg}</div>');

// Optimized processing for large datasets
$chunkSize = 2000; // Larger chunks for better performance
$offset = 0;

// Function to format date
function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    return $dateValue;
}

// Start table with header
$mpdf->WriteHTML('<table class="data-table">' . $tableHeaderHtml . '<tbody>');

$rowsProcessed = 0;
$maxRows = min($totalRecords, 10000); // Limit to 10K records
set_time_limit(300); // 5 minute timeout

if ($totalRecords > $maxRows) {
    debugLog("Warning: Large dataset ($totalRecords records). Processing first $maxRows records.");
}

while ($rowsProcessed < $totalRecords && $rowsProcessed < $maxRows) {
    // Properly build column selection with table alias
    $finalHeadersWithAlias = array_map(function($header) {
        return 'fcl.`' . $header . '`';
    }, $finalHeaders);
    $columnsToSelectWithAlias = implode(', ', $finalHeadersWithAlias);
    
    $currentChunkSize = min($chunkSize, $totalRecords - $offset, $maxRows - $rowsProcessed);
    $sql = "SELECT {$columnsToSelectWithAlias} " . $fullBaseSql . " ORDER BY fcl.id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        die("Error preparing statement (data fetch): " . $conn->error);
    }

    $chunkParams = array_merge($params, [$offset, $currentChunkSize]);
    $chunkTypes = $types . 'ii';
    if($chunkTypes) $stmt->bind_param($chunkTypes, ...$chunkParams);
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        break;
    }

    // Build HTML for this chunk in memory (more efficient than row-by-row)
    $chunkHtml = '';
    $chunkRows = 0;
    while ($row = $result->fetch_assoc()) {
        $chunkHtml .= '<tr>';
        foreach ($finalHeaders as $header) {
            $class = '';
            $cellContent = '';
            switch($header) {
                case 'disposition':
                    // Use the dynamic disposition grid
                    $cellContent = $dispoGridHtml;
                    $class = 'disposition-cell';
                    break;
                case 'connectivity':
                    $cellContent = '○ Y / ○ N';
                    $class = 'connectivity-col';
                    break;
                case 'slot':
                    $cellContent = '';
                    $class = 'slot-cell';
                    break;
                case 'mobile_no':
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    $class = 'mobile-col';
                    break;
                case 'id':
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    $class = 'id-col';
                    break;
                case 'name':
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    $class = 'name-col';
                    break;
                case 'dob':
                case 'expiry':
                    $cellContent = htmlspecialchars(formatDateForPDF($row[$header] ?? ''));
                    $class = 'dob-col';
                    break;
                case 'address':
                    $addr = $row[$header] ?? '';
                    // Intelligent address formatting instead of truncation
                    if (strlen($addr) > 40) {
                        $words = explode(' ', $addr);
                        $line1 = '';
                        foreach ($words as $word) {
                            if (strlen($line1 . ' ' . $word) <= 40) {
                                $line1 .= ($line1 ? ' ' : '') . $word;
                            } else {
                                break;
                            }
                        }
                        $cellContent = htmlspecialchars($line1 . (strlen($addr) > strlen($line1) ? '..' : ''));
                    } else {
                        $cellContent = htmlspecialchars($addr);
                    }
                    $class = 'address-col';
                    break;
                default:
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    break;
            }
            $chunkHtml .= '<td class="' . $class . '">' . $cellContent . '</td>';
        }
        $chunkHtml .= '</tr>';
        $rowsProcessed++;
        $chunkRows++;
    }
    
    // Only write if we have rows to avoid empty chunks
    if ($chunkRows > 0) {
        $mpdf->WriteHTML($chunkHtml);
    }
    
    $stmt->close();
    $offset += $chunkSize;
    
    // Memory management
    if ($rowsProcessed % 1000 === 0) {
        $memUsage = memory_get_usage(true) / 1024 / 1024; // MB
        debugLog("Processed $rowsProcessed records, Memory: {$memUsage}MB");
        if ($memUsage > 800) {
            debugLog("High memory usage. Stopping processing.");
            break;
        }
        gc_collect_cycles();
    }
    
    // Clear memory
    unset($chunkHtml);
}

// Close table and body
debugLog("Closing HTML structure");
$mpdf->WriteHTML('</tbody></table></body></html>');

debugLog("Preparing PDF output - clearing buffers");
// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

debugLog("Setting PDF headers");
// Set proper headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $pdfFileName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

debugLog("Outputting PDF: $pdfFileName");
try {
    $mpdf->Output($pdfFileName, 'D');
    debugLog("PDF output successful");
} catch (Exception $e) {
    debugLog("Error outputting PDF: " . $e->getMessage());
    die("Error generating PDF output.");
}

$conn->close();
exit;