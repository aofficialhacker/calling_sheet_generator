<?php
set_time_limit(0);

$argument = '';
$filename = 'Calling_Sheet_'.date('Y-m-d').'.pdf';

if (isset($_GET['batch_id']) && is_numeric($_GET['batch_id'])) {
    $batch_id = (int)$_GET['batch_id'];
    $argument = 'batch_id=' . $batch_id;
    $filename = 'Batch_' . $batch_id . '_Calling_Sheet_' . date('Y-m-d') . '.pdf';

} elseif (isset($_GET['disposition']) && $_GET['disposition'] === 'follow_up') {
    $argument = 'disposition=follow_up';
    $filename = 'Follow_Up_Calling_Sheet_' . date('Y-m-d') . '.pdf';
} else {
    die("Error: No valid batch ID or disposition provided.");
}

// Define path to your python executable.
// 'python' usually works if it's in the system PATH.
// Otherwise, provide the full path e.g., 'C:\Python39\python.exe'
$python_executable = 'python';

$script_path = __DIR__ . '/reportlab_generator.py'; // Make sure this points to the new script
$command = escapeshellcmd($python_executable) . ' ' . escapeshellarg($script_path) . ' ' . escapeshellarg($argument);

// Use this block to debug if you still have issues
$full_command_for_debug = $command . ' 2>&1';
$output = shell_exec($full_command_for_debug);

// A simple check to see if the output is a PDF. If not, it's an error.
if (strpos($output, '%PDF') !== 0) {
    header("Content-Type: text/plain");
    echo "PDF Generation Failed!\n\n";
    echo "COMMAND EXECUTED:\n" . htmlspecialchars($command) . "\n\n";
    echo "PYTHON SCRIPT OUTPUT (ERROR):\n";
    echo htmlspecialchars($output);
    exit;
}

// If it works, stream the PDF to the browser
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
echo $output;