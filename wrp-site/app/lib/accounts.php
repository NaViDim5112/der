<?php
// Аккаунты и профили: регистрация, аватары и обложки, стена профиля, подписки и игнор,
// списки участников, личные переписки, игровой аккаунт в кабинете.
// Все функции этого файла начинаются с acc_.

const ACC_ONLINE_SECONDS = 900;
const ACC_AVATAR_SIZE = 256;
const ACC_AVATAR_MAX_BYTES = 2097152;
const ACC_COVER_MAX_BYTES = 4194304;
const ACC_COVER_W = 1600;
const ACC_COVER_H = 400;
const ACC_WALL_FLOOD_SECONDS = 10;
const ACC_CONV_FLOOD_SECONDS = 10;
const ACC_CONV_MAX_RECIPIENTS = 5;
const ACC_CONV_MAX_USERS = 10;

// ---------- Проверка данных ----------

// Имена, которые нельзя занять при регистрации
function acc_reserved_names()
{
    return ['admin', 'administrator', 'administration', 'root', 'system', 'sysadmin', 'moderator', 'support', 'staff',
        'guest', 'owner', 'server', 'console', 'wrp', 'worldroleplay', 'null', 'undefined',
        'администратор', 'администрация', 'админ', 'модератор', 'модерация', 'система', 'гость', 'поддержка', 'сервер', 'владелец'];
}

// Ник без регистра и разделителей: "Ad_Min" -> "admin"
function acc_name_key($name)
{
    return preg_replace('~[\s_\-\.]+~u', '', mb_strtolower((string)$name));
}

function acc_name_reserved($name)
{
    $key = acc_name_key($name);
    foreach (acc_reserved_names() as $r) {
        if ($key === acc_name_key($r)) {
            return true;
        }
    }
    foreach (['administrator', 'администратор', 'администрация', 'moderator', 'модератор'] as $word) {
        if (mb_strpos($key, $word) !== false) {
            return true;
        }
    }
    return false;
}

// Текст ошибки для имени пользователя или ''
function acc_username_error($name, $exceptId = 0)
{
    $name = (string)$name;
    if ($name === '') {
        return 'Введите имя пользователя.';
    }
    if (mb_strlen($name) < 3 || mb_strlen($name) > 24) {
        return 'Имя должно быть длиной от 3 до 24 символов.';
    }
    if (!username_valid($name)) {
        return 'Можно использовать буквы, цифры, пробел и символы _ - . (без пробелов по краям).';
    }
    if (preg_match('~\p{Latin}~u', $name) && preg_match('~\p{Cyrillic}~u', $name)) {
        return 'Не смешивайте латиницу и кириллицу в одном имени.';
    }
    if (acc_name_reserved($name)) {
        return 'Это имя зарезервировано. Выберите другое.';
    }
    if (db_val('SELECT id FROM users WHERE username = :n AND id <> :id', ['n' => $name, 'id' => (int)$exceptId])) {
        return 'Это имя уже занято.';
    }
    return '';
}

function acc_email_error($email, $exceptId = 0)
{
    $email = (string)$email;
    if ($email === '') {
        return 'Введите email.';
    }
    if (mb_strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Введите корректный email, например name@mail.ru.';
    }
    if (db_val('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $email, 'id' => (int)$exceptId])) {
        return 'Этот email уже используется другим аккаунтом.';
    }
    return '';
}

function acc_password_error($pass, $username = '')
{
    $len = mb_strlen((string)$pass);
    if ($len < 8) {
        return 'Пароль должен быть не короче 8 символов.';
    }
    if ($len > 128) {
        return 'Пароль слишком длинный (не больше 128 символов).';
    }
    if ($username !== '' && mb_strtolower((string)$pass) === mb_strtolower((string)$username)) {
        return 'Пароль не должен совпадать с именем пользователя.';
    }
    return '';
}

// Однострочный текст: без управляющих символов и лишних пробелов
function acc_clean_line($s)
{
    $s = preg_replace('~[\x00-\x1F\x7F]+~u', ' ', (string)$s);
    return trim(preg_replace('~\s+~u', ' ', (string)$s));
}

// ---------- Проверочный вопрос (защита от ботов) ----------

function acc_captcha_question($key, $renew = false)
{
    if ($renew || empty($_SESSION['acc_captcha'][$key])) {
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        if ($a > $b && random_int(0, 1)) {
            $_SESSION['acc_captcha'][$key] = ['q' => $a . ' - ' . $b, 'a' => $a - $b];
        } else {
            $_SESSION['acc_captcha'][$key] = ['q' => $a . ' + ' . $b, 'a' => $a + $b];
        }
    }
    return 'Сколько будет ' . $_SESSION['acc_captcha'][$key]['q'] . '?';
}

// Проверка ответа. Вопрос одноразовый: после проверки нужен новый.
function acc_captcha_check($key, $answer)
{
    $c = $_SESSION['acc_captcha'][$key] ?? null;
    unset($_SESSION['acc_captcha'][$key]);
    $answer = trim((string)$answer);
    return $c && preg_match('~^-?\d{1,3}$~', $answer) && (int)$answer === (int)$c['a'];
}

// ---------- Формы ----------

function acc_field_error(array $errors, $key)
{
    return !empty($errors[$key]) ? '<div class="field-error">' . e($errors[$key]) . '</div>' : '';
}

function acc_invalid(array $errors, $key)
{
    return !empty($errors[$key]) ? ' acc-invalid' : '';
}

// Адрес, с которого пришёл запрос (только свой сайт), или $fallback
function acc_referer_path($fallback)
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') {
        return $fallback;
    }
    $p = parse_url($ref);
    if (!$p || empty($p['path']) || (isset($p['host']) && strcasecmp($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0)) {
        return $fallback;
    }
    $path = $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
    return safe_return($path, $fallback);
}

