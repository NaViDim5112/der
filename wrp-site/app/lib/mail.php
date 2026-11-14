<?php
// Почта сайта: восстановление пароля по email и подтверждение email при регистрации.
// Настройка - config.php, блок 'mail' (README, раздел «Почта»):
//   'transport' => 'smtp' - через почтовый ящик (Яндекс, Mail.ru, Gmail: пароль приложения),
//                  'mail' - функция mail() PHP (как настроена на сервере),
//                  'log'  - письма пишутся в storage/logs/mail.log (проверка на своём компьютере).

function mail_conf()
{
    $c = (array)cfg('mail', []);
    $smtp = (array)($c['smtp'] ?? []);
    $transport = (string)($c['transport'] ?? 'smtp');
    $secure = (string)($smtp['secure'] ?? 'ssl');
    return [
        'enabled' => !empty($c['enabled']),
        'transport' => in_array($transport, ['smtp', 'mail', 'log'], true) ? $transport : 'smtp',
        'from' => trim((string)($c['from'] ?? '')),
        'from_name' => (string)($c['from_name'] ?? setting('site_name')),
        'host' => trim((string)($smtp['host'] ?? '')),
        'port' => (int)($smtp['port'] ?? 465),
        'secure' => in_array($secure, ['ssl', 'tls', ''], true) ? $secure : 'ssl',
        'user' => (string)($smtp['user'] ?? ''),
        'pass' => (string)($smtp['pass'] ?? ''),
    ];
}

function mail_enabled()
{
    $c = mail_conf();
    return $c['enabled'] && filter_var($c['from'], FILTER_VALIDATE_EMAIL) && ($c['transport'] !== 'smtp' || $c['host'] !== '');
}

// Ссылки в письмах строятся только от адреса сайта из config.php ('site_url'): иначе подменённый заголовок Host
// мог бы увести ссылку сброса пароля на чужой сайт. Для проверки на своём компьютере хватает debug.
function mail_links_ok()
{
    return site_url_configured() || cfg('debug');
}

// Последняя ошибка отправки (для кнопки «Проверить почту» в админке)
function mail_last_error($set = null)
{
    if ($set !== null) {
        $GLOBALS['wrp_mail_error'] = (string)$set;
    }
    return (string)($GLOBALS['wrp_mail_error'] ?? '');
}

function mail_header_value($s)
{
    $s = trim(str_replace(["\r", "\n"], ' ', (string)$s));
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

// Отправить письмо (простой текст). Возвращает true/false, причину - mail_last_error().
function mail_send($to, $subject, $text)
{
    mail_last_error('');
    $c = mail_conf();
    $to = trim((string)$to);
    if (!mail_enabled()) {
        mail_last_error('Почта не настроена в config.php.');
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n<>]/', $to)) {
        mail_last_error('Неверный адрес получателя.');
        return false;
    }
    $domain = substr((string)strrchr($c['from'], '@'), 1) ?: 'localhost';
    $headers = [
        'From: ' . mail_header_value($c['from_name']) . ' <' . $c['from'] . '>',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
    ];
    $body = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", (string)$text)), 76, "\r\n"));
    $subj = mail_header_value($subject);
    try {
        if ($c['transport'] === 'log') {
            $entry = '==== ' . date('Y-m-d H:i:s') . "\nTo: " . $to . "\nSubject: " . $subject . "\n\n" . $text . "\n\n";
            if (@file_put_contents(WRP_STORAGE . '/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Не удалось записать storage/logs/mail.log.');
            }
            return true;
        }
        if ($c['transport'] === 'mail') {
            if (!@mail($to, $subj, $body, implode("\r\n", $headers))) {
                throw new RuntimeException('Функция mail() не смогла отправить письмо (проверьте настройки почты сервера).');
            }
            return true;
        }
        return mail_smtp($c, $to, 'To: <' . $to . ">\r\nSubject: " . $subj . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
    } catch (Exception $e) {
        mail_last_error($e->getMessage());
        error_log('[mail] ' . $e->getMessage());
        return false;
    }
}

// Минимальный SMTP-клиент: SSL (порт 465) или STARTTLS (587), вход AUTH LOGIN
function mail_smtp(array $c, $to, $data)
{
    $remote = ($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . ($c['port'] ?: 465);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException('Нет соединения с почтовым сервером ' . $c['host'] . ': ' . $errstr);
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) {
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $resp;
    };
    $cmd = function ($line, array $expect, $what) use ($fp, $read) {
        if ($line !== null) {
            fwrite($fp, $line . "\r\n");
        }
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $expect, true)) {
            throw new RuntimeException('Почтовый сервер отказал (' . $what . '): ' . trim(mb_substr($r, 0, 200)));
        }
        return $r;
    };
    try {
        $ehlo = parse_url(site_origin(), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, [220], 'приветствие');
        $cmd('EHLO ' . $ehlo, [250], 'EHLO');
        if ($c['secure'] === 'tls') {
            $cmd('STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Не удалось включить шифрование (STARTTLS).');
            }
            $cmd('EHLO ' . $ehlo, [250], 'EHLO');
        }
        if ($c['user'] !== '') {
            $cmd('AUTH LOGIN', [334], 'вход');
            $cmd(base64_encode($c['user']), [334], 'логин');
            $cmd(base64_encode($c['pass']), [235], 'пароль: проверьте логин и пароль приложения');
        }
        $cmd('MAIL FROM:<' . $c['from'] . '>', [250], 'отправитель');
        $cmd('RCPT TO:<' . $to . '>', [250, 251], 'получатель');
        $cmd('DATA', [354], 'DATA');
        fwrite($fp, preg_replace('/^\./m', '..', $data) . "\r\n.\r\n");
        $cmd(null, [250], 'письмо');
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
    return true;
}

