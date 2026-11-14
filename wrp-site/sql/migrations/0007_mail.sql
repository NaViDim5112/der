-- Почта: одноразовые ссылки из писем (восстановление пароля, подтверждение email)
-- и отметка о подтверждённой почте. Уже зарегистрированные считаются подтверждёнными.
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `purpose` VARCHAR(16) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hash` (`token_hash`),
  KEY `idx_user` (`user_id`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD `email_confirmed` TINYINT(1) NOT NULL DEFAULT 1;
