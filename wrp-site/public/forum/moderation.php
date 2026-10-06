<?php
// Действия модераторов модуля «Модерация» (только POST): запрет ответов в теме, снятие запрета, снятие предупреждения
require __DIR__ . '/../../app/bootstrap.php';
require_moderator();
require_post();
mdr_require_ready();

$action = input('action');

if ($action === 'replyban') {
    $thread = thread_get(input_int('thread_id'));
    $node = $thread ? node_get($thread['node_id']) : null;
    if (!$thread || !$node) {
        abort(404, 'Тема не найдена.');
    }
    $back = thread_url($thread['id']);
    $target = user_by_name(input('username'));
    $err = $target ? mdr_punish_error($target) : 'Пользователь с таким ником не найден.';
    $days = input_int('days', -1);
    $reason = input('reason');
    if ($err === '' && !array_key_exists($days, mdr_replyban_terms())) {
        $err = 'Выберите срок.';
    }
    if ($err === '' && mb_strlen($reason) > 255) {
        $err = 'Причина слишком длинная (не больше 255 символов).';
    }
    if ($err !== '') {
        flash('error', $err);
        redirect($back);
    }
    $id = mdr_replyban_add((int)$thread['id'], (int)$target['id'], $days, $reason);
    $until = (string)db_val('SELECT expires_at FROM mod_reply_bans WHERE id = :id', ['id' => $id]);
    if (fp_user_can_see($target, $node, $thread)) {
        alert_add((int)$target['id'], 'mod_replyban', uid(), (int)$thread['id'], null, $until);
    }
    flash('success', $target['username'] . ' больше не может отвечать в этой теме ' . ($until !== '' ? 'до ' . fdate($until) : 'без срока') . '.');
    redirect($back);
}

if ($action === 'replyunban') {
    $b = db_one('SELECT * FROM mod_reply_bans WHERE id = :id', ['id' => input_int('id')]);
    if (!$b) {
        flash('error', 'Запрет уже снят.');
        redirect(fp_referer(url('/forum/')));
    }
    mdr_replyban_remove($b);
    flash('success', 'Запрет ответов снят.');
    redirect($b['thread_id'] ? thread_url($b['thread_id']) : url('/forum/member.php', ['id' => (int)$b['user_id'], 'tab' => 'warnings']));
}

if ($action === 'warn_revoke') {
    $w = db_one('SELECT * FROM mod_warnings WHERE id = :id', ['id' => input_int('id')]);
    if (!$w) {
        abort(404, 'Предупреждение не найдено.');
    }
    $back = url('/forum/member.php', ['id' => (int)$w['user_id'], 'tab' => 'warnings']);
    $target = user_by_id($w['user_id']);
    $err = mdr_punish_error($target);
    if ($err !== '') {
        flash('error', $err);
        redirect($back);
    }
    if (mb_strlen(input('reason')) > 255) {
        flash('error', 'Причина слишком длинная (не больше 255 символов).');
        redirect($back);
    }
    list($ok, $msg) = mdr_warn_revoke($w, input('reason'));
    flash($ok ? 'success' : 'error', $msg);
    redirect($back . '#warning-' . (int)$w['id']);
}

abort(400, 'Неизвестное действие.');
