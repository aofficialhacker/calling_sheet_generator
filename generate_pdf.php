<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// Check admin authentication
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

set_time_limit(300); // 5 minute timeout instead of unlimited
ini_set('memory_limit', '1024M'); // Reasonable memory limit
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);

// Enable output buffering for better performance
if (ob_get_level() == 0) ob_start();

// Error logging function for debugging
function debugLog($message) {
    error_log(date('[Y-m-d H:i:s] ') . "PDF Generation: " . $message);
}

debugLog("PDF generation started");

$conn = getDBConnection();
debugLog("Database connection established");

// Get all the same parameters as the original
$batch_id = $_GET['batch_id'] ?? null;
$dispositions = null;
$scope = $_GET['scope'] ?? '';
$product_code = $_GET['product_code'] ?? '';

if (!$batch_id && !isset($_GET['disposition'])) {
    die("Error: No valid batch ID or disposition provided.");
}

$adminId = $_SESSION['admin_id'];

// Handle different types of requests (same logic as original)
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle = 'Calling Sheet';

if (isset($_GET['disposition'])) {
    if (is_array($_GET['disposition'])) {
        $dispositions = $_GET['disposition'];
    } else {
        $dispositions = [$_GET['disposition']];
    }
    
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
            $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
            $pdfFileName = ucwords($safeDispositionName) . '_Sheet.pdf';
            $pdfTitle = "Calling Sheet for Status: " . implode(', ', $dispositions);
    }
} elseif (isset($_GET['batch_id'])) {
    $batch_id = $_GET['batch_id'];
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
}

// --- Fetch Dynamic Disposition Codes (same as original) ---
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

// Build legends (same as original)
$dispLegend = '';
if (!empty($dispLegendY)) {
    $dispLegend .= "DISPO (Y): " . implode(' | ', $dispLegendY);
}
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "DISPO (N): " . implode(' | ', $dispLegendN);
}

$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Build Database Query (same as original) ---
$baseSql = "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id ";
$whereClauses = [];
$params = [];
$types = '';

$whereClauses[] = "fb.admin_id = ?";
$params[] = $adminId;
$types .= 's';

switch ($scope) {
    case 'batch-wise':
        if ($batch_id) {
            $whereClauses[] = "fcl.batch_id = ?";
            $params[] = $batch_id;
            $types .= 's';
        }
        break;
    case 'product-wise':
        if ($product_code) {
            $whereClauses[] = "fb.product_code = ?";
            $params[] = $product_code;
            $types .= 's';
        }
        break;
    default:
        if ($batch_id) {
            $whereClauses[] = "fcl.batch_id = ?";
            $params[] = $batch_id;
            $types .= 's';
        }
}

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

// Check total record count
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

if ($totalRecords == 0) {
    die("No records found matching the criteria.");
}

