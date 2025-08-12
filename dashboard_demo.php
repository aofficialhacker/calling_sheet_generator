<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Demo - Calling Sheet Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <style>
        .dashboard-card {
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .superadmin-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .admin-card {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        .telecaller-card {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }
        .feature-list {
            font-size: 0.9rem;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row">
            <div class="col-12 text-center mb-5">
                <h1 class="display-4"><i class="bi bi-graph-up-arrow me-3"></i>Interactive Dashboards</h1>
                <p class="lead text-muted">Comprehensive analytics and performance insights for all user types</p>
            </div>
        </div>

        <div class="row">
            <!-- Super Admin Dashboard -->
            <div class="col-md-4 mb-4">
                <div class="card dashboard-card superadmin-card h-100" onclick="window.open('superadmin_dashboard.php', '_blank')">
                    <div class="card-body text-center">
                        <i class="bi bi-shield-check fs-1 mb-3"></i>
                        <h4>Super Admin Dashboard</h4>
                        <p class="mb-3">Overall Performance Overview</p>
                        
                        <div class="feature-list text-start">
                            <h6><i class="bi bi-check-circle me-2"></i>Key Features:</h6>
                            <ul class="list-unstyled small">
                                <li><i class="bi bi-arrow-right me-2"></i>Total calls across all admins</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Conversion rate analytics</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Time slot performance trends</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Vendor insights & comparison</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Admin performance ranking</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Top/Bottom performer analysis</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Interactive filtering by admin, caller, date</li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-footer text-center border-0 bg-transparent">
                        <small><i class="bi bi-cursor-fill me-2"></i>Click to view dashboard</small>
                    </div>
                </div>
            </div>

            <!-- Admin Dashboard -->
            <div class="col-md-4 mb-4">
                <div class="card dashboard-card admin-card h-100" onclick="window.open('admin_dashboard.php', '_blank')">
                    <div class="card-body text-center">
                        <i class="bi bi-people-fill fs-1 mb-3"></i>
                        <h4>Admin Dashboard</h4>
                        <p class="mb-3">Team Performance Management</p>
                        
                        <div class="feature-list text-start">
                            <h6><i class="bi bi-check-circle me-2"></i>Key Features:</h6>
                            <ul class="list-unstyled small">
                                <li><i class="bi bi-arrow-right me-2"></i>Team calls & conversion metrics</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Telecaller leaderboard</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Top 3 & Bottom 3 performers</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Disposition breakdown analysis</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Best performing time slots</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Vendor performance insights</li>
                                <li><i class="bi bi-arrow-right me-2"></i>7-day trend analysis</li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-footer text-center border-0 bg-transparent">
                        <small><i class="bi bi-cursor-fill me-2"></i>Click to view dashboard</small>
                    </div>
                </div>
            </div>

            <!-- Telecaller Dashboard -->
            <div class="col-md-4 mb-4">
                <div class="card dashboard-card telecaller-card h-100" onclick="showTelecallerOptions()">
                    <div class="card-body text-center">
                        <i class="bi bi-person-circle fs-1 mb-3"></i>
                        <h4>Telecaller Dashboard</h4>
                        <p class="mb-3">Personal Performance Insights</p>
                        
                        <div class="feature-list text-start">
                            <h6><i class="bi bi-check-circle me-2"></i>Key Features:</h6>
                            <ul class="list-unstyled small">
                                <li><i class="bi bi-arrow-right me-2"></i>Today's performance summary</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Personal conversion tracking</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Disposition breakdown</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Time slot efficiency analysis</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Best performing slots identification</li>
                                <li><i class="bi bi-arrow-right me-2"></i>7-day personal trend</li>
                                <li><i class="bi bi-arrow-right me-2"></i>Performance insights & tips</li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-footer text-center border-0 bg-transparent">
                        <small><i class="bi bi-cursor-fill me-2"></i>Click to select telecaller</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Features Overview -->
        <div class="row mt-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="bi bi-star-fill me-2"></i>Dashboard Features Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="bi bi-graph-up me-2 text-primary"></i>Interactive Charts & Visualizations</h6>
                                <ul>
                                    <li>Real-time doughnut charts for disposition breakdown</li>
                                    <li>Bar charts for time slot performance comparison</li>
                                    <li>Line charts for trend analysis over time</li>
                                    <li>Progress bars for performance metrics</li>
                                </ul>

                                <h6><i class="bi bi-funnel me-2 text-success"></i>Advanced Filtering System</h6>
                                <ul>
                                    <li>Filter by admin, telecaller, date, or month</li>
                                    <li>Real-time data updates based on filters</li>
                                    <li>Reset functionality for quick filter clearing</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-speedometer2 me-2 text-warning"></i>Key Performance Indicators</h6>
                                <ul>
                                    <li>Total calls made and connected percentages</li>
                                    <li>Conversion rates and disposition breakdowns</li>
                                    <li>Time slot effectiveness analysis</li>
                                    <li>Vendor and admin performance comparisons</li>
                                </ul>

                                <h6><i class="bi bi-award me-2 text-danger"></i>Performance Insights</h6>
                                <ul>
                                    <li>Top and bottom performer identification</li>
                                    <li>Coaching recommendations for underperformers</li>
                                    <li>Best practice identification from top performers</li>
                                    <li>Personal performance insights and tips</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Technical Features -->
        <div class="row mt-4 mb-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="bi bi-gear-fill me-2"></i>Technical Implementation</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6>Frontend Technologies</h6>
                                <ul class="small">
                                    <li>Bootstrap 5.3 for responsive design</li>
                                    <li>Chart.js for interactive visualizations</li>
                                    <li>Bootstrap Icons for UI elements</li>
                                    <li>Custom CSS gradients and animations</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6>Backend Features</h6>
                                <ul class="small">
                                    <li>PHP 8+ with MySQLi prepared statements</li>
                                    <li>Optimized SQL queries with joins</li>
                                    <li>Real-time data aggregation</li>
                                    <li>Secure user authentication</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6>Data Security</h6>
                                <ul class="small">
                                    <li>Role-based access control</li>
                                    <li>SQL injection prevention</li>
                                    <li>Data sanitization and validation</li>
                                    <li>Session management security</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Telecaller Selection Modal -->
    <div class="modal fade" id="telecallerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Select Telecaller</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Enter a Telecaller ID to view their personal dashboard:</p>
                    <input type="text" id="telecallerIdInput" class="form-control" placeholder="Enter Finqy ID">
                    <small class="text-muted">For demo purposes, you can use any valid finqy_id from the callers table</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="viewTelecallerDashboard()">View Dashboard</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showTelecallerOptions() {
            const modal = new bootstrap.Modal(document.getElementById('telecallerModal'));
            modal.show();
        }

        function viewTelecallerDashboard() {
            const telecallerId = document.getElementById('telecallerIdInput').value.trim();
            if (telecallerId) {
                window.open(`telecaller_dashboard.php?finqy_id=${encodeURIComponent(telecallerId)}`, '_blank');
                bootstrap.Modal.getInstance(document.getElementById('telecallerModal')).hide();
            } else {
                alert('Please enter a valid Telecaller ID');
            }
        }

        // Enter key support for telecaller input
        document.getElementById('telecallerIdInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                viewTelecallerDashboard();
            }
        });
    </script>
</body>
</html>