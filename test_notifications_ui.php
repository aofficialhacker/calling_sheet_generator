<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Notifications UI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2">Test Dashboard</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <div class="btn-group me-2">
                    <button type="button" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            <h4>Notification System Test</h4>
            <p>This page tests the notification UI and JavaScript functionality.</p>
            <p><strong>Check the browser console for any errors.</strong></p>
            <p><strong>Look for the notification bell icon in the toolbar above.</strong></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/followup-notifications.js"></script>
    <script>
        // Simulate team leader session for testing
        window.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded, checking notification system...');
            
            // Create notification system
            window.followupNotifications = new FollowupNotifications();
            
            // Log notification permission status
            console.log('Notification permission:', Notification.permission);
            
            // Check if bell icon was created
            setTimeout(() => {
                const bellIcon = document.getElementById('notificationBell');
                if (bellIcon) {
                    console.log('✓ Bell icon created successfully');
                    bellIcon.style.border = '3px solid green';
                    bellIcon.title = 'Notification system active';
                } else {
                    console.error('✗ Bell icon not found');
                }
            }, 1000);
        });
    </script>
</body>
</html>