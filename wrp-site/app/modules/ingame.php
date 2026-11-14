<?php
// Модуль «В игре»: форум на игровых устройствах (ноутбук, компьютер, планшет в доме), которые открывают сайт
// во встроенном браузере игры (CEF). Описание для мода и лаунчера - docs/INGAME.md.
//
// - Режим устройства: ?device=laptop|pc|tablet|phone запоминается до закрытия браузера (cookie wrp_device),
//   ?device=off - обычный сайт. В режиме устройства нет подвала и фоновых эффектов, есть кнопка «Закрыть».
// - Вход без пароля: /forum/game-login.php со ссылкой, которую подписал сервер игры (ingame_sso_*).
//   Если игровой ник ещё не привязан к форуму, он привяжется после входа или регистрации.

function ingame_devices()
{
    return ['laptop' => 'Ноутбук', 'pc' => 'Компьютер', 'tablet' => 'Планшет', 'phone' => 'Телефон'];
}

// Текущее устройство: '' - обычный сайт
function ingame_device()
{
    if (array_key_exists('wrp_device', $GLOBALS)) {
        return $GLOBALS['wrp_device'];
    }
    $c = $_COOKIE['wrp_device'] ?? '';
    return $GLOBALS['wrp_device'] = is_string($c) && isset(ingame_devices()[$c]) ? $c : '';
}

function ingame_device_set($device)
{
    if ($device === '' || !isset(ingame_devices()[$device])) {
        cookie_set('wrp_device', '', time() - 3600);
        $GLOBALS['wrp_device'] = '';
        return;
    }
    cookie_set('wrp_device', $device, 0);
    $GLOBALS['wrp_device'] = $device;
}

// ?device=... в любой ссылке включает или выключает режим устройства
hook_add('request_start', function () {
    $q = $_GET['device'] ?? null;
    if (!is_string($q) || $q === '') {
        return;
    }
    ingame_device_set($q === 'off' ? '' : $q);
});

hook_add('page_head', function ($area, $o) {
    $d = ingame_device();
    if ($d === '') {
        return '';
    }
    return '<meta name="wrp-device" content="' . e($d) . '">'
        . '<script>document.documentElement.classList.add("device-mode","device-' . e($d) . '");</script>'
        . '<link rel="stylesheet" href="' . e(asset('css/ingame.css')) . '">';
});

hook_add('page_footer', function ($area, $o) {
    if (ingame_device() === '') {
        return '';
    }
    return '<button class="ingame-close" type="button" data-ingame-close title="Закрыть и вернуться в игру">' . icon('x') . '<span>Закрыть</span></button>';
});

hook_add('page_scripts', function ($area, $o) {
    if (ingame_device() === '') {
        return '';
    }
    return '<script src="' . e(asset('js/ingame.js')) . '"></script>';
});

// ---------- Вход с игрового устройства ----------

function ingame_sso_conf()
{
    $c = (array)cfg('game_sso', []);
    $secret = (string)($c['secret'] ?? '');
    return [
        'enabled' => !empty($c['enabled']) && strlen($secret) >= 32,
        'secret' => $secret,
        'max_age' => max(30, min(600, (int)($c['max_age'] ?? 120))),
    ];
}

// Подпись ссылки: SHA256("wrp-sso|ник|время|nonce|устройство" . secret), HEX заглавными.
// В Pawn это SHA256_PassHash(данные, secret, результат).
function ingame_sso_sign($nick, $ts, $nonce, $device, $secret)
{
    return strtoupper(hash('sha256', 'wrp-sso|' . $nick . '|' . (int)$ts . '|' . $nonce . '|' . $device . $secret));
}

// Разбор и проверка ссылки. Возвращает [ник, устройство, адрес возврата] или строку с ошибкой.
function ingame_sso_verify(array $q)
{
    $conf = ingame_sso_conf();
    if (!$conf['enabled']) {
        return 'Вход из игры выключен в настройках сайта.';
    }
    $nick = (string)($q['nick'] ?? '');
    $ts = (string)($q['ts'] ?? '');
    $nonce = (string)($q['nonce'] ?? '');
    $device = (string)($q['device'] ?? '');
    $sig = (string)($q['sig'] ?? '');
    if (!preg_match('~^[A-Za-z]{2,20}_[A-Za-z]{2,20}$~', $nick) || !preg_match('~^\d{9,11}$~', $ts)
        || !preg_match('~^[A-Za-z0-9]{8,64}$~', $nonce) || !preg_match('~^[A-Fa-f0-9]{64}$~', $sig)
        || ($device !== '' && !isset(ingame_devices()[$device]))) {
        return 'Ссылка повреждена. Откройте форум с устройства в игре ещё раз.';
    }
    if (!hash_equals(ingame_sso_sign($nick, $ts, $nonce, $device, $conf['secret']), strtoupper($sig))) {
        return 'Ссылка не прошла проверку. Откройте форум с устройства в игре ещё раз.';
    }
    if (abs(time() - (int)$ts) > $conf['max_age']) {
        return 'Ссылка устарела. Откройте форум с устройства в игре ещё раз.';
    }
    // Одноразовая: вторая попытка с тем же nonce не пройдёт
    if (!db_exec('INSERT IGNORE INTO game_sso_nonces (nonce, nick, created_at) VALUES (:n, :k, :t)', ['n' => $nonce, 'k' => $nick, 't' => now()])) {
        return 'Эта ссылка уже использована. Откройте форум с устройства в игре ещё раз.';
    }
    return [$nick, $device, safe_return($q['return'] ?? '', url('/forum/'))];
}

// Привязка игрового ника, который подтвердил сервер игры, к форумному аккаунту
function ingame_link_nick($userId, $nick)
{
    $u = user_by_id($userId);
    if (!$u) {
        return 'Пользователь не найден.';
    }
    if ((string)$u['game_nick'] !== '') {
        return strcasecmp($u['game_nick'], $nick) === 0 ? '' : 'Ваш аккаунт форума уже привязан к нику ' . $u['game_nick'] . '.';
    }
    if (db_val('SELECT id FROM users WHERE game_nick = :n', ['n' => $nick])) {
        return 'Ник ' . $nick . ' уже привязан к другому аккаунту форума.';
    }
    db_update('users', ['game_nick' => $nick], 'id = :id', ['id' => (int)$userId]);
    if (uid() === (int)$userId) {
        $GLOBALS['wrp_user'] = user_by_id($userId);
        mod_log('game_link', 'user', $userId, $nick);
    }
    return '';
}

// После входа или регистрации привязываем ник, подтверждённый игрой (ссылка открыта, но ник ещё не был привязан)
hook_add('user_logged_in', function ($u) {
    $p = $_SESSION['ingame_link'] ?? null;
    if (!$u || !is_array($p)) {
        return;
    }
    unset($_SESSION['ingame_link']);
    if ((int)($p['until'] ?? 0) < time()) {
        return;
    }
    $err = ingame_link_nick((int)$u['id'], (string)$p['nick']);
    flash($err === '' ? 'success' : 'error', $err === '' ? 'Игровой аккаунт ' . $p['nick'] . ' привязан к форуму. Теперь с игровых устройств вход будет без пароля.' : $err);
});

cron_register('ingame_sso_cleanup', 3600, function () {
    try {
        db_exec('DELETE FROM game_sso_nonces WHERE created_at < :t', ['t' => date('Y-m-d H:i:s', time() - 86400)]);
    } catch (Exception $e) {
        // обновление базы 0004 ещё не применено
    }
});

hook_add('modlog_labels', function ($l) {
    $l['game_link'] = 'Привязка игрового ника (вход из игры)';
    return $l;
});
