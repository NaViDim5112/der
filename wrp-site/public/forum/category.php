<?php
require __DIR__ . '/../../app/bootstrap.php';

$cat = node_get(query_int('id'));
if (!$cat || $cat['type'] !== 'category') {
    abort(404);
}
if (!node_can_view($cat)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}

forum_header([
    'title' => $cat['title'],
    'crumbs' => node_crumbs($cat['id']),
    'nav' => 'forums',
]);

$html = render_category($cat);
echo $html !== '' ? $html : '<div class="card empty">' . icon('chats') . '<div>В этой категории пока нет разделов.</div></div>';

forum_footer();
