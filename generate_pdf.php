<?php
// ---------- Start buffering BEFORE any include ----------
if (!headers_sent()) { ob_start(); }
ini_set('zlib.output_compression', '0'); // avoid proxy/compression issues

require 'vendor/autoload.php';
require_once 'db_config.php';

// --- Auth ---------------------------------------------------------------
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

// --- Runtime ------------------------------------------------------------
set_time_limit(300);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);

function debugLog($m){ error_log(date('[Y-m-d H:i:s] ')."PDF Generation: ".$m); }
debugLog("PDF generation started");

$conn = getDBConnection();
debugLog("Database connection established");

// --- Inputs -------------------------------------------------------------
$batch_id     = $_GET['batch_id'] ?? null;
$dispositions = null;
$scope        = $_GET['scope'] ?? '';
$product_code = $_GET['product_code'] ?? '';
$caller_id    = $_GET['caller_id'] ?? null;
$excluded_batches = isset($_GET['excluded_batches']) ? explode(',', $_GET['excluded_batches']) : [];

if (!$batch_id && !isset($_GET['disposition'])) {
    die("Error: No valid batch ID or disposition provided.");
}

$adminId = $_SESSION['admin_id'];

// Get caller name for PDF title if caller filter is applied
$callerName = null;
if ($caller_id) {
    $callerStmt = $conn->prepare("SELECT name FROM callers WHERE caller_id = ?");
    $callerStmt->bind_param("s", $caller_id);
    $callerStmt->execute();
    $callerResult = $callerStmt->get_result()->fetch_assoc();
    $callerName = $callerResult ? $callerResult['name'] : $caller_id;
    $callerStmt->close();
}

// --- File name / Title --------------------------------------------------
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle    = 'Calling Sheet';

if (isset($_GET['disposition'])) {
    $dispositions = is_array($_GET['disposition']) ? $_GET['disposition'] : [$_GET['disposition']];
    $dispNames = array_map(fn($d)=>preg_replace("/[^a-zA-Z0-9]/","",$d), $dispositions);
    
    $callerSuffix = $caller_id ? ('_' . preg_replace("/[^a-zA-Z0-9]/", "", $callerName)) : '';
    $callerTitleSuffix = $callerName ? " - Caller: " . htmlspecialchars($callerName) : "";

    switch ($scope) {
        case 'batch-wise':
            $pdfFileName = 'Batch_' . $batch_id . '_' . implode('_', $dispNames) . $callerSuffix . '.pdf';
            $pdfTitle    = "Calling Sheet for Batch $batch_id - Status: " . implode(', ', $dispositions) . $callerTitleSuffix;
            break;
        case 'all-batch':
            $pdfFileName = 'AllBatches_' . implode('_', $dispNames) . $callerSuffix . '.pdf';
            $pdfTitle    = "Calling Sheet for All Batches - Status: " . implode(', ', $dispositions) . $callerTitleSuffix;
            break;
        case 'product-wise':
            $pdfFileName = 'Product_' . $product_code . '_' . implode('_', $dispNames) . $callerSuffix . '.pdf';
            $pdfTitle    = "Calling Sheet for Product $product_code - Status: " . implode(', ', $dispositions) . $callerTitleSuffix;
            break;
        case 'all-product':
            $pdfFileName = 'AllProducts_' . implode('_', $dispNames) . $callerSuffix . '.pdf';
            $pdfTitle    = "Calling Sheet for All Products - Status: " . implode(', ', $dispositions) . $callerTitleSuffix;
            break;
        default:
            $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
            $pdfFileName = ucwords($safeDispositionName) . '_Sheet' . $callerSuffix . '.pdf';
            $pdfTitle    = "Calling Sheet for Status: " . implode(', ', $dispositions) . $callerTitleSuffix;
    }
} elseif ($batch_id) {
    $callerSuffix = $caller_id ? ('_' . preg_replace("/[^a-zA-Z0-9]/", "", $callerName)) : '';
    $callerTitleSuffix = $callerName ? " - Caller: " . htmlspecialchars($callerName) : "";
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet' . $callerSuffix . '.pdf';
    $pdfTitle    = "Calling Sheet for Batch " . htmlspecialchars($batch_id) . $callerTitleSuffix;
}

