<?php
/**
 * Download Counter Management System
 * Handles tracking and enforcement of download limits for admin users
 */

class DownloadCounter {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Check if admin can download based on current limits
     */
    public function canDownload($adminId, $disposition, $batchId = null, $productCode = null, $callerId = null) {
        try {
            // Get admin's download limit
            $limit = $this->getAdminDownloadLimit($adminId);
            
            // Get current usage for this specific combination
            $currentUsage = $this->getCurrentUsage($adminId, $disposition, $batchId, $productCode, $callerId);
            
            return $currentUsage < $limit;
            
        } catch (Exception $e) {
            error_log("DownloadCounter::canDownload error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Record a download attempt
     */
    public function recordDownload($adminId, $disposition, $batchId = null, $productCode = null, $callerId = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO download_tracking 
                (admin_id, disposition, batch_id, product_code, caller_id, download_count, first_download_at, last_download_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    download_count = download_count + 1,
                    last_download_at = NOW()
            ");
            
            $stmt->bind_param("sssss", $adminId, $disposition, $batchId, $productCode, $callerId);
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("DownloadCounter::recordDownload error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get admin's download limit
     */
    public function getAdminDownloadLimit($adminId) {
        try {
            $stmt = $this->conn->prepare("
                SELECT download_limit 
                FROM admin_users 
                WHERE admin_id = ?
            ");
            
            $stmt->bind_param("s", $adminId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            return $result ? $result['download_limit'] : 5; // default 5 if not found
            
        } catch (Exception $e) {
            error_log("DownloadCounter::getAdminDownloadLimit error: " . $e->getMessage());
            return 5; // default fallback
        }
    }
    
    /**
     * Get current usage count for specific combination
     */
    public function getCurrentUsage($adminId, $disposition, $batchId = null, $productCode = null, $callerId = null) {
        try {
            $stmt = $this->conn->prepare("
                SELECT download_count 
                FROM download_tracking 
                WHERE admin_id = ? AND disposition = ? 
                AND (batch_id = ? OR (batch_id IS NULL AND ? IS NULL))
                AND (product_code = ? OR (product_code IS NULL AND ? IS NULL))
                AND (caller_id = ? OR (caller_id IS NULL AND ? IS NULL))
            ");
            
            $stmt->bind_param("ssssssss", 
                $adminId, $disposition, 
                $batchId, $batchId,
                $productCode, $productCode,
                $callerId, $callerId
            );
            
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            return $result ? $result['download_count'] : 0;
            
        } catch (Exception $e) {
            error_log("DownloadCounter::getCurrentUsage error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get download status for admin dashboard
     */
    public function getDownloadStatus($adminId) {
        try {
            $stmt = $this->conn->prepare("
                SELECT dt.disposition, dt.batch_id, dt.product_code, dt.caller_id,
                       dt.download_count, au.download_limit,
                       (au.download_limit - dt.download_count) as remaining
                FROM download_tracking dt
                JOIN admin_users au ON dt.admin_id = au.admin_id
                WHERE dt.admin_id = ?
                ORDER BY dt.last_download_at DESC
            ");
            
            $stmt->bind_param("s", $adminId);
            $stmt->execute();
            
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
        } catch (Exception $e) {
            error_log("DownloadCounter::getDownloadStatus error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if batch should be excluded from "All Batches" download
     */
    public function shouldExcludeBatch($adminId, $disposition, $batchId) {
        try {
            $limit = $this->getAdminDownloadLimit($adminId);
            $usage = $this->getCurrentUsage($adminId, $disposition, $batchId);
            
            return $usage >= $limit;
            
        } catch (Exception $e) {
            error_log("DownloadCounter::shouldExcludeBatch error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get list of excluded batch IDs for "All Batches" download
     */
    public function getExcludedBatches($adminId, $disposition) {
        try {
            $limit = $this->getAdminDownloadLimit($adminId);
            
            $stmt = $this->conn->prepare("
                SELECT batch_id 
                FROM download_tracking 
                WHERE admin_id = ? AND disposition = ? 
                AND download_count >= ? 
                AND batch_id IS NOT NULL
            ");
            
            $stmt->bind_param("ssi", $adminId, $disposition, $limit);
            $stmt->execute();
            
            $result = $stmt->get_result();
            $excludedBatches = [];
            
            while ($row = $result->fetch_assoc()) {
                $excludedBatches[] = $row['batch_id'];
            }
            
            return $excludedBatches;
            
        } catch (Exception $e) {
            error_log("DownloadCounter::getExcludedBatches error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update admin download limit (for superadmin)
     */
    public function updateAdminLimit($adminId, $newLimit, $superadminId, $notes = null) {
        try {
            $this->conn->begin_transaction();
            
            // Update admin_users table
            $stmt1 = $this->conn->prepare("UPDATE admin_users SET download_limit = ? WHERE admin_id = ?");
            $stmt1->bind_param("is", $newLimit, $adminId);
            $stmt1->execute();
            
            // Update admin_download_limits table
            $stmt2 = $this->conn->prepare("
                INSERT INTO admin_download_limits (admin_id, download_limit, set_by_superadmin, notes)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    download_limit = VALUES(download_limit),
                    set_by_superadmin = VALUES(set_by_superadmin),
                    notes = VALUES(notes),
                    updated_at = NOW()
            ");
            $stmt2->bind_param("siss", $adminId, $newLimit, $superadminId, $notes);
            $stmt2->execute();
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("DownloadCounter::updateAdminLimit error: " . $e->getMessage());
            return false;
        }
    }
}
?>