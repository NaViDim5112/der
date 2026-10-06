<?php
// Модуль «Модерация»: жалобы пользователей и центр жалоб для команды.
// Несколько жалоб на один материал собираются в одну открытую жалобу (mod_reports),
// каждый пожаловавшийся - отдельная строка mod_report_items.

// Почему текущий пользователь не может пожаловаться на материал ('' - может)
function mdr_report_denied($c)
{
    if (!is_logged()) {
        return 'Войдите, чтобы отправить жалобу.';
    }
    if (is_banned()) {
        return 'Пока аккаунт заблокирован, жалобы отправлять нельзя.';
    }
    if (!$c || $c['deleted']) {
        return 'Материал не найден или уже удалён.';
    }
    if ($c['type'] === 'message' && empty($c['is_member'])) {
        return 'Пожаловаться можно только на сообщение из своей переписки.';
    }
    if (!$c['visible']) {
        return 'Материал не найден или недоступен.';
    }
    if ((int)$c['user_id'] === uid()) {
        return $c['type'] === 'user' ? 'Нельзя пожаловаться на самого себя.' : 'Нельзя пожаловаться на своё сообщение.';
    }
    if (empty($c['author']['id'])) {
        return 'Автор материала удалён.';
    }
    return '';
}

// Открытая жалоба на материал или null
function mdr_report_open_for($type, $id)
{
    return db_one("SELECT * FROM mod_reports WHERE content_type = :t AND content_id = :c AND status = 'open' ORDER BY id DESC LIMIT 1",
        ['t' => (string)$type, 'c' => (int)$id]);
}

// Уже есть моя жалоба на этот материал, которая ещё рассматривается
function mdr_report_already($type, $id, $userId)
{
    return (bool)db_val("SELECT 1 FROM mod_reports r JOIN mod_report_items i ON i.report_id = r.id
        WHERE r.content_type = :t AND r.content_id = :c AND r.status = 'open' AND i.user_id = :u LIMIT 1",
        ['t' => (string)$type, 'c' => (int)$id, 'u' => (int)$userId]);
}

// Отправить жалобу. Возвращает [ok, сообщение, id жалобы].
function mdr_report_submit($type, $id, $reason, $comment)
{
    $reasons = mdr_report_reasons();
    $comment = trim(str_replace(["\r\n", "\r"], "\n", (string)$comment));
    $c = mdr_content_load($type, $id);
    $err = mdr_report_denied($c);
    if ($err !== '') {
        return [false, $err, 0];
    }
    if (!isset($reasons[$reason])) {
        return [false, 'Выберите причину жалобы.', 0];
    }
    if ($reason === 'other' && mb_strlen($comment) < 5) {
        return [false, 'Опишите, что не так (хотя бы пару слов).', 0];
    }
    if (mb_strlen($comment) > 1000) {
        return [false, 'Комментарий слишком длинный (не больше 1000 символов).', 0];
    }
    if (mdr_report_already($c['type'], $c['id'], uid())) {
        return [false, 'Вы уже пожаловались на это. Жалоба ещё рассматривается.', 0];
    }
    $limit = max(1, setting_int('mod_report_limit'));
    if (!is_staff() && !rate_ok('mdr_report', $limit, 3600)) {
        return [false, 'Слишком много жалоб за час. Попробуйте позже.', 0];
    }
    $now = now();
    $report = mdr_report_open_for($c['type'], $c['id']);
    if ($report) {
        $rid = (int)$report['id'];
    } else {
        $rid = db_insert('mod_reports', [
            'content_type' => $c['type'],
            'content_id' => $c['id'],
            'content_user_id' => $c['user_id'] ?: null,
            'content_snapshot' => $c['body'],
            'status' => 'open',
            'reports_count' => 0,
            'last_report_at' => $now,
            'created_at' => $now,
        ]);
    }
    db_exec('INSERT IGNORE INTO mod_report_items (report_id, user_id, reason, comment, created_at) VALUES (:r, :u, :re, :c, :t)',
        ['r' => $rid, 'u' => uid(), 're' => $reason, 'c' => $comment !== '' ? $comment : null, 't' => $now]);
    db_exec('UPDATE mod_reports SET reports_count = (SELECT COUNT(*) FROM mod_report_items WHERE report_id = :a), last_report_at = :t WHERE id = :b',
        ['a' => $rid, 't' => $now, 'b' => $rid]);
    if (!is_staff()) {
        rate_hit('mdr_report');
    }
    return [true, 'Жалоба отправлена. Спасибо! Модераторы её рассмотрят, о решении придёт оповещение.', $rid];
}

