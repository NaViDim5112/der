<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка модуля «Контент»: php tests/content_test.php
// Загрузка картинок (проверка типа, перекодирование, PHP-код внутри картинки, размеры, лимиты, привязка, очистка),
// правила опросов, формат новостей для лаунчера и RSS (закрытые разделы не попадают никуда).
// Необязательно: CNT_BASE=http://127.0.0.1:8083 php tests/content_test.php - ещё и проверка заголовков по HTTP.
// Все тестовые строки и файлы удаляются в конце.
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$fail = 0;
function check($name, $cond, $out = '')
{
    global $fail;
    if (!$cond) {
        $fail++;
        echo "FAIL: $name\n  " . (is_string($out) ? $out : json_encode($out, JSON_UNESCAPED_UNICODE)) . "\n";
    } else {
        echo "ok: $name\n";
    }
}

if (!cnt_ready()) {
    echo "Таблицы модуля не созданы: php tools/migrate.php\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . '/cnt_test_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$saved = [];       // строки attachments или пути, созданные тестом
$cleanupSql = [];  // id тестовых строк

// Настройки: запомнить и вернуть в конце
$settingsBackup = [];
function test_setting($k, $v)
{
    global $settingsBackup;
    if (!array_key_exists($k, $settingsBackup)) {
        $settingsBackup[$k] = db_one('SELECT v FROM settings WHERE k = :k', ['k' => $k]);
    }
    setting_set($k, $v);
}

function make_png($file, $w, $h, $alpha = false)
{
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 2, 2, (int)($w / 2), (int)($h / 2), imagecolorallocatealpha($im, 255, 90, 54, 0));
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 50));
        imagefilledrectangle($im, 5, 5, (int)($w / 2), (int)($h / 2), imagecolorallocate($im, 255, 90, 54));
    }
    imagepng($im, $file);
    imagedestroy($im);
}

// ---------- Загрузка: проверка и перекодирование ----------

$f = "$tmp/shot.png";
make_png($f, 800, 450);
$r = cnt_attach_save_image($f, $err);
check('png принят', $r && preg_match('~^\d{4}/\d{2}/[a-f0-9]{20}\.png$~', $r['path']), $err);
if ($r) {
    $saved[] = $r;
    check('png размеры', $r['width'] === 800 && $r['height'] === 450, $r);
    check('png превью', $r['thumb'] && is_file(cnt_attach_dir() . '/' . $r['thumb']), $r);
    $ti = getimagesize(cnt_attach_dir() . '/' . $r['thumb']);
    check('превью 320 по длинной стороне', $ti && max($ti[0], $ti[1]) === CNT_THUMB_SIDE, $ti);
}

// PNG с PHP-кодом внутри (в конце файла и в текстовом блоке) - после перекодирования кода нет
$payload = '<?php echo "PWNED"; system($_GET["c"]); ?>';
$poly = "$tmp/poly.png";
make_png($poly, 64, 64);
$png = file_get_contents($poly);
$chunk = 'tEXt' . "Comment\0" . $payload;
$png = substr($png, 0, 33) . pack('N', strlen($chunk) - 4) . $chunk . pack('N', crc32($chunk)) . substr($png, 33) . $payload;
file_put_contents($poly, $png);
check('полиглот: исходник содержит PHP', strpos(file_get_contents($poly), '<?php') !== false);
$r = cnt_attach_save_image($poly, $err);
if ($r) {
    $saved[] = $r;
    $out = file_get_contents(cnt_attach_dir() . '/' . $r['path']);
    check('полиглот: перекодирован без PHP-кода', strpos($out, '<?php') === false && strpos($out, 'PWNED') === false);
} else {
    check('полиглот: отклонён', $err !== '', $err);
}

