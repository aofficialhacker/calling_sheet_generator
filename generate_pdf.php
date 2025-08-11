<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

use Mpdf\Mpdf;
use Mpdf\HTMLParserMode;

// --- Server-Side Download Token ---
if (isset($_GET['download_token'])) {
    $token = preg_replace('/[^0-9]/', '', $_GET['download_token']);
    if (!empty($token)) {
        setcookie("download_token_" . $token, "true", time() + 30, "/");
    }
}

set_time_limit(0);
ini_set('memory_limit', '2048M');

$conn = getDBConnection();

// --- Determine Data Source ---
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle = 'Calling Sheet';
$batch_id = null;
$dispositions = null;

if (isset($_GET['batch_id'])) {
    $batch_id = $_GET['batch_id'];
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
} elseif (isset($_GET['disposition'])) {
    $dispositions = explode(',', $_GET['disposition']);
    $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
    $pdfFileName = ucwords($safeDispositionName) . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Status: " . htmlspecialchars(implode(', ', $dispositions));
} else {
    die("Error: No valid batch ID or disposition provided.");
}

// --- OPTIMIZATION: Cache disposition codes ---
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

// --- Build WHERE clause ---
$baseSql = "FROM final_call_logs ";
$whereClauses = [];
$params = [];
$types = '';

if ($batch_id) {
    $whereClauses[] = "batch_id = ?";
    $params[] = $batch_id;
    $types .= 's';
}
if ($dispositions) {
    $placeholders = implode(',', array_fill(0, count($dispositions), '?'));
    $whereClauses[] = "disposition IN ($placeholders)";
    $params = array_merge($params, $dispositions);
    $types .= str_repeat('s', count($dispositions));
}

if (empty($whereClauses)) {
    die("Error: No criteria selected for PDF generation.");
}
$whereSql = "WHERE " . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// --- OPTIMIZATION: Get count more efficiently ---
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// --- OPTIMIZATION: Simplified column detection using LIMIT 1 ---
$optionalColumns = ['title','name', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];

