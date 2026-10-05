-- World Role Play: схема базы сайта и форума
-- MySQL 5.7+ / MariaDB 10.3+, кодировка utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `color` VARCHAR(16) NOT NULL DEFAULT '#9aa0b4',
  `level` INT NOT NULL DEFAULT 10,
  `is_staff` TINYINT(1) NOT NULL DEFAULT 0,
  `can_moderate` TINYINT(1) NOT NULL DEFAULT 0,
  `can_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `show_banner` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `group_id` INT UNSIGNED NOT NULL DEFAULT 2,
  `secondary_groups` VARCHAR(255) NULL,
  `custom_title` VARCHAR(64) NULL,
  `avatar` VARCHAR(64) NULL,
  `cover` VARCHAR(64) NULL,
  `location` VARCHAR(64) NULL,
  `status_text` VARCHAR(140) NULL,
  `signature` TEXT NULL,
  `about` TEXT NULL,
  `game_nick` VARCHAR(32) NULL,
  `posts_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `threads_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `likes_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  `ban_reason` VARCHAR(255) NULL,
  `ban_until` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `last_activity` DATETIME NULL,
  `last_ip` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_game_nick` (`game_nick`),
  KEY `idx_group` (`group_id`),
  KEY `idx_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Узлы форума: категории, форумы, ссылки. Вложенность через parent_id.
-- Уровни доступа сравниваются с groups.level пользователя (гость = 0).
CREATE TABLE IF NOT EXISTS `nodes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NULL,
  `type` VARCHAR(16) NOT NULL DEFAULT 'forum',
  `title` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `icon` VARCHAR(32) NOT NULL DEFAULT 'chats',
  `link_url` VARCHAR(255) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `view_level` INT NOT NULL DEFAULT 0,
  `thread_level` INT NOT NULL DEFAULT 10,
  `reply_level` INT NOT NULL DEFAULT 10,
  `hide_if_no_access` TINYINT(1) NOT NULL DEFAULT 0,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `quick_nav` INT NOT NULL DEFAULT 0,
  `require_prefix` TINYINT(1) NOT NULL DEFAULT 0,
  `default_prefix_id` INT UNSIGNED NULL,
  `title_hint` VARCHAR(120) NULL,
  `thread_template` TEXT NULL,
  `form_json` TEXT NULL,
  `thread_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `post_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_thread_id` INT UNSIGNED NULL,
  `last_post_id` INT UNSIGNED NULL,
  `last_post_at` DATETIME NULL,
  `last_post_user_id` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prefixes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(48) NOT NULL,
  `color` VARCHAR(16) NOT NULL DEFAULT 'gray',
  `display_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `node_prefixes` (
  `node_id` INT UNSIGNED NOT NULL,
  `prefix_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`node_id`, `prefix_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `threads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `node_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `prefix_id` INT UNSIGNED NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `reply_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_post_id` INT UNSIGNED NULL,
  `last_post_id` INT UNSIGNED NULL,
  `last_post_at` DATETIME NOT NULL,
  `last_post_user_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_list` (`node_id`, `is_deleted`, `is_pinned`, `last_post_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_last` (`last_post_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  `edited_at` DATETIME NULL,
  `edited_by` INT UNSIGNED NULL,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NULL,
  `likes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`thread_id`, `is_deleted`, `id`),
  KEY `idx_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_likes` (
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reaction` VARCHAR(16) NOT NULL DEFAULT 'like',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`post_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Оповещения (колокольчик): ответ в теме, лайк, цитата, смена статуса жалобы
CREATE TABLE IF NOT EXISTS `alerts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `actor_id` INT UNSIGNED NULL,
  `type` VARCHAR(24) NOT NULL,
  `thread_id` INT UNSIGNED NULL,
  `post_id` INT UNSIGNED NULL,
  `extra` VARCHAR(255) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `is_read`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `selector` CHAR(24) NOT NULL,
  `validator_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_selector` (`selector`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Попытки входа и прочие действия для ограничения частоты (вход, регистрация, привязка аккаунта)
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(24) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action_ip` (`action`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кто сейчас на сайте (гости и пользователи)
CREATE TABLE IF NOT EXISTS `online` (
  `sid` CHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `last_activity` DATETIME NOT NULL,
  PRIMARY KEY (`sid`),
  KEY `idx_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- База знаний
CREATE TABLE IF NOT EXISTS `wiki_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `icon` VARCHAR(32) NOT NULL DEFAULT 'book',
  `description` VARCHAR(255) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_articles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(96) NOT NULL,
  `summary` VARCHAR(255) NULL,
  `body` MEDIUMTEXT NOT NULL,
  `author_id` INT UNSIGNED NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_category` (`category_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `target_type` VARCHAR(16) NOT NULL,
  `target_id` INT UNSIGNED NOT NULL,
  `details` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прочитанные темы и разделы (значок «НОВОЕ», «Прочитать всё»)
CREATE TABLE IF NOT EXISTS `thread_reads` (
  `user_id` INT UNSIGNED NOT NULL,
  `thread_id` INT UNSIGNED NOT NULL,
  `read_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `thread_id`),
  KEY `idx_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `node_reads` (
  `user_id` INT UNSIGNED NOT NULL,
  `node_id` INT UNSIGNED NOT NULL,
  `read_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отслеживаемые темы (оповещения об ответах)
CREATE TABLE IF NOT EXISTS `thread_watch` (
  `user_id` INT UNSIGNED NOT NULL,
  `thread_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `thread_id`),
  KEY `idx_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Личные переписки
CREATE TABLE IF NOT EXISTS `conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `starter_id` INT UNSIGNED NOT NULL,
  `reply_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_message_id` INT UNSIGNED NULL,
  `last_message_at` DATETIME NOT NULL,
  `last_message_user_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_users` (
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `last_read_at` DATETIME NULL,
  `is_left` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`conversation_id`, `user_id`),
  KEY `idx_user` (`user_id`, `is_left`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Навигация в левом меню форума (редактируется в админке)
CREATE TABLE IF NOT EXISTS `nav_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(64) NOT NULL,
  `url` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(32) NOT NULL DEFAULT 'link',
  `display_order` INT NOT NULL DEFAULT 0,
  `new_tab` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Стена профиля: сообщения и комментарии к ним
CREATE TABLE IF NOT EXISTS `profile_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_user_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `likes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_profile` (`profile_user_id`, `is_deleted`, `id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profile_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `likes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`profile_post_id`, `is_deleted`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лайки на стене профиля: content_type = 'post' | 'comment'
CREATE TABLE IF NOT EXISTS `profile_likes` (
  `content_type` VARCHAR(8) NOT NULL,
  `content_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reaction` VARCHAR(16) NOT NULL DEFAULT 'like',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`content_type`, `content_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Подписки на пользователей и игнор
CREATE TABLE IF NOT EXISTS `user_follows` (
  `user_id` INT UNSIGNED NOT NULL,
  `follow_user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `follow_user_id`),
  KEY `idx_follow` (`follow_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_ignores` (
  `user_id` INT UNSIGNED NOT NULL,
  `ignored_user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `ignored_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Подписка на разделы (оповещение о новых темах)
CREATE TABLE IF NOT EXISTS `node_watch` (
  `user_id` INT UNSIGNED NOT NULL,
  `node_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `node_id`),
  KEY `idx_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
