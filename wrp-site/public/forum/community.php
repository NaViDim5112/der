<?php
// Действия модуля «Сообщество» без своей страницы: закрыть объявление-плашку.
require __DIR__ . '/../../app/bootstrap.php';

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
if (!is_post()) {
    abort(400, 'Неверный запрос.');
}
$sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
    if ($ajax) {
        json_out(['ok' => false, 'error' => 'Сессия устарела. Обновите страницу.'], 400);
    }
    abort(400, 'Сессия устарела. Обновите страницу и попробуйте ещё раз.');
}

$do = input('do');
if ($do === 'notice_dismiss') {
    $ok = com_notice_dismiss(input_int('id'));
    if ($ajax) {
        json_out(['ok' => $ok]);
    }
    redirect(safe_return(input('return'), url('/forum/')));
}

if ($ajax) {
    json_out(['ok' => false, 'error' => 'Неизвестное действие.'], 400);
}
abort(400, 'Неизвестное действие.');
