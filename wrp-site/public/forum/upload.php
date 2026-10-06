<?php
// Загрузка картинки из редактора (вложение). Только POST, ответ JSON.
// Поля: file - картинка, _token или заголовок X-CSRF-Token.
require __DIR__ . '/../../app/bootstrap.php';

header('Cache-Control: no-store');
if (!is_post()) {
    cnt_json_fail('Неверный запрос.', 405);
}

// Файл больше post_max_size: PHP отбрасывает всё тело запроса, в том числе токен - сообщаем понятно
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = acc_ini_bytes(ini_get('post_max_size'));
if ($postMax > 0 && $len > $postMax) {
    cnt_json_fail('Файл слишком большой. Максимум ' . acc_bytes_text(cnt_attach_max_bytes()) . '.', 413);
}
if (!cnt_csrf_ok()) {
    cnt_json_fail('Сессия устарела. Обновите страницу и попробуйте ещё раз.', 400);
}
$denied = cnt_attach_denied();
if ($denied !== '') {
    cnt_json_fail($denied, is_logged() ? 403 : 401);
}
if (!rate_ok('attach_upload', 40, 600)) {
    cnt_json_fail('Слишком много загрузок подряд. Подождите несколько минут.', 429);
}
rate_hit('attach_upload');

$max = cnt_attach_max_bytes();
$error = '';
$tmp = acc_upload_take('file', $max, $error);
if ($tmp === '') {
    cnt_json_fail($error);
}
$row = cnt_attach_create(uid(), $tmp, $error, is_staff());
if (!$row) {
    cnt_json_fail($error);
}
json_out(['ok' => true] + cnt_attach_public($row));