// Запрос из JS (WRP.post)
function acc_is_ajax()
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

// ---------- Загрузка изображений ----------

// '2M' -> 2097152
function acc_ini_bytes($v)
{
    $v = trim((string)$v);
    if ($v === '') {
        return 0;
    }
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g':
            $n *= 1024;
            // no break
        case 'm':
            $n *= 1024;
            // no break
        case 'k':
            $n *= 1024;
    }
    return (int)$n;
}

// Реальный предел загрузки с учётом php.ini
function acc_upload_limit($want)
{
    $limit = (int)$want;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = acc_ini_bytes(ini_get($k));
        if ($b > 0) {
            $limit = min($limit, $b);
        }
    }
    return $limit;
}

function acc_bytes_text($b)
{
    if ($b >= 1048576) {
        return rtrim(rtrim(number_format($b / 1048576, 1, ',', ''), '0'), ',') . ' МБ';
    }
    return max(1, (int)round($b / 1024)) . ' КБ';
}

// Путь к загруженному файлу из $_FILES или '' с текстом ошибки в $error
function acc_upload_take($field, $maxBytes, &$error)
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
        $error = 'Выберите файл.';
        return '';
    }
    $code = (int)$f['error'];
    if ($code === UPLOAD_ERR_NO_FILE) {
        $error = 'Выберите файл.';
        return '';
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        $error = 'Файл слишком большой. Максимум ' . acc_bytes_text($maxBytes) . '.';
        return '';
    }
    if ($code !== UPLOAD_ERR_OK) {
        $error = 'Файл не загрузился (код ошибки ' . $code . '). Попробуйте ещё раз.';
        return '';
    }
    if ((int)$f['size'] > $maxBytes) {
        $error = 'Файл слишком большой. Максимум ' . acc_bytes_text($maxBytes) . '.';
        return '';
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        $error = 'Файл не загрузился. Попробуйте ещё раз.';
        return '';
    }
    return $f['tmp_name'];
}

function acc_gd_ready()
{
    return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
}

// Открывает картинку через GD (только разрешённые типы), поворачивает по EXIF
function acc_image_load($path, array $types, &$error)
{
    if (!acc_gd_ready()) {
        $error = 'На сервере не включено расширение PHP GD, загрузка изображений недоступна. Сообщите администрации.';
        return null;
    }
    $info = @getimagesize($path);
    if (!$info || !in_array((int)$info[2], $types, true)) {
        $error = 'Файл не является изображением подходящего формата.';
        return null;
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w < 1 || $h < 1 || $w > 8000 || $h > 8000 || $w * $h > 25000000) {
        $error = 'Слишком большое разрешение изображения (не больше 8000 точек по стороне).';
        return null;
    }
    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif'];
    if (defined('IMAGETYPE_WEBP')) {
        $loaders[IMAGETYPE_WEBP] = 'imagecreatefromwebp';
    }
    $fn = $loaders[(int)$info[2]] ?? '';
    if ($fn === '' || !function_exists($fn)) {
        $error = 'Этот формат изображений не поддерживается сервером. Используйте JPG или PNG.';
        return null;
    }
    $need = (int)($w * $h * 5 + 33554432);
    $limit = acc_ini_bytes(ini_get('memory_limit'));
    if ($limit > 0 && $limit < $need) {
        @ini_set('memory_limit', (string)$need);
    }
    $img = @$fn($path);
    if (!$img) {
        $error = 'Не удалось прочитать изображение. Попробуйте другой файл.';
        return null;
    }
    if ((int)$info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        $angle = $o === 3 ? 180 : ($o === 6 ? 270 : ($o === 8 ? 90 : 0));
        if ($angle) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
    }
    return $img;
}

