<?php
// Модуль «Контент»: картинки-вложения (доказательства к жалобам, скриншоты).
//
// Как устроено:
// - Редактор отправляет файл на /forum/upload.php, сервер проверяет его и ПЕРЕКОДИРУЕТ через GD
//   (EXIF и любые чужие данные внутри файла пропадают), имя файла случайное, расширение по типу картинки.
// - JPG остаётся JPG, PNG - PNG, WEBP - WEBP. GIF сохраняется как PNG только с первым кадром:
//   так любой файл гарантированно перекодирован (анимация не сохраняется).
// - Большие картинки уменьшаются до attach_max_px по длинной стороне, для больших делается превью 320 точек.
// - Файл лежит в public/uploads/attachments/ГГГГ/ММ/, в текст вставляется [img]/uploads/attachments/...[/img].
// - После отправки сообщения файлы автора из текста привязываются к сообщению (post_id).
//   Непривязанные файлы старше суток удаляет фоновая задача content_attach_cleanup.

const CNT_THUMB_SIDE = 320;
const CNT_ORPHAN_HOURS = 24;

function cnt_attach_dir()
{
    return WRP_PUBLIC . '/uploads/attachments';
}

// Адрес файла для сайта (с base_path): /uploads/attachments/2026/10/abc.png
function cnt_attach_url($path)
{
    return url('/uploads/attachments/' . $path);
}

// Путь вложения допустим: ГГГГ/ММ/имя.расширение (без ../ и прочего)
function cnt_attach_path_ok($path)
{
    return is_string($path) && (bool)preg_match('~^\d{4}/\d{2}/[a-z0-9_\-]{8,64}\.(?:jpe?g|png|gif|webp)$~', $path);
}

// Предел размера одного файла в байтах с учётом настроек PHP
function cnt_attach_max_bytes()
{
    $mb = (int)setting('attach_max_mb', 8);
    $mb = max(1, min(64, $mb ?: 8));
    return acc_upload_limit($mb * 1048576);
}

// Большие картинки уменьшаются до этой стороны
function cnt_attach_max_px()
{
    $px = (int)setting('attach_max_px', 2560);
    return max(800, min(8000, $px ?: 2560));
}

// Размер для людей: 512 КБ, 3,4 МБ, 12,5 ГБ
function cnt_bytes_text($b)
{
    $b = (float)$b;
    if ($b >= 1073741824) {
        return rtrim(rtrim(number_format($b / 1073741824, 1, ',', ''), '0'), ',') . ' ГБ';
    }
    return $b > 0 ? acc_bytes_text((int)$b) : '0 КБ';
}

// Разрешённые типы GD: [IMAGETYPE_* => ['mime', 'расширение файла на выходе']]
function cnt_attach_types()
{
    $t = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_GIF => ['image/gif', 'png'],
    ];
    if (defined('IMAGETYPE_WEBP') && function_exists('imagecreatefromwebp')) {
        $t[IMAGETYPE_WEBP] = ['image/webp', function_exists('imagewebp') ? 'webp' : 'png'];
    }
    return $t;
}

// Лимит на сутки: '' - можно загружать, иначе текст ошибки. Команда проекта без ограничений.
function cnt_attach_quota_error($userId, $isStaff = false)
{
    if ($isStaff) {
        return '';
    }
    $files = (int)setting('attach_daily_files', 30);
    $mb = (int)setting('attach_daily_mb', 100);
    if ($files <= 0 && $mb <= 0) {
        return '';
    }
    $row = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS s FROM attachments WHERE user_id = :u AND created_at > :t',
        ['u' => (int)$userId, 't' => date('Y-m-d H:i:s', time() - 86400)]);
    if ($files > 0 && (int)$row['n'] >= $files) {
        return 'Лимит загрузок на сутки исчерпан: не больше ' . $files . ' ' . plural($files, 'картинки', 'картинок', 'картинок') . '. Попробуйте завтра.';
    }
    if ($mb > 0 && (int)$row['s'] >= $mb * 1048576) {
        return 'Лимит загрузок на сутки исчерпан: не больше ' . $mb . ' МБ. Попробуйте завтра.';
    }
    return '';
}

// Холст нужного размера с прозрачным фоном (или заливкой для JPEG)
function cnt_attach_canvas($w, $h, $opaque)
{
    $dst = imagecreatetruecolor($w, $h);
    if ($opaque) {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
    } else {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    return $dst;
}

// Уменьшенная копия по длинной стороне (или та же картинка, если она меньше)
function cnt_attach_fit($img, $side, $opaque)
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= $side && $h <= $side) {
        return null;
    }
    $k = $side / max($w, $h);
    $tw = max(1, (int)round($w * $k));
    $th = max(1, (int)round($h * $k));
    $dst = cnt_attach_canvas($tw, $th, $opaque);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    return $dst;
}