// Get a sample row to check which columns have data
$sampleSql = "SELECT * " . $fullBaseSql . " LIMIT 100";
$stmt = $conn->prepare($sampleSql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$sampleResult = $stmt->get_result();
$columnPresence = [];
while ($row = $sampleResult->fetch_assoc()) {
    foreach ($optionalColumns as $col) {
        if (!isset($columnPresence["has_$col"]) && !empty($row[$col])) {
            $columnPresence["has_$col"] = 1;
        }
    }
}
$stmt->close();

// Build final headers
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

// Add name if it has data
if (!empty($columnPresence["has_title"]) || !empty($columnPresence["has_name"])) {
    if (!empty($columnPresence["has_title"])) {
        $finalHeaders[] = 'title';
    }
    $finalHeaders[] = 'name';
}

// Add remaining columns limiting to 12 total
$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if ($column !== 'title' && $column !== 'name' && !empty($columnPresence["has_$column"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

// --- OPTIMIZATION: Pre-generate static HTML components ---
// Create disposition grid HTML once
$dispoGridHtml = '<table class="dispo-grid"><tr>';
$gridCols = 0;
$maxCols = 4;
foreach ($dispositionList as $index => $disp) {
    if ($gridCols >= $maxCols) {
        $dispoGridHtml .= '</tr><tr>';
        $gridCols = 0;
    }
    $dispoGridHtml .= '<td>○ ' . htmlspecialchars($disp['code']) . '</td>';
    $gridCols++;
}
while ($gridCols < $maxCols) {
    $dispoGridHtml .= '<td></td>';
    $gridCols++;
}
$dispoGridHtml .= '</tr></table>';

// --- OPTIMIZATION: Enhanced mPDF configuration ---
$colCount = count($finalHeaders);
$mpdf = new Mpdf([
    'mode' => 'utf-8', 
    'format' => 'A4-L', 
    'tempDir' => __DIR__ . '/tmp',
    'simpleTables' => true,
    'packTableData' => true,
    'useSubstitutions' => false,  // Disable font substitution for speed
    'autoScriptToLang' => false,   // Disable auto script detection
    'autoLangToFont' => false,     // Disable auto language to font
    'allow_output_buffering' => true,
    'shrink_tables_to_fit' => 0,  // Disable table shrinking calculations
    'use_kwt' => false,            // Disable keep-with-table
    'biDirectional' => false,      // Disable bidirectional text if not needed
]);

$mpdf->SetDisplayMode('fullpage');
$mpdf->SetTitle($pdfTitle);

// --- CSS optimized for performance ---
$css = '
<style>
    @page { size: A4-L; margin: 10mm; }
    body { font-family: sans-serif; font-size: 7pt; margin: 0; padding: 0; }
    table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #333; padding: 2px 3px; text-align: left; vertical-align: middle; }
    thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; }
    .id-col { font-size: 6pt; font-family: monospace; }
    .mobile-col { font-weight: bold; font-family: monospace; }
    .connectivity-col, .slot-cell { text-align: center; }
    .disposition-cell { font-size: 6.5pt; padding: 1px !important; }
    .dispo-grid { border: none !important; width: 100%; }
    .dispo-grid td { border: none !important; padding: 0px 1px; text-align: left; font-size: 6.5pt; }
    
    /* Cutline styles */
    .cutline {
        position: fixed;
        top: 0;
        left: 79.8mm;
        width: 0.4mm;
        height: 100%;
        background: repeating-linear-gradient(to bottom, #555 0 6px, transparent 6px 12px);
        z-index: 10;
    }
    .scissor {
        position: fixed;
        font-family: DejaVu Sans, sans-serif;
        font-size: 12pt;
        z-index: 11;
    }
    .scissor.top { top: 10mm; left: 77mm; }
    .scissor.middle { top: 105mm; left: 77mm; }
    .scissor.bottom { bottom: 10mm; left: 77mm; }
</style>';

// Write CSS once
$mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

// Add cutline
$cutlineHtml = '
<div class="cutline"></div>
<div class="scissor top">&#9986;</div>
<div class="scissor middle">&#9986;</div>
<div class="scissor bottom">&#9986;</div>';
$mpdf->WriteHTML($cutlineHtml, \Mpdf\HTMLParserMode::HTML_BODY);

// Set footer
$mpdf->SetHTMLFooter('<div style="text-align: right; font-size: 8pt;">Page {PAGENO} of {nbpg}</div>');

// --- Create table header ---
$tableHeader = '<table class="data-table">
<thead>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $pdfTitle . '</th></tr>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $slotLegend . '</th></tr>';

if (!empty($dispLegend)) {
    $tableHeader .= '<tr><th class="legend-cell" colspan="' . $colCount . '">' . $dispLegend . '</th></tr>';
}

$tableHeader .= '<tr>';
foreach ($finalHeaders as $header) {
    $displayHeader = str_replace('_', ' ', ucwords($header));
    if ($header === 'mobile_no') $displayHeader = 'Mobile';
    $headerClass = '';
    if ($header === 'id') $headerClass = ' class="id-col"';
    elseif ($header === 'mobile_no') $headerClass = ' class="mobile-col"';
    $tableHeader .= '<th' . $headerClass . '>' . htmlspecialchars($displayHeader) . '</th>';
}
$tableHeader .= '</tr></thead><tbody>';

// --- OPTIMIZATION: Process data in larger chunks with streaming ---
$chunkSize = 2000; // Increased chunk size
$offset = 0;
$columnsToSelect = '`' . implode('`, `', $finalHeaders) . '`';
$rowBuffer = '';
$bufferSize = 0;
$maxBufferSize = 100; // Rows to buffer before writing

// Function to format date
function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    $timestamp = strtotime($dateValue);
    return $timestamp !== false ? date('d-m-Y', $timestamp) : $dateValue;
}

// --- OPTIMIZATION: Pre-compute static cell values ---
$staticCells = [
    'connectivity' => '○ Y / ○ N',
    'disposition' => $dispoGridHtml,
    'slot' => ''
];

// Start the table
$mpdf->WriteHTML($tableHeader);

// Process data
while ($offset < $totalRecords) {
    $sql = "SELECT {$columnsToSelect} " . $fullBaseSql . " ORDER BY id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        die("Error preparing statement: " . $conn->error);
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

    // --- OPTIMIZATION: Use fetch_all for batch processing ---
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as $row) {
        $rowBuffer .= '<tr>';
        foreach ($finalHeaders as $header) {
            $class = '';
            $content = '';
            
            // Use pre-computed static values
            if (isset($staticCells[$header])) {
                $content = $staticCells[$header];
                $class = $header === 'disposition' ? 'disposition-cell' : 
                        ($header === 'connectivity' ? 'connectivity-col' : 'slot-cell');
            } else {
                switch($header) {
                    case 'mobile_no':
                        $content = htmlspecialchars($row[$header] ?? '');
                        $class = 'mobile-col';
                        break;
                    case 'id':
                        $content = htmlspecialchars($row[$header] ?? '');
                        $class = 'id-col';
                        break;
                    case 'dob':
                    case 'expiry':
                        $content = htmlspecialchars(formatDateForPDF($row[$header] ?? ''));
                        break;
                    default:
                        $content = htmlspecialchars($row[$header] ?? '');
                        break;
                }
            }
            $rowBuffer .= '<td' . ($class ? ' class="' . $class . '"' : '') . '>' . $content . '</td>';
        }
        $rowBuffer .= '</tr>';
        $bufferSize++;
        
        // Write buffer when it reaches max size
        if ($bufferSize >= $maxBufferSize) {
            $mpdf->WriteHTML($rowBuffer);
            $rowBuffer = '';
            $bufferSize = 0;
        }
    }
    
    $offset += $chunkSize;
    
    // Optional: Add progress indicator for very large files
    if ($totalRecords > 10000 && $offset % 10000 == 0) {
        error_log("PDF Generation Progress: " . round(($offset / $totalRecords) * 100) . "%");
    }
}

// Write any remaining buffer
if (!empty($rowBuffer)) {
    $mpdf->WriteHTML($rowBuffer);
}

// Close table
$mpdf->WriteHTML('</tbody></table>');

// Output PDF
$mpdf->Output($pdfFileName, 'D');

$conn->close();
exit;