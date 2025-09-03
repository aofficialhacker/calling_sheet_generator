<?php
require_once 'db_config.php';
requireTeamLeader();

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$leaderName = $_SESSION['leader_name'];
$adminId = $_SESSION['admin_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification System Debug - Team Leader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .debug-panel {
            background: #1e1e1e;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            max-height: 400px;
            overflow-y: auto;
        }
        .debug-title {
            color: #ffff00;
            font-weight: bold;
            border-bottom: 1px solid #444;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .debug-item {
            margin: 5px 0;
            padding: 5px;
            background: rgba(0, 255, 0, 0.1);
            border-left: 3px solid #00ff00;
        }
        .debug-error {
            color: #ff6b6b;
            border-left-color: #ff6b6b;
            background: rgba(255, 107, 107, 0.1);
        }
        .debug-warning {
            color: #ffa726;
            border-left-color: #ffa726;
            background: rgba(255, 167, 38, 0.1);
        }
        .bell-demo {
            padding: 20px;
            border: 2px dashed #ccc;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
    </style>
</head>
<body data-role="team-leader">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center py-3">
                    <h1 class="h2">🔔 Notification System Debug</h1>
                    <div class="btn-toolbar">
                        <!-- Notification bell will appear here -->
                    </div>
                </div>
                
                <div class="alert alert-warning">
                    <h5>🚨 Debug Mode Active</h5>
                    <p>This page has <strong>NO SECURITY PROTECTION</strong> to allow console debugging. Use only for testing!</p>
                    <p><strong>Team Leader:</strong> <?= htmlspecialchars($leaderName) ?> (<?= $leaderId ?>)</p>
                </div>
                
                <!-- Live Debug Panel -->
                <div class="debug-panel">
                    <div class="debug-title">📊 LIVE NOTIFICATION DEBUG LOG</div>
                    <div id="debugLog">Initializing debug system...</div>
                </div>
                
                <!-- Bell Demo Area -->
                <div class="bell-demo">
                    <h4>🔔 Notification Bell Demo</h4>
                    <p>The notification bell should appear above. Check the debug log for details.</p>
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-bell-fill text-danger fs-1"></i>
                                    <h6>Critical (Red)</h6>
                                    <small>Overdue items</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-bell-fill text-warning fs-1"></i>
                                    <h6>High (Orange)</h6>
                                    <small>Due within 15min</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-bell-fill text-info fs-1"></i>
                                    <h6>Medium (Blue)</h6>
                                    <small>Due within 1hr</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bi bi-bell-fill text-success fs-1"></i>
                                    <h6>Low (Green)</h6>
                                    <small>Future items</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Manual Test Controls -->
                <div class="card">
                    <div class="card-header">
                        <h5>🧪 Manual Test Controls</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <button class="btn btn-danger w-100" onclick="testCriticalAlert()">
                                    🚨 Test Critical Alert
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-warning w-100" onclick="testUpcomingAlert()">
                                    ⏰ Test Upcoming Alert
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-info w-100" onclick="refreshNotifications()">
                                    🔄 Refresh Notifications
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Raw Data Display -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5>📋 Raw Notification Data</h5>
                    </div>
                    <div class="card-body">
                        <pre id="rawDataDisplay" class="bg-light p-3" style="max-height: 300px; overflow-y: auto;">Loading...</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="js/followup-notifications.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Custom debug logger for this page
        let debugLog = [];
        
        function addDebugLog(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `[${timestamp}] ${message}`;
            debugLog.push({message: logEntry, type: type});
            
            const debugDiv = document.getElementById('debugLog');
            
            debugDiv.innerHTML = debugLog.map(log => 
                `<div class="${log.type === 'error' ? 'debug-error' : log.type === 'warning' ? 'debug-warning' : 'debug-item'}">${log.message}</div>`
            ).slice(-20).join(''); // Keep only last 20 entries
            
            debugDiv.scrollTop = debugDiv.scrollHeight;
        }
        
        // Override console.log for this page to capture all debug output
        const originalConsoleLog = console.log;
        const originalConsoleError = console.error;
        
        console.log = function(...args) {
            originalConsoleLog.apply(console, arguments);
            addDebugLog(args.join(' '), 'info');
        };
        
        console.error = function(...args) {
            originalConsoleError.apply(console, arguments);
            addDebugLog('ERROR: ' + args.join(' '), 'error');
        };
        
        // Initialize notification system
        let followupNotifications = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            addDebugLog('🚀 Page loaded, initializing notification system...');
            
            try {
                followupNotifications = new FollowupNotifications();
                addDebugLog('✅ Notification system created successfully');
                
                // Make it globally accessible
                window.followupNotifications = followupNotifications;
                
                // Initial check
                setTimeout(() => {
                    addDebugLog('🔍 Performing initial notification check...');
                    refreshNotifications();
                }, 1000);
                
            } catch (error) {
                addDebugLog('❌ Failed to initialize notifications: ' + error.message, 'error');
            }
        });
        
        function refreshNotifications() {
            if (followupNotifications) {
                addDebugLog('🔄 Manually refreshing notifications...');
                followupNotifications.checkNotifications();
            }
        }
        
        function testCriticalAlert() {
            addDebugLog('🧪 Testing critical alert...');
            if (followupNotifications) {
                const testData = [{
                    id: '999',
                    customer_name: 'TEST CUSTOMER',
                    disposition_name: 'Test Disposition', 
                    urgency: 'critical',
                    minutes_until_due: -30,
                    display_time: '30 minutes ago'
                }];
                followupNotifications.showCriticalOverdueAlert(testData);
            }
        }
        
        function testUpcomingAlert() {
            addDebugLog('🧪 Testing upcoming alert...');
            if (followupNotifications) {
                const testData = [{
                    id: '888',
                    customer_name: 'TEST UPCOMING',
                    disposition_name: 'Test Follow-up',
                    urgency: 'high', 
                    minutes_until_due: 10,
                    display_time: 'in 10 minutes'
                }];
                followupNotifications.showUpcomingAlert(testData);
            }
        }
        
        // Intercept fetch requests to show API calls
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            addDebugLog('📡 API Call: ' + args[0]);
            
            return originalFetch.apply(this, arguments).then(response => {
                return response.clone().json().then(data => {
                    addDebugLog('📥 API Response received');
                    
                    // Display raw data
                    document.getElementById('rawDataDisplay').textContent = JSON.stringify(data, null, 2);
                    
                    if (data.notifications && data.notifications.length > 0) {
                        addDebugLog(`📊 Found ${data.notifications.length} notifications:`);
                        data.notifications.forEach((notif, index) => {
                            addDebugLog(`  ${index + 1}. ${notif.customer_name} - Urgency: ${notif.urgency} - Due in: ${notif.minutes_until_due} min`);
                        });
                    } else {
                        addDebugLog('📭 No notifications found');
                    }
                    
                    return Promise.resolve(new Response(JSON.stringify(data)));
                }).catch(err => {
                    addDebugLog('❌ Failed to parse API response: ' + err.message, 'error');
                    return response;
                });
            });
        };
        
        addDebugLog('🔧 Debug system initialized - console access enabled on this page');
    </script>
</body>
</html>