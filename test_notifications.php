<?php
/**
 * Simple test script to verify notification system
 * Run this manually to test notification processing without cron job
 */

require_once 'notification_processor.php';

// This will execute the notification processor once
echo "\nNotification processor test completed.\n";
echo "Check the logs/notification_processor.log file for details.\n";
?>