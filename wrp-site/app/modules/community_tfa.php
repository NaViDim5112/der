<?php
// Сообщество: двухфакторная защита (TOTP по RFC 6238: SHA1, 6 цифр, шаг 30 секунд) и резервные коды.
// Секрет хранится зашифрованным (com_encrypt, ключ из config.php -> secret, привязан к id пользователя).
// Резервные коды хранятся только как HMAC-SHA256, показываются один раз.

const COM_TFA_PERIOD = 30;
const COM_TFA_DIGITS = 6;
const COM_TFA_BACKUP_COUNT = 10;
const COM_TFA_PENDING_SECONDS = 300;

// ---------- Base32 (RFC 4648) ----------

function com_b32_encode($bin)
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

// Двоичная строка или false. Пробелы, дефисы и «=» в конце не мешают, регистр не важен.
function com_b32_decode($s)
{
    $s = strtoupper(preg_replace('~[\s\-]+~', '', (string)$s));
    $s = rtrim($s, '=');
    if ($s === '' || !preg_match('~^[A-Z2-7]+$~', $s)) {
        return false;
    }
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(strpos($alpha, $s[$i])), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

// ---------- TOTP ----------

// Код для шага $counter (HOTP, RFC 4226). $algo: sha1 / sha256 / sha512.
function com_totp_code($secretBin, $counter, $digits = COM_TFA_DIGITS, $algo = 'sha1')
{
    $counter = (int)$counter;
    $msg = PHP_INT_SIZE >= 8 ? pack('J', $counter) : pack('N2', 0, $counter);
    $h = hash_hmac($algo, $msg, (string)$secretBin, true);
    $off = ord($h[strlen($h) - 1]) & 0x0f;
    $v = ((ord($h[$off]) & 0x7f) << 24) | (ord($h[$off + 1]) << 16) | (ord($h[$off + 2]) << 8) | ord($h[$off + 3]);
    return str_pad((string)($v % (int)pow(10, $digits)), $digits, '0', STR_PAD_LEFT);
}

function com_totp_step($time = null)
{
    return (int)floor(($time === null ? time() : (int)$time) / COM_TFA_PERIOD);
}

// Проверка кода с допуском ±$window шагов. Код с шагом не больше $lastStep не принимается (защита от повтора).
// Возвращает шаг совпавшего кода или false.
function com_totp_verify($secretBin, $code, $lastStep = 0, $time = null, $window = 1)
{
    $code = preg_replace('~\s+~', '', (string)$code);
    if (!preg_match('~^\d{' . COM_TFA_DIGITS . '}$~', $code) || (string)$secretBin === '') {
        return false;
    }
    $now = com_totp_step($time);
    $found = false;
    for ($i = -$window; $i <= $window; $i++) {
        $step = $now + $i;
        if ($step <= (int)$lastStep || $step < 0) {
            continue;
        }
        if (hash_equals(com_totp_code($secretBin, $step), $code) && $found === false) {
            $found = $step;
        }
    }
    return $found;
}

// Новый секрет: 20 случайных байт в Base32 (32 символа)
function com_totp_new_secret()
{
    return com_b32_encode(random_bytes(20));
}

// Ссылка otpauth:// для приложения
function com_tfa_uri($secretB32, $username)
{
    $issuer = (string)setting('site_name');
    if ($issuer === '') {
        $issuer = 'World Role Play';
    }
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode((string)$username)
        . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . COM_TFA_DIGITS . '&period=' . COM_TFA_PERIOD;
}

// ---------- Резервные коды ----------

// 10 кодов вида "k7m2-x9pq" (без похожих символов 0/o, 1/l/i)
function com_tfa_backup_generate($n = COM_TFA_BACKUP_COUNT)
{
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    while (count($codes) < $n) {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $codes[substr($c, 0, 4) . '-' . substr($c, 4)] = true;
    }
    return array_keys($codes);
}

// Резервный код без дефиса и пробелов, в нижнем регистре, или ''
function com_tfa_backup_norm($code)
{
    $c = strtolower(preg_replace('~[\s\-]+~', '', (string)$code));
    return preg_match('~^[a-z0-9]{8}$~', $c) ? $c : '';
}

function com_tfa_backup_hash($uid, $code)
{
    return hash_hmac('sha256', (int)$uid . '|' . com_tfa_backup_norm($code), com_crypt_key('tfa-backup'));
}

function com_tfa_backup_hashes($uid, array $codes)
{
    $out = [];
    foreach ($codes as $c) {
        $out[] = com_tfa_backup_hash($uid, $c);
    }
    return $out;
}

// Использовать резервный код: оставшиеся хэши или false, если код не подошёл
function com_tfa_backup_use(array $hashes, $uid, $code)
{
    if (com_tfa_backup_norm($code) === '') {
        return false;
    }
    $want = com_tfa_backup_hash($uid, $code);
    $hit = -1;
    foreach (array_values($hashes) as $i => $h) {
        if (is_string($h) && hash_equals($h, $want)) {
            $hit = $i;
        }
    }
    if ($hit < 0) {
        return false;
    }
    $left = array_values($hashes);
    array_splice($left, $hit, 1);
    return $left;
}

// ---------- Хранение ----------

function com_tfa_row($uid)
{
    if (!com_ready()) {
        return null;
    }
    return db_one('SELECT * FROM com_tfa WHERE user_id = :u', ['u' => (int)$uid]);
}

// Включена ли 2FA (кэш на запрос)
function com_tfa_enabled($uid)
{
    static $cache = [];
    $uid = (int)$uid;
    if (!isset($cache[$uid])) {
        try {
            $cache[$uid] = com_ready() && (bool)db_val('SELECT is_enabled FROM com_tfa WHERE user_id = :u', ['u' => $uid]);
        } catch (Exception $e) {
            $cache[$uid] = false;
        }
    }
    return $cache[$uid];
}

function com_tfa_purpose($uid)
{
    return 'tfa:' . (int)$uid;
}

// Секрет Base32 из строки базы или null
function com_tfa_secret(array $row)
{
    $s = com_decrypt($row['secret_enc'], com_tfa_purpose($row['user_id']));
    return ($s !== null && com_b32_decode($s) !== false) ? $s : null;
}

function com_tfa_backup_left(array $row)
{
    $list = json_decode((string)$row['backup_codes'], true);
    return is_array($list) ? count($list) : 0;
}

// Проверка кода из приложения или резервного кода для включённой 2FA.
// ['ok' => bool, 'backup' => bool, 'left' => осталось резервных кодов, 'reused' => код уже использован]
function com_tfa_check($uid, $code, $allowBackup = true)
{
    $res = ['ok' => false, 'backup' => false, 'left' => 0, 'reused' => false];
    $row = com_tfa_row($uid);
    if (!$row || empty($row['is_enabled'])) {
        return $res;
    }
    $res['left'] = com_tfa_backup_left($row);
    $code = trim((string)$code);
    $digits = preg_replace('~\s+~', '', $code);
    if (preg_match('~^\d{' . COM_TFA_DIGITS . '}$~', $digits)) {
        $secret = com_tfa_secret($row);
        if ($secret === null) {
            return $res;
        }
        $bin = com_b32_decode($secret);
        $step = com_totp_verify($bin, $digits, (int)$row['last_step']);
        if ($step !== false) {
            // Шаг записываем условием: второй запрос с тем же кодом не пройдёт
            $n = db_exec('UPDATE com_tfa SET last_step = :s WHERE user_id = :u AND last_step < :s2', ['s' => $step, 'u' => (int)$uid, 's2' => $step]);
            $res['ok'] = $n > 0;
            $res['reused'] = $n === 0;
            return $res;
        }
        // Код верный, но уже использован в этом окне
        if (com_totp_verify($bin, $digits, -1) !== false) {
            $res['reused'] = true;
        }
        return $res;
    }
    if ($allowBackup && com_tfa_backup_norm($code) !== '') {
        $hashes = json_decode((string)$row['backup_codes'], true);
        $left = com_tfa_backup_use(is_array($hashes) ? $hashes : [], $uid, $code);
        if ($left !== false) {
            $n = db_exec('UPDATE com_tfa SET backup_codes = :b WHERE user_id = :u AND backup_codes = :old',
                ['b' => json_encode($left), 'u' => (int)$uid, 'old' => (string)$row['backup_codes']]);
            if ($n > 0) {
                $res['ok'] = true;
                $res['backup'] = true;
                $res['left'] = count($left);
            }
        }
    }
    return $res;
}

// Проверка текущего пароля в настройках (как на вкладке «Безопасность»)
function com_check_password($me, $plain)
{
    if (!rate_ok('account_password', 10, 900)) {
        return 'Слишком много неверных попыток. Подождите 15 минут.';
    }
    if ($plain === '' || !password_verify($plain, (string)$me['password_hash'])) {
        rate_hit('account_password');
        return 'Неверный текущий пароль.';
    }
    return '';
}

// ---------- Вход: шаг с кодом ----------

hook_add('login_intercept', function ($u, $remember, $return) {
    try {
        if (!com_ready() || !com_tfa_enabled($u['id'])) {
            return null;
        }
    } catch (Exception $e) {
        return null;
    }
    // Хэш пароля берём из базы: auth_attempt() мог только что перехэшировать пароль
    $hash = (string)db_val('SELECT password_hash FROM users WHERE id = :id', ['id' => (int)$u['id']]);
    session_regenerate_id(true);
    $_SESSION['com_tfa_pending'] = [
        'uid' => (int)$u['id'],
        'remember' => $remember ? 1 : 0,
        'return' => safe_return($return, url('/forum/')),
        'exp' => time() + COM_TFA_PENDING_SECONDS,
        'pw' => substr(hash('sha256', $hash), 0, 16),
        'tries' => 0,
    ];
    return url('/forum/login-2fa.php');
});

// ---------- Настройки аккаунта: вкладка «Двухфакторная защита» ----------

hook_add('account_tabs', function ($tabs, $me) {
    if (com_ready()) {
        $tabs['twofactor'] = ['Двухфакторная защита', 'key'];
    }
    return $tabs;
});

hook_add('account_menu_settings', function ($u) {
    return com_ready() ? '<a href="' . e(url('/forum/account.php', ['tab' => 'twofactor'])) . '">Двухфакторная защита</a>' : '';
});

hook_add('account_action', function ($action, $tab, $me) {
    $actions = ['com_tfa_start', 'com_tfa_confirm', 'com_tfa_cancel', 'com_tfa_disable', 'com_tfa_regen'];
    if (!in_array($action, $actions, true)) {
        return;
    }
    $back = url('/forum/account.php', ['tab' => 'twofactor']);
    if (!com_ready()) {
        flash('error', 'Сначала нужно обновить базу сайта.');
        redirect($back);
    }
    $uid = (int)$me['id'];
    $row = com_tfa_row($uid);
    $enabled = $row && !empty($row['is_enabled']);

    switch ($action) {
        case 'com_tfa_start':
            if ($enabled) {
                flash('info', 'Двухфакторная защита уже включена.');
                redirect($back);
            }
            if ($err = com_check_password($me, (string)($_POST['current_password'] ?? ''))) {
                flash('error', $err);
                redirect($back);
            }
            $secret = com_totp_new_secret();
            db_exec('INSERT INTO com_tfa (user_id, secret_enc, is_enabled, last_step, backup_codes, enabled_at, created_at)
                     VALUES (:u, :s, 0, 0, NULL, NULL, :c)
                     ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), is_enabled = 0, last_step = 0, backup_codes = NULL, enabled_at = NULL, created_at = VALUES(created_at)',
                ['u' => $uid, 's' => com_encrypt($secret, com_tfa_purpose($uid)), 'c' => now()]);
            redirect($back);
            break;

        case 'com_tfa_cancel':
            if (!$enabled) {
                db_exec('DELETE FROM com_tfa WHERE user_id = :u AND is_enabled = 0', ['u' => $uid]);
            }
            redirect($back);
            break;

        case 'com_tfa_confirm':
            if ($enabled || !$row) {
                redirect($back);
            }
            if (!rate_ok('tfa_code', 10, 900)) {
                flash('error', 'Слишком много попыток. Подождите 15 минут.');
                redirect($back);
            }
            $secret = com_tfa_secret($row);
            $step = $secret !== null ? com_totp_verify(com_b32_decode($secret), input('code'), 0) : false;
            if ($step === false) {
                rate_hit('tfa_code');
                flash('error', 'Код не подошёл. Проверьте, что время на телефоне выставлено автоматически, и введите новый код из приложения.');
                redirect($back);
            }
            $codes = com_tfa_backup_generate();
            db_update('com_tfa', [
                'is_enabled' => 1,
                'last_step' => $step,
                'backup_codes' => json_encode(com_tfa_backup_hashes($uid, $codes)),
                'enabled_at' => now(),
            ], 'user_id = :u', ['u' => $uid]);
            $_SESSION['com_tfa_codes'] = $codes;
            flash('success', 'Двухфакторная защита включена. Сохраните резервные коды!');
            redirect($back);
            break;

        case 'com_tfa_regen':
            if (!$enabled) {
                redirect($back);
            }
            if (!rate_ok('tfa_code', 10, 900)) {
                flash('error', 'Слишком много попыток. Подождите 15 минут.');
                redirect($back);
            }
            $r = com_tfa_check($uid, input('code'), false);
            if (!$r['ok']) {
                rate_hit('tfa_code');
                flash('error', $r['reused'] ? 'Этот код уже использован. Дождитесь следующего кода в приложении.' : 'Неверный код из приложения.');
                redirect($back);
            }
            $codes = com_tfa_backup_generate();
            db_update('com_tfa', ['backup_codes' => json_encode(com_tfa_backup_hashes($uid, $codes))], 'user_id = :u', ['u' => $uid]);
            $_SESSION['com_tfa_codes'] = $codes;
            flash('success', 'Новые резервные коды созданы. Старые больше не работают.');
            redirect($back);
            break;

        case 'com_tfa_disable':
            if (!$enabled) {
                redirect($back);
            }
            if ($err = com_check_password($me, (string)($_POST['current_password'] ?? ''))) {
                flash('error', $err);
                redirect($back);
            }
            if (!rate_ok('tfa_code', 10, 900)) {
                flash('error', 'Слишком много попыток. Подождите 15 минут.');
                redirect($back);
            }
            $r = com_tfa_check($uid, input('code'), true);
            if (!$r['ok']) {
                rate_hit('tfa_code');
                flash('error', $r['reused'] ? 'Этот код уже использован. Дождитесь следующего кода в приложении.' : 'Неверный код. Введите код из приложения или резервный код.');
                redirect($back);
            }
            db_exec('DELETE FROM com_tfa WHERE user_id = :u', ['u' => $uid]);
            flash('success', 'Двухфакторная защита выключена.');
            redirect($back);
            break;
    }
});

