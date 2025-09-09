<?php
/**
 * Session Manager - Safe session handling for the application
 * Use this instead of calling session_start() directly
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

class SessionManager {
    
    /**
     * Safely start a session with security configuration
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // Initialize session security settings first
            Security::initSessionSecurity();
            
            // Start the session
            session_start();
            
            // Apply additional security measures for active session
            self::handleSessionSecurity();
        }
    }
    
    /**
     * Handle security for already active sessions
     */
    private static function handleSessionSecurity() {
        $sessionConfig = Config::session();
        
        // Session timeout handling
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > $sessionConfig['lifetime']) {
                self::destroy();
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
    
    /**
     * Safely destroy a session
     */
    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            // Delete the session cookie
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            
            session_destroy();
        }
    }
    
    /**
     * Check if session is active
     */
    public static function isActive() {
        return session_status() === PHP_SESSION_ACTIVE;
    }
    
    /**
     * Get session variable safely
     */
    public static function get($key, $default = null) {
        self::start(); // Ensure session is started
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }
    
    /**
     * Set session variable safely
     */
    public static function set($key, $value) {
        self::start(); // Ensure session is started
        $_SESSION[$key] = $value;
    }
    
    /**
     * Check if user is logged in as admin
     */
    public static function isAdmin() {
        return self::get('is_admin', false) === true;
    }
    
    /**
     * Check if user is logged in as superadmin
     */
    public static function isSuperadmin() {
        return self::get('is_superadmin', false) === true;
    }
    
    /**
     * Check if user is logged in as team leader
     */
    public static function isTeamLeader() {
        return self::get('is_team_leader', false) === true;
    }
    
    /**
     * Require admin access (redirect if not admin)
     */
    public static function requireAdmin() {
        if (!self::isAdmin() && !self::isSuperadmin()) {
            header("Location: admin_login.php");
            exit();
        }
    }
    
    /**
     * Require superadmin access (redirect if not superadmin)
     */
    public static function requireSuperadmin() {
        if (!self::isSuperadmin()) {
            header("Location: superadmin_login.php");
            exit();
        }
    }
    
    /**
     * Require team leader access (redirect if not team leader)
     */
    public static function requireTeamLeader() {
        if (!self::isTeamLeader()) {
            header("Location: team_leader_login.php?reason=not_logged_in");
            exit();
        }
        
        // Additional team leader security checks
        self::checkTeamLeaderSession();
    }
    
    /**
     * Check team leader multi-device login
     */
    private static function checkTeamLeaderSession() {
        if (!self::get('leader_id')) {
            header("Location: team_leader_login.php?reason=invalid_user");
            exit();
        }
        
        // Multi-device login check
        try {
            require_once __DIR__ . '/db_config.php';
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT active_session_id FROM team_leaders WHERE leader_id = ?");
            $stmt->bind_param("s", $_SESSION['leader_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['active_session_id'] !== session_id()) {
                    self::destroy();
                    if ($row['active_session_id'] === null || $row['active_session_id'] === '') {
                        header("Location: team_leader_login.php?reason=code_refreshed");
                    } else {
                        header("Location: team_leader_login.php?reason=multi_device");
                    }
                    exit();
                }
            } else {
                self::destroy();
                header("Location: team_leader_login.php?reason=invalid_user");
                exit();
            }
            
            $stmt->close();
            $conn->close();
        } catch (Exception $e) {
            error_log("Session check error: " . $e->getMessage());
            // Continue without breaking the session
        }
    }
}