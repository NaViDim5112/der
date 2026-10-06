<?php
// Модуль «Контент»: картинки-вложения в сообщениях, просмотр картинок на весь экран, опросы в темах,
// закладки на сообщения, помощники редактора (упоминания, мультицитата, черновики), RSS и новости для лаунчера.
//
// Файлы модуля:
//   app/modules/content.php           - общее: настройки, иконки, подключение стилей и скрипта, меню, журнал
//   app/modules/content_attach.php    - загрузка и обработка картинок, привязка к сообщениям, очистка
//   app/modules/content_poll.php      - опросы
//   app/modules/content_bookmark.php  - закладки, мультицитата, упоминания
//   app/modules/content_feed.php      - RSS и JSON новостей для лаунчера
//   public/forum/{upload,poll,bookmarks,user-suggest,rss}.php, public/api/news.php, public/admin/attachments.php
//   public/assets/css/content.css, public/assets/js/content.js, sql/migrations/0300_content.sql
// Все функции начинаются с cnt_.

// Таблицы модуля уже есть в базе (обновление 0300_content.sql применено). Проверка один раз за запрос.
function cnt_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            $n = (int)db_val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                AND table_name IN ('attachments', 'polls', 'poll_options', 'poll_votes', 'bookmarks')");
            $ok = $n === 5;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

// Адрес текущего скрипта без base_path: '/forum/forum.php'
function cnt_script()
{
    $s = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim((string)cfg('base_path', ''), '/');
    if ($base !== '' && strpos($s, $base . '/') === 0) {
        $s = substr($s, strlen($base));
    }
    return $s;
}

// Полный адрес для ссылок вне сайта (лаунчер, RSS): https://site/forum/...
function cnt_abs($path)
{
    return site_origin() . $path;
}

// Ответ JSON с ошибкой
function cnt_json_fail($msg, $code = 400, array $extra = [])
{
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

// Токен CSRF из поля формы или заголовка X-CSRF-Token (запросы из JS)
function cnt_csrf_ok()
{
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

// ---------- Настройки ----------

hook_add('settings_defaults', function ($d) {
    $d['attach_enabled'] = '1';       // загрузка картинок включена
    $d['attach_max_mb'] = '8';        // размер одного файла, МБ
    $d['attach_max_px'] = '2560';     // большие картинки уменьшаются до этой стороны
    $d['attach_daily_files'] = '30';  // файлов на пользователя за сутки (0 - без ограничения)
    $d['attach_daily_mb'] = '100';    // МБ на пользователя за сутки (0 - без ограничения)
    $d['poll_nodes'] = 'auto';        // auto - все разделы без анкет, иначе id через запятую
    $d['news_api_node2'] = '0';       // второй раздел для новостей лаунчера
    $d['news_api_limit'] = '10';      // сколько новостей отдавать по умолчанию
    return $d;
});

// ---------- Иконки ----------

hook_add('icon_paths', function ($p) {
    $p['poll'] = '<path d="M3 3v18h18"/><path d="M8 17v-4M13 17V7M18 17v-7"/>';
    $p['upload'] = '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>';
    $p['rss'] = '<path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>';
    $p['quote-plus'] = '<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.76-2.02-2-2H4c-1.25 0-2 .75-2 1.97V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .01-1 1.03V20c0 1 0 1 1 1z"/><path d="M18 8v8M14 12h8"/>';
    return $p;
});

// ---------- Стили, скрипт, RSS в <head> ----------

hook_add('page_head', function ($area, $o) {
    if ($area !== 'forum') {
        return '';
    }
    $h = '<link rel="stylesheet" href="' . e(asset('css/content.css')) . '">';
    $script = cnt_script();
    if ($script === '/forum/forum.php' || $script === '/forum/category.php') {
        $node = node_get(query_int('id'));
        if ($node && site_node_public($node)) {
            $h .= "\n" . '<link rel="alternate" type="application/rss+xml" title="' . e($node['title'] . ' - ' . setting('site_name')) . '" href="' . e(url('/forum/rss.php', ['node' => (int)$node['id']])) . '">';
        }
    } elseif ($script === '/forum/index.php' || $script === '/forum/') {
        $h .= "\n" . '<link rel="alternate" type="application/rss+xml" title="' . e('Форум - ' . setting('site_name')) . '" href="' . e(url('/forum/rss.php')) . '">';
    }
    return $h;
});

hook_add('page_scripts', function ($area, $o) {
    if ($area !== 'forum') {
        return '';
    }
    return '<script src="' . e(asset('js/content.js')) . '"></script>';
});

// Кнопка RSS рядом с «Отслеживать» в открытых для гостей разделах
hook_add('forum_actions', function ($node) {
    if (!site_node_public($node)) {
        return '';
    }
    return '<a class="btn btn-black btn-pill cnt-rss-btn" href="' . e(url('/forum/rss.php', ['node' => (int)$node['id']])) . '" title="RSS-лента раздела" target="_blank" rel="noopener">' . icon('rss') . ' RSS</a>';
});

// ---------- Меню ----------

hook_add('account_menu_links', function ($u) {
    return '<a href="' . e(url('/forum/bookmarks.php')) . '">Закладки</a>';
});

hook_add('sidebar_user_links', function ($nav, $u) {
    return '<a class="side-link" href="' . e(url('/forum/bookmarks.php')) . '">' . icon('bookmark') . '<span>Закладки</span></a>';
});

hook_add('admin_menu', function ($items) {
    $items['attachments'] = ['Вложения и опросы', 'image', '/admin/attachments.php'];
    return $items;
});

hook_add('admin_tiles', function ($tiles) {
    if (!cnt_ready()) {
        return $tiles;
    }
    try {
        $n = (int)db_val('SELECT COUNT(*) FROM attachments');
        $tiles[] = ['Вложений', num($n), 'image', '#14b8a6', url('/admin/attachments.php')];
    } catch (Exception $e) {
        // таблицы нет - плитку не показываем
    }
    return $tiles;
});

// ---------- Журнал модерации ----------

hook_add('modlog_labels', function ($l) {
    $l['attach_delete'] = 'Удалено вложение';
    $l['poll_close'] = 'Опрос закрыт';
    $l['poll_open'] = 'Опрос открыт';
    $l['poll_edit'] = 'Опрос изменён';
    $l['poll_delete'] = 'Опрос удалён';
    return $l;
});

hook_add('modlog_target', function ($r) {
    $id = (int)$r['target_id'];
    if ($r['target_type'] === 'attachment') {
        return '<span class="muted small">Вложение</span><br>#' . $id . (!empty($r['details']) ? ' <span class="muted small">' . e(str_limit($r['details'], 60)) . '</span>' : '');
    }
    if ($r['target_type'] === 'poll') {
        // target_id опроса - id темы
        return '<span class="muted small">Опрос в теме</span><br><a href="' . e(thread_url($id)) . '#poll">#' . $id . '</a>';
    }
    return null;
});
