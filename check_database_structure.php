<?php
// This script checks if all required tables and columns exist
require_once 'db_config.php';

$conn = getDBConnection();

echo "<h2>Database Structure Check</h2>";
echo "<pre>";

// Check if tables exist
$tables = [
    'first_register' => ['id', 'refercode', 'rname', 'mobile_no', 'addedBy'],
    'corporate_connector' => ['id', 'refercode', 'rname', 'mobile_no', 'master_refercode'],
    'corp_leader' => ['id', 'refercode', 'username', 'mobile', 'leader_of'],
    'callers' => ['finqy_id', 'caller_name', 'caller_type', 'mobile_no', 'is_active'],
    'admin_caller_mapping' => ['admin_id', 'finqy_id']
];

foreach ($tables as $table => $columns) {
    echo "\nChecking table: $table\n";
    
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✓ Table exists\n";
        
        // Check columns
        foreach ($columns as $column) {
            $colResult = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            if ($colResult->num_rows > 0) {
                echo "  ✓ Column '$column' exists\n";
            } else {
                echo "  ✗ Column '$column' MISSING\n";
            }
        }
    } else {
        echo "✗ Table MISSING\n";
    }
}

// Check sample data
echo "\n\nSample Data Check:\n";

// Check corporate_user_permission
$result = $conn->query("SELECT COUNT(*) as count FROM corporate_user_permission WHERE designation IN ('agency_development_manager', 'branch_manager', 'zonal_manager')");
$count = $result->fetch_assoc()['count'];
echo "\nCorporate users (ADM/BM/ZM): $count\n";

// Check first_register
$result = $conn->query("SELECT COUNT(*) as count FROM first_register");
$count = $result->fetch_assoc()['count'];
echo "Partners in first_register: $count\n";

// Check corporate_connector
$result = $conn->query("SELECT COUNT(*) as count FROM corporate_connector");
$count = $result->fetch_assoc()['count'];
echo "Connectors in corporate_connector: $count\n";

// Check corp_leader
$result = $conn->query("SELECT COUNT(*) as count FROM corp_leader");
$count = $result->fetch_assoc()['count'];
echo "Team members in corp_leader: $count\n";

// Sample hierarchy check
echo "\n\nSample Hierarchy Check:\n";
$result = $conn->query("
    SELECT cup.id, cup.name, cup.designation 
    FROM corporate_user_permission cup 
    WHERE designation IN ('agency_development_manager', 'branch_manager', 'zonal_manager')
    LIMIT 1
");

if ($row = $result->fetch_assoc()) {
    echo "Testing with: {$row['name']} (ID: {$row['id']}, {$row['designation']})\n";
    
    // Check partners
    $partnerResult = $conn->query("SELECT COUNT(*) as count FROM first_register WHERE addedBy = {$row['id']}");
    $partnerCount = $partnerResult->fetch_assoc()['count'];
    echo "  Partners under this user: $partnerCount\n";
    
    if ($partnerCount > 0) {
        // Get one partner
        $partnerData = $conn->query("SELECT refercode FROM first_register WHERE addedBy = {$row['id']} LIMIT 1")->fetch_assoc();
        if ($partnerData) {
            // Check connectors
            $connectorResult = $conn->query("SELECT COUNT(*) as count FROM corporate_connector WHERE master_refercode = '{$partnerData['refercode']}'");
            $connectorCount = $connectorResult->fetch_assoc()['count'];
            echo "  Connectors under first partner: $connectorCount\n";
        }
    }
}

echo "</pre>";

$conn->close();
?>