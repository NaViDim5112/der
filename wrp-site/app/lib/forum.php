<?php
// Логика форума: разделы и права, темы, префиксы, непрочитанное, оповещения, статистика.

// ---------- Разделы ----------

// Все разделы [id => раздел], кэш на запрос
function nodes_all($reset = false)
{
    static $nodes = null;
    if ($reset) {
        $nodes = null;
        return [];
    }
    if ($nodes === null) {
        $nodes = [];
        foreach (db_all('SELECT * FROM nodes ORDER BY display_order, id') as $n) {
            $nodes[(int)$n['id']] = $n;
        }
    }
    return $nodes;
}

function node_get($id)
{
    $all = nodes_all();
    return $all[(int)$id] ?? null;
}

// id дочерних разделов
function node_children_ids($parentId)
{
    $ids = [];
    foreach (nodes_all() as $id => $n) {
        $match = $parentId === null
            ? $n['parent_id'] === null
            : ($n['parent_id'] !== null && (int)$n['parent_id'] === (int)$parentId);
        if ($match) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function node_roots()
{
    $ids = [];
    foreach (nodes_all() as $id => $n) {
        if ($n['parent_id'] === null) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function node_can_view($node, $level = null)
{
    if (!$node) {
        return false;
    }
    $level = $level === null ? user_level() : $level;
    if (can_moderate()) {
        return true;
    }
    // доступ ещё и ко всем родителям
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

// Можно ли создавать темы
function node_can_thread($node)
{
    if (!$node || $node['type'] !== 'forum' || !is_logged() || is_banned()) {
        return false;
    }
    if (can_moderate()) {
        return true;
    }
    return node_can_view($node) && !$node['is_closed'] && user_level() >= (int)$node['thread_level'];
}

// Можно ли отвечать в темах раздела (без учёта закрытия темы)
function node_can_reply($node)
{
    if (!$node || !is_logged() || is_banned()) {
        return false;
    }
    if (can_moderate()) {
        return true;
    }
    return node_can_view($node) && !$node['is_closed'] && user_level() >= (int)$node['reply_level'];
}

// Раздел виден в списках (даже если закрыт, показывается «Приватный»)
function node_listed($node)
{
    return node_can_view($node) || !$node['hide_if_no_access'];
}

function node_url($node)
{
    if ($node['type'] === 'link' && $node['link_url']) {
        return $node['link_url'];
    }
    if ($node['type'] === 'category') {
        return url('/forum/category.php', ['id' => (int)$node['id']]);
    }
    return url('/forum/forum.php', ['id' => (int)$node['id']]);
}

// Цепочка от корня до раздела (включая его)
function node_path($id)
{
    $path = [];
    $cur = node_get($id);
    $guard = 0;
    while ($cur && $guard++ < 20) {
        array_unshift($path, $cur);
        $cur = $cur['parent_id'] ? node_get($cur['parent_id']) : null;
    }
    return $path;
}

// «Хлебные крошки» до раздела: [['Форумы', url], ...]
function node_crumbs($id, $includeSelf = true)
{
    $crumbs = [['Форумы', url('/forum/')]];
    foreach (node_path($id) as $n) {
        if (!$includeSelf && (int)$n['id'] === (int)$id) {
            continue;
        }
        $crumbs[] = [$n['title'], node_url($n)];
    }
    return $crumbs;
}

// Все id в поддереве (включая сам раздел)
function node_subtree_ids($id)
{
    $ids = [(int)$id];
    foreach (node_children_ids($id) as $cid) {
        $ids = array_merge($ids, node_subtree_ids($cid));
    }
    return $ids;
}

// Видимые дочерние разделы
function node_visible_children($parentId)
{
    $out = [];
    foreach (node_children_ids($parentId) as $cid) {
        $n = node_get($cid);
        if (node_listed($n)) {
            $out[] = $n;
        }
    }
    return $out;
}

// Сумма по поддереву: темы, сообщения, самый свежий раздел с последним сообщением (только видимые)
function node_aggregate($id)
{
    $threads = 0;
    $posts = 0;
    $last = null;
    foreach (node_subtree_ids($id) as $nid) {
        $n = node_get($nid);
        if (!$n || $n['type'] !== 'forum') {
            continue;
        }
        $threads += (int)$n['thread_count'];
        $posts += (int)$n['post_count'];
        if (node_can_view($n) && $n['last_post_at'] && (!$last || $n['last_post_at'] > $last['last_post_at'])) {
            $last = $n;
        }
    }
    return ['threads' => $threads, 'posts' => $posts, 'last' => $last];
}

// Пересчёт счётчиков раздела по таблице тем
function node_rebuild($nodeId)
{
    $nodeId = (int)$nodeId;
    $c = db_one('SELECT COUNT(*) AS t, COALESCE(SUM(reply_count + 1), 0) AS p FROM threads WHERE node_id = :n AND is_deleted = 0', ['n' => $nodeId]);
    $last = db_one('SELECT id, last_post_id, last_post_at, last_post_user_id FROM threads WHERE node_id = :n AND is_deleted = 0 ORDER BY last_post_at DESC, id DESC LIMIT 1', ['n' => $nodeId]);
    db_update('nodes', [
        'thread_count' => (int)$c['t'],
        'post_count' => (int)$c['p'],
        'last_thread_id' => $last ? (int)$last['id'] : null,
        'last_post_id' => $last ? (int)$last['last_post_id'] : null,
        'last_post_at' => $last ? $last['last_post_at'] : null,
        'last_post_user_id' => $last ? (int)$last['last_post_user_id'] : null,
    ], 'id = :id', ['id' => $nodeId]);
    nodes_all(true);
}

// Данные для колонки «последнее сообщение»: [thread_id => тема с префиксом и автором последнего ответа]
function last_threads_info(array $threadIds)
{
    $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
    if (!$threadIds) {
        return [];
    }
    $in = db_in($threadIds, 't');
    $rows = db_all('SELECT t.id, t.title, t.prefix_id, t.last_post_at, t.last_post_id, t.last_post_user_id,
                           u.id AS user_id, u.username, u.avatar, u.group_id, g.color AS group_color
                    FROM threads t
                    LEFT JOIN users u ON u.id = t.last_post_user_id
                    LEFT JOIN user_groups g ON g.id = u.group_id
                    WHERE t.id IN (' . $in['sql'] . ')', $in['params']);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

// ---------- Темы и сообщения ----------

function thread_get($id)
{
    return db_one('SELECT * FROM threads WHERE id = :id', ['id' => (int)$id]);
}

function thread_url($id, $page = 1)
{
    return url('/forum/thread.php', ['id' => (int)$id, 'page' => $page > 1 ? (int)$page : null]);
}

// Ссылка на сообщение (страница сама найдёт нужную страницу темы)
function post_url($id)
{
    return url('/forum/post.php', ['id' => (int)$id]);
}

// Пересчёт темы: ответы, первое и последнее сообщение
function thread_rebuild($threadId)
{
    $threadId = (int)$threadId;
    $c = (int)db_val('SELECT COUNT(*) FROM posts WHERE thread_id = :t AND is_deleted = 0', ['t' => $threadId]);
    $first = db_one('SELECT id FROM posts WHERE thread_id = :t AND is_deleted = 0 ORDER BY id ASC LIMIT 1', ['t' => $threadId]);
    $last = db_one('SELECT id, user_id, created_at FROM posts WHERE thread_id = :t AND is_deleted = 0 ORDER BY id DESC LIMIT 1', ['t' => $threadId]);
    if (!$last) {
        return;
    }
    db_update('threads', [
        'reply_count' => max(0, $c - 1),
        'first_post_id' => (int)$first['id'],
        'last_post_id' => (int)$last['id'],
        'last_post_at' => $last['created_at'],
        'last_post_user_id' => (int)$last['user_id'],
    ], 'id = :id', ['id' => $threadId]);
}

// Пересчёт счётчиков пользователя
function user_rebuild_counts($userId)
{
    $userId = (int)$userId;
    db_exec('UPDATE users SET
        posts_count = (SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.user_id = :a AND p.is_deleted = 0 AND t.is_deleted = 0),
        threads_count = (SELECT COUNT(*) FROM threads WHERE user_id = :b AND is_deleted = 0),
        likes_received = (SELECT COUNT(*) FROM post_likes l JOIN posts p ON p.id = l.post_id JOIN threads t ON t.id = p.thread_id WHERE p.user_id = :c AND p.is_deleted = 0 AND t.is_deleted = 0)
        WHERE id = :id', ['a' => $userId, 'b' => $userId, 'c' => $userId, 'id' => $userId]);
}

// Флуд-контроль: true, если можно писать
function flood_ok()
{
    if (is_staff()) {
        return true;
    }
    $sec = setting_int('flood_seconds');
    if ($sec <= 0) {
        return true;
    }
    $last = db_val('SELECT MAX(created_at) FROM posts WHERE user_id = :u', ['u' => uid()]);
    return !$last || strtotime($last) <= time() - $sec;
}

// ---------- Префиксы ----------

function prefixes_all()
{
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (db_all('SELECT * FROM prefixes ORDER BY display_order, id') as $r) {
            $p[(int)$r['id']] = $r;
        }
    }
    return $p;
}

function prefix_html($prefixId)
{
    $all = prefixes_all();
    if (!$prefixId || !isset($all[(int)$prefixId])) {
        return '';
    }
    $p = $all[(int)$prefixId];
    return '<span class="prefix prefix-' . e($p['color']) . '">' . e($p['title']) . '</span>';
}

function prefix_colors()
{
    return ['gray' => 'Серый', 'light' => 'Светлый', 'green' => 'Зелёный', 'red' => 'Красный', 'orange' => 'Оранжевый',
        'yellow' => 'Жёлтый', 'blue' => 'Синий', 'purple' => 'Фиолетовый', 'pink' => 'Розовый', 'teal' => 'Бирюзовый', 'dark' => 'Чёрный'];
}

// Префиксы, разрешённые в разделе
function node_prefixes($nodeId)
{
    $ids = array_map('intval', array_column(db_all('SELECT prefix_id FROM node_prefixes WHERE node_id = :n', ['n' => (int)$nodeId]), 'prefix_id'));
    $all = prefixes_all();
    $out = [];
    foreach ($all as $id => $p) {
        if (in_array($id, $ids, true)) {
            $out[$id] = $p;
        }
    }
    return $out;
}

// ---------- Непрочитанное ----------

// Всё, что старше 30 дней или старше регистрации, считается прочитанным
function read_cutoff()
{
    $cut = time() - 86400 * 30;
    $u = user();
    if ($u && strtotime($u['created_at']) > $cut) {
        $cut = strtotime($u['created_at']);
    }
    return date('Y-m-d H:i:s', $cut);
}

// [node_id => число непрочитанных тем] для текущего пользователя
function unread_by_node()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!is_logged()) {
        return $cache;
    }
    $rows = db_all('SELECT t.node_id, COUNT(*) AS c FROM threads t
        LEFT JOIN thread_reads tr ON tr.thread_id = t.id AND tr.user_id = :u1
        LEFT JOIN node_reads nr ON nr.node_id = t.node_id AND nr.user_id = :u2
        WHERE t.is_deleted = 0 AND t.last_post_at > :cut
          AND (tr.read_at IS NULL OR tr.read_at < t.last_post_at)
          AND (nr.read_at IS NULL OR nr.read_at < t.last_post_at)
        GROUP BY t.node_id', ['u1' => uid(), 'u2' => uid(), 'cut' => read_cutoff()]);
    foreach ($rows as $r) {
        $cache[(int)$r['node_id']] = (int)$r['c'];
    }
    return $cache;
}

// Есть ли непрочитанное в поддереве (только в доступных разделах)
function node_has_unread($nodeId)
{
    $unread = unread_by_node();
    if (!$unread) {
        return false;
    }
    foreach (node_subtree_ids($nodeId) as $id) {
        if (!empty($unread[$id]) && node_can_view(node_get($id))) {
            return true;
        }
    }
    return false;
}

// Время прочтения тем: [thread_id => read_at] с учётом отметки раздела и порога
function thread_read_times(array $threads)
{
    $out = [];
    if (!is_logged() || !$threads) {
        return $out;
    }
    $ids = array_map(function ($t) {
        return (int)$t['id'];
    }, $threads);
    $nodeIds = array_values(array_unique(array_map(function ($t) {
        return (int)$t['node_id'];
    }, $threads)));
    $in = db_in($ids, 't');
    $reads = [];
    foreach (db_all('SELECT thread_id, read_at FROM thread_reads WHERE user_id = :u AND thread_id IN (' . $in['sql'] . ')', array_merge(['u' => uid()], $in['params'])) as $r) {
        $reads[(int)$r['thread_id']] = $r['read_at'];
    }
    $nin = db_in($nodeIds, 'n');
    $nodeReads = [];
    foreach (db_all('SELECT node_id, read_at FROM node_reads WHERE user_id = :u AND node_id IN (' . $nin['sql'] . ')', array_merge(['u' => uid()], $nin['params'])) as $r) {
        $nodeReads[(int)$r['node_id']] = $r['read_at'];
    }
    $cut = read_cutoff();
    foreach ($threads as $t) {
        $at = $cut;
        if (isset($reads[(int)$t['id']]) && $reads[(int)$t['id']] > $at) {
            $at = $reads[(int)$t['id']];
        }
        if (isset($nodeReads[(int)$t['node_id']]) && $nodeReads[(int)$t['node_id']] > $at) {
            $at = $nodeReads[(int)$t['node_id']];
        }
        $out[(int)$t['id']] = $at;
    }
    return $out;
}

// Отметить тему прочитанной до момента $at (по умолчанию сейчас)
function thread_mark_read($threadId, $at = null)
{
    if (!is_logged()) {
        return;
    }
    $at = $at ?: now();
    db_exec('INSERT INTO thread_reads (user_id, thread_id, read_at) VALUES (:u, :t, :a)
             ON DUPLICATE KEY UPDATE read_at = GREATEST(read_at, VALUES(read_at))',
        ['u' => uid(), 't' => (int)$threadId, 'a' => $at]);
}

// Отметить раздел (и подразделы) прочитанными. Без аргумента - весь форум.
function node_mark_read($nodeId = null)
{
    if (!is_logged()) {
        return;
    }
    $ids = $nodeId ? node_subtree_ids($nodeId) : array_keys(nodes_all());
    foreach ($ids as $id) {
        db_exec('INSERT INTO node_reads (user_id, node_id, read_at) VALUES (:u, :n, :a)
                 ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)', ['u' => uid(), 'n' => (int)$id, 'a' => now()]);
    }
}

// ---------- Отслеживание тем ----------

function thread_is_watched($threadId)
{
    if (!is_logged()) {
        return false;
    }
    return (bool)db_val('SELECT 1 FROM thread_watch WHERE user_id = :u AND thread_id = :t', ['u' => uid(), 't' => (int)$threadId]);
}

function thread_watch($threadId, $on = true, $userId = null)
{
    $userId = $userId ?: uid();
    if (!$userId) {
        return;
    }
    if ($on) {
        db_exec('INSERT IGNORE INTO thread_watch (user_id, thread_id, created_at) VALUES (:u, :t, :c)', ['u' => (int)$userId, 't' => (int)$threadId, 'c' => now()]);
    } else {
        db_exec('DELETE FROM thread_watch WHERE user_id = :u AND thread_id = :t', ['u' => (int)$userId, 't' => (int)$threadId]);
    }
}

// ---------- Подписка на разделы ----------

function node_is_watched($nodeId)
{
    if (!is_logged()) {
        return false;
    }
    return (bool)db_val('SELECT 1 FROM node_watch WHERE user_id = :u AND node_id = :n', ['u' => uid(), 'n' => (int)$nodeId]);
}

function node_watch($nodeId, $on = true)
{
    if (!is_logged()) {
        return;
    }
    if ($on) {
        db_exec('INSERT IGNORE INTO node_watch (user_id, node_id, created_at) VALUES (:u, :n, :c)', ['u' => uid(), 'n' => (int)$nodeId, 'c' => now()]);
    } else {
        db_exec('DELETE FROM node_watch WHERE user_id = :u AND node_id = :n', ['u' => uid(), 'n' => (int)$nodeId]);
    }
}

// id пользователей, подписанных на раздел или на его родителей
function node_watchers($nodeId)
{
    $ids = array_map(function ($n) {
        return (int)$n['id'];
    }, node_path($nodeId));
    if (!$ids) {
        return [];
    }
    $in = db_in($ids, 'n');
    return array_map('intval', array_column(db_all('SELECT DISTINCT user_id FROM node_watch WHERE node_id IN (' . $in['sql'] . ')', $in['params']), 'user_id'));
}

// ---------- Оповещения ----------

// Типы: reply (ответ в отслеживаемой теме), quote, like, prefix (смена статуса), mention, move,
// thread (новая тема в отслеживаемом разделе), conversation, profile_post, profile_comment, profile_like, follow
function alert_add($userId, $type, $actorId = null, $threadId = null, $postId = null, $extra = null)
{
    $userId = (int)$userId;
    if ($userId <= 0 || ($actorId && (int)$actorId === $userId)) {
        return;
    }
    db_insert('alerts', [
        'user_id' => $userId,
        'actor_id' => $actorId ? (int)$actorId : null,
        'type' => $type,
        'thread_id' => $threadId ? (int)$threadId : null,
        'post_id' => $postId ? (int)$postId : null,
        'extra' => $extra !== null ? mb_substr((string)$extra, 0, 255) : null,
        'is_read' => 0,
        'created_at' => now(),
    ]);
}

function alerts_unread_count()
{
    if (!is_logged()) {
        return 0;
    }
    return (int)db_val('SELECT COUNT(*) FROM alerts WHERE user_id = :u AND is_read = 0', ['u' => uid()]);
}

// Текст оповещения
function alert_text($a)
{
    $actor = !empty($a['actor_name']) ? '<b>' . e($a['actor_name']) . '</b>' : 'Кто-то';
    $title = !empty($a['thread_title']) ? '«' . e($a['thread_title']) . '»' : '';
    switch ($a['type']) {
        case 'reply':
            return $actor . ' ответил(а) в теме ' . $title;
        case 'quote':
            return $actor . ' процитировал(а) ваше сообщение в теме ' . $title;
        case 'like':
            return $actor . ' оценил(а) ваше сообщение в теме ' . $title;
        case 'mention':
            return $actor . ' упомянул(а) вас в теме ' . $title;
        case 'prefix':
            return 'Статус темы ' . $title . ' изменён: <b>' . e($a['extra']) . '</b>';
        case 'move':
            return 'Ваша тема ' . $title . ' перенесена' . (!empty($a['extra']) ? ' в «' . e($a['extra']) . '»' : '');
        case 'conversation':
            return $actor . ' написал(а) вам личное сообщение';
        case 'profile_post':
            return $actor . ' написал(а) сообщение в вашем профиле';
        case 'profile_comment':
            return $actor . ' прокомментировал(а) сообщение в профиле';
        case 'profile_like':
            return $actor . ' оценил(а) ваше сообщение в профиле';
        case 'follow':
            return $actor . ' подписался(ась) на вас';
        case 'thread':
            return $actor . ' создал(а) тему ' . $title;
        default:
            return e($a['extra'] ?? 'Новое оповещение');
    }
}

// Куда ведёт оповещение.
// reply/quote/like/mention/prefix/move: post_id или thread_id;
// conversation: extra = id переписки; profile_*: post_id = id сообщения стены, extra = id владельца профиля;
// follow: actor_id.
function alert_link($a)
{
    switch ($a['type']) {
        case 'conversation':
            return url('/forum/conversations.php', ['id' => (int)$a['extra']]);
        case 'profile_post':
        case 'profile_comment':
        case 'profile_like':
            return url('/forum/member.php', ['id' => (int)$a['extra']]) . ($a['post_id'] ? '#profile-post-' . (int)$a['post_id'] : '');
        case 'follow':
            return url('/forum/member.php', ['id' => (int)$a['actor_id']]);
    }
    if (!empty($a['post_id'])) {
        return post_url($a['post_id']);
    }
    if (!empty($a['thread_id'])) {
        return thread_url($a['thread_id']);
    }
    return url('/forum/alerts.php');
}

// id пользователей, которых игнорирует текущий пользователь
function ignored_user_ids()
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        if (is_logged()) {
            $ids = array_map('intval', array_column(db_all('SELECT ignored_user_id FROM user_ignores WHERE user_id = :u', ['u' => uid()]), 'ignored_user_id'));
        }
    }
    return $ids;
}

function conversations_unread_count()
{
    if (!is_logged()) {
        return 0;
    }
    return (int)db_val('SELECT COUNT(*) FROM conversation_users cu JOIN conversations c ON c.id = cu.conversation_id
        WHERE cu.user_id = :u AND cu.is_left = 0 AND (cu.last_read_at IS NULL OR cu.last_read_at < c.last_message_at)
          AND c.last_message_user_id <> :u2', ['u' => uid(), 'u2' => uid()]);
}

// ---------- Статистика и онлайн ----------

function forum_stats()
{
    $s = db_one('SELECT
        (SELECT COUNT(*) FROM threads WHERE is_deleted = 0) AS threads,
        (SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.is_deleted = 0 AND t.is_deleted = 0) AS posts,
        (SELECT COUNT(*) FROM users) AS users');
    $s['newest'] = db_one('SELECT u.id, u.username, g.color AS group_color FROM users u JOIN user_groups g ON g.id = u.group_id ORDER BY u.id DESC LIMIT 1');
    return $s;
}

// Кто на сайте за последние 15 минут
function online_list($limit = 60)
{
    $since = date('Y-m-d H:i:s', time() - 900);
    $users = db_all('SELECT u.id, u.username, u.group_id, g.color AS group_color, g.display_order
        FROM users u JOIN user_groups g ON g.id = u.group_id
        WHERE u.last_activity > :t ORDER BY g.is_staff DESC, u.last_activity DESC', ['t' => $since]);
    $guests = (int)db_val('SELECT COUNT(*) FROM online WHERE user_id IS NULL AND last_activity > :t', ['t' => $since]);
    return [
        'users' => array_slice($users, 0, $limit),
        'more' => max(0, count($users) - $limit),
        'members' => count($users),
        'guests' => $guests,
        'total' => count($users) + $guests,
    ];
}

// Разделы для блока «Быстрая навигация»
function quick_nav_nodes()
{
    $out = [];
    foreach (nodes_all() as $n) {
        if ((int)$n['quick_nav'] > 0 && node_listed($n)) {
            $out[] = $n;
        }
    }
    usort($out, function ($a, $b) {
        return (int)$a['quick_nav'] - (int)$b['quick_nav'];
    });
    return $out;
}

// Ссылки левого меню из админки
function nav_links()
{
    static $links = null;
    if ($links === null) {
        $links = db_all('SELECT * FROM nav_links ORDER BY display_order, id');
    }
    return $links;
}

// ---------- Журнал модерации ----------

function mod_log($action, $targetType, $targetId, $details = '')
{
    if (!is_logged()) {
        return;
    }
    db_insert('mod_log', [
        'user_id' => uid(),
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => (int)$targetId,
        'details' => mb_substr((string)$details, 0, 500),
        'created_at' => now(),
    ]);
}
