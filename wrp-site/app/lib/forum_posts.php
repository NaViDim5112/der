<?php
// Темы и сообщения форума: списки тем, вывод сообщений и реакций, создание тем и ответов,
// права на правку, модерация тем, оповещения. Используется страницами forum.php, thread.php, post.php,
// new-thread.php, find.php, watched.php, search.php, alerts.php. Все функции начинаются с fp_.

// ---------- Настройки и мелочи ----------

function fp_posts_per_page()
{
    $n = setting_int('posts_per_page');
    return $n > 0 ? $n : 15;
}

function fp_threads_per_page()
{
    $n = setting_int('threads_per_page');
    return $n > 0 ? $n : 20;
}

// Запрос пришёл из JS (WRP.post / fetch)
function fp_is_ajax()
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

// Обратный адрес из заголовка Referer (только этот сайт)
function fp_referer($fallback = null)
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $parts = parse_url($ref);
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $refHost = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($parts && $refHost !== '' && strcasecmp($refHost, $host) === 0 && !empty($parts['path'])) {
            return safe_return($parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : ''), $fallback);
        }
    }
    return $fallback !== null ? $fallback : url('/forum/');
}

// Текст: убрать лишние пробелы и переводы строк (для заголовков)
function fp_clean_title($s)
{
    return trim(preg_replace('~\s+~u', ' ', (string)$s));
}

// Проверка заголовка темы: '' или текст ошибки
function fp_title_error($title)
{
    $len = mb_strlen($title);
    if ($len < 3) {
        return 'Заголовок слишком короткий (минимум 3 символа).';
    }
    if ($len > 150) {
        return 'Заголовок слишком длинный (максимум 150 символов).';
    }
    return '';
}

// Проверка текста сообщения: '' или текст ошибки
function fp_body_error($body)
{
    $len = mb_strlen($body);
    if ($len < 2) {
        return 'Сообщение слишком короткое.';
    }
    if ($len > 50000) {
        return 'Сообщение слишком длинное (максимум 50 000 символов).';
    }
    return '';
}

// Сколько секунд ждать до следующего сообщения (0 - можно писать)
function fp_flood_wait()
{
    if (flood_ok()) {
        return 0;
    }
    $sec = setting_int('flood_seconds');
    $last = db_val('SELECT MAX(created_at) FROM posts WHERE user_id = :u', ['u' => uid()]);
    return $last ? max(1, strtotime($last) + $sec - time()) : 1;
}

// Разделы-форумы, которые видит текущий пользователь
function fp_viewable_node_ids()
{
    $ids = [];
    foreach (nodes_all() as $id => $n) {
        if ($n['type'] === 'forum' && node_can_view($n)) {
            $ids[] = (int)$id;
        }
    }
    return $ids;
}

// <option> для выбора раздела: категории некликабельны, вложенность отступом
function fp_node_options($selected = 0, $forumsOnly = true)
{
    $h = '';
    $walk = function ($parentId, $depth) use (&$walk, &$h, $selected, $forumsOnly) {
        foreach (node_children_ids($parentId) as $id) {
            $n = node_get($id);
            if (!$n || $n['type'] === 'link' || !node_can_view($n)) {
                continue;
            }
            $disabled = $forumsOnly && $n['type'] !== 'forum';
            $h .= '<option value="' . (int)$id . '"' . ($disabled ? ' disabled' : '') . ((int)$selected === (int)$id ? ' selected' : '') . '>'
                . str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . e($n['title']) . '</option>';
            $walk($id, $depth + 1);
        }
    };
    $walk(null, 0);
    return $h;
}

// Пагинация в стиле форума: «‹ Назад» 1 2 3 … 9 «Вперёд ›»
function fp_pagination(array $p, $path, array $params = [])
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $page = $p['page'];
    $pages = $p['pages'];
    $link = function ($i) use ($path, $params) {
        return e(url($path, array_merge($params, ['page' => $i > 1 ? $i : null])));
    };
    $show = [1, $pages];
    for ($i = $page - 2; $i <= $page + 2; $i++) {
        if ($i >= 1 && $i <= $pages) {
            $show[] = $i;
        }
    }
    $show = array_unique($show);
    sort($show);
    $h = '<nav class="pagination fp-pages" aria-label="Страницы">';
    if ($page > 1) {
        $h .= '<a class="page-btn page-step" href="' . $link($page - 1) . '">&lsaquo; Назад</a>';
    }
    $prev = 0;
    foreach ($show as $i) {
        if ($prev && $i - $prev > 1) {
            $h .= '<span class="page-gap">&hellip;</span>';
        }
        $h .= '<a class="page-btn' . ($i == $page ? ' active' : '') . '" href="' . $link($i) . '">' . $i . '</a>';
        $prev = $i;
    }
    if ($page < $pages) {
        $h .= '<a class="page-btn page-step" href="' . $link($page + 1) . '">Вперёд &rsaquo;</a>';
    }
    return $h . '</nav>';
}

// ---------- Пользователь в списках и сообщениях ----------

// Короткий пользователь для списков (если аккаунт удалён - id 0)
function fp_list_user($id, $name, $avatar = null, $color = null)
{
    if ($name === null || $name === '') {
        return ['id' => 0, 'username' => 'Гость', 'avatar' => null, 'group_color' => null];
    }
    return ['id' => (int)$id, 'username' => $name, 'avatar' => $avatar, 'group_color' => $color];
}

function fp_user_link($u)
{
    if (!$u || empty($u['id'])) {
        return '<span class="username muted">' . e($u['username'] ?? 'Гость') . '</span>';
    }
    return user_link($u);
}