// JPEG с PHP-кодом в комментарии и EXIF (Make) - EXIF и код исчезают
$jpg = "$tmp/exif.jpg";
$im = imagecreatetruecolor(300, 200);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 100, 50));
imagejpeg($im, $jpg, 90);
imagedestroy($im);
$data = file_get_contents($jpg);
$tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x0F\x00\x02\x00\x00\x00\x04WRP\x00" . "\x00\x00\x00\x00";
$app1 = "Exif\x00\x00" . $tiff;
$com = $payload;
$data = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com . substr($data, 2);
file_put_contents($jpg, $data);
$ex = function_exists('exif_read_data') ? @exif_read_data($jpg) : null;
check('jpeg: исходник с EXIF', !function_exists('exif_read_data') || (is_array($ex) && ($ex['Make'] ?? '') === 'WRP'), $ex);
$r = cnt_attach_save_image($jpg, $err);
check('jpeg принят', (bool)$r, $err);
if ($r) {
    $saved[] = $r;
    $file = cnt_attach_dir() . '/' . $r['path'];
    $out = file_get_contents($file);
    $ex2 = function_exists('exif_read_data') ? @exif_read_data($file) : [];
    check('jpeg: расширение .jpg', substr($r['path'], -4) === '.jpg', $r['path']);
    check('jpeg: EXIF удалён', empty($ex2['Make']), $ex2);
    check('jpeg: PHP-код удалён', strpos($out, '<?php') === false);
    check('jpeg: маленький - без превью', $r['thumb'] === null, $r);
}

// PHP-файл под видом картинки
$fake = "$tmp/evil.png";
file_put_contents($fake, $payload);
$r = cnt_attach_save_image($fake, $err);
check('php под видом png отклонён', !$r && $err !== '', $err);
if ($r) {
    $saved[] = $r;
}

// GIF89a-заголовок + PHP: getimagesize может поверить заголовку, но GD его не откроет
$fakeGif = "$tmp/evil.gif";
file_put_contents($fakeGif, 'GIF89a' . pack('v', 10) . pack('v', 10) . "\x00\x00\x00" . $payload);
$r = cnt_attach_save_image($fakeGif, $err);
check('поддельный gif отклонён', !$r && $err !== '', $err);
if ($r) {
    $saved[] = $r;
}

// Неверное расширение: настоящая картинка с именем .php сохраняется по своему типу
$wrong = "$tmp/photo.php";
make_png($wrong, 120, 80);
$r = cnt_attach_save_image($wrong, $err);
check('имя файла не важно: .php -> .png', $r && substr($r['path'], -4) === '.png', $err);
if ($r) {
    $saved[] = $r;
}

// SVG, текст и HTML не принимаются
$svg = "$tmp/x.svg";
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>');
$r = cnt_attach_save_image($svg, $err);
check('svg отклонён', !$r, $err);

// GIF -> PNG (первый кадр)
$gif = "$tmp/a.gif";
$im = imagecreate(50, 40);
imagecolorallocate($im, 10, 200, 10);
imagegif($im, $gif);
imagedestroy($im);
$r = cnt_attach_save_image($gif, $err);
check('gif принят и сохранён как png', $r && substr($r['path'], -4) === '.png', $err);
if ($r) {
    $saved[] = $r;
}

// WEBP с прозрачностью
if (function_exists('imagewebp')) {
    $webp = "$tmp/a.webp";
    $im = imagecreatetruecolor(400, 400);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagewebp($im, $webp, 80);
    imagedestroy($im);
    $r = cnt_attach_save_image($webp, $err);
    check('webp принят', $r && substr($r['path'], -5) === '.webp', $err);
    if ($r) {
        $saved[] = $r;
    }
}

// Слишком большое разрешение
$huge = "$tmp/huge.png";
$im = imagecreatetruecolor(9000, 4);
imagepng($im, $huge);
imagedestroy($im);
$r = cnt_attach_save_image($huge, $err);
check('9000 точек по стороне отклонено', !$r && mb_stripos($err, 'разрешение') !== false, $err);