// --- Disposition Codes & Legends ---------------------------------------
$dispResult = $conn->query("SELECT code, description, category FROM disposition_codes WHERE is_active = 1 ORDER BY category, CAST(code AS UNSIGNED), code");
$dispositionList = [];
$dispLegendY = []; $dispLegendN = [];
while ($d = $dispResult->fetch_assoc()) {
    $dispositionList[] = $d;
    if ($d['category'] === 'connected') $dispLegendY[] = "{$d['code']}:{$d['description']}";
    else $dispLegendN[] = "{$d['code']}:{$d['description']}";
}
$dispLegend = '';
if (!empty($dispLegendY)) $dispLegend .= 'DISPO (Y): ' . implode(' | ', $dispLegendY);
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= 'DISPO (N): ' . implode(' | ', $dispLegendN);
}
$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Query Build --------------------------------------------------------
$baseSql = "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id ";
$whereClauses = ["fb.admin_id = ?"];
$params = [$adminId]; $types = 's';

switch ($scope) {
    case 'batch-wise':
        if ($batch_id) { $whereClauses[] = "fcl.batch_id = ?"; $params[] = $batch_id; $types .= 's'; }
        break;
    case 'product-wise':
        if ($product_code) { $whereClauses[] = "fb.product_code = ?"; $params[] = $product_code; $types .= 's'; }
        break;
    default:
        if ($batch_id) { $whereClauses[] = "fcl.batch_id = ?"; $params[] = $batch_id; $types .= 's'; }
}

// Add caller filter
if ($caller_id) {
    $whereClauses[] = "fcl.caller_id = ?";
    $params[] = $caller_id;
    $types .= 's';
}

// Add disposition filter
if ($dispositions && !empty($dispositions)) {
    $placeholders = implode(',', array_fill(0, count($dispositions), '?'));
    $whereClauses[] = "fcl.disposition IN ($placeholders)";
    $params = array_merge($params, $dispositions);
    $types .= str_repeat('s', count($dispositions));
}

// Exclude batches that have reached download limits
if (!empty($excluded_batches)) {
    $excludePlaceholders = implode(',', array_fill(0, count($excluded_batches), '?'));
    $whereClauses[] = "fcl.batch_id NOT IN ($excludePlaceholders)";
    $params = array_merge($params, $excluded_batches);
    $types .= str_repeat('s', count($excluded_batches));
    
    debugLog("Excluding " . count($excluded_batches) . " batches from download: " . implode(', ', $excluded_batches));
}
if (count($whereClauses) <= 1 && !$batch_id && !$dispositions) {
    die("Error: No criteria selected for PDF generation.");
}
$whereSql    = 'WHERE ' . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// --- Count --------------------------------------------------------------
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
if ($totalRecords == 0) die("No records found matching the criteria.");

// --- Detect optional columns -------------------------------------------
$optionalColumns = ['title','name','policy_number','pan','dob','age','expiry','address','city','state','country','pincode','plan','premium','sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN fcl.`{$column}` IS NOT NULL AND fcl.`{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}
$presenceCheckSql = 'SELECT ' . implode(', ', $selects) . ' ' . $fullBaseSql;
$stmt = $conn->prepare($presenceCheckSql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$columnPresence = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Final headers ------------------------------------------------------
$finalHeaders = ['id','slot','connectivity','disposition','mobile_no'];
if (!empty($columnPresence['has_title'])) $finalHeaders[] = 'title';
if (!empty($columnPresence['has_name']))  $finalHeaders[] = 'name';

$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if (!in_array($column, $finalHeaders) && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column; $addedCount++;
    }
}

// --- PDF Class (center cutline + scissors using core font) -------------
class CompletePDF extends TCPDF {
    private $pdfTitle; private $slotLegend; private $dispLegend;
    private $cutlineCenter = null;

    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title; $this->slotLegend = $slotLegend; $this->dispLegend = $dispLegend;
    }
    public function setCutlines($before, $after) { $this->cutlineCenter = ($before + $after) / 2.0; }

    public function Header() {
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(0, 6, $this->pdfTitle, 0, 1, 'C');
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 4, $this->slotLegend, 0, 1, 'C');
        $lines = strlen($this->dispLegend) > 140 ? explode(' || ', $this->dispLegend) : [$this->dispLegend];
        foreach ($lines as $line) { $this->Cell(0, 3, $line, 0, 1, 'C'); }
        $this->Ln(2);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica','I',8);
        $this->Cell(0,10,'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(),0,0,'R');
    }
    // --- inside class CompletePDF ---
// --- inside class CompletePDF ---
public function drawPageCutlines() {
    if ($this->cutlineCenter === null) return;

    // 1) Remember cursor so drawing doesn't move the table
    $curX = $this->GetX();
    $curY = $this->GetY();

    // 2) Compute limits relative to the HEADER TOP (table header starts at top margin)
    $margins     = $this->getMargins();
    $headerTop   = $margins['top'];

    // Put the line slightly ABOVE the header so scissors can sit cleanly above it
    $topLimit    = max(10, $headerTop - 6);      // line starts ~6mm above the header
    $bottomLimit = $this->getPageHeight() - 10;  // keep clear of footer

    // 3) Dashed vertical line through center of Mobile column
    $this->SetLineStyle(['width'=>0.2,'dash'=>'2,2','color'=>[180,180,180]]);
    $this->Line($this->cutlineCenter, $topLimit, $this->cutlineCenter, $bottomLimit);

    // 4) Scissors icons (ZapfDingbats core font)
    $this->SetFont('zapfdingbats','',12);
    $xGlyph = $this->cutlineCenter - 2.2;

    // TOP scissors: ~8mm above the header row (adjust if you want closer/farther)
    $yTop = max(10, $headerTop - 8);
    $this->Text($xGlyph, $yTop, chr(34));   // 34 = ✂

    // BOTTOM scissors: rotated 180° so blades face the cut
    $yBottom = $bottomLimit - 12;           // keep above footer area
    $this->StartTransform();
    $this->Rotate(180, $this->cutlineCenter, $yBottom + 6);
    $this->Text($xGlyph, $yBottom, chr(34));
    $this->StopTransform();

    // 5) Reset style & restore cursor
    $this->SetLineStyle(['width'=>0.2,'dash'=>0,'color'=>[0,0,0]]);
    $this->SetXY($curX, $curY);
}
}