// Column detection logic (same as original)
$optionalColumns = ['title','name', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN fcl.`{$column}` IS NOT NULL AND fcl.`{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}

$presenceCheckSql = "SELECT " . implode(', ', $selects) . " " . $fullBaseSql;
$stmt = $conn->prepare($presenceCheckSql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$columnPresence = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Build final headers (same logic as original)
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

if (!empty($columnPresence["has_title"]) || !empty($columnPresence["has_name"])) {
    if (!empty($columnPresence["has_title"])) {
        $finalHeaders[] = 'title';
    }
    $finalHeaders[] = 'name';
}

$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if ($column !== 'title' && $column !== 'name' && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

// Create custom PDF class with proper header/footer for every page
class CompletePDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    
    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
    }
    
    public function Header() {
        // Title and legends on EVERY page
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(0, 6, $this->pdfTitle, 0, 1, 'C');
        
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 4, $this->slotLegend, 0, 1, 'C');
        
        // Handle long disposition legend
        $dispLines = strlen($this->dispLegend) > 140 ? explode(' || ', $this->dispLegend) : [$this->dispLegend];
        foreach ($dispLines as $line) {
            $this->Cell(0, 3, $line, 0, 1, 'C');
        }
        $this->Ln(2);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

// Set up optimized column structure with proper widths FIRST
$columnData = [];
$totalWidth = 275; // A4 landscape minus margins for centering

foreach ($finalHeaders as $header) {
    switch ($header) {
        case 'id':
            $columnData[] = ['header' => 'Id', 'width' => 20];
            break;
        case 'slot':
            $columnData[] = ['header' => 'Slot', 'width' => 10];
            break;
        case 'connectivity':
            $columnData[] = ['header' => 'Connectivity', 'width' => 18];
            break;
        case 'disposition':
            $columnData[] = ['header' => 'Disposition', 'width' => 42];
            break;
        case 'mobile_no':
            $columnData[] = ['header' => 'Mobile', 'width' => 25];
            break;
        case 'title':
            $columnData[] = ['header' => 'Title', 'width' => 12];
            break;
        case 'name':
            $columnData[] = ['header' => 'Name', 'width' => 35]; // Increased to prevent overlap
            break;
        case 'pan':
            $columnData[] = ['header' => 'Pan', 'width' => 18];
            break;
        case 'dob':
            $columnData[] = ['header' => 'Dob', 'width' => 22]; // Increased for better spacing
            break;
        case 'age':
            $columnData[] = ['header' => 'Age', 'width' => 8];
            break;
        case 'address':
            $columnData[] = ['header' => 'Address', 'width' => 45]; // Increased for less truncation
            break;
        case 'city':
            $columnData[] = ['header' => 'City', 'width' => 18];
            break;
        case 'state':
            $columnData[] = ['header' => 'State', 'width' => 18];
            break;
        case 'pincode':
            $columnData[] = ['header' => 'Pincode', 'width' => 16];
            break;
        default:
            $columnData[] = ['header' => ucwords(str_replace('_', ' ', $header)), 'width' => 16];
            break;
    }
}

debugLog("Creating PDF with title: $pdfTitle");
debugLog("Column data prepared with " . count($columnData) . " columns");

// Create PDF with proper header/footer AFTER column data is ready
$pdf = new CompletePDF($pdfTitle, $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);

debugLog("PDF object created successfully");

// Scale to fit if necessary
$currentTotal = array_sum(array_column($columnData, 'width'));
if ($currentTotal > $totalWidth) {
    $scale = $totalWidth / $currentTotal;
    foreach ($columnData as &$col) {
        $col['width'] *= $scale;
    }
    // Recalculate centering after scaling
    $currentTotal = array_sum(array_column($columnData, 'width'));
}

// Calculate centering margins now that we have proper column data
$pageWidth = 297; // A4 landscape width
$leftMargin = ($pageWidth - $currentTotal) / 2;
$pdf->SetMargins($leftMargin, 35, $leftMargin); // Centered margins
$pdf->SetAutoPageBreak(true, 15);

$pdf->AddPage();

// Draw header row with proper styling
$pdf->SetFont('helvetica', 'B', 8); // Larger font for headers
$pdf->SetFillColor(240, 240, 240);
$headerHeight = 7;
foreach ($columnData as $col) {
    $pdf->Cell($col['width'], $headerHeight, $col['header'], 1, 0, 'C', true);
}
$pdf->Ln();

// Calculate cutline positions (before and after Mobile column)
$mobileIndex = array_search('mobile_no', $finalHeaders);
if ($mobileIndex !== false) {
    // Calculate X position before Mobile column
    $cutlineBefore = $leftMargin;
    for ($i = 0; $i < $mobileIndex; $i++) {
        $cutlineBefore += $columnData[$i]['width'];
    }
    
    // Calculate X position after Mobile column  
    $cutlineAfter = $cutlineBefore + $columnData[$mobileIndex]['width'];
    
    // Draw cutlines (dashed vertical lines)
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $startY = $pdf->GetY();
    
    // Store cutline positions for use in page breaks
    $GLOBALS['cutlineBefore'] = $cutlineBefore;
    $GLOBALS['cutlineAfter'] = $cutlineAfter;
    
    // Function to draw cutlines
    function drawCutlines($pdf, $startY, $endY) {
        $cutlineBefore = $GLOBALS['cutlineBefore'];
        $cutlineAfter = $GLOBALS['cutlineAfter'];
        
        // Draw dashed lines with 2mm dash pattern
        for ($y = $startY; $y < $endY; $y += 4) {
            $pdf->Line($cutlineBefore, $y, $cutlineBefore, min($y + 2, $endY));
            $pdf->Line($cutlineAfter, $y, $cutlineAfter, min($y + 2, $endY));
        }
    }
    
    // Draw initial cutlines
    drawCutlines($pdf, $startY, 200);
}

// Create better spaced disposition grid
$dispGrid = '';
$gridRow1 = [];
$gridRow2 = [];
$gridRow3 = [];

foreach ($dispositionList as $i => $disp) {
    if ($i < 5) { // 5 codes per row instead of 4 for better spacing
        $gridRow1[] = 'O ' . $disp['code'];
    } elseif ($i < 10) {
        $gridRow2[] = 'O ' . $disp['code'];
    } else {
        $gridRow3[] = 'O ' . $disp['code'];
    }
}

// Better spacing between disposition codes
$dispGrid = implode('  ', $gridRow1); // Double space for better readability
if (!empty($gridRow2)) {
    $dispGrid .= "\n" . implode('  ', $gridRow2);
}
if (!empty($gridRow3)) {
    $dispGrid .= "\n" . implode('  ', $gridRow3);
}

function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    return $dateValue;
}

debugLog("Found $totalRecords total records");

// Implement chunked processing for large datasets
$finalHeadersWithAlias = array_map(function($header) {
    return 'fcl.`' . $header . '`';
}, $finalHeaders);
$columnsToSelectWithAlias = implode(', ', $finalHeadersWithAlias);

// Process data in chunks to prevent memory issues
$chunkSize = 1000; // Process 1000 records at a time
$offset = 0;
$processedRows = 0;
$maxRows = min($totalRecords, 10000); // Limit to 10K records for performance

if ($totalRecords > $maxRows) {
    debugLog("Warning: Large dataset ($totalRecords records). Processing first $maxRows records.");
}

debugLog("Column headers: " . implode(', ', $finalHeaders));

// Function to redraw headers on new page
function redrawHeaders($pdf, $columnData) {
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    foreach ($columnData as $col) {
        $pdf->Cell($col['width'], 7, $col['header'], 1, 0, 'C', true);
    }
    $pdf->Ln();
    
    // Redraw cutlines if mobile column exists
    if (isset($GLOBALS['cutlineBefore']) && isset($GLOBALS['cutlineAfter'])) {
        $startY = $pdf->GetY();
        drawCutlines($pdf, $startY, 200);
    }
}

$rowHeight = 12; // Increased row height to prevent overlapping
$memoryCheckInterval = 100; // Check memory every 100 rows

// Process data in chunks to prevent timeout and memory issues
while ($offset < $totalRecords && $processedRows < $maxRows) {
    $currentChunkSize = min($chunkSize, $totalRecords - $offset, $maxRows - $processedRows);
    
    $sql = "SELECT {$columnsToSelectWithAlias} " . $fullBaseSql . " ORDER BY fcl.id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    $chunkParams = array_merge($params, [$offset, $currentChunkSize]);
    $chunkTypes = $types . 'ii';
    
    if ($chunkTypes) {
        $stmt->bind_param($chunkTypes, ...$chunkParams);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        break;
    }
    
    // Process current chunk
    $chunkRowCount = 0;
    while ($row = $result->fetch_assoc()) {
        // Memory and time management
        if ($chunkRowCount % $memoryCheckInterval === 0) {
            $memUsage = memory_get_usage(true) / 1024 / 1024; // MB
            if ($memUsage > 800) { // If using more than 800MB
                debugLog("High memory usage: {$memUsage}MB. Stopping processing.");
                break 2; // Break both while loops
            }
        }
        
        if ($pdf->GetY() + $rowHeight > 190) {
            $pdf->AddPage();
            redrawHeaders($pdf, $columnData);
        }
        
        $startY = $pdf->GetY();
    
    foreach ($finalHeaders as $i => $header) {
        $cellContent = '';
        $fontSize = 7; // Slightly larger font
        $isBold = false;
        
        switch($header) {
            case 'disposition':
                $cellContent = $dispGrid;
                $fontSize = 6; // Readable size for disposition
                break;
            case 'connectivity':
                $cellContent = 'O Y / O N';
                break;
            case 'slot':
                $cellContent = '';
                break;
            case 'mobile_no':
                $cellContent = $row[$header] ?? '';
                $isBold = true;
                break;
            case 'id':
                $cellContent = $row[$header] ?? '';
                break;
            case 'dob':
            case 'expiry':
                $cellContent = formatDateForPDF($row[$header] ?? '');
                break;
            case 'address':
                $addr = $row[$header] ?? '';
                // Use intelligent wrapping instead of truncation
                if (strlen($addr) > 35) {
                    // Break at natural word boundaries when possible
                    $words = explode(' ', $addr);
                    $line1 = '';
                    foreach ($words as $word) {
                        if (strlen($line1 . ' ' . $word) <= 35) {
                            $line1 .= ($line1 ? ' ' : '') . $word;
                        } else {
                            break;
                        }
                    }
                    $cellContent = $line1 . (strlen($addr) > strlen($line1) ? '..' : '');
                } else {
                    $cellContent = $addr;
                }
                $fontSize = 6;
                break;
            default:
                $cellContent = $row[$header] ?? '';
                if (strlen($cellContent) > 20) {
                    $cellContent = substr($cellContent, 0, 20) . '..';
                }
                break;
        }
        
        $pdf->SetFont('helvetica', $isBold ? 'B' : '', $fontSize);
        
        // Proper cell alignment with padding to prevent overlapping
        if ($header === 'disposition') {
            $currentX = $pdf->GetX();
            $pdf->MultiCell($columnData[$i]['width'], $rowHeight, $cellContent, 1, 'C', false);
            $pdf->SetXY($currentX + $columnData[$i]['width'], $startY);
        } else {
            $pdf->SetXY($pdf->GetX(), $startY);
            // Add proper alignment based on content type
            $alignment = ($header === 'id' || $header === 'age' || $header === 'mobile_no') ? 'C' : 'L';
            $pdf->Cell($columnData[$i]['width'], $rowHeight, $cellContent, 1, 0, $alignment);
        }
    }
        
        $pdf->SetXY($leftMargin, $startY + $rowHeight);
        $chunkRowCount++;
        $processedRows++;
    }
    
    $stmt->close();
    $offset += $currentChunkSize;
    
    // Optional progress indication (can be removed for production)
    if ($processedRows % 500 === 0) {
        debugLog("Processed $processedRows / $totalRecords records");
    }
    
    // Force garbage collection periodically
    if ($processedRows % 1000 === 0) {
        gc_collect_cycles();
    }
}

// Add completion statistics
debugLog("PDF generation completed. Processed $processedRows records.");

if ($processedRows === 0) {
    debugLog("ERROR: No records were processed!");
} else {
    debugLog("SUCCESS: PDF ready for output");
}

// Error handling and safe PDF output
try {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Check if PDF has content
    if ($processedRows === 0) {
        // Generate error PDF if no data was processed
        $pdf = new CompletePDF("No Data Found", "", "No records found matching your criteria.");
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 20, 'No Data Found', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'No records found matching your selection criteria.', 0, 1, 'C');
        $pdf->Cell(0, 10, 'Please adjust your filters and try again.', 0, 1, 'C');
    }
    
    // Set proper headers for PDF download
    header('Content-Type: application/pdf', true);
    header('Content-Disposition: attachment; filename="' . $pdfFileName . '"', true);
    header('Cache-Control: private, max-age=0, must-revalidate', true);
    header('Pragma: public', true);
    
    // Output the PDF
    $pdf->Output($pdfFileName, 'D');
    
} catch (Exception $e) {
    // Handle any PDF output errors
    debugLog("Error during PDF output: " . $e->getMessage());
    
    // Clear any partial output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send error response
    header('Content-Type: text/html', true, 500);
    echo '<!DOCTYPE html><html><head><title>PDF Generation Error</title></head><body>';
    echo '<h2>PDF Generation Error</h2>';
    echo '<p>An error occurred while generating the PDF. Please try again.</p>';
    echo '<p>If the problem persists, try selecting a smaller date range or fewer records.</p>';
    echo '<button onclick="history.back()">Go Back</button>';
    echo '</body></html>';
} finally {
    // Always close database connection
    if (isset($conn)) {
        $conn->close();
    }
}

exit;
?>