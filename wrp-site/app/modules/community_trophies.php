<?php
// Сообщество: награды (трофеи), баллы за награды, звания по баллам.
// Баллы за награды хранятся в users.trophy_points и пересчитываются при выдаче и снятии.
// Данные для карточек авторов в теме собираются одним набором запросов на страницу (com_user_data).

// Условия выдачи: ключ => [название для админки, что нужно сделать]
function com_trophy_criteria()
{
    return [
        'posts_count' => ['Сообщений на форуме', 'Написать сообщений'],
        'threads_count' => ['Создано тем', 'Создать тем'],
        'likes_received' => ['Получено реакций', 'Получить реакций'],
        'days_registered' => ['Дней с регистрации', 'Дней на форуме'],
        'game_linked' => ['Привязан игровой аккаунт', 'Привязать игровой аккаунт'],
        'manual' => ['Выдаёт администрация', 'Выдаёт администрация'],
    ];
}

// Все награды [id => награда], кэш на запрос
function com_trophies_all($reset = false)
{
    static $all = null;
    if ($reset) {
        $all = null;
        return [];
    }
    if ($all === null) {
        $all = [];
        if (com_ready()) {
            foreach (db_all('SELECT * FROM com_trophies ORDER BY display_order, id') as $t) {
                $all[(int)$t['id']] = $t;
            }
        }
    }
    return $all;
}

// Ручные награды первыми (списки выдачи), порядок внутри сохраняется
function com_trophies_manual_first(array $list)
{
    $manual = [];
    $auto = [];
    foreach ($list as $t) {
        if ($t['criteria'] === 'manual') {
            $manual[] = $t;
        } else {
            $auto[] = $t;
        }
    }
    return array_merge($manual, $auto);
}

// Что нужно для награды: "100 сообщений", "30 дней на форуме"
function com_trophy_goal_text(array $t)
{
    $v = (int)$t['criteria_value'];
    switch ($t['criteria']) {
        case 'posts_count':
            return num($v) . ' ' . plural($v, 'сообщение', 'сообщения', 'сообщений') . ' на форуме';
        case 'threads_count':
            return num($v) . ' ' . plural($v, 'тема', 'темы', 'тем') . ' на форуме';
        case 'likes_received':
            return num($v) . ' ' . plural($v, 'реакция', 'реакции', 'реакций') . ' на сообщения';
        case 'days_registered':
            return $v > 0 ? com_days_text($v) . ' на форуме' : 'Регистрация на форуме';
        case 'game_linked':
            return 'Привязать игровой аккаунт в личном кабинете';
    }
    return 'Выдаёт администрация';
}

// Прогресс по условию: [сейчас, нужно] или null для ручных наград
function com_trophy_progress(array $t, array $u, $now = null)
{
    $now = $now === null ? time() : (int)$now;
    $v = max(0, (int)$t['criteria_value']);
    switch ($t['criteria']) {
        case 'posts_count':
            return [(int)($u['posts_count'] ?? 0), $v];
        case 'threads_count':
            return [(int)($u['threads_count'] ?? 0), $v];
        case 'likes_received':
            return [(int)($u['likes_received'] ?? 0), $v];
        case 'days_registered':
            $created = !empty($u['created_at']) ? strtotime((string)$u['created_at']) : $now;
            return [max(0, (int)floor(($now - $created) / 86400)), $v];
        case 'game_linked':
            return [trim((string)($u['game_nick'] ?? '')) !== '' ? 1 : 0, 1];
    }
    return null;
}

function com_trophy_qualifies(array $t, array $u, $now = null)
{
    $p = com_trophy_progress($t, $u, $now);
    return $p !== null && $p[0] >= $p[1];
}

