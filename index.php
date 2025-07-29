<?php
// Start the session to store processed data for download links.
session_start();

// Require the Composer autoloader
require 'vendor/autoload.php';

// Use the necessary classes from external libraries
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Mpdf\Mpdf;

// --- DATABASE CONFIGURATION ---
// Replace with your actual database credentials.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

/**
 * Generates an Excel file from session data with legends, borders, and correct formatting.
 */
function downloadExcel() {
    if (!isset($_SESSION['processed_data']) || !isset($_SESSION['output_headers'])) {
        die("No data available to download. Please upload a file first.");
    }
    $processedData = $_SESSION['processed_data'];
    $outputHeaders = $_SESSION['output_headers'];
    $colCount = count($outputHeaders);
    $slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
    $dispLegend = "DISPO CODES (Y): 11:Int | 12:Not Int | 13:CB | 14:FU | 15:Info | 16:Lang | 17:Drop || (N): 21:Ring | 22:Off | 23:Invalid | 24:OOS | 25:Wrong# | 26:Busy";
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
    $lastRow = count($processedData) + 3;
    $sheet->mergeCells('A1:' . $highestColumn . '1')->setCellValue('A1', $slotLegend);
    $sheet->mergeCells('A2:' . $highestColumn . '2')->setCellValue('A2', $dispLegend);
    $sheet->getStyle('A1:A2')->applyFromArray(['font' => ['bold' => true],'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
    $sheet->getRowDimension('1')->setRowHeight(20);
    $sheet->getRowDimension('2')->setRowHeight(20);
    $headerContent = array_map(fn($h) => str_replace('_', ' ', ucwords($h)), $outputHeaders);
    $sheet->fromArray($headerContent, null, 'A3');
    $sheet->getStyle('A3:' . $highestColumn . '3')->applyFromArray(['font' => ['bold' => true],'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
    $forceTextColumns = ['policy_number', 'pan', 'mobile_no'];
    $currentRow = 4;
    foreach ($processedData as $dataRow) {
        $currentCol = 1;
        foreach ($outputHeaders as $header) {
            $cellValue = $dataRow[$header] ?? '';
            if ($header === 'disposition') { $cellValue = str_replace('|', "\n", $cellValue); }
            if (in_array($header, $forceTextColumns)) {
                $sheet->setCellValueExplicitByColumnAndRow($currentCol, $currentRow, $cellValue, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValueByColumnAndRow($currentCol, $currentRow, $cellValue);
            }
            $currentCol++;
        }
        $currentRow++;
    }
    $dispoColumnIndex = array_search('disposition', $outputHeaders);
    if ($dispoColumnIndex !== false) {
        $dispoColumnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dispoColumnIndex + 1);
        $sheet->getStyle($dispoColumnLetter . '4:' . $dispoColumnLetter . $lastRow)->getAlignment()->setWrapText(true);
    }
    $borderStyle = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]]];
    $sheet->getStyle('A1:' . $highestColumn . $lastRow)->applyFromArray($borderStyle);
    foreach (range('A', $highestColumn) as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    $outputFilename = 'Standard_Calling_Sheet_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $outputFilename . '"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Generates a PDF file from session data.
 */
function downloadPdf() {
    if (!isset($_SESSION['processed_data']) || !isset($_SESSION['output_headers'])) { die("No data available to download. Please upload a file first."); }
    $processedData = $_SESSION['processed_data'];
    $outputHeaders = $_SESSION['output_headers'];
    $colCount = count($outputHeaders);
    $slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
    $dispLegend = "<strong>DISPO CODES (Y):</strong> 11:Int | 12:Not Int | 13:CB | 14:FU | 15:Info | 16:Lang | 17:Drop || <strong>(N):</strong> 21:Ring | 22:Off | 23:Invalid | 24:OOS | 25:Wrong# | 26:Busy";
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L']);
    $mpdf->SetDisplayMode('fullpage');
    $mpdf->shrink_tables_to_fit = 1;
    $html = '<html><head><style>body { font-family: sans-serif; font-size: 7.5pt; } table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; } thead { display: table-header-group; } tr { page-break-inside: avoid; page-break-after: auto; } th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; } thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; } .disposition-cell { font-size: 7pt; text-align: center; } .connectivity-cell, .slot-cell { text-align: center; }</style></head><body>';
    $tableHeader = '<thead>';
    $tableHeader .= '<tr><th class="legend-cell" colspan="'.$colCount.'">' . $slotLegend . '</th></tr>';
    $tableHeader .= '<tr><th class="legend-cell" colspan="'.$colCount.'">' . $dispLegend . '</th></tr>';
    $tableHeader .= '<tr>';
    foreach($outputHeaders as $header) { $tableHeader .= '<th>' . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>'; }
    $tableHeader .= '</tr></thead>';
    $html .= '<table class="data-table">' . $tableHeader . '<tbody>';
    foreach ($processedData as $dataRow) {
        $html .= '<tr>';
        foreach ($outputHeaders as $header) {
             $cell = $dataRow[$header] ?? '';
             $class = '';
             if ($header === 'slot') $class = 'slot-cell';
             if ($header === 'connectivity') $class = 'connectivity-cell';
             if ($header === 'disposition') $class = 'disposition-cell';
             $cellContent = ($header === 'disposition') ? str_replace('|', '<br>', htmlspecialchars($cell)) : htmlspecialchars($cell);
             $html .= '<td class="'.$class.'">' . $cellContent . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';
    $mpdf->WriteHTML($html);
    $mpdf->Output('Standard_Calling_Sheet_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}

/**
 * Maps various input header names to a standard set of keys.
 */
function mapColumns(array $headerRow): array {
    $map = ['title' => -1, 'name' => -1, 'mobile_no' => -1, 'policy_number' => -1, 'pan' => -1, 'dob' => -1, 'expiry' => -1, 'address' => -1, 'address2' => -1, 'address3' => -1, 'city' => -1, 'state' => -1, 'country' => -1, 'pincode' => -1, 'plan' => -1, 'premium' => -1, 'sum_insured' => -1];
    foreach ($headerRow as $index => $header) {
        $normalizedHeader = strtolower(trim(str_replace(['_', ' '], '', $header)));
        if (empty($normalizedHeader)) continue;
        switch (true) {
            case ($map['title'] === -1 && preg_match('/^title$/i', $normalizedHeader)): $map['title'] = $index; break;
            case ($map['name'] === -1 && preg_match('/name|insured/i', $normalizedHeader)): $map['name'] = $index; break;
            case ($map['mobile_no'] === -1 && preg_match('/mobile|phone|cell/i', $normalizedHeader)): $map['mobile_no'] = $index; break;
            case ($map['policy_number'] === -1 && preg_match('/policy(number)?/i', $normalizedHeader)): $map['policy_number'] = $index; break;
            case ($map['pan'] === -1 && preg_match('/pan/i', $normalizedHeader)): $map['pan'] = $index; break;
            case ($map['dob'] === -1 && preg_match('/dob|birth/i', $normalizedHeader)): $map['dob'] = $index; break;
            case ($map['expiry'] === -1 && preg_match('/expiry/i', $normalizedHeader)): $map['expiry'] = $index; break;
            case ($map['address'] === -1 && preg_match('/(cadd1|address|add\b)/i', $normalizedHeader)): $map['address'] = $index; break;
            case ($map['address2'] === -1 && preg_match('/cadd2/i', $normalizedHeader)): $map['address2'] = $index; break;
            case ($map['address3'] === -1 && preg_match('/cadd3/i', $normalizedHeader)): $map['address3'] = $index; break;
            case ($map['city'] === -1 && preg_match('/city|ccity/i', $normalizedHeader)): $map['city'] = $index; break;
            case ($map['state'] === -1 && preg_match('/state|cstate/i', $normalizedHeader)): $map['state'] = $index; break;
            case ($map['country'] === -1 && preg_match('/country|ccntry/i', $normalizedHeader)): $map['country'] = $index; break;
            case ($map['pincode'] === -1 && preg_match('/pin|pincode|cpin/i', $normalizedHeader)): $map['pincode'] = $index; break;
            case ($map['plan'] === -1 && preg_match('/plan(name)?/i', $normalizedHeader)): $map['plan'] = $index; break;
            case ($map['premium'] === -1 && preg_match('/premium/i', $normalizedHeader)): $map['premium'] = $index; break;
            case ($map['sum_insured'] === -1 && preg_match('/sum(insured)?/i', $normalizedHeader)): $map['sum_insured'] = $index; break;
        }
    }
    return $map;
}

// --- LOGIC CONTROLLER ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'download_pdf') downloadPdf();
    if ($_GET['action'] == 'download_excel') downloadExcel();
}

// Handle File Upload for Sheet Generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['customerFile'])) {
    unset($_SESSION['processed_data'], $_SESSION['output_headers']);
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $originalFileName = basename($_FILES['customerFile']['name']);
    $originalFile = $uploadDir . $originalFileName;
    if (move_uploaded_file($_FILES['customerFile']['tmp_name'], $originalFile)) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($originalFile);
            $spreadsheet = $reader->load($originalFile);
            $dataRows = $spreadsheet->getActiveSheet()->toArray();
            $headerRow = array_shift($dataRows);
            $columnMap = mapColumns($headerRow);
            $standardKeys = array_keys($columnMap);
            $foundStandardKeys = []; $extraHeadersInfo = []; $mappedIndexes = [];
            foreach ($columnMap as $key => $index) {
                if ($index !== -1) { $foundStandardKeys[$key] = $key; if (!in_array($index, $mappedIndexes)) $mappedIndexes[] = $index; }
            }
            foreach ($headerRow as $index => $header) {
                if (!in_array($index, $mappedIndexes)) {
                    $sanitizedHeader = strtolower(str_replace(' ', '_', preg_replace('/[^A-Za-z0-9 ]/', '', trim($header))));
                    if (!empty($sanitizedHeader)) { $extraHeadersInfo[$index] = $sanitizedHeader; }
                }
            }
            $outputHeaders = ['slot'];
            $standardOrder = ['title', 'name', 'mobile_no', 'connectivity', 'disposition', 'policy_number', 'pan', 'dob', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
            foreach($standardOrder as $key) { if(isset($foundStandardKeys[$key])) { $outputHeaders[] = $key; } }
            $outputHeaders = array_merge($outputHeaders, array_values($extraHeadersInfo));
            $outputHeaders = array_unique($outputHeaders);
            $processedData = [];
            foreach ($dataRows as $row) {
                if (empty(implode('', $row))) continue;
                $newRow = [];
                $newRow['slot'] = '';
                $newRow['connectivity'] = '○ Y  /  ○ N';
                $newRow['disposition'] = "○ 11 ○ 12 ○ 13 ○ 14 ○ 15 ○ 16 ○ 17|○ 21 ○ 22 ○ 23 ○ 24 ○ 25 ○ 26";
                foreach ($columnMap as $standardKey => $mappedIndex) {
                    if ($mappedIndex !== -1 && isset($row[$mappedIndex])) {
                        $cellValue = preg_replace('/(\\\\r\\\\n|\\\\n|\\\\r|\r\n|\n|\r)/', ' ', (string) $row[$mappedIndex]);
                        if (is_numeric($cellValue) && $cellValue > 1 && ($standardKey === 'dob' || $standardKey === 'expiry')) {
                            try { $cellValue = Date::excelToDateTimeObject($cellValue)->format('d-m-Y'); } catch (Exception $e) {}
                        }
                        $newRow[$standardKey] = $cellValue;
                    }
                }
                foreach (['address2', 'address3'] as $addrKey) {
                    if (isset($columnMap[$addrKey]) && $columnMap[$addrKey] !== -1 && !empty($row[$columnMap[$addrKey]])) {
                        $newRow['address'] = ($newRow['address'] ?? '') . ', ' . $row[$columnMap[$addrKey]];
                    }
                }
                if (isset($newRow['address'])) { $newRow['address'] = trim($newRow['address'], " ,"); }
                foreach ($extraHeadersInfo as $originalIndex => $sanitizedHeader) {
                    $newRow[$sanitizedHeader] = $row[$originalIndex] ?? '';
                }
                $processedData[] = $newRow;
            }
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) { die("Database Connection Failed: " . $conn->connect_error); }
            $sql = "INSERT INTO initial_data_logs (source_filename, title, name, mobile_no, policy_number, pan, dob, expiry, address, city, state, country, pincode, plan, premium, sum_insured, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) { die("Failed to prepare statement: " . $conn->error); }
            
            // ########## THE FIX IS HERE ##########
            foreach($processedData as $dataRow) {
                $extraData = [];
                foreach($dataRow as $key => $value) {
                    if (!in_array($key, $standardKeys) && !in_array($key, ['slot','connectivity','disposition'])) { $extraData[$key] = $value; }
                }
                
                // Assign results of expressions to intermediate variables
                $jsonExtraData = !empty($extraData) ? json_encode($extraData) : null;
                $title = $dataRow['title'] ?? null;
                $name = $dataRow['name'] ?? null;
                $mobile_no = $dataRow['mobile_no'] ?? null;
                $policy_number = $dataRow['policy_number'] ?? null;
                $pan = $dataRow['pan'] ?? null;
                $dob = $dataRow['dob'] ?? null;
                $expiry = $dataRow['expiry'] ?? null;
                $address = $dataRow['address'] ?? null;
                $city = $dataRow['city'] ?? null;
                $state = $dataRow['state'] ?? null;
                $country = $dataRow['country'] ?? null;
                $pincode = $dataRow['pincode'] ?? null;
                $plan = $dataRow['plan'] ?? null;
                $premium = $dataRow['premium'] ?? null;
                $sum_insured = $dataRow['sum_insured'] ?? null;

                // Bind the new variables, NOT the expressions
                $stmt->bind_param("sssssssssssssssss", 
                    $originalFileName, $title, $name, $mobile_no, $policy_number, $pan, $dob, 
                    $expiry, $address, $city, $state, $country, $pincode, $plan, $premium, 
                    $sum_insured, $jsonExtraData
                );
                
                $stmt->execute();
            }
            // ########## END OF FIX ##########
            
            $stmt->close();
            $conn->close();

            $_SESSION['processed_data'] = $processedData;
            $_SESSION['output_headers'] = $outputHeaders;
            if (file_exists($originalFile)) unlink($originalFile);

        } catch (Exception $e) { die('Error loading file: ' . $e->getMessage()); }
    } else { die("Error: There was a problem uploading your file."); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calling Sheet Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1 class="mb-4 text-center">Calling Sheet Dashboard</h1>
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                        <h3 class="h5 mb-0">1. Generate Calling Sheets</h3>
                        <a href="view_initial_logs.php" class="btn btn-sm btn-light">View Source Logs</a>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Upload a source file (Excel/CSV) to process data, save it to the database, and create printable calling sheets.</p>
                        <form action="index.php" method="post" enctype="multipart/form-data" class="mt-auto">
                            <div class="mb-3">
                                <label for="customerFile" class="form-label"><strong>Select Source File:</strong></label>
                                <input class="form-control" type="file" id="customerFile" name="customerFile" accept=".xlsx, .csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Generate & Save Data</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100 shadow-sm">
                     <div class="card-header d-flex justify-content-between align-items-center bg-info text-white">
                        <h3 class="h5 mb-0">2. Interpret Marked Sheets</h3>
                        <a href="view_interpreted_logs.php" class="btn btn-sm btn-light">View Interpreted Logs</a>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center text-center">
                        <p class="card-text">After a sheet has been marked by a caller, upload a photo or PDF of it to have the AI interpret the results.</p>
                        <div class="mt-3">
                           <a href="process_with_gemini_python.php" class="btn btn-info text-white w-75">Go to AI Interpreter</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['processed_data'])): ?>
            <div class="card mt-4 text-center shadow-sm">
                <div class="card-body p-4">
                    <h4 class="text-success">File Processed & Saved!</h4>
                    <p>Your calling sheets are ready for download.</p>
                    <div class="d-grid gap-2 col-md-6 mx-auto mt-3">
                        <a href="index.php?action=download_pdf" class="btn btn-danger">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-file-earmark-pdf-fill" viewBox="0 0 16 16" style="vertical-align: -0.125em;"><path d="M5.523 12.424q.21-.164.479-.164.27 0 .479.164.21.164.21.326a.5.5 0 0 1-.21.326q-.21.164-.479.164-.27 0-.479-.164a.5.5 0 0 1-.21-.326q0-.162.21-.326M6.25 11.163h.291v-1.09h-.291zM4.99 11.163h.291v-1.09h-.291zM4.019 12.424q.21-.164.48-.164.27 0 .48.164.21.164.21.326a.5.5 0 0 1-.21.326q-.21.164-.48.164-.27 0-.48-.164a.5.5 0 0 1-.21-.326q0-.162.21-.326m2.953-2.185h.545v.931h-.545zm-1.809.931h.545v-.931h-.545z"/><path d="M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0M9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1M3 9.422c0-.402.164-.735.418-.945.254-.21.582-.315.972-.315.468 0 .85.144 1.12.432.27.29.405.67.405 1.123 0 .416-.14.76-.42.99-.28.23-.635.345-1.06.345-.312 0-.58-.063-.805-.19a.99.99 0 0 1-.485-.515H3z"/></svg>
                            Download PDF
                        </a>
                        <a href="index.php?action=download_excel" class="btn btn-success">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-file-earmark-spreadsheet-fill" viewBox="0 0 16 16" style="vertical-align: -0.125em;"><path d="M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0M9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1M3 6.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-3zm5 0a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-3zm-5 4a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-3zm5 0a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-3z"/></svg>
                            Download Excel
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>