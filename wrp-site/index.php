<?php
// Запасной вход для серверов без mod_rewrite: домен смотрит в корень проекта,
// поэтому перенаправляем посетителя в папку public/, где лежит сам сайт.
// Правильнее направить домен прямо в public/ (OpenServer 6: .osp/project.ini, public_dir).
// Если сайт работает через этот файл, в config.php укажите base_path с /public на конце,
// например '/public' или '/wrp/public' (установщик подскажет).
$dir = rtrim(str_replace('\\', '/', dirname((string)(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php'))), '/');
header('Location: ' . $dir . '/public/', true, 302);
exit;
