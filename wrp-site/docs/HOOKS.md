# Модули и точки расширения

Новые функции форума делаются модулями и не правят страницы ядра.

## Как устроен модуль

- Код: `app/modules/<имя>.php`. Подключается сам после `app/lib/*.php`, по алфавиту. В файле только функции, `hook_add(...)` и `cron_register(...)`. При подключении файла ничего не выводить и не ходить в базу.
- Свои страницы: `public/forum/<имя>.php` или `public/admin/<имя>.php`. Каждая начинается с `require __DIR__ . '/../../app/bootstrap.php';`, проверяет права (`require_login()`, `require_moderator()`, `require_admin()`), а POST проверяет через `csrf_check()`.
- Стили и скрипты: `public/assets/css/<имя>.css`, `public/assets/js/<имя>.js`. Подключать через `forum_header(['css' => [...], 'js' => [...]])` на своих страницах, а на чужих через `page_head` и `page_scripts`.
- База: файл `sql/migrations/NNNN_<имя>.sql`, номер из своего диапазона: ядро 0001-0099, процессы 0100-0199, модерация 0200-0299, контент 0300-0399, сообщество 0400-0499.
  - Таблицы через `CREATE TABLE IF NOT EXISTS`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
  - Колонки через `ALTER TABLE ... ADD ...`: ошибка «уже есть» при повторном запуске пропускается.
  - Данные через `INSERT IGNORE` или `UPDATE`.
  - Каждый запрос в одну строку или с `;` только в конце последней строки.
  - `sql/schema.sql` и `sql/seed.sql` не трогать.
- Модуль должен работать, даже если его обновление базы ещё не применено (страница не падает). Для этого запросы к своим таблицам оборачивать в `try { ... } catch (Exception $e) { ... }` там, где модуль встраивается в чужие страницы.
- Настройки: значения по умолчанию через фильтр `settings_defaults`, чтение через `setting('ключ')`, запись на своей странице админки через `setting_set('ключ', 'значение')`.
- Фоновые задачи: `cron_register('имя', секунды, function () { ... });`. Запускаются сами после ответа страницы, не чаще раза в минуту. Из консоли: `php tools/cron.php [--all]`.
- Оповещения: `alert_add($userId, 'тип', $actorId, $threadId, $postId, $extra)`. Тип - до 24 символов. Текст и ссылку своего типа задают фильтры `alert_text` и `alert_link`.
- Журнал модерации: `mod_log('действие', 'тип_цели', $id, 'подробности')`. Подписи действий даёт фильтр `modlog_labels`, а вывод своей цели - `modlog_target`.

## Правила кода (как во всём проекте)

- Совместимость с PHP 7.4: без `match`, `?->`, именованных аргументов, `enum` и `readonly`.
- Запросы только через `db_one / db_all / db_val / db_exec / db_insert / db_update`, все значения параметрами. Один именованный плейсхолдер не повторять в одном запросе (`:a` дважды - ошибка). Списки передавать через `db_in($ids, 'pfx')`, затем `$in['sql']` и `$in['params']`.
- Вывод: текст через `e()`, BB-коды через `bbcode()`, ссылки через `url('/путь', [...])`, формы с `csrf_field()`.
- Права: `is_logged()`, `uid()`, `user()`, `user_level()`. Уровни: 10 пользователь, 20 лидер, 30 хелпер, 40 модератор, 60 администратор, 80 старший администратор, 90 разработчик, 100 главный администратор. Также `is_staff()`, `can_moderate()`, `can_admin()`, `is_banned()`, `node_can_view($node)`, `node_can_reply($node)`.
- Сайт не обещает того, чего нет в игре. Сейчас нет работ, банд, семей, ФБР, армии, больницы, казино и Android-клиента. Есть Правительство (12 рангов), Департамент полиции (14 рангов), дома, гаражи, автосалон, банк, лаунчер.

## Вызовы

- `hook_add($имя, $функция, $приоритет = 10)`: меньше приоритет - раньше.
- `hook_html($имя, ...)`: склеивает строки HTML от всех модулей.
- `hook_fire($имя, ...)`: событие.
- `hook_filter($имя, $значение, ...)`: функция получает значение первым аргументом и обязана его вернуть.
- `hook_first($имя, ...)`: первый ответ не `null` побеждает.

## Точки

