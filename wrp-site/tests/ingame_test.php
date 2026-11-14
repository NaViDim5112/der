<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка модуля «В игре»: BASE=http://127.0.0.1:8080 php tests/ingame_test.php
// Нужны демо-данные (tests/dev_seed.php), обновление 0004 и в config.php включённый 'game_sso' с secret от 32 символов.
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$fails = 0;
$total = 0;
function t($name, $cond, $extra = '')
{
    global $fails, $total;
    $total++;
    if (!$cond) {
        $fails++;
    }
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . ($cond || $extra === '' ? '' : "\n  " . $extra) . "\n";
}

$base = rtrim((string)getenv('BASE'), '/');
$conf = ingame_sso_conf();
if (!$conf['enabled'] || $base === '') {
    echo "FAIL: нужен BASE=http://... и включённый game_sso в config.php\n";
    exit(1);
}

// Вектор из вики SA-MP: SHA256_PassHash("test", "78sdjs86d2h") - подпись считается так же (данные + ключ)
t('SHA256 как SHA256_PassHash в Pawn', strtoupper(hash('sha256', 'test' . '78sdjs86d2h')) === 'CD16A1C8BF5792B48142FF6B67C9CB5B1BDC7260D8D11AFBA6BCDE0933A3C0AF');
t('пример подписи из docs/INGAME.md', ingame_sso_sign('Ivan_Petrov', 1700000000, 'abc123xyz789', 'laptop', 'example-secret-0123456789abcdefXYZ')
    === strtoupper(hash('sha256', 'wrp-sso|Ivan_Petrov|1700000000|abc123xyz789|laptopexample-secret-0123456789abcdefXYZ')));

$jarDir = sys_get_temp_dir();
$http = function ($jar, $path, array $post = null) use ($base) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
};
$link = function ($nick, $device = 'laptop', $ts = null, $nonce = null, $secret = null) use ($conf) {
    $ts = $ts === null ? time() : $ts;
    $nonce = $nonce === null ? bin2hex(random_bytes(8)) : $nonce;
    $sig = ingame_sso_sign($nick, $ts, $nonce, $device, $secret === null ? $conf['secret'] : $secret);
    return '/forum/game-login.php?' . http_build_query(['nick' => $nick, 'ts' => $ts, 'nonce' => $nonce, 'device' => $device, 'sig' => $sig]);
};

$kuzya = user_by_name('Kuzya_Kabanov');
$roxas = user_by_name('Roxas_Alexandro');
$savedK = $kuzya['game_nick'];
$savedR = $roxas['game_nick'];
db_exec('DELETE FROM rate_limits WHERE action = :a', ['a' => 'game_sso']);
db_update('users', ['game_nick' => 'Kuzya_Kabanov'], 'id = :id', ['id' => (int)$kuzya['id']]);
db_update('users', ['game_nick' => null], 'id = :id', ['id' => (int)$roxas['id']]);
$jars = [];
register_shutdown_function(function () use ($kuzya, $roxas, $savedK, $savedR, &$jars) {
    db_update('users', ['game_nick' => $savedK], 'id = :id', ['id' => (int)$kuzya['id']]);
    db_update('users', ['game_nick' => $savedR], 'id = :id', ['id' => (int)$roxas['id']]);
    db_exec("DELETE FROM game_sso_nonces WHERE nick IN ('Kuzya_Kabanov', 'Test_Player')");
    db_exec('DELETE FROM rate_limits WHERE action = :a', ['a' => 'game_sso']);
    foreach ($jars as $j) {
        @unlink($j);
    }
});
$newJar = function () use (&$jars, $jarDir) {
    $j = tempnam($jarDir, 'ig');
    $jars[] = $j;
    return $j;
};

// Привязанный ник: вход без пароля, режим устройства
$jar = $newJar();
$url = $link('Kuzya_Kabanov', 'tablet');
list($code, $hdr) = $http($jar, $url);
t('вход по ссылке: переход на форум', $code === 302 && preg_match('~Location: [^\r\n]*/forum/~', $hdr), $hdr);
t('режим устройства запомнен', strpos($hdr, 'wrp_device=tablet') !== false);
list($code, , $body) = $http($jar, '/forum/');
t('на форуме вошёл Kuzya_Kabanov', $code === 200 && strpos($body, 'user-pill-name">Kuzya_Kabanov') !== false);
t('компактный вид устройства', strpos($body, 'name="wrp-device" content="tablet"') !== false && strpos($body, 'css/ingame.css') !== false && strpos($body, 'data-ingame-close') !== false);
list($code) = $http($newJar(), $url);
t('повтор той же ссылки не пускает', $code === 400);

// Подделки и устаревшие ссылки
t('чужая подпись', $http($newJar(), $link('Kuzya_Kabanov', 'laptop', null, null, str_repeat('x', 40)))[0] === 400);
t('устаревшая ссылка', $http($newJar(), $link('Kuzya_Kabanov', 'laptop', time() - 600))[0] === 400);
t('ссылка из будущего', $http($newJar(), $link('Kuzya_Kabanov', 'laptop', time() + 600))[0] === 400);
t('неизвестное устройство', $http($newJar(), $link('Kuzya_Kabanov', 'fridge'))[0] === 400);
t('не RP-ник', $http($newJar(), $link('admin', 'laptop'))[0] === 400);
$bad = $link('Kuzya_Kabanov') . '&return=' . rawurlencode('https://evil.example/');
list($code, $hdr) = $http($newJar(), $bad);
t('чужой адрес возврата не используется', $code === 302 && strpos($hdr, 'evil.example') === false, $hdr);

// Непривязанный ник: привяжется после входа
$jar = $newJar();
list($code, , $body) = $http($jar, $link('Test_Player', 'laptop'));
t('непривязанный ник: страница с входом', $code === 200 && strpos($body, 'ещё не привязан') !== false);
list(, , $html) = $http($jar, '/forum/login.php');
preg_match('~name="_token" value="([a-f0-9]+)"~', $html, $m);
list($code) = $http($jar, '/forum/login.php', ['login' => 'Roxas_Alexandro', 'password' => 'test12345', '_token' => $m[1] ?? '']);
t('после входа ник привязан', $code === 302 && (string)db_val('SELECT game_nick FROM users WHERE id = :id', ['id' => (int)$roxas['id']]) === 'Test_Player');
list($code, $hdr) = $http($newJar(), $link('Test_Player', 'pc'));
t('теперь Test_Player входит сразу', $code === 302 && strpos($hdr, 'Location:') !== false && strpos($hdr, 'game-login') === false);

// ?device=off возвращает обычный вид
list(, $hdr) = $http($jar, '/forum/?device=off');
t('device=off снимает режим устройства', strpos($hdr, 'wrp_device=deleted') !== false || preg_match('~wrp_device=;~', $hdr));

// Встраивание: без frame_ancestors - только свой сайт
list(, $hdr) = $http($newJar(), '/forum/');
t('без frame_ancestors - X-Frame-Options SAMEORIGIN', stripos($hdr, 'X-Frame-Options: SAMEORIGIN') !== false);

echo "\n" . ($fails ? "FAILED: $fails из $total\n" : "ALL OK ($total)\n");
exit($fails ? 1 : 0);
