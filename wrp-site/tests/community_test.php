<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка модуля «Сообщество»: php tests/community_test.php
// TOTP по векторам RFC 6238, резервные коды, шифрование, награды и баллы, звания,
// «Кто где» без утечки закрытых разделов, адреса вебхуков, плашки, поля профиля.
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$fail = 0;
function check($name, $cond, $out = '')
{
    global $fail;
    if (!$cond) {
        $fail++;
        echo "FAIL: $name\n  $out\n";
    } else {
        echo "ok: $name\n";
    }
}
function as_user($id)
{
    $GLOBALS['wrp_user'] = $id ? user_by_id($id) : null;
}

// ---------- Base32 (RFC 4648) ----------
check('base32 encode foobar', com_b32_encode('foobar') === 'MZXW6YTBOI');
check('base32 decode with padding', com_b32_decode('MZXW6YTBOI======') === 'foobar');
check('base32 decode lowercase and spaces', com_b32_decode('mzxw 6ytb oi') === 'foobar');
check('base32 rejects bad chars', com_b32_decode('MZXW1!') === false);
$rnd = random_bytes(20);
check('base32 roundtrip 20 bytes', com_b32_decode(com_b32_encode($rnd)) === $rnd);
check('new secret is 32 base32 chars', (bool)preg_match('~^[A-Z2-7]{32}$~', com_totp_new_secret()));

// ---------- TOTP: векторы RFC 6238 (8 цифр, шаг 30 с) ----------
$seeds = [
    'sha1' => '12345678901234567890',
    'sha256' => '12345678901234567890123456789012',
    'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
];
$vectors = [
    59 => ['94287082', '46119246', '90693936'],
    1111111109 => ['07081804', '68084774', '25091201'],
    1111111111 => ['14050471', '67062674', '99943326'],
    1234567890 => ['89005924', '91819424', '93441116'],
    2000000000 => ['69279037', '90698825', '38618901'],
    20000000000 => ['65353130', '77737706', '47863826'],
];
foreach ($vectors as $t => $codes) {
    $i = 0;
    foreach ($seeds as $algo => $seed) {
        $got = com_totp_code($seed, (int)floor($t / 30), 8, $algo);
        check("rfc6238 $algo T=$t", $got === $codes[$i], $got . ' != ' . $codes[$i]);
        $i++;
    }
}
// 6 цифр (как в приложениях): последние 6 цифр 8-значного кода
check('6 digits = tail of 8 digits', com_totp_code($seeds['sha1'], 1) === '287082');

// Проверка кода: окно ±1, повтор, мусор
$sec = $seeds['sha1'];
$t0 = 1700000000;
$step = com_totp_step($t0);
check('verify current step', com_totp_verify($sec, com_totp_code($sec, $step), 0, $t0) === $step);
check('verify previous step (window -1)', com_totp_verify($sec, com_totp_code($sec, $step - 1), 0, $t0) === $step - 1);
check('verify next step (window +1)', com_totp_verify($sec, com_totp_code($sec, $step + 1), 0, $t0) === $step + 1);
check('reject step -2', com_totp_verify($sec, com_totp_code($sec, $step - 2), 0, $t0) === false);
check('reject step +2', com_totp_verify($sec, com_totp_code($sec, $step + 2), 0, $t0) === false);
check('reject reused code (last_step = step)', com_totp_verify($sec, com_totp_code($sec, $step), $step, $t0) === false);
check('accept newer code after use', com_totp_verify($sec, com_totp_code($sec, $step + 1), $step, $t0) === $step + 1);
check('code with space accepted', com_totp_verify($sec, substr(com_totp_code($sec, $step), 0, 3) . ' ' . substr(com_totp_code($sec, $step), 3), 0, $t0) === $step);
check('reject letters', com_totp_verify($sec, 'abcdef', 0, $t0) === false);
check('reject 5 digits', com_totp_verify($sec, '12345', 0, $t0) === false);
check('reject empty secret', com_totp_verify('', '123456', 0, $t0) === false);
$uri = com_tfa_uri('JBSWY3DPEHPK3PXP', 'Nick_Name');
check('otpauth uri', strpos($uri, 'otpauth://totp/') === 0 && strpos($uri, 'secret=JBSWY3DPEHPK3PXP') !== false && strpos($uri, 'digits=6') !== false && strpos($uri, 'period=30') !== false);

