-- Модуль «Рассмотрение» (app/modules/workflow*.php): жалобы, заявления, техподдержка и предложения.
-- Настройки разделов, ответственные, ход рассмотрения темы, готовые ответы, голоса за предложения,
-- архивы рассмотренных тем. Повторный запуск безопасен.

-- Настройки рассмотрения по разделам
CREATE TABLE IF NOT EXISTS `wf_nodes` (
  `node_id` INT UNSIGNED NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'complaint',
  `min_level` INT NOT NULL DEFAULT 30,
  `deadline_hours` INT NOT NULL DEFAULT 48,
  `claim_prefix_id` INT UNSIGNED NULL,
  `final_prefixes` VARCHAR(255) NOT NULL DEFAULT '2,3,4',
  `archive_node_id` INT UNSIGNED NULL,
  `archive_days` INT NOT NULL DEFAULT 0,
  `voting` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ответственные за раздел (лидеры организаций и т.п.): могут брать темы и выносить решения только здесь
CREATE TABLE IF NOT EXISTS `wf_node_staff` (
  `node_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `note` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`node_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ход рассмотрения темы: кто взял, когда, решение, ожидание доказательств
CREATE TABLE IF NOT EXISTS `wf_threads` (
  `thread_id` INT UNSIGNED NOT NULL,
  `claimed_by` INT UNSIGNED NULL,
  `claimed_at` DATETIME NULL,
  `verdict_by` INT UNSIGNED NULL,
  `verdict_at` DATETIME NULL,
  `verdict_prefix_id` INT UNSIGNED NULL,
  `verdict_post_id` INT UNSIGNED NULL,
  `evidence_at` DATETIME NULL,
  `evidence_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`thread_id`),
  KEY `idx_claimed` (`claimed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История действий (для статистики сотрудников): claim, unclaim, transfer, verdict, autoclose, archive
CREATE TABLE IF NOT EXISTS `wf_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` INT UNSIGNED NOT NULL,
  `node_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(16) NOT NULL,
  `prefix_id` INT UNSIGNED NULL,
  `target_user_id` INT UNSIGNED NULL,
  `seconds` INT UNSIGNED NULL,
  `overdue` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_time` (`created_at`),
  KEY `idx_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Готовые ответы. node_ids: id разделов через запятую, пусто - во всех разделах рассмотрения
CREATE TABLE IF NOT EXISTS `wf_macros` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(100) NOT NULL,
  `body` TEXT NOT NULL,
  `node_ids` VARCHAR(255) NULL,
  `prefix_id` INT UNSIGNED NULL,
  `do_lock` TINYINT(1) NOT NULL DEFAULT 0,
  `do_archive` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Голоса за предложения: 1 - за, -1 - против. Итоги хранятся в threads.wf_up / wf_down
CREATE TABLE IF NOT EXISTS `wf_votes` (
  `thread_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `vote` TINYINT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`thread_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `threads` ADD COLUMN `wf_up` INT NOT NULL DEFAULT 0;
ALTER TABLE `threads` ADD COLUMN `wf_down` INT NOT NULL DEFAULT 0;

-- Архивы рассмотренных тем (создаются, только если такого раздела ещё нет)
INSERT INTO nodes (parent_id, type, title, description, icon, display_order, view_level, thread_level, reply_level, hide_if_no_access, is_closed, quick_nav, require_prefix) SELECT 51, 'forum', 'Архив жалоб', 'Рассмотренные жалобы и обжалования', 'folder', 99, 0, 80, 40, 0, 0, 0, 0 FROM DUAL WHERE EXISTS (SELECT 1 FROM nodes WHERE id = 51) AND NOT EXISTS (SELECT 1 FROM nodes WHERE parent_id = 51 AND title = 'Архив жалоб');
INSERT INTO nodes (parent_id, type, title, description, icon, display_order, view_level, thread_level, reply_level, hide_if_no_access, is_closed, quick_nav, require_prefix) SELECT 57, 'forum', 'Архив Правительства', 'Рассмотренные заявления и жалобы на сотрудников мэрии', 'folder', 99, 0, 80, 40, 0, 0, 0, 0 FROM DUAL WHERE EXISTS (SELECT 1 FROM nodes WHERE id = 57) AND NOT EXISTS (SELECT 1 FROM nodes WHERE parent_id = 57 AND title = 'Архив Правительства');
INSERT INTO nodes (parent_id, type, title, description, icon, display_order, view_level, thread_level, reply_level, hide_if_no_access, is_closed, quick_nav, require_prefix) SELECT 58, 'forum', 'Архив Департамента полиции', 'Рассмотренные заявления в академию и жалобы на сотрудников', 'folder', 99, 0, 80, 40, 0, 0, 0, 0 FROM DUAL WHERE EXISTS (SELECT 1 FROM nodes WHERE id = 58) AND NOT EXISTS (SELECT 1 FROM nodes WHERE parent_id = 58 AND title = 'Архив Департамента полиции');
INSERT INTO nodes (parent_id, type, title, description, icon, display_order, view_level, thread_level, reply_level, hide_if_no_access, is_closed, quick_nav, require_prefix) SELECT 50, 'forum', 'Архив заявлений', 'Рассмотренные заявления на пост лидера и в администрацию', 'folder', 99, 0, 80, 40, 0, 0, 0, 0 FROM DUAL WHERE EXISTS (SELECT 1 FROM nodes WHERE id = 50) AND NOT EXISTS (SELECT 1 FROM nodes WHERE parent_id = 50 AND title = 'Архив заявлений');
INSERT INTO nodes (parent_id, type, title, description, icon, display_order, view_level, thread_level, reply_level, hide_if_no_access, is_closed, quick_nav, require_prefix) SELECT 20, 'forum', 'Архив технического раздела', 'Решённые обращения, исправленные ошибки и восстановленное имущество', 'folder', 99, 0, 80, 40, 0, 0, 0, 0 FROM DUAL WHERE EXISTS (SELECT 1 FROM nodes WHERE id = 20) AND NOT EXISTS (SELECT 1 FROM nodes WHERE parent_id = 20 AND title = 'Архив технического раздела');

-- Настройки разделов по умолчанию: жалобы - 48 часов, заявления - 72 часа
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'complaint', 30, 48, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 51 AND a.title = 'Архив жалоб' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 52;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'complaint', 60, 48, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 51 AND a.title = 'Архив жалоб' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 53;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'complaint', 60, 48, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 51 AND a.title = 'Архив жалоб' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 54;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'appeal', 60, 48, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 51 AND a.title = 'Архив жалоб' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 55;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'complaint', 60, 48, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 57 AND a.title = 'Архив Правительства' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 62;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'complaint', 60, 48, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 58 AND a.title = 'Архив Департамента полиции' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 65;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'application', 60, 72, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 57 AND a.title = 'Архив Правительства' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 61;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'application', 60, 72, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 58 AND a.title = 'Архив Департамента полиции' LIMIT 1), 3, 0, NOW() FROM nodes n WHERE n.id = 64;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'application', 80, 72, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 50 AND a.title = 'Архив заявлений' LIMIT 1), 7, 0, NOW() FROM nodes n WHERE n.id = 67;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'application', 80, 72, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 50 AND a.title = 'Архив заявлений' LIMIT 1), 7, 0, NOW() FROM nodes n WHERE n.id = 68;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'tech', 30, 48, NULL, '4,6,14', (SELECT a.id FROM nodes a WHERE a.parent_id = 20 AND a.title = 'Архив технического раздела' LIMIT 1), 7, 0, NOW() FROM nodes n WHERE n.id = 21;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'tech', 30, 72, NULL, '4,6,14,18', (SELECT a.id FROM nodes a WHERE a.parent_id = 20 AND a.title = 'Архив технического раздела' LIMIT 1), 7, 0, NOW() FROM nodes n WHERE n.id = 22;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'property', 60, 72, 1, '2,3,4', (SELECT a.id FROM nodes a WHERE a.parent_id = 20 AND a.title = 'Архив технического раздела' LIMIT 1), 7, 0, NOW() FROM nodes n WHERE n.id = 23;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'suggestion', 60, 168, 1, '3,5,13,14,15', (SELECT a.id FROM nodes a WHERE a.id = 41 LIMIT 1), 3, 1, NOW() FROM nodes n WHERE n.id = 40;
INSERT IGNORE INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at) SELECT n.id, 1, 'suggestion', 60, 0, NULL, '3,5,13,14,15', NULL, 0, 1, NOW() FROM nodes n WHERE n.id = 41;

-- Готовые ответы. Подстановки: {author} - автор темы, {staff} - кто отвечает, {thread} - название темы, {date} - сегодняшняя дата
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (1, 'Одобрено: нарушитель будет наказан', 'Здравствуйте, {author}.\n\nЖалоба рассмотрена и [b][color=#22c55e]одобрена[/color][/b]. Нарушение подтверждено доказательствами, нарушитель получит наказание по правилам сервера.\n\nСпасибо, что помогаете делать World Role Play лучше!\n\nС уважением, {staff}.', '52,54,62,65', 2, 1, 1, 1, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (2, 'Отказано: недостаточно доказательств', 'Здравствуйте, {author}.\n\nЖалоба [b][color=#ef4444]отклонена[/color][/b]: представленных доказательств недостаточно, чтобы подтвердить нарушение.\n\nНа видео или скриншотах должно быть чётко видно само нарушение, ник нарушителя и время. Если у вас есть полная запись, создайте новую жалобу.\n\nС уважением, {staff}.', '52,53,54,62,65', 3, 1, 1, 2, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (3, 'Отказано: прошло более 72 часов с момента нарушения', 'Здравствуйте, {author}.\n\nЖалоба [b][color=#ef4444]отклонена[/color][/b]: с момента нарушения прошло более 72 часов. Жалобы принимаются в течение трёх суток после нарушения.\n\nС уважением, {staff}.', '52,53,54,62,65', 3, 1, 1, 3, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (4, 'Отказано: нет тайм-кода /time на скриншоте', 'Здравствуйте, {author}.\n\nЖалоба [b][color=#ef4444]отклонена[/color][/b]: на скриншотах не видно времени (/time). Без даты и времени нельзя проверить, когда произошло нарушение.\n\nВ следующий раз перед скриншотом введите /time или прикладывайте видео.\n\nС уважением, {staff}.', '52,53,54,62,65', 3, 1, 1, 4, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (5, 'Ожидание доказательств: прикрепите видео', 'Здравствуйте, {author}.\n\nЧтобы рассмотреть обращение, нужны доказательства: [b]прикрепите видеозапись[/b] (YouTube, VK Видео, Rutube) или полные скриншоты со временем /time.\n\nОтветьте в этой теме в течение 24 часов. Если доказательств не будет, обращение закроется автоматически.\n\nС уважением, {staff}.', '23,52,53,54,62,65', 17, 0, 0, 5, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (6, 'Передано главному администратору', 'Здравствуйте, {author}.\n\nОбращение передано [b]главному администратору[/b]. Ожидайте решения в этой теме.\n\nС уважением, {staff}.', '53,55', 16, 0, 0, 6, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (7, 'Наказание снято / смягчено', 'Здравствуйте, {author}.\n\nОбжалование [b][color=#22c55e]одобрено[/color][/b]: наказание снято или смягчено.\n\nПросим и дальше соблюдать правила сервера. Приятной игры на World Role Play!\n\nС уважением, {staff}.', '55', 2, 1, 1, 7, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (8, 'Тех. раздел: переустановите лаунчер World RP и проверьте файлы', 'Здравствуйте, {author}.\n\nПопробуйте по порядку:\n[list=1]\n[*]Полностью закройте игру и лаунчер World RP.\n[*]Запустите лаунчер от имени администратора и проверьте файлы игры.\n[*]Если не помогло, переустановите лаунчер и на время загрузки файлов добавьте папку игры в исключения антивируса.\n[/list]\nЕсли проблема останется, напишите в этой теме, что именно происходит, и приложите скриншот ошибки.\n\nС уважением, {staff}.', '21', 6, 0, 0, 8, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (9, 'Заявление одобрено: приходите на собеседование', 'Здравствуйте, {author}.\n\nВаше заявление [b][color=#22c55e]одобрено[/color][/b]! Руководство организации свяжется с вами в игре и назначит время собеседования.\n\nНа собеседование приходите в деловом виде и с паспортом.\n\nС уважением, {staff}.', '61,64', 2, 1, 1, 9, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (10, 'Заявление отклонено', 'Здравствуйте, {author}.\n\nК сожалению, заявление [b][color=#ef4444]отклонено[/color][/b]. Проверьте требования в закреплённой теме раздела и подайте заявление повторно не раньше чем через 7 дней.\n\nС уважением, {staff}.', '61,64,67,68', 3, 1, 1, 10, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (11, 'Предложение принято и передано разработчикам', 'Здравствуйте, {author}.\n\nСпасибо за идею! Предложение [b]принято[/b] и передано разработчикам World Role Play. Когда оно появится в игре, статус темы сменится на «Реализовано».\n\nС уважением, {staff}.', '40,41', 14, 0, 1, 11, 1, NOW());
INSERT IGNORE INTO wf_macros (id, title, body, node_ids, prefix_id, do_lock, do_archive, display_order, is_active, created_at) VALUES (12, 'Имущество восстановлено', 'Здравствуйте, {author}.\n\nПроверка завершена, имущество [b][color=#22c55e]восстановлено[/color][/b]. Зайдите в игру и проверьте дом, гараж, транспорт или счёт в World Bank. Если что-то не так, создайте новое обращение в этом разделе.\n\nС уважением, {staff}.', '23', 2, 1, 1, 12, 1, NOW());
