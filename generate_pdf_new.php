<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// Check admin authentication with debugging
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isAdmin() && !isSuperadmin()) {
    // Log authentication failure
    $logFile = __DIR__ . '/pdf_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] PDF generation failed - not authenticated\n", FILE_APPEND);
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

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

debugLog("TCPDF PDF generation started");

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
    $dispLegend .= "DISPO (Y): " . implode(' | ', $dispLegendY);
}
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "DISPO (N): " . implode(' | ', $dispLegendN);
}

$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

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
    die("No records found matching the criteria. Please check your filters and try again.");
}

// Define columns in the order they should appear (mobile_no before name)
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

// Build final headers with mobile_no before name
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

// Add name if it has data
if (!empty($columnPresence["has_title"]) || !empty($columnPresence["has_name"])) {
    if (!empty($columnPresence["has_title"])) {
        $finalHeaders[] = 'title';
    }
    $finalHeaders[] = 'name';
}

// Add remaining optional columns that have data, limiting to 12 total columns
$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if ($column !== 'title' && $column !== 'name' && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

debugLog("Creating TCPDF instance with " . count($finalHeaders) . " columns");

// Create custom TCPDF class
class CallSheetPDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    private $finalHeaders;
    private $cutlinePosition;
    
    public function __construct($title, $slotLegend, $dispLegend, $finalHeaders) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
        $this->finalHeaders = $finalHeaders;
        
        // Calculate cutline position (after mobile column)
        $mobileIndex = array_search('mobile_no', $finalHeaders);
        $this->cutlinePosition = 10 + ($mobileIndex + 1) * 22; // Approximate position
    }
    
    public function Header() {
        // Draw cutline first (behind everything)
        $this->SetDrawColor(85, 85, 85);
        $this->SetLineWidth(0.2);
        
        // Draw dashed vertical line
        for ($y = 25; $y < $this->getPageHeight() - 20; $y += 3) {
            $this->Line($this->cutlinePosition, $y, $this->cutlinePosition, $y + 1.5);
        }
        
        // Add scissors at top and bottom
        $this->SetXY($this->cutlinePosition - 3, 20);
        $this->SetFont('dejavusans', '', 12);
        $this->Cell(5, 5, '✂', 0, 0, 'C');
        
        $this->SetXY($this->cutlinePosition - 3, $this->getPageHeight() - 25);
        $this->Cell(5, 5, '✂', 0, 0, 'C');
        
        // Title
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 8, $this->pdfTitle, 0, 1, 'C');
        
        // Slot legend
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 5, $this->slotLegend, 0, 1, 'C');
        
        // Disposition legend
        if (!empty($this->dispLegend)) {
            $this->Cell(0, 5, $this->dispLegend, 0, 1, 'C');
        }
        
        $this->Ln(2);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
    
    public function drawTableHeader($finalHeaders) {
        $this->SetFont('helvetica', 'B', 7);
        $this->SetFillColor(240, 240, 240);
        
        // Column widths (adjust as needed)
        $widths = [];
        foreach ($finalHeaders as $header) {
            switch ($header) {
                case 'id': $widths[] = 15; break;
                case 'slot': $widths[] = 12; break;
                case 'connectivity': $widths[] = 20; break;
                case 'disposition': $widths[] = 25; break;
                case 'mobile_no': $widths[] = 22; break;
                case 'name': $widths[] = 30; break;
                case 'title': $widths[] = 15; break;
                case 'policy_number': $widths[] = 20; break;
                case 'age': $widths[] = 10; break;
                default: $widths[] = 18; break;
            }
        }
        
        foreach ($finalHeaders as $i => $header) {
            $displayHeader = str_replace('_', ' ', ucwords($header));
            if ($header === 'mobile_no') $displayHeader = 'Mobile';
            $this->Cell($widths[$i], 8, $displayHeader, 1, 0, 'C', true);
        }
        $this->Ln();
        
        return $widths;
    }
}

// Function to format date
function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    return $dateValue;
}

try {
    // Create PDF instance
    $pdf = new CallSheetPDF($pdfTitle, $slotLegend, $dispLegend, $finalHeaders);
    
    // Set document information
    $pdf->SetCreator('Calling Sheet Generator');
    $pdf->SetTitle($pdfTitle);
    
    // Set margins
    $pdf->SetMargins(10, 35, 10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true, 20);
    
    // Add first page
    $pdf->AddPage();
    
    // Draw table header
    $columnWidths = $pdf->drawTableHeader($finalHeaders);
    
    debugLog("Starting data processing");
    
    // Process data in optimized chunks
    $chunkSize = 2000;
    $offset = 0;
    $rowsProcessed = 0;
    
    // Create dynamic disposition grid
    $dispoGrid = '';
    $gridCols = 0;
    $maxCols = 4;
    foreach ($dispositionList as $disp) {
        if ($gridCols >= $maxCols) {
            $dispoGrid .= "\n";
            $gridCols = 0;
        }
        $dispoGrid .= '○ ' . $disp['code'] . '  ';
        $gridCols++;
    }
    
    while ($rowsProcessed < $totalRecords) {
        $finalHeadersWithAlias = array_map(function($header) {
            return 'fcl.`' . $header . '`';
        }, $finalHeaders);
        $columnsToSelectWithAlias = implode(', ', $finalHeadersWithAlias);
        
        $sql = "SELECT {$columnsToSelectWithAlias} " . $fullBaseSql . " ORDER BY fcl.id LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            throw new Exception("Error preparing statement (data fetch): " . $conn->error);
        }

        $chunkParams = array_merge($params, [$offset, $chunkSize]);
        $chunkTypes = $types . 'ii';
        if($chunkTypes) $stmt->bind_param($chunkTypes, ...$chunkParams);
        
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            break;
        }

        while ($row = $result->fetch_assoc()) {
            // Check if we need a new page
            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                $columnWidths = $pdf->drawTableHeader($finalHeaders);
            }
            
            $pdf->SetFont('helvetica', '', 6);
            
            foreach ($finalHeaders as $i => $header) {
                $cellContent = '';
                switch($header) {
                    case 'disposition':
                        $cellContent = $dispoGrid;
                        break;
                    case 'connectivity':
                        $cellContent = '○ Y / ○ N';
                        break;
                    case 'slot':
                        $cellContent = '';
                        break;
                    case 'mobile_no':
                        $cellContent = $row[$header] ?? '';
                        $pdf->SetFont('helvetica', 'B', 6); // Bold for mobile
                        break;
                    case 'id':
                        $cellContent = substr($row[$header] ?? '', -8); // Last 8 chars
                        $pdf->SetFont('helvetica', '', 5); // Smaller font
                        break;
                    case 'dob':
                    case 'expiry':
                        $cellContent = formatDateForPDF($row[$header] ?? '');
                        break;
                    default:
                        $cellContent = $row[$header] ?? '';
                        break;
                }
                
                $pdf->Cell($columnWidths[$i], 6, $cellContent, 1, 0, 'L');
                
                // Reset font for next cell
                if ($header === 'mobile_no' || $header === 'id') {
                    $pdf->SetFont('helvetica', '', 6);
                }
            }
            $pdf->Ln();
            $rowsProcessed++;
        }
        
        $stmt->close();
        $offset += $chunkSize;
    }
    
    debugLog("Data processing complete, outputting PDF");

    // Clear output buffer and send PDF
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdfFileName . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $pdf->Output($pdfFileName, 'D');
    debugLog("PDF output successful");

} catch (Exception $e) {
    debugLog("Error during PDF generation: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}

$conn->close();
exit;
?>