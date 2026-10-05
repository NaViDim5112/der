<?php
// Предпросмотр BB-кодов для редактора
require __DIR__ . '/../../app/bootstrap.php';

if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Неверный запрос'], 400);
}
$sent = $_POST['_token'] ?? '';
if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
    json_out(['ok' => false, 'error' => 'Сессия устарела, обновите страницу'], 400);
}
if (!is_logged()) {
    json_out(['ok' => false, 'error' => 'Войдите, чтобы пользоваться предпросмотром'], 403);
}
$body = mb_substr(input_text('body'), 0, 50000);
json_out(['ok' => true, 'html' => bbcode($body)]);