// Условие для выборки всех, кто заслужил награду: ['sql' => ..., 'params' => ...] или null
function com_trophy_sql(array $t)
{
    $v = max(0, (int)$t['criteria_value']);
    switch ($t['criteria']) {
        case 'posts_count':
            return ['sql' => 'u.posts_count >= :cv', 'params' => ['cv' => $v]];
        case 'threads_count':
            return ['sql' => 'u.threads_count >= :cv', 'params' => ['cv' => $v]];
        case 'likes_received':
            return ['sql' => 'u.likes_received >= :cv', 'params' => ['cv' => $v]];
        case 'days_registered':
            return ['sql' => 'u.created_at <= :cv', 'params' => ['cv' => date('Y-m-d H:i:s', time() - $v * 86400)]];
        case 'game_linked':
            return ['sql' => "u.game_nick IS NOT NULL AND u.game_nick <> ''", 'params' => []];
    }
    return null;
}

// Значок награды (круглый медальон)
function com_trophy_badge(array $t, $size = '', $locked = false)
{
    $cls = 'com-medal com-c-' . com_color_key($t['color']) . ($size !== '' ? ' com-medal-' . $size : '') . ($locked ? ' is-locked' : '');
    return '<span class="' . e($cls) . '">' . icon($t['icon']) . '</span>';
}

// id наград пользователя
function com_user_trophy_ids($uid)
{
    return array_map('intval', array_column(db_all('SELECT trophy_id FROM com_user_trophies WHERE user_id = :u', ['u' => (int)$uid]), 'trophy_id'));
}

// Пересчёт users.trophy_points для списка пользователей
function com_recount_points(array $uids)
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    foreach (array_chunk($uids, 500) as $chunk) {
        $in = db_in($chunk, 'u');
        db_exec('UPDATE users u SET u.trophy_points = (SELECT COALESCE(SUM(t.points), 0) FROM com_user_trophies ut JOIN com_trophies t ON t.id = ut.trophy_id WHERE ut.user_id = u.id)
                 WHERE u.id IN (' . $in['sql'] . ')', $in['params']);
    }
    $cache = &com_user_cache();
    foreach ($uids as $id) {
        unset($cache[$id]);
    }
}