function fp_avatar_link($u, $size = 'm')
{
    if (!$u || empty($u['id'])) {
        return avatar($u ?: ['username' => '?'], $size);
    }
    return '<a class="avatar-link" href="' . e(url('/forum/member.php', ['id' => (int)$u['id']])) . '" title="' . e($u['username']) . '">' . avatar($u, $size) . '</a>';
}

// Может ли произвольный пользователь (массив из user_by_id) видеть раздел и тему - для оповещений
function fp_user_can_see($u, $node, $thread = null)
{
    if (!$u || !$node) {
        return false;
    }
    $banned = !empty($u['is_banned']);
    if (!$banned && !empty($u['can_moderate'])) {
        return true;
    }
    if ($thread && !empty($thread['is_deleted'])) {
        return false;
    }
    $level = $banned ? 0 : (int)$u['level'];
    $cur = $node;
    $guard = 0;
    while ($cur && $guard++ < 20) {
        if ($level < (int)$cur['view_level']) {
            return false;
        }
        $cur = $cur['parent_id'] ? node_get($cur['parent_id']) : null;
    }
    return true;
}

// ---------- Списки тем ----------

// $from - начало FROM (можно с JOIN), тема должна иметь псевдоним t
function fp_thread_select_sql($from = 'threads t')
{
    return 'SELECT t.*, u.username, u.avatar, ug.color AS group_color,
                   lu.username AS last_username, lu.avatar AS last_avatar, lg.color AS last_group_color
            FROM ' . $from . '
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN user_groups ug ON ug.id = u.group_id
            LEFT JOIN users lu ON lu.id = t.last_post_user_id
            LEFT JOIN user_groups lg ON lg.id = lu.group_id';
}

// Строки списка тем. $opts: show_node (раздел в мета-строке), actions (function($t) -> HTML кнопок справа)
function fp_thread_rows(array $threads, array $opts = [])
{
    if (!$threads) {
        return '';
    }
    $reads = thread_read_times($threads);
    $h = '';
    foreach ($threads as $t) {
        $h .= fp_thread_row($t, $reads, $opts);
    }
    return $h;
}

function fp_thread_row(array $t, array $reads, array $opts = [])
{
    $tid = (int)$t['id'];
    $unread = is_logged() && isset($reads[$tid]) && $t['last_post_at'] > $reads[$tid];
    $author = fp_list_user($t['user_id'], $t['username'], $t['avatar'], $t['group_color']);
    $last = fp_list_user($t['last_post_user_id'], $t['last_username'], $t['last_avatar'], $t['last_group_color']);
    $unreadUrl = url('/forum/thread.php', ['id' => $tid, 'goto' => 'unread']);
    $href = $unread ? $unreadUrl : thread_url($tid);

    $cls = 'trow' . ($unread ? ' unread' : '') . ($t['is_pinned'] ? ' pinned' : '') . ($t['is_locked'] ? ' locked' : '') . ($t['is_deleted'] ? ' deleted' : '');
    $h = '<div class="' . $cls . '" id="thread-' . $tid . '">';
    $h .= hook_html('thread_row_start', $t, $opts);
    $h .= '<div class="trow-avatar">' . fp_avatar_link($author, 'm') . '</div>';

    $h .= '<div class="trow-main">';
    $h .= '<div class="trow-title">' . prefix_html($t['prefix_id']) . '<a href="' . e($href) . '">' . e($t['title']) . '</a>';
    if ($unread) {
        $h .= ' <a class="badge-new" href="' . e($unreadUrl) . '" title="Перейти к первому непрочитанному">Новое</a>';
    }
    if ($t['is_deleted']) {
        $h .= ' <span class="tag tag-deleted">' . icon('trash') . 'Удалена</span>';
    }
    $h .= hook_html('thread_row_title_after', $t, $opts);
    $h .= '</div>';

    $h .= '<div class="trow-meta">' . fp_user_link($author) . '<span class="trow-dot">·</span><a class="trow-date" href="' . e(thread_url($tid)) . '">' . e(fdate($t['created_at'])) . '</a>';
    if (!empty($opts['show_node'])) {
        $n = node_get($t['node_id']);
        if ($n) {
            $h .= '<span class="trow-dot">·</span><a class="trow-node" href="' . e(node_url($n)) . '">' . e($n['title']) . '</a>';
        }
    }
    $pages = (int)ceil(((int)$t['reply_count'] + 1) / fp_posts_per_page());
    if ($pages > 1) {
        $list = $pages <= 4 ? range(1, $pages) : [1, 0, $pages - 2, $pages - 1, $pages];
        $h .= '<span class="trow-pages">' . icon('file');
        foreach ($list as $i) {
            $h .= $i ? '<a href="' . e(thread_url($tid, $i)) . '">' . $i . '</a>' : '<span>&hellip;</span>';
        }
        $h .= '</span>';
    }
    $h .= '</div></div>';

    $h .= '<div class="trow-status">';
    if ($t['is_locked']) {
        $h .= '<span title="Тема закрыта">' . icon('lock') . '</span>';
    }
    if ($t['is_pinned']) {
        $h .= '<span title="Тема закреплена">' . icon('pin') . '</span>';
    }
    if (!empty($opts['actions']) && is_callable($opts['actions'])) {
        $h .= call_user_func($opts['actions'], $t);
    }
    $h .= '</div>';

    $h .= '<div class="trow-stats">'
        . '<dl><dt>Ответы:</dt><dd>' . num($t['reply_count']) . '</dd></dl>'
        . '<dl><dt>Просмотры:</dt><dd>' . e(num_short($t['views'])) . '</dd></dl>'
        . '</div>';

    $h .= '<div class="trow-last">';
    if ($t['last_post_id']) {
        $h .= '<div class="trow-last-text"><a class="trow-last-date" href="' . e(post_url($t['last_post_id'])) . '" title="К последнему сообщению">' . e(fdate($t['last_post_at'])) . '</a>'
            . '<span class="trow-last-user">' . fp_user_link($last) . '</span></div>' . fp_avatar_link($last, 'xs');
    }
    $h .= '</div>';
    return $h . '</div>';
}

