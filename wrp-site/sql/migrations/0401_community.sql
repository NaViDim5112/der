-- Сообщество: награды и звания, дополнительные поля профиля, двухфакторная защита,
-- объявления-плашки, Discord-вебхуки. Повторный запуск безопасен.
-- Колонка users.trophy_points добавляется последней: по ней модуль понимает, что обновление применено.

-- ---------- Награды ----------
CREATE TABLE IF NOT EXISTS `com_trophies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NULL,
  `icon` VARCHAR(32) NOT NULL DEFAULT 'trophy',
  `color` VARCHAR(16) NOT NULL DEFAULT 'orange',
  `points` INT NOT NULL DEFAULT 0,
  `criteria` VARCHAR(24) NOT NULL DEFAULT 'manual',
  `criteria_value` INT NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `com_user_trophies` (
  `user_id` INT UNSIGNED NOT NULL,
  `trophy_id` INT UNSIGNED NOT NULL,
  `awarded_at` DATETIME NOT NULL,
  `awarded_by` INT UNSIGNED NULL,
  `note` VARCHAR(255) NULL,
  PRIMARY KEY (`user_id`, `trophy_id`),
  KEY `idx_trophy` (`trophy_id`, `awarded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (1, 'Прилетел в Лос-Сантос', 'Зарегистрировался на форуме World Role Play. Добро пожаловать в штат!', 'plane', 'blue', 1, 'days_registered', 0, 1, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (2, 'Первое сообщение', 'Написал первое сообщение на форуме.', 'chat', 'teal', 1, 'posts_count', 1, 2, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (3, 'Первая тема', 'Создал свою первую тему.', 'edit', 'teal', 2, 'threads_count', 1, 3, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (4, 'Житель штата', 'Уже месяц на форуме World RP.', 'home', 'green', 5, 'days_registered', 30, 4, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (5, 'Старожил', 'На форуме больше года. Помнит, как всё начиналось.', 'clock', 'gold', 25, 'days_registered', 365, 5, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (6, 'Активный гражданин', 'Написал 100 сообщений на форуме.', 'chats', 'orange', 10, 'posts_count', 100, 6, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (7, 'Голос народа', 'Написал 500 сообщений на форуме.', 'megaphone', 'red', 30, 'posts_count', 500, 7, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (8, 'Автор', 'Создал 10 тем на форуме.', 'feather', 'purple', 10, 'threads_count', 10, 8, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (9, 'Уважаемый человек', 'Получил 50 реакций на свои сообщения.', 'heart', 'pink', 10, 'likes_received', 50, 9, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (10, 'Звезда Лос-Сантоса', 'Получил 500 реакций на свои сообщения.', 'star', 'accent', 50, 'likes_received', 500, 10, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (11, 'Привязал игровой аккаунт', 'Привязал игровой аккаунт World RP в личном кабинете.', 'gamepad', 'blue', 5, 'game_linked', 1, 11, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (12, 'Охотник за багами', 'Нашёл и подробно описал ошибку мода. Выдаёт администрация.', 'wrench', 'teal', 15, 'manual', 0, 12, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (13, 'Помощь проекту', 'Помог проекту идеями, тестами или контентом. Выдаёт администрация.', 'handshake', 'green', 20, 'manual', 0, 13, NOW());
INSERT IGNORE INTO `com_trophies` (id, title, description, icon, color, points, criteria, criteria_value, display_order, created_at) VALUES (14, 'Победитель конкурса', 'Победил в конкурсе или мероприятии World RP. Выдаёт администрация.', 'trophy', 'gold', 25, 'manual', 0, 14, NOW());

-- ---------- Звания по баллам ----------
CREATE TABLE IF NOT EXISTS `com_ranks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(48) NOT NULL,
  `min_points` INT NOT NULL DEFAULT 0,
  `color` VARCHAR(16) NOT NULL DEFAULT 'gray',
  PRIMARY KEY (`id`),
  KEY `idx_points` (`min_points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (1, 'Турист', 0, 'gray');
INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (2, 'Новичок', 10, 'teal');
INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (3, 'Житель', 50, 'blue');
INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (4, 'Гражданин', 150, 'green');
INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (5, 'Уважаемый гражданин', 400, 'purple');
INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (6, 'Почётный житель', 1000, 'orange');
INSERT IGNORE INTO `com_ranks` (id, title, min_points, color) VALUES (7, 'Легенда Лос-Сантоса', 2500, 'accent');

-- ---------- Дополнительные поля профиля ----------
CREATE TABLE IF NOT EXISTS `com_fields` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(64) NOT NULL,
  `description` VARCHAR(255) NULL,
  `type` VARCHAR(12) NOT NULL DEFAULT 'text',
  `options` TEXT NULL,
  `max_length` INT NOT NULL DEFAULT 64,
  `placeholder` VARCHAR(100) NULL,
  `icon` VARCHAR(32) NOT NULL DEFAULT 'info',
  `show_profile` TINYINT(1) NOT NULL DEFAULT 1,
  `show_post` TINYINT(1) NOT NULL DEFAULT 0,
  `user_editable` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `com_field_values` (
  `user_id` INT UNSIGNED NOT NULL,
  `field_id` INT UNSIGNED NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`user_id`, `field_id`),
  KEY `idx_field` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `com_fields` (id, title, description, type, options, max_length, placeholder, icon, show_profile, show_post, user_editable, display_order) VALUES (1, 'Discord', 'Ник в Discord, чтобы с вами можно было связаться.', 'text', NULL, 40, 'Например: nick_name', 'discord', 1, 0, 1, 1);
INSERT IGNORE INTO `com_fields` (id, title, description, type, options, max_length, placeholder, icon, show_profile, show_post, user_editable, display_order) VALUES (2, 'ВКонтакте', 'Ссылка на страницу ВКонтакте.', 'url', NULL, 100, 'https://vk.com/...', 'vk', 1, 0, 1, 2);
INSERT IGNORE INTO `com_fields` (id, title, description, type, options, max_length, placeholder, icon, show_profile, show_post, user_editable, display_order) VALUES (3, 'Telegram', 'Ник в Telegram.', 'text', NULL, 40, '@username', 'telegram', 1, 0, 1, 3);
INSERT IGNORE INTO `com_fields` (id, title, description, type, options, max_length, placeholder, icon, show_profile, show_post, user_editable, display_order) VALUES (4, 'Часовой пояс', 'Чтобы другие знали, когда вы обычно в игре.', 'select', 'МСК-1 (Калининград)\nМСК (Москва)\nМСК+1 (Самара)\nМСК+2 (Екатеринбург)\nМСК+3 (Омск)\nМСК+4 (Красноярск)\nМСК+5 (Иркутск)\nМСК+6 (Якутск)\nМСК+7 (Владивосток)\nМСК+8 (Магадан)\nМСК+9 (Камчатка)', 40, NULL, 'clock', 1, 1, 1, 4);
INSERT IGNORE INTO `com_fields` (id, title, description, type, options, max_length, placeholder, icon, show_profile, show_post, user_editable, display_order) VALUES (5, 'Канал / стрим (YouTube, Twitch)', 'Ссылка на ваш канал, если снимаете или стримите World RP.', 'url', NULL, 150, 'https://www.youtube.com/@...', 'youtube', 1, 0, 1, 5);

-- ---------- Двухфакторная защита (TOTP) ----------
-- secret_enc: секрет, зашифрованный AES-256-GCM ключом из config.php -> secret (g1:...),
-- без OpenSSL - открытым текстом (p1:...). backup_codes: JSON с HMAC-хэшами резервных кодов.
CREATE TABLE IF NOT EXISTS `com_tfa` (
  `user_id` INT UNSIGNED NOT NULL,
  `secret_enc` VARCHAR(255) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `last_step` BIGINT NOT NULL DEFAULT 0,
  `backup_codes` TEXT NULL,
  `enabled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Объявления-плашки ----------
CREATE TABLE IF NOT EXISTS `com_notices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(120) NOT NULL,
  `message` TEXT NULL,
  `style` VARCHAR(16) NOT NULL DEFAULT 'info',
  `audience` VARCHAR(16) NOT NULL DEFAULT 'all',
  `min_level` INT NULL,
  `max_level` INT NULL,
  `area` VARCHAR(8) NOT NULL DEFAULT 'forum',
  `link_url` VARCHAR(255) NULL,
  `link_text` VARCHAR(60) NULL,
  `dismissible` TINYINT(1) NOT NULL DEFAULT 1,
  `starts_at` DATETIME NULL,
  `ends_at` DATETIME NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `revision` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `com_notice_dismiss` (
  `notice_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `revision` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`notice_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `com_notices` (id, title, message, style, audience, area, link_url, link_text, dismissible, display_order, is_active, revision, created_at) VALUES (1, 'Скачайте лаунчер World RP', 'Вход на сервер - только через лаунчер World RP. Как скачать и начать играть - на странице «Начать игру».', 'accent', 'all', 'both', '/start.php', 'Начать игру', 1, 1, 0, 1, NOW());
INSERT IGNORE INTO `com_notices` (id, title, message, style, audience, area, link_url, link_text, dismissible, display_order, is_active, revision, created_at) VALUES (2, 'Зарегистрируйтесь, чтобы писать на форуме', 'Гости могут только читать. После регистрации можно создавать темы, отвечать, ставить реакции и подавать жалобы.', 'info', 'guests', 'forum', '/forum/register.php', 'Регистрация', 1, 2, 0, 1, NOW());

-- ---------- Discord-вебхуки ----------
CREATE TABLE IF NOT EXISTS `com_webhooks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `url_enc` TEXT NOT NULL,
  `url_mask` VARCHAR(120) NOT NULL DEFAULT '',
  `node_ids` VARCHAR(1000) NOT NULL DEFAULT '',
  `events` VARCHAR(64) NOT NULL DEFAULT 'thread',
  `prefix_ids` VARCHAR(255) NOT NULL DEFAULT '',
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_status` VARCHAR(255) NULL,
  `last_sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `com_webhook_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id` INT UNSIGNED NOT NULL,
  `event` VARCHAR(16) NOT NULL,
  `thread_id` INT UNSIGNED NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `next_try_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `next_try_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Баллы за награды (сумма, пересчитывается при выдаче и снятии) ----------
ALTER TABLE `users` ADD COLUMN `trophy_points` INT NOT NULL DEFAULT 0;
