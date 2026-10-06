<?php
// Модуль «Модерация»: предупреждения с баллами, пороги (бан, запрет писать), истечение срока.
// Активные баллы = сумма баллов не снятых и не истёкших предупреждений.
// Порог срабатывает один раз при пересечении: запись в mod_warning_triggers.
// Если баллы опустились ниже порога (снятие, истечение), запись удаляется и порог может сработать снова.

function mdr_warning_types()
{
    return db_all('SELECT * FROM mod_warning_types ORDER BY display_order, id');
}

function mdr_warning_rules()
{
    return db_all('SELECT * FROM mod_warning_rules ORDER BY points, id');
}

function mdr_rule_actions()
{
    return ['ban' => 'Бан на форуме', 'noreply' => 'Запрет писать на форуме'];
}

// «Бан на 3 дня», «Бессрочный бан», «Запрет писать на форуме на 7 дней»
function mdr_rule_text(array $r)
{
    $days = (int)$r['days'];
    $term = $days > 0 ? 'на ' . $days . ' ' . plural($days, 'день', 'дня', 'дней') : '';
    if ($r['action'] === 'noreply') {
        return 'Запрет писать на форуме ' . ($term !== '' ? $term : 'навсегда');
    }
    return $term !== '' ? 'Бан ' . $term : 'Бессрочный бан';
}

function mdr_points_text($n)
{
    $n = (int)$n;
    return $n . ' ' . plural($n, 'балл', 'балла', 'баллов');
}

// Активные баллы пользователя
function mdr_points_active($userId)
{
    return (int)db_val('SELECT COALESCE(SUM(points), 0) FROM mod_warnings
        WHERE user_id = :u AND revoked_at IS NULL AND is_expired = 0 AND (expires_at IS NULL OR expires_at > :n)',
        ['u' => (int)$userId, 'n' => now()]);
}

// Действующие баллы всех пользователей, у кого они есть: [user_id => баллы] (кэш на запрос)
function mdr_active_points_map()
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db_all('SELECT user_id, SUM(points) AS pts FROM mod_warnings
            WHERE revoked_at IS NULL AND is_expired = 0 AND (expires_at IS NULL OR expires_at > :n) GROUP BY user_id', ['n' => now()]) as $r) {
            $map[(int)$r['user_id']] = (int)$r['pts'];
        }
    }
    return $map;
}

// Предупреждение действует (не снято и не истекло)
function mdr_warning_active(array $w)
{
    return $w['revoked_at'] === null && !(int)$w['is_expired'] && ($w['expires_at'] === null || strtotime($w['expires_at']) > time());
}

// Выдать предупреждение. $d: type_id, title, points, expiry_days, message, public_note, content_type, content_id,
// report_id, send_pm. Права проверяет страница (mdr_punish_error). Возвращает ['id', 'points', 'applied' => [тексты]].
function mdr_warn_issue(array $target, array $d)
{
    $uidT = (int)$target['id'];
    $points = max(0, min(1000, (int)$d['points']));
    $days = max(0, min(3650, (int)$d['expiry_days']));
    $now = now();
    $title = mb_substr(trim((string)$d['title']), 0, 100);
    $message = trim((string)($d['message'] ?? ''));
    $contentType = !empty($d['content_type']) ? (string)$d['content_type'] : null;
    $contentId = !empty($d['content_id']) ? (int)$d['content_id'] : null;
    $wid = db_insert('mod_warnings', [
        'user_id' => $uidT,
        'type_id' => !empty($d['type_id']) ? (int)$d['type_id'] : null,
        'title' => $title,
        'points' => $points,
        'content_type' => $contentType,
        'content_id' => $contentId,
        'report_id' => !empty($d['report_id']) ? (int)$d['report_id'] : null,
        'issued_by' => uid(),
        'message' => $message !== '' ? mb_substr($message, 0, 2000) : null,
        'public_note' => !empty($d['public_note']) && $contentType === 'post' ? 1 : 0,
        'created_at' => $now,
        'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
        'is_expired' => 0,
    ]);
    $where = '';
    $threadId = null;
    $postId = null;
    if ($contentType === 'post' && $contentId) {
        $post = db_one('SELECT p.id, p.thread_id, t.title FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.id = :id', ['id' => $contentId]);
        if ($post) {
            $threadId = (int)$post['thread_id'];
            $postId = (int)$post['id'];
            $where = ', сообщение #' . $postId . ' в теме «' . $post['title'] . '»';
        }
    }
    mod_log('warning_add', 'user', $uidT, $title . ' (+' . $points . ', ' . ($days > 0 ? $days . ' дн.' : 'бессрочно') . ')' . $where);
    alert_add($uidT, 'mod_warning', uid(), $threadId, $postId, $points . '|' . $wid . '|' . $title);
    $applied = mdr_warn_escalate($uidT, $wid);
    $total = mdr_points_active($uidT);
    if (!empty($d['send_pm'])) {
        mdr_warn_send_pm($target, $title, $points, $days, $message, $postId, $total, $applied);
    }
    return ['id' => $wid, 'points' => $total, 'applied' => $applied];
}

