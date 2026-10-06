-- Ядро: состояние планировщика, где сейчас находится посетитель («Просматривает тему ...»)
CREATE TABLE IF NOT EXISTS `cron_state` (
  `name` VARCHAR(64) NOT NULL,
  `last_run` DATETIME NULL,
  `last_status` VARCHAR(255) NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `online` ADD COLUMN `location` VARCHAR(255) NULL;
ALTER TABLE `users` ADD COLUMN `last_location` VARCHAR(255) NULL;
