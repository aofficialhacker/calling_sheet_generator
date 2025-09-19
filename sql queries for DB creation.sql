USE `finqy_dev`;
-- MySQL dump 10.13  Distrib 8.0.42, for Win64 (x86_64)
--
-- Host: localhost    Database: finqy_dev
-- ----------------------------------------------------
-- Server version	8.0.42

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- =====================================================
-- PHASE 1: BASE TABLES (NO DEPENDENCIES)
-- =====================================================

--
-- Table structure for table `admin_users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(10) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `multi_status_selection` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `download_limit` int DEFAULT '5' COMMENT 'Maximum downloads allowed per disposition per batch',
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_id` (`admin_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `callers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_callers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `finqy_id` varchar(50) NOT NULL,
  `caller_name` varchar(255) NOT NULL,
  `caller_type` enum('partner','connector','team') DEFAULT 'partner',
  `mobile_no` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finqy_id` (`finqy_id`),
  KEY `idx_finqy_id` (`finqy_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_caller_type` (`caller_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `disposition_codes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_disposition_codes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `category` enum('connected','not_connected') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_category_active` (`category`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_violations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_security_violations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` varchar(50) NOT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `violation_type` varchar(100) NOT NULL,
  `violation_details` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `page_url` text,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_violation_type` (`violation_type`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=4535 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_read_status`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_notification_read_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `leader_id` varchar(50) NOT NULL,
  `schedule_id` varchar(50) NOT NULL,
  `marked_read_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_read` (`leader_id`,`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- =====================================================
-- PHASE 2: TABLES WITH SINGLE DEPENDENCIES
-- =====================================================

--
-- Table structure for table `vendors`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_vendors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` varchar(10) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `admin_id` varchar(10) NOT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_id` (`vendor_id`),
  UNIQUE KEY `unique_vendor_id` (`vendor_id`),
  KEY `fk_vendor_admin` (`admin_id`),
  CONSTRAINT `fk_vendor_admin` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_disposition_buckets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bucket_name` varchar(100) NOT NULL,
  `description` text,
  `has_calendar_enabled` tinyint(1) DEFAULT '0',
  `created_by` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `bucket_name` (`bucket_name`),
  KEY `idx_bucket_active` (`is_active`),
  KEY `idx_bucket_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_caller_mapping`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_admin_caller_mapping` (
  `map_id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(10) NOT NULL,
  `finqy_id` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`map_id`),
  UNIQUE KEY `unique_mapping` (`admin_id`,`finqy_id`),
  KEY `idx_admin_finqy` (`admin_id`,`finqy_id`),
  CONSTRAINT `fk_acm_admin` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_download_limits`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_admin_download_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(20) NOT NULL,
  `download_limit` int NOT NULL DEFAULT '0',
  `created_by` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_admin_limit` (`admin_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `admin_download_limits_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `admin_download_limits_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `lv_admin_users` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `blocklist_numbers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_blocklist_numbers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(50) NOT NULL,
  `mobile_no` varchar(15) NOT NULL,
  `batch_id` varchar(100) DEFAULT NULL,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) NOT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_admin_mobile` (`admin_id`,`mobile_no`),
  KEY `idx_admin_mobile` (`admin_id`,`mobile_no`),
  KEY `idx_mobile` (`mobile_no`),
  KEY `idx_admin_batch` (`admin_id`,`batch_id`),
  CONSTRAINT `blocklist_numbers_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `download_tracking`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_download_tracking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(10) NOT NULL,
  `disposition` varchar(100) NOT NULL,
  `batch_id` varchar(50) DEFAULT NULL COMMENT 'NULL means all batches restriction',
  `product_code` varchar(50) DEFAULT NULL,
  `caller_id` varchar(50) DEFAULT NULL,
  `download_count` int DEFAULT '0',
  `first_download_at` timestamp NULL DEFAULT NULL,
  `last_download_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tracking` (`admin_id`,`disposition`,`batch_id`,`product_code`,`caller_id`),
  KEY `idx_admin_disposition` (`admin_id`,`disposition`),
  KEY `idx_batch_tracking` (`batch_id`),
  KEY `idx_product_tracking` (`product_code`),
  CONSTRAINT `download_tracking_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tracks download usage against limits set by superadmin';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_requests`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_vendor_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(10) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `is_additional` tinyint(1) DEFAULT '0' COMMENT '1 for additional vendor requests (V61+), 0 for default',
  PRIMARY KEY (`id`),
  KEY `idx_admin_status` (`admin_id`,`status`),
  CONSTRAINT `fk_vr_admin` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team_leaders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_team_leaders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `leader_id` varchar(20) NOT NULL,
  `leader_name` varchar(100) NOT NULL,
  `finqy_id` varchar(20) NOT NULL,
  `admin_id` varchar(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `auth_token` varchar(255) DEFAULT NULL,
  `token_expires` timestamp NULL DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `active_session_id` varchar(255) DEFAULT NULL,
  `login_attempts` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `access_code` varchar(6) DEFAULT NULL,
  `code_generated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leader_id` (`leader_id`),
  UNIQUE KEY `username` (`username`),
  KEY `finqy_id` (`finqy_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `team_leaders_ibfk_1` FOREIGN KEY (`finqy_id`) REFERENCES `lv_callers` (`finqy_id`),
  CONSTRAINT `team_leaders_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- =====================================================
-- PHASE 3: TABLES WITH MULTIPLE DEPENDENCIES
-- =====================================================

--
-- Table structure for table `file_batches`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_file_batches` (
  `id` varchar(50) NOT NULL,
  `admin_id` varchar(10) NOT NULL,
  `vendor_id` varchar(10) NOT NULL,
  `product_code` varchar(50) NOT NULL,
  `original_batch_id` varchar(50) DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `upload_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_batch_admin` (`admin_id`),
  KEY `fk_batch_vendor` (`vendor_id`),
  KEY `idx_original_batch` (`original_batch_id`),
  CONSTRAINT `fk_batch_admin` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_batch_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `lv_vendors` (`vendor_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team_leader_dispositions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_team_leader_dispositions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `disposition_name` varchar(100) NOT NULL,
  `description` text,
  `bucket_id` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `disposition_name` (`disposition_name`),
  KEY `created_by` (`created_by`),
  KEY `idx_bucket_id` (`bucket_id`),
  CONSTRAINT `team_leader_dispositions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `lv_admin_users` (`admin_id`),
  CONSTRAINT `team_leader_dispositions_ibfk_2` FOREIGN KEY (`bucket_id`) REFERENCES `lv_disposition_buckets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team_leader_logins`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_team_leader_logins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `leader_id` varchar(20) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text,
  `login_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `login_status` enum('success','failed') DEFAULT 'success',
  `session_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leader_id` (`leader_id`),
  CONSTRAINT `team_leader_logins_ibfk_1` FOREIGN KEY (`leader_id`) REFERENCES `lv_team_leaders` (`leader_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- =====================================================
-- PHASE 4: COMPLEX DEPENDENCIES
-- =====================================================

--
-- Table structure for table `final_call_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_final_call_logs` (
  `id` varchar(50) NOT NULL,
  `batch_id` varchar(50) DEFAULT NULL,
  `mobile_no` varchar(20) NOT NULL,
  `title` varchar(50) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `policy_number` varchar(100) DEFAULT NULL,
  `pan` varchar(20) DEFAULT NULL,
  `dob` varchar(20) DEFAULT NULL,
  `age` int DEFAULT NULL,
  `expiry` varchar(20) DEFAULT NULL,
  `address` text,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `plan` varchar(255) DEFAULT NULL,
  `premium` varchar(50) DEFAULT NULL,
  `sum_insured` varchar(50) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'fresh',
  `extra_data` json DEFAULT NULL,
  `connectivity` varchar(10) DEFAULT NULL,
  `disposition` varchar(50) DEFAULT NULL,
  `slot` int DEFAULT NULL,
  `finqy_id` varchar(50) DEFAULT NULL,
  `original_caller_id` varchar(50) DEFAULT NULL COMMENT 'First caller who worked on this record',
  `redistribution_count` int DEFAULT '0' COMMENT 'How many times this record has been redistributed',
  `last_updated_by` varchar(50) DEFAULT NULL COMMENT 'Last caller who updated this record',
  `is_redistributed` tinyint(1) DEFAULT '0' COMMENT 'Whether this record has been redistributed',
  `last_attempt_date` datetime DEFAULT NULL COMMENT 'Date of last call attempt',
  `processed_at` datetime DEFAULT NULL,
  `total_attempts` int DEFAULT '0',
  `data_backup_confirmed` tinyint(1) DEFAULT '0',
  `last_backup_at` datetime DEFAULT NULL,
  `first_attempt_date` datetime DEFAULT NULL,
  `follow_day` int DEFAULT NULL COMMENT 'Days to add for follow-up date (1-9 days from call date)',
  `follow_slot` int DEFAULT NULL COMMENT 'Preferred slot for follow-up call (1-8 time slots)',
  PRIMARY KEY (`id`),
  KEY `mobile_no` (`mobile_no`),
  KEY `batch_id` (`batch_id`),
  KEY `finqy_id` (`finqy_id`),
  KEY `idx_batch_id` (`batch_id`),
  KEY `idx_finqy_id` (`finqy_id`),
  KEY `idx_processed_at` (`processed_at`),
  KEY `idx_disposition` (`disposition`),
  KEY `idx_mobile_batch` (`mobile_no`,`batch_id`),
  KEY `idx_finqy_processed` (`finqy_id`,`processed_at`),
  CONSTRAINT `fk_log_batch` FOREIGN KEY (`batch_id`) REFERENCES `lv_file_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `call_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_call_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `original_record_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'References final_call_logs.id',
  `finqy_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Caller who made this attempt',
  `attempt_number` int NOT NULL DEFAULT '1' COMMENT 'Sequential attempt counter for this record',
  `batch_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Which batch this attempt belongs to',
  `disposition` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Disposition marked by caller',
  `slot` int DEFAULT NULL COMMENT 'Time slot marked by caller',
  `connectivity` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Connectivity status marked',
  `attempt_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this attempt was made',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Additional notes or comments',
  `is_original_attempt` tinyint(1) DEFAULT '0' COMMENT 'TRUE if this was the first attempt on this record',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `call_duration` int DEFAULT NULL COMMENT 'Duration of the call in minutes',
  `customer_response` text COLLATE utf8mb4_unicode_ci COMMENT 'Customer specific response',
  `follow_day` int DEFAULT NULL COMMENT 'Days for follow-up (preserved from final_call_logs)',
  `follow_slot` int DEFAULT NULL COMMENT 'Follow-up time slot (preserved from final_call_logs)',
  PRIMARY KEY (`id`),
  KEY `idx_original_record` (`original_record_id`),
  KEY `idx_caller` (`finqy_id`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_attempt_date` (`attempt_date`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_download_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_admin_download_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(20) NOT NULL,
  `disposition_status` varchar(100) NOT NULL,
  `scope_type` enum('batch-wise','all-batch','product-wise','all-product') NOT NULL,
  `batch_id` varchar(50) DEFAULT NULL,
  `product_code` varchar(20) DEFAULT NULL,
  `caller_id` varchar(20) DEFAULT NULL,
  `download_token` varchar(50) NOT NULL,
  `records_count` int DEFAULT '0',
  `pdf_filename` varchar(255) DEFAULT NULL,
  `download_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text,
  `status` enum('success','failed','limit_exceeded') DEFAULT 'success',
  `error_message` text,
  PRIMARY KEY (`id`),
  KEY `caller_id` (`caller_id`),
  KEY `idx_admin_downloads` (`admin_id`,`download_time`),
  KEY `idx_download_token` (`download_token`),
  KEY `idx_download_history_admin_time` (`admin_id`,`download_time` DESC),
  CONSTRAINT `admin_download_history_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `admin_download_history_ibfk_2` FOREIGN KEY (`caller_id`) REFERENCES `lv_callers` (`finqy_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_download_tracking`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_admin_download_tracking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(20) NOT NULL,
  `disposition_status` varchar(100) NOT NULL,
  `batch_id` varchar(50) DEFAULT NULL,
  `product_code` varchar(20) DEFAULT NULL,
  `scope_type` enum('batch-wise','all-batch','product-wise','all-product') NOT NULL DEFAULT 'batch-wise',
  `download_count` int DEFAULT '0',
  `last_download` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tracking` (`admin_id`,`disposition_status`,`batch_id`,`product_code`,`scope_type`),
  KEY `batch_id` (`batch_id`),
  KEY `product_code` (`product_code`),
  KEY `idx_admin_tracking_status` (`admin_id`,`disposition_status`),
  KEY `idx_admin_tracking_batch` (`admin_id`,`batch_id`),
  KEY `idx_admin_tracking_product` (`admin_id`,`product_code`),
  CONSTRAINT `admin_download_tracking_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `lv_admin_users` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `admin_download_tracking_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `lv_file_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admin_download_tracking_ibfk_3` FOREIGN KEY (`product_code`) REFERENCES `lv_products` (`product_code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `follow_up_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_follow_up_schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `schedule_id` varchar(50) NOT NULL,
  `lead_id` varchar(50) NOT NULL,
  `leader_id` varchar(20) NOT NULL,
  `disposition_name` varchar(100) NOT NULL,
  `bucket_id` int NOT NULL,
  `follow_up_datetime` datetime NOT NULL,
  `status` enum('scheduled','completed','cancelled','overdue') DEFAULT 'scheduled',
  `completed_at` timestamp NULL DEFAULT NULL,
  `delay_minutes` int DEFAULT NULL COMMENT 'Delay in minutes from scheduled time to completion',
  `completed_by` varchar(50) DEFAULT NULL COMMENT 'User ID who marked as completed',
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_id` (`schedule_id`),
  KEY `idx_leader_id` (`leader_id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_bucket_id` (`bucket_id`),
  KEY `idx_follow_up_datetime` (`follow_up_datetime`),
  KEY `idx_status` (`status`),
  CONSTRAINT `follow_up_schedules_ibfk_1` FOREIGN KEY (`bucket_id`) REFERENCES `lv_disposition_buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `follow_up_notifications`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_follow_up_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `schedule_id` int NOT NULL,
  `notification_type` enum('immediate','1_hour','1_day') DEFAULT 'immediate',
  `scheduled_time` datetime NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `next_attempt` datetime DEFAULT NULL,
  `attempt_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedule_id` (`schedule_id`),
  KEY `idx_scheduled_time` (`scheduled_time`),
  KEY `idx_status` (`status`),
  CONSTRAINT `follow_up_notifications_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `lv_follow_up_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team_leader_actions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_team_leader_actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action_id` varchar(50) NOT NULL,
  `leader_id` varchar(20) NOT NULL,
  `lead_id` varchar(50) NOT NULL,
  `original_disposition` varchar(50) DEFAULT NULL,
  `new_disposition` varchar(100) DEFAULT NULL,
  `remarks` text,
  `action_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `session_id` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `action_id` (`action_id`),
  KEY `leader_id` (`leader_id`),
  KEY `lead_id` (`lead_id`),
  KEY `new_disposition` (`new_disposition`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_action_date` (`action_date`),
  CONSTRAINT `team_leader_actions_ibfk_1` FOREIGN KEY (`leader_id`) REFERENCES `lv_team_leaders` (`leader_id`),
  CONSTRAINT `team_leader_actions_ibfk_2` FOREIGN KEY (`lead_id`) REFERENCES `lv_final_call_logs` (`id`),
  CONSTRAINT `team_leader_actions_ibfk_3` FOREIGN KEY (`new_disposition`) REFERENCES `lv_team_leader_dispositions` (`disposition_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team_leader_view_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lv_team_leader_view_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `leader_id` varchar(50) NOT NULL,
  `lead_id` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `session_id` varchar(100) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leader_id` (`leader_id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- =====================================================
-- TRIGGERS
-- =====================================================

/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `after_followup_insert` AFTER INSERT ON `follow_up_schedules` FOR EACH ROW BEGIN INSERT INTO lv_follow_up_notifications (schedule_id, notification_type, scheduled_time) VALUES (NEW.id, 'immediate', NEW.follow_up_datetime); INSERT INTO lv_follow_up_notifications (schedule_id, notification_type, scheduled_time) VALUES (NEW.id, '1_hour', DATE_SUB(NEW.follow_up_datetime, INTERVAL 1 HOUR)); END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;



-- Dump completed on 2025-09-12 17:17:26