// Большая картинка уменьшается до attach_max_px
test_setting('attach_max_px', '1000');
$wide = "$tmp/wide.png";
make_png($wide, 3000, 600);
$r = cnt_attach_save_image($wide, $err);
check('большая уменьшена до 1000', $r && $r['width'] === 1000 && $r['height'] === 200, $r ?: $err);
if ($r) {
    $saved[] = $r;
}

// Размер файла: предел из настройки и из PHP
test_setting('attach_max_mb', '1');
check('предел 1 МБ', cnt_attach_max_bytes() <= 1048576, cnt_attach_max_bytes());
$big = "$tmp/big.bin";
file_put_contents($big, str_repeat('x', 1048576 + 10));
$_FILES['file'] = ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $big, 'error' => UPLOAD_ERR_OK, 'size' => filesize($big)];
$t = acc_upload_take('file', cnt_attach_max_bytes(), $err);
check('слишком большой файл отклонён', $t === '' && mb_stripos($err, 'слишком большой') !== false, $err);
$_FILES['file'] = ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
$t = acc_upload_take('file', cnt_attach_max_bytes(), $err);
check('предел php.ini - понятная ошибка', $t === '' && mb_stripos($err, 'Максимум') !== false, $err);
unset($_FILES['file']);

// Лимит на сутки
$qUser = 999901;
test_setting('attach_daily_files', '2');
test_setting('attach_daily_mb', '100');
foreach ([1, 2] as $i) {
    $cleanupSql[] = db_insert('attachments', ['user_id' => $qUser, 'path' => '2000/01/quota' . $i . 'test00000000.png', 'thumb' => null, 'size' => 1000, 'width' => 1, 'height' => 1, 'post_id' => null, 'created_at' => now()]);
}
check('лимит файлов в сутки', cnt_attach_quota_error($qUser) !== '', cnt_attach_quota_error($qUser));
check('команда проекта без лимита', cnt_attach_quota_error($qUser, true) === '');
$r = cnt_attach_create($qUser, "$tmp/shot.png", $err);
check('создание с превышенным лимитом отклонено', !$r && $err !== '', $err);

// Привязка к сообщению: только свои файлы
$uA = 999902;
$uB = 999903;
$a1 = db_insert('attachments', ['user_id' => $uA, 'path' => '2000/01/linkaaaa1111.png', 'thumb' => null, 'size' => 1, 'width' => 1, 'height' => 1, 'post_id' => null, 'created_at' => now()]);
$b1 = db_insert('attachments', ['user_id' => $uB, 'path' => '2000/01/linkbbbb2222.png', 'thumb' => null, 'size' => 1, 'width' => 1, 'height' => 1, 'post_id' => null, 'created_at' => now()]);
$cleanupSql[] = $a1;
$cleanupSql[] = $b1;
$body = "Доказательства:\n[img]/uploads/attachments/2000/01/linkaaaa1111.png[/img]\n[img]https://site.ru/uploads/attachments/2000/01/linkbbbb2222.png[/img]\n[img]/uploads/attachments/../../config/x.png[/img]";
check('пути в тексте', cnt_attach_paths_in($body) === ['2000/01/linkaaaa1111.png', '2000/01/linkbbbb2222.png'], cnt_attach_paths_in($body));
$n = cnt_attach_link(777001, $body, $uA);
check('привязан только свой файл', $n === 1 && (int)db_val('SELECT post_id FROM attachments WHERE id = :id', ['id' => $a1]) === 777001
    && db_val('SELECT post_id FROM attachments WHERE id = :id', ['id' => $b1]) === null);

// Полный адрес своего сайта в [img] сохраняется коротким путём
$_SERVER['HTTP_HOST'] = 'wrp.test';
$saveIn = '[img]http://wrp.test/uploads/attachments/2026/10/abcdef0123456789abcd.png[/img] [img]https://evil.com/uploads/attachments/2026/10/abcdef0123456789abcd.png[/img]';
$saveOut = hook_filter('post_body_save', $saveIn, ['id' => 1], null);
check('полный адрес своего сайта -> короткий путь', str_contains($saveOut, '[img]/uploads/attachments/2026/10/abcdef0123456789abcd.png[/img]') && str_contains($saveOut, 'https://evil.com/'), $saveOut);
check('bbcode показывает вложение', str_contains(bbcode('[img]/uploads/attachments/2026/10/abcdef0123456789abcd.png[/img]'), 'class="bb-img" src="/uploads/attachments/2026/10/abcdef0123456789abcd.png"'));

