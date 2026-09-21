-- Public Release Installer Schema
-- Project: Temp Identity Privacy Framework
-- Date: 2026-05-12
-- Purpose: Minimal schema for self-hosted deployments (Hostinger/GoDaddy)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================================
-- Core Admin Tables
-- ============================================================================

DROP TABLE IF EXISTS `admin_sessions`;
DROP TABLE IF EXISTS `security_log`;
DROP TABLE IF EXISTS `email_messages`;
DROP TABLE IF EXISTS `temp_emails`;
DROP TABLE IF EXISTS `domains`;
DROP TABLE IF EXISTS `email_generation_stats`;
DROP TABLE IF EXISTS `system_stats`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `blocked_ips`;
DROP TABLE IF EXISTS `admin_users`;

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` VARBINARY(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `failed_login_attempts` int(11) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `security_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details` longtext DEFAULT NULL,
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'LOW',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_severity` (`severity`),
  CONSTRAINT `security_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Core Email Runtime Tables
-- ============================================================================

CREATE TABLE `domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_name` varchar(255) NOT NULL,
  `imap_server` varchar(255) NOT NULL,
  `imap_port` int(11) DEFAULT 993,
  `imap_username` varchar(255) NOT NULL,
  `imap_password` VARBINARY(255) NOT NULL,
  `imap_password_encrypted` text DEFAULT NULL,
  `protocol` enum('imap') DEFAULT 'imap',
  `use_ssl` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `password_updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_name` (`domain_name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `temp_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email_address` varchar(255) NOT NULL,
  `domain_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT (current_timestamp() + interval 1 hour),
  `is_active` tinyint(1) DEFAULT 1,
  `ip_address` varchar(45) DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_address` (`email_address`),
  KEY `idx_domain_id` (`domain_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_ip_created` (`ip_address`,`created_at`),
  CONSTRAINT `temp_emails_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temp_email_id` int(11) NOT NULL,
  `sender_email` varchar(255) NOT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `subject` text DEFAULT NULL,
  `body_text` longtext DEFAULT NULL,
  `body_html` longtext DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `message_id` varchar(255) DEFAULT NULL,
  `has_attachments` tinyint(1) DEFAULT 0,
  `source_folder` varchar(50) DEFAULT 'INBOX',
  `is_read` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_temp_email_id` (`temp_email_id`),
  KEY `idx_received_at` (`received_at`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_is_read` (`is_read`),
  CONSTRAINT `email_messages_ibfk_1` FOREIGN KEY (`temp_email_id`) REFERENCES `temp_emails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `archived_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `archived_by_user_id` int(11) NOT NULL,
  `email_address` varchar(255) NOT NULL,
  `original_temp_email_id` int(11) DEFAULT NULL,
  `source_message_id` int(11) NOT NULL,
  `sender_email` varchar(255) NOT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `subject` text DEFAULT NULL,
  `body_text` longtext DEFAULT NULL,
  `body_html` longtext DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `has_attachments` tinyint(1) DEFAULT 0,
  `source_folder` varchar(50) DEFAULT 'INBOX',
  `is_read` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_source_message` (`archived_by_user_id`,`source_message_id`),
  KEY `idx_archived_user` (`archived_by_user_id`),
  KEY `idx_archived_email` (`email_address`),
  KEY `idx_archived_at` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_generation_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `emails_generated` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Security and Operational Tables
-- ============================================================================

CREATE TABLE `blocked_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(50) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `blocked_by` varchar(100) DEFAULT 'system',
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_permanent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_is_permanent` (`is_permanent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `endpoint` varchar(255) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `request_count` int(11) DEFAULT 1,
  `window_start` timestamp NULL DEFAULT NULL,
  `blocked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_endpoint` (`endpoint`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stat_name` varchar(100) NOT NULL,
  `stat_value` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_name` (`stat_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `surveillance_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(50) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `added_by` varchar(100) DEFAULT 'admin',
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Seed Data
-- ============================================================================

-- Create your first admin user after generating a valid PHP password hash.
-- Example insert (replace REPLACE_WITH_BCRYPT_HASH):
-- INSERT INTO `admin_users` (`username`, `password_hash`, `email`, `is_active`)
-- VALUES ('admin', 'REPLACE_WITH_BCRYPT_HASH', 'admin@example.com', 1);

INSERT INTO `system_stats` (`stat_name`, `stat_value`) VALUES
('active_email_addresses', 0),
('total_emails_generated', 0),
('total_messages_received', 0),
('total_domains', 0)
ON DUPLICATE KEY UPDATE
`stat_value` = VALUES(`stat_value`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- Dropping IndexNow tables
DROP TABLE IF EXISTS `indexnow_submissions`;
DROP TABLE IF EXISTS `indexnow_batch_log`;
