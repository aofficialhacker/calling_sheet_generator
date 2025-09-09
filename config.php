<?php
/**
 * Production-Ready Configuration Manager
 * Loads environment variables and provides secure configuration management
 */

class Config {
    private static $config = [];
    private static $loaded = false;
    
    /**
     * Load environment configuration
     */
    public static function load() {
        if (self::$loaded) {
            return;
        }
        
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue; // Skip comments
                }
                
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                // Remove quotes if present
                if (preg_match('/^(["\'])(.*)\1$/', $value, $matches)) {
                    $value = $matches[2];
                }
                
                self::$config[$name] = $value;
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
        
        // Set defaults for missing values
        self::setDefaults();
        self::$loaded = true;
    }
    
    /**
     * Get configuration value
     */
    public static function get($key, $default = null) {
        self::load();
        return isset(self::$config[$key]) ? self::$config[$key] : $default;
    }
    
    /**
     * Check if we're in production environment
     */
    public static function isProduction() {
        return self::get('APP_ENV', 'production') === 'production';
    }
    
    /**
     * Check if debug mode is enabled
     */
    public static function isDebug() {
        return filter_var(self::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    }
    
    /**
     * Get database configuration
     */
    public static function database() {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'user' => self::get('DB_USER', 'root'),
            'password' => self::get('DB_PASS', ''),
            'database' => self::get('DB_NAME', 'caller_sheet3')
        ];
    }
    
    /**
     * Get session configuration
     */
    public static function session() {
        return [
            'secure' => filter_var(self::get('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN),
            'httponly' => filter_var(self::get('SESSION_HTTPONLY', 'true'), FILTER_VALIDATE_BOOLEAN),
            'samesite' => self::get('SESSION_SAMESITE', 'Strict'),
            'lifetime' => (int)self::get('SESSION_LIFETIME', 3600), // 1 hour
        ];
    }
    
    /**
     * Set default values for missing configuration
     */
    private static function setDefaults() {
        $defaults = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'SESSION_SECURE' => 'true',
            'SESSION_HTTPONLY' => 'true',
            'SESSION_SAMESITE' => 'Strict',
            'SESSION_LIFETIME' => '3600',
            'MAX_UPLOAD_SIZE' => '10485760', // 10MB
            'ALLOWED_FILE_TYPES' => 'xlsx,xls,jpg,jpeg,png,pdf'
        ];
        
        foreach ($defaults as $key => $value) {
            if (!isset(self::$config[$key])) {
                self::$config[$key] = $value;
            }
        }
    }
    
    /**
     * Validate required configuration
     */
    public static function validate() {
        $required = [
            'DB_HOST',
            'DB_USER', 
            'DB_NAME',
            'GEMINI_API_KEY'
        ];
        
        $missing = [];
        foreach ($required as $key) {
            if (empty(self::get($key))) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            throw new Exception('Missing required configuration: ' . implode(', ', $missing));
        }
    }
    
    /**
     * Generate secure random string
     */
    public static function generateSecureKey($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
}

// Initialize configuration
Config::load();