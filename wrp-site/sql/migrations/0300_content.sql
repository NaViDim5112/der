-- Модуль «Контент»: вложения (картинки-доказательства), опросы в темах, закладки на сообщения.
-- Повторный запуск безопасен: только CREATE TABLE IF NOT EXISTS.

-- Загруженные картинки. path - путь от public/uploads/attachments/ (ГГГГ/ММ/имя.расширение).
-- post_id пустой, пока файл не попал в отправленное сообщение (такие файлы старше суток удаляет фоновая задача).
CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `path` VARCHAR(128) NOT NULL,
  `thumb` VARCHAR(128) NULL,
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `post_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_path` (`path`),
  KEY `idx_user` (`user_id`, `created_at`),
  KEY `idx_post` (`post_id`),
  KEY `idx_orphan` (`post_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Опрос в теме (один на тему)
CREATE TABLE IF NOT EXISTS `polls` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `question` VARCHAR(200) NOT NULL,
  `is_multiple` TINYINT(1) NOT NULL DEFAULT 0,
  `public_results` TINYINT(1) NOT NULL DEFAULT 1,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `close_at` DATETIME NULL,
  `voters` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `votes` INT UNSIGNED NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_poll` (`poll_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `poll_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`poll_id`, `user_id`, `option_id`),
  KEY `idx_option` (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Закладки на сообщения форума (с необязательной заметкой)
CREATE TABLE IF NOT EXISTS `bookmarks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `post_id` INT UNSIGNED NOT NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_post` (`user_id`, `post_id`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