// Очистка: старые непривязанные удаляются вместе с файлом, свежие и привязанные остаются
$old = cnt_attach_save_image("$tmp/shot.png", $err);
$oldId = db_insert('attachments', ['user_id' => $uA, 'path' => $old['path'], 'thumb' => $old['thumb'], 'size' => $old['size'], 'width' => $old['width'], 'height' => $old['height'], 'post_id' => null, 'created_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]);
$fresh = cnt_attach_save_image("$tmp/shot.png", $err);
$freshId = db_insert('attachments', ['user_id' => $uA, 'path' => $fresh['path'], 'thumb' => $fresh['thumb'], 'size' => $fresh['size'], 'width' => $fresh['width'], 'height' => $fresh['height'], 'post_id' => null, 'created_at' => now()]);
$saved[] = $fresh;
$cleanupSql[] = $freshId;
db_exec('UPDATE attachments SET created_at = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s', time() - 3 * 86400), 'id' => $a1]);
cnt_attach_cleanup();
check('очистка: старый непривязанный удалён', !db_val('SELECT id FROM attachments WHERE id = :id', ['id' => $oldId]) && !is_file(cnt_attach_dir() . '/' . $old['path']) && !is_file(cnt_attach_dir() . '/' . $old['thumb']));
check('очистка: свежий остался', (bool)db_val('SELECT id FROM attachments WHERE id = :id', ['id' => $freshId]) && is_file(cnt_attach_dir() . '/' . $fresh['path']));
check('очистка: привязанный остался', (bool)db_val('SELECT id FROM attachments WHERE id = :id', ['id' => $a1]));

check('папка uploads закрыта для скриптов (.htaccess)', str_contains((string)@file_get_contents(WRP_PUBLIC . '/uploads/.htaccess'), 'php_flag engine off'));

// ---------- Опросы ----------

$d = cnt_poll_input(['sent' => '1', 'question' => '  Что   добавить? ', 'options' => ['Дома', ' ', 'Гаражи', 'дома'], 'close_days' => '3']);
check('опрос: пробелы и пустые варианты', $d['question'] === 'Что добавить?' && count($d['options']) === 3 && $d['public'] === false, $d);
check('опрос: повтор варианта', str_contains(cnt_poll_error($d), 'повторяется'), cnt_poll_error($d));
$d['options'] = ['Дома'];
check('опрос: меньше двух вариантов', cnt_poll_error($d) !== '');
$d['options'] = array_map(function ($i) {
    return 'Вариант ' . $i;
}, range(1, 11));
check('опрос: больше десяти вариантов', cnt_poll_error($d) !== '');
$d['options'] = ['Да', 'Нет'];
$d['question'] = 'Ок';
check('опрос: короткий вопрос', cnt_poll_error($d) !== '');
$d['question'] = 'Нужен ли второй банкомат у мэрии?';
$d['close_days'] = 400;
check('опрос: срок больше года', cnt_poll_error($d) !== '');
$d['close_days'] = 0;
check('опрос: корректный', cnt_poll_error($d) === '', cnt_poll_error($d));
check('опрос: пустая форма', cnt_poll_input(['sent' => '1', 'question' => '', 'options' => ['', '']])['empty'] === true);

check('опрос разрешён в обычном разделе', cnt_poll_allowed(node_get(81)));
check('опрос запрещён в разделе с анкетой', !cnt_poll_allowed(node_get(52)));
test_setting('poll_nodes', '52,81');
check('список разделов: анкета разрешена явно', cnt_poll_allowed(node_get(52)) && !cnt_poll_allowed(node_get(82)));
test_setting('poll_nodes', 'auto');

