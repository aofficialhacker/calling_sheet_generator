<?php
require_once 'db_config.php';
requireAdmin();

if (!isset($_GET['export']) || $_GET['export'] !== 'csv') {
    http_response_code(400);
    exit('Invalid export request');
}

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Apply same filters as the main page
$filterLeader = $_GET['leader'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterBucket = $_GET['bucket'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterOverdue = $_GET['overdue'] ?? '';

// Build query conditions
$conditions = ["tl.admin_id = ?"];
$params = [$adminId];
$paramTypes = "s";

if ($filterLeader) {
    $conditions[] = "fs.leader_id = ?";
    $params[] = $filterLeader;
    $paramTypes .= "s";
}

if ($filterStatus) {
    $conditions[] = "fs.status = ?";
    $params[] = $filterStatus;
    $paramTypes .= "s";
}

if ($filterBucket) {
    $conditions[] = "fs.bucket_id = ?";
    $params[] = $filterBucket;
    $paramTypes .= "i";
}

if ($filterDateFrom) {
    $conditions[] = "DATE(fs.follow_up_datetime) >= ?";
    $params[] = $filterDateFrom;
    $paramTypes .= "s";
}

if ($filterDateTo) {
    $conditions[] = "DATE(fs.follow_up_datetime) <= ?";
    $params[] = $filterDateTo;
    $paramTypes .= "s";
}

if ($filterOverdue === 'yes') {
    $conditions[] = "fs.status = 'scheduled' AND fs.follow_up_datetime < NOW()";
} elseif ($filterOverdue === 'no') {
    $conditions[] = "(fs.status != 'scheduled' OR fs.follow_up_datetime >= NOW())";
}

$whereClause = implode(" AND ", $conditions);

// Export query - get all data without limit
$query = "
    SELECT 
        fs.schedule_id as 'Schedule ID',
        tl.leader_name as 'Team Leader',
        tl.leader_id as 'Leader ID',
        fcl.name as 'Customer Name',
        fcl.mobile_no as 'Customer Mobile',
        p.product_name as 'Product',
        b.original_filename as 'Batch File',
        fs.disposition_name as 'Disposition',
        db.bucket_name as 'Bucket',
        fs.follow_up_datetime as 'Scheduled Time',
        fs.status as 'Status',
        fs.completed_at as 'Completed Time',
        CASE 
            WHEN fs.delay_minutes IS NULL THEN 'N/A'
            WHEN fs.delay_minutes < 0 THEN CONCAT('-', ABS(fs.delay_minutes), 'm (Early)')
            WHEN fs.delay_minutes = 0 THEN 'On Time'
            ELSE CONCAT('+', fs.delay_minutes, 'm (Late)')
        END as 'Delay',
        CASE 
            WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() 
            THEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW())
            ELSE NULL 
        END as 'Current Overdue Minutes',
        CASE 
            WHEN fs.status = 'scheduled' AND fs.follow_up_datetime < NOW() THEN
                CASE 
                    WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 60 THEN 'Recently Overdue'
                    WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 1440 THEN 'Overdue (< 1 day)'
                    WHEN TIMESTAMPDIFF(MINUTE, fs.follow_up_datetime, NOW()) <= 10080 THEN 'Overdue (< 1 week)'
                    ELSE 'Severely Overdue (> 1 week)'
                END
            ELSE 'Not Overdue'
        END as 'Overdue Status',
        fs.remarks as 'Remarks',
        fs.created_at as 'Created Time',
        fs.updated_at as 'Last Updated'
    FROM follow_up_schedules fs
    JOIN team_leaders tl ON fs.leader_id = tl.leader_id
    JOIN disposition_buckets db ON fs.bucket_id = db.id
    JOIN final_call_logs fcl ON fs.lead_id = fcl.id
    JOIN file_batches b ON fcl.batch_id = b.id
    JOIN products p ON b.product_code = p.product_code
    WHERE $whereClause
    ORDER BY fs.follow_up_datetime DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Set CSV headers
$filename = 'TL_Followup_History_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create file handle for output
$output = fopen('php://output', 'w');

// Add BOM for proper UTF-8 handling in Excel
fwrite($output, "\xEF\xBB\xBF");

// Write CSV header
if ($result->num_rows > 0) {
    $firstRow = $result->fetch_assoc();
    fputcsv($output, array_keys($firstRow));
    
    // Write first row data
    fputcsv($output, array_values($firstRow));
    
    // Write remaining rows
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, array_values($row));
    }
} else {
    // Write headers even if no data
    $headers = [
        'Schedule ID', 'Team Leader', 'Leader ID', 'Customer Name', 'Customer Mobile',
        'Product', 'Batch File', 'Disposition', 'Bucket', 'Scheduled Time',
        'Status', 'Completed Time', 'Delay', 'Current Overdue Minutes',
        'Overdue Status', 'Remarks', 'Created Time', 'Last Updated'
    ];
    fputcsv($output, $headers);
    fputcsv($output, ['No data found matching the selected criteria']);
}

fclose($output);
$stmt->close();
$conn->close();
exit();
?>