// ---------- Резервные коды ----------
$codes = com_tfa_backup_generate();
check('10 backup codes', count($codes) === 10 && count(array_unique($codes)) === 10);
$fmt = true;
foreach ($codes as $c) {
    $fmt = $fmt && (bool)preg_match('~^[a-z2-9]{4}-[a-z2-9]{4}$~', $c) && !preg_match('~[01ilo]~', $c);
}
check('backup code format xxxx-xxxx without 0/1/i/l/o', $fmt);
$hashes = com_tfa_backup_hashes(77, $codes);
check('backup hashes are not codes', !in_array($codes[0], $hashes, true) && strlen($hashes[0]) === 64);
$left = com_tfa_backup_use($hashes, 77, $codes[3]);
check('backup code used once', is_array($left) && count($left) === 9);
check('same backup code second time fails', com_tfa_backup_use($left, 77, $codes[3]) === false);
check('backup code case and spaces', is_array(com_tfa_backup_use($hashes, 77, strtoupper(str_replace('-', ' ', $codes[5])))));
check('backup code of other user fails', com_tfa_backup_use($hashes, 78, $codes[0]) === false);
check('garbage backup code fails', com_tfa_backup_use($hashes, 77, "' OR 1=1 --") === false);

// ---------- Шифрование ----------
$enc = com_encrypt('JBSWY3DPEHPK3PXP', 'tfa:5');
check('encrypted with AES-GCM', strpos($enc, 'g1:') === 0 && strpos($enc, 'JBSWY3DPEHPK3PXP') === false);
check('decrypt roundtrip', com_decrypt($enc, 'tfa:5') === 'JBSWY3DPEHPK3PXP');
check('decrypt with other purpose fails', com_decrypt($enc, 'tfa:6') === null);
$raw = base64_decode(substr($enc, 3));
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
check('tampered ciphertext fails', com_decrypt('g1:' . base64_encode($raw), 'tfa:5') === null);
check('plain fallback readable', com_decrypt('p1:abc', 'x') === 'abc');

// ---------- Награды: условия ----------
$now = strtotime('2026-10-06 12:00:00');
$u = ['posts_count' => 120, 'threads_count' => 3, 'likes_received' => 49, 'created_at' => '2026-09-01 12:00:00', 'game_nick' => ''];
$t = function ($criteria, $v) {
    return ['criteria' => $criteria, 'criteria_value' => $v, 'title' => 'x', 'points' => 1];
};
check('posts 100 qualifies', com_trophy_qualifies($t('posts_count', 100), $u, $now));
check('posts 500 not yet', !com_trophy_qualifies($t('posts_count', 500), $u, $now));
check('threads 1 qualifies', com_trophy_qualifies($t('threads_count', 1), $u, $now));
check('likes 50 not yet (49)', !com_trophy_qualifies($t('likes_received', 50), $u, $now));
check('days 30 qualifies (35 days)', com_trophy_qualifies($t('days_registered', 30), $u, $now));
check('days 365 not yet', !com_trophy_qualifies($t('days_registered', 365), $u, $now));
check('days 0 = registration', com_trophy_qualifies($t('days_registered', 0), $u, $now));
check('game not linked', !com_trophy_qualifies($t('game_linked', 1), $u, $now));
check('game linked', com_trophy_qualifies($t('game_linked', 1), ['game_nick' => 'Nick_Name'] + $u, $now));
check('manual never automatic', !com_trophy_qualifies($t('manual', 0), $u, $now) && com_trophy_sql($t('manual', 0)) === null);
check('progress posts', com_trophy_progress($t('posts_count', 500), $u, $now) === [120, 500]);
check('progress days', com_trophy_progress($t('days_registered', 365), $u, $now) === [35, 365]);
check('goal text', com_trophy_goal_text($t('posts_count', 100)) === '100 сообщений на форуме');
$sql = com_trophy_sql($t('posts_count', 100));
check('trophy sql uses parameter', $sql['sql'] === 'u.posts_count >= :cv' && $sql['params'] === ['cv' => 100]);