// Личное сообщение о предупреждении от имени модератора
function mdr_warn_send_pm(array $target, $title, $points, $days, $message, $postId, $total, array $applied)
{
    $now = now();
    $body = '[b]Вам выдано предупреждение:[/b] ' . $title . ' (+' . mdr_points_text($points) . ')' . "\n"
        . '[b]Срок действия:[/b] ' . ($days > 0 ? 'до ' . date('d.m.Y', time() + $days * 86400) : 'бессрочно') . "\n";
    if ($postId) {
        $body .= '[b]За сообщение:[/b] [url=/forum/post.php?id=' . (int)$postId . ']открыть[/url]' . "\n";
    }
    if ($message !== '') {
        $body .= "\n" . $message . "\n";
    }
    $body .= "\n" . 'Сейчас у вас ' . mdr_points_text($total) . '.';
    if ($applied) {
        $body .= ' Сработало: ' . implode(', ', $applied) . '.';
    }
    $body .= "\n" . 'Все предупреждения видны в профиле, вкладка «Предупреждения».';
    $cid = db_insert('conversations', [
        'title' => mb_substr('Предупреждение: ' . $title, 0, 150),
        'starter_id' => uid(),
        'reply_count' => 0,
        'last_message_at' => $now,
        'last_message_user_id' => uid(),
        'created_at' => $now,
    ]);
    db_insert('conversation_users', ['conversation_id' => $cid, 'user_id' => uid(), 'last_read_at' => $now, 'is_left' => 0]);
    db_insert('conversation_users', ['conversation_id' => $cid, 'user_id' => (int)$target['id'], 'last_read_at' => null, 'is_left' => 0]);
    acc_conv_add_message($cid, $body, true);
    acc_conv_notify($cid, [(int)$target['id']]);
    return $cid;
}

// Бан на форуме на $days дней (0 - навсегда). Короче уже действующего бан не делается.
// Возвращает [применён, до какого времени, причина].
function mdr_ban_apply($userId, $days, $reason)
{
    $u = db_one('SELECT id, is_banned, ban_until FROM users WHERE id = :id', ['id' => (int)$userId]);
    if (!$u) {
        return [false, null, $reason];
    }
    $until = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
    $activeBan = (int)$u['is_banned'] && ($u['ban_until'] === null || strtotime($u['ban_until']) > time());
    if ($activeBan) {
        // уже забанен: навсегда - ничего не делаем; на срок - только если новый срок дольше
        if ($u['ban_until'] === null) {
            return [false, null, $reason];
        }
        if ($until !== null && strtotime($u['ban_until']) >= strtotime($until)) {
            return [false, $u['ban_until'], $reason];
        }
    }
    db_update('users', ['is_banned' => 1, 'ban_reason' => $reason, 'ban_until' => $until], 'id = :id', ['id' => (int)$userId]);
    mod_log('warning_ban', 'user', $userId, ($until ? 'до ' . date('d.m.Y H:i', strtotime($until)) : 'навсегда') . '. ' . $reason);
    return [true, $until, $reason];
}