### Общие страницы (`app/lib/layout.php`, `app/lib/site.php`)

| Точка | Вид | Аргументы | Где |
|---|---|---|---|
| `page_head` | html | `$area` ('forum' / 'site'), `$o` (параметры страницы) | в `<head>` |
| `page_notices` | html | `'forum'`, `$o` | под объявлением, над сообщениями flash |
| `page_footer` | html | `'forum'`, `$o` | перед скриптами (модальные окна и т.п.) |
| `page_scripts` | html | `$area`, `$o` | после всех скриптов |
| `header_icons` | html | `$u` | в шапке перед значками ЛС и оповещений (только для вошедших) |
| `account_menu_links` | html | `$u` | меню аккаунта, блок «Мой профиль / Оповещения ...» |
| `account_menu_settings` | html | `$u` | меню аккаунта, блок настроек |
| `sidebar_links` | html | `$nav` | левое меню, после «База знаний» |
| `sidebar_user_links` | html | `$nav`, `$u` | левое меню «Раздел навигации» (вошедшие) |
| `right_widgets_top` / `right_widgets_middle` / `right_widgets_bottom` | html | `$u` (или null) | правая колонка: после входа, перед «Пользователи онлайн», в конце |

Виджет оформляется так: `<div class="widget"><h3 class="widget-title">...</h3>...</div>`.

`forum_header(['location' => '...'])` задаёт своё место для «Сейчас на форуме». По умолчанию пишется адрес страницы без base_path в `online.location` и `users.last_location`.

### Форум: список тем (`public/forum/forum.php`, `fp_thread_row`)

| Точка | Вид | Аргументы | Что делает |
|---|---|---|---|
| `forum_orders` | filter | `$orders` (['ключ' => ['Название', 'SQL ORDER BY']]), `$node` | свои сортировки |
| `forum_threads_where` | filter | `[$where, $params]`, `$node` | условия списка (`$where` - массив строк для AND) |
| `forum_actions` | html | `$node` | кнопки рядом с «Создать тему» |
| `forum_before_list` | html | `$node` | над списком тем |
| `forum_list_after` | html | `$node`, `$pg` | под списком тем |
| `thread_row_start` | html | `$t`, `$opts` | в начале строки темы (чекбокс массовых действий) |
| `thread_row_title_after` | html | `$t`, `$opts` | после названия темы (значки) |

### Тема (`public/forum/thread.php`)

| Точка | Вид | Аргументы | Что делает |
|---|---|---|---|
| `thread_meta` | filter | `$meta` (HTML строки под заголовком), `$thread`, `$node` | |
| `thread_actions` | html | `$thread`, `$node` | кнопки у заголовка темы |
| `thread_mod_menu` | html | `$thread`, `$node` | пункты меню модерации (элементы `<a>` / `<button>` в `.dropdown-menu`) |
| `thread_before_posts` / `thread_after_posts` | html | `$thread`, `$node`, `$pg` | над и под сообщениями (опрос, ход рассмотрения) |
| `thread_reply_form` | html | `$thread`, `$node` | внутри формы ответа, после поля текста |
| `thread_reply_denied` | filter | `''`, `$thread`, `$node` | вернуть текст причины, если отвечать нельзя (бан ответов) |

### Сообщение (`app/lib/forum_posts.php`)

`$ctx`: `thread`, `node`, `pos`, `is_first`, `likes`, `can_quote`, `inner`.

| Точка | Вид | Аргументы | Где |
|---|---|---|---|
| `post_head_right` | html | `$p`, `$ctx` | шапка сообщения справа, перед «Поделиться» |
| `post_after_body` | html | `$p`, `$ctx` | после текста, перед подписью (вложения, «Изменено») |
| `post_actions_left` | html | `$p`, `$ctx` | низ сообщения слева («Жалоба», «История») |
| `post_actions_right` | html | `$p`, `$ctx` | низ сообщения справа («+ Цитата») |
| `post_footer_after` | html | `$p`, `$ctx` | после строки реакций |
| `post_user_rows` | filter | `$rows` ([['Подпись:', 'значение'], ...], значения - простой текст), `$u` | строки карточки автора |
| `post_user_after` | html | `$u` | под карточкой автора (награды) |

Карточка автора и `user_points` выводятся для каждого сообщения. Данные для них модуль должен собирать одним запросом на страницу (static-кэш), а не запросом на каждое сообщение.

