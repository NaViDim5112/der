<?php
// Модуль «Рассмотрение»: как администрация разбирает жалобы, заявления, обращения в техподдержку и предложения.
//
// - настройки по разделам (кто рассматривает, срок ответа, архив, финальные статусы, голосование);
// - ответственные за раздел (например, лидер Правительства рассматривает заявления и жалобы на сотрудников мэрии);
// - «Взять на рассмотрение», передача другому сотруднику, решение в один клик с готовым ответом;
// - сроки ответа, очередь рассмотрения, автозакрытие без доказательств, автоперенос в архив;
// - голосование «За / Против» в предложениях, статистика сотрудников.
//
// Таблицы: wf_nodes, wf_node_staff, wf_threads, wf_events, wf_macros, wf_votes и threads.wf_up / wf_down
// (sql/migrations/0100_workflow.sql). Вывод на страницах форума - app/modules/workflow_ui.php.
// Страницы: /forum/queue.php, /forum/staff-stats.php, /forum/workflow.php (действия), /admin/workflow.php, /admin/macros.php.

// ---------- Настройки сайта ----------

hook_add('settings_defaults', function ($d) {
    $d['wf_evidence_prefix_id'] = '17';
    $d['wf_evidence_hours'] = '24';
    $d['wf_evidence_refuse_prefix_id'] = '3';
    $d['wf_evidence_text'] = "Здравствуйте, {author}.\n\nДоказательства не были предоставлены в течение {hours} ч, поэтому обращение [b]отклонено[/b] и закрыто автоматически.\n\nЕсли доказательства появятся, создайте новую тему в этом разделе.";
    $d['wf_bot_user_id'] = '0';
    $d['wf_list_deadlines'] = '0';
    $d['wf_header_icon'] = '1';
    return $d;
});

// Виды разделов: [название, «вашу жалобу» (для оповещений), «жалобы» (для заголовков)]
function wf_kinds()
{
    return [
        'complaint' => ['Жалобы', 'вашу жалобу', 'жалобы'],
        'appeal' => ['Обжалования', 'ваше обжалование', 'обжалования'],
        'application' => ['Заявления', 'ваше заявление', 'заявления'],
        'tech' => ['Техподдержка', 'ваше обращение', 'обращения'],
        'property' => ['Восстановление имущества', 'ваше обращение', 'обращения'],
        'suggestion' => ['Предложения', 'ваше предложение', 'предложения'],
        'other' => ['Другое', 'вашу тему', 'темы'],
    ];
}

function wf_kind($c, $i)
{
    $k = wf_kinds();
    $key = $c && isset($k[$c['kind']]) ? $c['kind'] : 'other';
    return $k[$key][$i];
}

// ---------- База и настройки разделов ----------

