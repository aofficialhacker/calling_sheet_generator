<?php
/**
 * Production Deployment Script
 * This script prepares the application for production deployment
 * 
 * WARNING: This will remove debug files and sensitive information
 * Only run this on production deployment, not development environments
 */

require_once 'config.php';

class ProductionDeployer {
    
    private $baseDir;
    private $backupDir;
    private $logFile;
    
    public function __construct() {
        $this->baseDir = __DIR__;
        $this->backupDir = $this->baseDir . '/deployment_backup_' . date('Y-m-d_H-i-s');
        $this->logFile = $this->baseDir . '/logs/deployment.log';
        
        // Ensure logs directory exists
        if (!is_dir($this->baseDir . '/logs')) {
            mkdir($this->baseDir . '/logs', 0750, true);
        }
    }
    
    public function deploy() {
        $this->log("=== Starting Production Deployment ===");
        
        try {
            $this->validateEnvironment();
            $this->createBackup();
            $this->removeDebugFiles();
            $this->removeTestFiles();
            $this->removeUnnecessaryFiles();
            $this->setProperPermissions();
            $this->validateDeployment();
            
            $this->log("=== Production Deployment Completed Successfully ===");
            echo "✅ Production deployment completed successfully!\n";
            echo "📁 Backup created at: {$this->backupDir}\n";
            echo "📋 Deployment log: {$this->logFile}\n";
            
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            echo "❌ Deployment failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function validateEnvironment() {
        $this->log("Validating environment...");
        
        // Check if .env file exists
        if (!file_exists($this->baseDir . '/.env')) {
            throw new Exception(".env file not found. Please create .env file from .env.example");
        }
        
        // Validate required environment variables
        try {
            Config::validate();
            $this->log("✓ Environment variables validated");
        } catch (Exception $e) {
            throw new Exception("Environment validation failed: " . $e->getMessage());
        }
        
        // Check if we're in production mode
        if (!Config::isProduction()) {
            throw new Exception("APP_ENV must be set to 'production' in .env file");
        }
        
        $this->log("✓ Environment validation passed");
    }
    
    private function createBackup() {
        $this->log("Creating deployment backup...");
        
        if (!mkdir($this->backupDir, 0750, true)) {
            throw new Exception("Failed to create backup directory");
        }
        
        // Backup important files
        $filesToBackup = [
            'config.php',
            'db_config.php',
            'security.php',
            '.env.example'
        ];
        
        foreach ($filesToBackup as $file) {
            if (file_exists($this->baseDir . '/' . $file)) {
                copy($this->baseDir . '/' . $file, $this->backupDir . '/' . $file);
            }
        }
        
        $this->log("✓ Backup created at: {$this->backupDir}");
    }
    
    private function removeDebugFiles() {
        $this->log("Removing debug files...");
        
        $debugPatterns = [
            'debug_*.php',
            '*_debug.php'
        ];
        
        $removedCount = 0;
        foreach ($debugPatterns as $pattern) {
            $files = glob($this->baseDir . '/' . $pattern);
            foreach ($files as $file) {
                if (unlink($file)) {
                    $this->log("Removed debug file: " . basename($file));
                    $removedCount++;
                }
            }
        }
        
        $this->log("✓ Removed {$removedCount} debug files");
    }
    
    private function removeTestFiles() {
        $this->log("Removing test files...");
        
        $testPatterns = [
            'test_*.php',
            '*_test.php'
        ];
        
        $removedCount = 0;
        foreach ($testPatterns as $pattern) {
            $files = glob($this->baseDir . '/' . $pattern);
            foreach ($files as $file) {
                if (unlink($file)) {
                    $this->log("Removed test file: " . basename($file));
                    $removedCount++;
                }
            }
        }
        
        $this->log("✓ Removed {$removedCount} test files");
    }
    
    private function removeUnnecessaryFiles() {
        $this->log("Removing unnecessary files...");
        
        $filesToRemove = [
            'algo.txt',
            'modifications.txt',
            'NEW version algo.txt',
            'modifications to be made 21 08 2025.txt',
            'callers dashboard.txt',
            'changes.txt',
            'Dashboards.docx',
            'TELEBAR OCR BRDD.docx',
            'php_server.log',
            'ocr_extraction_log.txt'
        ];
        
        $removedCount = 0;
        foreach ($filesToRemove as $file) {
            $fullPath = $this->baseDir . '/' . $file;
            if (file_exists($fullPath) && unlink($fullPath)) {
                $this->log("Removed unnecessary file: {$file}");
                $removedCount++;
            }
        }
        
        // Remove backup PDF generation files
        $backupPdfFiles = glob($this->baseDir . '/generate_pdf_backup*.php');
        foreach ($backupPdfFiles as $file) {
            if (unlink($file)) {
                $this->log("Removed backup PDF file: " . basename($file));
                $removedCount++;
            }
        }
        
        $this->log("✓ Removed {$removedCount} unnecessary files");
    }
    
    private function setProperPermissions() {
        $this->log("Setting proper file permissions...");
        
        // Set directory permissions
        $directories = [
            'logs' => 0750,
            'uploads' => 0750,
            'vendor' => 0755
        ];
        
        foreach ($directories as $dir => $perm) {
            $fullPath = $this->baseDir . '/' . $dir;
            if (is_dir($fullPath)) {
                chmod($fullPath, $perm);
                $this->log("Set permissions {$perm} for directory: {$dir}");
            }
        }
        
        // Set file permissions
        $sensitiveFiles = [
            '.env' => 0600,
            'config.php' => 0644,
            'db_config.php' => 0644,
            'security.php' => 0644
        ];
        
        foreach ($sensitiveFiles as $file => $perm) {
            $fullPath = $this->baseDir . '/' . $file;
            if (file_exists($fullPath)) {
                chmod($fullPath, $perm);
                $this->log("Set permissions {$perm} for file: {$file}");
            }
        }
        
        $this->log("✓ File permissions configured");
    }
    
    private function validateDeployment() {
        $this->log("Validating deployment...");
        
        // Check critical files exist
        $criticalFiles = [
            'index.php',
            'config.php', 
            'db_config.php',
            'security.php',
            '.env'
        ];
        
        foreach ($criticalFiles as $file) {
            if (!file_exists($this->baseDir . '/' . $file)) {
                throw new Exception("Critical file missing after deployment: {$file}");
            }
        }
        
        // Verify no debug files remain
        $remainingDebugFiles = array_merge(
            glob($this->baseDir . '/debug_*.php'),
            glob($this->baseDir . '/test_*.php')
        );
        
        if (!empty($remainingDebugFiles)) {
            $this->log("WARNING: Some debug/test files remain: " . implode(', ', $remainingDebugFiles));
        }
        
        $this->log("✓ Deployment validation passed");
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        echo $logEntry;
    }
}

// Check if running from command line
if (php_sapi_name() === 'cli') {
    $deployer = new ProductionDeployer();
    $deployer->deploy();
} else {
    // Web interface with confirmation
    if (isset($_POST['confirm_deployment'])) {
        if ($_POST['confirm_deployment'] === 'YES_DEPLOY_TO_PRODUCTION') {
            $deployer = new ProductionDeployer();
            $deployer->deploy();
        } else {
            echo "❌ Deployment cancelled - confirmation text incorrect\n";
        }
    } else {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Production Deployment</title>
            <style>
                body { font-family: monospace; max-width: 800px; margin: 50px auto; padding: 20px; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; }
                .danger { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 20px 0; }
                input, button { padding: 10px; margin: 10px 0; }
                .code { background: #f8f9fa; padding: 10px; border-left: 3px solid #007bff; }
            </style>
        </head>
        <body>
            <h1>🚀 Production Deployment</h1>
            
            <div class="danger">
                <strong>⚠️ WARNING: This will permanently modify your application!</strong>
                <p>This deployment script will:</p>
                <ul>
                    <li>Remove ALL debug and test files</li>
                    <li>Remove unnecessary documentation files</li>
                    <li>Set production file permissions</li>
                    <li>Validate production configuration</li>
                </ul>
                <p><strong>This action cannot be undone!</strong></p>
            </div>
            
            <div class="warning">
                <strong>Prerequisites:</strong>
                <ul>
                    <li>✅ .env file configured with production values</li>
                    <li>✅ APP_ENV set to 'production'</li>
                    <li>✅ Database credentials secured</li>
                    <li>✅ API keys moved to environment variables</li>
                    <li>✅ Full backup of application created</li>
                </ul>
            </div>
            
            <div class="code">
                <strong>Recommended: Run from command line instead</strong><br>
                <code>php deploy_production.php</code>
            </div>
            
            <form method="POST">
                <p><strong>Type exactly:</strong> <code>YES_DEPLOY_TO_PRODUCTION</code></p>
                <input type="text" name="confirm_deployment" placeholder="Confirmation text" style="width: 300px;" />
                <br>
                <button type="submit" style="background: #dc3545; color: white; border: none; padding: 15px 30px;">
                    🚀 Deploy to Production
                </button>
            </form>
        </body>
        </html>
        <?php
    }
}
?>