// ---------- Одноразовые ссылки из писем ----------

// Новая ссылка: старые неиспользованные того же назначения удаляются. Возвращает токен для ссылки.
function user_token_create($userId, $purpose, $ttlSeconds)
{
    db_exec('DELETE FROM user_tokens WHERE user_id = :u AND purpose = :p AND used_at IS NULL', ['u' => (int)$userId, 'p' => $purpose]);
    $raw = bin2hex(random_bytes(32));
    db_insert('user_tokens', [
        'user_id' => (int)$userId,
        'purpose' => $purpose,
        'token_hash' => hash('sha256', $raw),
        'created_at' => now(),
        'expires_at' => date('Y-m-d H:i:s', time() + (int)$ttlSeconds),
    ]);
    return $raw;
}

// Действующая ссылка по токену (или по хэшу из сессии)
function user_token_find($raw, $purpose, $isHash = false)
{
    $raw = (string)$raw;
    if (!preg_match('~^[a-f0-9]{64}$~', $raw)) {
        return null;
    }
    return db_one('SELECT * FROM user_tokens WHERE token_hash = :h AND purpose = :p AND used_at IS NULL AND expires_at > :n',
        ['h' => $isHash ? $raw : hash('sha256', $raw), 'p' => $purpose, 'n' => now()]);
}

function user_token_use(array $row)
{
    return (bool)db_exec('UPDATE user_tokens SET used_at = :n WHERE id = :id AND used_at IS NULL', ['n' => now(), 'id' => (int)$row['id']]);
}

// Когда последний раз выдавали ссылку этого назначения (чтобы не слать письма чаще раза в 5 минут)
function user_token_recent($userId, $purpose, $seconds)
{
    return (bool)db_val('SELECT 1 FROM user_tokens WHERE user_id = :u AND purpose = :p AND created_at > :t LIMIT 1',
        ['u' => (int)$userId, 'p' => $purpose, 't' => date('Y-m-d H:i:s', time() - (int)$seconds)]);
}

// ---------- Подтверждение email ----------

function email_confirm_required()
{
    return setting('email_confirm') === '1' && mail_enabled() && mail_links_ok();
}

// Обновление базы с колонкой email_confirmed применено
function email_confirm_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db_val('SELECT email_confirmed FROM users LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

function email_unconfirmed($u)
{
    return $u && array_key_exists('email_confirmed', $u) && (int)$u['email_confirmed'] === 0;
}

// Письмо со ссылкой подтверждения
function email_confirm_send(array $u)
{
    $raw = user_token_create($u['id'], 'confirm', 3 * 86400);
    $site = (string)setting('site_name');
    $link = site_origin() . url('/forum/confirm-email.php', ['token' => $raw]);
    return mail_send($u['email'], 'Подтверждение почты - ' . $site,
        "Здравствуйте, " . $u['username'] . "!\n\nВы зарегистрировались на форуме " . $site . ". Чтобы подтвердить почту и войти, откройте ссылку (действует 3 дня):\n"
        . $link . "\n\nЕсли вы не регистрировались, просто удалите это письмо.\n\n" . $site);
}

// Пока почта не подтверждена, войти нельзя (так же, как на форумах проектов: защита от ботов и чужих адресов)
// Если подтверждение выключили (или почта перестала работать) - ждущие подтверждения входят как обычно.
hook_add('login_intercept', function ($u, $remember, $return) {
    if (!email_unconfirmed($u) || !email_confirm_required()) {
        return null;
    }
    $_SESSION['confirm_uid'] = ['id' => (int)$u['id'], 'until' => time() + 1800];
    return url('/forum/confirm-email.php');
}, 1);
