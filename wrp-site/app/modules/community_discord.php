<?php
// Сообщество: Discord-вебхуки. Новые темы и смена статуса (префикса) в выбранных разделах
// уходят в каналы Discord. Сообщения кладутся в очередь com_webhook_queue и отправляются
// фоновой задачей раз в минуту (после ответа страницы или из tools/cron.php): таймаут 5 секунд,
// до 3 попыток. Темы из разделов, закрытых для гостей, уходят только в «служебные» вебхуки.

const COM_DISCORD_COLOR = 0xff5a36;
const COM_DISCORD_TRIES = 3;

function com_discord_events()
{
    return [
        'thread' => ['Новая тема', 'Когда в разделе создают тему', 'темы'],
        'prefix' => ['Смена статуса', 'Когда модератор меняет префикс темы (например, «Одобрено» у жалобы)', 'статусы'],
    ];
}

// Адрес вебхука Discord. При debug = true в config.php для проверки разрешён http://127.0.0.1:порт/...
function com_discord_url_ok($u, $allowLocal = null)
{
    $u = trim((string)$u);
    if (strlen($u) > 255) {
        return false;
    }
    if (preg_match('~^https://(?:(?:ptb|canary)\.)?(?:discord\.com|discordapp\.com)/api(?:/v\d{1,2})?/webhooks/\d{5,25}/[A-Za-z0-9_\-]{20,120}/?$~', $u)) {
        return true;
    }
    if ($allowLocal === null) {
        $allowLocal = (bool)cfg('debug');
    }
    return $allowLocal && (bool)preg_match('~^http://127\.0\.0\.1(?::\d{2,5})?/[A-Za-z0-9_\-/.]*$~', $u);
}

// Для показа в админке: discord.com/api/webhooks/1234…/••••abcd
function com_discord_mask($u)
{
    $u = (string)$u;
    if (preg_match('~^https?://([^/]+)/(.*?webhooks/)(\d+)/(.+?)/?$~', $u, $m)) {
        return $m[1] . '/' . $m[2] . substr($m[3], 0, 4) . '…/••••' . substr($m[4], -4);
    }
    if (preg_match('~^https?://([^/]+)~', $u, $m)) {
        return $m[1] . '/…';
    }
    return '…';
}

