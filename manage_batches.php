<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// Fetch batches uploaded by this admin
$sql = "SELECT b.id, b.original_filename, b.upload_time, p.product_name, 
               (SELECT COUNT(*) FROM final_call_logs WHERE batch_id = b.id) as record_count
        FROM file_batches b
        JOIN products p ON b.product_code = p.product_code
        WHERE b.admin_id = ?
        ORDER BY b.upload_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $adminId);
$stmt->execute();
$batches_data = $stmt->get_result();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Batches</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1050; display: none; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; animation: spin 1.5s linear infinite; }
        .loading-text { color: white; margin-top: 20px; font-size: 1.2rem; font-weight: bold; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="loading-text" id="loading-message">Generating PDF, please wait...</p></div>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-stack me-2"></i>Manage Batches</h1>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Batch No</th>
                                        <th>Product</th>
                                        <th>Original Filename</th>
                                        <th>Records</th>
                                        <th>Uploaded On</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($batches_data && $batches_data->num_rows > 0): ?>
                                        <?php while($row = $batches_data->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($row['id']) ?></span></td>
                                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                                            <td title="<?= htmlspecialchars($row['original_filename']) ?>"><?= htmlspecialchars(substr($row['original_filename'], 0, 30)) . (strlen($row['original_filename']) > 30 ? '...' : '') ?></td>
                                            <td><?= htmlspecialchars($row['record_count']) ?></td>
                                            <td><?= date('d-M-Y H:i', strtotime($row['upload_time'])) ?></td>
                                            <td>
                                                <a href="generate_pdf.php?batch_id=<?= $row['id'] ?>" class="btn btn-danger btn-sm download-pdf-btn" title="Download PDF for this batch">
                                                    <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center text-muted">No batches have been uploaded yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const startPdfDownload = function(url) {
        const loadingOverlay = document.getElementById('loading-overlay');
        loadingOverlay.style.display = 'flex';
        const downloadToken = new Date().getTime();
        const cookieName = `download_token_${downloadToken}`;
        const finalUrl = url + (url.includes('?') ? '&' : '?') + `download_token=${downloadToken}`;
        window.location.href = finalUrl;
        const timer = setInterval(function() {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${cookieName}=`);
            if (parts.length === 2) {
                loadingOverlay.style.display = 'none';
                clearInterval(timer);
                document.cookie = `${cookieName}=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;`;
            }
        }, 1000);
        setTimeout(() => {
            clearInterval(timer);
            loadingOverlay.style.display = 'none';
        }, 20000);
    };

    document.querySelectorAll('.download-pdf-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            startPdfDownload(this.href);
        });
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
