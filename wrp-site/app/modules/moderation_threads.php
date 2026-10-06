<?php
// Модуль «Модерация»: запрет ответов в теме (и во всём форуме), история правок сообщений и сравнение версий.

// ---------- Запрет ответов ----------

// Сроки запрета для формы: дни => подпись (0 - навсегда)
function mdr_replyban_terms()
{
    return [1 => '1 день', 3 => '3 дня', 7 => '7 дней', 14 => '14 дней', 30 => '30 дней', 0 => 'Навсегда'];
}

// Действующий запрет пользователя: в этой теме или во всём форуме. $threadId = 0 - только общий запрет.
function mdr_replyban_active($userId, $threadId)
{
    static $cache = [];
    $key = (int)$userId . ':' . (int)$threadId;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = db_one('SELECT b.*, t.title AS thread_title FROM mod_reply_bans b LEFT JOIN threads t ON t.id = b.thread_id
            WHERE b.user_id = :u AND (b.thread_id = :t OR b.thread_id IS NULL) AND (b.expires_at IS NULL OR b.expires_at > :n)
            ORDER BY b.thread_id IS NULL, b.id DESC LIMIT 1', ['u' => (int)$userId, 't' => (int)$threadId, 'n' => now()]);
    }
    return $cache[$key];
}

// Текст для пользователя, которому запрещено отвечать
function mdr_replyban_text(array $b)
{
    $t = $b['thread_id'] ? 'Модератор запретил вам отвечать в этой теме' : 'Модератор запретил вам писать на форуме';
    $t .= $b['expires_at'] ? ' до ' . fdate($b['expires_at']) : ' без срока';
    if ($b['reason'] !== null && $b['reason'] !== '') {
        $t .= '. Причина: ' . $b['reason'];
    }
    return $t . '.';
}

// Выдать запрет (заменяет прежний в той же теме). $threadId null - во всём форуме. Возвращает id.
function mdr_replyban_add($threadId, $userId, $days, $reason)
{
    $userId = (int)$userId;
    if ($threadId) {
        db_exec('DELETE FROM mod_reply_bans WHERE user_id = :u AND thread_id = :t', ['u' => $userId, 't' => (int)$threadId]);
    } else {
        db_exec('DELETE FROM mod_reply_bans WHERE user_id = :u AND thread_id IS NULL', ['u' => $userId]);
    }
    $until = (int)$days > 0 ? date('Y-m-d H:i:s', time() + (int)$days * 86400) : null;
    $reason = mb_substr(trim((string)$reason), 0, 255);
    $id = db_insert('mod_reply_bans', [
        'thread_id' => $threadId ? (int)$threadId : null,
        'user_id' => $userId,
        'reason' => $reason !== '' ? $reason : null,
        'banned_by' => uid() ?: null,
        'created_at' => now(),
        'expires_at' => $until,
    ]);
    $who = (string)db_val('SELECT username FROM users WHERE id = :id', ['id' => $userId]);
    $details = $who . ': ' . ($until ? 'до ' . date('d.m.Y H:i', strtotime($until)) : 'навсегда') . ($reason !== '' ? '. Причина: ' . $reason : '');
    if ($threadId) {
        mod_log('reply_ban', 'thread', $threadId, $details);
    } else {
        mod_log('reply_ban', 'user', $userId, 'Во всём форуме, ' . $details);
    }
    return $id;
}

function mdr_replyban_remove(array $b)
{
    db_exec('DELETE FROM mod_reply_bans WHERE id = :id', ['id' => (int)$b['id']]);
    $who = (string)db_val('SELECT username FROM users WHERE id = :id', ['id' => (int)$b['user_id']]);
    if ($b['thread_id']) {
        mod_log('reply_unban', 'thread', $b['thread_id'], $who);
    } else {
        mod_log('reply_unban', 'user', $b['user_id'], 'Во всём форуме');
    }
}

