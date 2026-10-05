<?php
// Общие функции: конфиг, экранирование, ссылки, CSRF, флеш-сообщения, даты, пагинация.

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// Значение из config.php: cfg('db.host'), cfg('game_db.enabled', false)
function cfg($key, $default = null)
{
    $value = $GLOBALS['wrp_config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

// Экранирование для HTML
function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Ссылка внутри сайта: url('/forum/thread.php', ['id' => 5])
function url($path = '/', array $params = [])
{
    $base = rtrim((string)cfg('base_path', ''), '/');
    $u = $base . '/' . ltrim($path, '/');
    $params = array_filter($params, function ($v) {
        return $v !== null && $v !== '';
    });
    if ($params) {
        $u .= (strpos($u, '?') === false ? '?' : '&') . http_build_query($params);
    }
    return $u;
}

// Путь сайта от корня домена по текущему запросу (до установки, когда base_path ещё не задан).
// /wrp/forum/index.php при файле public/forum/index.php -> /wrp
function detect_base_path()
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $file = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $pub = str_replace('\\', '/', (string)realpath(WRP_PUBLIC));
    if ($file !== '' && $pub !== '' && strpos($file, $pub . '/') === 0) {
        $rel = substr($file, strlen($pub));
        if ($rel !== '' && substr($script, -strlen($rel)) === $rel) {
            return rtrim(substr($script, 0, -strlen($rel)), '/');
        }
    }
    return rtrim((string)cfg('base_path', ''), '/');
}

// Ссылка на файл в public/assets с версией для сброса кэша
function asset($path)
{
    $file = WRP_PUBLIC . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url('/assets/' . ltrim($path, '/')) . '?v=' . $v;
}

// Полный адрес текущего сайта (для ссылок вне сайта)
function site_origin()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

// Безопасный адрес возврата: только внутренние пути сайта
function safe_return($to, $fallback = null)
{
    $to = (string)$to;
    // Управляющие символы (в том числе табуляцию) браузер выбрасывает из адреса: "/\t/site" превращается в "//site"
    if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || strpbrk($to, "\\") !== false || preg_match('~[\x00-\x1F\x7F]~', $to)) {
        return $fallback !== null ? $fallback : url('/forum/');
    }
    return $to;
}

function current_url()
{
    return $_SERVER['REQUEST_URI'] ?? url('/');
}

function is_post()
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// Строка из POST (обрезанная)
function input($key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

// Текст из POST без обрезки пробелов внутри, только по краям, с нормализацией переводов строк
function input_text($key)
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return trim(str_replace(["\r\n", "\r"], "\n", $v));
}