// Проверить пороги после нового предупреждения: применить новые, забыть те, что ниже баллов
function mdr_warn_escalate($userId, $warningId = null)
{
    $userId = (int)$userId;
    $total = mdr_points_active($userId);
    $fired = [];
    foreach (db_all('SELECT * FROM mod_warning_triggers WHERE user_id = :u', ['u' => $userId]) as $t) {
        $fired[(int)$t['rule_id']] = $t;
    }
    $applied = [];
    foreach (mdr_warning_rules() as $r) {
        $rid = (int)$r['id'];
        if ((int)$r['points'] > $total) {
            if (isset($fired[$rid])) {
                db_exec('DELETE FROM mod_warning_triggers WHERE user_id = :u AND rule_id = :r', ['u' => $userId, 'r' => $rid]);
            }
            continue;
        }
        if (isset($fired[$rid])) {
            continue;
        }
        $reason = 'Предупреждения: ' . mdr_points_text($total) . ' (порог ' . (int)$r['points'] . ')';
        $row = ['user_id' => $userId, 'rule_id' => $rid, 'warning_id' => $warningId ? (int)$warningId : null, 'points' => $total, 'created_at' => now()];
        if ($r['action'] === 'noreply') {
            $row['reply_ban_id'] = mdr_replyban_add(null, $userId, (int)$r['days'], $reason);
            $until = (int)$r['days'] > 0 ? date('Y-m-d H:i:s', time() + (int)$r['days'] * 86400) : '';
            alert_add($userId, 'mod_penalty', uid() ?: null, null, null, 'noreply|' . $until);
        } else {
            list($ok, $until, $why) = mdr_ban_apply($userId, (int)$r['days'], $reason);
            $row['ban_applied'] = $ok ? 1 : 0;
            $row['ban_until'] = $ok ? $until : null;
            $row['ban_reason'] = $ok ? $why : null;
            if ($ok) {
                alert_add($userId, 'mod_penalty', uid() ?: null, null, null, 'ban|' . (string)$until);
            }
        }
        db_insert('mod_warning_triggers', $row);
        $applied[] = mdr_rule_text($r);
    }
    return $applied;
}