// Обрезка по центру до пропорций $tw:$th и уменьшение до $tw x $th.
// $bg = [r, g, b] - заливка под прозрачными местами (для JPEG), null - сохранить прозрачность
function acc_image_crop($src, $tw, $th, $bg = null)
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $ratio = $tw / $th;
    if ($sw / $sh > $ratio) {
        $ch = $sh;
        $cw = max(1, (int)round($sh * $ratio));
        $cx = (int)floor(($sw - $cw) / 2);
        $cy = 0;
    } else {
        $cw = $sw;
        $ch = max(1, (int)round($sw / $ratio));
        $cx = 0;
        $cy = (int)floor(($sh - $ch) / 2);
    }
    $dst = imagecreatetruecolor($tw, $th);
    if ($bg === null) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, $bg[0], $bg[1], $bg[2]));
        imagealphablending($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
    return $dst;
}

function acc_upload_dir($kind)
{
    $dir = WRP_PUBLIC . '/uploads/' . ($kind === 'covers' ? 'covers' : 'avatars');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

// Аватар из файла: квадрат 256x256 (WEBP, если GD умеет, иначе PNG). Возвращает имя файла или ''.
function acc_avatar_from_path($path, $userId, &$error)
{
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];
    if (defined('IMAGETYPE_WEBP')) {
        $types[] = IMAGETYPE_WEBP;
    }
    $img = acc_image_load($path, $types, $error);
    if (!$img) {
        return '';
    }
    $dst = acc_image_crop($img, ACC_AVATAR_SIZE, ACC_AVATAR_SIZE, null);
    imagedestroy($img);
    $webp = function_exists('imagewebp');
    $name = (int)$userId . '_' . bin2hex(random_bytes(8)) . ($webp ? '.webp' : '.png');
    $file = acc_upload_dir('avatars') . '/' . $name;
    $ok = $webp ? @imagewebp($dst, $file, 90) : @imagepng($dst, $file, 6);
    imagedestroy($dst);
    if (!$ok || !is_file($file)) {
        $error = 'Не удалось сохранить файл. Проверьте права на запись в папку public/uploads/avatars.';
        return '';
    }
    return $name;
}

// Обложка профиля из файла: пропорции 4:1, не шире 1600 точек, JPEG. Возвращает имя файла или ''.
function acc_cover_from_path($path, $userId, &$error)
{
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
    if (defined('IMAGETYPE_WEBP')) {
        $types[] = IMAGETYPE_WEBP;
    }
    $img = acc_image_load($path, $types, $error);
    if (!$img) {
        return '';
    }
    $sw = imagesx($img);
    $sh = imagesy($img);
    $cw = min($sw, $sh * 4);
    if ($cw < 400) {
        imagedestroy($img);
        $error = 'Изображение слишком маленькое. Нужно хотя бы 400 x 100 точек, лучше 1600 x 400.';
        return '';
    }
    $tw = min(ACC_COVER_W, $cw);
    $th = max(1, (int)round($tw / 4));
    $dst = acc_image_crop($img, $tw, $th, [22, 20, 30]);
    imagedestroy($img);
    if (function_exists('imageinterlace')) {
        imageinterlace($dst, true);
    }
    $name = (int)$userId . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $file = acc_upload_dir('covers') . '/' . $name;
    $ok = @imagejpeg($dst, $file, 85);
    imagedestroy($dst);
    if (!$ok || !is_file($file)) {
        $error = 'Не удалось сохранить файл. Проверьте права на запись в папку public/uploads/covers.';
        return '';
    }
    return $name;
}

// Удаление старого файла: только наши имена вида 12_abcdef.webp, без путей
function acc_upload_remove($kind, $name)
{
    $name = (string)$name;
    if (!preg_match('~^\d+_[a-f0-9]{8,40}\.(png|webp|jpe?g|gif)$~', $name)) {
        return;
    }
    $file = acc_upload_dir($kind) . '/' . $name;
    if (is_file($file)) {
        @unlink($file);
    }
}

// ---------- Отображение пользователя ----------

function acc_is_online($u)
{
    return !empty($u['last_activity']) && strtotime($u['last_activity']) > time() - ACC_ONLINE_SECONDS;
}

function acc_lcfirst($s)
{
    return mb_strtolower(mb_substr((string)$s, 0, 1)) . mb_substr((string)$s, 1);
}

function acc_ucfirst($s)
{
    return mb_strtoupper(mb_substr((string)$s, 0, 1)) . mb_substr((string)$s, 1);
}

// "В сети" или "Был(а) сегодня в 12:30"
function acc_seen_text($u)
{
    if (acc_is_online($u)) {
        return 'В сети';
    }
    if (empty($u['last_activity'])) {
        return 'Ещё не заходил(а)';
    }
    return 'Был(а) ' . acc_lcfirst(fdate($u['last_activity']));
}

// Звание под ником: своё или название группы
function acc_user_title($u)
{
    $t = trim((string)($u['custom_title'] ?? ''));
    return $t !== '' ? $t : (string)($u['group_name'] ?? '');
}

