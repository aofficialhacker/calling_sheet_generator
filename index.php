<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Calling Sheet System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .main-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .panel-card {
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-radius: 15px;
        }
        .panel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .panel-card .card-body {
            padding: 2rem;
        }
        .panel-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .superadmin-icon { color: #6f42c1; }
        .admin-icon { color: #28a745; }
        .caller-icon { color: #17a2b8; }
        .hero-title {
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-container">
            <div class="text-center mb-5">
                <h1 class="display-4 hero-title">AI Calling Sheet System</h1>
                <p class="lead text-muted">Streamline your calling operations with AI-powered automation</p>
            </div>

            <div class="row g-4 justify-content-center">
                <!-- Superadmin Panel Card -->
                <div class="col-lg-4">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon superadmin-icon">
                                <i class="bi bi-shield-fill-check"></i>
                            </div>
                            <h3 class="card-title h4">Superadmin Panel</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Complete system control. Manage admins, products, dispositions, and monitor global performance.
                            </p>
                            <a href="superadmin_login.php" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Superadmin Login
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Admin Panel Card -->
                <div class="col-lg-4">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon admin-icon">
                                <i class="bi bi-person-badge-fill"></i>
                            </div>
                            <h3 class="card-title h4">Admin Panel</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Upload customer data, generate calling sheets, and monitor caller performance.
                            </p>
                            <a href="admin_login.php" class="btn btn-success btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Admin Login
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Caller Panel Card -->
                <div class="col-lg-4">
                    <div class="card panel-card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="panel-icon caller-icon">
                                <i class="bi bi-telephone-inbound-fill"></i>
                            </div>
                            <h3 class="card-title h4">Caller Panel</h3>
                            <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                                Upload completed sheets for AI processing and track your performance.
                            </p>
                            <a href="caller_panel.php" class="btn btn-info btn-lg text-white">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Caller Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <a href="view_final_logs.php" class="btn btn-outline-secondary">
                    <i class="bi bi-card-list me-2"></i>View Final Call Logs
                </a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    