// ---------- Сообщения ----------

function fp_post_select_sql()
{
    return 'SELECT p.*, u.username, u.avatar, u.group_id, u.secondary_groups, u.custom_title, u.signature, u.game_nick,
                   u.posts_count, u.threads_count, u.likes_received, u.created_at AS user_created_at,
                   g.name AS group_name, g.color AS group_color, eu.username AS editor_name
            FROM posts p
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN user_groups g ON g.id = u.group_id
            LEFT JOIN users eu ON eu.id = p.edited_by';
}

function fp_post_get($id)
{
    return db_one(fp_post_select_sql() . ' WHERE p.id = :id', ['id' => (int)$id]);
}

// Автор сообщения из строки fp_post_select_sql()
function fp_author(array $p)
{
    if ($p['username'] === null) {
        return ['id' => 0, 'username' => 'Удалённый пользователь', 'avatar' => null, 'group_id' => 0, 'secondary_groups' => '',
            'custom_title' => '', 'group_name' => '', 'group_color' => null, 'signature' => '', 'game_nick' => null,
            'posts_count' => 0, 'threads_count' => 0, 'likes_received' => 0, 'created_at' => null];
    }
    return [
        'id' => (int)$p['user_id'],
        'username' => $p['username'],
        'avatar' => $p['avatar'],
        'group_id' => (int)$p['group_id'],
        'secondary_groups' => $p['secondary_groups'],
        'custom_title' => $p['custom_title'],
        'group_name' => $p['group_name'],
        'group_color' => $p['group_color'],
        'signature' => $p['signature'],
        'game_nick' => $p['game_nick'],
        'posts_count' => (int)$p['posts_count'],
        'threads_count' => (int)$p['threads_count'],
        'likes_received' => (int)$p['likes_received'],
        'created_at' => $p['user_created_at'],
    ];
}

// id самого первого сообщения темы (с учётом удалённых)
function fp_first_post_id($threadId)
{
    return (int)db_val('SELECT MIN(id) FROM posts WHERE thread_id = :t', ['t' => (int)$threadId]);
}

// Номер сообщения в теме. Модераторы видят удалённые, поэтому для них они тоже считаются.
function fp_post_position(array $post, $withDeleted)
{
    $sql = 'SELECT COUNT(*) FROM posts WHERE thread_id = :t AND id <= :id' . ($withDeleted ? '' : ' AND is_deleted = 0');
    return max(1, (int)db_val($sql, ['t' => (int)$post['thread_id'], 'id' => (int)$post['id']]));
}

function fp_post_page(array $post, $withDeleted)
{
    return (int)ceil(fp_post_position($post, $withDeleted) / fp_posts_per_page());
}

// Адрес страницы темы с якорем сообщения
function fp_post_anchor_url(array $post, $withDeleted)
{
    return thread_url($post['thread_id'], fp_post_page($post, $withDeleted)) . '#post-' . (int)$post['id'];
}

// Можно ли видеть сообщение (тема, раздел, удаление)
function fp_post_visible(array $post, $thread, $node)
{
    if (!$thread || !$node || !node_can_view($node)) {
        return false;
    }
    if (($thread['is_deleted'] || $post['is_deleted']) && !can_moderate()) {
        return false;
    }
    return true;
}

// Причина, по которой нельзя ответить ('' - можно, 'guest' - нужно войти)
function fp_reply_denied(array $thread, $node)
{
    if (!is_logged()) {
        return 'guest';
    }
    if (is_banned()) {
        return 'Ваш аккаунт заблокирован, отвечать в темах нельзя.';
    }
    if ($thread['is_deleted'] && !can_moderate()) {
        return 'Тема удалена.';
    }
    if (!node_can_reply($node)) {
        return 'У вас нет прав отвечать в этом разделе.';
    }
    if ($thread['is_locked'] && !can_moderate()) {
        return 'Тема закрыта. Ответы в ней больше не принимаются.';
    }
    return (string)hook_filter('thread_reply_denied', '', $thread, $node);
}

// Может ли текущий пользователь редактировать сообщение
function fp_can_edit_post(array $post, array $thread, $node)
{
    if (!is_logged()) {
        return false;
    }
    if (can_moderate()) {
        return true;
    }
    if (is_banned() || (int)$post['user_id'] !== uid()) {
        return false;
    }
    if ($post['is_deleted'] || $thread['is_deleted'] || $thread['is_locked'] || !node_can_view($node)) {
        return false;
    }
    $win = setting_int('edit_window_minutes');
    if ($win > 0 && strtotime($post['created_at']) < time() - $win * 60) {
        return false;
    }
    return true;
}