function cnt_attach_write($img, $file, $ext, $quality = 88)
{
    if ($ext === 'jpg') {
        if (function_exists('imageinterlace')) {
            imageinterlace($img, true);
        }
        return @imagejpeg($img, $file, $quality);
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);
    if ($ext === 'webp') {
        return @imagewebp($img, $file, $quality);
    }
    return @imagepng($img, $file, 6);
}

// Проверяет картинку и сохраняет перекодированную копию в public/uploads/attachments/ГГГГ/ММ/.
// Возвращает ['path', 'thumb', 'size', 'width', 'height'] или null с текстом в $error.
function cnt_attach_save_image($src, &$error)
{
    $error = '';
    if (!is_file($src) || filesize($src) < 12) {
        $error = 'Файл пустой или повреждён.';
        return null;
    }
    $types = cnt_attach_types();
    $info = @getimagesize($src);
    if (!$info || !isset($types[(int)$info[2]])) {
        $error = 'Можно загружать только картинки JPG, PNG, GIF или WEBP.';
        return null;
    }
    $type = (int)$info[2];
    // Второе мнение о типе по содержимому файла (fileinfo)
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)@finfo_file($fi, $src) : '';
        if ($fi) {
            finfo_close($fi);
        }
        if ($mime !== '' && $mime !== $types[$type][0]) {
            $error = 'Файл не похож на картинку (тип ' . $mime . '). Сохраните скриншот заново и попробуйте ещё раз.';
            return null;
        }
    }
    $img = acc_image_load($src, array_keys($types), $error);
    if (!$img) {
        return null;
    }
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    $ext = $types[$type][1];
    $opaque = $ext === 'jpg';

    $big = cnt_attach_fit($img, cnt_attach_max_px(), $opaque);
    if ($big) {
        imagedestroy($img);
        $img = $big;
    }
    $w = imagesx($img);
    $h = imagesy($img);

    $sub = date('Y') . '/' . date('m');
    $dir = cnt_attach_dir() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($img);
        $error = 'Не удалось создать папку для файлов. Проверьте права на запись в public/uploads/attachments.';
        return null;
    }
    $name = bin2hex(random_bytes(10));
    $path = $sub . '/' . $name . '.' . $ext;
    $file = cnt_attach_dir() . '/' . $path;
    if (!cnt_attach_write($img, $file, $ext) || !is_file($file)) {
        imagedestroy($img);
        @unlink($file);
        $error = 'Не удалось сохранить файл. Проверьте права на запись в public/uploads/attachments.';
        return null;
    }

    $thumb = null;
    $small = cnt_attach_fit($img, CNT_THUMB_SIDE, $opaque);
    if ($small) {
        $tp = $sub . '/' . $name . '_t.' . $ext;
        if (cnt_attach_write($small, cnt_attach_dir() . '/' . $tp, $ext, 80)) {
            $thumb = $tp;
        }
        imagedestroy($small);
    }
    imagedestroy($img);

    // Итог ещё раз проверяется как картинка
    $check = @getimagesize($file);
    if (!$check) {
        cnt_attach_unlink(['path' => $path, 'thumb' => $thumb]);
        $error = 'Не удалось обработать картинку. Попробуйте другой файл.';
        return null;
    }
    clearstatcache(true, $file);
    return ['path' => $path, 'thumb' => $thumb, 'size' => (int)filesize($file), 'width' => $w, 'height' => $h];
}

// Загрузка файла пользователем: проверка лимита, обработка, запись в базу. Строка attachments или null.
function cnt_attach_create($userId, $src, &$error, $isStaff = false)
{
    $error = cnt_attach_quota_error($userId, $isStaff);
    if ($error !== '') {
        return null;
    }
    $saved = cnt_attach_save_image($src, $error);
    if (!$saved) {
        return null;
    }
    $row = [
        'user_id' => (int)$userId,
        'path' => $saved['path'],
        'thumb' => $saved['thumb'],
        'size' => $saved['size'],
        'width' => $saved['width'],
        'height' => $saved['height'],
        'post_id' => null,
        'created_at' => now(),
    ];
    try {
        $row['id'] = db_insert('attachments', $row);
    } catch (Exception $e) {
        cnt_attach_unlink($row);
        throw $e;
    }
    return $row;
}

// Данные о загруженном файле для редактора (JSON)
function cnt_attach_public(array $row)
{
    $url = cnt_attach_url($row['path']);
    return [
        'id' => (int)$row['id'],
        'url' => $url,
        'abs' => cnt_abs($url),
        'thumb' => $row['thumb'] ? cnt_attach_url($row['thumb']) : $url,
        'bbcode' => '[img]/uploads/attachments/' . $row['path'] . '[/img]',
        'width' => (int)$row['width'],
        'height' => (int)$row['height'],
        'size' => (int)$row['size'],
        'size_text' => acc_bytes_text((int)$row['size']),
    ];
}

