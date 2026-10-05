<?php
// Отметить раздел (с подразделами) или весь форум прочитанным
require __DIR__ . '/../../app/bootstrap.php';

require_post();
if (!is_logged()) {
    flash('info', 'Войдите, чтобы отмечать темы прочитанными.');
    redirect(url('/forum/login.php'));
}

$nodeId = input_int('node');
$node = $nodeId ? node_get($nodeId) : null;
if ($nodeId && (!$node || !node_can_view($node))) {
    abort(404, 'Такого раздела нет.');
}
if ($node) {
    node_mark_read($node['id']);
    flash('success', 'Раздел «' . $node['title'] . '» отмечен прочитанным.');
} else {
    node_mark_read();
    flash('success', 'Весь форум отмечен прочитанным.');
}
redirect(fp_referer($node ? node_url($node) : url('/forum/')));
