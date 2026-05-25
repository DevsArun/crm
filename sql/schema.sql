-- ============================================================
-- WhatsApp CRM + Cold Outreach OS — Database Schema
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+05:30';

-- ============================================================
-- TABLE: leads
-- ============================================================
CREATE TABLE IF NOT EXISTS `leads` (
  `id`                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `business_name`       VARCHAR(255)      NOT NULL,
  `address`             TEXT              DEFAULT NULL,
  `locality`            VARCHAR(150)      DEFAULT NULL,
  `city`                VARCHAR(100)      DEFAULT NULL,
  `state`               VARCHAR(100)      DEFAULT NULL,
  `phone_number`        VARCHAR(20)       NOT NULL,
  `phone_normalized`    VARCHAR(20)       NOT NULL COMMENT 'E.164 format: 91XXXXXXXXXX',
  `website_url`         VARCHAR(500)      DEFAULT NULL,
  `website_status`      ENUM('has_website','no_website') NOT NULL DEFAULT 'no_website',
  `rating`              DECIMAL(3,1)      DEFAULT NULL,
  `review_count`        INT UNSIGNED      DEFAULT 0,
  `whatsapp_status`     ENUM('pending','valid','invalid','not_on_whatsapp','failed') NOT NULL DEFAULT 'pending',
  `outreach_status`     ENUM('pending','queued','sent','replied','failed','skipped') NOT NULL DEFAULT 'pending',
  `pitch_type`          ENUM('type_a','type_b') DEFAULT NULL COMMENT 'type_a=has website, type_b=no website',
  `language_preference` VARCHAR(50)       DEFAULT 'english',
  `generated_message`   TEXT              DEFAULT NULL COMMENT 'AI generated first outreach message',
  `ai_reasoning`        TEXT              DEFAULT NULL COMMENT 'Groq reasoning/notes for this lead',
  `tags`                JSON              DEFAULT NULL,
  `notes`               TEXT              DEFAULT NULL,
  `last_contacted_at`   DATETIME          DEFAULT NULL,
  `replied_at`          DATETIME          DEFAULT NULL,
  `campaign_id`         INT UNSIGNED      DEFAULT NULL,
  `is_pinned`           TINYINT(1)        NOT NULL DEFAULT 0,
  `is_archived`         TINYINT(1)        NOT NULL DEFAULT 0,
  `created_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone_normalized` (`phone_normalized`),
  KEY `idx_whatsapp_status`  (`whatsapp_status`),
  KEY `idx_outreach_status`  (`outreach_status`),
  KEY `idx_city`             (`city`),
  KEY `idx_state`            (`state`),
  KEY `idx_pitch_type`       (`pitch_type`),
  KEY `idx_campaign_id`      (`campaign_id`),
  KEY `idx_is_pinned`        (`is_pinned`),
  KEY `idx_last_contacted`   (`last_contacted_at`),
  KEY `idx_created_at`       (`created_at`),
  FULLTEXT KEY `ft_search`   (`business_name`, `locality`, `city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: messages
-- ============================================================
CREATE TABLE IF NOT EXISTS `messages` (
  `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `lead_id`         INT UNSIGNED      NOT NULL,
  `sender`          ENUM('user','lead') NOT NULL,
  `message_text`    TEXT              NOT NULL,
  `wa_message_id`   VARCHAR(100)      DEFAULT NULL COMMENT 'WhatsApp message ID for deduplication',
  `direction`       ENUM('inbound','outbound') NOT NULL,
  `is_read`         TINYINT(1)        NOT NULL DEFAULT 0,
  `status`          ENUM('pending','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
  `error_message`   VARCHAR(500)      DEFAULT NULL,
  `timestamp`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wa_message_id` (`wa_message_id`),
  KEY `idx_lead_id`    (`lead_id`),
  KEY `idx_direction`  (`direction`),
  KEY `idx_is_read`    (`is_read`),
  KEY `idx_timestamp`  (`timestamp`),
  CONSTRAINT `fk_messages_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: campaigns
-- ============================================================
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(255)      NOT NULL,
  `description`     TEXT              DEFAULT NULL,
  `status`          ENUM('draft','running','paused','completed','failed') NOT NULL DEFAULT 'draft',
  `total_leads`     INT UNSIGNED      NOT NULL DEFAULT 0,
  `sent_count`      INT UNSIGNED      NOT NULL DEFAULT 0,
  `replied_count`   INT UNSIGNED      NOT NULL DEFAULT 0,
  `failed_count`    INT UNSIGNED      NOT NULL DEFAULT 0,
  `skipped_count`   INT UNSIGNED      NOT NULL DEFAULT 0,
  `daily_limit`     INT UNSIGNED      NOT NULL DEFAULT 50,
  `delay_min`       INT UNSIGNED      NOT NULL DEFAULT 120 COMMENT 'seconds',
  `delay_max`       INT UNSIGNED      NOT NULL DEFAULT 300 COMMENT 'seconds',
  `started_at`      DATETIME          DEFAULT NULL,
  `paused_at`       DATETIME          DEFAULT NULL,
  `completed_at`    DATETIME          DEFAULT NULL,
  `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_status`     (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100)  NOT NULL,
  `setting_value` TEXT        DEFAULT NULL,
  `setting_type`  ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
  `label`         VARCHAR(255) DEFAULT NULL,
  `group_name`    VARCHAR(100) DEFAULT 'general',
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`),
  KEY `idx_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: logs
-- ============================================================
CREATE TABLE IF NOT EXISTS `logs` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `level`       ENUM('info','warning','error','debug') NOT NULL DEFAULT 'info',
  `source`      VARCHAR(100)  NOT NULL DEFAULT 'system',
  `message`     TEXT          NOT NULL,
  `context`     JSON          DEFAULT NULL,
  `ip_address`  VARCHAR(45)   DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_level`      (`level`),
  KEY `idx_source`     (`source`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: csv_imports
-- ============================================================
CREATE TABLE IF NOT EXISTS `csv_imports` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `filename`        VARCHAR(255)  NOT NULL,
  `original_name`   VARCHAR(255)  NOT NULL,
  `total_rows`      INT UNSIGNED  NOT NULL DEFAULT 0,
  `imported_rows`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `skipped_rows`    INT UNSIGNED  NOT NULL DEFAULT 0,
  `duplicate_rows`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `error_rows`      INT UNSIGNED  NOT NULL DEFAULT 0,
  `status`          ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
  `error_log`       JSON          DEFAULT NULL,
  `imported_by`     VARCHAR(100)  DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`    DATETIME      DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_status`     (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: whatsapp_sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `whatsapp_sessions` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `session_id`      VARCHAR(100)  NOT NULL DEFAULT 'default',
  `status`          ENUM('disconnected','connecting','qr_ready','connected','auth_failure') NOT NULL DEFAULT 'disconnected',
  `phone_number`    VARCHAR(20)   DEFAULT NULL,
  `display_name`    VARCHAR(255)  DEFAULT NULL,
  `qr_code`         TEXT          DEFAULT NULL,
  `last_connected`  DATETIME      DEFAULT NULL,
  `last_ping`       DATETIME      DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;