// Удалить файлы вложения с диска
function cnt_attach_unlink(array $row)
{
    foreach (['path', 'thumb'] as $k) {
        if (!empty($row[$k]) && cnt_attach_path_ok($row[$k])) {
            $f = cnt_attach_dir() . '/' . $row[$k];
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}

// Полное удаление (админка): файлы и строка
function cnt_attach_delete(array $row)
{
    cnt_attach_unlink($row);
    db_exec('DELETE FROM attachments WHERE id = :id', ['id' => (int)$row['id']]);
}

// Пути вложений, упомянутые в тексте: ['2026/10/abc.png', ...]
function cnt_attach_paths_in($body)
{
    if (!preg_match_all('~/uploads/attachments/(\d{4}/\d{2}/[a-z0-9_\-]{8,64}\.(?:jpe?g|png|gif|webp))~i', (string)$body, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $p) {
        if (cnt_attach_path_ok($p)) {
            $out[$p] = true;
        }
    }
    return array_slice(array_keys($out), 0, 100);
}

// Привязать к сообщению свои ещё не привязанные файлы из текста
function cnt_attach_link($postId, $body, $userId)
{
    if (!$postId || !$userId || !cnt_ready()) {
        return 0;
    }
    $paths = cnt_attach_paths_in($body);
    if (!$paths) {
        return 0;
    }
    $in = db_in($paths, 'ap');
    return db_exec('UPDATE attachments SET post_id = :p WHERE user_id = :u AND post_id IS NULL AND path IN (' . $in['sql'] . ')',
        array_merge(['p' => (int)$postId, 'u' => (int)$userId], $in['params']));
}

// Удаление непривязанных файлов старше суток. Возвращает число удалённых.
function cnt_attach_cleanup($limit = 500)
{
    if (!cnt_ready()) {
        return 0;
    }
    $rows = db_all('SELECT id, path, thumb FROM attachments WHERE post_id IS NULL AND created_at < :t ORDER BY id LIMIT ' . (int)$limit,
        ['t' => date('Y-m-d H:i:s', time() - CNT_ORPHAN_HOURS * 3600)]);
    foreach ($rows as $r) {
        cnt_attach_delete($r);
    }
    return count($rows);
}

// Загрузка разрешена этому пользователю сейчас: '' или причина
function cnt_attach_denied()
{
    if (!is_logged()) {
        return 'Войдите, чтобы загружать картинки.';
    }
    if (is_banned()) {
        return 'Ваш аккаунт заблокирован.';
    }
    if (!cnt_ready()) {
        return 'Загрузка пока недоступна: администратору нужно нажать «Обновить базу» в админ-панели.';
    }
    if ((string)setting('attach_enabled') !== '1') {
        return 'Загрузка картинок отключена администрацией.';
    }
    return '';
}

// Скрытая метка в форме: на неё content.js добавляет кнопку загрузки к редактору и полям-ссылкам анкеты
function cnt_attach_marker()
{
    if (cnt_attach_denied() !== '') {
        return '';
    }
    $max = cnt_attach_max_bytes();
    return '<span hidden data-cnt-upload data-max="' . (int)$max . '" data-max-text="' . e(acc_bytes_text($max)) . '"></span>';
}

hook_add('thread_reply_form', function ($thread, $node) {
    return cnt_attach_marker();
}, 5);

hook_add('new_thread_form', function ($node, $useForm, $errors) {
    return cnt_attach_marker();
}, 5);

hook_add('post_edit_form', function ($post, $thread, $node) {
    return cnt_attach_marker();
}, 5);

// Свои картинки с полным адресом этого сайта (поле-ссылка анкеты) хранятся коротким путём,
// чтобы не сломаться при переезде на другой домен
hook_add('post_body_save', function ($body, $node, $thread) {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || stripos((string)$body, '/uploads/attachments/') === false) {
        return $body;
    }
    $base = rtrim((string)cfg('base_path', ''), '/');
    $re = '~\[img(=[0-9x]{1,9})?\]https?://' . preg_quote($host, '~') . preg_quote($base, '~')
        . '(/uploads/attachments/\d{4}/\d{2}/[a-z0-9_\-]{8,64}\.(?:jpe?g|png|gif|webp))\[/img\]~i';
    $out = preg_replace($re, '[img$1]$2[/img]', (string)$body);
    return $out === null ? $body : $out;
});

hook_add('thread_created', function ($threadId, $node, $postId) {
    if (!cnt_ready()) {
        return;
    }
    $body = (string)db_val('SELECT body FROM posts WHERE id = :id', ['id' => (int)$postId]);
    cnt_attach_link($postId, $body, uid());
});

hook_add('post_created', function ($postId, $thread, $node) {
    if (!cnt_ready()) {
        return;
    }
    $body = (string)db_val('SELECT body FROM posts WHERE id = :id', ['id' => (int)$postId]);
    cnt_attach_link($postId, $body, uid());
});

hook_add('post_updated', function ($post, $body, $thread, $node) {
    cnt_attach_link((int)$post['id'], $body, uid());
});

cron_register('content_attach_cleanup', 3600, function () {
    cnt_attach_cleanup();
});