// Баллы уменьшились (снятие или истечение): забыть пороги выше текущих баллов.
// $lift - снять бан и запрет писать, выданные этими порогами (при снятии предупреждения модератором).
function mdr_warn_drop_triggers($userId, $lift)
{
    $userId = (int)$userId;
    $total = mdr_points_active($userId);
    $rules = [];
    foreach (mdr_warning_rules() as $r) {
        $rules[(int)$r['id']] = $r;
    }
    $dropped = [];
    $kept = [];
    foreach (db_all('SELECT * FROM mod_warning_triggers WHERE user_id = :u', ['u' => $userId]) as $t) {
        $r = $rules[(int)$t['rule_id']] ?? null;
        if ($r && (int)$r['points'] <= $total) {
            $kept[] = $t;
            continue;
        }
        $dropped[] = $t;
        db_exec('DELETE FROM mod_warning_triggers WHERE user_id = :u AND rule_id = :r', ['u' => $userId, 'r' => (int)$t['rule_id']]);
    }
    $lifted = [];
    if (!$lift || !$dropped) {
        return $lifted;
    }
    $u = db_one('SELECT id, is_banned, ban_reason, ban_until FROM users WHERE id = :id', ['id' => $userId]);
    foreach ($dropped as $t) {
        if ($t['reply_ban_id']) {
            if (db_exec('DELETE FROM mod_reply_bans WHERE id = :id', ['id' => (int)$t['reply_ban_id']])) {
                $lifted[] = 'запрет писать на форуме';
            }
        }
        if ($u && (int)$t['ban_applied'] && (int)$u['is_banned'] && (string)$u['ban_reason'] === (string)$t['ban_reason'] && (string)$u['ban_until'] === (string)$t['ban_until']) {
            // Текущий бан выдан этим порогом. Если остался более ранний действующий порог с баном - вернуть его, иначе снять бан.
            $back = null;
            foreach ($kept as $k) {
                if ((int)$k['ban_applied'] && ($k['ban_until'] === null || strtotime($k['ban_until']) > time())) {
                    if ($back === null || $k['ban_until'] === null || ($back['ban_until'] !== null && strtotime($k['ban_until']) > strtotime($back['ban_until']))) {
                        $back = $k;
                    }
                }
            }
            if ($back) {
                db_update('users', ['is_banned' => 1, 'ban_reason' => $back['ban_reason'], 'ban_until' => $back['ban_until']], 'id = :id', ['id' => $userId]);
                $lifted[] = 'бан сокращён';
            } else {
                db_update('users', ['is_banned' => 0, 'ban_reason' => null, 'ban_until' => null], 'id = :id', ['id' => $userId]);
                $lifted[] = 'бан снят';
            }
            mod_log('warning_unban', 'user', $userId, 'Баллы опустились ниже порога: ' . mdr_points_text($total));
            $u = db_one('SELECT id, is_banned, ban_reason, ban_until FROM users WHERE id = :id', ['id' => $userId]);
        }
    }
    return $lifted;
}

// Снять предупреждение (модератор). Возвращает [ok, сообщение].
function mdr_warn_revoke(array $w, $reason)
{
    if ($w['revoked_at'] !== null) {
        return [false, 'Предупреждение уже снято.'];
    }
    $reason = mb_substr(trim((string)$reason), 0, 255);
    db_update('mod_warnings', ['revoked_by' => uid(), 'revoked_at' => now(), 'revoke_reason' => $reason !== '' ? $reason : null], 'id = :id', ['id' => (int)$w['id']]);
    mod_log('warning_revoke', 'user', $w['user_id'], $w['title'] . ' (+' . (int)$w['points'] . ')' . ($reason !== '' ? '. Причина: ' . $reason : ''));
    $lifted = mdr_warn_drop_triggers($w['user_id'], true);
    return [true, 'Предупреждение снято.' . ($lifted ? ' Также: ' . implode(', ', $lifted) . '.' : '')];
}

