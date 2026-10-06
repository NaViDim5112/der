-- Тексты под новые функции: в анкетах можно загрузить скриншот кнопкой «Загрузить»,
-- описание раздела предложений без «работ» (их в игре пока нет).
UPDATE nodes SET form_json = REPLACE(form_json, 'Загрузите скриншот на imgur.com или yapx.ru и вставьте ссылку.', 'Нажмите «Загрузить» или вставьте ссылку на скриншот (imgur.com, yapx.ru).') WHERE form_json IS NOT NULL;
UPDATE nodes SET form_json = REPLACE(form_json, 'Ссылка на видео (YouTube) или скриншот.', 'Ссылка на видео (YouTube, VK Видео, Rutube) или скриншот через «Загрузить».') WHERE form_json IS NOT NULL;
UPDATE nodes SET form_json = REPLACE(form_json, 'Ссылка на видео (YouTube, VK Видео, Rutube) или скриншот.', 'Ссылка на видео (YouTube, VK Видео, Rutube) или скриншот через «Загрузить».') WHERE form_json IS NOT NULL;
UPDATE nodes SET description = 'Идеи для сервера: новые системы, улучшения и изменения баланса' WHERE id = 40 AND description = 'Идеи для сервера: новые системы, работы, изменения баланса';