// Основная и дополнительные группы: [группа, ...]
function acc_user_groups($u)
{
    $groups = groups_all();
    $out = [];
    foreach (array_merge([(int)($u['group_id'] ?? 0)], user_secondary_ids($u)) as $gid) {
        if (isset($groups[$gid])) {
            $out[$gid] = $groups[$gid];
        }
    }
    return array_values($out);
}

function acc_group_badges($u)
{
    $h = '';
    foreach (acc_user_groups($u) as $g) {
        $h .= group_badge(['group_name' => $g['name'], 'group_color' => $g['color']]) . ' ';
    }
    return trim($h);
}

// Пользователь из строки запроса с полями-префиксами: acc_user_from_row($row, 'author_')
function acc_user_from_row(array $r, $prefix = '')
{
    return [
        'id' => (int)($r[$prefix . 'id'] ?? 0),
        'username' => $r[$prefix . 'username'] ?? '',
        'avatar' => $r[$prefix . 'avatar'] ?? null,
        'group_color' => $r[$prefix . 'group_color'] ?? '',
    ];
}

function acc_member_url($id, array $params = [])
{
    return url('/forum/member.php', array_merge(['id' => (int)$id], $params));
}

// Условие "пользователь в группе (основной или дополнительной)" для SQL
function acc_group_sql($gid, $p = 'g')
{
    return [
        'sql' => '(u.group_id = :' . $p . '1 OR CONCAT(\',\', REPLACE(COALESCE(u.secondary_groups, \'\'), \' \', \'\'), \',\') LIKE :' . $p . '2)',
        'params' => [$p . '1' => (int)$gid, $p . '2' => '%,' . (int)$gid . ',%'],
    ];
}

// Строка поиска для LIKE с экранированием % и _
function acc_like($s)
{
    return '%' . addcslashes((string)$s, '\\%_') . '%';
}

// Карточка участника (списки участников, администрация, онлайн)
function acc_member_card($u, $extra = '')
{
    $h = '<div class="acc-mcard">';
    $h .= '<a class="acc-mcard-avatar" href="' . e(acc_member_url($u['id'])) . '">' . avatar($u, 'l') . (acc_is_online($u) ? '<span class="acc-dot-online" title="В сети"></span>' : '') . '</a>';
    $h .= '<div class="acc-mcard-body">';
    $h .= '<div class="acc-mcard-name">' . user_link($u) . '</div>';
    $h .= '<div class="acc-mcard-title">' . e(acc_user_title($u)) . '</div>';
    $h .= $extra;
    $h .= '</div></div>';
    return $h;
}

// ---------- Разделы ----------

// id форумов, которые может смотреть текущий посетитель
function acc_viewable_node_ids()
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        foreach (nodes_all() as $id => $n) {
            if ($n['type'] === 'forum' && node_can_view($n)) {
                $ids[] = (int)$id;
            }
        }
    }
    return $ids;
}

// Ссылка на раздел из настроек (rules_node_id, tech_node_id) или ''
function acc_setting_node_url($key)
{
    $n = node_get(setting_int($key));
    return $n ? node_url($n) : '';
}

// ---------- Подписки и игнор ----------

function acc_is_following($userId, $targetId)
{
    return (bool)db_val('SELECT 1 FROM user_follows WHERE user_id = :u AND follow_user_id = :t', ['u' => (int)$userId, 't' => (int)$targetId]);
}

// Игнорирует ли $userId пользователя $targetId
function acc_is_ignoring($userId, $targetId)
{
    return (bool)db_val('SELECT 1 FROM user_ignores WHERE user_id = :u AND ignored_user_id = :t', ['u' => (int)$userId, 't' => (int)$targetId]);
}

// Можно ли текущему пользователю игнорировать $target (себя и администрацию нельзя)
function acc_can_ignore($target)
{
    return is_logged() && (int)$target['id'] !== uid() && empty($target['is_staff']);
}

// Кто из $ownerIds игнорирует текущего пользователя: [id => true]
function acc_ignored_by(array $ownerIds)
{
    $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds))));
    if (!is_logged() || !$ownerIds || is_staff()) {
        return [];
    }
    $in = db_in($ownerIds, 'o');
    $out = [];
    foreach (db_all('SELECT user_id FROM user_ignores WHERE ignored_user_id = :me AND user_id IN (' . $in['sql'] . ')', array_merge(['me' => uid()], $in['params'])) as $r) {
        $out[(int)$r['user_id']] = true;
    }
    return $out;
}

function acc_follow_counts($userId)
{
    return [
        'followers' => (int)db_val('SELECT COUNT(*) FROM user_follows WHERE follow_user_id = :u', ['u' => (int)$userId]),
        'following' => (int)db_val('SELECT COUNT(*) FROM user_follows WHERE user_id = :u', ['u' => (int)$userId]),
    ];
}

