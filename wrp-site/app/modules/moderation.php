<?php
// Модуль «Модерация»: жалобы, центр жалоб, предупреждения с баллами и порогами, запрет ответов в теме,
// история правок сообщений, IP-адреса для администраторов.
// Файлы модуля: app/modules/moderation*.php, public/forum/{report,reports,warn,post-history,moderation}.php,
// public/admin/{warnings,ips}.php, assets/css/moderation.css, assets/js/moderation.js, sql/migrations/0200_moderation.sql.
// Все функции модуля начинаются с mdr_.

// ---------- Готовность базы ----------

// Таблицы модуля созданы (обновление базы 0200 применено). Проверка один раз за запрос.
function mdr_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)db_val("SHOW TABLES LIKE 'user_ips'");
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

// Выполнить код модуля на чужой странице. Если обновление базы ещё не применено
// (нет таблицы или колонки), вернуть $default, а не ронять страницу.
function mdr_safe($fn, $default = '')
{
    static $broken = false;
    if ($broken) {
        return $default;
    }
    try {
        return call_user_func($fn);
    } catch (PDOException $e) {
        $state = (string)$e->getCode();
        if ($state === '42S02' || $state === '42S22') {
            $broken = true;
            return $default;
        }
        throw $e;
    }
}

// Страница модуля без обновления базы: понятное сообщение вместо ошибки
function mdr_require_ready()
{
    if (!mdr_ready()) {
        abort(503, 'Модерация ещё не готова: нужно обновить базу сайта. Администратор может сделать это в админ-панели - кнопка «Обновить базу».');
    }
}

// ---------- Права ----------

// Уровень пользователя с учётом дополнительных групп (для любой строки с group_id и secondary_groups)
function mdr_level_of($u)
{
    if (!$u) {
        return 0;
    }
    $groups = groups_all();
    $gid = (int)($u['group_id'] ?? 0);
    $level = isset($groups[$gid]) ? (int)$groups[$gid]['level'] : 0;
    if (isset($u['level'])) {
        $level = max($level, (int)$u['level']);
    }
    foreach (explode(',', (string)($u['secondary_groups'] ?? '')) as $sid) {
        $sid = (int)$sid;
        if ($sid > 0 && isset($groups[$sid])) {
            $level = max($level, (int)$groups[$sid]['level']);
        }
    }
    return $level;
}

// Можно ли текущему модератору выдать предупреждение (или запрет ответов) этому пользователю:
// не себе и только тем, у кого уровень ниже моего
function mdr_can_punish($target)
{
    if (!can_moderate() || !$target || empty($target['id'])) {
        return false;
    }
    if ((int)$target['id'] === uid()) {
        return false;
    }
    return mdr_level_of($target) < user_level();
}

// Почему нельзя наказать пользователя ('' - можно)
function mdr_punish_error($target)
{
    if (!can_moderate()) {
        return 'Действие только для модераторов.';
    }
    if (!$target || empty($target['id'])) {
        return 'Пользователь не найден.';
    }
    if ((int)$target['id'] === uid()) {
        return 'Нельзя наказать самого себя.';
    }
    if (mdr_level_of($target) >= user_level()) {
        return 'Нельзя наказать пользователя с таким же или более высоким уровнем доступа.';
    }
    return '';
}

// ---------- Справочники ----------

function mdr_report_reasons()
{
    return [
        'insult' => 'Оскорбления',
        'flood' => 'Флуд / оффтоп',
        'ads' => 'Реклама',
        'fraud' => 'Обман',
        'rules' => 'Нарушение правил форума',
        'other' => 'Другое',
    ];
}

// Какой вид предупреждения предложить по причине жалобы (id из обновления базы 0200)
function mdr_reason_warning_type($reason)
{
    $map = ['insult' => 1, 'flood' => 2, 'ads' => 3, 'fraud' => 4];
    return $map[$reason] ?? 0;
}

function mdr_content_types()
{
    return [
        'post' => 'Сообщение на форуме',
        'profile_post' => 'Сообщение в профиле',
        'profile_comment' => 'Комментарий в профиле',
        'message' => 'Личное сообщение',
        'user' => 'Профиль пользователя',
    ];
}