// ---------- Звания ----------
$ranks = [
    ['id' => 1, 'title' => 'Турист', 'min_points' => 0, 'color' => 'gray'],
    ['id' => 2, 'title' => 'Новичок', 'min_points' => 10, 'color' => 'teal'],
    ['id' => 3, 'title' => 'Житель', 'min_points' => 50, 'color' => 'blue'],
    ['id' => 7, 'title' => 'Легенда Лос-Сантоса', 'min_points' => 2500, 'color' => 'accent'],
];
check('rank 0 = Турист', com_rank_for(0, $ranks)['title'] === 'Турист');
check('rank 9 = Турист', com_rank_for(9, $ranks)['title'] === 'Турист');
check('rank 10 = Новичок', com_rank_for(10, $ranks)['title'] === 'Новичок');
check('rank 2499 = Житель', com_rank_for(2499, $ranks)['title'] === 'Житель');
check('rank 2500 = Легенда', com_rank_for(2500, $ranks)['title'] === 'Легенда Лос-Сантоса');
check('negative points = no rank', com_rank_for(-1, $ranks) === null);
check('next rank after 10', com_rank_next(10, $ranks)['title'] === 'Житель');
check('no next rank at top', com_rank_next(9999, $ranks) === null);
check('rank html escapes', strpos(com_rank_html(['title' => '<b>x</b>', 'color' => 'evil"']), '<b>x</b>') === false && strpos(com_rank_html(['title' => 'x', 'color' => 'evil"']), 'com-c-gray') !== false);

// ---------- Баллы за награды (база) ----------
if (com_ready()) {
    $uid = (int)db_val('SELECT id FROM users ORDER BY id DESC LIMIT 1');
    $manual = db_one("SELECT * FROM com_trophies WHERE criteria = 'manual' AND points > 0 ORDER BY id LIMIT 1");
    if ($uid && $manual) {
        $had = (bool)db_val('SELECT 1 FROM com_user_trophies WHERE user_id = :u AND trophy_id = :t', ['u' => $uid, 't' => (int)$manual['id']]);
        if ($had) {
            com_revoke($uid, $manual['id']);
        }
        $before = (int)db_val('SELECT trophy_points FROM users WHERE id = :u', ['u' => $uid]);
        $pBefore = user_points(user_by_id($uid));
        check('award manual trophy', com_award($uid, $manual['id'], null, 'тест'));
        check('award twice is no-op', !com_award($uid, $manual['id']));
        $after = (int)db_val('SELECT trophy_points FROM users WHERE id = :u', ['u' => $uid]);
        check('trophy_points increased by trophy points', $after === $before + (int)$manual['points'], "$before -> $after");
        check('user_points includes trophy points', user_points(user_by_id($uid)) === $pBefore + (int)$manual['points']);
        // Карточка автора без колонки trophy_points (как fp_author): баллы из пакетной загрузки
        $card = ['id' => $uid, 'posts_count' => 0, 'threads_count' => 0, 'likes_received' => 0];
        check('batch-loaded trophy points for post card', user_points($card) === $after);
        check('revoke', com_revoke($uid, $manual['id']));
        check('trophy_points restored', (int)db_val('SELECT trophy_points FROM users WHERE id = :u', ['u' => $uid]) === $before);
        db_exec("DELETE FROM alerts WHERE user_id = :u AND type = 'trophy' AND extra = :x", ['u' => $uid, 'x' => (string)$manual['id']]);
        if ($had) {
            com_award($uid, $manual['id']);
        }
    }
    $n = com_cron_trophies();
    check('cron awards are idempotent (second run gives 0)', com_cron_trophies() === 0, 'first run: ' . $n);
} else {
    echo "skip: database part (run php tools/migrate.php)\n";
}