function input_int($key, $default = 0)
{
    $v = $_POST[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function input_bool($key)
{
    return !empty($_POST[$key]);
}

// Целое из GET
function query_int($key, $default = 0)
{
    $v = $_GET[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function query_str($key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

// ---------- CSRF ----------

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

// Проверка токена на POST. Если неверный - 400.
function csrf_check()
{
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        abort(400, 'Сессия устарела. Обновите страницу и попробуйте ещё раз.');
    }
}

// ---------- Флеш-сообщения ----------

function flash($type, $message)
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes()
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- Ошибки ----------

function abort($code, $message = '')
{
    http_response_code($code);
    $titles = [400 => 'Ошибка запроса', 403 => 'Нет доступа', 404 => 'Страница не найдена', 429 => 'Слишком часто', 500 => 'Ошибка сервера'];
    $title = $titles[$code] ?? 'Ошибка';
    if ($message === '') {
        $message = $code == 404 ? 'Такой страницы нет или она была удалена.' : 'Действие недоступно.';
    }
    if (function_exists('forum_header') && empty($GLOBALS['wrp_no_layout'])) {
        forum_header(['title' => $title, 'crumbs' => [], 'right' => false]);
        echo '<div class="card notice-card"><div class="notice-icon">' . icon($code == 403 ? 'lock' : 'alert') . '</div><div><h2>' . e($title) . '</h2><p>' . e($message) . '</p>';
        if ($code == 403 && !is_logged()) {
            echo '<p><a class="btn btn-white" href="' . e(url('/forum/login.php', ['return' => current_url()])) . '">' . icon('login') . ' Войти</a></p>';
        }
        echo '</div></div>';
        forum_footer();
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>' . e($title) . '</title><p>' . e($message) . '</p>';
    }
    exit;
}

// ---------- Даты и числа ----------

function now()
{
    return date('Y-m-d H:i:s');
}

function ru_months_short()
{
    return ['', 'янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
}

// "Сегодня в 12:30", "Вчера в 08:10", "5 окт 2026"
function fdate($datetime, $withTime = true)
{
    if (!$datetime) {
        return '';
    }
    $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    $diff = time() - $ts;
    if ($diff >= 0 && $diff < 60) {
        return 'только что';
    }
    if ($diff >= 0 && $diff < 3600) {
        $m = (int)floor($diff / 60);
        return $m . ' ' . plural($m, 'минуту', 'минуты', 'минут') . ' назад';
    }
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) {
        return 'Сегодня в ' . date('H:i', $ts);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Вчера в ' . date('H:i', $ts);
    }
    $m = ru_months_short();
    $s = (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($withTime) {
        $s .= ' в ' . date('H:i', $ts);
    }
    return $s;
}

// Дата без времени: "5 окт 2026"
function fday($datetime)
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    $m = ru_months_short();
    return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// plural(5, 'тема', 'темы', 'тем') -> 'тем'
function plural($n, $one, $few, $many)
{
    $n = abs((int)$n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) {
        return $many;
    }
    if ($n1 > 1 && $n1 < 5) {
        return $few;
    }
    if ($n1 == 1) {
        return $one;
    }
    return $many;
}

// 7304688 -> "7 304 688"
function num($n)
{
    return number_format((int)$n, 0, ',', ' ');
}

// 1500 -> "1,5K" для компактных счётчиков
function num_short($n)
{
    $n = (int)$n;
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1, ',', ''), '0'), ',') . 'K';
    }
    return (string)$n;
}

// ---------- Пагинация ----------

function paginate($total, $perPage, $page)
{
    $perPage = max(1, (int)$perPage);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, (int)$page), $pages);
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => (int)$total];
}

// HTML пагинации. $path и $params - как в url(), номер страницы добавляется в 'page'
function pagination_html(array $p, $path, array $params = [])
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $page = $p['page'];
    $pages = $p['pages'];
    $show = [1, $pages];
    for ($i = $page - 2; $i <= $page + 2; $i++) {
        if ($i >= 1 && $i <= $pages) {
            $show[] = $i;
        }
    }
    $show = array_unique($show);
    sort($show);
    $h = '<nav class="pagination">';
    if ($page > 1) {
        $h .= '<a class="page-btn" href="' . e(url($path, array_merge($params, ['page' => $page - 1 > 1 ? $page - 1 : null]))) . '">&lsaquo;</a>';
    }
    $prev = 0;
    foreach ($show as $i) {
        if ($prev && $i - $prev > 1) {
            $h .= '<span class="page-gap">&hellip;</span>';
        }
        $cls = $i == $page ? 'page-btn active' : 'page-btn';
        $h .= '<a class="' . $cls . '" href="' . e(url($path, array_merge($params, ['page' => $i > 1 ? $i : null]))) . '">' . $i . '</a>';
        $prev = $i;
    }
    if ($page < $pages) {
        $h .= '<a class="page-btn" href="' . e(url($path, array_merge($params, ['page' => $page + 1]))) . '">&rsaquo;</a>';
    }
    return $h . '</nav>';
}

// ---------- Прочее ----------

function client_ip()
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// Обрезка строки по символам
function str_limit($s, $limit = 100)
{
    $s = (string)$s;
    if (mb_strlen($s) <= $limit) {
        return $s;
    }
    return rtrim(mb_substr($s, 0, $limit - 1)) . '…';
}

// Транслитерация для адресов статей
function slugify($s)
{
    $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
    $s = mb_strtolower((string)$s);
    $s = strtr($s, $map);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'page' : substr($s, 0, 80);
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Вызывается из страниц, где нужен POST-запрос
function require_post()
{
    if (!is_post()) {
        abort(400, 'Неверный запрос.');
    }
    csrf_check();
}