// Адрес сайта для ссылок в Discord: из настроек, иначе текущий
function com_discord_origin()
{
    $s = rtrim(trim((string)setting('com_discord_site_url')), '/');
    if ($s !== '' && preg_match('~^https?://[A-Za-z0-9.\-]+(?::\d+)?(?:/[A-Za-z0-9._\-/]*)?$~', $s)) {
        // base_path уже входит в url(), поэтому из настройки берём только схему и хост
        $p = parse_url($s);
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    return site_origin();
}

function com_webhooks_all($reset = false)
{
    static $all = null;
    if ($reset) {
        $all = null;
        return [];
    }
    if ($all === null) {
        $all = com_ready() ? db_all('SELECT * FROM com_webhooks ORDER BY id') : [];
    }
    return $all;
}

// Подходит ли событие вебхуку: раздел (с подразделами), тип события, префикс
function com_webhook_matches(array $hook, array $node, $event, $prefixId = null)
{
    if (empty($hook['is_active'])) {
        return false;
    }
    if (!in_array($event, explode(',', (string)$hook['events']), true)) {
        return false;
    }
    $want = com_ids($hook['node_ids']);
    if (!$want) {
        return false;
    }
    $inNode = false;
    foreach (node_path($node['id']) as $n) {
        if (in_array((int)$n['id'], $want, true)) {
            $inNode = true;
        }
    }
    if (!$inNode) {
        return false;
    }
    if ($event === 'prefix') {
        $pf = com_ids($hook['prefix_ids']);
        if ($pf && !in_array((int)$prefixId, $pf, true)) {
            return false;
        }
    }
    return true;
}

// Сообщение для Discord (embed)
function com_discord_payload($event, array $thread, array $node, array $extra = [])
{
    $origin = com_discord_origin();
    $site = (string)setting('site_name');
    $prefixes = prefixes_all();
    $prefix = !empty($thread['prefix_id']) && isset($prefixes[(int)$thread['prefix_id']]) ? $prefixes[(int)$thread['prefix_id']]['title'] : '';
    $author = $extra['author'] ?? null;
    $embed = [
        'title' => mb_substr(($prefix !== '' ? '[' . $prefix . '] ' : '') . $thread['title'], 0, 250),
        'url' => $origin . thread_url($thread['id']),
        'color' => COM_DISCORD_COLOR,
        'fields' => [['name' => 'Раздел', 'value' => mb_substr($node['title'], 0, 200), 'inline' => true]],
        'footer' => ['text' => mb_substr($site . ' · форум', 0, 100)],
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($author) {
        $embed['author'] = ['name' => mb_substr($author['username'], 0, 80), 'url' => $origin . url('/forum/member.php', ['id' => (int)$author['id']])];
    }
    if ($event === 'thread') {
        $content = 'Новая тема в разделе «' . $node['title'] . '»';
        $excerpt = bbcode_plain((string)($extra['body'] ?? ''), 300);
        if ($excerpt !== '') {
            $embed['description'] = $excerpt;
        }
    } else {
        $content = 'Статус темы изменён' . ($prefix !== '' ? ': ' . $prefix : '');
        $embed['fields'][] = ['name' => 'Статус', 'value' => $prefix !== '' ? $prefix : 'без статуса', 'inline' => true];
        if (!empty($extra['moderator'])) {
            $embed['fields'][] = ['name' => 'Изменил', 'value' => mb_substr($extra['moderator'], 0, 80), 'inline' => true];
        }
    }
    return [
        'username' => mb_substr($site !== '' ? $site : 'World Role Play', 0, 80),
        'content' => mb_substr($content, 0, 300),
        'allowed_mentions' => ['parse' => []],
        'embeds' => [$embed],
    ];
}

// Поставить событие в очередь всем подходящим вебхукам. Возвращает число записей.
function com_discord_enqueue($event, array $thread, array $node, array $extra = [])
{
    if (!com_ready() || !empty($thread['is_deleted'])) {
        return 0;
    }
    $public = site_node_public($node);
    $n = 0;
    foreach (com_webhooks_all() as $hook) {
        if (!com_webhook_matches($hook, $node, $event, $thread['prefix_id'] ?? null)) {
            continue;
        }
        if (!$public && empty($hook['is_internal'])) {
            continue;
        }
        $payload = com_discord_payload($event, $thread, $node, empty($hook['is_internal']) ? array_diff_key($extra, ['moderator' => 1]) : $extra);
        db_insert('com_webhook_queue', [
            'webhook_id' => (int)$hook['id'],
            'event' => $event,
            'thread_id' => (int)$thread['id'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'attempts' => 0,
            'next_try_at' => now(),
            'created_at' => now(),
        ]);
        $n++;
    }
    return $n;
}

hook_add('thread_created', function ($threadId, $node, $postId) {
    try {
        if (!com_ready() || !com_webhooks_all()) {
            return;
        }
        $t = thread_get($threadId);
        if (!$t) {
            return;
        }
        $body = (string)db_val('SELECT body FROM posts WHERE id = :id', ['id' => (int)$postId]);
        com_discord_enqueue('thread', $t, $node, ['author' => user(), 'body' => $body]);
    } catch (Exception $e) {
        error_log('[community discord] ' . $e->getMessage());
    }
});

hook_add('thread_moderated', function ($thread, $node, $do) {
    if ($do !== 'prefix') {
        return;
    }
    try {
        if (!com_ready() || !com_webhooks_all()) {
            return;
        }
        $t = thread_get($thread['id']);
        if (!$t || (int)$t['prefix_id'] === (int)$thread['prefix_id']) {
            return;
        }
        $author = user_by_id($t['user_id']);
        $me = user();
        com_discord_enqueue('prefix', $t, $node, ['author' => $author, 'moderator' => $me ? $me['username'] : '']);
    } catch (Exception $e) {
        error_log('[community discord] ' . $e->getMessage());
    }
});

// Отправка одного сообщения. true при успехе; ошибку и паузу (429) пишет в $error и $retryAfter.
function com_discord_send($url, $json, &$error, &$retryAfter)
{
    $error = '';
    $retryAfter = 0;
    if (!com_discord_url_ok($url)) {
        $error = 'Адрес вебхука не прошёл проверку';
        return false;
    }
    $code = 0;
    $body = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: WorldRP-Forum (community module)'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | (cfg('debug') ? CURLPROTO_HTTP : 0);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code === 0) {
            $error = 'Нет связи: ' . curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: WorldRP-Forum (community module)\r\n",
            'content' => $json,
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        $body = (string)$res;
        $hdrs = isset($http_response_header) ? $http_response_header : [];
        if (isset($hdrs[0]) && preg_match('~\s(\d{3})\s~', $hdrs[0] . ' ', $m)) {
            $code = (int)$m[1];
        }
        if ($code === 0) {
            $error = 'Нет связи с Discord';
        }
    }
    if ($code >= 200 && $code < 300) {
        return true;
    }
    if ($code === 429) {
        $j = json_decode($body, true);
        $retryAfter = is_array($j) && isset($j['retry_after']) ? (int)ceil((float)$j['retry_after']) : 30;
        $error = 'Discord просит подождать (429)';
    } elseif ($code) {
        $error = 'Ответ Discord: ' . $code . ($code === 404 ? ' (вебхук удалён в Discord?)' : '');
    }
    return false;
}

// Обработать очередь. $limit - сколько сообщений, $budget - секунд на всё.
function com_discord_process($limit = 10, $budget = 20)
{
    if (!com_ready()) {
        return ['sent' => 0, 'failed' => 0];
    }
    $start = microtime(true);
    $hooks = [];
    com_webhooks_all(true);
    foreach (com_webhooks_all() as $h) {
        $hooks[(int)$h['id']] = $h;
    }
    $stat = ['sent' => 0, 'failed' => 0];
    $rows = db_all('SELECT * FROM com_webhook_queue WHERE status = :s AND next_try_at <= :t ORDER BY id LIMIT ' . max(1, (int)$limit), ['s' => 'pending', 't' => now()]);
    foreach ($rows as $r) {
        if (microtime(true) - $start > $budget) {
            break;
        }
        $hook = $hooks[(int)$r['webhook_id']] ?? null;
        if (!$hook || empty($hook['is_active'])) {
            db_update('com_webhook_queue', ['status' => 'failed', 'last_error' => 'Вебхук выключен или удалён'], 'id = :id', ['id' => (int)$r['id']]);
            $stat['failed']++;
            continue;
        }
        $url = com_decrypt($hook['url_enc'], 'webhook');
        $err = '';
        $retry = 0;
        $ok = $url !== null && com_discord_send($url, $r['payload'], $err, $retry);
        if ($url === null) {
            $err = 'Не удалось расшифровать адрес (сменился secret в config.php?)';
        }
        $tries = (int)$r['attempts'] + 1;
        if ($ok) {
            db_update('com_webhook_queue', ['status' => 'sent', 'attempts' => $tries, 'sent_at' => now(), 'last_error' => null], 'id = :id', ['id' => (int)$r['id']]);
            db_update('com_webhooks', ['last_status' => 'ok', 'last_sent_at' => now()], 'id = :id', ['id' => (int)$hook['id']]);
            $stat['sent']++;
            continue;
        }
        $final = $tries >= COM_DISCORD_TRIES;
        $delays = [1 => 60, 2 => 300];
        $wait = max($retry, $delays[$tries] ?? 900);
        db_update('com_webhook_queue', [
            'status' => $final ? 'failed' : 'pending',
            'attempts' => $tries,
            'last_error' => mb_substr($err, 0, 250),
            'next_try_at' => date('Y-m-d H:i:s', time() + $wait),
        ], 'id = :id', ['id' => (int)$r['id']]);
        db_update('com_webhooks', ['last_status' => mb_substr($err, 0, 250)], 'id = :id', ['id' => (int)$hook['id']]);
        if ($final) {
            $stat['failed']++;
        }
    }
    // Старые записи очереди не копим
    if (mt_rand(1, 20) === 1) {
        db_exec('DELETE FROM com_webhook_queue WHERE status <> :s AND created_at < :t', ['s' => 'pending', 't' => date('Y-m-d H:i:s', time() - 86400 * 30)]);
    }
    return $stat;
}

cron_register('com_discord', 60, function () {
    if (com_ready() && (int)db_val('SELECT COUNT(*) FROM com_webhook_queue WHERE status = :s', ['s' => 'pending']) > 0) {
        com_discord_process(10, 20);
    }
});