// ---------- «Кто где»: закрытые разделы не раскрываются ----------
if (com_ready()) {
    $private = db_one('SELECT t.id, t.title, t.node_id FROM threads t JOIN nodes n ON n.id = t.node_id WHERE n.view_level >= 30 AND t.is_deleted = 0 LIMIT 1');
    $public = db_one('SELECT t.id, t.title FROM threads t JOIN nodes n ON n.id = t.node_id WHERE n.view_level = 0 AND n.parent_id IS NOT NULL AND t.is_deleted = 0 LIMIT 1');
    $locs = ['/forum/thread.php?id=' . ($private ? $private['id'] : 0), '/forum/thread.php?id=' . ($public ? $public['id'] : 0), '/forum/member.php?id=1'];
    $maps = com_where_resolve($locs);
    as_user(null);
    if ($private) {
        $d = com_where_describe('/forum/thread.php?id=' . $private['id'], $maps);
        check('guest: private thread hidden', $d['target'] === null && $d['text'] === 'Где-то на форуме' && strpos(com_where_html($d), $private['title']) === false);
        $d = com_where_describe('/forum/forum.php?id=' . $private['node_id'], $maps);
        check('guest: private node hidden', $d['target'] === null);
        $d = com_where_describe('/forum/new-thread.php?node=' . $private['node_id'], $maps);
        check('guest: new thread in private node hidden', $d['target'] === null);
    }
    if ($public) {
        $d = com_where_describe('/forum/thread.php?id=' . $public['id'], $maps);
        check('guest: public thread shown', $d['target'] === $public['title'] && $d['text'] === 'Читает тему');
    }
    $d = com_where_describe('/admin/settings.php', $maps);
    check('guest: admin area hidden', $d['text'] === 'Где-то на форуме' && $d['target'] === null);
    $d = com_where_describe('/forum/member.php?id=1', $maps);
    check('profile place', $d['text'] === 'Смотрит профиль' && $d['target'] !== null);
    check('forum index', com_where_describe('/forum/', $maps)['text'] === 'Главная форума');
    check('wiki article', com_where_describe('/wiki/article.php?slug=x', $maps)['text'] === 'Читает базу знаний');
    check('conversation details hidden', com_where_describe('/forum/conversations.php?id=5', $maps)['target'] === null);
    check('unknown path', com_where_describe('/forum/../../etc/passwd', $maps)['text'] === 'Где-то на форуме');
    check('null location', com_where_describe(null, $maps)['text'] === 'Где-то на форуме');
    // Удалённая тема в открытом разделе: только модераторам
    $fake = ['threads' => [999999 => ['id' => 999999, 'title' => 'Удалённая тема', 'node_id' => 81, 'prefix_id' => null, 'is_deleted' => 1]], 'users' => []];
    check('guest: deleted thread hidden', com_where_describe('/forum/thread.php?id=999999', $fake)['target'] === null);
    $plain = (int)db_val('SELECT u.id FROM users u JOIN user_groups g ON g.id = u.group_id WHERE g.level = 10 AND g.is_staff = 0 LIMIT 1');
    if ($plain && $private) {
        as_user($plain);
        check('user: private thread hidden', com_where_describe('/forum/thread.php?id=' . $private['id'], $maps)['target'] === null);
        check('user: admin area hidden', com_where_describe('/admin/', $maps)['target'] === null && com_where_describe('/admin/', $maps)['text'] === 'Где-то на форуме');
    }
    $adminId = (int)db_val('SELECT u.id FROM users u JOIN user_groups g ON g.id = u.group_id WHERE g.can_admin = 1 LIMIT 1');
    if ($adminId && $private) {
        as_user($adminId);
        check('admin: private thread shown', com_where_describe('/forum/thread.php?id=' . $private['id'], $maps)['target'] === $private['title']);
        check('admin: deleted thread shown', com_where_describe('/forum/thread.php?id=999999', $fake)['target'] === 'Удалённая тема');
        $d = com_where_describe('/admin/settings.php', $maps);
        check('admin: admin section named', $d['text'] === 'Админ-панель' && $d['target'] === 'Настройки');
    }
    as_user(null);
}