hook_add('account_tab_content', function ($tab, $me) {
    if ($tab !== 'twofactor' || !com_ready()) {
        return '';
    }
    return com_tfa_tab_html($me);
});

function com_tfa_tab_html(array $me)
{
    $uid = (int)$me['id'];
    $row = com_tfa_row($uid);
    $enabled = $row && !empty($row['is_enabled']);
    $self = url('/forum/account.php', ['tab' => 'twofactor']);
    $h = '';

    if (!$row) {
        $h .= '<section class="card com-tfa-intro">';
        $h .= '<div class="com-tfa-head"><span class="com-tfa-icon">' . icon('key') . '</span><div><h2 class="card-title">Двухфакторная защита</h2>'
            . '<p class="muted">Сейчас <b class="com-off">выключена</b>. При входе на форум, кроме пароля, нужно будет ввести код из приложения на телефоне. '
            . 'Даже если пароль узнают, без телефона в аккаунт не войти.</p></div></div>';
        $h .= '<ol class="com-steps"><li>Установите на телефон приложение для кодов: Google Authenticator, Яндекс Ключ, Microsoft Authenticator или Aegis.</li>'
            . '<li>Нажмите «Включить» и отсканируйте QR-код.</li><li>Введите код из приложения и сохраните резервные коды.</li></ol>';
        $h .= '<form method="post" class="form" action="' . e($self) . '">' . csrf_field() . '<input type="hidden" name="action" value="com_tfa_start">'
            . '<div class="form-row acc-narrow"><label class="label" for="tfa-pass">Текущий пароль для подтверждения</label>'
            . '<input class="input" id="tfa-pass" type="password" name="current_password" autocomplete="current-password" required></div>'
            . '<div class="form-actions"><button class="btn btn-accent btn-pill" type="submit">' . icon('shield') . ' Включить</button></div></form>';
        $h .= '</section>';
        return $h;
    }

    if (!$enabled) {
        $secret = com_tfa_secret($row);
        if ($secret === null) {
            return '<section class="card"><div class="flash flash-error">' . icon('alert') . '<div>Не удалось прочитать секрет. Начните заново.</div></div>'
                . '<form method="post" action="' . e($self) . '">' . csrf_field() . '<input type="hidden" name="action" value="com_tfa_cancel"><button class="btn btn-white" type="submit">Начать заново</button></form></section>';
        }
        $GLOBALS['com_need_qr'] = true;
        $uri = com_tfa_uri($secret, $me['username']);
        $h .= '<section class="card com-tfa-setup"><h2 class="card-title">Подключение приложения</h2>';
        $h .= '<div class="com-tfa-grid"><div class="com-tfa-qr-wrap"><div class="com-tfa-qr" data-com-qr="' . e($uri) . '" role="img" aria-label="QR-код для приложения">'
            . '<span class="muted small">QR-код появится здесь</span></div>'
            . '<a class="btn btn-ghost btn-sm" href="' . e($uri) . '">' . icon('smartphone') . ' Открыть в приложении</a></div>';
        $h .= '<div class="com-tfa-steps"><ol class="com-steps">'
            . '<li>Откройте приложение для кодов и отсканируйте QR-код.</li>'
            . '<li>Не получается сканировать? Добавьте аккаунт вручную, ключ:<div class="com-secret"><code>' . e(trim(chunk_split($secret, 4, ' '))) . '</code>'
            . '<button class="btn btn-sm btn-ghost btn-icon" type="button" data-copy="' . e($secret) . '" title="Скопировать ключ">' . icon('copy') . '</button></div>'
            . '<span class="hint">Тип - по времени (TOTP), 6 цифр, 30 секунд.</span></li>'
            . '<li>Введите 6 цифр, которые показывает приложение:</li></ol>';
        $h .= '<form method="post" class="com-code-form" action="' . e($self) . '">' . csrf_field() . '<input type="hidden" name="action" value="com_tfa_confirm">'
            . '<input class="input com-code-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required aria-label="Код из приложения">'
            . '<button class="btn btn-accent" type="submit">' . icon('check') . ' Подтвердить</button></form>';
        $h .= '<form method="post" action="' . e($self) . '" class="mt-1">' . csrf_field() . '<input type="hidden" name="action" value="com_tfa_cancel">'
            . '<button class="btn btn-ghost btn-sm" type="submit">Отмена</button></form>';
        $h .= '</div></div></section>';
        return $h;
    }

    // Включена
    $codes = $_SESSION['com_tfa_codes'] ?? null;
    unset($_SESSION['com_tfa_codes']);
    $left = com_tfa_backup_left($row);
    $h .= '<section class="card com-tfa-intro"><div class="com-tfa-head"><span class="com-tfa-icon is-on">' . icon('shield') . '</span><div><h2 class="card-title">Двухфакторная защита</h2>'
        . '<p class="muted">Защита <b class="com-on">включена</b> с ' . e(fday($row['enabled_at'])) . '. При входе нужен код из приложения. '
        . 'Резервных кодов осталось: <b>' . num($left) . '</b> из ' . COM_TFA_BACKUP_COUNT . '.</p></div></div>';
    if ($left <= 3) {
        $h .= '<div class="flash flash-info">' . icon('info') . '<div>Резервных кодов осталось мало. Создайте новые ниже.</div></div>';
    }
    $h .= '</section>';

    if (is_array($codes) && $codes) {
        $text = "Резервные коды World Role Play (" . $me['username'] . ")\nКаждый код можно использовать один раз.\n\n" . implode("\n", $codes) . "\n";
        $h .= '<section class="card com-codes-card"><h2 class="card-title">' . icon('key') . ' Резервные коды</h2>'
            . '<p>Сохраните их сейчас: больше они не покажутся. Каждый код подходит один раз, если телефона нет под рукой.</p><div class="com-codes">';
        foreach ($codes as $c) {
            $h .= '<code>' . e($c) . '</code>';
        }
        $h .= '</div><div class="form-actions mt-1"><button class="btn btn-white btn-sm" type="button" data-com-download="' . e($text) . '" data-filename="worldrp-backup-codes.txt">' . icon('download') . ' Скачать .txt</button>'
            . '<button class="btn btn-ghost btn-sm" type="button" data-com-copy="' . e(implode("\n", $codes)) . '">' . icon('copy') . ' Скопировать</button></div></section>';
    }

    $h .= '<section class="card"><h2 class="card-title">Новые резервные коды</h2><p class="muted">Старые коды перестанут работать.</p>'
        . '<form method="post" class="com-code-form" action="' . e($self) . '">' . csrf_field() . '<input type="hidden" name="action" value="com_tfa_regen">'
        . '<input class="input com-code-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="Код из приложения" required aria-label="Код из приложения">'
        . '<button class="btn btn-white" type="submit">' . icon('refresh') . ' Создать</button></form></section>';

    $h .= '<section class="card"><h2 class="card-title">Выключить защиту</h2>'
        . '<form method="post" class="form" action="' . e($self) . '" data-confirm="Выключить двухфакторную защиту? Аккаунт станет менее защищён.">' . csrf_field()
        . '<input type="hidden" name="action" value="com_tfa_disable"><div class="form-grid">'
        . '<div class="form-row"><label class="label" for="tfa-off-pass">Текущий пароль</label><input class="input" id="tfa-off-pass" type="password" name="current_password" autocomplete="current-password" required></div>'
        . '<div class="form-row"><label class="label" for="tfa-off-code">Код из приложения или резервный код</label><input class="input" id="tfa-off-code" name="code" autocomplete="one-time-code" maxlength="9" required></div>'
        . '</div><div class="form-actions"><button class="btn btn-danger btn-pill" type="submit">' . icon('unlock') . ' Выключить</button></div></form></section>';
    return $h;
}

