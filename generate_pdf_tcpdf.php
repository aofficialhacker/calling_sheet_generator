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

use TCPDF;

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

// Create TCPDF instance
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Calling Sheet - Batch ' . $batch_id);
$pdf->SetSubject('Calling Sheet');

// Set default header/footer data
$pdf->SetHeaderData('', 0, 'Calling Sheet - Batch ' . $batch_id, '');
$pdf->setHeaderFont(['helvetica', '', 10]);
$pdf->setFooterFont(['helvetica', '', 8]);

// Set margins
$pdf->SetMargins(10, 20, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(true, 15);

// Add a page
$pdf->AddPage();

// Define table structure
$headers = ['ID', 'Slot', 'Connectivity', 'Disposition', 'Mobile', 'Name', 'Policy', 'Age', 'City'];
$colWidths = [15, 12, 20, 25, 25, 30, 25, 12, 30]; // Column widths in mm

// Set font for header
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(240, 240, 240);

// Draw header row
$y = $pdf->GetY();
foreach ($headers as $i => $header) {
    $pdf->SetXY(10 + array_sum(array_slice($colWidths, 0, $i)), $y);
    $pdf->Cell($colWidths[$i], 8, $header, 1, 0, 'C', true);
}
$pdf->Ln(8);

// Set font for data
$pdf->SetFont('helvetica', '', 6);
$pdf->SetFillColor(255, 255, 255);

$rowCount = 0;
while ($row = $result->fetch_assoc()) {
    // Check if we need a new page
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        
        // Redraw header on new page
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(240, 240, 240);
        $y = $pdf->GetY();
        foreach ($headers as $i => $header) {
            $pdf->SetXY(10 + array_sum(array_slice($colWidths, 0, $i)), $y);
            $pdf->Cell($colWidths[$i], 8, $header, 1, 0, 'C', true);
        }
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', '', 6);
        $pdf->SetFillColor(255, 255, 255);
    }
    
    $y = $pdf->GetY();
    
    // Prepare row data
    $rowData = [
        substr($row['id'], -6), // Last 6 chars of ID
        '', // Empty slot
        '○ Y / ○ N', // Connectivity checkboxes
        '○ ' . ($row['disposition'] ?? ''), // Disposition
        $row['mobile_no'] ?? '',
        $row['name'] ?? '',
        substr($row['policy_number'] ?? '', 0, 12), // Truncate policy number
        $row['age'] ?? '',
        $row['city'] ?? ''
    ];
    
    // Draw row
    foreach ($rowData as $i => $data) {
        $pdf->SetXY(10 + array_sum(array_slice($colWidths, 0, $i)), $y);
        
        // Special formatting for mobile column
        if ($i == 4) { // Mobile column
            $pdf->SetFont('helvetica', 'B', 6);
        } else {
            $pdf->SetFont('helvetica', '', 6);
        }
        
        $pdf->Cell($colWidths[$i], 6, $data, 1, 0, 'L');
    }
    $pdf->Ln(6);
    $rowCount++;
}

// Add cutline
$cutlineX = 10 + array_sum(array_slice($colWidths, 0, 5)); // After mobile column
$pdf->SetDrawColor(85, 85, 85);
$pdf->SetLineWidth(0.4);

// Draw dashed line
$pageHeight = $pdf->getPageHeight();
for ($y = 30; $y < $pageHeight - 20; $y += 3) {
    $pdf->Line($cutlineX, $y, $cutlineX, $y + 1.5);
}

// Add scissors at top and bottom
$pdf->SetXY($cutlineX - 3, 25);
$pdf->SetFont('helvetica', '', 12);
$pdf->Write(0, '✂');
$pdf->SetXY($cutlineX - 3, $pageHeight - 25);
$pdf->Write(0, '✂');

// Clear output buffer and send PDF
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Calling_Sheet_TCPDF_' . $batch_id . '.pdf"');

$pdf->Output('Calling_Sheet_TCPDF_' . $batch_id . '.pdf', 'D');

$conn->close();
exit;
?>