// Кто и когда удалил сообщения (по журналу модерации): [post_id => ['name' => ..., 'at' => ...]]
function fp_deleted_info(array $postIds)
{
    $postIds = array_values(array_unique(array_map('intval', $postIds)));
    if (!$postIds) {
        return [];
    }
    $in = db_in($postIds, 'p');
    $rows = db_all("SELECT m.target_id, m.created_at, u.username FROM mod_log m LEFT JOIN users u ON u.id = m.user_id
                    WHERE m.target_type = 'post' AND m.action = 'post_delete' AND m.target_id IN (" . $in['sql'] . ') ORDER BY m.id', $in['params']);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['target_id']] = ['name' => $r['username'], 'at' => $r['created_at']];
    }
    return $out;
}

// Карточка автора слева от сообщения
function fp_post_user_html(array $u)
{
    $h = '<div class="post-user">';
    $h .= '<div class="post-user-avatar">' . fp_avatar_link($u, 'xl') . '</div>';
    $h .= '<div class="post-user-name">';
    $h .= '<h4 class="post-username">' . fp_user_link($u) . '</h4>';
    $title = $u['custom_title'] ?: $u['group_name'];
    if ($title) {
        $h .= '<div class="post-usertitle">' . e($title) . '</div>';
    }
    $h .= '</div>';
    if ($u['id']) {
        $banners = user_banners($u);
        if ($banners !== '') {
            $h .= '<div class="post-banners">' . $banners . '</div>';
        }
        $rows = [
            ['Регистрация:', fday($u['created_at'])],
            ['Сообщения:', num($u['posts_count'])],
            ['Реакции:', num($u['likes_received'])],
            ['Баллы:', num(user_points($u))],
        ];
        if (!empty($u['game_nick'])) {
            $rows[] = ['Игровой ник:', $u['game_nick']];
        }
        $rows = hook_filter('post_user_rows', $rows, $u);
        $h .= '<dl class="post-userstats">';
        foreach ($rows as $r) {
            $h .= '<div><dt>' . e($r[0]) . '</dt><dd title="' . e($r[1]) . '">' . e($r[1]) . '</dd></div>';
        }
        $h .= '</dl>';
        $h .= hook_html('post_user_after', $u);
    }
    return $h . '</div>';
}

// Одно сообщение. $ctx: thread, node, pos, unread, likes, is_first, inner (без id), can_quote
function fp_render_post(array $p, array $ctx)
{
    $thread = $ctx['thread'];
    $node = $ctx['node'];
    $pid = (int)$p['id'];
    $u = fp_author($p);
    $isMod = can_moderate();
    $postUrl = post_url($pid);
    $likes = $ctx['likes'] ?? [];
    $isFirst = !empty($ctx['is_first']);
    $mine = is_logged() && (int)$p['user_id'] === uid();

    $h = '<article class="post' . ($p['is_deleted'] ? ' is-deleted' : '') . '"' . (empty($ctx['inner']) ? ' id="post-' . $pid . '"' : '') . ' data-post="' . $pid . '">';
    $h .= fp_post_user_html($u);

    $h .= '<div class="post-main">';
    $h .= '<header class="post-head"><div class="post-head-left">';
    $h .= '<a class="post-date" href="' . e($postUrl) . '" title="' . e(date('d.m.Y H:i', strtotime($p['created_at']))) . '">' . e(fdate($p['created_at'])) . '</a>';
    if ($u['id'] && (int)$p['user_id'] === (int)$thread['user_id'] && !$isFirst) {
        $h .= '<span class="post-author-tag">Автор темы</span>';
    }
    $h .= '</div><div class="post-head-right">';
    if (!empty($ctx['unread'])) {
        $h .= '<span class="badge-new badge-new-pink">Новое</span>';
    }
    $h .= hook_html('post_head_right', $p, $ctx);
    $h .= '<button type="button" class="post-head-btn" data-copy="' . e(site_origin() . $postUrl) . '" title="Скопировать ссылку на сообщение">' . icon('share') . '</button>';
    $h .= '<a class="post-num" href="' . e($postUrl) . '">#' . (int)($ctx['pos'] ?? 1) . '</a>';
    $h .= '</div></header>';

    $h .= '<div class="post-body bb">' . bbcode($p['body']) . '</div>';
    if (!empty($p['edited_at'])) {
        $h .= '<div class="post-edited">' . icon('edit') . 'Изменено: ' . e(fdate($p['edited_at'])) . (!empty($p['editor_name']) ? ' (' . e($p['editor_name']) . ')' : '') . '</div>';
    }
    $h .= hook_html('post_after_body', $p, $ctx);
    if (!empty($u['signature']) && trim($u['signature']) !== '') {
        $h .= '<div class="post-sig bb">' . bbcode($u['signature']) . '</div>';
    }

    $h .= '<footer class="post-foot"><div class="post-foot-left">';
    if (fp_can_edit_post($p, $thread, $node)) {
        $h .= '<a class="post-act" href="' . e(url('/forum/post.php', ['action' => 'edit', 'id' => $pid])) . '">' . icon('edit') . '<span>Редактировать</span></a>';
    }
    if ($isMod) {
        if ($p['is_deleted']) {
            $h .= '<form method="post" action="' . e(url('/forum/post.php', ['action' => 'restore'])) . '" class="inline-form">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $pid . '"><button class="post-act post-act-ok" type="submit">' . icon('undo') . '<span>Восстановить</span></button></form>';
        } else {
            $confirm = $isFirst ? 'Это первое сообщение темы - будет удалена вся тема. Продолжить?' : 'Удалить это сообщение?';
            $h .= '<form method="post" action="' . e(url('/forum/post.php', ['action' => 'delete'])) . '" class="inline-form" data-confirm="' . e($confirm) . '">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $pid . '"><button class="post-act post-act-danger" type="submit">' . icon('trash') . '<span>Удалить</span></button></form>';
        }
        if (!empty($p['ip'])) {
            $h .= '<button type="button" class="post-act" data-copy="' . e($p['ip']) . '" title="IP: ' . e($p['ip']) . ' (нажмите, чтобы скопировать)">' . icon('server') . '<span>IP</span></button>';
        }
    }
    $h .= hook_html('post_actions_left', $p, $ctx);
    $h .= '</div><div class="post-foot-right">';
    $h .= hook_html('post_actions_right', $p, $ctx);
    if (!$p['is_deleted']) {
        $h .= fp_react_button($p, $likes, $mine);
    }
    if (!is_logged()) {
        $h .= '<a class="post-act" href="' . e(url('/forum/login.php', ['return' => current_url()])) . '">' . icon('reply') . '<span>Ответить</span></a>';
    } elseif (!$p['is_deleted'] && !empty($ctx['can_quote'])) {
        $h .= '<button type="button" class="post-act" data-quote="' . $pid . '" title="Ответить с цитатой">' . icon('reply') . '<span>Ответить</span></button>';
    }
    $h .= '</div></footer>';

    $bar = fp_reactions_bar($pid, $likes);
    $h .= '<div class="reactions-bar" data-reactions="' . $pid . '"' . ($bar === '' ? ' hidden' : '') . '>' . $bar . '</div>';
    $h .= hook_html('post_footer_after', $p, $ctx);
    $h .= '</div></article>';
    return $h;
}

// Сообщение с учётом удаления и игнора: свёрнутая строка с кнопкой «Показать»
function fp_render_post_entry(array $p, array $ctx)
{
    $pid = (int)$p['id'];
    $u = fp_author($p);
    if ($p['is_deleted']) {
        $info = $ctx['deleted'] ?? null;
        $by = $info && $info['name'] ? ' (' . e($info['name']) . ', ' . e(fdate($info['at'])) . ')' : '';
        $inner = $ctx;
        $inner['inner'] = true;
        $h = '<div class="post-collapsed post-collapsed-deleted" id="post-' . $pid . '">';
        $h .= '<div class="post-collapsed-head">' . icon('trash') . '<span class="post-collapsed-text">Сообщение от ' . fp_user_link($u) . ' удалено<span class="muted">' . $by . '</span></span>';
        $h .= '<span class="post-collapsed-actions"><span class="post-num muted">#' . (int)$ctx['pos'] . '</span>'
            . '<button type="button" class="post-act" data-collapse-toggle>' . icon('eye') . '<span>Показать</span></button>';
        $h .= '<form method="post" action="' . e(url('/forum/post.php', ['action' => 'restore'])) . '" class="inline-form">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $pid . '"><button class="post-act post-act-ok" type="submit">' . icon('undo') . '<span>Восстановить</span></button></form>';
        $h .= '</span></div>';
        $h .= '<div class="post-collapsed-body" hidden>' . fp_render_post($p, $inner) . '</div>';
        return $h . '</div>';
    }
    if ($u['id'] && in_array($u['id'], ignored_user_ids(), true)) {
        $inner = $ctx;
        $inner['inner'] = true;
        $h = '<div class="post-collapsed post-collapsed-ignored" id="post-' . $pid . '">';
        $h .= '<div class="post-collapsed-head">' . icon('eye-off') . '<span class="post-collapsed-text">Сообщение от игнорируемого пользователя ' . fp_user_link($u) . '</span>';
        $h .= '<span class="post-collapsed-actions"><button type="button" class="post-act" data-collapse-toggle>' . icon('eye') . '<span>Показать</span></button></span></div>';
        $h .= '<div class="post-collapsed-body" hidden>' . fp_render_post($p, $inner) . '</div>';
        return $h . '</div>';
    }
    return fp_render_post($p, $ctx);
}

// ---------- Реакции ----------

function fp_reactions()
{
    return [
        'like' => ['👍', 'Нравится'],
        'love' => ['❤️', 'Люблю'],
        'haha' => ['😂', 'Ха-ха'],
        'wow' => ['😮', 'Ух ты'],
        'sad' => ['😢', 'Грустно'],
        'angry' => ['😡', 'Злюсь'],
    ];
}

// Реакции к сообщениям: [post_id => [строки]], свежие сначала
function fp_load_likes(array $postIds)
{
    $postIds = array_values(array_unique(array_map('intval', $postIds)));
    if (!$postIds) {
        return [];
    }
    $in = db_in($postIds, 'p');
    $rows = db_all('SELECT l.post_id, l.user_id, l.reaction, l.created_at, u.username, u.avatar, g.color AS group_color
                    FROM post_likes l
                    LEFT JOIN users u ON u.id = l.user_id
                    LEFT JOIN user_groups g ON g.id = u.group_id
                    WHERE l.post_id IN (' . $in['sql'] . ')
                    ORDER BY l.created_at DESC, l.user_id DESC', $in['params']);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['post_id']][] = $r;
    }
    return $out;
}

function fp_my_reaction(array $likes)
{
    $me = uid();
    if (!$me) {
        return '';
    }
    foreach ($likes as $l) {
        if ((int)$l['user_id'] === $me) {
            return (string)$l['reaction'];
        }
    }
    return '';
}

// Содержимое кнопки реакции: «👍 Like» или выбранная реакция
function fp_react_btn_inner($my)
{
    $all = fp_reactions();
    if ($my !== '' && isset($all[$my])) {
        return '<span class="react-emoji">' . $all[$my][0] . '</span><span>' . e($all[$my][1]) . '</span>';
    }
    return icon('like') . '<span>Like</span>';
}

// Кнопка Like с выбором реакции при наведении
function fp_react_button(array $p, array $likes, $mine)
{
    if (!is_logged()) {
        return '<a class="post-act react-btn" href="' . e(url('/forum/login.php', ['return' => current_url()])) . '" title="Войдите, чтобы оценить">' . icon('like') . '<span>Like</span></a>';
    }
    if ($mine || is_banned()) {
        return '';
    }
    $my = fp_my_reaction($likes);
    $h = '<div class="react-wrap">';
    $h .= '<button type="button" class="post-act react-btn' . ($my !== '' ? ' is-reacted' : '') . '" data-react="' . (int)$p['id'] . '" title="Оценить">' . fp_react_btn_inner($my) . '</button>';
    $h .= '<div class="react-picker" role="menu" aria-label="Реакции">';
    foreach (fp_reactions() as $k => $r) {
        $h .= '<button type="button" data-react-pick="' . e($k) . '" title="' . e($r[1]) . '"' . ($my === $k ? ' class="active"' : '') . '>' . $r[0] . '</button>';
    }
    return $h . '</div></div>';
}

// Полоска «👍❤️ Вы, A, B и ещё 3» под сообщением. Пустая строка, если реакций нет.
function fp_reactions_bar($postId, $likes = null)
{
    if ($likes === null) {
        $all = fp_load_likes([(int)$postId]);
        $likes = $all[(int)$postId] ?? [];
    }
    if (!$likes) {
        return '';
    }
    $defs = fp_reactions();
    $counts = [];
    foreach ($likes as $l) {
        $r = isset($defs[$l['reaction']]) ? $l['reaction'] : 'like';
        $counts[$r] = ($counts[$r] ?? 0) + 1;
    }
    arsort($counts);
    $emojis = '';
    foreach (array_slice(array_keys($counts), 0, 3) as $r) {
        $emojis .= '<span title="' . e($defs[$r][1]) . ': ' . $counts[$r] . '">' . $defs[$r][0] . '</span>';
    }

    $me = uid();
    $names = [];
    foreach ($likes as $l) {
        if ($me && (int)$l['user_id'] === $me) {
            $names[] = '<b class="react-you">Вы</b>';
            break;
        }
    }
    foreach ($likes as $l) {
        if (count($names) >= 3) {
            break;
        }
        if ($me && (int)$l['user_id'] === $me) {
            continue;
        }
        $names[] = fp_user_link(fp_list_user($l['user_id'], $l['username'], $l['avatar'], $l['group_color']));
    }
    $rest = count($likes) - count($names);
    if ($rest > 0) {
        $text = implode(', ', $names) . ' <button type="button" class="react-more" data-react-list>и ещё ' . $rest . '</button>';
    } elseif (count($names) === 1) {
        $text = $names[0];
    } else {
        $lastName = array_pop($names);
        $text = implode(', ', $names) . ' и ' . $lastName;
    }

    $list = '<div class="react-list" hidden>';
    foreach ($likes as $l) {
        $r = isset($defs[$l['reaction']]) ? $l['reaction'] : 'like';
        $lu = fp_list_user($l['user_id'], $l['username'], $l['avatar'], $l['group_color']);
        $list .= '<div class="react-list-row"><span class="react-list-emoji" title="' . e($defs[$r][1]) . '">' . $defs[$r][0] . '</span>'
            . avatar($lu, 'xs') . fp_user_link($lu) . '<span class="muted small">' . e(fdate($l['created_at'])) . '</span></div>';
    }
    $list .= '</div>';

    return '<button type="button" class="react-emojis" data-react-list title="Кто отреагировал">' . $emojis . '</button>'
        . '<span class="react-names">' . $text . '</span>' . $list;
}

// Поставить, сменить или снять реакцию. $reaction = '' - переключить обычный лайк. Возвращает новую реакцию ('' - снята).
function fp_set_reaction(array $post, $reaction)
{
    $pid = (int)$post['id'];
    $me = uid();
    $defs = fp_reactions();
    $cur = (string)db_val('SELECT reaction FROM post_likes WHERE post_id = :p AND user_id = :u', ['p' => $pid, 'u' => $me]);
    if ($reaction === '') {
        $new = $cur !== '' ? '' : 'like';
    } else {
        $new = $cur === $reaction ? '' : $reaction;
    }
    if ($new !== '' && !isset($defs[$new])) {
        $new = 'like';
    }
    if ($new === '') {
        db_exec('DELETE FROM post_likes WHERE post_id = :p AND user_id = :u', ['p' => $pid, 'u' => $me]);
    } elseif ($cur !== '') {
        db_exec('UPDATE post_likes SET reaction = :r WHERE post_id = :p AND user_id = :u', ['r' => $new, 'p' => $pid, 'u' => $me]);
    } else {
        db_exec('INSERT IGNORE INTO post_likes (post_id, user_id, reaction, created_at) VALUES (:p, :u, :r, :c)', ['p' => $pid, 'u' => $me, 'r' => $new, 'c' => now()]);
    }
    $count = (int)db_val('SELECT COUNT(*) FROM post_likes WHERE post_id = :p', ['p' => $pid]);
    db_update('posts', ['likes_count' => $count], 'id = :id', ['id' => $pid]);
    $author = (int)$post['user_id'];
    if ($author) {
        db_exec('UPDATE users SET likes_received = (SELECT COUNT(*) FROM post_likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id = :a) WHERE id = :b', ['a' => $author, 'b' => $author]);
    }
    // Оповещение один раз на сообщение от одного человека
    if ($new !== '' && $cur === '' && $author && $author !== $me) {
        $was = db_val("SELECT 1 FROM alerts WHERE user_id = :u AND actor_id = :a AND type = 'like' AND post_id = :p LIMIT 1", ['u' => $author, 'a' => $me, 'p' => $pid]);
        if (!$was) {
            alert_add($author, 'like', $me, (int)$post['thread_id'], $pid, $defs[$new][0]);
        }
    }
    return $new;
}

// ---------- Цитаты ----------

// Текст без вложенных цитат
function fp_strip_quotes($body)
{
    $t = (string)$body;
    for ($i = 0; $i < 10; $i++) {
        $before = $t;
        $t = preg_replace('~\[quote(?:=[^\]]*)?\]((?:(?!\[quote[\]=]).)*?)\[/quote\]~is', '', $t);
        if ($t === $before || $t === null) {
            break;
        }
    }
    $t = preg_replace("~\n{3,}~", "\n\n", (string)$t);
    return trim($t);
}

// BB-код цитаты сообщения
function fp_quote_bbcode(array $p)
{
    $name = $p['username'] !== null ? $p['username'] : 'Гость';
    $name = str_replace(['"', '[', ']', ','], '', $name);
    return '[quote="' . $name . ', post: ' . (int)$p['id'] . ', member: ' . (int)$p['user_id'] . '"]' . "\n" . fp_strip_quotes($p['body']) . "\n[/quote]\n";
}

// ---------- Создание тем и ответов, оповещения ----------

// Новая тема. $opt: pinned, locked, watch. Возвращает id темы.
function fp_create_thread(array $node, $title, $prefixId, $body, array $opt = [])
{
    $body = hook_filter('post_body_save', $body, $node, null);
    $title = hook_filter('thread_title_save', $title, $node);
    $now = now();
    $me = uid();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tid = db_insert('threads', [
            'node_id' => (int)$node['id'],
            'user_id' => $me,
            'title' => $title,
            'prefix_id' => $prefixId ? (int)$prefixId : null,
            'is_pinned' => !empty($opt['pinned']) ? 1 : 0,
            'is_locked' => !empty($opt['locked']) ? 1 : 0,
            'last_post_at' => $now,
            'last_post_user_id' => $me,
            'created_at' => $now,
        ]);
        $pid = db_insert('posts', [
            'thread_id' => $tid,
            'user_id' => $me,
            'body' => $body,
            'created_at' => $now,
            'ip' => client_ip(),
        ]);
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    thread_rebuild($tid);
    node_rebuild($node['id']);
    user_rebuild_counts($me);
    if (!array_key_exists('watch', $opt) || $opt['watch']) {
        thread_watch($tid, true);
    }
    thread_mark_read($tid, $now);
    $thread = thread_get($tid);
    $done = fp_notify_post($thread, $node, $pid, $body, false);
    // Подписчики раздела (и родительских разделов)
    foreach (node_watchers($node['id']) as $wid) {
        if (isset($done[$wid])) {
            continue;
        }
        $done[$wid] = true;
        if (fp_user_can_see(user_by_id($wid), $node, $thread)) {
            alert_add($wid, 'thread', $me, $tid, $pid);
        }
    }
    hook_fire('thread_created', $tid, $node, $pid);
    return $tid;
}

// Ответ в теме. Возвращает id сообщения.
function fp_create_post(array $thread, array $node, $body)
{
    $body = hook_filter('post_body_save', $body, $node, $thread);
    $now = now();
    $pid = db_insert('posts', [
        'thread_id' => (int)$thread['id'],
        'user_id' => uid(),
        'body' => $body,
        'created_at' => $now,
        'ip' => client_ip(),
    ]);
    thread_rebuild($thread['id']);
    node_rebuild($node['id']);
    user_rebuild_counts(uid());
    thread_mark_read($thread['id'], $now);
    thread_watch($thread['id'], true);
    fp_notify_post($thread, $node, $pid, $body, true);
    hook_fire('post_created', $pid, $thread, $node);
    return $pid;
}

// Оповещения о цитатах, упоминаниях и (если $watchers) об ответе в отслеживаемой теме.
// Возвращает [user_id => true] всех, кто уже получил оповещение.
function fp_notify_post(array $thread, array $node, $postId, $body, $watchers = true)
{
    $me = uid();
    $tid = (int)$thread['id'];
    $done = [$me => true];
    $clean = preg_replace('~\[code(?:=[^\]]*)?\].*?\[/code\]~is', '', (string)$body);
    preg_match_all('~\[quote=[^\]]*?member:\s*(\d{1,10})~i', (string)$clean, $qm);
    preg_match_all('~\[user=(\d{1,10})\]~i', (string)$clean, $mm);
    $sets = [
        'quote' => array_slice(array_values(array_unique(array_map('intval', $qm[1]))), 0, 20),
        'mention' => array_slice(array_values(array_unique(array_map('intval', $mm[1]))), 0, 20),
    ];
    foreach ($sets as $type => $ids) {
        foreach ($ids as $id) {
            if ($id <= 0 || isset($done[$id])) {
                continue;
            }
            $done[$id] = true;
            if (fp_user_can_see(user_by_id($id), $node, $thread)) {
                alert_add($id, $type, $me, $tid, $postId);
            }
        }
    }
    if ($watchers) {
        foreach (db_all('SELECT user_id FROM thread_watch WHERE thread_id = :t', ['t' => $tid]) as $w) {
            $id = (int)$w['user_id'];
            if (isset($done[$id])) {
                continue;
            }
            $done[$id] = true;
            if (fp_user_can_see(user_by_id($id), $node, $thread)) {
                alert_add($id, 'reply', $me, $tid, $postId);
            }
        }
    }
    return $done;
}

// Пересчёт счётчиков всех участников темы (после удаления или восстановления темы)
function fp_rebuild_thread_users($threadId)
{
    foreach (db_all('SELECT DISTINCT user_id FROM posts WHERE thread_id = :t', ['t' => (int)$threadId]) as $r) {
        user_rebuild_counts($r['user_id']);
    }
}

// Удалить тему (мягко) или восстановить
function fp_thread_set_deleted(array $thread, $deleted)
{
    db_update('threads', ['is_deleted' => $deleted ? 1 : 0], 'id = :id', ['id' => (int)$thread['id']]);
    thread_rebuild($thread['id']);
    node_rebuild($thread['node_id']);
    fp_rebuild_thread_users($thread['id']);
    mod_log($deleted ? 'thread_delete' : 'thread_restore', 'thread', $thread['id'], $thread['title']);
}

// ---------- Модерация темы ----------

// Действие модератора над темой. Возвращает [ok, сообщение, адрес для перехода].
function fp_mod_thread(array $thread, array $node, $do)
{
    $r = fp_mod_thread_do($thread, $node, $do);
    if ($r[0]) {
        hook_fire('thread_moderated', $thread, $node, $do);
    }
    return $r;
}

function fp_mod_thread_do(array $thread, array $node, $do)
{
    $tid = (int)$thread['id'];
    $back = thread_url($tid);
    switch ($do) {
        case 'pin':
        case 'unpin':
            $on = $do === 'pin';
            db_update('threads', ['is_pinned' => $on ? 1 : 0], 'id = :id', ['id' => $tid]);
            mod_log($on ? 'thread_pin' : 'thread_unpin', 'thread', $tid, $thread['title']);
            return [true, $on ? 'Тема закреплена.' : 'Тема откреплена.', $back];

        case 'lock':
        case 'unlock':
            $on = $do === 'lock';
            db_update('threads', ['is_locked' => $on ? 1 : 0], 'id = :id', ['id' => $tid]);
            mod_log($on ? 'thread_lock' : 'thread_unlock', 'thread', $tid, $thread['title']);
            return [true, $on ? 'Тема закрыта.' : 'Тема открыта.', $back];

        case 'prefix':
            $lock = isset($_POST['prefix_lock']);
            $prefixId = $lock ? input_int('prefix_lock') : input_int('prefix_id');
            $allowed = node_prefixes($node['id']);
            if ($prefixId && !isset($allowed[$prefixId])) {
                return [false, 'Этот префикс нельзя использовать в разделе.', $back];
            }
            $changed = (int)$thread['prefix_id'] !== $prefixId;
            $data = ['prefix_id' => $prefixId ?: null];
            if ($lock) {
                $data['is_locked'] = 1;
            }
            db_update('threads', $data, 'id = :id', ['id' => $tid]);
            $all = prefixes_all();
            $ptitle = $prefixId ? $all[$prefixId]['title'] : 'без префикса';
            if ($changed) {
                mod_log('thread_prefix', 'thread', $tid, $thread['title'] . ' -> ' . $ptitle);
                alert_add($thread['user_id'], 'prefix', uid(), $tid, null, $ptitle);
            }
            if ($lock && !$thread['is_locked']) {
                mod_log('thread_lock', 'thread', $tid, $thread['title']);
            }
            return [true, 'Статус темы: ' . $ptitle . ($lock ? ', тема закрыта.' : '.'), $back];

        case 'move':
            $target = node_get(input_int('node_id'));
            if (!$target || $target['type'] !== 'forum') {
                return [false, 'Выберите раздел для переноса.', $back];
            }
            if ((int)$target['id'] === (int)$node['id']) {
                return [false, 'Тема уже находится в этом разделе.', $back];
            }
            db_update('threads', ['node_id' => (int)$target['id']], 'id = :id', ['id' => $tid]);
            node_rebuild($node['id']);
            node_rebuild($target['id']);
            mod_log('thread_move', 'thread', $tid, $thread['title'] . ': ' . $node['title'] . ' -> ' . $target['title']);
            alert_add($thread['user_id'], 'move', uid(), $tid, null, $target['title']);
            return [true, 'Тема перенесена в раздел «' . $target['title'] . '».', $back];

        case 'rename':
            $title = fp_clean_title(input('title'));
            $err = fp_title_error($title);
            if ($err) {
                return [false, $err, $back];
            }
            if ($title !== $thread['title']) {
                db_update('threads', ['title' => $title], 'id = :id', ['id' => $tid]);
                node_rebuild($node['id']);
                mod_log('thread_rename', 'thread', $tid, $thread['title'] . ' -> ' . $title);
            }
            return [true, 'Тема переименована.', $back];

        case 'delete':
            if ($thread['is_deleted']) {
                return [false, 'Тема уже удалена.', $back];
            }
            fp_thread_set_deleted($thread, true);
            return [true, 'Тема удалена. Её видят только модераторы.', node_url($node)];

        case 'restore':
            if (!$thread['is_deleted']) {
                return [false, 'Тема не удалена.', $back];
            }
            fp_thread_set_deleted($thread, false);
            return [true, 'Тема восстановлена.', $back];
    }
    return [false, 'Неизвестное действие.', $back];
}

// ---------- Поиск ----------

// Экранирование для LIKE с ESCAPE '!'
function fp_like($s)
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string)$s) . '%';
}

// Отрывок текста вокруг найденного слова с подсветкой <mark>
function fp_snippet($text, $q, $len = 260)
{
    $plain = bbcode_plain($text);
    if ($q === '') {
        return e(str_limit($plain, $len));
    }
    $pos = mb_stripos($plain, $q);
    if ($pos === false) {
        $out = str_limit($plain, $len);
    } else {
        $start = max(0, $pos - 80);
        $out = mb_substr($plain, $start, $len);
        if ($start > 0) {
            $out = '…' . ltrim($out);
        }
        if ($start + $len < mb_strlen($plain)) {
            $out = rtrim($out) . '…';
        }
    }
    return fp_highlight($out, $q);
}

// Экранирует текст и подсвечивает совпадения
function fp_highlight($text, $q)
{
    $safe = e($text);
    if ($q === '') {
        return $safe;
    }
    $res = preg_replace('~' . preg_quote(e($q), '~') . '~iu', '<mark>$0</mark>', $safe);
    return $res === null ? $safe : $res;
}