// --- Column widths & centering -----------------------------------------
$columnData  = [];
$totalWidth  = 275; // target content width (mm)
$widthMap = [
    'id'=>22,'slot'=>12,'connectivity'=>18,'disposition'=>42,
    'mobile_no'=>25,'title'=>12,'name'=>45,'policy_number'=>25,
    'pan'=>20,'dob'=>20,'age'=>12,'expiry'=>20,
    'address'=>50,'city'=>25,'state'=>25,'country'=>18,
    'pincode'=>16,'plan'=>22,'premium'=>18,'sum_insured'=>22
];
foreach ($finalHeaders as $h) {
    $w = $widthMap[$h] ?? 20;
    $label = ($h==='id') ? 'ID' : (($h==='mobile_no') ? 'Mobile' : ucwords(str_replace('_',' ',$h)));
    $columnData[] = ['header'=>$label,'width'=>$w,'key'=>$h];
}

$pdf = new CompletePDF($pdfTitle, $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);

// Fit to total width if needed
$currentTotal = array_sum(array_column($columnData, 'width'));
if ($currentTotal > $totalWidth) {
    $scale = $totalWidth / $currentTotal;
    foreach ($columnData as &$col) { $col['width'] = round($col['width'] * $scale, 2); }
    unset($col);
    $currentTotal = array_sum(array_column($columnData, 'width'));
}

$pageWidth  = $pdf->getPageWidth();
$leftMargin = ($pageWidth - $currentTotal) / 2;
$pdf->SetMargins($leftMargin, 35, $leftMargin);
$pdf->SetAutoPageBreak(true, 15);

$margins = $pdf->getMargins(); // ['left','top','right']

// compute cutline center from Mobile column edges
$mobileIndex = array_search('mobile_no', array_column($columnData, 'key'));
if ($mobileIndex !== false) {
    $cutlineBefore = $leftMargin;
    for ($i=0; $i<$mobileIndex; $i++) $cutlineBefore += $columnData[$i]['width'];
    $cutlineAfter  = $cutlineBefore + $columnData[$mobileIndex]['width'];
    $pdf->setCutlines($cutlineBefore, $cutlineAfter);
}

$pdf->AddPage();
$pdf->SetXY($margins['left'], $margins['top']); // hard reset at start

// --- Helpers ------------------------------------------------------------

