<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CallFlow Pro - Smart Calling Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 25px;
            padding: 3rem;
            box-shadow: 0 25px 80px rgba(0,0,0,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .panel-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(145deg, #ffffff 0%, #f8f9ff 100%);
        }
        .panel-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        .panel-card .card-body {
            padding: 2.5rem;
            position: relative;
        }
        .panel-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
        }
        .superadmin-icon { 
            background: linear-gradient(45deg, #6f42c1, #8e44ad);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .admin-icon { 
            background: linear-gradient(45deg, #28a745, #20c997);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .caller-icon { 
            background: linear-gradient(45deg, #17a2b8, #007bff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .team-leader-icon { 
            background: linear-gradient(45deg, #6f42c1, #8e44ad);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-title {
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
            letter-spacing: -1px;
        }
        .btn {
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-container">
            <div class="text-center mb-5">
                <div class="mb-4">
                    <i class="bi bi-phone-vibrate-fill display-1 text-primary mb-3"></i>
                </div>
                <h1 class="display-3 hero-title mb-3">LeadVision</h1>
                <p class="lead text-muted">Smart Calling Management & AI-Powered Analytics</p>
                <p class="text-secondary">Streamline your calling operations with intelligent automation and real-time insights</p>
            </div>

            <div class="row g-4 justify-content-center">
                <!-- Superadmin Panel Card -->
                <div class="col-xl-3 col-lg-6">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon superadmin-icon">
                                <i class="bi bi-shield-fill-check"></i>
                            </div>
                            <h3 class="card-title h4 fw-bold">Superadmin Hub</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Complete system oversight with admin management, product catalog control, and comprehensive analytics dashboard.
                            </p>
                            <a href="superadmin_login.php" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Superadmin Login
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Admin Panel Card -->
                <div class="col-xl-3 col-lg-6">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon admin-icon">
                                <i class="bi bi-person-badge-fill"></i>
                            </div>
                            <h3 class="card-title h4 fw-bold">Admin Control</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Manage customer data, generate smart calling sheets, track team performance, and monitor campaign success.
                            </p>
                            <a href="admin_login.php" class="btn btn-success btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Admin Login
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Caller Panel Card -->
                <div class="col-xl-3 col-lg-6">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon caller-icon">
                                <i class="bi bi-telephone-inbound-fill"></i>
                            </div>
                            <h3 class="card-title h4 fw-bold">Caller Portal</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Upload marked sheets for instant AI processing, view personalized analytics, and track your calling achievements.
                            </p>
                            <a href="caller_panel.php" class="btn btn-info btn-lg text-white">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Caller Login
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Team Leader Panel Card -->
                <div class="col-xl-3 col-lg-6">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon team-leader-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <h3 class="card-title h4 fw-bold">Team Leader Hub</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Secure portal for team leaders to review interested leads, take follow-up actions, and manage payment requests with advanced authentication.
                            </p>
                            <a href="team_leader_login.php" class="btn btn-warning btn-lg text-white">
                                <i class="bi bi-shield-lock-fill me-2"></i>Team Leader Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <a href="view_final_logs.php" class="btn btn-outline-primary btn-lg">
                    <i class="bi bi-clipboard-data me-2"></i>View Call Reports
                </a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    