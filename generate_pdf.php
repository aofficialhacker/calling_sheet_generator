<?php
require 'vendor/autoload.php';

use Mpdf\Mpdf;

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// Essential for long-running scripts
set_time_limit(0);
ini_set('memory_limit', '1024M');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// --- Determine Data Source and WHERE clause ---
$dataSourceWhereClause = '';
$pdfFileName = 'Calling_Sheet_' . date('Y-m-d') . '.pdf';

if (isset($_GET['batch_id']) && is_numeric($_GET['batch_id'])) {
    $batch_id = (int)$_GET['batch_id'];
    $dataSourceWhereClause = "WHERE batch_id = {$batch_id}";
    $pdfFileName = 'Batch_' . $batch_id . '_Calling_Sheet_' . date('Y-m-d') . '.pdf';
} elseif (isset($_GET['disposition']) && $_GET['disposition'] === 'follow_up') {
    $dataSourceWhereClause = "WHERE disposition = 'Follow Up'";
    $pdfFileName = 'Follow_Up_Calling_Sheet_' . date('Y-m-d') . '.pdf';
} else {
    die("Error: No valid batch ID or disposition provided.");
}

// --- Step 1: Dynamically Detect Which Columns Have Data ---
$mandatoryHeaders = ['mobile_no', 'slot', 'connectivity', 'disposition'];
$optionalColumns = ['name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];

$selects = [];
foreach ($optionalColumns as $column) {
    // Create a query part to check for the presence of data in each column
    $selects[] = "MAX(CASE WHEN `{$column}` IS NOT NULL AND `{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}
$presenceCheckSql = "SELECT " . implode(', ', $selects) . " FROM final_call_logs {$dataSourceWhereClause}";
$presenceResult = $conn->query($presenceCheckSql);
$columnPresence = $presenceResult->fetch_assoc();

// --- Step 2: Build the Final List of Headers for the PDF ---
$pdfHeaders = $mandatoryHeaders;
if ($columnPresence) {
    foreach ($optionalColumns as $column) {
        if ($columnPresence["has_{$column}"] == 1) {
            $pdfHeaders[] = $column; // Add column to PDF only if it has data
        }
    }
}

// Check if any data exists at all before proceeding
$countCheckSql = "SELECT COUNT(*) as total FROM final_call_logs {$dataSourceWhereClause}";
$countResult = $conn->query($countCheckSql)->fetch_assoc();
if ($countResult['total'] == 0) {
     die("No data found for the selected criteria. PDF cannot be generated.");
}

// --- Step 3: Generate the PDF with the Dynamic Headers ---
$colCount = count($pdfHeaders);
$mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => __DIR__ . '/tmp']);
$mpdf->SetDisplayMode('fullpage');
$mpdf->shrink_tables_to_fit = 1;

$slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
$dispLegend = "<strong>DISPO CODES (Y):</strong> 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up | 15:More Info | 16:Language Barrier | 17:Drop || <strong>(N):</strong> 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service | 25:Wrong Number | 26:Busy";
$html_head = '<html><head><style>body { font-family: sans-serif; font-size: 7.5pt; } table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; } thead { display: table-header-group; } tr { page-break-inside: avoid; page-break-after: auto; } th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; } thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; } .anchor-col { font-weight: bold; color: #333; font-family: monospace; } .connectivity-col, .slot-cell { text-align: center; } .disposition-cell { font-size: 7pt; padding: 1px !important; } .dispo-grid { border: none !important; width: 100%; table-layout: fixed; } .dispo-grid td { border: none !important; padding: 1px 2px; text-align: left; }</style></head><body>';
$tableHeader = '<thead><tr><th class="legend-cell" colspan="'.$colCount.'">' . $slotLegend . '</th></tr><tr><th class="legend-cell" colspan="'.$colCount.'">' . $dispLegend . '</th></tr><tr>';

// Use the dynamically generated headers
foreach($pdfHeaders as $header) { $tableHeader .= '<th>' . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>'; }
$tableHeader .= '</tr></thead>';

$mpdf->WriteHTML($html_head);

// --- Step 4: Process in Chunks Using the Dynamic Headers ---
$chunkSize = 500;
$offset = 0;
$columnsToSelect = implode(', ', $pdfHeaders);

while (true) {
    $stmt = $conn->prepare("SELECT {$columnsToSelect} FROM final_call_logs {$dataSourceWhereClause} LIMIT ?, ?");
    $stmt->bind_param("ii", $offset, $chunkSize);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        break;
    }
    
    $chunkHtml = '<table class="data-table">' . $tableHeader . '<tbody>';
    while ($row = $result->fetch_assoc()) {
        $chunkHtml .= '<tr>';
        // Loop through the dynamic headers to ensure order and inclusion
        foreach ($pdfHeaders as $header) {
            if ($header === 'disposition') {
                $chunkHtml .= '<td class="disposition-cell"><table class="dispo-grid"><tr><td>○ 11</td><td>○ 12</td><td>○ 13</td><td>○ 14</td><td>○ 15</td><td>○ 16</td><td>○ 17</td></tr><tr><td>○ 21</td><td>○ 22</td><td>○ 23</td><td>○ 24</td><td>○ 25</td><td>○ 26</td><td></td></tr></table></td>';
            } elseif ($header === 'connectivity') {
                 $chunkHtml .= '<td class="connectivity-col">○ Y / ○ N</td>';
            } elseif ($header === 'slot') {
                 $chunkHtml .= '<td class="slot-cell"></td>';
            } else {
                $cell = $row[$header] ?? '';
                $class = ($header === 'mobile_no') ? 'anchor-col' : '';
                $chunkHtml .= '<td class="'.$class.'">' . htmlspecialchars($cell) . '</td>';
            }
        }
        $chunkHtml .= '</tr>';
    }
    $chunkHtml .= '</tbody></table>';

    $mpdf->WriteHTML($chunkHtml);
    $stmt->close();
    $offset += $chunkSize;
}

$mpdf->WriteHTML('</body></html>');
$mpdf->Output($pdfFileName, 'D');

$conn->close();
exit;