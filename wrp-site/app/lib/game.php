<?php
// Игровая база сервера: проверка игрового пароля и статистика для кабинета.
// Настраивается в config.php -> game_db. По умолчанию выключено.

function game_enabled()
{
    return (bool)cfg('game_db.enabled', false);
}

function game_db()
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('game_db');
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=' . ($c['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    }
    return $pdo;
}

// Имя таблицы или колонки из конфига: только буквы, цифры и _
function game_ident($name)
{
    if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', (string)$name)) {
        throw new RuntimeException('Неверное имя в настройках game_db: ' . $name);
    }
    return '`' . $name . '`';
}

// Строка аккаунта по нику или null
function game_account($nick)
{
    if (!game_enabled()) {
        return null;
    }
    $sql = 'SELECT * FROM ' . game_ident(cfg('game_db.table')) . ' WHERE ' . game_ident(cfg('game_db.col_name')) . ' = ? LIMIT 1';
    $st = game_db()->prepare($sql);
    $st->execute([game_to_db($nick)]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return array_map('game_from_db', $row);
}

// Проверка пароля игрового аккаунта
function game_check_password($nick, $password)
{
    $acc = game_account($nick);
    if (!$acc) {
        return false;
    }
    $stored = (string)($acc[cfg('game_db.col_pass')] ?? '');
    $saltCol = (string)cfg('game_db.col_salt', '');
    $salt = $saltCol !== '' ? (string)($acc[$saltCol] ?? '') : '';
    $p = game_to_db($password);
    $mode = cfg('game_db.hash', 'auto');
    if ($mode === 'auto') {
        // Мод World RP: новые пароли bcrypt, старые аккаунты - SHA256 с солью (перехэшируются при входе в игру)
        if (strpos($stored, '$2') === 0) {
            $mode = 'bcrypt';
        } elseif ($salt !== '') {
            $mode = 'sha256_salt';
        } else {
            $mode = 'sha256';
        }
    }
    switch ($mode) {
        case 'bcrypt':
            return $stored !== '' && password_verify($p, $stored);
        case 'sha256':
            return hash_equals(strtolower($stored), hash('sha256', $p));
        case 'sha256_salt':
            // SHA256_PassHash(password, salt) в SA-MP: sha256(пароль + соль), верхний регистр
            return hash_equals(strtolower($stored), hash('sha256', $p . $salt));
        case 'whirlpool':
            return hash_equals(strtolower($stored), hash('whirlpool', $p));
        case 'md5':
            return hash_equals(strtolower($stored), md5($p));
        case 'sha1':
            return hash_equals(strtolower($stored), sha1($p));
        case 'plain':
            return $stored !== '' && hash_equals($stored, $p);
    }
    return false;
}

// Статистика для кабинета: [['label' => 'Уровень', 'value' => '5'], ...]
function game_stats($nick)
{
    $acc = game_account($nick);
    if (!$acc) {
        return null;
    }
    $out = [];
    foreach ((array)cfg('game_db.stats', []) as $col => $label) {
        if (array_key_exists($col, $acc)) {
            $v = $acc[$col];
            $out[] = ['label' => $label, 'value' => is_numeric($v) && strlen((string)$v) < 16 ? num($v) : (string)$v];
        }
    }
    return $out;
}

// Перекодировка, если игровая база в cp1251 (или latin1-колонки с байтами cp1251, как часто бывает в SA-MP модах)
function game_is_cp1251()
{
    return in_array(strtolower((string)cfg('game_db.charset', 'utf8mb4')), ['cp1251', 'latin1'], true);
}

function game_to_db($s)
{
    return game_is_cp1251() ? mb_convert_encoding((string)$s, 'Windows-1251', 'UTF-8') : (string)$s;
}

function game_from_db($s)
{
    if ($s === null || !game_is_cp1251() || !is_string($s)) {
        return $s;
    }
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
}