// ---------- Центр жалоб ----------

function mdr_report_get($id)
{
    return db_one('SELECT * FROM mod_reports WHERE id = :id', ['id' => (int)$id]);
}

// Условие списка по вкладке: [sql, params]
function mdr_reports_where($tab)
{
    if ($tab === 'mine') {
        return ["r.status = 'open' AND r.assigned_to = :me", ['me' => uid()]];
    }
    if ($tab === 'closed') {
        return ["r.status <> 'open'", []];
    }
    return ["r.status = 'open'", []];
}

function mdr_reports_count($tab)
{
    list($where, $params) = mdr_reports_where($tab);
    return (int)db_val('SELECT COUNT(*) FROM mod_reports r WHERE ' . $where, $params);
}

function mdr_reports_list($tab, $limit, $offset)
{
    list($where, $params) = mdr_reports_where($tab);
    $order = $tab === 'closed' ? 'r.resolved_at DESC, r.id DESC' : 'r.last_report_at DESC, r.id DESC';
    return db_all('SELECT r.* FROM mod_reports r WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT :lim OFFSET :off',
        array_merge($params, ['lim' => (int)$limit, 'off' => (int)$offset]));
}

// Кто пожаловался: [report_id => [строки с именами]]
function mdr_report_items(array $reportIds)
{
    $reportIds = array_values(array_unique(array_map('intval', $reportIds)));
    if (!$reportIds) {
        return [];
    }
    $in = db_in($reportIds, 'r');
    $out = [];
    foreach (db_all('SELECT i.*, u.username, u.avatar, g.color AS group_color FROM mod_report_items i
        LEFT JOIN users u ON u.id = i.user_id LEFT JOIN user_groups g ON g.id = u.group_id
        WHERE i.report_id IN (' . $in['sql'] . ') ORDER BY i.id', $in['params']) as $r) {
        $out[(int)$r['report_id']][] = $r;
    }
    return $out;
}

// Причины жалобы, которые встречаются чаще (для подсказки вида предупреждения)
function mdr_report_main_reason(array $items)
{
    $count = [];
    foreach ($items as $i) {
        $count[$i['reason']] = ($count[$i['reason']] ?? 0) + 1;
    }
    arsort($count);
    return $count ? (string)array_key_first($count) : '';
}

// Взять жалобу себе или отпустить
function mdr_report_claim(array $report, $on)
{
    if ($report['status'] !== 'open') {
        return [false, 'Жалоба уже закрыта.'];
    }
    if ($on) {
        db_update('mod_reports', ['assigned_to' => uid(), 'assigned_at' => now()], 'id = :id', ['id' => (int)$report['id']]);
        mod_log('report_claim', 'report', $report['id'], mdr_content_types()[$report['content_type']] ?? $report['content_type']);
        return [true, 'Жалоба закреплена за вами.'];
    }
    db_update('mod_reports', ['assigned_to' => null, 'assigned_at' => null], 'id = :id', ['id' => (int)$report['id']]);
    return [true, 'Жалоба снова свободна.'];
}

// Закрыть жалобу: $verdict 'resolved' (нарушение подтверждено) или 'rejected' (нарушений нет).
// Каждый пожаловавшийся получает оповещение.
function mdr_report_resolve(array $report, $verdict, $note)
{
    if ($report['status'] !== 'open') {
        return [false, 'Жалоба уже закрыта.'];
    }
    $verdict = $verdict === 'resolved' ? 'resolved' : 'rejected';
    $note = trim((string)$note);
    if (mb_strlen($note) > 1000) {
        return [false, 'Комментарий слишком длинный (не больше 1000 символов).'];
    }
    $rid = (int)$report['id'];
    db_update('mod_reports', [
        'status' => $verdict,
        'resolved_by' => uid(),
        'resolved_at' => now(),
        'resolution_note' => $note !== '' ? $note : null,
        'assigned_to' => $report['assigned_to'] ?: uid(),
    ], 'id = :id', ['id' => $rid]);

    // Для ссылки в оповещении: тема сообщения, владелец стены, переписка
    $c = mdr_content_load($report['content_type'], $report['content_id']);
    $aux = 0;
    $threadId = null;
    $postId = null;
    if ($c) {
        if ($c['type'] === 'post') {
            $threadId = $c['thread_id'];
            $postId = $c['id'];
        } elseif (in_array($c['type'], ['profile_post', 'profile_comment', 'message'], true)) {
            $aux = (int)$c['owner_id'];
        }
    }
    $extra = $verdict . '|' . $report['content_type'] . '|' . (int)$report['content_id'] . '|' . $aux;
    foreach (db_all('SELECT DISTINCT user_id FROM mod_report_items WHERE report_id = :r', ['r' => $rid]) as $i) {
        alert_add((int)$i['user_id'], 'mod_report', uid(), $threadId, $postId, $extra);
    }
    mod_log($verdict === 'resolved' ? 'report_resolve' : 'report_reject', 'report', $rid, $note);
    return [true, $verdict === 'resolved' ? 'Жалоба закрыта: нарушение подтверждено.' : 'Жалоба закрыта: нарушений нет.'];
}

function mdr_report_reopen(array $report)
{
    if ($report['status'] === 'open') {
        return [false, 'Жалоба и так открыта.'];
    }
    if (mdr_report_open_for($report['content_type'], $report['content_id'])) {
        return [false, 'На этот материал уже есть открытая жалоба.'];
    }
    db_update('mod_reports', ['status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'assigned_to' => uid(), 'assigned_at' => now()],
        'id = :id', ['id' => (int)$report['id']]);
    mod_log('report_reopen', 'report', $report['id'], '');
    return [true, 'Жалоба открыта снова.'];
}

// Карточка жалобы в центре жалоб
function mdr_report_card(array $r, array $items, $c, array $users, $return)
{
    $rid = (int)$r['id'];
    $types = mdr_content_types();
    $reasons = mdr_report_reasons();
    $open = $r['status'] === 'open';
    $author = $c ? $c['author'] : ($users[(int)$r['content_user_id']] ?? mdr_user_row(0, null));
    $assigned = $r['assigned_to'] ? ($users[(int)$r['assigned_to']] ?? null) : null;
    $resolver = $r['resolved_by'] ? ($users[(int)$r['resolved_by']] ?? null) : null;

    $h = '<article class="card mdr-report' . ($open ? '' : ' is-closed') . '" id="report-' . $rid . '">';
    $h .= '<header class="mdr-report-head">';
    $h .= '<div class="mdr-report-who">' . avatar($author, 'm') . '<div><div class="mdr-report-title">' . e($types[$r['content_type']] ?? $r['content_type']) . ' от ' . fp_user_link($author) . '</div>';
    $h .= '<div class="muted small">';
    if ($c && $c['context'] !== '') {
        $h .= $c['context'] . ' · ';
    }
    $h .= ($c && $c['created_at'] && $c['type'] !== 'user' ? 'написано ' . e(fdate($c['created_at'])) . ' · ' : '') . 'жалоба #' . $rid . '</div></div></div>';
    $h .= '<div class="mdr-report-badges">';
    $n = count($items);
    $h .= '<span class="tag mdr-tag-count" title="Сколько человек пожаловались">' . icon('users') . $n . ' ' . plural($n, 'жалоба', 'жалобы', 'жалоб') . '</span>';
    if ($open) {
        $h .= $assigned ? '<span class="tag mdr-tag-claimed">' . icon('user') . 'Взял(а) ' . e($assigned['username']) . '</span>' : '<span class="tag mdr-tag-open">Ожидает</span>';
    } elseif ($r['status'] === 'resolved') {
        $h .= '<span class="tag mdr-tag-ok">' . icon('check') . 'Нарушение подтверждено</span>';
    } else {
        $h .= '<span class="tag">' . icon('x') . 'Нарушений нет</span>';
    }
    $h .= '</div></header>';

    // Текст материала
    if (!$c) {
        $h .= '<div class="mdr-gone">' . icon('trash') . ' Материал удалён полностью. Текст на момент жалобы:</div>' . mdr_content_preview($r['content_snapshot']);
    } else {
        $flags = '';
        if ($c['deleted']) {
            $flags .= '<span class="tag tag-deleted">' . icon('trash') . 'Удалено</span>';
        }
        if ($c['type'] === 'message') {
            $flags .= '<span class="tag">' . icon('lock') . 'Видно только это сообщение из переписки</span>';
        }
        if ($flags !== '') {
            $h .= '<div class="mdr-report-flags">' . $flags . '</div>';
        }
        // Личное сообщение из жалобы видно команде, остальное - по правам на раздел
        $canSee = $c['visible'] || $c['type'] === 'message';
        $h .= '<div class="mdr-preview-wrap">' . ($canSee ? mdr_content_preview($c['body']) : '<div class="mdr-preview muted">' . icon('lock') . ($c['deleted'] ? ' Сообщение удалено, текст видят модераторы.' : ' Сообщение в разделе, закрытом для вас.') . '</div>') . '</div>';
        if ($canSee && $r['content_snapshot'] !== null && (string)$r['content_snapshot'] !== $c['body']) {
            $h .= '<details class="mdr-snapshot"><summary>' . icon('edit') . ' Текст изменили после жалобы - показать, как было</summary>' . mdr_content_preview($r['content_snapshot']) . '</details>';
        }
        if ($c['link'] !== '') {
            $openLabels = ['user' => 'Открыть профиль', 'profile_post' => 'Открыть в профиле', 'profile_comment' => 'Открыть в профиле', 'message' => 'Открыть переписку'];
            $h .= '<a class="mdr-open-link" href="' . e($c['link']) . '">' . icon('external') . ' ' . ($openLabels[$c['type']] ?? 'Открыть на форуме') . '</a>';
        }
    }

    // Кто пожаловался
    $h .= '<div class="mdr-reporters">';
    foreach ($items as $i) {
        $ru = mdr_user_row($i['user_id'], $i['username'], $i['avatar'], $i['group_color']);
        $h .= '<div class="mdr-reporter">' . avatar($ru, 'xs') . '<div class="mdr-reporter-body"><div>' . fp_user_link($ru)
            . ' <span class="tag mdr-reason">' . e($reasons[$i['reason']] ?? $i['reason']) . '</span> <span class="muted small">' . e(fdate($i['created_at'])) . '</span></div>';
        if ($i['comment'] !== null && $i['comment'] !== '') {
            $h .= '<div class="mdr-reporter-text">' . nl2br(e($i['comment'])) . '</div>';
        }
        $h .= '</div></div>';
    }
    $h .= '</div>';

    // Действия
    $action = e(url('/forum/reports.php'));
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . $rid . '"><input type="hidden" name="return" value="' . e($return) . '">';
    if ($open) {
        $h .= '<div class="mdr-report-actions">';
        $mineClaim = (int)$r['assigned_to'] === uid();
        $h .= '<form method="post" action="' . $action . '" class="inline-form"' . ($assigned && !$mineClaim ? ' data-confirm="Жалобу уже взял(а) ' . e($assigned['username']) . '. Забрать себе?"' : '') . '>' . $hidden
            . '<input type="hidden" name="action" value="' . ($mineClaim ? 'unclaim' : 'claim') . '">'
            . '<button class="btn btn-sm btn-outline" type="submit">' . icon($mineClaim ? 'x' : 'user') . ' ' . ($mineClaim ? 'Отпустить' : 'Взять') . '</button></form>';
        $target = $author && !empty($author['id']) ? $author : null;
        if ($target && mdr_can_punish($target)) {
            $h .= '<a class="btn btn-sm btn-outline" href="' . e(url('/forum/warn.php', ['report' => $rid])) . '">' . icon('alert') . ' Выдать предупреждение</a>';
        }
        $h .= '</div>';
        $h .= '<form method="post" action="' . $action . '" class="mdr-resolve">' . $hidden . '<input type="hidden" name="action" value="resolve">'
            . '<textarea class="textarea mdr-resolve-note" name="note" rows="2" maxlength="1000" placeholder="Комментарий к решению (видят только модераторы)"></textarea>'
            . '<div class="mdr-resolve-btns">'
            . '<button class="btn btn-sm btn-success" type="submit" name="verdict" value="resolved">' . icon('check') . ' Нарушение подтверждено</button>'
            . '<button class="btn btn-sm btn-ghost" type="submit" name="verdict" value="rejected">' . icon('x') . ' Нарушений нет</button>'
            . '</div></form>';
    } else {
        $h .= '<div class="mdr-resolution">' . icon('info') . '<div>Решение: ' . ($resolver ? fp_user_link($resolver) : 'модератор') . ', ' . e(fdate($r['resolved_at']));
        if ($r['resolution_note'] !== null && $r['resolution_note'] !== '') {
            $h .= '<div class="mdr-resolution-note">' . nl2br(e($r['resolution_note'])) . '</div>';
        }
        $h .= '</div>';
        $h .= '<form method="post" action="' . $action . '" class="inline-form">' . $hidden . '<input type="hidden" name="action" value="reopen">'
            . '<button class="btn btn-sm btn-ghost" type="submit">' . icon('undo') . ' Открыть снова</button></form>';
        $h .= '</div>';
    }
    return $h . '</article>';
}
