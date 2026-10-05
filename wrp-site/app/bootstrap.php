<?php
// Загрузка приложения. Каждая страница в public/ начинается с:
//   require __DIR__ . '/../app/bootstrap.php';        (из public/)
//   require __DIR__ . '/../../app/bootstrap.php';     (из public/forum/ и т.п.)

define('WRP_ROOT', dirname(__DIR__));
define('WRP_APP', __DIR__);
define('WRP_PUBLIC', WRP_ROOT . '/public');
define('WRP_STORAGE', WRP_ROOT . '/storage');

mb_internal_encoding('UTF-8');

$configFile = WRP_ROOT . '/config/config.php';
if (is_file($configFile)) {
    $GLOBALS['wrp_config'] = require $configFile;
} else {
    $GLOBALS['wrp_config'] = require WRP_ROOT . '/config/config.example.php';
}

date_default_timezone_set((string)($GLOBALS['wrp_config']['timezone'] ?? 'Europe/Moscow'));

if (!empty($GLOBALS['wrp_config']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', WRP_STORAGE . '/logs/php-error.log');
}

foreach (glob(WRP_APP . '/lib/*.php') as $lib) {
    require_once $lib;
}

// Страница установщика подключает только функции, без сессии и базы
if (defined('WRP_INSTALLER')) {
    return;
}

if (empty($GLOBALS['wrp_config']['installed'])) {
    header('Location: ' . detect_base_path() . '/install/');
    exit;
}

// Заголовки безопасности
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Сессия
session_name('wrp_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim((string)cfg('base_path', ''), '/') . '/',
    'secure' => request_is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

// Ошибки базы показываем аккуратно
set_exception_handler(function ($ex) {
    error_log((string)$ex);
    http_response_code(500);
    if (cfg('debug')) {
        echo '<pre>' . e((string)$ex) . '</pre>';
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Ошибка</title><body style="font-family:sans-serif;background:#0d0b14;color:#eee;padding:40px">'
            . '<h2>Что-то пошло не так</h2><p>Попробуйте обновить страницу. Если ошибка повторяется, сообщите администрации.</p></body>';
    }
    exit;
});

auth_boot();