// Пересчёт у всех, у кого есть награда (после смены её баллов)
function com_recount_holders($trophyId)
{
    db_exec('UPDATE users u SET u.trophy_points = (SELECT COALESCE(SUM(t.points), 0) FROM com_user_trophies ut JOIN com_trophies t ON t.id = ut.trophy_id WHERE ut.user_id = u.id)
             WHERE u.id IN (SELECT h.user_id FROM com_user_trophies h WHERE h.trophy_id = :t)', ['t' => (int)$trophyId]);
}

// Записать награду (без пересчёта баллов). true, если её ещё не было.
function com_award_insert($uid, $trophyId, $by = null, $note = null)
{
    $n = db_exec('INSERT IGNORE INTO com_user_trophies (user_id, trophy_id, awarded_at, awarded_by, note) VALUES (:u, :t, :at, :by, :note)', [
        'u' => (int)$uid,
        't' => (int)$trophyId,
        'at' => now(),
        'by' => $by ? (int)$by : null,
        'note' => $note !== null && $note !== '' ? mb_substr((string)$note, 0, 255) : null,
    ]);
    if ($n && setting('com_trophy_alerts') === '1') {
        alert_add((int)$uid, 'trophy', $by ? (int)$by : null, null, null, (string)(int)$trophyId);
    }
    return $n > 0;
}

// Выдать награду с пересчётом баллов. true, если выдана сейчас.
function com_award($uid, $trophyId, $by = null, $note = null)
{
    if (!com_award_insert($uid, $trophyId, $by, $note)) {
        return false;
    }
    com_recount_points([$uid]);
    return true;
}

function com_revoke($uid, $trophyId)
{
    $n = db_exec('DELETE FROM com_user_trophies WHERE user_id = :u AND trophy_id = :t', ['u' => (int)$uid, 't' => (int)$trophyId]);
    if ($n) {
        com_recount_points([$uid]);
    }
    return $n > 0;
}

// Проверка автоматических наград одного пользователя (после сообщения, темы, входа). Возвращает id новых наград.
function com_check_user($uid)
{
    $uid = (int)$uid;
    if ($uid <= 0 || !com_ready()) {
        return [];
    }
    $auto = [];
    foreach (com_trophies_all() as $t) {
        if ($t['criteria'] !== 'manual') {
            $auto[] = $t;
        }
    }
    if (!$auto) {
        return [];
    }
    $u = db_one('SELECT id, posts_count, threads_count, likes_received, created_at, game_nick, is_banned FROM users WHERE id = :id', ['id' => $uid]);
    if (!$u || !empty($u['is_banned'])) {
        return [];
    }
    $have = array_flip(com_user_trophy_ids($uid));
    $new = [];
    foreach ($auto as $t) {
        if (!isset($have[(int)$t['id']]) && com_trophy_qualifies($t, $u) && com_award_insert($uid, $t['id'])) {
            $new[] = (int)$t['id'];
        }
    }
    if ($new) {
        com_recount_points([$uid]);
    }
    return $new;
}

// Фоновая задача: автоматические награды всем, кто их заслужил. Возвращает число выданных.
function com_cron_trophies()
{
    if (!com_ready()) {
        return 0;
    }
    $touched = [];
    $given = 0;
    foreach (com_trophies_all() as $t) {
        $cond = com_trophy_sql($t);
        if ($cond === null) {
            continue;
        }
        $rows = db_all('SELECT u.id FROM users u WHERE u.is_banned = 0 AND ' . $cond['sql'] . '
            AND NOT EXISTS (SELECT 1 FROM com_user_trophies ut WHERE ut.user_id = u.id AND ut.trophy_id = :tid)
            ORDER BY u.id LIMIT 2000', array_merge($cond['params'], ['tid' => (int)$t['id']]));
        foreach ($rows as $r) {
            if (com_award_insert($r['id'], $t['id'])) {
                $touched[(int)$r['id']] = true;
                $given++;
            }
        }
    }
    com_recount_points(array_keys($touched));
    return $given;
}

// ---------- Звания ----------

// Лестница званий по возрастанию баллов
function com_ranks_all($reset = false)
{
    static $ranks = null;
    if ($reset) {
        $ranks = null;
        return [];
    }
    if ($ranks === null) {
        $ranks = com_ready() ? db_all('SELECT * FROM com_ranks ORDER BY min_points, id') : [];
    }
    return $ranks;
}

// Звание по баллам (последняя ступень, до которой дотянулся) или null
function com_rank_for($points, $ranks = null)
{
    $ranks = $ranks === null ? com_ranks_all() : $ranks;
    $found = null;
    foreach ($ranks as $r) {
        if ((int)$points >= (int)$r['min_points'] && ($found === null || (int)$r['min_points'] >= (int)$found['min_points'])) {
            $found = $r;
        }
    }
    return $found;
}

// Следующее звание или null
function com_rank_next($points, $ranks = null)
{
    $ranks = $ranks === null ? com_ranks_all() : $ranks;
    foreach ($ranks as $r) {
        if ((int)$r['min_points'] > (int)$points) {
            return $r;
        }
    }
    return null;
}

function com_rank_html($rank)
{
    if (!$rank) {
        return '';
    }
    return '<span class="com-rank com-c-' . e(com_color_key($rank['color'])) . '">' . icon('award') . '<span>' . e($rank['title']) . '</span></span>';
}

// ---------- Данные авторов на странице (один набор запросов) ----------

function &com_user_cache()
{
    static $cache = [];
    return $cache;
}

// Контекст страницы темы: чьи карточки будут выведены
function com_page_context($set = null)
{
    static $ctx = null;
    if ($set !== null) {
        $ctx = $set;
    }
    return $ctx;
}

hook_add('thread_before_posts', function ($thread, $node, $pg) {
    com_page_context([
        'thread' => (int)$thread['id'],
        'limit' => (int)$pg['per_page'],
        'offset' => (int)$pg['offset'],
        'deleted' => can_moderate(),
        'loaded' => false,
    ]);
    return '';
});

function com_user_blank()
{
    return ['tp' => 0, 'fields' => [], 'trophies' => []];
}

// Баллы за награды, значения полей для карточки, награды - сразу для всех авторов страницы
function com_preload_users(array $ids)
{
    $cache = &com_user_cache();
    $need = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0 && !isset($cache[$id])) {
            $need[$id] = true;
            $cache[$id] = com_user_blank();
        }
    }
    $need = array_keys($need);
    if (!$need || !com_ready()) {
        return;
    }
    try {
        $in = db_in($need, 'u');
        foreach (db_all('SELECT id, trophy_points FROM users WHERE id IN (' . $in['sql'] . ')', $in['params']) as $r) {
            $cache[(int)$r['id']]['tp'] = (int)$r['trophy_points'];
        }
        $postFields = [];
        foreach (com_fields_all() as $f) {
            if (!empty($f['show_post'])) {
                $postFields[] = (int)$f['id'];
            }
        }
        if ($postFields) {
            $fin = db_in($postFields, 'f');
            foreach (db_all('SELECT user_id, field_id, value FROM com_field_values WHERE user_id IN (' . $in['sql'] . ') AND field_id IN (' . $fin['sql'] . ')',
                array_merge($in['params'], $fin['params'])) as $r) {
                $cache[(int)$r['user_id']]['fields'][(int)$r['field_id']] = $r['value'];
            }
        }
        if ((int)setting('com_post_trophies') > 0) {
            foreach (db_all('SELECT user_id, trophy_id FROM com_user_trophies WHERE user_id IN (' . $in['sql'] . ') ORDER BY awarded_at DESC, trophy_id DESC', $in['params']) as $r) {
                $cache[(int)$r['user_id']]['trophies'][] = (int)$r['trophy_id'];
            }
        }
    } catch (Exception $e) {
        // база ещё не обновлена - карточки без дополнений
    }
}

