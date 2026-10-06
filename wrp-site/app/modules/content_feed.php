<?php
// Модуль «Контент»: RSS-ленты разделов (/forum/rss.php) и новости для лаунчера (/api/news.php).
// Отдаются ТОЛЬКО разделы, открытые гостям (уровень просмотра 0 у раздела и всех родителей),
// даже если запрос пришёл от администратора: в ленты и лаунчер не попадает ничего закрытого.

// Открытые гостям форумы в поддереве разделов
function cnt_feed_node_ids(array $rootIds)
{
    $ids = [];
    foreach ($rootIds as $rid) {
        $root = node_get($rid);
        if (!$root || !site_node_public($root)) {
            continue;
        }
        foreach (node_subtree_ids($rid) as $id) {
            $n = node_get($id);
            if ($n && $n['type'] === 'forum' && site_node_public($n)) {
                $ids[(int)$id] = true;
            }
        }
    }
    return array_keys($ids);
}

// Все открытые гостям форумы
function cnt_feed_all_public_ids()
{
    $ids = [];
    foreach (nodes_all() as $id => $n) {
        if ($n['type'] === 'forum' && site_node_public($n)) {
            $ids[] = (int)$id;
        }
    }
    return $ids;
}

// Последние темы с первым сообщением (только неудалённые)
function cnt_feed_threads(array $nodeIds, $limit)
{
    if (!$nodeIds) {
        return [];
    }
    $in = db_in(array_values($nodeIds), 'n');
    return db_all('SELECT t.id, t.node_id, t.title, t.prefix_id, t.created_at, t.last_post_at, t.reply_count, t.views,
            u.username, p.body, p.is_deleted AS post_deleted
        FROM threads t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN posts p ON p.id = COALESCE(t.first_post_id, (SELECT MIN(p2.id) FROM posts p2 WHERE p2.thread_id = t.id))
        WHERE t.is_deleted = 0 AND t.node_id IN (' . $in['sql'] . ')
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT ' . max(1, min(50, (int)$limit)), $in['params']);
}

// Первая картинка из текста: полный адрес или null
function cnt_feed_first_image($body)
{
    if (preg_match('~\[img(?:=[0-9x]{1,9})?\]\s*((?:https?://|/uploads/attachments/)[^\s\[\]"\'<>]+?)\s*\[/img\]~i', (string)$body, $m)) {
        $src = $m[1];
        if ($src[0] === '/') {
            if (!preg_match('~^/uploads/attachments/\d{4}/\d{2}/[a-z0-9_\-]{8,64}\.(?:jpe?g|png|gif|webp)$~i', $src)) {
                return null;
            }
            return cnt_abs(url($src));
        }
        return $src;
    }
    return null;
}

// Внутренние ссылки и картинки в HTML делает полными (для лаунчера: он открывает HTML не на нашем сайте)
function cnt_feed_abs_html($html)
{
    $origin = site_origin();
    $out = preg_replace('~\b(src|href)="/(?!/)~', '$1="' . $origin . '/', (string)$html);
    return $out === null ? (string)$html : $out;
}

// Дата для RSS (RFC 822) и для JSON (ISO 8601)
function cnt_feed_rfc($datetime)
{
    return date(DATE_RSS, strtotime((string)$datetime));
}

function cnt_feed_iso($datetime)
{
    return date('c', strtotime((string)$datetime));
}

// Название префикса или null
function cnt_feed_prefix($prefixId)
{
    $all = prefixes_all();
    return $prefixId && isset($all[(int)$prefixId]) ? $all[(int)$prefixId] : null;
}

// Одна новость для лаунчера
function cnt_feed_item(array $t)
{
    $body = !empty($t['post_deleted']) ? '' : (string)$t['body'];
    $prefix = cnt_feed_prefix($t['prefix_id']);
    return [
        'id' => (int)$t['id'],
        'title' => (string)$t['title'],
        'prefix' => $prefix ? (string)$prefix['title'] : null,
        'prefix_color' => $prefix ? (string)$prefix['color'] : null,
        'url' => cnt_abs(thread_url($t['id'])),
        'date' => cnt_feed_iso($t['created_at']),
        'author' => $t['username'] !== null ? (string)$t['username'] : null,
        'excerpt' => site_plain($body, 300),
        'html' => cnt_feed_abs_html(bbcode($body)),
        'image' => cnt_feed_first_image($body),
        'replies' => (int)$t['reply_count'],
        'views' => (int)$t['views'],
    ];
}

// Новости для лаунчера: раздел news_node_id и (если задан) news_api_node2, только открытые гостям
function cnt_news_items($limit)
{
    $roots = [];
    foreach (['news_node_id', 'news_api_node2'] as $k) {
        $id = setting_int($k);
        if ($id > 0) {
            $roots[] = $id;
        }
    }
    $items = [];
    foreach (cnt_feed_threads(cnt_feed_node_ids($roots), $limit) as $t) {
        $items[] = cnt_feed_item($t);
    }
    return $items;
}

// Лента RSS 2.0. $node - раздел или null (весь форум).
function cnt_rss_xml($node, $limit = 20)
{
    $ids = $node ? cnt_feed_node_ids([(int)$node['id']]) : cnt_feed_all_public_ids();
    $rows = cnt_feed_threads($ids, $limit);
    $x = function ($s) {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $site = (string)setting('site_name');
    $title = $node ? $node['title'] . ' - ' . $site : 'Форум - ' . $site;
    $link = cnt_abs($node ? node_url($node) : url('/forum/'));
    $self = cnt_abs($node ? url('/forum/rss.php', ['node' => (int)$node['id']]) : url('/forum/rss.php'));
    $desc = $node && trim((string)$node['description']) !== '' ? $node['description'] : setting('site_description');
    $last = $rows ? $rows[0]['created_at'] : now();

    $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $o .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n";
    $o .= '<title>' . $x($title) . "</title>\n<link>" . $x($link) . "</link>\n<description>" . $x($desc) . "</description>\n";
    $o .= "<language>ru</language>\n<lastBuildDate>" . $x(cnt_feed_rfc($last)) . "</lastBuildDate>\n";
    $o .= '<atom:link href="' . $x($self) . '" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rows as $t) {
        $prefix = cnt_feed_prefix($t['prefix_id']);
        $url = cnt_abs(thread_url($t['id']));
        $body = !empty($t['post_deleted']) ? '' : (string)$t['body'];
        $n = node_get($t['node_id']);
        $o .= "<item>\n<title>" . $x(($prefix ? '[' . $prefix['title'] . '] ' : '') . $t['title']) . "</title>\n";
        $o .= '<link>' . $x($url) . "</link>\n<guid isPermaLink=\"true\">" . $x($url) . "</guid>\n";
        $o .= '<pubDate>' . $x(cnt_feed_rfc($t['created_at'])) . "</pubDate>\n";
        if ($t['username'] !== null) {
            $o .= '<dc:creator>' . $x($t['username']) . "</dc:creator>\n";
        }
        if ($n) {
            $o .= '<category>' . $x($n['title']) . "</category>\n";
        }
        $o .= '<description>' . $x(site_plain($body, 600)) . "</description>\n</item>\n";
    }
    return $o . "</channel>\n</rss>\n";
}