// ---------- Материалы (сообщения, стена, ЛС, профиль) ----------

// Короткая строка пользователя из строки запроса
function mdr_user_row($id, $name, $avatar = null, $color = null, $groupId = 0, $secondary = null)
{
    if ($name === null || $name === '') {
        return ['id' => 0, 'username' => 'Удалённый пользователь', 'avatar' => null, 'group_color' => null, 'group_id' => 0, 'secondary_groups' => null];
    }
    return ['id' => (int)$id, 'username' => $name, 'avatar' => $avatar, 'group_color' => $color, 'group_id' => (int)$groupId, 'secondary_groups' => $secondary];
}

// Пользователи по id одним запросом: [id => строка]
function mdr_users_by_ids(array $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = db_in($ids, 'u');
    $out = [];
    foreach (db_all('SELECT u.id, u.username, u.avatar, u.group_id, u.secondary_groups, u.is_banned, u.ban_until, g.color AS group_color
        FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.id IN (' . $in['sql'] . ')', $in['params']) as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

// Материалы пачкой. $pairs: [['post', 5], ['message', 7], ...]. Возвращает ['post:5' => материал].
// Материал: type, id, user_id (автор), body, created_at, deleted, visible (можно смотреть текущему пользователю),
// link (адрес или ''), context (HTML: где находится), author, owner (для стены).
function mdr_content_load_many(array $pairs)
{
    $byType = [];
    foreach ($pairs as $p) {
        if (isset(mdr_content_types()[$p[0]]) && (int)$p[1] > 0) {
            $byType[$p[0]][(int)$p[1]] = true;
        }
    }
    $out = [];
    $userIds = [];

    if (!empty($byType['post'])) {
        $in = db_in(array_keys($byType['post']), 'p');
        $rows = db_all('SELECT p.id, p.user_id, p.body, p.is_deleted, p.thread_id, p.created_at, t.title AS thread_title, t.node_id, t.is_deleted AS thread_deleted
            FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.id IN (' . $in['sql'] . ')', $in['params']);
        foreach ($rows as $r) {
            $node = node_get($r['node_id']);
            $deleted = (int)$r['is_deleted'] || (int)$r['thread_deleted'];
            $visible = $node && node_can_view($node) && (!$deleted || can_moderate());
            $out['post:' . $r['id']] = [
                'type' => 'post', 'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'body' => (string)$r['body'],
                'created_at' => $r['created_at'], 'deleted' => (bool)$deleted, 'visible' => $visible,
                'thread_id' => (int)$r['thread_id'], 'thread_title' => $r['thread_title'], 'owner_id' => 0,
                'link' => $visible ? post_url($r['id']) : '',
                'context' => $visible ? 'в теме «<a href="' . e(thread_url($r['thread_id'])) . '">' . e($r['thread_title']) . '</a>»' : 'в разделе, закрытом для вас',
            ];
            $userIds[] = (int)$r['user_id'];
        }
    }

    if (!empty($byType['profile_post'])) {
        $in = db_in(array_keys($byType['profile_post']), 'pp');
        foreach (db_all('SELECT id, user_id, profile_user_id, body, is_deleted, created_at FROM profile_posts WHERE id IN (' . $in['sql'] . ')', $in['params']) as $r) {
            $owner = (int)$r['profile_user_id'];
            $out['profile_post:' . $r['id']] = [
                'type' => 'profile_post', 'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'body' => (string)$r['body'],
                'created_at' => $r['created_at'], 'deleted' => (bool)$r['is_deleted'], 'visible' => !$r['is_deleted'] || can_moderate(),
                'thread_id' => 0, 'owner_id' => $owner,
                'link' => url('/forum/member.php', ['id' => $owner]) . '#profile-post-' . (int)$r['id'],
                'context' => '',
            ];
            $userIds[] = (int)$r['user_id'];
            $userIds[] = $owner;
        }
    }

    if (!empty($byType['profile_comment'])) {
        $in = db_in(array_keys($byType['profile_comment']), 'pc');
        foreach (db_all('SELECT c.id, c.user_id, c.body, c.is_deleted, c.created_at, c.profile_post_id, pp.profile_user_id, pp.is_deleted AS parent_deleted
            FROM profile_comments c JOIN profile_posts pp ON pp.id = c.profile_post_id WHERE c.id IN (' . $in['sql'] . ')', $in['params']) as $r) {
            $owner = (int)$r['profile_user_id'];
            $deleted = (int)$r['is_deleted'] || (int)$r['parent_deleted'];
            $out['profile_comment:' . $r['id']] = [
                'type' => 'profile_comment', 'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'body' => (string)$r['body'],
                'created_at' => $r['created_at'], 'deleted' => (bool)$deleted, 'visible' => !$deleted || can_moderate(),
                'thread_id' => 0, 'owner_id' => $owner,
                'link' => url('/forum/member.php', ['id' => $owner]) . '#profile-comment-' . (int)$r['id'],
                'context' => '',
            ];
            $userIds[] = (int)$r['user_id'];
            $userIds[] = $owner;
        }
    }

    if (!empty($byType['message'])) {
        $in = db_in(array_keys($byType['message']), 'm');
        $rows = db_all('SELECT id, user_id, body, created_at, conversation_id FROM conversation_messages WHERE id IN (' . $in['sql'] . ')', $in['params']);
        // Ссылка на переписку - только участникам. Модератор видит лишь само сообщение из жалобы.
        $mine = [];
        if ($rows && is_logged()) {
            $cin = db_in(array_values(array_unique(array_map(function ($r) {
                return (int)$r['conversation_id'];
            }, $rows))), 'c');
            foreach (db_all('SELECT conversation_id FROM conversation_users WHERE user_id = :u AND is_left = 0 AND conversation_id IN (' . $cin['sql'] . ')', array_merge(['u' => uid()], $cin['params'])) as $c) {
                $mine[(int)$c['conversation_id']] = true;
            }
        }
        foreach ($rows as $r) {
            $isMember = !empty($mine[(int)$r['conversation_id']]);
            $out['message:' . $r['id']] = [
                'type' => 'message', 'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'body' => (string)$r['body'],
                'created_at' => $r['created_at'], 'deleted' => false, 'visible' => $isMember, 'is_member' => $isMember,
                'thread_id' => 0, 'owner_id' => (int)$r['conversation_id'],
                'link' => $isMember ? url('/forum/conversations.php', ['id' => (int)$r['conversation_id']]) . '#msg-' . (int)$r['id'] : '',
                'context' => 'в личной переписке',
            ];
            $userIds[] = (int)$r['user_id'];
        }
    }

    if (!empty($byType['user'])) {
        $in = db_in(array_keys($byType['user']), 'uu');
        foreach (db_all('SELECT id, status_text, custom_title, location, about, signature, created_at FROM users WHERE id IN (' . $in['sql'] . ')', $in['params']) as $r) {
            $out['user:' . $r['id']] = [
                'type' => 'user', 'id' => (int)$r['id'], 'user_id' => (int)$r['id'], 'body' => mdr_profile_text($r),
                'created_at' => $r['created_at'], 'deleted' => false, 'visible' => true,
                'thread_id' => 0, 'owner_id' => (int)$r['id'],
                'link' => url('/forum/member.php', ['id' => (int)$r['id']]),
                'context' => '',
            ];
            $userIds[] = (int)$r['id'];
        }
    }

    $users = mdr_users_by_ids($userIds);
    foreach ($out as $k => $c) {
        $a = $users[$c['user_id']] ?? null;
        $out[$k]['author'] = $a ? $a : mdr_user_row(0, null);
        if (in_array($c['type'], ['profile_post', 'profile_comment'], true)) {
            $o = $users[$c['owner_id']] ?? null;
            $out[$k]['owner'] = $o ? $o : mdr_user_row(0, null);
            $out[$k]['context'] = 'в профиле ' . user_link($out[$k]['owner']);
        }
    }
    return $out;
}

function mdr_content_load($type, $id)
{
    $all = mdr_content_load_many([[(string)$type, (int)$id]]);
    return $all[$type . ':' . (int)$id] ?? null;
}

// Текст профиля для жалобы на профиль (BB-код)
function mdr_profile_text(array $r)
{
    $parts = [];
    $labels = ['status_text' => 'Статус', 'custom_title' => 'Звание', 'location' => 'Откуда', 'about' => 'О себе', 'signature' => 'Подпись'];
    foreach ($labels as $k => $label) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '') {
            $parts[] = '[b]' . $label . ':[/b] ' . $v;
        }
    }
    return $parts ? implode("\n", $parts) : '';
}

// Предпросмотр текста материала (HTML)
function mdr_content_preview($body, $class = '')
{
    if (trim((string)$body) === '') {
        return '<div class="mdr-preview muted ' . e($class) . '">Текста нет.</div>';
    }
    return '<div class="mdr-preview bb ' . e($class) . '">' . bbcode($body) . '</div>';
}

// ---------- IP-адреса ----------

function mdr_ip_log($userId, $ip = null)
{
    $userId = (int)$userId;
    $ip = $ip === null ? client_ip() : (string)$ip;
    if ($userId <= 0 || $ip === '') {
        return;
    }
    $t = now();
    db_exec('INSERT INTO user_ips (user_id, ip, first_seen, last_seen, hits) VALUES (:u, :ip, :f, :l, 1)
             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), hits = hits + 1',
        ['u' => $userId, 'ip' => $ip, 'f' => $t, 'l' => $t]);
}

// ---------- Ссылки модуля ----------

function mdr_report_link($type, $id, $class = 'post-act', $label = 'Пожаловаться')
{
    return '<a class="' . e($class) . ' mdr-report-link" href="' . e(url('/forum/report.php', ['type' => $type, 'id' => (int)$id])) . '" data-mdr-report rel="nofollow" title="Сообщить модераторам о нарушении">'
        . icon('flag') . '<span>' . e($label) . '</span></a>';
}

// Количество открытых жалоб (кэш на запрос)
function mdr_open_reports_count()
{
    static $n = null;
    if ($n === null) {
        $n = (int)mdr_safe(function () {
            return db_val("SELECT COUNT(*) FROM mod_reports WHERE status = 'open'");
        }, 0);
    }
    return $n;
}

// ---------- Подключение к страницам ----------

hook_add('settings_defaults', function ($d) {
    $d['mod_report_limit'] = '10';
    $d['mod_warn_pm'] = '0';
    return $d;
});

hook_add('page_head', function ($area, $o) {
    if ($area !== 'forum' || in_array('moderation.css', $o['css'] ?? [], true)) {
        return '';
    }
    return '<link rel="stylesheet" href="' . e(asset('css/moderation.css')) . '">' . "\n";
});

hook_add('page_scripts', function ($area, $o) {
    if ($area !== 'forum' || !is_logged() || in_array('moderation.js', $o['js'] ?? [], true)) {
        return '';
    }
    return '<script src="' . e(asset('js/moderation.js')) . '"></script>' . "\n";
});

// Значок жалоб в шапке для команды
hook_add('header_icons', function ($u) {
    if (!is_staff()) {
        return '';
    }
    $n = mdr_open_reports_count();
    return '<a class="btn btn-ghost btn-icon mdr-hicon" href="' . e(url('/forum/reports.php')) . '" title="Центр жалоб' . ($n ? ': открыто ' . $n : '') . '">'
        . icon('flag') . ($n ? '<span class="count-badge">' . $n . '</span>' : '') . '</a>';
});

hook_add('sidebar_user_links', function ($nav, $u) {
    if (!is_staff()) {
        return '';
    }
    $n = mdr_open_reports_count();
    return '<a class="side-link" href="' . e(url('/forum/reports.php')) . '">' . icon('flag') . '<span>Центр жалоб</span>'
        . ($n ? '<span class="mdr-side-count">' . $n . '</span>' : '') . '</a>';
});

hook_add('admin_menu', function ($items) {
    $items['mdr_reports'] = ['Центр жалоб', 'flag', '/forum/reports.php'];
    $items['mdr_warnings'] = ['Предупреждения', 'alert', '/admin/warnings.php'];
    $items['mdr_ips'] = ['IP-адреса', 'server', '/admin/ips.php'];
    return $items;
});

hook_add('admin_tiles', function ($tiles) {
    $n = mdr_open_reports_count();
    $tiles[] = ['Открытые жалобы', $n, 'flag', '#ef4444', url('/forum/reports.php')];
    $w = (int)mdr_safe(function () {
        return db_val('SELECT COUNT(*) FROM mod_warnings WHERE revoked_at IS NULL AND is_expired = 0 AND (expires_at IS NULL OR expires_at > :n)', ['n' => now()]);
    }, 0);
    $tiles[] = ['Действующие предупреждения', $w, 'alert', '#f59e0b', url('/admin/warnings.php')];
    return $tiles;
});

hook_add('modlog_labels', function ($l) {
    return array_merge($l, [
        'report_claim' => 'Жалоба взята',
        'report_resolve' => 'Жалоба: нарушение подтверждено',
        'report_reject' => 'Жалоба: нарушений нет',
        'report_reopen' => 'Жалоба открыта снова',
        'warning_add' => 'Предупреждение',
        'warning_revoke' => 'Предупреждение снято',
        'warning_ban' => 'Бан за баллы предупреждений',
        'warning_unban' => 'Снят бан за баллы',
        'reply_ban' => 'Запрет ответов',
        'reply_unban' => 'Снят запрет ответов',
        'warning_type' => 'Вид предупреждения изменён',
        'warning_type_del' => 'Вид предупреждения удалён',
        'warning_rule' => 'Порог баллов изменён',
        'warning_rule_del' => 'Порог баллов удалён',
        'mod_settings' => 'Настройки модерации',
    ]);
});

hook_add('modlog_target', function ($r) {
    $id = (int)$r['target_id'];
    switch ($r['target_type']) {
        case 'report':
            return '<span class="muted small">Жалоба</span><br><a href="' . e(url('/forum/reports.php', ['id' => $id])) . '">#' . $id . '</a>';
        case 'warning_type':
            return '<span class="muted small">Вид предупреждения</span><br><a href="' . e(url('/admin/warnings.php')) . '">#' . $id . '</a>';
        case 'warning_rule':
            return '<span class="muted small">Порог баллов</span><br><a href="' . e(url('/admin/warnings.php')) . '">#' . $id . '</a>';
        case 'mod_settings':
            return '<span class="muted small">Настройки</span><br><a href="' . e(url('/admin/warnings.php')) . '">Модерация</a>';
    }
    return null;
});

// Оповещения модуля: mod_report (жалоба рассмотрена), mod_warning (предупреждение), mod_replyban (запрет ответов)
hook_add('alert_text', function ($text, $a, $actor, $title) {
    switch ($a['type']) {
        case 'mod_report':
            $parts = explode('|', (string)$a['extra']);
            $what = [
                'post' => 'на сообщение' . ($title !== '' ? ' в теме ' . $title : ''),
                'profile_post' => 'на сообщение в профиле',
                'profile_comment' => 'на комментарий в профиле',
                'message' => 'на личное сообщение',
                'user' => 'на профиль пользователя',
            ];
            $verdict = ($parts[0] ?? '') === 'resolved' ? 'нарушение подтверждено' : 'нарушений не найдено';
            return 'Ваша жалоба ' . ($what[$parts[1] ?? ''] ?? '') . ' рассмотрена: <b>' . $verdict . '</b>';
        case 'mod_warning':
            $parts = explode('|', (string)$a['extra'], 3);
            $pts = (int)($parts[0] ?? 0);
            return 'Вы получили предупреждение <b>«' . e($parts[2] ?? '') . '»</b>' . ($pts ? ' (+' . $pts . ' ' . plural($pts, 'балл', 'балла', 'баллов') . ')' : '')
                . ($title !== '' ? ' за сообщение в теме ' . $title : '');
        case 'mod_penalty':
            $parts = explode('|', (string)$a['extra'], 2);
            $until = (string)($parts[1] ?? '');
            $what = ($parts[0] ?? '') === 'noreply' ? 'запрет писать на форуме' : 'блокировка на форуме';
            return 'Набрано много баллов предупреждений: <b>' . $what . '</b> ' . ($until !== '' ? 'до ' . e(fdate($until)) : 'без срока');
        case 'mod_replyban':
            $until = (string)$a['extra'];
            return $actor . ' запретил(а) вам отвечать в теме ' . $title . ($until !== '' ? ' до ' . e(fdate($until)) : ' навсегда');
    }
    return $text;
});

hook_add('alert_link', function ($link, $a) {
    if ($a['type'] === 'mod_report') {
        $p = explode('|', (string)$a['extra']);
        $id = (int)($p[2] ?? 0);
        $aux = (int)($p[3] ?? 0);
        switch ($p[1] ?? '') {
            case 'post':
                return post_url($id);
            case 'profile_post':
                return url('/forum/member.php', ['id' => $aux]) . '#profile-post-' . $id;
            case 'profile_comment':
                return url('/forum/member.php', ['id' => $aux]) . '#profile-comment-' . $id;
            case 'message':
                return url('/forum/conversations.php', ['id' => $aux]) . '#msg-' . $id;
            case 'user':
                return url('/forum/member.php', ['id' => $id]);
        }
        return url('/forum/alerts.php');
    }
    if ($a['type'] === 'mod_warning' || $a['type'] === 'mod_penalty') {
        return url('/forum/member.php', ['id' => (int)$a['user_id'], 'tab' => 'warnings']);
    }
    return $link;
});

// ---------- Сообщения форума ----------

hook_add('post_actions_left', function ($p, $ctx) {
    if (!is_logged()) {
        return '';
    }
    $h = '';
    $pid = (int)$p['id'];
    $mine = (int)$p['user_id'] === uid();
    if (!$mine && !is_banned() && !$p['is_deleted'] && (int)$p['user_id'] > 0) {
        $h .= mdr_report_link('post', $pid);
    }
    if (!$mine && (int)$p['user_id'] > 0 && can_moderate() && mdr_level_of($p) < user_level()) {
        $h .= '<a class="post-act mdr-warn-link" href="' . e(url('/forum/warn.php', ['type' => 'post', 'id' => $pid])) . '" title="Выдать предупреждение за это сообщение">' . icon('alert') . '<span>Предупреждение</span></a>';
    }
    if (!empty($p['edited_at']) && ($mine || is_staff())) {
        $h .= '<a class="post-act" href="' . e(url('/forum/post-history.php', ['id' => $pid])) . '" title="Все версии сообщения">' . icon('clock') . '<span>История</span></a>';
    }
    return $h;
});

hook_add('post_after_body', function ($p, $ctx) {
    $tid = (int)($ctx['thread']['id'] ?? $p['thread_id']);
    $list = mdr_safe(function () use ($tid) {
        return mdr_thread_post_warnings($tid);
    }, []);
    $rows = $list[(int)$p['id']] ?? [];
    if (!$rows) {
        return '';
    }
    $h = '';
    foreach ($rows as $w) {
        $h .= '<div class="mdr-post-warning">' . icon('alert') . '<div><b>Пользователь получил предупреждение за это сообщение</b>';
        if (is_staff()) {
            $h .= '<span class="mdr-post-warning-meta">«' . e($w['title']) . '», +' . (int)$w['points'] . ' ' . plural($w['points'], 'балл', 'балла', 'баллов')
                . ($w['issuer_name'] ? ' · выдал(а) ' . e($w['issuer_name']) : '') . ' · ' . e(fdate($w['created_at'])) . '</span>';
        }
        $h .= '</div></div>';
    }
    return $h;
});

// Строка «Предупреждения: N баллов» в карточке автора - только команде и только если баллы есть.
// Один запрос на страницу: баллы всех, у кого есть действующие предупреждения.
hook_add('post_user_rows', function ($rows, $u) {
    if (!is_staff() || empty($u['id'])) {
        return $rows;
    }
    $map = mdr_safe(function () {
        return mdr_active_points_map();
    }, []);
    $n = (int)($map[(int)$u['id']] ?? 0);
    if ($n > 0) {
        $rows[] = ['Предупреждения:', mdr_points_text($n)];
    }
    return $rows;
});

// ---------- Стена профиля, личные сообщения, профиль ----------

hook_add('wall_post_actions', function ($p, $ownerId) {
    if (!is_logged() || is_banned() || (int)$p['user_id'] === uid()) {
        return '';
    }
    return mdr_report_link('profile_post', $p['id'], 'acc-link-btn');
});

hook_add('wall_comment_actions', function ($c, $ownerId) {
    if (!is_logged() || is_banned() || (int)$c['user_id'] === uid()) {
        return '';
    }
    return mdr_report_link('profile_comment', $c['id'], 'acc-link-btn');
});

hook_add('conversation_message_actions', function ($msg, $convId) {
    if (!is_logged() || is_banned() || (int)$msg['user_id'] === uid()) {
        return '';
    }
    return '<div class="mdr-msg-actions">' . mdr_report_link('message', $msg['id'], 'acc-link-btn') . '</div>';
});

hook_add('member_actions', function ($m, $isSelf) {
    if (!is_logged() || $isSelf) {
        return '';
    }
    $h = '';
    if (!is_banned()) {
        $h .= mdr_report_link('user', $m['id'], 'btn btn-pill btn-outline', 'Пожаловаться');
    }
    if (mdr_can_punish($m)) {
        $h .= '<a class="btn btn-pill btn-outline mdr-warn-btn" href="' . e(url('/forum/warn.php', ['user' => (int)$m['id']])) . '">' . icon('alert') . ' Предупреждение</a>';
    }
    return $h;
});

hook_add('member_tabs', function ($tabs, $m, $tab) {
    if (is_logged() && ((int)$m['id'] === uid() || is_staff())) {
        $tabs['warnings'] = 'Предупреждения';
    }
    return $tabs;
});

hook_add('member_tab_content', function ($tab, $m, $page) {
    if ($tab !== 'warnings') {
        return '';
    }
    return mdr_safe(function () use ($m) {
        return mdr_member_warnings_html($m);
    }, '<div class="card empty">' . icon('alert') . '<div>Предупреждения появятся после обновления базы сайта.</div></div>');
});

// ---------- Тема: запрет ответов ----------

hook_add('thread_mod_menu', function ($thread, $node) {
    if (!can_moderate()) {
        return '';
    }
    return '<button type="button" data-dialog-open="dlg-mdr-replyban">' . icon('ban') . ' Запрет ответов</button>';
});

hook_add('thread_after_posts', function ($thread, $node, $pg) {
    if (!is_staff()) {
        return '';
    }
    return mdr_safe(function () use ($thread) {
        return mdr_thread_replyban_box($thread);
    }, '');
});

hook_add('thread_reply_denied', function ($denied, $thread, $node) {
    if ($denied !== '' || !is_logged() || can_moderate()) {
        return $denied;
    }
    $ban = mdr_safe(function () use ($thread) {
        return mdr_replyban_active(uid(), (int)$thread['id']);
    }, null);
    return $ban ? mdr_replyban_text($ban) : $denied;
});

hook_add('new_thread_validate', function ($errors, $node, $useForm) {
    if (!is_logged() || can_moderate()) {
        return $errors;
    }
    $ban = mdr_safe(function () {
        return mdr_replyban_active(uid(), 0);
    }, null);
    if ($ban) {
        $errors['mdr_noreply'] = mdr_replyban_text($ban);
    }
    return $errors;
});

hook_add('new_thread_form', function ($node, $useForm, $errors) {
    if (empty($errors['mdr_noreply'])) {
        return '';
    }
    return '<div class="flash flash-error">' . icon('ban') . '<div>' . e($errors['mdr_noreply']) . '</div></div>';
});

// ---------- История правок и IP ----------

hook_add('post_before_update', function ($post, $body, $thread, $node) {
    mdr_safe(function () use ($post) {
        mdr_history_store($post);
    }, null);
});

hook_add('user_logged_in', function ($u) {
    if ($u && !empty($u['id'])) {
        mdr_safe(function () use ($u) {
            mdr_ip_log($u['id']);
        }, null);
    }
});

hook_add('post_created', function ($postId, $thread, $node) {
    mdr_safe(function () {
        mdr_ip_log(uid());
    }, null);
});

// ---------- Фоновые задачи ----------

cron_register('moderation_expire', 600, function () {
    if (!mdr_ready()) {
        return;
    }
    mdr_warnings_expire();
    mdr_bans_lift_expired();
    db_exec('DELETE FROM mod_reply_bans WHERE expires_at IS NOT NULL AND expires_at < :t', ['t' => date('Y-m-d H:i:s', time() - 86400 * 30)]);
});
