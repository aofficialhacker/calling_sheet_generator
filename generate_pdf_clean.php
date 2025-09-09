<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// Check admin authentication
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

set_time_limit(0);
ini_set('memory_limit', '2048M');

$conn = getDBConnection();

// Get parameters
$batch_id = $_GET['batch_id'] ?? null;
if (!$batch_id) {
    die("Error: No batch ID provided.");
}

$adminId = $_SESSION['admin_id'];
$pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
$pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);

// --- Fetch Dynamic Disposition Codes ---
$dispResult = $conn->query("
    SELECT code, description, category 
    FROM disposition_codes 
    WHERE is_active = 1 
    ORDER BY category, CAST(code AS UNSIGNED), code
");
$dispositionList = [];
while($d = $dispResult->fetch_assoc()){
    $dispositionList[] = $d;
}

// Create legends
$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// Get data count
$countSql = "SELECT COUNT(*) as total FROM final_call_logs fcl 
             JOIN file_batches fb ON fcl.batch_id = fb.id 
             WHERE fb.admin_id = ? AND fcl.batch_id = ?";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param("ss", $adminId, $batch_id);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

if ($totalRecords == 0) {
    die("No records found for this batch.");
}

// Create PDF
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Set document properties
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);
$pdf->SetMargins(10, 20, 10);
$pdf->SetAutoPageBreak(true, 20);

// Add first page
$pdf->AddPage();

// Header
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, $pdfTitle, 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 6, $slotLegend, 0, 1, 'C');
$pdf->Ln(5);

// Table headers
$headers = ['Id', 'Slot', 'Connectivity', 'Disposition', 'Mobile', 'Name', 'Dob', 'Age', 'Address', 'City', 'State'];
$widths = [25, 15, 25, 40, 30, 35, 25, 15, 35, 25, 25];

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(220, 220, 220);

foreach ($headers as $i => $header) {
    $pdf->Cell($widths[$i], 12, $header, 1, 0, 'C', true);
}
$pdf->Ln();

// Draw cutline after mobile column
$cutlineX = 10 + 25 + 15 + 25 + 40 + 30; // Position after mobile column
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);

// Draw dashed line from header to bottom
for ($y = $pdf->GetY(); $y < 200; $y += 5) {
    $pdf->Line($cutlineX, $y, $cutlineX, $y + 2.5);
}

// Add scissors
$pdf->SetXY($cutlineX - 5, $pdf->GetY() - 5);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(10, 6, 'X', 0, 0, 'C');

// Fetch and display data
$sql = "SELECT fcl.id, fcl.mobile_no, fcl.name, fcl.dob, fcl.age, fcl.address, fcl.city, fcl.state
        FROM final_call_logs fcl 
        JOIN file_batches fb ON fcl.batch_id = fb.id 
        WHERE fb.admin_id = ? AND fcl.batch_id = ? 
        ORDER BY fcl.id LIMIT 50"; // Limit for testing

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $adminId, $batch_id);
$stmt->execute();
$result = $stmt->get_result();

// Create disposition grid
$dispCodes = [];
foreach ($dispositionList as $disp) {
    $dispCodes[] = 'O ' . $disp['code'];
    if (count($dispCodes) >= 6) break; // First 6 codes only
}
$dispositionGrid = implode('  ', $dispCodes);

while ($row = $result->fetch_assoc()) {
    // Check if we need a new page
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        
        // Redraw headers
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 12, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();
    }
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Format date
    $dob = '';
    if (!empty($row['dob']) && $row['dob'] !== '0000-00-00') {
        $dob = date('d-m-Y', strtotime($row['dob']));
    }
    
    // Prepare row data
    $rowData = [
        $row['id'],                                    // Full ID
        '',                                           // Empty slot
        'O Y / O N',                                 // Connectivity
        $dispositionGrid,                            // Disposition codes
        $row['mobile_no'] ?? '',                     // Mobile
        $row['name'] ?? '',                          // Name
        $dob,                                        // DOB formatted
        $row['age'] ?? '',                           // Age
        substr($row['address'] ?? '', 0, 30),        // Address truncated
        $row['city'] ?? '',                          // City
        $row['state'] ?? ''                          // State
    ];
    
    foreach ($rowData as $i => $data) {
        // Make mobile number bold
        if ($i == 4 && !empty($data)) {
            $pdf->SetFont('helvetica', 'B', 9);
        } else {
            $pdf->SetFont('helvetica', '', 9);
        }
        
        $pdf->Cell($widths[$i], 10, $data, 1, 0, 'L');
    }
    $pdf->Ln();
}

// Add scissors at bottom of cutline
$pdf->SetXY($cutlineX - 5, $pdf->GetY() + 5);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(10, 6, 'X', 0, 0, 'C');

// Output PDF
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $pdfFileName . '"');

$pdf->Output($pdfFileName, 'D');

$conn->close();
exit;
?>