// Подписчики ('followers') или подписки ('following') пользователя
function acc_follow_list($userId, $which, $limit = 50)
{
    $join = $which === 'followers' ? 'f.user_id' : 'f.follow_user_id';
    $where = $which === 'followers' ? 'f.follow_user_id' : 'f.user_id';
    return db_all('SELECT u.id, u.username, u.avatar, u.custom_title, u.last_activity, g.name AS group_name, g.color AS group_color, f.created_at AS followed_at
        FROM user_follows f JOIN users u ON u.id = ' . $join . ' JOIN user_groups g ON g.id = u.group_id
        WHERE ' . $where . ' = :u ORDER BY f.created_at DESC LIMIT :lim', ['u' => (int)$userId, 'lim' => (int)$limit]);
}

// Оповещение без повторов: не создаёт второе непрочитанное с тем же смыслом
function acc_alert_once($to, $type, $postId = null, $extra = null)
{
    $to = (int)$to;
    if ($to <= 0 || $to === uid()) {
        return;
    }
    $exists = db_val('SELECT 1 FROM alerts WHERE user_id = :u AND type = :t AND actor_id = :a AND is_read = 0
        AND COALESCE(post_id, 0) = :p AND COALESCE(extra, \'\') = :x LIMIT 1',
        ['u' => $to, 't' => $type, 'a' => uid(), 'p' => (int)$postId, 'x' => $extra === null ? '' : (string)$extra]);
    if (!$exists) {
        alert_add($to, $type, uid(), null, $postId, $extra);
    }
}

// ---------- Стена профиля ----------

// Флуд-контроль стены: 10 секунд между сообщениями и комментариями (администрация без ограничений)
function acc_wall_flood_ok()
{
    if (is_staff()) {
        return true;
    }
    $t = date('Y-m-d H:i:s', time() - ACC_WALL_FLOOD_SECONDS);
    $a = db_val('SELECT 1 FROM profile_posts WHERE user_id = :u AND created_at > :t LIMIT 1', ['u' => uid(), 't' => $t]);
    $b = db_val('SELECT 1 FROM profile_comments WHERE user_id = :u AND created_at > :t LIMIT 1', ['u' => uid(), 't' => $t]);
    return !$a && !$b;
}

// Сообщения стены с авторами и владельцами профилей
function acc_wall_query($where, array $params, $limit, $offset = 0)
{
    return db_all('SELECT pp.*, u.username AS author_username, u.avatar AS author_avatar, g.color AS author_group_color,
            pu.username AS owner_username, pu.avatar AS owner_avatar, pg.color AS owner_group_color
        FROM profile_posts pp
        JOIN users u ON u.id = pp.user_id JOIN user_groups g ON g.id = u.group_id
        JOIN users pu ON pu.id = pp.profile_user_id JOIN user_groups pg ON pg.id = pu.group_id
        WHERE ' . $where . ' ORDER BY pp.id DESC LIMIT :lim OFFSET :off',
        array_merge($params, ['lim' => (int)$limit, 'off' => (int)$offset]));
}

// Комментарии к сообщениям: [post_id => [комментарии]]
function acc_wall_comments(array $postIds)
{
    $out = [];
    if (!$postIds) {
        return $out;
    }
    $in = db_in(array_map('intval', $postIds), 'p');
    $rows = db_all('SELECT c.*, u.username AS author_username, u.avatar AS author_avatar, g.color AS author_group_color
        FROM profile_comments c JOIN users u ON u.id = c.user_id JOIN user_groups g ON g.id = u.group_id
        WHERE c.is_deleted = 0 AND c.profile_post_id IN (' . $in['sql'] . ') ORDER BY c.id ASC', $in['params']);
    foreach ($rows as $r) {
        $out[(int)$r['profile_post_id']][] = $r;
    }
    return $out;
}

// Кто оценил: ['post' => [id => [пользователи]], 'comment' => [...]], свежие первыми
function acc_wall_likes(array $postIds, array $commentIds)
{
    $out = ['post' => [], 'comment' => []];
    foreach (['post' => $postIds, 'comment' => $commentIds] as $type => $ids) {
        if (!$ids) {
            continue;
        }
        $in = db_in(array_map('intval', $ids), 'c');
        $rows = db_all('SELECT l.content_id, u.id, u.username, u.avatar, g.color AS group_color
            FROM profile_likes l JOIN users u ON u.id = l.user_id JOIN user_groups g ON g.id = u.group_id
            WHERE l.content_type = :t AND l.content_id IN (' . $in['sql'] . ') ORDER BY l.created_at DESC',
            array_merge(['t' => $type], $in['params']));
        foreach ($rows as $r) {
            $out[$type][(int)$r['content_id']][] = $r;
        }
    }
    return $out;
}

// Строка «Нравится: Вы, Name и ещё 2»
function acc_likes_line(array $likers)
{
    if (!$likers) {
        return '';
    }
    $me = null;
    $others = [];
    foreach ($likers as $l) {
        if ((int)$l['id'] === uid()) {
            $me = $l;
        } else {
            $others[] = $l;
        }
    }
    $names = [];
    if ($me) {
        $names[] = '<a class="username" href="' . e(acc_member_url($me['id'])) . '">Вы</a>';
    }
    foreach ($others as $l) {
        if (count($names) >= 3) {
            break;
        }
        $names[] = user_link($l);
    }
    $rest = count($likers) - count($names);
    if ($rest > 0) {
        $text = implode(', ', $names) . ' и ещё ' . num($rest) . ' ' . plural($rest, 'человек', 'человека', 'человек');
    } elseif (count($names) > 1) {
        $last = array_pop($names);
        $text = implode(', ', $names) . ' и ' . $last;
    } else {
        $text = $names[0];
    }
    return '<span class="acc-like-icon">' . icon('like') . '</span><span>' . $text . '</span>';
}

function acc_user_liked(array $likers)
{
    foreach ($likers as $l) {
        if ((int)$l['id'] === uid()) {
            return true;
        }
    }
    return false;
}

// Кнопка «Нравится» (работает и без JS: обычная форма)
function acc_like_button($type, $id, $ownerId, $liked, $return)
{
    if (!is_logged() || is_banned()) {
        return '';
    }
    return '<form class="acc-inline-form" method="post" action="' . e(acc_member_url($ownerId)) . '" data-acc-like>'
        . csrf_field()
        . '<input type="hidden" name="action" value="like"><input type="hidden" name="type" value="' . e($type) . '">'
        . '<input type="hidden" name="cid" value="' . (int)$id . '"><input type="hidden" name="return" value="' . e($return) . '">'
        . '<button class="acc-link-btn' . ($liked ? ' is-liked' : '') . '" type="submit">' . icon('like') . '<span>' . ($liked ? 'Не нравится' : 'Нравится') . '</span></button></form>';
}

function acc_delete_button($action, $id, $ownerId, $return, $question)
{
    return '<form class="acc-inline-form" method="post" action="' . e(acc_member_url($ownerId)) . '" data-confirm="' . e($question) . '">'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="cid" value="' . (int)$id . '">'
        . '<input type="hidden" name="return" value="' . e($return) . '">'
        . '<button class="acc-link-btn acc-link-danger" type="submit">' . icon('trash') . '<span>Удалить</span></button></form>';
}

// Можно ли писать на стене владельца
function acc_wall_can_post($ownerId, array $ignoredBy = [])
{
    return is_logged() && !is_banned() && empty($ignoredBy[(int)$ownerId]);
}

// Блок «игнорируемый пользователь» вокруг содержимого
function acc_ignored_wrap($html, $authorId, $what = 'Сообщение')
{
    if (!in_array((int)$authorId, ignored_user_ids(), true)) {
        return $html;
    }
    return '<details class="acc-ignored"><summary>' . icon('eye-off') . ' ' . e($what) . ' от игнорируемого пользователя - показать</summary>' . $html . '</details>';
}

// Вывод сообщений стены вместе с комментариями. $opts: show_owner (показывать «в профиле ...»), return (адрес возврата)
function acc_wall_render(array $posts, array $opts = [])
{
    if (!$posts) {
        return '';
    }
    $showOwner = !empty($opts['show_owner']);
    $return = (string)($opts['return'] ?? '');
    $postIds = array_map(function ($p) {
        return (int)$p['id'];
    }, $posts);
    $comments = acc_wall_comments($postIds);
    $commentIds = [];
    foreach ($comments as $list) {
        foreach ($list as $c) {
            $commentIds[] = (int)$c['id'];
        }
    }
    $likes = acc_wall_likes($postIds, $commentIds);
    $ignoredBy = acc_ignored_by(array_map(function ($p) {
        return (int)$p['profile_user_id'];
    }, $posts));

    $h = '';
    foreach ($posts as $p) {
        $pid = (int)$p['id'];
        $ownerId = (int)$p['profile_user_id'];
        $author = acc_user_from_row($p + ['author_id' => $p['user_id']], 'author_');
        $owner = acc_user_from_row($p + ['owner_id' => $ownerId], 'owner_');
        $ret = $return !== '' ? $return . '#profile-post-' . $pid : acc_member_url($ownerId) . '#profile-post-' . $pid;
        $pLikers = $likes['post'][$pid] ?? [];
        $canDelete = is_logged() && (uid() === (int)$p['user_id'] || uid() === $ownerId || can_moderate());
        $canComment = acc_wall_can_post($ownerId, $ignoredBy);
        $isStatus = (int)$p['user_id'] === $ownerId;

        $inner = '<a class="acc-wall-avatar" href="' . e(acc_member_url($author['id'])) . '">' . avatar($author, 'l') . '</a>';
        $inner .= '<div class="acc-wall-main">';
        $inner .= '<div class="acc-wall-head">' . user_link($author);
        if ($showOwner && !$isStatus) {
            $inner .= ' <span class="acc-wall-arrow">' . icon('chevron-right') . '</span> ' . user_link($owner);
        } elseif ($showOwner && $isStatus) {
            $inner .= ' <span class="muted small">обновил(а) статус</span>';
        }
        $inner .= '</div>';
        $inner .= '<div class="bb acc-wall-body">' . bbcode($p['body']) . '</div>';
        $inner .= '<div class="acc-wall-foot">';
        $inner .= '<a class="acc-wall-date" href="' . e(acc_member_url($ownerId)) . '#profile-post-' . $pid . '">' . e(fdate($p['created_at'])) . '</a>';
        $inner .= acc_like_button('post', $pid, $ownerId, acc_user_liked($pLikers), $ret);
        if ($canComment) {
            $inner .= '<button class="acc-link-btn" type="button" data-acc-comment-focus="' . $pid . '">' . icon('reply') . '<span>Комментировать</span></button>';
        }
        if ($canDelete) {
            $inner .= acc_delete_button('wall_delete', $pid, $ownerId, $return !== '' ? $return : acc_member_url($ownerId), 'Удалить это сообщение вместе с комментариями?');
        }
        $inner .= '</div>';
        $inner .= '<div class="acc-likes-line" data-likes="post-' . $pid . '"' . ($pLikers ? '' : ' hidden') . '>' . acc_likes_line($pLikers) . '</div>';

        $list = $comments[$pid] ?? [];
        if ($list || $canComment) {
            $inner .= '<div class="acc-comments">';
            $hiddenCount = count($list) > 4 ? count($list) - 3 : 0;
            if ($hiddenCount) {
                $inner .= '<details class="acc-comments-more"><summary>Показать предыдущие комментарии (' . num($hiddenCount) . ')</summary>';
            }
            foreach ($list as $i => $c) {
                if ($hiddenCount && $i === $hiddenCount) {
                    $inner .= '</details>';
                }
                $inner .= acc_comment_render($c, $ownerId, (int)$p['user_id'], $likes['comment'][(int)$c['id']] ?? [], $return !== '' ? $return : acc_member_url($ownerId));
            }
            if ($canComment) {
                $me = user();
                $inner .= '<form class="acc-comment-form" method="post" action="' . e(acc_member_url($ownerId)) . '" data-acc-expand>'
                    . csrf_field()
                    . '<input type="hidden" name="action" value="comment"><input type="hidden" name="cid" value="' . $pid . '">'
                    . '<input type="hidden" name="return" value="' . e($ret) . '">'
                    . avatar($me, 's')
                    . '<div class="acc-expand-body"><textarea class="textarea acc-expand-input" name="body" rows="1" maxlength="1000" placeholder="Напишите комментарий..." id="comment-' . $pid . '" required></textarea>'
                    . '<div class="acc-expand-actions"><button class="btn btn-white btn-sm" type="submit">' . icon('send') . ' Отправить</button></div></div></form>';
            }
            $inner .= '</div>';
        }
        $inner .= '</div>';

        $h .= '<article class="acc-wall-post" id="profile-post-' . $pid . '">' . acc_ignored_wrap('<div class="acc-wall-row">' . $inner . '</div>', $p['user_id']) . '</article>';
    }
    return $h;
}

function acc_comment_render(array $c, $ownerId, $postAuthorId, array $likers, $return)
{
    $cid = (int)$c['id'];
    $author = acc_user_from_row($c + ['author_id' => $c['user_id']], 'author_');
    $canDelete = is_logged() && (uid() === (int)$c['user_id'] || uid() === (int)$ownerId || can_moderate());
    $h = '<div class="acc-comment-row">';
    $h .= '<a href="' . e(acc_member_url($author['id'])) . '">' . avatar($author, 's') . '</a>';
    $h .= '<div class="acc-comment-main">';
    $h .= '<div class="acc-comment-text">' . user_link($author) . ' <span class="bb">' . bbcode($c['body']) . '</span></div>';
    $h .= '<div class="acc-wall-foot acc-comment-foot"><span class="acc-wall-date">' . e(fdate($c['created_at'])) . '</span>';
    $h .= acc_like_button('comment', $cid, $ownerId, acc_user_liked($likers), $return . '#profile-comment-' . $cid);
    if ($canDelete) {
        $h .= acc_delete_button('comment_delete', $cid, $ownerId, $return, 'Удалить этот комментарий?');
    }
    $h .= '</div>';
    $h .= '<div class="acc-likes-line" data-likes="comment-' . $cid . '"' . ($likers ? '' : ' hidden') . '>' . acc_likes_line($likers) . '</div>';
    $h .= '</div></div>';
    return '<div class="acc-comment" id="profile-comment-' . $cid . '">' . acc_ignored_wrap($h, $c['user_id'], 'Комментарий') . '</div>';
}

// Пересчёт лайков записи стены
function acc_wall_like_toggle($type, $id)
{
    $id = (int)$id;
    $table = $type === 'post' ? 'profile_posts' : 'profile_comments';
    $liked = (bool)db_val('SELECT 1 FROM profile_likes WHERE content_type = :t AND content_id = :c AND user_id = :u', ['t' => $type, 'c' => $id, 'u' => uid()]);
    if ($liked) {
        db_exec('DELETE FROM profile_likes WHERE content_type = :t AND content_id = :c AND user_id = :u', ['t' => $type, 'c' => $id, 'u' => uid()]);
    } else {
        db_exec('INSERT IGNORE INTO profile_likes (content_type, content_id, user_id, reaction, created_at) VALUES (:t, :c, :u, :r, :d)',
            ['t' => $type, 'c' => $id, 'u' => uid(), 'r' => 'like', 'd' => now()]);
    }
    $count = (int)db_val('SELECT COUNT(*) FROM profile_likes WHERE content_type = :t AND content_id = :c', ['t' => $type, 'c' => $id]);
    db_update($table, ['likes_count' => $count], 'id = :id', ['id' => $id]);
    return ['liked' => !$liked, 'count' => $count];
}

// ---------- Личные переписки ----------

// Переписка, если текущий пользователь в ней участвует (и не вышел), иначе null
function acc_conv_load($id)
{
    if (!is_logged()) {
        return null;
    }
    return db_one('SELECT c.*, cu.last_read_at, cu.is_left FROM conversations c
        JOIN conversation_users cu ON cu.conversation_id = c.id AND cu.user_id = :u
        WHERE c.id = :id AND cu.is_left = 0', ['u' => uid(), 'id' => (int)$id]);
}

// Участники переписки (включая вышедших)
function acc_conv_users($convId)
{
    return db_all('SELECT u.id, u.username, u.avatar, u.custom_title, u.last_activity, g.name AS group_name, g.color AS group_color,
            cu.is_left, cu.last_read_at
        FROM conversation_users cu JOIN users u ON u.id = cu.user_id JOIN user_groups g ON g.id = u.group_id
        WHERE cu.conversation_id = :c ORDER BY u.username', ['c' => (int)$convId]);
}

// Флуд-контроль личных сообщений
function acc_conv_flood_ok()
{
    if (is_staff()) {
        return true;
    }
    $last = db_val('SELECT MAX(created_at) FROM conversation_messages WHERE user_id = :u', ['u' => uid()]);
    return !$last || strtotime($last) <= time() - ACC_CONV_FLOOD_SECONDS;
}

// Список ников через запятую -> уникальные ники
function acc_parse_names($s)
{
    $out = [];
    foreach (preg_split('~[,;\n]+~u', (string)$s) as $n) {
        $n = acc_clean_line($n);
        if ($n !== '') {
            $out[mb_strtolower($n)] = $n;
        }
    }
    return array_values($out);
}

// Новое сообщение в переписке: обновляет счётчики, отмечает прочитанным для автора
function acc_conv_add_message($convId, $body, $isFirst = false)
{
    $now = now();
    $mid = db_insert('conversation_messages', [
        'conversation_id' => (int)$convId,
        'user_id' => uid(),
        'body' => $body,
        'created_at' => $now,
    ]);
    db_exec('UPDATE conversations SET last_message_id = :m, last_message_at = :t, last_message_user_id = :u'
        . ($isFirst ? '' : ', reply_count = reply_count + 1') . ' WHERE id = :id',
        ['m' => $mid, 't' => $now, 'u' => uid(), 'id' => (int)$convId]);
    db_exec('UPDATE conversation_users SET last_read_at = :t WHERE conversation_id = :c AND user_id = :u', ['t' => $now, 'c' => (int)$convId, 'u' => uid()]);
    return $mid;
}

// Оповестить участников (кроме себя и вышедших)
function acc_conv_notify($convId, $userIds = null)
{
    if ($userIds === null) {
        $userIds = array_map('intval', array_column(db_all('SELECT user_id FROM conversation_users WHERE conversation_id = :c AND is_left = 0', ['c' => (int)$convId]), 'user_id'));
    }
    foreach ($userIds as $id) {
        acc_alert_once($id, 'conversation', null, (string)(int)$convId);
    }
}

// ---------- Игровой аккаунт ----------

// Вызов функции игровой базы без падения страницы: [результат, ошибка]
function acc_game_call($fn, array $args)
{
    try {
        return [call_user_func_array($fn, $args), ''];
    } catch (Throwable $ex) {
        error_log('[game_db] ' . $ex->getMessage());
        return [null, 'Игровая база сейчас недоступна. Попробуйте позже.'];
    }
}

// Ник в формате SA-MP: латиница, цифры и [ ] ( ) $ @ . _ =, 3-24 символа
function acc_game_nick_valid($nick)
{
    return (bool)preg_match('~^[A-Za-z0-9_\[\]\(\)\$@\.=]{3,24}$~', (string)$nick);
}
