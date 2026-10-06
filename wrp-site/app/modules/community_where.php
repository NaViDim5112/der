<?php
// Сообщество: виджет «Команда проекта онлайн» и «Кто где на форуме» (/forum/where.php).
// Место посетителя пишет ядро (online.location, users.last_location) - адрес страницы без base_path.
// Названия тем и разделов показываются только тем, кто может их видеть.

const COM_ONLINE_SECONDS = 900;

// Команда онлайн за 15 минут (один запрос, кэш на запрос)
function com_staff_online()
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    $rows = db_all('SELECT u.id, u.username, u.avatar, u.group_id, u.secondary_groups, u.custom_title, u.last_activity,
                           g.name AS group_name, g.color AS group_color, g.level, g.is_staff, g.can_moderate, g.can_admin
                    FROM users u JOIN user_groups g ON g.id = u.group_id
                    WHERE u.last_activity > :t AND u.is_banned = 0 AND (g.is_staff = 1 OR (u.secondary_groups IS NOT NULL AND u.secondary_groups <> \'\'))
                    ORDER BY u.last_activity DESC LIMIT 200', ['t' => date('Y-m-d H:i:s', time() - COM_ONLINE_SECONDS)]);
    foreach ($rows as $r) {
        $u = user_enrich($r);
        if (!empty($u['is_staff'])) {
            $list[] = $u;
        }
    }
    usort($list, function ($a, $b) {
        return (int)$b['level'] - (int)$a['level'] ?: strcmp((string)$b['last_activity'], (string)$a['last_activity']);
    });
    return $list;
}

hook_add('right_widgets_middle', function ($u) {
    if (setting('com_staff_widget') !== '1') {
        return '';
    }
    try {
        $staff = com_staff_online();
    } catch (Exception $e) {
        return '';
    }
    if (!$staff) {
        return '';
    }
    $max = 10;
    $h = '<div class="widget com-staff-widget"><h3 class="widget-title com-widget-title"><a href="' . e(url('/forum/staff.php')) . '">Команда проекта онлайн</a>'
        . '<span class="com-count">' . num(count($staff)) . '</span></h3><div class="com-staff-list">';
    foreach (array_slice($staff, 0, $max) as $s) {
        $h .= '<a class="com-staff-row" href="' . e(url('/forum/member.php', ['id' => (int)$s['id']])) . '">'
            . '<span class="com-staff-ava">' . avatar($s, 's') . '<span class="com-dot"></span></span>'
            . '<span class="com-staff-info"><span class="com-staff-name" style="color:' . e($s['group_color']) . '">' . e($s['username']) . '</span>'
            . '<span class="com-staff-group">' . e(acc_user_title($s)) . '</span></span></a>';
    }
    $h .= '</div>';
    if (count($staff) > $max) {
        $h .= '<div class="muted small com-staff-more">...и ещё ' . num(count($staff) - $max) . '</div>';
    }
    $h .= '<div class="com-widget-foot"><a href="' . e(url('/forum/where.php')) . '">' . icon('map-pin') . ' Кто где на форуме</a></div></div>';
    return $h;
});

// ---------- Где посетитель ----------

// Разбор адреса: ['path' => '/forum/thread.php', 'q' => [...]]
function com_where_parse($loc)
{
    $loc = (string)$loc;
    $path = (string)parse_url($loc, PHP_URL_PATH);
    $query = (string)parse_url($loc, PHP_URL_QUERY);
    $q = [];
    if ($query !== '') {
        parse_str($query, $q);
    }
    $path = preg_replace('~/+~', '/', $path);
    if ($path === '' || $path === '/index.php') {
        $path = '/';
    }
    if ($path === '/forum' || $path === '/forum/index.php') {
        $path = '/forum/';
    }
    if ($path === '/wiki/index.php' || $path === '/wiki') {
        $path = '/wiki/';
    }
    if ($path === '/cabinet/index.php' || $path === '/cabinet') {
        $path = '/cabinet/';
    }
    if ($path === '/admin' || $path === '/admin/index.php') {
        $path = '/admin/';
    }
    return ['path' => $path, 'q' => $q];
}

function com_where_qint(array $q, $key)
{
    $v = $q[$key] ?? null;
    return is_string($v) && ctype_digit($v) ? (int)$v : 0;
}

// Темы и пользователи, которые упоминаются в адресах: два запроса на всю страницу
function com_where_resolve(array $locs)
{
    $threadIds = [];
    $userIds = [];
    foreach ($locs as $loc) {
        $p = com_where_parse($loc);
        if ($p['path'] === '/forum/thread.php' && ($id = com_where_qint($p['q'], 'id'))) {
            $threadIds[$id] = true;
        } elseif ($p['path'] === '/forum/member.php' && ($id = com_where_qint($p['q'], 'id'))) {
            $userIds[$id] = true;
        }
    }
    $maps = ['threads' => [], 'users' => []];
    if ($threadIds) {
        $in = db_in(array_slice(array_keys($threadIds), 0, 500), 't');
        foreach (db_all('SELECT id, title, node_id, prefix_id, is_deleted FROM threads WHERE id IN (' . $in['sql'] . ')', $in['params']) as $t) {
            $maps['threads'][(int)$t['id']] = $t;
        }
    }
    if ($userIds) {
        $in = db_in(array_slice(array_keys($userIds), 0, 500), 'u');
        foreach (db_all('SELECT u.id, u.username, g.color AS group_color FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.id IN (' . $in['sql'] . ')', $in['params']) as $u) {
            $maps['users'][(int)$u['id']] = $u;
        }
    }
    return $maps;
}

// Описание места: ['icon', 'text', 'target' (название или null), 'url' (или null)]
// Названия закрытых для смотрящего тем и разделов не раскрываются, админка видна только команде.
function com_where_describe($loc, array $maps)
{
    $nowhere = ['icon' => 'map-pin', 'text' => 'Где-то на форуме', 'target' => null, 'url' => null];
    if ($loc === null || $loc === '') {
        return $nowhere;
    }
    $p = com_where_parse($loc);
    $path = $p['path'];
    $q = $p['q'];
    $simple = [
        '/' => ['home', 'Главная страница сайта'],
        '/start.php' => ['download', 'Читает, как начать играть'],
        '/partners.php' => ['handshake', 'Смотрит страницу сотрудничества'],
        '/forum/' => ['chats', 'Главная форума'],
        '/forum/members.php' => ['users', 'Смотрит список участников'],
        '/forum/online.php' => ['users', 'Смотрит, кто на форуме'],
        '/forum/where.php' => ['map-pin', 'Смотрит, кто где на форуме'],
        '/forum/staff.php' => ['shield', 'Смотрит список администрации'],
        '/forum/search.php' => ['search', 'Ищет на форуме'],
        '/forum/find.php' => ['search', 'Ищет новые сообщения'],
        '/forum/alerts.php' => ['bell', 'Читает оповещения'],
        '/forum/conversations.php' => ['mail', 'Личные сообщения'],
        '/forum/account.php' => ['settings', 'Настройки аккаунта'],
        '/forum/watched.php' => ['bell', 'Отслеживаемые темы'],
        '/forum/login.php' => ['login', 'Входит на форум'],
        '/forum/login-2fa.php' => ['login', 'Входит на форум'],
        '/forum/register.php' => ['user-plus', 'Регистрируется'],
        '/forum/lost-password.php' => ['lock', 'Восстанавливает пароль'],
        '/forum/trophies.php' => ['trophy', 'Смотрит награды форума'],
        '/forum/profile-posts.php' => ['chat', 'Читает сообщения профилей'],
        '/forum/post.php' => ['edit', 'Редактирует сообщение'],
        '/wiki/' => ['help', 'База знаний'],
        '/cabinet/' => ['gamepad', 'Личный кабинет'],
    ];
    if (isset($simple[$path])) {
        return ['icon' => $simple[$path][0], 'text' => $simple[$path][1], 'target' => null, 'url' => null];
    }
    if (strpos($path, '/wiki/') === 0) {
        return ['icon' => 'help', 'text' => 'Читает базу знаний', 'target' => null, 'url' => url('/wiki/')];
    }
    if (strpos($path, '/admin/') === 0) {
        if (!is_staff()) {
            return $nowhere;
        }
        $items = function_exists('adm_menu_items') && can_admin() ? adm_menu_items() : [];
        $section = null;
        foreach ($items as $it) {
            if (rtrim($it[2], '/') === rtrim($path, '/')) {
                $section = $it[0];
            }
        }
        return ['icon' => 'shield', 'text' => 'Админ-панель', 'target' => $section, 'url' => null];
    }
    if ($path === '/forum/thread.php') {
        $t = $maps['threads'][com_where_qint($q, 'id')] ?? null;
        $node = $t ? node_get($t['node_id']) : null;
        if (!$t || !$node || !node_can_view($node) || (!empty($t['is_deleted']) && !can_moderate())) {
            return $nowhere;
        }
        return ['icon' => 'chat', 'text' => 'Читает тему', 'target' => $t['title'], 'url' => thread_url($t['id']), 'prefix' => $t['prefix_id']];
    }
    if ($path === '/forum/forum.php' || $path === '/forum/category.php' || $path === '/forum/new-thread.php') {
        $node = node_get(com_where_qint($q, $path === '/forum/new-thread.php' ? 'node' : 'id'));
        if (!$node || !node_can_view($node)) {
            return $nowhere;
        }
        if ($path === '/forum/new-thread.php') {
            return ['icon' => 'edit', 'text' => 'Пишет новую тему в разделе', 'target' => $node['title'], 'url' => node_url($node)];
        }
        return ['icon' => 'folder', 'text' => $node['type'] === 'category' ? 'Просматривает категорию' : 'Просматривает раздел', 'target' => $node['title'], 'url' => node_url($node)];
    }
    if ($path === '/forum/member.php') {
        $u = $maps['users'][com_where_qint($q, 'id')] ?? null;
        if (!$u) {
            return ['icon' => 'user', 'text' => 'Смотрит профиль', 'target' => null, 'url' => null];
        }
        return ['icon' => 'user', 'text' => 'Смотрит профиль', 'target' => $u['username'], 'url' => url('/forum/member.php', ['id' => (int)$u['id']]), 'user' => $u];
    }
    return $nowhere;
}

// HTML описания места
function com_where_html(array $d)
{
    $h = '<span class="com-where-text">' . e($d['text']);
    if ($d['target'] !== null && $d['target'] !== '') {
        if (!empty($d['user'])) {
            $h .= ' ' . user_link($d['user']);
        } else {
            $label = '«' . e(str_limit($d['target'], 80)) . '»';
            $h .= ' ' . (!empty($d['prefix']) ? prefix_html($d['prefix']) : '') . ($d['url'] ? '<a href="' . e($d['url']) . '">' . $label . '</a>' : $label);
        }
    }
    return $h . '</span>';
}