function redrawHeaders($pdf, $columnData) {
    $pdf->drawPageCutlines();

    $m = $pdf->getMargins();
    // Hard-set Y to the top margin for the header row
    $pdf->SetY($m['top']);
    $pdf->SetX($m['left']);

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240,240,240);
    foreach ($columnData as $col) {
        $pdf->Cell($col['width'], 7, $col['header'], 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetX($m['left']);
}


function formatDateForPDF($d){
    if (empty($d) || $d==='0000-00-00') return '';
    $ts = strtotime($d);
    return $ts!==false ? date('d-m-Y',$ts) : $d;
}

// Draw initial headers
redrawHeaders($pdf, $columnData);

// Disposition grid
$dispGrid = '';
$gridRows = array_chunk($dispositionList, 5);
foreach ($gridRows as $row) {
    $dispGrid .= implode('  ', array_map(fn($x)=>'O '.$x['code'], $row)) . "\n";
}
$dispGrid = trim($dispGrid);

debugLog("Found $totalRecords total records");

// --- Data streaming -----------------------------------------------------
$finalHeadersWithAlias = 'fcl.`' . implode('`, fcl.`', $finalHeaders) . '`';
$chunkSize=500; $offset=0; $processed=0; $maxRows=min($totalRecords,10000); $memCheckEvery=100;

while ($offset < $totalRecords && $processed < $maxRows) {
    $currentChunk = min($chunkSize, $totalRecords - $offset, $maxRows - $processed);
    $sql = "SELECT {$finalHeadersWithAlias} " . $fullBaseSql . " ORDER BY fcl.id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    $chunkParams = array_merge($params, [$offset, $currentChunk]);
    $chunkTypes  = $types . 'ii';
    if ($chunkTypes) $stmt->bind_param($chunkTypes, ...$chunkParams);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) { $stmt->close(); break; }

    $rowsInChunk = 0;
    while ($row = $result->fetch_assoc()) {
        if ($rowsInChunk % $memCheckEvery === 0) {
            $mem = memory_get_usage(true) / 1048576;
            if ($mem > 800) { debugLog("High memory usage: {$mem}MB. Aborting."); break 2; }
        }

        // Measure row height
        $maxHeight = 6;
        $cellContents = [];
        foreach ($columnData as $col) {
            $k = $col['key'];
            switch ($k) {
                case 'disposition': $content = $dispGrid; $font=6; break;
                case 'connectivity': $content = 'O Y / O N'; $font=7; break;
                case 'dob':
                case 'expiry': $content = formatDateForPDF($row[$k] ?? ''); $font=7; break;
                default: $content = $row[$k] ?? ''; $font = ($k==='address'?6:7);
            }
            $cellContents[$k]=[$content,$font];
            if ($k === 'id') $h = 6; // single line fixed height for ID
            else { $pdf->SetFont('helvetica','', $font); $h = $pdf->getStringHeight($col['width'], (string)$content); }
            if ($h > $maxHeight) $maxHeight = $h;
        }

        // Page break pre-check
        if ($pdf->GetY() + $maxHeight > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
            $pdf->AddPage();
            redrawHeaders($pdf, $columnData);
        }

        // Draw row
        $m = $pdf->getMargins();
        $pdf->SetX($m['left']);
        foreach ($columnData as $col) {
            $k = $col['key']; [$content,$font] = $cellContents[$k];
            $align='L'; $bold=false;
            switch ($k) {
                case 'mobile_no': $align='C'; $bold=true; break;
                case 'id':
                case 'dob':
                case 'expiry':
                case 'slot': $align='C'; break;
                case 'connectivity': $align='C'; break;
                case 'disposition': $font=6; break;
                case 'address': $font=6; break;
            }
            $pdf->SetFont('helvetica', $bold?'B':'', $font);
            if ($k === 'id') {
                // stretch=1 shrinks-to-fit horizontally to keep one line
                $pdf->Cell($col['width'], $maxHeight, (string)$content, 1, 0, 'C', false, '', 1, false, 'T', 'M');
            } else {
                $pdf->MultiCell($col['width'], $maxHeight, (string)$content, 1, $align, false, 0, '', '', true, 0, false, true, $maxHeight, 'M');
            }
        }
        $pdf->Ln($maxHeight);
        $pdf->SetX($m['left']);

        $processed++; $rowsInChunk++;
    }

    $stmt->close();
    $offset += $currentChunk;
    if ($processed % 1000 === 0) { gc_collect_cycles(); debugLog("Processed $processed / $totalRecords"); }
}

// --- Output -------------------------------------------------------------
try {
    // Clean ANY buffered output (BOM/whitespace/notices) before headers
    if (ob_get_length()) { @ob_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$pdfFileName.'"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $pdf->Output($pdfFileName, 'D');
    exit;
} catch (Exception $e) {
    debugLog('Error during PDF output: ' . $e->getMessage());
    if (ob_get_length()) { @ob_end_clean(); }
    header('Content-Type: text/html', true, 500);
    echo '<!DOCTYPE html><html><head><title>PDF Generation Error</title></head><body>';
    echo '<h2>PDF Generation Error</h2><p>An error occurred while generating the PDF. Please try again or select fewer records.</p>';
    echo '<button onclick="history.back()">Go Back</button></body></html>';
} finally {
    if (isset($conn)) $conn->close();
}