$fakeThread = ['id' => 999800, 'user_id' => 3, 'is_locked' => 0, 'is_deleted' => 0];
$node81 = node_get(81);
$pollId = cnt_poll_create($fakeThread['id'], 3, ['question' => 'Тест опроса', 'options' => ['Первый', 'Второй', 'Третий'], 'multiple' => false, 'public' => true, 'close_days' => 0]);
$pollMulti = cnt_poll_create(999801, 3, ['question' => 'Тест мульти', 'options' => ['A', 'B', 'C'], 'multiple' => true, 'public' => false, 'close_days' => 2]);
$poll = cnt_poll_get($pollId);
$pm = cnt_poll_get($pollMulti);
$opts = array_map('intval', array_column(cnt_poll_options($pollId), 'id'));
$optsM = array_map('intval', array_column(cnt_poll_options($pollMulti), 'id'));
check('опрос сохранён', count($opts) === 3 && $poll['question'] === 'Тест опроса' && $pm['close_at'] !== null);

$GLOBALS['wrp_user'] = null;
check('гость не голосует', cnt_poll_vote_denied($poll, $fakeThread, $node81) === 'guest');
$GLOBALS['wrp_user'] = user_by_id(2);
check('пользователь может голосовать', cnt_poll_vote_denied($poll, $fakeThread, $node81) === '');
check('один вариант: два нельзя', cnt_poll_vote($poll, [$opts[0], $opts[1]], 2) !== '');
check('чужой вариант нельзя', cnt_poll_vote($poll, [$optsM[0]], 2) !== '');
check('пустой голос нельзя', cnt_poll_vote($poll, [], 2) !== '');
check('голос учтён', cnt_poll_vote($poll, [$opts[0]], 2) === '');
check('голос второго пользователя', cnt_poll_vote($poll, [$opts[0]], 3) === '');
$poll = cnt_poll_get($pollId);
$votes = array_column(cnt_poll_options($pollId), 'votes', 'id');
check('проголосовало 2, первый вариант 2', (int)$poll['voters'] === 2 && (int)$votes[$opts[0]] === 2, $votes);
check('смена голоса', cnt_poll_vote($poll, [$opts[2]], 2) === '');
$votes = array_column(cnt_poll_options($pollId), 'votes', 'id');
check('после смены: 1 и 1, всё ещё 2 человека', (int)$votes[$opts[0]] === 1 && (int)$votes[$opts[2]] === 1 && (int)cnt_poll_get($pollId)['voters'] === 2, $votes);
check('мои голоса', cnt_poll_my_votes($pollId, 2) === [$opts[2]]);
check('несколько вариантов', cnt_poll_vote($pm, [$optsM[0], $optsM[2]], 2) === '' && (int)cnt_poll_get($pollMulti)['voters'] === 1
    && count(cnt_poll_my_votes($pollMulti, 2)) === 2);

$closed = $poll;
$closed['is_closed'] = 1;
check('закрытый опрос', cnt_poll_vote_denied($closed, $fakeThread, $node81) === 'Опрос закрыт.');
$expired = $poll;
$expired['close_at'] = date('Y-m-d H:i:s', time() - 60);
check('истёк срок', !cnt_poll_is_open($expired, $fakeThread));
check('закрытая тема', !cnt_poll_is_open($poll, ['is_locked' => 1, 'is_deleted' => 0]));
$GLOBALS['wrp_user'] = user_by_id(2);
check('чужой опрос не управляется', !cnt_poll_can_manage($poll, $fakeThread));
$GLOBALS['wrp_user'] = user_by_id(3);
check('автор темы управляет', cnt_poll_can_manage($poll, $fakeThread));
$GLOBALS['wrp_user'] = user_by_id(5);
check('модератор управляет', cnt_poll_can_manage($poll, $fakeThread));
$GLOBALS['wrp_user'] = user_by_id(4);
$node90 = node_get(90);
$GLOBALS['wrp_user'] = user_by_id(2);
check('нет доступа к разделу - нет голоса', cnt_poll_vote_denied($poll, $fakeThread, $node90) !== '');

