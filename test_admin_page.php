<?php
require_once 'db_config.php';
requireAdmin();

// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Page Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 bg-dark text-white p-3">
                <h5>Test Sidebar</h5>
                <p>Admin: <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Unknown') ?></p>
                <p>ID: <?= htmlspecialchars($_SESSION['admin_id'] ?? 'Unknown') ?></p>
            </div>
            
            <main class="col-md-10 px-4 py-3">
                <h1>🧪 Admin Page Test</h1>
                
                <div class="alert alert-success">
                    <h4>✅ Success: Page Loading</h4>
                    <p>If you can see this, the basic admin authentication and page structure is working.</p>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5>🔧 Test All Dropdowns</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label"><strong>Status Filter</strong></label>
                                <select class="form-select">
                                    <option>Follow Up</option>
                                    <option>Callback</option>
                                    <option>Not Reachable</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label"><strong>Product Filter</strong></label>
                                <select class="form-select">
                                    <option>All Products</option>
                                    <option>Personal Loan</option>
                                    <option>Credit Card</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label"><strong>Batch Filter</strong></label>
                                <select class="form-select">
                                    <option>All Batches</option>
                                    <option>Batch 001</option>
                                    <option>Batch 002</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label"><strong>Download Scope</strong></label>
                                <select class="form-select">
                                    <option>-- Select Scope --</option>
                                    <option>Selected Batch Only</option>
                                    <option>All Batches</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row g-3 mt-3">
                            <div class="col-md-6">
                                <label class="form-label"><strong>Caller Filter</strong></label>
                                <select class="form-select">
                                    <option>All Callers</option>
                                    <option>Test Caller 1</option>
                                    <option>Test Caller 2</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <button class="btn btn-success mt-4">
                                    🚀 Generate & Download PDF
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5>📊 Test Batches Table</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Batch No</th>
                                    <th>Product</th>
                                    <th>Filename</th>
                                    <th>Records</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="badge bg-primary">BATCH001</span></td>
                                    <td>Personal Loan</td>
                                    <td>test_file_1.xlsx</td>
                                    <td>150</td>
                                    <td><button class="btn btn-danger btn-sm">📄 PDF</button></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-primary">BATCH002</span></td>
                                    <td>Credit Card</td>
                                    <td>test_file_2.xlsx</td>
                                    <td>200</td>
                                    <td><button class="btn btn-danger btn-sm">📄 PDF</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="mt-4">
                    <a href="manage_batches.php" class="btn btn-warning">🔙 Back to Original Manage Batches</a>
                    <a href="simple_manage_batches.php" class="btn btn-info">🧪 Simple Test Page</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>