// Блок под сообщениями темы: действующие запреты (команде) и окно выдачи запрета (модераторам)
function mdr_thread_replyban_box(array $thread)
{
    $tid = (int)$thread['id'];
    $bans = db_all('SELECT b.*, u.username, u.avatar, g.color AS group_color, bu.username AS by_name
        FROM mod_reply_bans b LEFT JOIN users u ON u.id = b.user_id LEFT JOIN user_groups g ON g.id = u.group_id
        LEFT JOIN users bu ON bu.id = b.banned_by
        WHERE b.thread_id = :t AND (b.expires_at IS NULL OR b.expires_at > :n) ORDER BY b.id DESC', ['t' => $tid, 'n' => now()]);
    $h = '';
    if ($bans) {
        $h .= '<section class="card mdr-rb-box"><div class="mdr-rb-head">' . icon('ban') . '<b>Запрет ответов в теме</b><span class="muted small">видят только модераторы</span></div>';
        foreach ($bans as $b) {
            $u = mdr_user_row($b['user_id'], $b['username'], $b['avatar'], $b['group_color']);
            $h .= '<div class="mdr-rb-row">' . avatar($u, 'xs') . '<div class="mdr-rb-text">' . fp_user_link($u)
                . ' <span class="muted small">' . ($b['expires_at'] ? 'до ' . e(fdate($b['expires_at'])) : 'навсегда')
                . ($b['by_name'] ? ' · выдал(а) ' . e($b['by_name']) : '') . '</span>'
                . ($b['reason'] ? '<div class="small">' . e($b['reason']) . '</div>' : '') . '</div>';
            if (can_moderate()) {
                $h .= '<form method="post" action="' . e(url('/forum/moderation.php')) . '" class="inline-form" data-confirm="Снять запрет ответов?">' . csrf_field()
                    . '<input type="hidden" name="action" value="replyunban"><input type="hidden" name="id" value="' . (int)$b['id'] . '">'
                    . '<button class="btn btn-sm btn-ghost" type="submit">' . icon('undo') . ' Снять</button></form>';
            }
            $h .= '</div>';
        }
        $h .= '</section>';
    }
    if (can_moderate()) {
        $names = array_column(db_all('SELECT DISTINCT u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE p.thread_id = :t AND p.user_id <> :me ORDER BY u.username LIMIT 200',
            ['t' => $tid, 'me' => uid()]), 'username');
        $h .= '<dialog class="modal" id="dlg-mdr-replyban"><form method="post" action="' . e(url('/forum/moderation.php')) . '">' . csrf_field()
            . '<input type="hidden" name="action" value="replyban"><input type="hidden" name="thread_id" value="' . $tid . '">'
            . '<div class="modal-head"><span>Запрет ответов в теме</span><button type="button" class="modal-x" data-dialog-close aria-label="Закрыть">' . icon('x') . '</button></div>'
            . '<div class="modal-body form">'
            . '<div class="form-row"><label class="label" for="mdr-rb-user">Пользователь</label>'
            . '<input class="input" id="mdr-rb-user" name="username" list="mdr-rb-names" maxlength="32" required autocomplete="off" placeholder="Ник на форуме">'
            . '<datalist id="mdr-rb-names">';
        foreach ($names as $n) {
            $h .= '<option value="' . e($n) . '">';
        }
        $h .= '</datalist></div>'
            . '<div class="form-row"><label class="label" for="mdr-rb-days">Срок</label><select class="select" id="mdr-rb-days" name="days">';
        foreach (mdr_replyban_terms() as $d => $label) {
            $h .= '<option value="' . (int)$d . '"' . ($d === 3 ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $h .= '</select></div>'
            . '<div class="form-row"><label class="label" for="mdr-rb-reason">Причина</label><input class="input" id="mdr-rb-reason" name="reason" maxlength="255" placeholder="Например: флуд в теме жалобы"></div>'
            . '<div class="hint">Пользователь продолжит видеть тему, но не сможет в ней отвечать. Он получит оповещение.</div>'
            . '</div>'
            . '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-dialog-close>Отмена</button>'
            . '<button type="submit" class="btn btn-accent">' . icon('ban') . ' Запретить</button></div>'
            . '</form></dialog>';
    }
    return $h;
}

// ---------- История правок ----------

// Сохранить текст сообщения перед правкой (точка post_before_update)
function mdr_history_store(array $post)
{
    db_insert('post_edits', [
        'post_id' => (int)$post['id'],
        'body' => (string)$post['body'],
        'prev_edited_at' => !empty($post['edited_at']) ? $post['edited_at'] : null,
        'prev_edited_by' => !empty($post['edited_by']) ? (int)$post['edited_by'] : null,
        'editor_id' => uid() ?: null,
        'edited_at' => now(),
    ]);
}

// Версии сообщения, новые сначала: [body, at, by (id), kind: current / edit / original / early]
function mdr_post_versions(array $post)
{
    $edits = db_all('SELECT * FROM post_edits WHERE post_id = :p ORDER BY id ASC', ['p' => (int)$post['id']]);
    $n = count($edits);
    $versions = [];
    for ($i = 0; $i < $n; $i++) {
        $e = $edits[$i];
        if ($i === 0) {
            $versions[] = [
                'body' => (string)$e['body'],
                'at' => $e['prev_edited_at'] ?: $post['created_at'],
                'by' => $e['prev_edited_by'] ? (int)$e['prev_edited_by'] : (int)$post['user_id'],
                'kind' => $e['prev_edited_at'] ? 'early' : 'original',
            ];
        } else {
            $prev = $edits[$i - 1];
            $versions[] = ['body' => (string)$e['body'], 'at' => $prev['edited_at'], 'by' => (int)$prev['editor_id'], 'kind' => 'edit'];
        }
    }
    if ($n) {
        $last = $edits[$n - 1];
        $versions[] = ['body' => (string)$post['body'], 'at' => $last['edited_at'], 'by' => (int)$last['editor_id'], 'kind' => 'current'];
    } else {
        $versions[] = [
            'body' => (string)$post['body'],
            'at' => $post['edited_at'] ?: $post['created_at'],
            'by' => $post['edited_at'] && $post['edited_by'] ? (int)$post['edited_by'] : (int)$post['user_id'],
            'kind' => $post['edited_at'] ? 'current' : 'original',
        ];
    }
    return array_reverse($versions);
}

// Построчное сравнение: [['=', строка] | ['-', строка] | ['+', строка], ...]
function mdr_diff_lines(array $a, array $b)
{
    $na = count($a);
    $nb = count($b);
    $start = 0;
    while ($start < $na && $start < $nb && $a[$start] === $b[$start]) {
        $start++;
    }
    $endA = $na - 1;
    $endB = $nb - 1;
    while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) {
        $endA--;
        $endB--;
    }
    $ops = [];
    for ($i = 0; $i < $start; $i++) {
        $ops[] = ['=', $a[$i]];
    }
    $midA = array_slice($a, $start, $endA - $start + 1);
    $midB = array_slice($b, $start, $endB - $start + 1);
    $m = count($midA);
    $n = count($midB);
    if ($m * $n > 250000) {
        // слишком большой кусок: просто «было» и «стало»
        foreach ($midA as $l) {
            $ops[] = ['-', $l];
        }
        foreach ($midB as $l) {
            $ops[] = ['+', $l];
        }
    } else {
        $len = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $len[$i][$j] = $midA[$i] === $midB[$j] ? $len[$i + 1][$j + 1] + 1 : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }
        $i = 0;
        $j = 0;
        while ($i < $m && $j < $n) {
            if ($midA[$i] === $midB[$j]) {
                $ops[] = ['=', $midA[$i]];
                $i++;
                $j++;
            } elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
                $ops[] = ['-', $midA[$i]];
                $i++;
            } else {
                $ops[] = ['+', $midB[$j]];
                $j++;
            }
        }
        for (; $i < $m; $i++) {
            $ops[] = ['-', $midA[$i]];
        }
        for (; $j < $n; $j++) {
            $ops[] = ['+', $midB[$j]];
        }
    }
    for ($i = $endA + 1; $i < $na; $i++) {
        $ops[] = ['=', $a[$i]];
    }
    return $ops;
}