// Результаты скрыты до голосования (public_results = 0)
$GLOBALS['wrp_user'] = user_by_id(3);
$html = cnt_poll_html($pm, $fakeThread, $node81);
check('скрытые результаты до голоса', !str_contains($html, 'cnt-poll-results') && str_contains($html, 'после голосования'));
$GLOBALS['wrp_user'] = user_by_id(2);
$html = cnt_poll_html(cnt_poll_get($pollMulti), $fakeThread, $node81);
check('результаты после голоса', str_contains($html, 'cnt-poll-results') && str_contains($html, 'Проголосовало: <b>1</b>'));
check('вопрос экранирован', !str_contains(cnt_poll_html(array_merge($poll, ['question' => '<script>x</script>']), $fakeThread, $node81), '<script>x'));
$GLOBALS['wrp_user'] = null;

// ---------- Новости для лаунчера и RSS ----------

$tNews = db_insert('threads', ['node_id' => 11, 'user_id' => 1, 'title' => 'CNT-TEST новость с картинкой', 'prefix_id' => null, 'last_post_at' => now(), 'created_at' => date('Y-m-d H:i:s', time() + 5)]);
$pNews = db_insert('posts', ['thread_id' => $tNews, 'user_id' => 1, 'body' => "[b]Важно[/b] [img]/uploads/attachments/2026/10/abcdef0123456789abcd.png[/img] <script>x</script>", 'created_at' => now()]);
$tDel = db_insert('threads', ['node_id' => 11, 'user_id' => 1, 'title' => 'CNT-TEST удалённая', 'is_deleted' => 1, 'last_post_at' => now(), 'created_at' => date('Y-m-d H:i:s', time() + 6)]);
db_insert('posts', ['thread_id' => $tDel, 'user_id' => 1, 'body' => 'секрет', 'created_at' => now()]);
$tPriv = db_insert('threads', ['node_id' => 90, 'user_id' => 1, 'title' => 'CNT-TEST закрытый раздел', 'last_post_at' => now(), 'created_at' => date('Y-m-d H:i:s', time() + 7)]);
db_insert('posts', ['thread_id' => $tPriv, 'user_id' => 1, 'body' => 'служебное', 'created_at' => now()]);