// ---------- Профиль: статус 2FA для команды, сброс для администрации ----------

hook_add('member_info_rows', function ($m) {
    if (!is_staff() || !com_ready()) {
        return '';
    }
    $on = com_tfa_enabled($m['id']);
    return '<dt>2FA</dt><dd>' . ($on ? '<span class="com-on">' . icon('shield') . ' включена</span>' : '<span class="muted">выключена</span>') . '</dd>';
}, 30);

hook_add('member_actions', function ($m, $isSelf) {
    if ($isSelf || !can_admin() || !com_ready() || !com_tfa_enabled($m['id']) || (int)$m['level'] > user_level()) {
        return '';
    }
    return '<form method="post" action="' . e(url('/forum/member.php', ['id' => (int)$m['id']])) . '" data-confirm="Сбросить двухфакторную защиту у ' . e($m['username']) . '? Делайте это, только если владелец аккаунта подтвердил личность.">'
        . csrf_field() . '<input type="hidden" name="action" value="com_tfa_reset">'
        . '<button class="btn btn-pill btn-outline" type="submit">' . icon('key') . ' Сбросить 2FA</button></form>';
});

hook_add('member_action', function ($action, $m, $fail, $return) {
    if ($action !== 'com_tfa_reset') {
        return;
    }
    if (!can_admin() || (int)$m['level'] > user_level() || (int)$m['id'] === uid()) {
        $fail('Нет прав на это действие.', 403);
    }
    if (com_ready() && db_exec('DELETE FROM com_tfa WHERE user_id = :u', ['u' => (int)$m['id']])) {
        mod_log('tfa_reset', 'user', $m['id'], $m['username']);
        flash('success', 'Двухфакторная защита у ' . $m['username'] . ' сброшена.');
    }
    redirect(url('/forum/member.php', ['id' => (int)$m['id']]));
});