// Данные одного автора. Первый промах на странице темы загружает всех авторов страницы.
function com_user_data($uid)
{
    $uid = (int)$uid;
    $cache = &com_user_cache();
    if ($uid <= 0) {
        return com_user_blank();
    }
    if (!isset($cache[$uid])) {
        $ids = [$uid];
        $ctx = com_page_context();
        if ($ctx && empty($ctx['loaded']) && com_ready()) {
            $ctx['loaded'] = true;
            com_page_context($ctx);
            $rows = db_all('SELECT DISTINCT x.user_id FROM (SELECT p.user_id FROM posts p WHERE p.thread_id = :t' . ($ctx['deleted'] ? '' : ' AND p.is_deleted = 0')
                . ' ORDER BY p.id LIMIT ' . max(1, (int)$ctx['limit']) . ' OFFSET ' . max(0, (int)$ctx['offset']) . ') x', ['t' => $ctx['thread']]);
            foreach ($rows as $r) {
                $ids[] = (int)$r['user_id'];
            }
        }
        com_preload_users($ids);
    }
    return $cache[$uid] ?? com_user_blank();
}

function com_user_trophy_points($u)
{
    if (isset($u['trophy_points'])) {
        return (int)$u['trophy_points'];
    }
    if (empty($u['id']) || !com_ready()) {
        return 0;
    }
    $d = com_user_data($u['id']);
    return (int)$d['tp'];
}

// ---------- Точки подключения ----------

// Баллы: + награды
hook_add('user_points', function ($points, $u) {
    return (int)$points + com_user_trophy_points($u);
});

// Проверка наград автора после сообщения, темы, входа
hook_add('post_created', function ($postId, $thread, $node) {
    try {
        com_check_user(uid());
    } catch (Exception $e) {
        error_log('[community] ' . $e->getMessage());
    }
});
hook_add('thread_created', function ($threadId, $node, $postId) {
    try {
        com_check_user(uid());
    } catch (Exception $e) {
        error_log('[community] ' . $e->getMessage());
    }
});
hook_add('user_logged_in', function ($u) {
    try {
        if ($u) {
            com_check_user($u['id']);
        }
    } catch (Exception $e) {
        error_log('[community] ' . $e->getMessage());
    }
});

cron_register('com_trophies', 3600, 'com_cron_trophies');

