<?php
// Действия модуля «Рассмотрение» с темой: взять, отказаться, передать, вынести решение, голос за предложение.
// Только POST с CSRF. Права проверяются в функциях wf_* (app/modules/workflow.php).
require __DIR__ . '/../../app/bootstrap.php';

if (!is_post()) {
    redirect(url('/forum/queue.php'));
}
csrf_check();
$action = input('action');
$ajax = fp_is_ajax();

if (!is_logged()) {
    if ($ajax) {
        json_out(['ok' => false, 'error' => 'Войдите, чтобы продолжить.', 'login' => url('/forum/login.php')]);
    }
    require_login();
}
if (!function_exists('wf_ready') || !wf_ready()) {
    abort(400, 'Модуль рассмотрения ещё не готов: обновите базу в админ-панели.');
}

$thread = thread_get(input_int('thread_id'));
if (!$thread || ($thread['is_deleted'] && !can_moderate())) {
    abort(404, 'Тема не найдена или была удалена.');
}
$node = node_get($thread['node_id']);
if (!$node || !node_can_view($node)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}
$tid = (int)$thread['id'];
$back = safe_return(input('return'), thread_url($tid));

switch ($action) {
    case 'vote':
        list($ok, $err, $data) = wf_vote($thread, $node, input_int('vote'));
        if ($ajax) {
            json_out($ok ? array_merge(['ok' => true], $data) : ['ok' => false, 'error' => $err]);
        }
        if (!$ok) {
            flash('error', $err);
        }
        redirect(thread_url($tid));
        break;

    case 'claim':
        list($ok, $msg) = wf_claim($thread, $node);
        flash($ok ? 'success' : 'error', $msg);
        redirect($back);
        break;

    case 'unclaim':
        list($ok, $msg) = wf_unclaim($thread, $node);
        flash($ok ? 'success' : 'error', $msg);
        redirect($back);
        break;

    case 'transfer':
        list($ok, $msg) = wf_transfer($thread, $node, input_int('to'));
        flash($ok ? 'success' : 'error', $msg);
        redirect($back);
        break;

    case 'verdict':
        $in = [
            'prefix_id' => input_int('prefix_id'),
            'body' => input_text('body'),
            'macro_id' => input_int('macro_id'),
            'lock' => input_bool('lock'),
            'archive' => input_bool('archive'),
        ];
        list($ok, $msg, $to) = wf_verdict($thread, $node, $in);
        flash($ok ? 'success' : 'error', $msg);
        if (!$ok) {
            // Текст не теряется: форма откроется заново с тем же ответом
            $_SESSION['wf_draft'][$tid] = $in;
            redirect(thread_url($tid) . '#wf-verdict');
        }
        redirect($to);
        break;
}
abort(400, 'Неизвестное действие.');
