<?php
// Модуль «Сообщество»: награды и звания, дополнительные поля профиля, двухфакторная защита,
// объявления-плашки, команда онлайн, «Кто где на форуме», Discord-вебхуки.
// Этот файл - общие функции модуля. Остальное - в community_*.php.
// Все функции модуля начинаются с com_. Обновление базы: sql/migrations/0401_community.sql.

// Обновление базы применено? (один запрос на страницу; до обновления модуль молчит, страницы не падают)
function com_ready($reset = false)
{
    static $ok = null;
    if ($reset) {
        $ok = null;
    }
    if ($ok === null) {
        try {
            // Колонка добавляется последней в 0401_community.sql
            db_val('SELECT trophy_points FROM users LIMIT 1');
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}

// ---------- Шифрование (секрет 2FA, адреса вебхуков) ----------
// Ключ выводится из config.php -> secret и назначения. Если secret поменять, сохранённые
// секреты 2FA и адреса вебхуков перестанут расшифровываться (2FA придётся включить заново).

function com_crypt_key($purpose)
{
    return hash_hmac('sha256', 'wrp-community|' . $purpose, (string)cfg('secret', ''), true);
}

function com_crypt_available()
{
    return function_exists('openssl_encrypt') && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
}

// AES-256-GCM: "g1:" . base64(iv . tag . шифротекст). Без OpenSSL - "p1:" . открытый текст.
function com_encrypt($plain, $purpose)
{
    $plain = (string)$plain;
    if (com_crypt_available()) {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', com_crypt_key($purpose), OPENSSL_RAW_DATA, $iv, $tag, $purpose, 16);
        if ($ct !== false && strlen($tag) === 16) {
            return 'g1:' . base64_encode($iv . $tag . $ct);
        }
    }
    return 'p1:' . $plain;
}

// Расшифровка или null (чужой ключ, испорченные данные)
function com_decrypt($stored, $purpose)
{
    $stored = (string)$stored;
    if (strncmp($stored, 'p1:', 3) === 0) {
        return substr($stored, 3);
    }
    if (strncmp($stored, 'g1:', 3) !== 0 || !com_crypt_available()) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', com_crypt_key($purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $purpose);
    return $plain === false ? null : $plain;
}

// ---------- Общие помощники ----------

// Цвета наград, званий и плашек: ключ => [название, CSS-цвет]
function com_colors()
{
    return [
        'accent' => ['Фирменный градиент', '#ff5a36'],
        'orange' => ['Оранжевый', '#ff7a1a'],
        'gold' => ['Золотой', '#f5b70a'],
        'red' => ['Красный', '#ef4444'],
        'pink' => ['Розовый', '#ff2d78'],
        'purple' => ['Фиолетовый', '#8b5cf6'],
        'blue' => ['Синий', '#3b82f6'],
        'teal' => ['Бирюзовый', '#14b8a6'],
        'green' => ['Зелёный', '#22c55e'],
        'gray' => ['Серый', '#9aa0b4'],
    ];
}

function com_color_key($c)
{
    $c = (string)$c;
    return isset(com_colors()[$c]) ? $c : 'gray';
}

// Список id из строки "1,2,3"
function com_ids($s)
{
    $out = [];
    foreach (preg_split('~[\s,]+~', (string)$s) as $x) {
        if (ctype_digit($x) && (int)$x > 0) {
            $out[] = (int)$x;
        }
    }
    return array_values(array_unique($out));
}

// Внешняя ссылка только http/https, без пробелов и кавычек
function com_http_url_ok($u)
{
    $u = (string)$u;
    if ($u === '' || strlen($u) > 255 || !preg_match('~^https?://[^\s<>"\'`\\\\]+$~i', $u)) {
        return false;
    }
    $host = parse_url($u, PHP_URL_HOST);
    return is_string($host) && $host !== '' && strpos($host, '.') !== false;
}

// Ссылка для плашек и кнопок: внутренний путь сайта или http(s)
function com_link_href($u)
{
    $u = trim((string)$u);
    if ($u === '') {
        return '';
    }
    if ($u[0] === '/' && !str_starts_with($u, '//') && !preg_match('~[\s<>"\'`\\\\]~', $u)) {
        return url($u);
    }
    return com_http_url_ok($u) ? $u : '';
}

// Однострочный текст без управляющих символов
function com_clean_line($s)
{
    $s = preg_replace('~[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]~u', '', (string)$s);
    return trim(preg_replace('~\s+~u', ' ', (string)$s));
}

// Подпись "N дней" и т.п.
function com_days_text($n)
{
    return num($n) . ' ' . plural($n, 'день', 'дня', 'дней');
}

// ---------- Подключение к страницам ----------

hook_add('settings_defaults', function ($d) {
    $d['com_staff_widget'] = '1';
    $d['com_post_trophies'] = '4';
    $d['com_trophy_alerts'] = '1';
    $d['com_discord_site_url'] = '';
    return $d;
});

// Свои иконки (рисунок в стиле Lucide, ISC License)
hook_add('icon_paths', function ($p) {
    $p['trophy'] = '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>';
    $p['award'] = '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>';
    $p['plane'] = '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>';
    $p['feather'] = '<path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><path d="M16 8 2 22"/><path d="M17.5 15H9"/>';
    $p['key'] = '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>';
    $p['smartphone'] = '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>';
    $p['map-pin'] = '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>';
    $p['gift'] = '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/>';
    $p['flame'] = '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>';
    $p['badge-check'] = '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>';
    return $p;
});

// Стили модуля на всех страницах (плашки, виджет, карточки сообщений, награды)
hook_add('page_head', function ($area, $o) {
    return '<link rel="stylesheet" href="' . e(asset('css/community.css')) . '">' . "\n";
});

// Скрипт модуля; библиотека QR-кода - только там, где она нужна (вкладка 2FA)
hook_add('page_scripts', function ($area, $o) {
    $h = '';
    if (!empty($GLOBALS['com_need_qr'])) {
        $h .= '<script src="' . e(asset('js/vendor/qrcode.js')) . '"></script>' . "\n";
    }
    return $h . '<script src="' . e(asset('js/community.js')) . '"></script>' . "\n";
});

hook_add('admin_menu', function ($items) {
    $items['trophies'] = ['Награды и звания', 'trophy', '/admin/trophies.php'];
    $items['profile-fields'] = ['Поля профиля', 'list', '/admin/profile-fields.php'];
    $items['notices'] = ['Объявления', 'megaphone', '/admin/notices.php'];
    $items['discord'] = ['Discord', 'discord', '/admin/discord.php'];
    return $items;
});

hook_add('modlog_labels', function ($l) {
    $l['trophy_award'] = 'Выдана награда';
    $l['trophy_revoke'] = 'Снята награда';
    $l['tfa_reset'] = 'Сброшена двухфакторная защита';
    $l['fields_edit'] = 'Изменены поля профиля';
    return $l;
});
