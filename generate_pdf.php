<?php
// This is a dedicated script for generating large PDFs without timing out.
require 'vendor/autoload.php';

use Mpdf\Mpdf;

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// Essential for long-running scripts
set_time_limit(0);
// Attempt to increase memory limit for this specific script
ini_set('memory_limit', '1024M');


if (!isset($_GET['batch_id']) || !is_numeric($_GET['batch_id'])) {
    die("Error: No valid batch ID provided.");
}
$batch_id = (int)$_GET['batch_id'];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Prepare headers for the PDF
$pdfHeaders = ['mobile_no', 'slot', 'connectivity', 'disposition', 'name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
$colCount = count($pdfHeaders);

// Prepare mPDF instance
$mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => __DIR__ . '/tmp']);
$mpdf->SetDisplayMode('fullpage');
$mpdf->shrink_tables_to_fit = 1;

// Prepare HTML structure and CSS
$slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
$dispLegend = "<strong>DISPO CODES (Y):</strong> 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up | 15:More Info | 16:Language Barrier | 17:Drop || <strong>(N):</strong> 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service | 25:Wrong Number | 26:Busy";
$html_head = '<html><head><style>body { font-family: sans-serif; font-size: 7.5pt; } table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; } thead { display: table-header-group; } tr { page-break-inside: avoid; page-break-after: auto; } th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; } thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; } .anchor-col { font-weight: bold; color: #333; font-family: monospace; } .connectivity-col, .slot-cell { text-align: center; } .disposition-cell { font-size: 7pt; padding: 1px !important; } .dispo-grid { border: none !important; width: 100%; table-layout: fixed; } .dispo-grid td { border: none !important; padding: 1px 2px; text-align: left; }</style></head><body>';
$tableHeader = '<thead><tr><th class="legend-cell" colspan="'.$colCount.'">' . $slotLegend . '</th></tr><tr><th class="legend-cell" colspan="'.$colCount.'">' . $dispLegend . '</th></tr><tr>';
foreach($pdfHeaders as $header) { $tableHeader .= '<th>' . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>'; }
$tableHeader .= '</tr></thead>';

// Start writing the PDF document
$mpdf->WriteHTML($html_head);

// *** THE CORE OPTIMIZATION: PROCESS IN CHUNKS ***
$chunkSize = 500; // Process 500 records at a time
$offset = 0;

while (true) {
    // Fetch a chunk of data from the database
    $stmt = $conn->prepare("SELECT * FROM final_call_logs WHERE batch_id = ? LIMIT ?, ?");
    $stmt->bind_param("iii", $batch_id, $offset, $chunkSize);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        break; // No more records, exit the loop
    }
    
    $chunkHtml = '<table class="data-table">' . $tableHeader . '<tbody>';
    while ($row = $result->fetch_assoc()) {
        $chunkHtml .= '<tr>';
        foreach ($pdfHeaders as $header) {
            // Add placeholders for caller-input fields
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

    // Write the HTML for the current chunk to the PDF
    $mpdf->WriteHTML($chunkHtml);

    $stmt->close();
    $offset += $chunkSize; // Move to the next chunk
}

// Finalize the PDF
$mpdf->WriteHTML('</body></html>');
$filename = 'Batch_' . $batch_id . '_Calling_Sheet_' . date('Y-m-d') . '.pdf';
$mpdf->Output($filename, 'D');

$conn->close();
exit;