// Обновление базы применено (таблицы модуля есть). Проверяется один раз за запрос.
function wf_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db_val('SELECT node_id FROM wf_nodes LIMIT 1');
            db_val('SELECT wf_up FROM threads LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

// Строка «2,3,4» -> [2, 3, 4]
function wf_ids($csv)
{
    $out = [];
    foreach (explode(',', (string)$csv) as $x) {
        $x = (int)trim($x);
        if ($x > 0) {
            $out[] = $x;
        }
    }
    return array_values(array_unique($out));
}

// Все настройки разделов [node_id => строка], кэш на запрос
function wf_nodes_cfg($reset = false)
{
    static $cfg = null;
    if ($reset) {
        $cfg = null;
        return [];
    }
    if ($cfg === null) {
        $cfg = [];
        if (wf_ready()) {
            foreach (db_all('SELECT * FROM wf_nodes') as $r) {
                $r['finals'] = wf_ids($r['final_prefixes']);
                $cfg[(int)$r['node_id']] = $r;
            }
        }
    }
    return $cfg;
}

// Настройки раздела, если рассмотрение в нём включено, иначе null
function wf_cfg($nodeId)
{
    $all = wf_nodes_cfg();
    $c = $all[(int)$nodeId] ?? null;
    return $c && (int)$c['enabled'] === 1 ? $c : null;
}

// Минимальный уровень: пользователям (10) рассматривать нельзя никогда
function wf_min_level($c)
{
    return max(20, (int)$c['min_level']);
}

function wf_is_final($c, $prefixId)
{
    return $c && $prefixId && in_array((int)$prefixId, $c['finals'], true);
}

// Тема ждёт решения: не удалена, не закрыта, без финального статуса
function wf_is_open(array $t, $c)
{
    return $c && empty($t['is_deleted']) && empty($t['is_locked']) && !wf_is_final($c, $t['prefix_id'] ?? null);
}

// Ключ кэшей на запрос: меняется в wf_reset_caches() (тесты, фоновые задачи от имени другого пользователя)
function wf_ckey($id)
{
    return (int)($GLOBALS['wf_cache_v'] ?? 0) . ':' . $id;
}

function wf_reset_caches()
{
    $GLOBALS['wf_cache_v'] = (int)($GLOBALS['wf_cache_v'] ?? 0) + 1;
    wf_nodes_cfg(true);
    nodes_all(true);
    $c = &wf_list_cache();
    $c = ['rows' => [], 'nodes' => []];
}

// ---------- Права ----------

// Разделы, где текущий пользователь назначен ответственным
function wf_my_staff_nodes()
{
    static $cache = [];
    $me = uid();
    if (!$me || is_banned() || !wf_ready()) {
        return [];
    }
    $k = wf_ckey($me);
    if (!isset($cache[$k])) {
        $cache[$k] = array_map('intval', array_column(db_all('SELECT node_id FROM wf_node_staff WHERE user_id = :u', ['u' => $me]), 'node_id'));
    }
    return $cache[$k];
}

// Может ли текущий пользователь брать темы раздела и выносить решения
function wf_can_process($node)
{
    if (!$node || !is_logged() || is_banned()) {
        return false;
    }
    $c = wf_cfg($node['id']);
    if (!$c || !node_can_view($node)) {
        return false;
    }
    if (user_level() >= wf_min_level($c)) {
        return true;
    }
    return in_array((int)$node['id'], wf_my_staff_nodes(), true);
}

// Отвод: свою тему не рассматривают, а жалобу - и тот, чей ник (Имя_Фамилия) в ней упомянут
// (жалоба на него, он свидетель или участник). $u - строка пользователя, по умолчанию текущий.
function wf_recused(array $thread, $u = null)
{
    $u = $u ?: user();
    if (!$u) {
        return true;
    }
    if ((int)$thread['user_id'] === (int)$u['id']) {
        return true;
    }
    $c = wf_cfg($thread['node_id']);
    if (!$c || $c['kind'] !== 'complaint') {
        return false;
    }
    static $texts = [];
    $tid = (int)$thread['id'];
    if (!isset($texts[$tid])) {
        $body = (string)db_val('SELECT body FROM posts WHERE thread_id = :t ORDER BY id LIMIT 1', ['t' => $tid]);
        $texts[$tid] = $thread['title'] . "\n" . $body;
    }
    foreach ([$u['username'] ?? '', $u['game_nick'] ?? ''] as $nick) {
        $nick = trim((string)$nick);
        if (preg_match('~^[A-Za-z]{2,}_[A-Za-z]{2,}$~', $nick)
            && preg_match('~(?<![A-Za-z0-9_])' . preg_quote($nick, '~') . '(?![A-Za-z0-9_])~i', $texts[$tid])) {
            return true;
        }
    }
    return false;
}

function wf_recused_text()
{
    return 'Это ваша тема или вы упомянуты в жалобе - её рассмотрит другой сотрудник.';
}

// Старший в разделе: снимает чужие темы, передаёт их и выносит решение вместо взявшего
function wf_is_supervisor($node)
{
    $c = $node ? wf_cfg($node['id']) : null;
    return $c && wf_can_process($node) && user_level() >= max(60, wf_min_level($c));
}

// Может ли произвольный пользователь (строка user_by_id) рассматривать темы раздела
function wf_user_can_process($u, $node)
{
    if (!$u || !empty($u['is_banned']) || !$node) {
        return false;
    }
    $c = wf_cfg($node['id']);
    if (!$c || !fp_user_can_see($u, $node)) {
        return false;
    }
    if ((int)$u['level'] >= wf_min_level($c)) {
        return true;
    }
    return (bool)db_val('SELECT 1 FROM wf_node_staff WHERE node_id = :n AND user_id = :u', ['n' => (int)$node['id'], 'u' => (int)$u['id']]);
}

// id разделов, где текущий пользователь может рассматривать темы
function wf_processable_node_ids()
{
    static $cache = [];
    $me = uid();
    if (!$me) {
        return [];
    }
    $k = wf_ckey($me);
    if (!isset($cache[$k])) {
        $ids = [];
        foreach (wf_nodes_cfg() as $nid => $c) {
            $n = node_get($nid);
            if ($n && $n['type'] === 'forum' && wf_can_process($n)) {
                $ids[] = (int)$nid;
            }
        }
        $cache[$k] = $ids;
    }
    return $cache[$k];
}

// Кому можно передать тему: [id => пользователь], кэш на запрос
function wf_candidates($node)
{
    static $cache = [];
    $nid = (int)$node['id'];
    $k = wf_ckey($nid);
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $c = wf_cfg($nid);
    $out = [];
    if ($c) {
        $rows = db_all(user_select_sql() . ' WHERE u.is_banned = 0 AND (g.level >= :lvl OR (u.secondary_groups IS NOT NULL AND u.secondary_groups <> :empty)
                OR u.id IN (SELECT s.user_id FROM wf_node_staff s WHERE s.node_id = :n))
            ORDER BY g.level DESC, u.username LIMIT 300', ['lvl' => wf_min_level($c), 'empty' => '', 'n' => $nid]);
        $staffIds = array_map('intval', array_column(db_all('SELECT user_id FROM wf_node_staff WHERE node_id = :n', ['n' => $nid]), 'user_id'));
        foreach ($rows as $r) {
            $u = user_enrich($r);
            if (!fp_user_can_see($u, $node)) {
                continue;
            }
            if ((int)$u['level'] >= wf_min_level($c) || in_array((int)$u['id'], $staffIds, true)) {
                $out[(int)$u['id']] = $u;
            }
        }
    }
    return $cache[$k] = $out;
}

// «Хелпер и выше» для уровня
function wf_level_label($level)
{
    $level = (int)$level;
    $best = null;
    foreach (groups_all() as $g) {
        $l = (int)$g['level'];
        if ($l >= $level && $l < 999 && ($best === null || $l < $best)) {
            $best = $l;
        }
    }
    if ($best === null) {
        return 'уровень ' . $level . '+';
    }
    $names = [];
    foreach (groups_all() as $g) {
        if ((int)$g['level'] === $best) {
            $names[] = $g['name'];
        }
    }
    return implode(' / ', $names) . ' и выше';
}

// ---------- Сроки ----------

// Срок ответа (unix time) или 0, если срока нет
function wf_deadline_ts(array $t, $c)
{
    $h = $c ? (int)$c['deadline_hours'] : 0;
    return $h > 0 && !empty($t['created_at']) ? strtotime($t['created_at']) + $h * 3600 : 0;
}

// Состояние срока: ['state' => none|ok|soon|overdue, 'ts' => срок, 'left' => секунд до срока (минус - просрочено)]
function wf_deadline(array $t, $c)
{
    $ts = wf_deadline_ts($t, $c);
    if (!$ts || !wf_is_open($t, $c)) {
        return ['state' => 'none', 'ts' => $ts, 'left' => 0];
    }
    $left = $ts - time();
    if ($left < 0) {
        return ['state' => 'overdue', 'ts' => $ts, 'left' => $left];
    }
    $total = (int)$c['deadline_hours'] * 3600;
    return ['state' => $left < max(3 * 3600, (int)($total / 4)) ? 'soon' : 'ok', 'ts' => $ts, 'left' => $left];
}

// 45 мин, 12 ч, 2 д 5 ч
function wf_dur($sec)
{
    $sec = abs((int)$sec);
    if ($sec < 3600) {
        return max(1, (int)floor($sec / 60)) . ' мин';
    }
    $h = (int)floor($sec / 3600);
    if ($h < 48) {
        return $h . ' ч';
    }
    $d = (int)floor($h / 24);
    $rh = $h % 24;
    return $d . ' д' . ($rh ? ' ' . $rh . ' ч' : '');
}

// Срок раздела: «48 ч», «7 дней»
function wf_hours_label($h)
{
    $h = (int)$h;
    if ($h >= 96 && $h % 24 === 0) {
        $d = (int)($h / 24);
        return $d . ' ' . plural($d, 'день', 'дня', 'дней');
    }
    return $h . ' ч';
}

// ---------- Ход рассмотрения темы ----------

function wf_thread_state($threadId)
{
    return db_one('SELECT w.*, cu.username AS claimed_name, cu.avatar AS claimed_avatar, cg.color AS claimed_color,
                          vu.username AS verdict_name, vg.color AS verdict_color
                   FROM wf_threads w
                   LEFT JOIN users cu ON cu.id = w.claimed_by
                   LEFT JOIN user_groups cg ON cg.id = cu.group_id
                   LEFT JOIN users vu ON vu.id = w.verdict_by
                   LEFT JOIN user_groups vg ON vg.id = vu.group_id
                   WHERE w.thread_id = :t', ['t' => (int)$threadId]);
}

function wf_state_row_ensure($threadId)
{
    db_exec('INSERT IGNORE INTO wf_threads (thread_id, updated_at) VALUES (:t, :n)', ['t' => (int)$threadId, 'n' => now()]);
}

// Кэш для списков тем: [thread_id => строка wf_threads с ником взявшего или null]
function &wf_list_cache()
{
    static $c = ['rows' => [], 'nodes' => []];
    return $c;
}

// Загрузить ход рассмотрения сразу для пачки тем (очередь, поиск)
function wf_list_prime(array $threadIds)
{
    $cache = &wf_list_cache();
    $need = [];
    foreach ($threadIds as $id) {
        $id = (int)$id;
        if ($id > 0 && !array_key_exists($id, $cache['rows'])) {
            $need[] = $id;
            $cache['rows'][$id] = null;
        }
    }
    if (!$need || !wf_ready()) {
        return;
    }
    $in = db_in($need, 't');
    $rows = db_all('SELECT w.thread_id, w.claimed_by, w.claimed_at, u.username, u.avatar, g.color AS group_color
                    FROM wf_threads w
                    LEFT JOIN users u ON u.id = w.claimed_by
                    LEFT JOIN user_groups g ON g.id = u.group_id
                    WHERE w.claimed_by IS NOT NULL AND w.thread_id IN (' . $in['sql'] . ')', $in['params']);
    foreach ($rows as $r) {
        $cache['rows'][(int)$r['thread_id']] = $r;
    }
}

// Кто рассматривает тему из списка. Без запроса на каждую строку: при первой теме раздела
// грузятся все взятые и ещё не решённые темы этого раздела (их немного - это и есть очередь).
function wf_list_claim(array $t)
{
    $cache = &wf_list_cache();
    $tid = (int)$t['id'];
    if (array_key_exists($tid, $cache['rows'])) {
        return $cache['rows'][$tid];
    }
    $nid = (int)$t['node_id'];
    $c = wf_cfg($nid);
    if ($c && !isset($cache['nodes'][$nid])) {
        $cache['nodes'][$nid] = true;
        $in = db_in($c['finals'] ?: [0], 'f');
        $rows = db_all('SELECT w.thread_id, w.claimed_by, w.claimed_at, u.username, u.avatar, g.color AS group_color
                        FROM wf_threads w
                        JOIN threads t ON t.id = w.thread_id
                        LEFT JOIN users u ON u.id = w.claimed_by
                        LEFT JOIN user_groups g ON g.id = u.group_id
                        WHERE t.node_id = :n AND w.claimed_by IS NOT NULL AND t.is_deleted = 0 AND t.is_locked = 0
                          AND (t.prefix_id IS NULL OR t.prefix_id NOT IN (' . $in['sql'] . '))', array_merge(['n' => $nid], $in['params']));
        foreach ($rows as $r) {
            if (!array_key_exists((int)$r['thread_id'], $cache['rows']) || $cache['rows'][(int)$r['thread_id']] === null) {
                $cache['rows'][(int)$r['thread_id']] = $r;
            }
        }
    }
    if (!array_key_exists($tid, $cache['rows'])) {
        $cache['rows'][$tid] = null;
    }
    return $cache['rows'][$tid];
}

// Запись в историю (статистика). $userId = 0 - система.
function wf_event(array $thread, $action, array $extra = [], $userId = null)
{
    db_insert('wf_events', [
        'thread_id' => (int)$thread['id'],
        'node_id' => (int)$thread['node_id'],
        'user_id' => $userId === null ? uid() : (int)$userId,
        'action' => $action,
        'prefix_id' => !empty($extra['prefix_id']) ? (int)$extra['prefix_id'] : null,
        'target_user_id' => !empty($extra['target_user_id']) ? (int)$extra['target_user_id'] : null,
        'seconds' => isset($extra['seconds']) ? max(0, (int)$extra['seconds']) : null,
        'overdue' => !empty($extra['overdue']) ? 1 : 0,
        'created_at' => now(),
    ]);
}

function wf_prefix_title($prefixId)
{
    $all = prefixes_all();
    return $prefixId && isset($all[(int)$prefixId]) ? $all[(int)$prefixId]['title'] : 'без префикса';
}

// Сменить префикс темы (как модератор в меню темы) и обновить ожидание доказательств
function wf_set_prefix(array $thread, array $node, $prefixId)
{
    $tid = (int)$thread['id'];
    $prefixId = (int)$prefixId;
    if ((int)$thread['prefix_id'] === $prefixId) {
        return;
    }
    db_update('threads', ['prefix_id' => $prefixId ?: null], 'id = :id', ['id' => $tid]);
    mod_log('thread_prefix', 'thread', $tid, $thread['title'] . ' -> ' . wf_prefix_title($prefixId));
    wf_after_prefix($thread, $prefixId);
    $GLOBALS['wf_quiet'] = true;
    try {
        hook_fire('thread_moderated', $thread, $node, 'prefix');
    } finally {
        $GLOBALS['wf_quiet'] = false;
    }
}

// После смены префикса: «Ожидание доказательств» запускает отсчёт, другой префикс его снимает
function wf_after_prefix(array $thread, $newPrefixId)
{
    $ev = setting_int('wf_evidence_prefix_id');
    if (!$ev) {
        return;
    }
    $tid = (int)$thread['id'];
    if ((int)$newPrefixId === $ev && (int)$thread['prefix_id'] !== $ev) {
        wf_state_row_ensure($tid);
        db_update('wf_threads', ['evidence_at' => now(), 'evidence_by' => uid() ?: null, 'updated_at' => now()], 'thread_id = :t', ['t' => $tid]);
    } elseif ((int)$newPrefixId !== $ev) {
        db_exec('UPDATE wf_threads SET evidence_at = NULL, evidence_by = NULL WHERE thread_id = :t AND evidence_at IS NOT NULL', ['t' => $tid]);
    }
}

// Перенести тему в другой раздел (как модератор, но без оповещения: автор получит одно - о решении)
function wf_move_thread(array $thread, array $node, array $target)
{
    $tid = (int)$thread['id'];
    db_update('threads', ['node_id' => (int)$target['id']], 'id = :id', ['id' => $tid]);
    node_rebuild($node['id']);
    node_rebuild($target['id']);
    mod_log('thread_move', 'thread', $tid, $thread['title'] . ': ' . $node['title'] . ' -> ' . $target['title']);
    $GLOBALS['wf_quiet'] = true;
    try {
        hook_fire('thread_moderated', $thread, $node, 'move');
    } finally {
        $GLOBALS['wf_quiet'] = false;
    }
}

// Раздел-архив для раздела или null
function wf_archive_node($c, $node)
{
    if (!$c || empty($c['archive_node_id'])) {
        return null;
    }
    $a = node_get($c['archive_node_id']);
    if (!$a || $a['type'] !== 'forum' || (int)$a['id'] === (int)$node['id']) {
        return null;
    }
    return $a;
}

// Префикс «На рассмотрении» при взятии: только если тема ещё не решена и не ждёт доказательств
function wf_claim_prefix(array $thread, array $node, $c)
{
    $p = (int)$c['claim_prefix_id'];
    $cur = (int)$thread['prefix_id'];
    if (!$p || $cur === $p || wf_is_final($c, $cur) || $cur === setting_int('wf_evidence_prefix_id')) {
        return;
    }
    $allowed = node_prefixes($node['id']);
    if (isset($allowed[$p])) {
        wf_set_prefix($thread, $node, $p);
    }
}

// ---------- Действия ----------

// Взять тему на рассмотрение. Возвращает [ok, сообщение].
function wf_claim(array $thread, array $node)
{
    $c = wf_cfg($node['id']);
    if (!$c || !wf_can_process($node)) {
        return [false, 'Вы не можете рассматривать темы в этом разделе.'];
    }
    if ($thread['is_deleted']) {
        return [false, 'Тема удалена.'];
    }
    if (wf_recused($thread)) {
        return [false, wf_recused_text()];
    }
    $tid = (int)$thread['id'];
    wf_state_row_ensure($tid);
    $n = db_exec('UPDATE wf_threads SET claimed_by = :u, claimed_at = :t, updated_at = :t2 WHERE thread_id = :id AND claimed_by IS NULL',
        ['u' => uid(), 't' => now(), 't2' => now(), 'id' => $tid]);
    if (!$n) {
        $st = wf_thread_state($tid);
        if ($st && (int)$st['claimed_by'] === uid()) {
            return [false, 'Вы уже рассматриваете эту тему.'];
        }
        return [false, 'Тему уже взял(а) ' . ($st['claimed_name'] ?? 'другой сотрудник') . '.'];
    }
    wf_claim_prefix($thread, $node, $c);
    mod_log('wf_claim', 'thread', $tid, $thread['title']);
    wf_event($thread, 'claim');
    alert_add($thread['user_id'], 'wf_claim', uid(), $tid, null, wf_kind($c, 1));
    return [true, 'Тема взята на рассмотрение. Не забудьте вынести решение до срока.'];
}

// Отказаться от темы (сам взявший или старший)
function wf_unclaim(array $thread, array $node)
{
    if (!wf_can_process($node)) {
        return [false, 'Вы не можете рассматривать темы в этом разделе.'];
    }
    $tid = (int)$thread['id'];
    $st = wf_thread_state($tid);
    if (!$st || !$st['claimed_by']) {
        return [false, 'Тему пока никто не взял.'];
    }
    $was = (int)$st['claimed_by'];
    if ($was !== uid() && !wf_is_supervisor($node)) {
        return [false, 'Снять с рассмотрения чужую тему может только старший администратор раздела.'];
    }
    db_exec('UPDATE wf_threads SET claimed_by = NULL, claimed_at = NULL, updated_at = :t WHERE thread_id = :id AND claimed_by = :u',
        ['t' => now(), 'id' => $tid, 'u' => $was]);
    mod_log('wf_unclaim', 'thread', $tid, $thread['title'] . ($was !== uid() ? ' (' . $st['claimed_name'] . ')' : ''));
    wf_event($thread, 'unclaim', ['target_user_id' => $was]);
    if ($was !== uid()) {
        alert_add($was, 'wf_unclaim', uid(), $tid);
    }
    return [true, $was === uid() ? 'Вы больше не рассматриваете эту тему. Её может взять другой сотрудник.' : 'Тема снята с рассмотрения.'];
}

// Передать тему другому сотруднику
function wf_transfer(array $thread, array $node, $toUserId)
{
    $c = wf_cfg($node['id']);
    if (!$c || !wf_can_process($node)) {
        return [false, 'Вы не можете рассматривать темы в этом разделе.'];
    }
    $tid = (int)$thread['id'];
    $st = wf_thread_state($tid);
    $was = $st ? (int)$st['claimed_by'] : 0;
    if ($was && $was !== uid() && !wf_is_supervisor($node)) {
        return [false, 'Передать чужую тему может только старший администратор раздела.'];
    }
    $to = user_by_id((int)$toUserId);
    if (!$to || !wf_user_can_process($to, $node)) {
        return [false, 'Этот пользователь не может рассматривать темы в разделе.'];
    }
    if (wf_recused($thread, $to)) {
        return [false, $to['username'] . ' упомянут(а) в этой теме, передайте её другому сотруднику.'];
    }
    if ((int)$to['id'] === $was) {
        return [false, 'Тема уже у этого сотрудника.'];
    }
    wf_state_row_ensure($tid);
    db_update('wf_threads', ['claimed_by' => (int)$to['id'], 'claimed_at' => now(), 'updated_at' => now()], 'thread_id = :t', ['t' => $tid]);
    wf_claim_prefix($thread, $node, $c);
    mod_log('wf_transfer', 'thread', $tid, $thread['title'] . ' -> ' . $to['username']);
    wf_event($thread, 'transfer', ['target_user_id' => (int)$to['id']]);
    alert_add((int)$to['id'], 'wf_transfer', uid(), $tid);
    if ($was && $was !== uid() && $was !== (int)$to['id']) {
        alert_add($was, 'wf_unclaim', uid(), $tid);
    }
    return [true, 'Тема передана: ' . $to['username'] . '.'];
}

// Может ли текущий пользователь вынести решение (тема не взята, взята им, или он старший)
function wf_can_verdict(array $node, $state)
{
    if (!wf_can_process($node)) {
        return false;
    }
    $by = $state ? (int)$state['claimed_by'] : 0;
    return !$by || $by === uid() || wf_is_supervisor($node);
}

// Готовые ответы для раздела (активные), по порядку
function wf_macros_for($nodeId)
{
    static $cache = [];
    $k = wf_ckey('all');
    if (!isset($cache[$k])) {
        $cache = [$k => wf_ready() ? db_all('SELECT * FROM wf_macros WHERE is_active = 1 ORDER BY display_order, id') : []];
    }
    $out = [];
    foreach ($cache[$k] as $m) {
        $ids = wf_ids($m['node_ids']);
        if (!$ids || in_array((int)$nodeId, $ids, true)) {
            $out[(int)$m['id']] = $m;
        }
    }
    return $out;
}

// Подстановки в готовом ответе
function wf_fill($text, array $thread, $staff)
{
    $author = user_by_id($thread['user_id']);
    return strtr((string)$text, [
        '{author}' => $author ? $author['username'] : 'автор',
        '{staff}' => $staff ? $staff['username'] : 'Администрация',
        '{thread}' => $thread['title'],
        '{date}' => date('d.m.Y'),
    ]);
}

// Решение в один клик: ответ автору, статус, закрытие, перенос в архив, оповещение.
// $in: prefix_id (0 - не менять), body, macro_id, lock, archive. Возвращает [ok, сообщение, адрес].
function wf_verdict(array $thread, array $node, array $in)
{
    $tid = (int)$thread['id'];
    $back = thread_url($tid);
    $c = wf_cfg($node['id']);
    if (!$c || !wf_can_process($node)) {
        return [false, 'Вы не можете выносить решения в этом разделе.', $back];
    }
    if ($thread['is_deleted']) {
        return [false, 'Тема удалена.', $back];
    }
    if (wf_recused($thread)) {
        return [false, wf_recused_text(), $back];
    }
    $st = wf_thread_state($tid);
    if (!wf_can_verdict($node, $st)) {
        return [false, 'Тему рассматривает ' . $st['claimed_name'] . '. Решение выносит он(а) или старший администратор.', $back];
    }
    $prefixId = (int)($in['prefix_id'] ?? 0);
    $allowed = node_prefixes($node['id']);
    if ($prefixId && !isset($allowed[$prefixId])) {
        return [false, 'Этот статус нельзя поставить в разделе.', $back];
    }
    $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($in['body'] ?? '')));
    $macroId = (int)($in['macro_id'] ?? 0);
    if ($macroId) {
        $macros = wf_macros_for($node['id']);
        if (!isset($macros[$macroId])) {
            return [false, 'Готовый ответ не найден или не подходит для раздела.', $back];
        }
        if ($body === '') {
            $body = $macros[$macroId]['body'];
        }
    }
    $body = wf_fill($body, $thread, user());
    $err = fp_body_error($body);
    if ($err) {
        return [false, $body === '' ? 'Напишите ответ автору или выберите готовый ответ.' : $err, $back];
    }
    $archive = null;
    if (!empty($in['archive'])) {
        $archive = wf_archive_node($c, $node);
        if (!$archive) {
            return [false, 'Для раздела не выбран архив. Его можно указать в админ-панели: Рассмотрение.', $back];
        }
    }

    // 1. Ответ автору от имени сотрудника (счётчики, отслеживание, упоминания - как у обычного ответа)
    $pid = fp_create_post($thread, $node, $body);
    // Автору хватит одного оповещения - о решении
    db_exec("DELETE FROM alerts WHERE user_id = :u AND type = 'reply' AND post_id = :p", ['u' => (int)$thread['user_id'], 'p' => $pid]);

    // 2. Статус, закрытие, архив
    $thread = thread_get($tid);
    if ($prefixId && $prefixId !== (int)$thread['prefix_id']) {
        wf_set_prefix($thread, $node, $prefixId);
        $thread = thread_get($tid);
    }
    $final = $prefixId ? $prefixId : (int)$thread['prefix_id'];
    if (!empty($in['lock']) && !$thread['is_locked']) {
        fp_mod_thread($thread, $node, 'lock');
        $thread = thread_get($tid);
    }
    if ($archive) {
        wf_move_thread($thread, $node, $archive);
    }

    // 3. Ход рассмотрения и история. Окончательный статус или закрытая тема - решение,
    //    иначе промежуточный ответ (например, запрос доказательств): срок продолжает идти.
    $now = now();
    $done = wf_is_final($c, $final) || (int)$thread['is_locked'] === 1;
    wf_state_row_ensure($tid);
    if ($done) {
        db_exec('UPDATE wf_threads SET claimed_at = COALESCE(claimed_at, :t1), claimed_by = COALESCE(claimed_by, :u1),
                    verdict_by = :u2, verdict_at = :t2, verdict_prefix_id = :p, verdict_post_id = :pid, updated_at = :t3
                 WHERE thread_id = :id', ['t1' => $now, 'u1' => uid(), 'u2' => uid(), 't2' => $now, 'p' => $final ?: null, 'pid' => $pid, 't3' => $now, 'id' => $tid]);
    } else {
        db_exec('UPDATE wf_threads SET claimed_at = COALESCE(claimed_at, :t1), claimed_by = COALESCE(claimed_by, :u1), updated_at = :t2 WHERE thread_id = :id',
            ['t1' => $now, 'u1' => uid(), 't2' => $now, 'id' => $tid]);
    }
    $dl = wf_deadline_ts($thread, $c);
    wf_event($thread, $done ? 'verdict' : 'status', [
        'prefix_id' => $final,
        'seconds' => time() - strtotime($thread['created_at']),
        'overdue' => $dl && time() > $dl,
    ]);
    $ptitle = $final ? wf_prefix_title($final) : '';
    mod_log($done ? 'wf_verdict' : 'wf_status', 'thread', $tid, $thread['title'] . ($ptitle !== '' ? ': ' . $ptitle : ''));
    alert_add($thread['user_id'], $done ? 'wf_verdict' : 'wf_status', uid(), $tid, $pid, $ptitle !== '' ? $ptitle : null);

    $msg = $done ? 'Решение вынесено' . ($ptitle !== '' ? ': ' . $ptitle : '') . '.' : 'Ответ отправлен' . ($ptitle !== '' ? ', статус: ' . $ptitle : '') . '.';
    if (!empty($in['lock'])) {
        $msg .= ' Тема закрыта.';
    }
    if ($archive) {
        $msg .= ' Перенесена в «' . $archive['title'] . '».';
    }
    return [true, $msg, post_url($pid)];
}

// Голос за предложение: 1 - за, -1 - против, повторный голос снимает. Возвращает [ok, ошибка, [up, down, my]].
function wf_vote(array $thread, array $node, $vote)
{
    $c = wf_cfg($node['id']);
    $vote = (int)$vote > 0 ? 1 : -1;
    if (!$c || !(int)$c['voting']) {
        return [false, 'В этом разделе нет голосования.', null];
    }
    if (!is_logged()) {
        return [false, 'Войдите, чтобы голосовать.', null];
    }
    if (is_banned() || !node_can_reply($node)) {
        return [false, 'Вы не можете голосовать в этом разделе.', null];
    }
    if ($thread['is_deleted'] || $thread['is_locked']) {
        return [false, 'Голосование закрыто: тема закрыта.', null];
    }
    if ((int)$thread['user_id'] === uid()) {
        return [false, 'Нельзя голосовать за своё предложение.', null];
    }
    if (!rate_ok('wf_vote', 30, 600)) {
        return [false, 'Слишком часто. Подождите несколько минут.', null];
    }
    rate_hit('wf_vote');
    $tid = (int)$thread['id'];
    $cur = (int)db_val('SELECT vote FROM wf_votes WHERE thread_id = :t AND user_id = :u', ['t' => $tid, 'u' => uid()]);
    if ($cur === $vote) {
        db_exec('DELETE FROM wf_votes WHERE thread_id = :t AND user_id = :u', ['t' => $tid, 'u' => uid()]);
        $my = 0;
    } elseif ($cur) {
        db_exec('UPDATE wf_votes SET vote = :v, created_at = :c WHERE thread_id = :t AND user_id = :u', ['v' => $vote, 'c' => now(), 't' => $tid, 'u' => uid()]);
        $my = $vote;
    } else {
        db_exec('INSERT IGNORE INTO wf_votes (thread_id, user_id, vote, created_at) VALUES (:t, :u, :v, :c)', ['t' => $tid, 'u' => uid(), 'v' => $vote, 'c' => now()]);
        $my = $vote;
    }
    db_exec('UPDATE threads SET wf_up = (SELECT COUNT(*) FROM wf_votes WHERE thread_id = :a AND vote = 1),
                                wf_down = (SELECT COUNT(*) FROM wf_votes WHERE thread_id = :b AND vote = -1) WHERE id = :c',
        ['a' => $tid, 'b' => $tid, 'c' => $tid]);
    $r = db_one('SELECT wf_up, wf_down FROM threads WHERE id = :id', ['id' => $tid]);
    return [true, '', ['up' => (int)$r['wf_up'], 'down' => (int)$r['wf_down'], 'my' => $my]];
}

// ---------- Очередь ----------

// Сколько тем ждут внимания: не взятые или просроченные (для значка в шапке), кэш на запрос
function wf_attention_count()
{
    static $cache = [];
    $k = wf_ckey(uid());
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $ids = wf_processable_node_ids();
    if (!$ids) {
        return $cache[$k] = 0;
    }
    $in = db_in($ids, 'n');
    return $cache[$k] = (int)db_val('SELECT COUNT(*) FROM threads t
        JOIN wf_nodes n ON n.node_id = t.node_id
        LEFT JOIN wf_threads w ON w.thread_id = t.id
        WHERE t.node_id IN (' . $in['sql'] . ') AND t.is_deleted = 0 AND t.is_locked = 0
          AND (t.prefix_id IS NULL OR FIND_IN_SET(t.prefix_id, n.final_prefixes) = 0)
          AND (w.claimed_by IS NULL OR (n.deadline_hours > 0 AND t.created_at < DATE_SUB(:now, INTERVAL n.deadline_hours HOUR)))',
        array_merge($in['params'], ['now' => now()]));
}

// ---------- Фоновые задачи ----------

// Выполнить $fn от имени пользователя (фоновые задачи пишут ответы и журнал от его имени)
function wf_as_user($u, $fn)
{
    $prev = $GLOBALS['wrp_user'] ?? null;
    $GLOBALS['wrp_user'] = $u;
    try {
        return $fn();
    } finally {
        $GLOBALS['wrp_user'] = $prev;
    }
}

// От чьего имени пишет система: настройка, иначе $preferId (кто запросил доказательства), иначе главный администратор
function wf_bot_user($preferId = 0)
{
    $id = setting_int('wf_bot_user_id');
    $u = $id ? user_by_id($id) : null;
    if ((!$u || !empty($u['is_banned'])) && $preferId) {
        $u = user_by_id((int)$preferId);
    }
    if (!$u || !empty($u['is_banned'])) {
        $id = (int)db_val('SELECT u.id FROM users u JOIN user_groups g ON g.id = u.group_id WHERE g.can_admin = 1 AND u.is_banned = 0 ORDER BY g.level DESC, u.id ASC LIMIT 1');
        $u = $id ? user_by_id($id) : null;
    }
    return $u && empty($u['is_banned']) ? $u : null;
}

// Условие «только эти темы» для фоновых задач (тесты передают свои темы)
function wf_only_sql($only)
{
    if ($only === null) {
        return ['sql' => '', 'params' => []];
    }
    $in = db_in($only ?: [0], 'only');
    return ['sql' => ' AND t.id IN (' . $in['sql'] . ')', 'params' => $in['params']];
}

// Темы «Ожидание доказательств», где автор молчит дольше срока: отказ, закрытие, ответ системы.
// $only - ограничить списком id тем (для тестов). Возвращает число закрытых тем.
function wf_cron_evidence($only = null)
{
    if (!wf_ready()) {
        return 0;
    }
    $ev = setting_int('wf_evidence_prefix_id');
    if (!$ev) {
        return 0;
    }
    $hours = max(1, setting_int('wf_evidence_hours'));
    $cut = date('Y-m-d H:i:s', time() - $hours * 3600);
    $o = wf_only_sql($only);
    $rows = db_all('SELECT t.*, w.evidence_at, w.evidence_by, w.claimed_by AS wf_claimed_by
        FROM threads t
        JOIN wf_nodes n ON n.node_id = t.node_id AND n.enabled = 1
        LEFT JOIN wf_threads w ON w.thread_id = t.id
        WHERE t.prefix_id = :p AND t.is_deleted = 0' . $o['sql'] . '
          AND ((w.evidence_at IS NOT NULL AND w.evidence_at < :c1
                AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.thread_id = t.id AND p.user_id = t.user_id AND p.is_deleted = 0 AND p.created_at > w.evidence_at))
            OR (w.evidence_at IS NULL AND t.last_post_at < :c2 AND t.last_post_user_id <> t.user_id))
        ORDER BY t.id LIMIT 50', array_merge(['p' => $ev, 'c1' => $cut, 'c2' => $cut], $o['params']));
    $done = 0;
    foreach ($rows as $t) {
        $node = node_get($t['node_id']);
        $bot = wf_bot_user((int)($t['evidence_by'] ?: $t['wf_claimed_by']));
        if (!$node || !$bot) {
            continue;
        }
        wf_as_user($bot, function () use ($t, $node, $hours, $bot) {
            wf_autoclose_one($t, $node, $hours, $bot);
        });
        $done++;
    }
    return $done;
}

function wf_autoclose_one(array $t, array $node, $hours, array $bot)
{
    $tid = (int)$t['id'];
    $thread = thread_get($tid);
    $text = wf_fill(str_replace('{hours}', (string)$hours, (string)setting('wf_evidence_text')), $thread, $bot);
    if (fp_body_error($text)) {
        $text = 'Доказательства не были предоставлены вовремя. Тема закрыта автоматически.';
    }
    $pid = fp_create_post($thread, $node, $text);
    db_exec("DELETE FROM alerts WHERE user_id = :u AND type = 'reply' AND post_id = :p", ['u' => (int)$thread['user_id'], 'p' => $pid]);
    $refuse = setting_int('wf_evidence_refuse_prefix_id');
    $thread = thread_get($tid);
    if ($refuse) {
        wf_set_prefix($thread, $node, $refuse);
        $thread = thread_get($tid);
    }
    if (!$thread['is_locked']) {
        fp_mod_thread($thread, $node, 'lock');
    }
    wf_state_row_ensure($tid);
    db_update('wf_threads', [
        'verdict_by' => (int)$bot['id'], 'verdict_at' => now(), 'verdict_prefix_id' => $refuse ?: null, 'verdict_post_id' => $pid,
        'evidence_at' => null, 'evidence_by' => null, 'updated_at' => now(),
    ], 'thread_id = :t', ['t' => $tid]);
    wf_event($thread, 'autoclose', ['prefix_id' => $refuse], 0);
    mod_log('wf_autoclose', 'thread', $tid, $thread['title']);
    alert_add($thread['user_id'], 'wf_autoclose', null, $tid, $pid, $refuse ? wf_prefix_title($refuse) : null);
}

// Решённые темы без активности N дней переезжают в архив раздела и закрываются.
// $only - ограничить списком id тем (для тестов). Возвращает число перенесённых тем.
function wf_cron_archive($only = null)
{
    if (!wf_ready()) {
        return 0;
    }
    $o = wf_only_sql($only);
    $rows = db_all('SELECT t.* FROM threads t
        JOIN wf_nodes n ON n.node_id = t.node_id
        WHERE n.enabled = 1 AND n.archive_node_id > 0 AND n.archive_days > 0
          AND t.is_deleted = 0 AND t.is_pinned = 0 AND t.prefix_id IS NOT NULL AND FIND_IN_SET(t.prefix_id, n.final_prefixes) > 0
          AND t.last_post_at < DATE_SUB(:now, INTERVAL n.archive_days DAY)' . $o['sql'] . '
        ORDER BY t.last_post_at LIMIT 100', array_merge(['now' => now()], $o['params']));
    if (!$rows) {
        return 0;
    }
    $bot = wf_bot_user(0);
    if (!$bot) {
        return 0;
    }
    return (int)wf_as_user($bot, function () use ($rows) {
        $done = 0;
        foreach ($rows as $t) {
            $node = node_get($t['node_id']);
            $c = $node ? wf_cfg($node['id']) : null;
            $target = wf_archive_node($c, $node);
            if (!$target) {
                continue;
            }
            if (!$t['is_locked']) {
                fp_mod_thread($t, $node, 'lock');
                $t = thread_get($t['id']);
            }
            wf_move_thread($t, $node, $target);
            wf_event($t, 'archive', ['prefix_id' => (int)$t['prefix_id']], 0);
            $done++;
        }
        return $done;
    });
}

cron_register('wf_evidence', 600, 'wf_cron_evidence');
cron_register('wf_archive', 3600, 'wf_cron_archive');

// ---------- Синхронизация с модерацией ядра ----------

// Модератор сменил префикс через меню темы: отсчёт доказательств и учёт решения в статистике
hook_add('thread_moderated', function ($thread, $node, $do) {
    if (!empty($GLOBALS['wf_quiet']) || $do !== 'prefix' || !wf_ready()) {
        return;
    }
    try {
        $c = wf_cfg($node['id']);
        if (!$c) {
            return;
        }
        $new = (int)db_val('SELECT prefix_id FROM threads WHERE id = :id', ['id' => (int)$thread['id']]);
        if ($new === (int)$thread['prefix_id']) {
            return;
        }
        wf_after_prefix($thread, $new);
        if (wf_is_final($c, $new) && wf_can_process($node)) {
            $tid = (int)$thread['id'];
            wf_state_row_ensure($tid);
            db_exec('UPDATE wf_threads SET claimed_at = COALESCE(claimed_at, :t1), claimed_by = COALESCE(claimed_by, :u1),
                        verdict_by = :u2, verdict_at = :t2, verdict_prefix_id = :p, updated_at = :t3 WHERE thread_id = :id',
                ['t1' => now(), 'u1' => uid(), 'u2' => uid(), 't2' => now(), 'p' => $new, 't3' => now(), 'id' => $tid]);
            $dl = wf_deadline_ts($thread, $c);
            wf_event($thread, 'verdict', ['prefix_id' => $new, 'seconds' => time() - strtotime($thread['created_at']), 'overdue' => $dl && time() > $dl]);
        }
    } catch (Exception $e) {
        error_log('[workflow] ' . $e);
    }
});

// ---------- Оповещения ----------

hook_add('alert_text', function ($text, $a, $actor, $title) {
    switch ($a['type']) {
        case 'wf_claim':
            return $actor . ' взял(а) на рассмотрение ' . e($a['extra'] ?: 'вашу тему') . ' ' . $title;
        case 'wf_transfer':
            return $actor . ' передал(а) вам на рассмотрение тему ' . $title;
        case 'wf_unclaim':
            return $actor . ' снял(а) вас с рассмотрения темы ' . $title;
        case 'wf_verdict':
            return $actor . ' вынес(ла) решение по теме ' . $title . (!empty($a['extra']) ? ': <b>' . e($a['extra']) . '</b>' : '');
        case 'wf_status':
            return $actor . ' ответил(а) по вашей теме ' . $title . (!empty($a['extra']) ? ', статус: <b>' . e($a['extra']) . '</b>' : '');
        case 'wf_autoclose':
            return 'Тема ' . $title . ' закрыта автоматически: доказательства не предоставлены';
    }
    return $text;
});

hook_add('alert_link', function ($link, $a) {
    if (in_array($a['type'], ['wf_claim', 'wf_transfer', 'wf_unclaim'], true) && !empty($a['thread_id'])) {
        return thread_url($a['thread_id']);
    }
    return $link;
});

// ---------- Журнал модерации ----------

hook_add('modlog_labels', function ($labels) {
    $labels['wf_claim'] = 'Взята на рассмотрение';
    $labels['wf_unclaim'] = 'Снята с рассмотрения';
    $labels['wf_transfer'] = 'Передана другому сотруднику';
    $labels['wf_verdict'] = 'Вынесено решение';
    $labels['wf_status'] = 'Ответ по обращению';
    $labels['wf_autoclose'] = 'Автозакрытие без доказательств';
    $labels['wf_settings'] = 'Настройки рассмотрения';
    $labels['wf_staff'] = 'Ответственные за раздел';
    $labels['wf_macro'] = 'Готовый ответ';
    return $labels;
});

hook_add('modlog_target', function ($r) {
    if ($r['target_type'] === 'wf_macro') {
        return '<span class="muted small">Готовый ответ</span><br><a href="' . e(url('/admin/macros.php', ['edit' => (int)$r['target_id']])) . '">#' . (int)$r['target_id'] . '</a>';
    }
    return null;
});

// ---------- Админ-панель ----------

hook_add('admin_menu', function ($items) {
    $out = [];
    foreach ($items as $k => $v) {
        $out[$k] = $v;
        if ($k === 'prefixes') {
            $out['workflow'] = ['Рассмотрение', 'clock', '/admin/workflow.php'];
            $out['macros'] = ['Готовые ответы', 'quote', '/admin/macros.php'];
        }
    }
    if (!isset($out['workflow'])) {
        $out['workflow'] = ['Рассмотрение', 'clock', '/admin/workflow.php'];
        $out['macros'] = ['Готовые ответы', 'quote', '/admin/macros.php'];
    }
    return $out;
});

hook_add('admin_tiles', function ($tiles) {
    if (!wf_ready()) {
        return $tiles;
    }
    try {
        $n = (int)db_val('SELECT COUNT(*) FROM threads t JOIN wf_nodes n ON n.node_id = t.node_id
            WHERE n.enabled = 1 AND t.is_deleted = 0 AND t.is_locked = 0 AND (t.prefix_id IS NULL OR FIND_IN_SET(t.prefix_id, n.final_prefixes) = 0)');
        $tiles[] = ['В очереди рассмотрения', $n, 'clock', '#f59e0b', url('/forum/queue.php')];
    } catch (Exception $e) {
        // база не обновлена
    }
    return $tiles;
});
