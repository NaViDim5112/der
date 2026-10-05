<?php
// Пользователь, вход и выход, «Запомнить меня», права, ограничение частоты.

const WRP_GUEST_GROUP = 1;
const WRP_USER_GROUP = 2;
const WRP_BANNED_GROUP = 3;

function user_select_sql()
{
    return 'SELECT u.*, g.name AS group_name, g.color AS group_color, g.level AS level,
                   g.is_staff, g.can_moderate, g.can_admin
            FROM users u JOIN user_groups g ON g.id = u.group_id';
}

function user_by_id($id)
{
    return user_enrich(db_one(user_select_sql() . ' WHERE u.id = :id', ['id' => (int)$id]));
}

function user_by_name($name)
{
    return user_enrich(db_one(user_select_sql() . ' WHERE u.username = :n', ['n' => (string)$name]));
}

// Все группы (кэш на запрос): [id => группа]
function groups_all()
{
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        foreach (db_all('SELECT * FROM user_groups ORDER BY display_order, id') as $g) {
            $groups[(int)$g['id']] = $g;
        }
    }
    return $groups;
}

// id дополнительных групп пользователя
function user_secondary_ids($u)
{
    $ids = [];
    foreach (explode(',', (string)($u['secondary_groups'] ?? '')) as $id) {
        $id = (int)$id;
        if ($id > 0 && $id != (int)($u['group_id'] ?? 0)) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

// Права с учётом дополнительных групп: берётся максимум
function user_enrich($u)
{
    if (!$u) {
        return null;
    }
    $groups = groups_all();
    foreach (user_secondary_ids($u) as $gid) {
        if (!isset($groups[$gid])) {
            continue;
        }
        $g = $groups[$gid];
        $u['level'] = max((int)$u['level'], (int)$g['level']);
        $u['is_staff'] = max((int)$u['is_staff'], (int)$g['is_staff']);
        $u['can_moderate'] = max((int)$u['can_moderate'], (int)$g['can_moderate']);
        $u['can_admin'] = max((int)$u['can_admin'], (int)$g['can_admin']);
    }
    return $u;
}

// Плашки групп под аватаром в теме («ЛИДЕР», «ПОЛЬЗОВАТЕЛЬ»): сначала дополнительные, потом основная
function user_banners($u)
{
    $groups = groups_all();
    $ids = array_merge(user_secondary_ids($u), [(int)($u['group_id'] ?? 0)]);
    $h = '';
    foreach ($ids as $gid) {
        if (empty($groups[$gid]) || empty($groups[$gid]['show_banner'])) {
            continue;
        }
        $g = $groups[$gid];
        $h .= '<div class="user-banner" style="--c:' . e($g['color']) . '">' . icon('user') . '<span>' . e(mb_strtoupper($g['name'])) . '</span></div>';
    }
    return $h;
}

// Текущий пользователь (массив) или null для гостя
function user()
{
    return $GLOBALS['wrp_user'] ?? null;
}

function uid()
{
    $u = user();
    return $u ? (int)$u['id'] : 0;
}

function is_logged()
{
    return user() !== null;
}

// Уровень доступа текущего пользователя (гость и заблокированный = 0)
function user_level()
{
    $u = user();
    if (!$u || !empty($u['is_banned'])) {
        return 0;
    }
    return (int)$u['level'];
}

function is_banned()
{
    $u = user();
    return $u && !empty($u['is_banned']);
}

function is_staff()
{
    $u = user();
    return $u && empty($u['is_banned']) && !empty($u['is_staff']);
}

function can_moderate()
{
    $u = user();
    return $u && empty($u['is_banned']) && !empty($u['can_moderate']);
}

function can_admin()
{
    $u = user();
    return $u && empty($u['is_banned']) && !empty($u['can_admin']);
}

function require_login()
{
    if (!is_logged()) {
        flash('info', 'Войдите, чтобы продолжить.');
        redirect(url('/forum/login.php', ['return' => current_url()]));
    }
}

function require_admin()
{
    require_login();
    if (!can_admin()) {
        abort(403, 'Раздел только для администрации.');
    }
}

function require_moderator()
{
    require_login();
    if (!can_moderate()) {
        abort(403, 'Действие только для модераторов.');
    }
}

// Пароль: проверка и хэш
function password_make($plain)
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

// Вход: проверяет логин или e-mail и пароль. Возвращает пользователя или null.
function auth_attempt($login, $password)
{
    $login = trim((string)$login);
    if ($login === '' || $password === '') {
        return null;
    }
    $u = db_one(user_select_sql() . ' WHERE u.username = :a OR u.email = :b LIMIT 1', ['a' => $login, 'b' => $login]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return null;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db_update('users', ['password_hash' => password_make($password)], 'id = :id', ['id' => (int)$u['id']]);
    }
    return $u;
}

function login_user(array $u, $remember = false)
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['pw'] = substr(hash('sha256', $u['password_hash']), 0, 16);
    unset($_SESSION['csrf']);
    db_update('users', ['last_activity' => now(), 'last_ip' => client_ip()], 'id = :id', ['id' => (int)$u['id']]);
    if ($remember) {
        remember_issue((int)$u['id']);
    }
    $GLOBALS['wrp_user'] = user_by_id($u['id']);
}

function logout_user()
{
    $cookie = $_COOKIE['wrp_remember'] ?? '';
    if (is_string($cookie) && strpos($cookie, ':') !== false) {
        list($selector) = explode(':', $cookie, 2);
        db_exec('DELETE FROM remember_tokens WHERE selector = :s', ['s' => $selector]);
    }
    cookie_set('wrp_remember', '', time() - 3600);
    $_SESSION = [];
    session_regenerate_id(true);
    $GLOBALS['wrp_user'] = null;
}

function cookie_set($name, $value, $expires)
{
    $path = rtrim((string)cfg('base_path', ''), '/') . '/';
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => $path,
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function request_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

// «Запомнить меня» на 30 дней: selector:validator, в базе хранится только хэш validator
function remember_issue($userId)
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + 86400 * 30;
    db_insert('remember_tokens', [
        'user_id' => (int)$userId,
        'selector' => $selector,
        'validator_hash' => hash('sha256', $validator),
        'expires_at' => date('Y-m-d H:i:s', $expires),
    ]);
    cookie_set('wrp_remember', $selector . ':' . $validator, $expires);
}

function remember_consume()
{
    $cookie = $_COOKIE['wrp_remember'] ?? '';
    if (!is_string($cookie) || strpos($cookie, ':') === false) {
        return null;
    }
    list($selector, $validator) = explode(':', $cookie, 2);
    if (!preg_match('~^[a-f0-9]{24}$~', $selector) || !preg_match('~^[a-f0-9]{64}$~', $validator)) {
        cookie_set('wrp_remember', '', time() - 3600);
        return null;
    }
    $row = db_one('SELECT * FROM remember_tokens WHERE selector = :s', ['s' => $selector]);
    if (!$row || strtotime($row['expires_at']) < time() || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        if ($row) {
            db_exec('DELETE FROM remember_tokens WHERE id = :id', ['id' => (int)$row['id']]);
        }
        cookie_set('wrp_remember', '', time() - 3600);
        return null;
    }
    db_exec('DELETE FROM remember_tokens WHERE id = :id', ['id' => (int)$row['id']]);
    $u = user_by_id($row['user_id']);
    if (!$u) {
        return null;
    }
    login_user($u, true);
    return user();
}

// Загружает пользователя в начале каждого запроса
function auth_boot()
{
    $GLOBALS['wrp_user'] = null;
    if (!empty($_SESSION['uid'])) {
        $u = user_by_id($_SESSION['uid']);
        // Если пароль сменили, старые сессии выходят
        if ($u && isset($_SESSION['pw']) && hash_equals($_SESSION['pw'], substr(hash('sha256', $u['password_hash']), 0, 16))) {
            $GLOBALS['wrp_user'] = $u;
        } else {
            unset($_SESSION['uid'], $_SESSION['pw']);
        }
    } elseif (!empty($_COOKIE['wrp_remember'])) {
        remember_consume();
    }

    $u = user();
    // Временный бан закончился
    if ($u && !empty($u['is_banned']) && $u['ban_until'] && strtotime($u['ban_until']) < time()) {
        db_update('users', ['is_banned' => 0, 'ban_reason' => null, 'ban_until' => null], 'id = :id', ['id' => (int)$u['id']]);
        $GLOBALS['wrp_user'] = user_by_id($u['id']);
    }
    online_touch();
}

// Отметка «на сайте» не чаще раза в минуту
function online_touch()
{
    $last = $_SESSION['touch'] ?? 0;
    if (time() - $last < 60) {
        return;
    }
    $_SESSION['touch'] = time();
    $sid = hash('sha256', session_id() . '|' . cfg('secret'));
    $uid = uid() ?: null;
    db_exec('INSERT INTO online (sid, user_id, last_activity) VALUES (:s, :u, :t)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_activity = VALUES(last_activity)',
        ['s' => $sid, 'u' => $uid, 't' => now()]);
    if ($uid) {
        db_update('users', ['last_activity' => now(), 'last_ip' => client_ip()], 'id = :id', ['id' => $uid]);
    }
    if (mt_rand(1, 50) === 1) {
        db_exec('DELETE FROM online WHERE last_activity < :t', ['t' => date('Y-m-d H:i:s', time() - 1800)]);
        db_exec('DELETE FROM rate_limits WHERE created_at < :t', ['t' => date('Y-m-d H:i:s', time() - 86400)]);
        db_exec('DELETE FROM remember_tokens WHERE expires_at < :t', ['t' => now()]);
    }
}

// ---------- Ограничение частоты ----------

// true, если действие ещё можно выполнить (меньше $max раз за $seconds с этого IP)
function rate_ok($action, $max, $seconds)
{
    $n = (int)db_val('SELECT COUNT(*) FROM rate_limits WHERE action = :a AND ip = :ip AND created_at > :t',
        ['a' => $action, 'ip' => client_ip(), 't' => date('Y-m-d H:i:s', time() - $seconds)]);
    return $n < $max;
}

function rate_hit($action)
{
    db_insert('rate_limits', ['action' => $action, 'ip' => client_ip(), 'created_at' => now()]);
}

// ---------- Отображение пользователя ----------

// Имя пользователя цветом группы со ссылкой на профиль
function user_link($u, $class = '')
{
    if (!$u || empty($u['id'])) {
        return '<span class="username muted">Гость</span>';
    }
    $name = $u['username'] ?? ($u['user_name'] ?? '');
    $color = $u['group_color'] ?? '';
    $style = $color ? ' style="color:' . e($color) . '"' : '';
    return '<a class="username ' . e($class) . '" href="' . e(url('/forum/member.php', ['id' => (int)$u['id']])) . '"' . $style . '>' . e($name) . '</a>';
}

// Аватар: картинка или буква на цветном круге
function avatar($u, $size = 'm')
{
    $name = (string)($u['username'] ?? '?');
    $letter = mb_strtoupper(mb_substr($name, 0, 1));
    if (!empty($u['avatar'])) {
        return '<span class="avatar avatar-' . e($size) . '"><img src="' . e(url('/uploads/avatars/' . $u['avatar'])) . '" alt="" loading="lazy"></span>';
    }
    $hue = abs(crc32($name)) % 360;
    return '<span class="avatar avatar-' . e($size) . ' avatar-letter" style="--h:' . $hue . '">' . e($letter) . '</span>';
}

// Значок группы (плашка под ником)
function group_badge($u)
{
    if (empty($u['group_name'])) {
        return '';
    }
    $color = $u['group_color'] ?? '#9aa0b4';
    return '<span class="group-badge" style="--c:' . e($color) . '">' . e($u['group_name']) . '</span>';
}

// Баллы пользователя: темы x3 + сообщения + реакции x2
function user_points($u)
{
    return (int)($u['threads_count'] ?? 0) * 3 + (int)($u['posts_count'] ?? 0) + (int)($u['likes_received'] ?? 0) * 2;
}

// Обложка профиля (url или пусто)
function user_cover_url($u)
{
    return !empty($u['cover']) ? url('/uploads/covers/' . $u['cover']) : '';
}

// Проверка ника: буквы, цифры, _ - . и пробел, 3-24 символа
function username_valid($name)
{
    return (bool)preg_match('~^[\p{L}\p{N}_\-\.]+(?: [\p{L}\p{N}_\-\.]+)*$~u', $name)
        && mb_strlen($name) >= 3 && mb_strlen($name) <= 24;
}
