<?php
// RSS 2.0: последние темы раздела (вместе с подразделами) или всего форума.
// Только разделы, открытые гостям. Страница работает без сессии: читалки RSS не создают «гостей онлайн».
define('WRP_INSTALLER', true);
require __DIR__ . '/../../app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
$fail = function ($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
};
if (empty($GLOBALS['wrp_config']['installed'])) {
    $fail(503, 'Сайт ещё не установлен.');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    $fail(405, 'Только GET.');
}

$node = null;
if (isset($_GET['node'])) {
    $node = node_get(query_int('node'));
    if (!$node || !in_array($node['type'], ['forum', 'category'], true) || !site_node_public($node)) {
        $fail(404, 'Раздел не найден или закрыт для гостей.');
    }
}

$xml = cnt_rss_xml($node, 20);
header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $xml;
