-- Анкеты: поля с игровым ником получают тип «Игровой ник» (проверка формата Имя_Фамилия),
-- поле времени нарушения - тип «Время». Анкеты, изменённые в конструкторе, не трогаются (другой формат JSON).
UPDATE nodes SET form_json = REPLACE(form_json, '"type": "text", "required": true, "placeholder": "Имя_Фамилия"', '"type": "nick", "required": true, "placeholder": "Имя_Фамилия"') WHERE form_json IS NOT NULL;
UPDATE nodes SET form_json = REPLACE(form_json, '"type": "text", "placeholder": "21:10"', '"type": "time", "placeholder": "21:10"') WHERE form_json IS NOT NULL;
