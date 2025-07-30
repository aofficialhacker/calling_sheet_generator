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
            background-color: #f0f2f5;
        }
        .panel-card {
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .panel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .panel-card .card-body {
            padding: 2.5rem;
        }
        .panel-icon {
            font-size: 4rem;
            color: #0d6efd;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container vh-100 d-flex flex-column justify-content-center">
        <div class="text-center mb-5">
            <h1 class="display-5 fw-bold">AI Calling Sheet System</h1>
            <p class="lead text-muted">Please select your role to proceed.</p>
        </div>

        <div class="row g-5 justify-content-center">
            <!-- Admin Panel Card -->
            <div class="col-lg-5">
                <div class="card panel-card text-center h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="panel-icon"><i class="bi bi-shield-lock-fill"></i></div>
                        <h2 class="card-title h3">Admin Panel</h2>
                        <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                            Upload source files (Excel/CSV) to process data and generate new, printable calling sheets for your team.
                        </p>
                        <a href="admin_panel.php" class="btn btn-primary btn-lg">Enter Admin Panel</a>
                    </div>
                </div>
            </div>

            <!-- Caller Panel Card -->
            <div class="col-lg-5">
                <div class="card panel-card text-center h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="panel-icon"><i class="bi bi-telephone-inbound-fill"></i></div>
                        <h2 class="card-title h3">Caller Panel</h2>
                        <p class="card-text text-muted mt-2 mb-4 flex-grow-1">
                            Upload a photo of a completed sheet to interpret the results using AI and save them to the final log.
                        </p>
                        <a href="caller_panel.php" class="btn btn-info btn-lg text-white">Enter Caller Panel</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-center mt-5">
            <a href="view_final_logs.php" class="btn btn-secondary">
                <i class="bi bi-card-list"></i> View Final Call Logs
            </a>
        </div>
    </div>
</body>
</html>