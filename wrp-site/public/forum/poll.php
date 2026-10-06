<?php
// Опрос в теме: голос, закрытие и открытие, правка (пока нет голосов), удаление.
// Из JS (X-Requested-With) ответ JSON с новым HTML блока опроса, без JS - переход обратно в тему.
require __DIR__ . '/../../app/bootstrap.php';

if (!is_post()) {
    abort(405, 'Неверный запрос.');
}
$ajax = fp_is_ajax();
$fail = function ($msg, $code = 400, $back = null) use ($ajax) {
    if ($ajax) {
        cnt_json_fail($msg, $code);
    }
    flash('error', $msg);
    redirect($back ?: fp_referer());
};
if (!cnt_csrf_ok()) {
    $fail('Сессия устарела. Обновите страницу и попробуйте ещё раз.');
}
if (!cnt_ready()) {
    $fail('Опросы пока недоступны: администратору нужно обновить базу.', 503);
}
if (!is_logged()) {
    if ($ajax) {
        cnt_json_fail('Войдите, чтобы голосовать.', 401, ['login' => url('/forum/login.php', ['return' => fp_referer()])]);
    }
    redirect(url('/forum/login.php', ['return' => fp_referer()]));
}

$poll = cnt_poll_get(input_int('poll_id'));
$thread = $poll ? thread_get($poll['thread_id']) : null;
$node = $thread ? node_get($thread['node_id']) : null;
if (!$poll || !$thread || !$node || !node_can_view($node) || ($thread['is_deleted'] && !can_moderate())) {
    $fail('Опрос не найден.', 404);
}
$back = thread_url($thread['id']) . '#poll';
$action = input('action');
$msg = '';

if ($action === 'vote') {
    $denied = cnt_poll_vote_denied($poll, $thread, $node);
    if ($denied !== '') {
        $fail($denied === 'guest' ? 'Войдите, чтобы голосовать.' : $denied, 403, $back);
    }
    if (!rate_ok('poll_vote', 12, 60)) {
        $fail('Слишком часто. Подождите минуту и попробуйте снова.', 429, $back);
    }
    rate_hit('poll_vote');
    $raw = $_POST['options'] ?? [];
    $ids = [];
    foreach (is_array($raw) ? $raw : [$raw] as $v) {
        if (is_numeric($v)) {
            $ids[] = (int)$v;
        }
    }
    $err = cnt_poll_vote($poll, array_slice($ids, 0, 20), uid());
    if ($err !== '') {
        $fail($err, 400, $back);
    }
    $msg = 'Ваш голос учтён.';
} elseif ($action === 'close' || $action === 'open') {
    if (!cnt_poll_can_manage($poll, $thread)) {
        $fail('Управлять опросом могут автор темы и модераторы.', 403, $back);
    }
    $close = $action === 'close';
    $data = ['is_closed' => $close ? 1 : 0];
    if (!$close && $poll['close_at'] && strtotime($poll['close_at']) <= time()) {
        $data['close_at'] = null;
    }
    db_update('polls', $data, 'id = :id', ['id' => (int)$poll['id']]);
    if (can_moderate() && (int)$thread['user_id'] !== uid()) {
        mod_log($close ? 'poll_close' : 'poll_open', 'poll', $thread['id'], $poll['question']);
    }
    $msg = $close ? 'Опрос закрыт.' : 'Опрос снова открыт.';
} elseif ($action === 'edit') {
    if (!cnt_poll_can_manage($poll, $thread)) {
        $fail('Управлять опросом могут автор темы и модераторы.', 403, $back);
    }
    if ((int)db_val('SELECT COUNT(*) FROM poll_votes WHERE poll_id = :p', ['p' => (int)$poll['id']]) > 0) {
        $fail('В опросе уже есть голоса, менять варианты нельзя.', 409, $back);
    }
    $d = cnt_poll_input($_POST['poll'] ?? []);
    $err = cnt_poll_error($d);
    if ($err !== '') {
        $fail($err, 400, $back);
    }
    db_update('polls', [
        'question' => $d['question'],
        'is_multiple' => $d['multiple'] ? 1 : 0,
        'public_results' => $d['public'] ? 1 : 0,
        'close_at' => $d['close_days'] > 0 ? date('Y-m-d H:i:s', time() + $d['close_days'] * 86400) : null,
    ], 'id = :id', ['id' => (int)$poll['id']]);
    cnt_poll_save_options($poll['id'], $d['options']);
    if (can_moderate() && (int)$thread['user_id'] !== uid()) {
        mod_log('poll_edit', 'poll', $thread['id'], $d['question']);
    }
    $msg = 'Опрос сохранён.';
} elseif ($action === 'delete') {
    if (!can_moderate()) {
        $fail('Удалять опросы могут только модераторы.', 403, $back);
    }
    db_exec('DELETE FROM poll_votes WHERE poll_id = :p', ['p' => (int)$poll['id']]);
    db_exec('DELETE FROM poll_options WHERE poll_id = :p', ['p' => (int)$poll['id']]);
    db_exec('DELETE FROM polls WHERE id = :p', ['p' => (int)$poll['id']]);
    mod_log('poll_delete', 'poll', $thread['id'], $poll['question']);
    if ($ajax) {
        json_out(['ok' => true, 'html' => '', 'message' => 'Опрос удалён.']);
    }
    flash('success', 'Опрос удалён.');
    redirect(thread_url($thread['id']));
} else {
    $fail('Неизвестное действие.', 400, $back);
}

if ($ajax) {
    $poll = cnt_poll_get($poll['id']);
    json_out(['ok' => true, 'message' => $msg, 'html' => cnt_poll_html($poll, $thread, $node, $msg)]);
}
flash('success', $msg);
redirect($back);
