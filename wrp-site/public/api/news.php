<?php
// Новости для лаунчера World RP: JSON с последними темами раздела новостей (описание - docs/API.md).
// GET /api/news.php?limit=10. Только разделы, открытые гостям. Работает без сессии и cookie.
define('WRP_INSTALLER', true);
require __DIR__ . '/../../app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
$out = function ($data, $code = 200, $cache = true) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if ($cache) {
        header('Cache-Control: public, max-age=60');
        header('Access-Control-Allow-Origin: *');
    } else {
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Max-Age: 86400');
    header('Allow: GET, HEAD, OPTIONS');
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, OPTIONS');
    $out(['ok' => false, 'error' => 'Only GET'], 405, false);
}
if (empty($GLOBALS['wrp_config']['installed'])) {
    $out(['ok' => false, 'error' => 'Site is not installed'], 503, false);
}

$default = (int)setting('news_api_limit', 10);
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : ($default ?: 10);
$limit = max(1, min(20, $limit));

try {
    $items = cnt_news_items($limit);
} catch (Exception $e) {
    error_log('[api/news] ' . $e);
    $out(['ok' => false, 'error' => 'Server error'], 500, false);
}

$out([
    'ok' => true,
    'site' => (string)setting('site_name'),
    'forum' => cnt_abs(url('/forum/')),
    'generated' => date('c'),
    'count' => count($items),
    'items' => $items,
]);