// ---------- Адреса вебхуков ----------
$ok = [
    'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABC-123',
    'https://discordapp.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'https://ptb.discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'https://canary.discord.com/api/v10/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
];
foreach ($ok as $u) {
    check('webhook ok ' . parse_url($u, PHP_URL_HOST), com_discord_url_ok($u, false));
}
$bad = [
    'http://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'https://discord.com.evil.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'https://evil.com/discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'https://evil.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz?wait=true&x=1',
    'https://discord.com/api/webhooks/abc/abcdefghijklmnopqrstuvwxyz',
    'https://user@discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
    'javascript:alert(1)',
    "https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz\nX-Header: 1",
    'http://127.0.0.1:8099/api/webhooks/1/x',
    'http://localhost:8099/api/webhooks/1/x',
];
foreach ($bad as $i => $u) {
    check('webhook rejected #' . $i, !com_discord_url_ok($u, false), $u);
}
check('local test url only in debug', com_discord_url_ok('http://127.0.0.1:8099/api/webhooks/1/x', true) && !com_discord_url_ok('http://127.0.0.2:8099/x', true) && !com_discord_url_ok('http://localhost:8099/x', true));
$mask = com_discord_mask('https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz');
check('webhook mask hides token', strpos($mask, 'abcdefghijklmnop') === false && strpos($mask, 'wxyz') !== false && strpos($mask, '123456789012') === false);

// Вебхук: раздел с подразделами, события, фильтр статусов
$hook = ['is_active' => 1, 'events' => 'thread,prefix', 'node_ids' => '51', 'prefix_ids' => '2,3'];
$n52 = node_get(52);
$n11 = node_get(11);
if ($n52 && $n11) {
    check('hook matches subforum', com_webhook_matches($hook, $n52, 'thread'));
    check('hook skips other node', !com_webhook_matches($hook, $n11, 'thread'));
    check('hook prefix filter allows', com_webhook_matches($hook, $n52, 'prefix', 2));
    check('hook prefix filter blocks', !com_webhook_matches($hook, $n52, 'prefix', 1));
    check('inactive hook skipped', !com_webhook_matches(['is_active' => 0] + $hook, $n52, 'thread'));
    check('event not selected', !com_webhook_matches(['events' => 'prefix'] + $hook, $n52, 'thread'));
}
$payload = com_discord_payload('thread', ['id' => 5, 'title' => 'Тема @everyone', 'prefix_id' => null], ['id' => 11, 'title' => 'Новости'], ['author' => ['id' => 1, 'username' => 'admin'], 'body' => '[b]Текст[/b] @here']);
check('payload: mentions disabled', $payload['allowed_mentions'] === ['parse' => []]);
check('payload: brand color', $payload['embeds'][0]['color'] === 0xff5a36);
check('payload: bbcode stripped', $payload['embeds'][0]['description'] === 'Текст @here');

