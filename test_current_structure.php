<?php
// Test current structure with simple approach
require 'vendor/autoload.php';

// Simple test data
$testData = [
    ['id' => 'HIV02B00100001', 'mobile' => '9860470014'],
    ['id' => 'HIV02B00100002', 'mobile' => '9766343453'], 
    ['id' => 'HIV02B00100003', 'mobile' => '9561111037']
];

// Create simple PDF with exact 5 columns
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Test');
$pdf->SetTitle('Test Structure');
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

// Title and legends
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'Calling Sheet Test Structure', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, 'SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)', 0, 1, 'C');
$pdf->Cell(0, 4, 'DISPO (Y): 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up', 0, 1, 'C');
$pdf->Cell(0, 4, 'DISPO (N): 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service', 0, 1, 'C');
$pdf->Ln(5);

// Column widths - exactly 5 columns
$colWidths = [50, 25, 35, 110, 40]; // ID, Slot, Connectivity, Disposition, Mobile
$colHeaders = ['ID', 'Slot', 'Connectivity', 'Disposition', 'Mobile'];

// Draw headers
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(230, 230, 230);
for ($i = 0; $i < 5; $i++) {
    $pdf->Cell($colWidths[$i], 10, $colHeaders[$i], 1, 0, 'C', true);
}
$pdf->Ln();

// Disposition circles grid
$dispCircles = "○11  ○12  ○13  ○14\n○21  ○22  ○23  ○24";

// Draw data rows
foreach ($testData as $row) {
    $rowHeight = 20;
    
    // ID column
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell($colWidths[0], $rowHeight, $row['id'], 1, 0, 'C');
    
    // Slot column (empty)
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($colWidths[1], $rowHeight, '', 1, 0, 'C');
    
    // Connectivity column
    $pdf->Cell($colWidths[2], $rowHeight, 'O Y / O N', 1, 0, 'C');
    
    // Disposition column (circles)
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($colWidths[3], $rowHeight, $dispCircles, 1, 0, 'C');
    
    // Mobile column
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell($colWidths[4], $rowHeight, $row['mobile'], 1, 0, 'C');
    
    $pdf->Ln();
}

// Add scissor marks for mobile column
$pdf->SetDrawColor(100, 100, 100);
$pdf->SetLineWidth(0.3);

// Calculate mobile column position
$mobileStart = 15 + $colWidths[0] + $colWidths[1] + $colWidths[2] + $colWidths[3];
$mobileEnd = $mobileStart + $colWidths[4];
$mobileCenter = ($mobileStart + $mobileEnd) / 2;

// Top scissor line
$topY = 50;
for ($x = $mobileStart; $x < $mobileEnd; $x += 3) {
    $pdf->Line($x, $topY, $x + 1.5, $topY);
}
$pdf->SetXY($mobileCenter - 2, $topY - 3);
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(4, 3, '✂', 0, 0, 'C');

// Bottom scissor line  
$bottomY = $pdf->GetY() + 5;
for ($x = $mobileStart; $x < $mobileEnd; $x += 3) {
    $pdf->Line($x, $bottomY, $x + 1.5, $bottomY);
}
$pdf->SetXY($mobileCenter - 2, $bottomY + 1);
$pdf->Cell(4, 3, '✂', 0, 0, 'C');

// Output
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Current_Test.pdf"');
$pdf->Output('Current_Test.pdf', 'D');
?>