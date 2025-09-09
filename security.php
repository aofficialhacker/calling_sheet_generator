<?php
/**
 * Security Manager for Production Environment
 * Handles HTTPS enforcement, security headers, and secure session management
 */

require_once __DIR__ . '/config.php';

class Security {
    
    /**
     * Initialize security settings for production
     */
    public static function init() {
        // Force HTTPS in production
        if (Config::isProduction()) {
            self::enforceHTTPS();
        }
        
        // Set security headers
        self::setSecurityHeaders();
        
        // Configure error reporting for production
        self::configureErrorReporting();
        
        // Note: Session security is handled by SessionManager::start()
    }
    
    /**
     * Initialize session security settings before starting any session
     * Call this before session_start() in any file
     */
    public static function initSessionSecurity() {
        if (session_status() === PHP_SESSION_NONE) {
            $sessionConfig = Config::session();
            
            // Configure session security settings
            ini_set('session.cookie_httponly', $sessionConfig['httponly'] ? '1' : '0');
            ini_set('session.cookie_secure', $sessionConfig['secure'] ? '1' : '0');
            ini_set('session.cookie_samesite', $sessionConfig['samesite']);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_lifetime', $sessionConfig['lifetime']);
            ini_set('session.gc_maxlifetime', $sessionConfig['lifetime']);
        }
    }
    
    /**
     * Enforce HTTPS connections
     */
    public static function enforceHTTPS() {
        if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
            $redirect_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header("Location: $redirect_url", true, 301);
            exit();
        }
    }
    
    /**
     * Configure secure session settings
     */
    public static function configureSecureSessions() {
        $sessionConfig = Config::session();
        
        // Only configure session settings if session is not active
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session security BEFORE starting session
            ini_set('session.cookie_httponly', $sessionConfig['httponly'] ? '1' : '0');
            ini_set('session.cookie_secure', $sessionConfig['secure'] ? '1' : '0');
            ini_set('session.cookie_samesite', $sessionConfig['samesite']);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_lifetime', $sessionConfig['lifetime']);
        }
        
        // Handle active sessions
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Session timeout handling for active sessions
            if (isset($_SESSION['last_activity'])) {
                if (time() - $_SESSION['last_activity'] > $sessionConfig['lifetime']) {
                    session_unset();
                    session_destroy();
                    header('Location: index.php?timeout=1');
                    exit();
                }
            }
            $_SESSION['last_activity'] = time();
            
            // Regenerate session ID periodically to prevent fixation attacks
            if (!isset($_SESSION['regenerated']) || (time() - $_SESSION['regenerated']) > 300) { // Every 5 minutes
                session_regenerate_id(true);
                $_SESSION['regenerated'] = time();
            }
        }
    }
    
    /**
     * Set security headers
     */
    public static function setSecurityHeaders() {
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy (restrictive)
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
               "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
               "font-src 'self' https://cdn.jsdelivr.net; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self'; " .
               "form-action 'self'; " .
               "base-uri 'self'; " .
               "object-src 'none';";
        header("Content-Security-Policy: $csp");
        
        // HSTS header for HTTPS enforcement
        if (Config::isProduction() && isset($_SERVER['HTTPS'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }
    
    /**
     * Configure error reporting for production
     */
    public static function configureErrorReporting() {
        if (Config::isProduction()) {
            // Disable error display in production
            error_reporting(E_ALL);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', __DIR__ . '/logs/php_errors.log');
        } else {
            // Enable detailed errors in development
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        }
    }
    
    /**
     * Sanitize user input
     */
    public static function sanitizeInput($input, $type = 'string') {
        if (is_array($input)) {
            return array_map(function($item) use ($type) {
                return self::sanitizeInput($item, $type);
            }, $input);
        }
        
        switch ($type) {
            case 'email':
                return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
            case 'int':
                return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return (float) filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'url':
                return filter_var(trim($input), FILTER_SANITIZE_URL);
            case 'string':
            default:
                return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    
    /**
     * Validate file upload
     */
    public static function validateFileUpload($file, $allowedTypes = null, $maxSize = null) {
        if (!$allowedTypes) {
            $allowedTypes = explode(',', Config::get('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,pdf,xlsx,xls'));
        }
        
        if (!$maxSize) {
            $maxSize = (int) Config::get('MAX_UPLOAD_SIZE', 10485760); // 10MB default
        }
        
        $errors = [];
        
        // Check file was uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'No file uploaded or file upload failed.';
            return $errors;
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size (' . number_format($maxSize/1024/1024, 1) . 'MB).';
        }
        
        // Check file type
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedTypes)) {
            $errors[] = 'File type not allowed. Allowed types: ' . implode(', ', $allowedTypes);
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimeTypes = [
            'image/jpeg', 'image/png', 'image/gif',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel' // xls
        ];
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $errors[] = 'Invalid file type detected.';
        }
        
        return $errors;
    }
    
    /**
     * Generate secure random token
     */
    public static function generateSecureToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Rate limiting check
     */
    public static function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 300) {
        $logFile = __DIR__ . '/logs/rate_limit.log';
        
        // Create logs directory if it doesn't exist
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0750, true);
        }
        
        $currentTime = time();
        $attempts = [];
        
        // Read existing attempts
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $attempts = json_decode($content, true) ?: [];
        }
        
        // Clean old attempts
        if (isset($attempts[$identifier])) {
            $attempts[$identifier] = array_filter($attempts[$identifier], function($timestamp) use ($currentTime, $timeWindow) {
                return ($currentTime - $timestamp) < $timeWindow;
            });
        }
        
        // Check rate limit
        if (isset($attempts[$identifier]) && count($attempts[$identifier]) >= $maxAttempts) {
            return false; // Rate limit exceeded
        }
        
        // Record current attempt
        $attempts[$identifier][] = $currentTime;
        
        // Save back to file
        file_put_contents($logFile, json_encode($attempts), LOCK_EX);
        
        return true; // Within rate limit
    }
}

// Auto-initialize security on include
Security::init();