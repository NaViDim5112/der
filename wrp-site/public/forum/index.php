<?php
require __DIR__ . '/../../app/bootstrap.php';

forum_header([
    'title' => setting('forum_title'),
    'crumbs' => [],
    'nav' => 'forums',
]);

$loose = [];
foreach (node_roots() as $id) {
    $n = node_get($id);
    if (!node_listed($n)) {
        continue;
    }
    if ($n['type'] === 'category') {
        echo render_category($n);
    } else {
        $loose[] = $n;
    }
}
if ($loose) {
    echo '<section class="node-cat"><h2 class="node-cat-title">Разделы</h2>' . render_node_list($loose) . '</section>';
}
if (!node_roots()) {
    echo '<div class="card empty">' . icon('chats') . '<div>Разделов пока нет. Создайте их в админ-панели.</div></div>';
}

forum_footer();
