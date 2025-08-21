<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
if (!isset($input['type']) || !isset($input['userId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    $conn = getDBConnection();
    
    // Create security_violations table if it doesn't exist
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS security_violations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL,
            session_id VARCHAR(100),
            violation_type VARCHAR(100) NOT NULL,
            violation_details JSON,
            ip_address VARCHAR(45),
            user_agent TEXT,
            page_url TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            INDEX idx_user_id (user_id),
            INDEX idx_violation_type (violation_type),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    $conn->query($createTableSQL);
    
    // Determine severity based on violation type
    $highSeverityTypes = [
        'devtools_opened',
        'console_accessed', 
        'screen_capture_api_called',
        'suspicious_activity_detected'
    ];
    
    $criticalTypes = [
        'multiple_violations',
        'session_terminated'
    ];
    
    $severity = 'low';
    if (in_array($input['type'], $highSeverityTypes)) {
        $severity = 'high';
    } elseif (in_array($input['type'], $criticalTypes)) {
        $severity = 'critical';
    } elseif (isset($input['violationCount']) && $input['violationCount'] > 3) {
        $severity = 'high';
    }
    
    // Insert the violation log
    $stmt = $conn->prepare("
        INSERT INTO security_violations 
        (user_id, session_id, violation_type, violation_details, ip_address, user_agent, page_url, severity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $details = json_encode($input['details'] ?? []);
    $ipAddress = getRealIpAddress();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $pageUrl = $input['url'] ?? 'unknown';
    
    $stmt->bind_param(
        'ssssssss',
        $input['userId'],
        $input['sessionId'] ?? session_id(),
        $input['type'],
        $details,
        $ipAddress,
        $userAgent,
        $pageUrl,
        $severity
    );
    
    if ($stmt->execute()) {
        // Check for critical violations that require immediate action
        if ($severity === 'critical' || ($input['violationCount'] ?? 0) >= 5) {
            // Log critical event
            error_log("CRITICAL SECURITY VIOLATION: User {$input['userId']} - Type: {$input['type']}");
            
            // Optionally send alert email or notification here
            // sendSecurityAlert($input);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Violation logged',
            'severity' => $severity,
            'violation_id' => $stmt->insert_id
        ]);
    } else {
        throw new Exception('Failed to insert violation log');
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Security logging error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Function to send security alerts (implement as needed)
 */
function sendSecurityAlert($violation) {
    // Implement email notification, Slack webhook, etc.
    // Example:
    // mail('security@company.com', 'Security Violation Alert', json_encode($violation));
}
?>