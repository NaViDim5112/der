<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка почты: BASE=http://127.0.0.1:8080 php tests/mail_test.php
// Нужны демо-данные, обновление 0007 и в config.php: 'mail' => ['enabled' => true, 'transport' => 'log', 'from' => '...'], debug => true.
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
$conf = mail_conf();
if ($base === '' || !mail_enabled() || $conf['transport'] !== 'log' || !mail_links_ok()) {
    echo "FAIL: нужен BASE=http://... и в config.php почта с transport 'log' (и debug или site_url)\n";
    exit(1);
}
$log = WRP_STORAGE . '/logs/mail.log';
$logStart = is_file($log) ? filesize($log) : 0;
$lastLink = function ($path) use ($log, &$logStart) {
    clearstatcache();
    $s = (string)@file_get_contents($log, false, null, $logStart);
    preg_match_all('~https?://[^\s]+' . preg_quote($path, '~') . '\?token=([a-f0-9]{64})~', $s, $m);
    return $m[1] ? end($m[1]) : '';
};
$jars = [];
$newJar = function () use (&$jars) {
    $j = tempnam(sys_get_temp_dir(), 'ml');
    $jars[] = $j;
    return $j;
};
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
$token = function ($html) {
    preg_match('~name="_token" value="([a-f0-9]+)"~', $html, $m);
    return $m[1] ?? '';
};

// Заголовки письма не ломаются переводами строк
t('кодирование темы письма', mail_header_value("Тема\r\nBcc: x@y") === '=?UTF-8?B?' . base64_encode('Тема  Bcc: x@y') . '?=');
t('неверный адрес не принимается', !mail_send("a@b.ru\r\nBcc: c@d.ru", 'x', 'y') && !mail_send('not-an-email', 'x', 'y'));

$kuzya = user_by_name('Kuzya_Kabanov');
$savedHash = $kuzya['password_hash'];
$savedEmailConfirm = (string)setting('email_confirm');
db_exec('DELETE FROM rate_limits');
db_exec('DELETE FROM user_tokens WHERE user_id = :u', ['u' => (int)$kuzya['id']]);
register_shutdown_function(function () use ($kuzya, $savedHash, $savedEmailConfirm, &$jars) {
    db_update('users', ['password_hash' => $savedHash], 'id = :id', ['id' => (int)$kuzya['id']]);
    db_exec('DELETE FROM user_tokens WHERE user_id = :u', ['u' => (int)$kuzya['id']]);
    $ids = array_map('intval', array_column(db_all("SELECT id FROM users WHERE username LIKE 'qamail%'"), 'id'));
    foreach ($ids as $id) {
        db_exec('DELETE FROM user_tokens WHERE user_id = :u', ['u' => $id]);
        db_exec('DELETE FROM online WHERE user_id = :u', ['u' => $id]);
        db_exec('DELETE FROM com_user_trophies WHERE user_id = :u', ['u' => $id]);
        db_exec('DELETE FROM alerts WHERE user_id = :u', ['u' => $id]);
        db_exec('DELETE FROM users WHERE id = :u', ['u' => $id]);
    }
    setting_set('email_confirm', $savedEmailConfirm);
    db_exec('DELETE FROM rate_limits');
    foreach ($jars as $j) {
        @unlink($j);
    }
});

// ---------- Восстановление пароля ----------
$jar = $newJar();
list(, , $html) = $http($jar, '/forum/lost-password.php');
t('на странице есть восстановление по email', strpos($html, 'name="method" value="email"') !== false);
list($code) = $http($jar, '/forum/lost-password.php', ['_token' => $token($html), 'method' => 'email', 'login' => 'Kuzya_Kabanov']);
$raw = $lastLink('/forum/reset-password.php');
t('письмо со ссылкой отправлено', $code === 302 && $raw !== '');
list(, , $html) = $http($jar, '/forum/lost-password.php');
list($code, , ) = $http($jar, '/forum/lost-password.php', ['_token' => $token($html), 'method' => 'email', 'login' => 'nobody@nowhere.test']);
t('для неизвестного адреса ответ тот же (302)', $code === 302);
list($code, $hdr) = $http($jar, '/forum/reset-password.php?token=' . $raw);
t('ссылка убирается из адреса', $code === 302 && preg_match('~Location: [^\r\n]*reset-password\.php\r?\n~', $hdr), $hdr);
list($code, , $html) = $http($jar, '/forum/reset-password.php');
t('форма нового пароля', $code === 200 && strpos($html, 'name="password2"') !== false);
list($code) = $http($jar, '/forum/reset-password.php', ['_token' => $token($html), 'password' => 'NewPass12345', 'password2' => 'NewPass12345']);
t('пароль сменён', $code === 302 && password_verify('NewPass12345', (string)db_val('SELECT password_hash FROM users WHERE id = :id', ['id' => (int)$kuzya['id']])));
list($code) = $http($newJar(), '/forum/reset-password.php?token=' . $raw);
t('повторно ссылка не работает', $code === 302 && !user_token_find($raw, 'reset'));
$j2 = $newJar();
list(, , $html) = $http($j2, '/forum/login.php');
list($code) = $http($j2, '/forum/login.php', ['_token' => $token($html), 'login' => 'Kuzya_Kabanov', 'password' => 'NewPass12345']);
t('вход с новым паролем', $code === 302);
t('чужой токен не подходит', user_token_find(str_repeat('a', 64), 'reset') === null && user_token_find('../../x', 'reset') === null);

// ---------- Подтверждение email при регистрации ----------
setting_set('email_confirm', '1');
$jar = $newJar();
list(, , $html) = $http($jar, '/forum/register.php');
preg_match('~Сколько будет (\d+) ([+-]) (\d+)\?~u', $html, $q);
$ans = $q ? ($q[2] === '+' ? $q[1] + $q[3] : $q[1] - $q[3]) : 0;
$name = 'qamail' . substr(bin2hex(random_bytes(3)), 0, 5);
list($code, $hdr) = $http($jar, '/forum/register.php', ['_token' => $token($html), 'username' => $name, 'email' => $name . '@wrp.test',
    'password' => 'QaMail12345', 'password2' => 'QaMail12345', 'captcha' => $ans, 'agree' => 1, 'website' => '']);
$newU = user_by_name($name);
t('регистрация: аккаунт ждёт подтверждения', $code === 302 && strpos($hdr, 'confirm-email.php') !== false && $newU && (int)$newU['email_confirmed'] === 0, $hdr);
list($code, , $body) = $http($jar, '/forum/');
t('без подтверждения не вошёл', strpos($body, 'user-pill-name">' . $name) === false);
$j3 = $newJar();
list(, , $html) = $http($j3, '/forum/login.php');
list($code, $hdr) = $http($j3, '/forum/login.php', ['_token' => $token($html), 'login' => $name, 'password' => 'QaMail12345']);
t('вход до подтверждения ведёт на страницу подтверждения', $code === 302 && strpos($hdr, 'confirm-email.php') !== false, $hdr);
$raw = $lastLink('/forum/confirm-email.php');
list($code) = $http($j3, '/forum/confirm-email.php?token=' . $raw);
list(, , $body) = $http($j3, '/forum/');
t('ссылка из письма подтверждает и входит', $raw !== '' && (int)db_val('SELECT email_confirmed FROM users WHERE id = :id', ['id' => (int)$newU['id']]) === 1
    && strpos($body, 'user-pill-name">' . $name) !== false);
setting_set('email_confirm', '0');

echo "\n" . ($fails ? "FAILED: $fails из $total\n" : "ALL OK ($total)\n");
exit($fails ? 1 : 0);