// ---------- Плашки: кому показывать ----------
$base = ['is_active' => 1, 'starts_at' => null, 'ends_at' => null, 'area' => 'both', 'audience' => 'all', 'min_level' => null, 'max_level' => null];
$guest = ['logged' => false, 'staff' => false, 'level' => 0];
$member = ['logged' => true, 'staff' => false, 'level' => 10];
$staff = ['logged' => true, 'staff' => true, 'level' => 40];
check('notice all: guest', com_notice_visible($base, 'forum', $guest));
check('notice inactive hidden', !com_notice_visible(['is_active' => 0] + $base, 'forum', $guest));
check('notice guests: not for members', com_notice_visible(['audience' => 'guests'] + $base, 'forum', $guest) && !com_notice_visible(['audience' => 'guests'] + $base, 'forum', $member));
check('notice users: not for guests', !com_notice_visible(['audience' => 'users'] + $base, 'forum', $guest) && com_notice_visible(['audience' => 'users'] + $base, 'forum', $member));
check('notice staff only', !com_notice_visible(['audience' => 'staff'] + $base, 'forum', $member) && com_notice_visible(['audience' => 'staff'] + $base, 'forum', $staff));
check('notice area forum not on site', !com_notice_visible(['area' => 'forum'] + $base, 'site', $guest) && com_notice_visible(['area' => 'forum'] + $base, 'forum', $guest));
check('notice min level', !com_notice_visible(['min_level' => 30] + $base, 'forum', $member) && com_notice_visible(['min_level' => 30] + $base, 'forum', $staff));
check('notice max level', com_notice_visible(['max_level' => 10] + $base, 'forum', $member) && !com_notice_visible(['max_level' => 10] + $base, 'forum', $staff));
check('notice not started', !com_notice_visible(['starts_at' => '2026-10-07 00:00:00'] + $base, 'forum', $guest, strtotime('2026-10-06 12:00:00')));
check('notice ended', !com_notice_visible(['ends_at' => '2026-10-05 00:00:00'] + $base, 'forum', $guest, strtotime('2026-10-06 12:00:00')));
check('notice within dates', com_notice_visible(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-10 00:00:00'] + $base, 'forum', $guest, strtotime('2026-10-06 12:00:00')));
$h = com_notice_html(['id' => 1, 'title' => '<script>x</script>', 'message' => '[b]ok[/b]<img src=x onerror=alert(1)>', 'style' => 'bad', 'link_url' => 'javascript:alert(1)', 'link_text' => 'x', 'dismissible' => 0]);
check('notice html escaped', strpos($h, '<script>') === false && strpos($h, '<img') === false && strpos($h, 'javascript:') === false && strpos($h, 'com-notice-info') !== false);
check('link href: internal path', com_link_href('/start.php') === url('/start.php'));
check('link href: rejects protocol-relative', com_link_href('//evil.com/x') === '');
check('link href: rejects javascript', com_link_href('javascript:alert(1)') === '');

// ---------- Поля профиля ----------
$fUrl = ['title' => 'ВКонтакте', 'type' => 'url', 'max_length' => 100, 'options' => null];
$err = '';
check('url field: adds https', com_field_clean($fUrl, 'vk.com/nick', $err) === 'https://vk.com/nick' && $err === '');
com_field_clean($fUrl, 'javascript:alert(1)', $err);
check('url field: rejects javascript', $err !== '');
com_field_clean($fUrl, 'https://vk.com/"><script>', $err);
check('url field: rejects quotes', $err !== '');
check('url field html: rel and escaping', strpos(com_field_html($fUrl, 'https://vk.com/nick'), 'rel="nofollow ugc noopener"') !== false);
check('url field html: bad url not linked', strpos(com_field_html($fUrl, 'javascript:alert(1)'), '<a') === false);
$fSel = ['title' => 'Часовой пояс', 'type' => 'select', 'max_length' => 10, 'options' => "МСК (Москва)\nМСК+2 (Екатеринбург)"];
check('select field: valid option', com_field_clean($fSel, 'МСК+2 (Екатеринбург)', $err) === 'МСК+2 (Екатеринбург)' && $err === '');
com_field_clean($fSel, 'МСК+20', $err);
check('select field: rejects other value', $err !== '');
$fTxt = ['title' => 'Discord', 'type' => 'text', 'max_length' => 5, 'options' => null];
com_field_clean($fTxt, 'abcdefg', $err);
check('text field: max length', $err !== '');
check('text field: control chars removed', com_field_clean(['max_length' => 50] + $fTxt, "a\x00b\u{202E}c", $err) === 'abc');

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