// Оповещение «Вы получили награду»
hook_add('alert_text', function ($text, $a, $actor, $title) {
    if (($a['type'] ?? '') !== 'trophy') {
        return $text;
    }
    $all = com_trophies_all();
    $t = $all[(int)($a['extra'] ?? 0)] ?? null;
    return 'Вы получили награду ' . ($t ? '<b>«' . e($t['title']) . '»</b>' : '') . ($t && (int)$t['points'] ? ' (+' . (int)$t['points'] . ' ' . plural($t['points'], 'балл', 'балла', 'баллов') . ')' : '');
});
hook_add('alert_link', function ($link, $a) {
    if (($a['type'] ?? '') !== 'trophy') {
        return $link;
    }
    return url('/forum/member.php', ['id' => (int)$a['user_id'], 'tab' => 'trophies']);
});

// Карточка автора: «Звание» после «Баллы» и поля профиля для карточки
hook_add('post_user_rows', function ($rows, $u) {
    if (empty($u['id']) || !com_ready()) {
        return $rows;
    }
    $add = [];
    $rank = com_rank_for(user_points($u));
    $out = [];
    $placed = false;
    foreach ($rows as $r) {
        $out[] = $r;
        if (!$placed && $rank && ($r[0] ?? '') === 'Баллы:') {
            $out[] = ['Звание:', $rank['title']];
            $placed = true;
        }
    }
    if (!$placed && $rank) {
        $out[] = ['Звание:', $rank['title']];
    }
    $d = com_user_data($u['id']);
    foreach (com_fields_all() as $f) {
        $v = $d['fields'][(int)$f['id']] ?? '';
        if (!empty($f['show_post']) && $v !== '') {
            $add[] = [$f['title'] . ':', com_field_plain($f, $v)];
        }
    }
    return array_merge($out, $add);
});

// Под карточкой автора: последние награды значками
hook_add('post_user_after', function ($u) {
    $n = (int)setting('com_post_trophies');
    if (empty($u['id']) || $n <= 0 || !com_ready()) {
        return '';
    }
    $d = com_user_data($u['id']);
    if (!$d['trophies']) {
        return '';
    }
    $all = com_trophies_all();
    $h = '';
    $shown = 0;
    foreach ($d['trophies'] as $tid) {
        if (!isset($all[$tid]) || $shown >= $n) {
            continue;
        }
        $t = $all[$tid];
        $h .= '<span class="com-tmini com-c-' . e(com_color_key($t['color'])) . '" title="' . e($t['title']) . '">' . icon($t['icon']) . '</span>';
        $shown++;
    }
    $more = count($d['trophies']) - $shown;
    return '<a class="com-post-trophies" href="' . e(url('/forum/member.php', ['id' => (int)$u['id'], 'tab' => 'trophies'])) . '" aria-label="Награды">' . $h
        . ($more > 0 ? '<span class="com-tmini-more">+' . $more . '</span>' : '') . '</a>';
});

// Профиль: вкладка «Награды», звание в «Сведениях» и на обложке
hook_add('member_tabs', function ($tabs, $m, $tab) {
    if (com_ready()) {
        $tabs['trophies'] = 'Награды';
    }
    return $tabs;
});

hook_add('member_info_rows', function ($m) {
    if (!com_ready()) {
        return '';
    }
    $rank = com_rank_for(user_points($m));
    return $rank ? '<dt>Звание</dt><dd>' . com_rank_html($rank) . '</dd>' : '';
});

hook_add('member_cover', function ($m) {
    if (!com_ready()) {
        return '';
    }
    $rank = com_rank_for(user_points($m));
    return $rank ? '<div class="com-cover-rank">' . com_rank_html($rank) . '</div>' : '';
});

hook_add('member_tab_content', function ($tab, $m, $page) {
    if ($tab !== 'trophies' || !com_ready()) {
        return '';
    }
    return com_member_trophies_html($m);
});

