<?php
/**
 * Connectivity Backfill Script
 * 
 * This script fixes existing records in lv_final_call_logs that have blank/NULL connectivity values
 * by populating them based on the disposition's category from lv_disposition_codes table.
 * 
 * Usage: 
 *   Command Line: php fix_connectivity_backfill.php [--dry-run] [--limit=N]
 *   Web Browser:  fix_connectivity_backfill.php?dry-run=1&limit=100
 * 
 * Options:
 *   --dry-run / dry-run=1    Show what would be updated without making changes
 *   --limit=N / limit=N      Process only N records (default: all records)
 */

require_once 'db_config.php';

// Parse command line arguments (handle both CLI and web access)
$dry_run = false;
$limit = null;

// Check if running from command line
if (isset($argv) && is_array($argv)) {
    $args = array_slice($argv, 1);
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $dry_run = true;
        } elseif (strpos($arg, '--limit=') === 0) {
            $limit = (int) substr($arg, 8);
        }
    }
} else {
    // Handle web/browser access with GET parameters
    if (isset($_GET['dry-run']) || isset($_GET['dry_run'])) {
        $dry_run = true;
    }
    if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
        $limit = (int) $_GET['limit'];
    }
}

// Detect if running in web browser
$is_web = !isset($argv);
if ($is_web) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font-family: monospace; background: #f5f5f5; padding: 15px; border: 1px solid #ddd;'>\n";
}

echo "=== Connectivity Field Backfill Script ===\n";
echo "Mode: " . ($dry_run ? "DRY RUN (no changes will be made)" : "LIVE UPDATE") . "\n";
echo "Limit: " . ($limit ? "$limit records" : "All records") . "\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$conn = getDBConnection();

// Build disposition category mapping
echo "Building disposition category mapping...\n";
$dispositions = $conn->query("SELECT code, description, category FROM lv_disposition_codes WHERE is_active = 1");
$DISPOSITION_CATEGORY_MAP = [];
$category_counts = ['connected' => 0, 'not_connected' => 0];

while ($row = $dispositions->fetch_assoc()) {
    $DISPOSITION_CATEGORY_MAP[$row['description']] = $row['category'];
    $category_counts[$row['category']]++;
}

echo "Found " . count($DISPOSITION_CATEGORY_MAP) . " disposition mappings:\n";
echo "  - Connected dispositions: {$category_counts['connected']}\n";
echo "  - Not connected dispositions: {$category_counts['not_connected']}\n\n";

// Find records that need connectivity update
$limit_clause = $limit ? "LIMIT $limit" : "";
$find_query = "
    SELECT fcl.id, fcl.disposition, fcl.connectivity,
           dc.category
    FROM lv_final_call_logs fcl
    LEFT JOIN lv_disposition_codes dc ON fcl.disposition = dc.description AND dc.is_active = 1
    WHERE (fcl.connectivity IS NULL OR fcl.connectivity = '')
    AND fcl.disposition IS NOT NULL 
    AND fcl.disposition != ''
    AND dc.category IS NOT NULL
    ORDER BY fcl.id
    $limit_clause
";

$result = $conn->query($find_query);

if (!$result) {
    die("Error finding records to update: " . $conn->error . "\n");
}

$total_found = $result->num_rows;
echo "Found $total_found records with missing connectivity that can be fixed:\n\n";

if ($total_found == 0) {
    echo "No records need updating. Exiting.\n";
    $conn->close();
    exit(0);
}

// Group records by expected connectivity value
$updates = ['Yes' => [], 'No' => []];
$preview_count = 0;

while ($row = $result->fetch_assoc()) {
    $expected_connectivity = ($row['category'] === 'connected') ? 'Yes' : 'No';
    $updates[$expected_connectivity][] = $row['id'];
    
    // Show first few examples
    if ($preview_count < 10) {
        echo "Record #{$row['id']}: '{$row['disposition']}' (category: {$row['category']}) -> connectivity: '$expected_connectivity'\n";
        $preview_count++;
    }
}

if ($total_found > 10) {
    echo "... and " . ($total_found - 10) . " more records\n";
}

echo "\nUpdate summary:\n";
echo "  - Records to set connectivity='Yes': " . count($updates['Yes']) . "\n";
echo "  - Records to set connectivity='No': " . count($updates['No']) . "\n";
echo "  - Total records to update: $total_found\n\n";

if ($dry_run) {
    echo "DRY RUN MODE: No changes made to database.\n";
    echo "Run without --dry-run to apply these changes.\n";
} else {
    echo "Proceeding with database updates...\n";
    
    $conn->begin_transaction();
    $total_updated = 0;
    
    try {
        // Update records that should have connectivity = 'Yes'
        if (count($updates['Yes']) > 0) {
            $ids_yes = "'" . implode("','", $updates['Yes']) . "'";
            $update_yes_sql = "UPDATE lv_final_call_logs SET connectivity = 'Yes' WHERE id IN ($ids_yes)";
            if ($conn->query($update_yes_sql)) {
                $updated_yes = $conn->affected_rows;
                $total_updated += $updated_yes;
                echo "✓ Updated $updated_yes records to connectivity='Yes'\n";
            } else {
                throw new Exception("Error updating 'Yes' records: " . $conn->error);
            }
        }
        
        // Update records that should have connectivity = 'No'  
        if (count($updates['No']) > 0) {
            $ids_no = "'" . implode("','", $updates['No']) . "'";
            $update_no_sql = "UPDATE lv_final_call_logs SET connectivity = 'No' WHERE id IN ($ids_no)";
            if ($conn->query($update_no_sql)) {
                $updated_no = $conn->affected_rows;
                $total_updated += $updated_no;
                echo "✓ Updated $updated_no records to connectivity='No'\n";
            } else {
                throw new Exception("Error updating 'No' records: " . $conn->error);
            }
        }
        
        $conn->commit();
        echo "\n✅ SUCCESS: Updated $total_updated records total\n";
        
        // Show final statistics
        echo "\nFinal connectivity statistics:\n";
        $final_stats = $conn->query("
            SELECT connectivity, COUNT(*) as count 
            FROM lv_final_call_logs 
            WHERE processed_at IS NOT NULL
            GROUP BY connectivity 
            ORDER BY count DESC
        ");
        
        while ($row = $final_stats->fetch_assoc()) {
            $connectivity_value = $row['connectivity'] ?: 'NULL/Empty';
            echo "  - $connectivity_value: {$row['count']} records\n";
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "\n❌ ERROR: " . $e->getMessage() . "\n";
        echo "Transaction rolled back. No changes made.\n";
        exit(1);
    }
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";

if ($is_web) {
    echo "</pre>\n";
    echo "<p><em>Usage via web: Add ?dry-run=1 for dry run, ?limit=100 to limit records</em></p>\n";
}

$conn->close();
?>