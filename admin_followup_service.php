<?php
/**
 * Admin Follow-up Service - Continuous monitoring
 * Alternative to cron job - runs continuously and processes every hour
 */

require_once 'admin_follow_up_processor.php';

// Log that service started
logMessage("Admin Follow-up Service started");

while (true) {
    try {
        // Run the processor
        include 'admin_follow_up_processor.php';
        
        // Wait for 1 hour (3600 seconds)
        logMessage("Waiting 1 hour until next processing cycle...");
        sleep(3600);
        
    } catch (Exception $e) {
        logMessage("Service error: " . $e->getMessage(), 'ERROR');
        // Wait 5 minutes before retrying on error
        sleep(300);
    }
}
?>