// Выдача и снятие наград прямо из профиля (администрация)
hook_add('member_action', function ($action, $m, $fail, $return) {
    if ($action !== 'com_trophy_award' && $action !== 'com_trophy_revoke') {
        return;
    }
    if (!can_admin()) {
        $fail('Выдавать награды может только администрация.', 403);
    }
    if (!com_ready()) {
        $fail('Сначала обновите базу сайта в админ-панели.');
    }
    $all = com_trophies_all();
    $t = $all[input_int('trophy_id')] ?? null;
    if (!$t) {
        $fail('Такой награды нет.');
    }
    $back = url('/forum/member.php', ['id' => (int)$m['id'], 'tab' => 'trophies']);
    if ($action === 'com_trophy_award') {
        $note = mb_substr(com_clean_line(input('note')), 0, 255);
        if (com_award($m['id'], $t['id'], uid(), $note)) {
            mod_log('trophy_award', 'user', $m['id'], $t['title'] . ($note !== '' ? ': ' . $note : ''));
            flash('success', 'Награда «' . $t['title'] . '» выдана.');
        } else {
            flash('info', 'У пользователя уже есть эта награда.');
        }
    } else {
        if (com_revoke($m['id'], $t['id'])) {
            mod_log('trophy_revoke', 'user', $m['id'], $t['title']);
            flash('success', 'Награда «' . $t['title'] . '» снята.');
        }
    }
    redirect($back);
});

