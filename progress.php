<?php
session_start();
header('Content-Type: application/json');

$progress = isset($_SESSION['processing_progress']) ? $_SESSION['processing_progress'] : 0;

echo json_encode(['progress' => $progress]);
?>