// Крон: отметить истёкшие предупреждения и пересчитать пороги
function mdr_warnings_expire()
{
    $now = now();
    $users = array_map('intval', array_column(db_all('SELECT DISTINCT user_id FROM mod_warnings
        WHERE is_expired = 0 AND revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= :n', ['n' => $now]), 'user_id'));
    db_exec('UPDATE mod_warnings SET is_expired = 1 WHERE is_expired = 0 AND expires_at IS NOT NULL AND expires_at <= :n', ['n' => $now]);
    foreach ($users as $id) {
        mdr_warn_drop_triggers($id, false);
    }
    return count($users);
}

// Крон: снять баны, срок которых прошёл (ядро снимает только когда пользователь сам заходит)
function mdr_bans_lift_expired()
{
    return db_exec('UPDATE users SET is_banned = 0, ban_reason = NULL, ban_until = NULL WHERE is_banned = 1 AND ban_until IS NOT NULL AND ban_until < :n', ['n' => now()]);
}

// Предупреждения с отметкой под сообщениями темы: [post_id => [строки]] (один запрос на тему)
function mdr_thread_post_warnings($threadId)
{
    static $cache = [];
    $threadId = (int)$threadId;
    if (!isset($cache[$threadId])) {
        $cache[$threadId] = [];
        $rows = db_all("SELECT w.id, w.content_id, w.title, w.points, w.created_at, u.username AS issuer_name
            FROM mod_warnings w JOIN posts p ON p.id = w.content_id LEFT JOIN users u ON u.id = w.issued_by
            WHERE w.content_type = 'post' AND w.public_note = 1 AND w.revoked_at IS NULL AND p.thread_id = :t ORDER BY w.id", ['t' => $threadId]);
        foreach ($rows as $r) {
            $cache[$threadId][(int)$r['content_id']][] = $r;
        }
    }
    return $cache[$threadId];
}

// Вкладка профиля «Предупреждения»: сам пользователь и команда
function mdr_member_warnings_html(array $m)
{
    $mid = (int)$m['id'];
    $isSelf = $mid === uid();
    $staff = is_staff();
    $canPunish = mdr_can_punish($m);
    $rows = db_all('SELECT w.*, iu.username AS issuer_name, ig.color AS issuer_color, ru.username AS revoker_name
        FROM mod_warnings w
        LEFT JOIN users iu ON iu.id = w.issued_by LEFT JOIN user_groups ig ON ig.id = iu.group_id
        LEFT JOIN users ru ON ru.id = w.revoked_by
        WHERE w.user_id = :u ORDER BY w.id DESC LIMIT 200', ['u' => $mid]);
    $total = mdr_points_active($mid);
    $rules = mdr_warning_rules();
    $bans = db_all('SELECT b.*, t.title AS thread_title FROM mod_reply_bans b LEFT JOIN threads t ON t.id = b.thread_id
        WHERE b.user_id = :u AND (b.expires_at IS NULL OR b.expires_at > :n) ORDER BY b.id DESC', ['u' => $mid, 'n' => now()]);
    $posts = [];
    foreach ($rows as $w) {
        if ($w['content_type'] === 'post' && $w['content_id']) {
            $posts[] = ['post', (int)$w['content_id']];
        }
    }
    $contents = $posts ? mdr_content_load_many($posts) : [];

    $h = '<div class="mdr-warn-tab">';
    $h .= '<section class="card mdr-points-card">';
    $h .= '<div class="mdr-points"><div class="mdr-points-num' . ($total ? ' is-on' : '') . '">' . $total . '</div><div><div class="mdr-points-label">'
        . ($total ? 'Действующих ' . plural($total, 'балл', 'балла', 'баллов') . ' предупреждений' : 'Действующих предупреждений нет') . '</div>';
    $h .= '<div class="muted small">' . ($isSelf ? 'Предупреждения сгорают сами, когда проходит их срок. Соблюдайте правила форума - и баллов не будет.' : 'Баллы растут с каждым предупреждением и сгорают по сроку.') . '</div></div>';
    if ($canPunish) {
        $h .= '<a class="btn btn-white btn-pill mdr-points-btn" href="' . e(url('/forum/warn.php', ['user' => $mid])) . '">' . icon('alert') . ' Выдать предупреждение</a>';
    }
    $h .= '</div>';
    if ($rules) {
        $h .= '<div class="mdr-thresholds">';
        foreach ($rules as $r) {
            $reached = $total >= (int)$r['points'];
            $h .= '<span class="mdr-threshold' . ($reached ? ' is-reached' : '') . '"><b>' . (int)$r['points'] . '</b> ' . e(mb_strtolower(mdr_rule_text($r))) . '</span>';
        }
        $h .= '</div>';
    }
    if ($bans) {
        $h .= '<div class="mdr-bans-list">';
        foreach ($bans as $b) {
            $h .= '<div class="mdr-ban-row">' . icon('ban') . '<span>' . ($b['thread_id'] ? 'Запрет ответов в теме «<a href="' . e(thread_url($b['thread_id'])) . '">' . e($b['thread_title'] ?? ('#' . $b['thread_id'])) . '</a>»' : 'Запрет писать на форуме')
                . ' ' . ($b['expires_at'] ? 'до ' . e(fdate($b['expires_at'])) : 'навсегда') . ($b['reason'] ? '. Причина: ' . e($b['reason']) : '') . '</span></div>';
        }
        $h .= '</div>';
    }
    $h .= '</section>';

    $h .= '<section class="card acc-list mdr-warn-list">';
    $h .= '<div class="acc-list-head"><b>История предупреждений</b><span class="muted small">' . num(count($rows)) . '</span></div>';
    if (!$rows) {
        $h .= '<div class="empty">' . icon('check') . '<div>' . ($isSelf ? 'У вас нет ни одного предупреждения. Так держать!' : 'Предупреждений нет.') . '</div></div>';
    }
    foreach ($rows as $w) {
        $active = mdr_warning_active($w);
        if ($w['revoked_at'] !== null) {
            $status = '<span class="tag">' . icon('undo') . 'Снято</span>';
        } elseif ($active) {
            $status = '<span class="tag mdr-tag-active">' . icon('alert') . 'Действует</span>';
        } else {
            $status = '<span class="tag">' . icon('clock') . 'Истекло</span>';
        }
        $h .= '<div class="mdr-warn-row' . ($active ? ' is-active' : '') . '" id="warning-' . (int)$w['id'] . '">';
        $h .= '<div class="mdr-warn-points">+' . (int)$w['points'] . '</div>';
        $h .= '<div class="mdr-warn-body">';
        $h .= '<div class="mdr-warn-title"><b>' . e($w['title']) . '</b> ' . $status . '</div>';
        $meta = [e(fdate($w['created_at']))];
        $meta[] = $w['expires_at'] ? ($active ? 'действует до ' : 'до ') . e(fday($w['expires_at'])) : 'бессрочно';
        if ($staff && $w['issuer_name'] !== null) {
            $meta[] = 'выдал(а) ' . user_link(['id' => $w['issued_by'], 'username' => $w['issuer_name'], 'group_color' => $w['issuer_color']]);
        }
        $c = $w['content_type'] === 'post' ? ($contents['post:' . (int)$w['content_id']] ?? null) : null;
        if ($c && $c['link'] !== '') {
            $meta[] = 'за <a href="' . e($c['link']) . '">сообщение</a> ' . $c['context'];
        } elseif ($w['content_type'] && $w['content_type'] !== 'user') {
            $meta[] = mb_strtolower(mdr_content_types()[$w['content_type']] ?? '');
        }
        $h .= '<div class="mdr-warn-meta muted small">' . implode(' · ', $meta) . '</div>';
        if ($w['message'] !== null && $w['message'] !== '') {
            $h .= '<div class="mdr-warn-msg bb">' . bbcode($w['message']) . '</div>';
        }
        if ($w['revoked_at'] !== null) {
            $h .= '<div class="mdr-warn-meta muted small">Снято ' . e(fdate($w['revoked_at']))
                . ($staff && $w['revoker_name'] !== null ? ', ' . e($w['revoker_name']) : '')
                . ($staff && $w['revoke_reason'] ? '. Причина: ' . e($w['revoke_reason']) : '') . '</div>';
        }
        $h .= '</div>';
        if ($active && $canPunish) {
            $h .= '<form method="post" action="' . e(url('/forum/moderation.php')) . '" class="mdr-revoke" data-confirm="Снять это предупреждение? Баллы пересчитаются.">' . csrf_field()
                . '<input type="hidden" name="action" value="warn_revoke"><input type="hidden" name="id" value="' . (int)$w['id'] . '">'
                . '<input class="input mdr-revoke-reason" name="reason" maxlength="255" placeholder="Причина снятия">'
                . '<button class="btn btn-sm btn-ghost" type="submit" title="Снять предупреждение">' . icon('undo') . '<span>Снять</span></button></form>';
        }
        $h .= '</div>';
    }
    $h .= '</section></div>';
    return $h;
}