function mdr_text_lines($text)
{
    return explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$text));
}

// HTML сравнения двух версий (текст экранирован). Длинные куски без изменений сворачиваются.
function mdr_diff_html($old, $new, $context = 2)
{
    $ops = mdr_diff_lines(mdr_text_lines($old), mdr_text_lines($new));
    $changed = [];
    foreach ($ops as $k => $op) {
        if ($op[0] !== '=') {
            $changed[] = $k;
        }
    }
    if (!$changed) {
        return '<div class="mdr-diff mdr-diff-same">Текст не изменился (могли измениться только пробелы в конце).</div>';
    }
    $show = [];
    foreach ($changed as $k) {
        for ($i = $k - $context; $i <= $k + $context; $i++) {
            $show[$i] = true;
        }
    }
    $h = '<div class="mdr-diff">';
    $skipped = 0;
    $added = 0;
    $removed = 0;
    foreach ($ops as $k => $op) {
        if ($op[0] === '=' && empty($show[$k])) {
            $skipped++;
            continue;
        }
        if ($skipped) {
            $h .= '<div class="mdr-diff-skip">… ' . $skipped . ' ' . plural($skipped, 'строка', 'строки', 'строк') . ' без изменений</div>';
            $skipped = 0;
        }
        $cls = $op[0] === '+' ? 'is-add' : ($op[0] === '-' ? 'is-del' : 'is-same');
        if ($op[0] === '+') {
            $added++;
        } elseif ($op[0] === '-') {
            $removed++;
        }
        $sign = $op[0] === '=' ? ' ' : ($op[0] === '+' ? '+' : '−');
        $h .= '<div class="mdr-diff-line ' . $cls . '"><span class="mdr-diff-sign" aria-hidden="true">' . $sign . '</span><span class="mdr-diff-text">' . ($op[1] === '' ? '&nbsp;' : e($op[1])) . '</span></div>';
    }
    if ($skipped) {
        $h .= '<div class="mdr-diff-skip">… ' . $skipped . ' ' . plural($skipped, 'строка', 'строки', 'строк') . ' без изменений</div>';
    }
    $h .= '</div>';
    $sum = '<div class="mdr-diff-sum"><span class="mdr-diff-add">+' . $added . '</span> <span class="mdr-diff-del">−' . $removed . '</span> ' . plural($added + $removed, 'строка', 'строки', 'строк') . '</div>';
    return $sum . $h;
}