$_SERVER['HTTP_HOST'] = 'wrp.test';
$GLOBALS['wrp_user'] = user_by_id(1); // даже администратору закрытое не отдаётся
test_setting('news_node_id', '11');
test_setting('news_api_node2', '90');
$items = cnt_news_items(20);
$ids = array_column($items, 'id');
check('api: новость есть', in_array($tNews, $ids, true), $ids);
check('api: удалённой нет', !in_array($tDel, $ids, true));
check('api: закрытого раздела нет', !in_array($tPriv, $ids, true));
$it = $items[array_search($tNews, $ids, true)];
$keys = ['id', 'title', 'prefix', 'url', 'date', 'author', 'excerpt', 'html', 'image'];
check('api: поля', !array_diff($keys, array_keys($it)), array_keys($it));
check('api: дата ISO 8601', (bool)preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$~', $it['date']), $it['date']);
check('api: полный адрес темы', $it['url'] === 'http://wrp.test/forum/thread.php?id=' . $tNews, $it['url']);
check('api: картинка полным адресом', $it['image'] === 'http://wrp.test/uploads/attachments/2026/10/abcdef0123456789abcd.png', $it['image']);
check('api: html безопасен и с полными адресами', !str_contains($it['html'], '<script') && str_contains($it['html'], 'src="http://wrp.test/uploads/'), $it['html']);
check('api: excerpt без BB-кодов', !str_contains($it['excerpt'], '[b]') && str_contains($it['excerpt'], 'Важно'), $it['excerpt']);
check('api: лимит', count(cnt_news_items(1)) === 1);
test_setting('news_node_id', '90');
test_setting('news_api_node2', '0');
check('api: раздел новостей закрыт - пусто', cnt_news_items(20) === []);
check('rss: закрытого раздела нет в общем списке', !in_array(90, cnt_feed_all_public_ids(), true));
check('rss: подразделы категории без закрытых', !in_array(90, cnt_feed_node_ids([3]), true) && in_array(81, cnt_feed_node_ids([3]), true));
$xml = cnt_rss_xml(null, 50);
check('rss: корректный XML', @simplexml_load_string($xml) !== false);
check('rss: без закрытого и удалённого', !str_contains($xml, 'CNT-TEST закрытый') && !str_contains($xml, 'CNT-TEST удалённая') && str_contains($xml, 'CNT-TEST новость'));
check('первая картинка: javascript не проходит', cnt_feed_first_image('[img]javascript:alert(1)[/img]') === null);
$GLOBALS['wrp_user'] = null;

// ---------- По HTTP (если задан CNT_BASE) ----------

$base = getenv('CNT_BASE');
if ($base) {
    test_setting('news_node_id', '11');
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body = @file_get_contents($base . '/api/news.php?limit=3', false, $ctx);
    $hdr = implode("\n", $http_response_header ?? []);
    $j = json_decode((string)$body, true);
    check('http api: 200 и JSON', is_array($j) && !empty($j['ok']) && count($j['items']) <= 3, $body);
    check('http api: Cache-Control и CORS', stripos($hdr, 'Cache-Control: public, max-age=60') !== false && stripos($hdr, 'Access-Control-Allow-Origin: *') !== false, $hdr);
    check('http api: без cookie', stripos($hdr, 'Set-Cookie') === false, $hdr);
    $post = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => 'x=1', 'header' => 'Content-Type: application/x-www-form-urlencoded']]);
    @file_get_contents($base . '/api/news.php', false, $post);
    check('http api: POST - 405', str_contains($http_response_header[0] ?? '', '405'), $http_response_header[0] ?? '');
    @file_get_contents($base . '/forum/rss.php?node=90', false, $ctx);
    check('http rss: закрытый раздел - 404', str_contains($http_response_header[0] ?? '', '404'), $http_response_header[0] ?? '');
    @file_get_contents($base . '/forum/upload.php', false, $post);
    check('http upload: без токена отказ', str_contains($http_response_header[0] ?? '', '400'), $http_response_header[0] ?? '');
    @file_get_contents($base . '/forum/user-suggest.php?q=ad', false, $ctx);
    check('http user-suggest: гостю отказ', str_contains($http_response_header[0] ?? '', '401'), $http_response_header[0] ?? '');
}

// ---------- Уборка ----------

foreach ([$tNews, $tDel, $tPriv] as $tid) {
    db_exec('DELETE FROM posts WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM threads WHERE id = :t', ['t' => $tid]);
}
foreach ([$pollId, $pollMulti] as $pid) {
    db_exec('DELETE FROM poll_votes WHERE poll_id = :p', ['p' => $pid]);
    db_exec('DELETE FROM poll_options WHERE poll_id = :p', ['p' => $pid]);
    db_exec('DELETE FROM polls WHERE id = :p', ['p' => $pid]);
}
if ($cleanupSql) {
    $in = db_in($cleanupSql, 'c');
    db_exec('DELETE FROM attachments WHERE id IN (' . $in['sql'] . ')', $in['params']);
}
foreach ($saved as $r) {
    cnt_attach_unlink($r);
}
foreach ($settingsBackup as $k => $row) {
    if ($row === null) {
        db_exec('DELETE FROM settings WHERE k = :k', ['k' => $k]);
    } else {
        setting_set($k, $row['v']);
    }
}
foreach (glob($tmp . '/*') as $file) {
    @unlink($file);
}
@rmdir($tmp);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
