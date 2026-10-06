-- Модерация: жалобы, предупреждения с баллами, запрет ответов, история правок, IP-адреса.
-- Повторный запуск безопасен: CREATE TABLE IF NOT EXISTS и INSERT IGNORE по явным id.

-- Жалоба на материал. Несколько жалоб на один материал собираются в одну открытую жалобу.
-- content_type: post, profile_post, profile_comment, message, user
CREATE TABLE IF NOT EXISTS `mod_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `content_type` VARCHAR(16) NOT NULL,
  `content_id` INT UNSIGNED NOT NULL,
  `content_user_id` INT UNSIGNED NULL,
  `content_snapshot` MEDIUMTEXT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'open',
  `assigned_to` INT UNSIGNED NULL,
  `assigned_at` DATETIME NULL,
  `resolved_by` INT UNSIGNED NULL,
  `resolved_at` DATETIME NULL,
  `resolution_note` VARCHAR(1000) NULL,
  `reports_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_report_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `last_report_at`),
  KEY `idx_content` (`content_type`, `content_id`, `status`),
  KEY `idx_assigned` (`assigned_to`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кто пожаловался: один человек - одна запись в открытой жалобе
CREATE TABLE IF NOT EXISTS `mod_report_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(16) NOT NULL,
  `comment` VARCHAR(1000) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_user` (`report_id`, `user_id`),
  KEY `idx_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Виды предупреждений: баллы, срок действия (0 - бессрочно), отметка под сообщением
CREATE TABLE IF NOT EXISTS `mod_warning_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(100) NOT NULL,
  `points` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `expiry_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `public_note` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Что происходит при наборе баллов: ban - бан форума (days 0 - навсегда), noreply - запрет писать на форуме
CREATE TABLE IF NOT EXISTS `mod_warning_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `points` SMALLINT UNSIGNED NOT NULL,
  `action` VARCHAR(16) NOT NULL DEFAULT 'ban',
  `days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_points` (`points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Выданные предупреждения
CREATE TABLE IF NOT EXISTS `mod_warnings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type_id` INT UNSIGNED NULL,
  `title` VARCHAR(100) NOT NULL,
  `points` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `content_type` VARCHAR(16) NULL,
  `content_id` INT UNSIGNED NULL,
  `report_id` INT UNSIGNED NULL,
  `issued_by` INT UNSIGNED NOT NULL,
  `message` VARCHAR(2000) NULL,
  `public_note` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NULL,
  `is_expired` TINYINT(1) NOT NULL DEFAULT 0,
  `revoked_by` INT UNSIGNED NULL,
  `revoked_at` DATETIME NULL,
  `revoke_reason` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `is_expired`),
  KEY `idx_content` (`content_type`, `content_id`),
  KEY `idx_expires` (`is_expired`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сработавшие пороги: одно срабатывание на одно пересечение порога
CREATE TABLE IF NOT EXISTS `mod_warning_triggers` (
  `user_id` INT UNSIGNED NOT NULL,
  `rule_id` INT UNSIGNED NOT NULL,
  `warning_id` INT UNSIGNED NULL,
  `points` INT NOT NULL DEFAULT 0,
  `ban_reason` VARCHAR(255) NULL,
  `ban_until` DATETIME NULL,
  `ban_applied` TINYINT(1) NOT NULL DEFAULT 0,
  `reply_ban_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Запрет ответов: в одной теме (thread_id) или во всём форуме (thread_id NULL). expires_at NULL - навсегда
CREATE TABLE IF NOT EXISTS `mod_reply_bans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NULL,
  `banned_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `thread_id`),
  KEY `idx_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История правок: текст сообщения до правки. prev_edited_at пусто - это исходный текст
CREATE TABLE IF NOT EXISTS `post_edits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `prev_edited_at` DATETIME NULL,
  `prev_edited_by` INT UNSIGNED NULL,
  `editor_id` INT UNSIGNED NULL,
  `edited_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP-адреса пользователей (вход, регистрация, сообщения). Видят только администраторы
CREATE TABLE IF NOT EXISTS `user_ips` (
  `user_id` INT UNSIGNED NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `first_seen` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`, `ip`),
  KEY `idx_ip` (`ip`, `last_seen`),
  KEY `idx_last` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Виды предупреждений по умолчанию
INSERT IGNORE INTO mod_warning_types (id, title, points, expiry_days, public_note, display_order) VALUES (1, 'Оскорбление', 3, 30, 1, 1);
INSERT IGNORE INTO mod_warning_types (id, title, points, expiry_days, public_note, display_order) VALUES (2, 'Флуд / оффтоп', 1, 14, 1, 2);
INSERT IGNORE INTO mod_warning_types (id, title, points, expiry_days, public_note, display_order) VALUES (3, 'Реклама', 5, 60, 1, 3);
INSERT IGNORE INTO mod_warning_types (id, title, points, expiry_days, public_note, display_order) VALUES (4, 'Обман / подделка доказательств', 10, 90, 1, 4);
INSERT IGNORE INTO mod_warning_types (id, title, points, expiry_days, public_note, display_order) VALUES (5, 'Нарушение правил раздела жалоб', 2, 30, 1, 5);

-- Пороги: 10 баллов - бан на 3 дня, 20 - на 14 дней, 30 - бессрочный бан
INSERT IGNORE INTO mod_warning_rules (id, points, action, days) VALUES (1, 10, 'ban', 3);
INSERT IGNORE INTO mod_warning_rules (id, points, action, days) VALUES (2, 20, 'ban', 14);
INSERT IGNORE INTO mod_warning_rules (id, points, action, days) VALUES (3, 30, 'ban', 0);

-- IP из уже написанных сообщений и последнего захода
INSERT IGNORE INTO user_ips (user_id, ip, first_seen, last_seen, hits) SELECT user_id, ip, MIN(created_at), MAX(created_at), COUNT(*) FROM posts WHERE ip IS NOT NULL AND ip <> '' AND user_id > 0 GROUP BY user_id, ip;
INSERT IGNORE INTO user_ips (user_id, ip, first_seen, last_seen, hits) SELECT id, last_ip, COALESCE(last_activity, created_at), COALESCE(last_activity, created_at), 1 FROM users WHERE last_ip IS NOT NULL AND last_ip <> '';