### Создание и правка (`fp_create_thread`, `fp_create_post`, `new-thread.php`, `post.php`)

| Точка | Вид | Аргументы |
|---|---|---|
| `new_thread_validate` | filter | `$errors` (['поле' => 'текст']), `$node`, `$useForm` |
| `new_thread_form` | html | `$node`, `$useForm`, `$errors` |
| `thread_title_save` | filter | `$title`, `$node` |
| `post_body_save` | filter | `$body`, `$node`, `$thread` (null для новой темы) |
| `thread_created` | fire | `$threadId`, `$node`, `$postId` |
| `post_created` | fire | `$postId`, `$thread`, `$node` |
| `post_edit_validate` | filter | `$errors`, `$post`, `$thread`, `$node` |
| `post_edit_form` | html | `$post`, `$thread`, `$node` |
| `post_before_update` | fire | `$post` (старая версия), `$body` (новый текст), `$thread`, `$node` |
| `post_updated` | fire | `$post` (старая версия), `$body`, `$thread`, `$node` |
| `post_deleted` / `post_restored` | fire | `$post`, `$thread`, `$node` |
| `thread_moderated` | fire | `$thread` (до действия), `$node`, `$do` (lock, unlock, pin, unpin, prefix, move, delete, restore, ...) |

### Профиль (`public/forum/member.php`)

| Точка | Вид | Аргументы |
|---|---|---|
| `member_tabs` | filter | `$tabs` (['ключ' => 'Название']), `$m`, `$tab` |
| `member_tab_content` | html | `$tab`, `$m`, `$page`: вывести содержимое своей вкладки |
| `member_actions` | html | `$m`, `$isSelf`: кнопки под обложкой |
| `member_cover` | html | `$m`: на обложке под плашками |
| `member_before_tabs` | html | `$m`, `$tab` |
| `member_info_rows` | html | `$m`: строки `<dt>..</dt><dd>..</dd>` в «Сведениях» |
| `member_about_main` / `member_about_side` | html | `$m`: карточки на вкладке «Информация» |
| `member_action` | fire | `$action`, `$m`, `$fail`, `$return`: POST-действие модуля (сам делает `redirect`) |
| `wall_post_actions` / `wall_comment_actions` | html | `$p` / `$c`, `$ownerId` |

### Настройки аккаунта (`public/forum/account.php`)

| Точка | Вид | Аргументы |
|---|---|---|
| `account_tabs` | filter | `$tabs` (['ключ' => ['Название', 'иконка']]), `$me` |
| `account_tab_content` | html | `$tab`, `$me` |
| `account_action` | fire | `$action`, `$tab`, `$me`: POST-действие модуля (CSRF уже проверен) |

### Вход и личные сообщения

| Точка | Вид | Аргументы |
|---|---|---|
| `login_intercept` | first | `$u`, `$remember`, `$return`: вернуть адрес следующего шага (например, код 2FA) или null |
| `user_logged_in` | fire | `$u` |
| `conversation_message_actions` | html | `$msg`, `$convId` |

### Прочее ядро

| Точка | Вид | Аргументы |
|---|---|---|
| `bbcode_html` | filter | `$html` (текст уже экранирован, блоки кода вынуты): свои теги вроде `[attach=N]`, всё вставляемое экранировать самому |
| `alert_text` | filter | `null`, `$a`, `$actor` (HTML), `$title` (HTML): вернуть HTML для своего типа, иначе вернуть то, что пришло |
| `alert_link` | filter | `null`, `$a` |
| `settings_defaults` | filter | `$defaults` |
| `user_points` | filter | `$points`, `$u` |
| `admin_menu` | filter | `$items` (['ключ' => ['Название', 'иконка', '/admin/x.php']]) |
| `admin_tiles` | filter | `$tiles` ([[название, число, иконка, цвет, ссылка]]) |
| `admin_dashboard` | html | - |
| `modlog_labels` | filter | `[]` -> ['действие' => 'Подпись'] |
| `modlog_target` | first | `$r` (строка журнала): HTML цели или null |

Иконки: `icon('имя')`, список имён - в `app/lib/icons.php`. Свою иконку (SVG 24x24, линии) модуль добавляет фильтром `icon_paths`: `$p['имя'] = '<path d="..."/>'`.