// Вкладка «Награды» в профиле
function com_member_trophies_html(array $m)
{
    $all = com_trophies_all();
    $earned = [];
    foreach (db_all('SELECT ut.*, a.username AS by_name FROM com_user_trophies ut LEFT JOIN users a ON a.id = ut.awarded_by WHERE ut.user_id = :u ORDER BY ut.awarded_at DESC',
        ['u' => (int)$m['id']]) as $r) {
        if (isset($all[(int)$r['trophy_id']])) {
            $earned[(int)$r['trophy_id']] = $r;
        }
    }
    $points = user_points($m);
    $rank = com_rank_for($points);
    $next = com_rank_next($points);
    $isSelf = uid() === (int)$m['id'];
    $isAdmin = can_admin();
    $tp = 0;
    foreach ($earned as $tid => $r) {
        $tp += (int)$all[$tid]['points'];
    }

    $h = '<div class="com-trophy-page">';
    // Звание и прогресс
    $h .= '<section class="card com-rank-card">';
    $h .= '<div class="com-rank-card-main"><div class="com-rank-card-label">Звание</div>' . ($rank ? com_rank_html($rank) : '<span class="muted">нет</span>');
    $h .= '<div class="com-rank-card-points"><b>' . num($points) . '</b> ' . plural($points, 'балл', 'балла', 'баллов') . ' · из них за награды: <b>' . num($tp) . '</b></div></div>';
    if ($next) {
        $from = $rank ? (int)$rank['min_points'] : 0;
        $pct = max(0, min(100, (int)round(($points - $from) * 100 / max(1, (int)$next['min_points'] - $from))));
        $h .= '<div class="com-rank-card-next"><div class="small muted">До звания «' . e($next['title']) . '»: ' . num((int)$next['min_points'] - $points) . ' ' . plural((int)$next['min_points'] - $points, 'балл', 'балла', 'баллов') . '</div>'
            . '<div class="meter"><span style="width:' . $pct . '%"></span></div></div>';
    } else {
        $h .= '<div class="com-rank-card-next"><div class="small muted">Высшее звание на форуме.</div></div>';
    }
    $h .= '<div class="com-rank-card-count"><b>' . num(count($earned)) . '</b><span>из ' . num(count($all)) . ' наград</span></div>';
    $h .= '</section>';

    // Полученные
    $h .= '<section class="card"><div class="card-head"><h2>Полученные награды</h2><a class="btn btn-sm btn-ghost" href="' . e(url('/forum/trophies.php')) . '">' . icon('trophy') . ' Все награды</a></div>';
    if ($earned) {
        $h .= '<div class="com-trophy-grid">';
        foreach ($earned as $tid => $r) {
            $t = $all[$tid];
            $h .= '<div class="com-trophy is-earned">' . com_trophy_badge($t, 'l') . '<div class="com-trophy-body">';
            $h .= '<div class="com-trophy-title">' . e($t['title']) . '</div>';
            if ($t['description'] !== null && $t['description'] !== '') {
                $h .= '<div class="com-trophy-desc">' . e($t['description']) . '</div>';
            }
            if (!empty($r['note'])) {
                $h .= '<div class="com-trophy-note">' . icon('info') . ' ' . e($r['note']) . '</div>';
            }
            $h .= '<div class="com-trophy-meta">' . e(fday($r['awarded_at'])) . ((int)$t['points'] ? ' · +' . num($t['points']) . ' ' . plural($t['points'], 'балл', 'балла', 'баллов') : '') . '</div>';
            if ($isAdmin) {
                $h .= '<form method="post" action="' . e(url('/forum/member.php', ['id' => (int)$m['id']])) . '" class="com-trophy-revoke" data-confirm="Снять награду «' . e($t['title']) . '»?">'
                    . csrf_field() . '<input type="hidden" name="action" value="com_trophy_revoke"><input type="hidden" name="trophy_id" value="' . (int)$tid . '">'
                    . '<button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Снять награду">' . icon('x') . '</button></form>';
            }
            $h .= '</div></div>';
        }
        $h .= '</div>';
    } else {
        $h .= '<div class="empty">' . icon('trophy') . '<div>' . ($isSelf ? 'У вас пока нет наград. Пишите на форуме, помогайте игрокам - награды придут сами.' : e($m['username']) . ' пока не получил(а) ни одной награды.') . '</div></div>';
    }
    $h .= '</section>';

    // Ещё не получены
    $locked = array_diff_key($all, $earned);
    if ($locked) {
        $h .= '<section class="card"><div class="card-head"><h2>Ещё не получены</h2></div><div class="com-trophy-grid">';
        foreach ($locked as $t) {
            $prog = com_trophy_progress($t, $m);
            $h .= '<div class="com-trophy is-locked">' . com_trophy_badge($t, 'l', true) . '<div class="com-trophy-body">';
            $h .= '<div class="com-trophy-title">' . e($t['title']) . '</div>';
            $h .= '<div class="com-trophy-desc">' . e(com_trophy_goal_text($t)) . '</div>';
            if ($prog !== null && $prog[1] > 1) {
                $pct = max(0, min(100, (int)floor($prog[0] * 100 / max(1, $prog[1]))));
                $h .= '<div class="com-trophy-progress"><div class="meter"><span style="width:' . $pct . '%"></span></div><span>' . num(min($prog[0], $prog[1])) . ' / ' . num($prog[1]) . '</span></div>';
            }
            if ((int)$t['points']) {
                $h .= '<div class="com-trophy-meta">+' . num($t['points']) . ' ' . plural($t['points'], 'балл', 'балла', 'баллов') . '</div>';
            }
            $h .= '</div></div>';
        }
        $h .= '</div></section>';
    }

    // Выдача наград администрацией
    if ($isAdmin && $locked) {
        $h .= '<section class="card com-award-box"><h3 class="acc-card-title">' . icon('gift') . ' Выдать награду</h3>';
        $h .= '<form method="post" action="' . e(url('/forum/member.php', ['id' => (int)$m['id']])) . '" class="com-award-form is-3">' . csrf_field()
            . '<input type="hidden" name="action" value="com_trophy_award">'
            . '<select class="select" name="trophy_id" aria-label="Награда">';
        foreach (com_trophies_manual_first($locked) as $t) {
            $h .= '<option value="' . (int)$t['id'] . '">' . e($t['title']) . ($t['criteria'] === 'manual' ? '' : ' (автоматическая)') . '</option>';
        }
        $h .= '</select><input class="input" name="note" maxlength="255" placeholder="За что (необязательно)">'
            . '<button class="btn btn-white" type="submit">' . icon('check') . ' Выдать</button></form>';
        $h .= '<div class="hint mt-1">Пользователь получит оповещение. Снять награду - крестик на карточке награды.</div></section>';
    }
    return $h . '</div>';
}
