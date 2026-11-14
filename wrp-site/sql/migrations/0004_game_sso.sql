-- Вход с игровых устройств (ноутбук, компьютер, планшет в доме): использованные одноразовые ссылки.
-- Ссылку подписывает сервер игры (docs/INGAME.md), повторно открыть ту же ссылку нельзя.
CREATE TABLE IF NOT EXISTS `game_sso_nonces` (
  `nonce` VARCHAR(64) NOT NULL,
  `nick` VARCHAR(32) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`nonce`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
