<?php
require_once 'db_config.php';
require 'vendor/autoload.php';

// Check admin authentication
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf as PdfWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

set_time_limit(0);
ini_set('memory_limit', '2048M');

$conn = getDBConnection();

// Get batch ID
$batch_id = $_GET['batch_id'] ?? null;
if (!$batch_id) {
    die("Error: No batch ID provided.");
}

// Fetch data
$adminId = $_SESSION['admin_id'];
$sql = "SELECT fcl.id, fcl.mobile_no, fcl.name, fcl.status, fcl.disposition, fcl.title, fcl.policy_number, fcl.pan, fcl.dob, fcl.age, fcl.expiry, fcl.address, fcl.city, fcl.state, fcl.pincode 
        FROM final_call_logs fcl 
        JOIN file_batches fb ON fcl.batch_id = fb.id 
        WHERE fb.admin_id = ? AND fcl.batch_id = ? 
        ORDER BY fcl.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $adminId, $batch_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("No records found for this batch.");
}

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set page orientation to landscape
$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

// Set margins
$sheet->getPageMargins()->setTop(0.75);
$sheet->getPageMargins()->setRight(0.25);
$sheet->getPageMargins()->setLeft(0.25);
$sheet->getPageMargins()->setBottom(0.75);

// Define headers
$headers = ['ID', 'Slot', 'Connectivity', 'Disposition', 'Mobile', 'Name', 'Policy', 'Age', 'City', 'State'];
$columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

// Set title
$sheet->setCellValue('A1', 'Calling Sheet - Batch ' . $batch_id);
$sheet->mergeCells('A1:J1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Set legend
$sheet->setCellValue('A2', 'SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)');
$sheet->mergeCells('A2:J2');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Set headers in row 3
foreach ($headers as $i => $header) {
    $sheet->setCellValue($columns[$i] . '3', $header);
}

// Style header row
$headerRange = 'A3:J3';
$sheet->getStyle($headerRange)->getFont()->setBold(true);
$sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
$sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Set column widths
$columnWidths = [8, 6, 12, 15, 12, 15, 12, 5, 10, 10];
foreach ($columns as $i => $col) {
    $sheet->getColumnDimension($col)->setWidth($columnWidths[$i]);
}

// Add data rows
$row = 4;
while ($data = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, substr($data['id'], -6)); // Last 6 chars
    $sheet->setCellValue('B' . $row, ''); // Slot (empty)
    $sheet->setCellValue('C' . $row, '○ Y / ○ N'); // Connectivity
    $sheet->setCellValue('D' . $row, '○ ' . ($data['disposition'] ?? '')); // Disposition
    $sheet->setCellValue('E' . $row, $data['mobile_no'] ?? '');
    $sheet->setCellValue('F' . $row, $data['name'] ?? '');
    $sheet->setCellValue('G' . $row, $data['policy_number'] ?? '');
    $sheet->setCellValue('H' . $row, $data['age'] ?? '');
    $sheet->setCellValue('I' . $row, $data['city'] ?? '');
    $sheet->setCellValue('J' . $row, $data['state'] ?? '');
    
    // Style mobile number column (bold)
    $sheet->getStyle('E' . $row)->getFont()->setBold(true);
    
    // Add borders
    $sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
    $row++;
}

// Set font size for all data
$dataRange = 'A3:J' . ($row - 1);
$sheet->getStyle($dataRange)->getFont()->setSize(8);

// Set row height
$sheet->getDefaultRowDimension()->setRowHeight(15);

// Configure PDF writer
$writer = new PdfWriter($spreadsheet);

// Clear output buffer and send PDF
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Calling_Sheet_PhpSpreadsheet_' . $batch_id . '.pdf"');

$writer->save('php://output');

$conn->close();
exit;
?>