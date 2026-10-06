<?php
// Раздел форума: подфорумы и список тем
require __DIR__ . '/../../app/bootstrap.php';

$node = node_get(query_int('id'));
if (!$node) {
    abort(404, 'Такого раздела нет.');
}
if ($node['type'] === 'category') {
    redirect(url('/forum/category.php', ['id' => (int)$node['id']]));
}
if ($node['type'] === 'link') {
    if (!$node['link_url']) {
        abort(404);
    }
    redirect(nav_link_href($node['link_url']));
}
if (!node_can_view($node)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}
$nid = (int)$node['id'];

// Отслеживание раздела (оповещения о новых темах)
if (is_post()) {
    csrf_check();
    require_login();
    if (input('action') === 'watch') {
        $on = !node_is_watched($nid);
        node_watch($nid, $on);
        if (fp_is_ajax()) {
            json_out(['ok' => true, 'watched' => $on]);
        }
        flash('success', $on ? 'Вы отслеживаете раздел: о новых темах придёт оповещение.' : 'Вы больше не отслеживаете раздел.');
    }
    redirect(url('/forum/forum.php', ['id' => $nid]));
}

$isMod = can_moderate();
$nodePrefixes = node_prefixes($nid);
$allPrefixes = prefixes_all();

// Фильтры
$prefix = query_int('prefix');
if ($prefix > 0 && !isset($allPrefixes[$prefix])) {
    $prefix = 0;
}
if ($prefix < -1) {
    $prefix = 0;
}
$orders = [
    'last' => ['Последнее сообщение', 't.last_post_at DESC, t.id DESC'],
    'created' => ['Дата создания', 't.created_at DESC, t.id DESC'],
    'replies' => ['Ответы', 't.reply_count DESC, t.last_post_at DESC'],
    'views' => ['Просмотры', 't.views DESC, t.last_post_at DESC'],
];
$orders = hook_filter('forum_orders', $orders, $node);
$order = query_str('order', 'last');
if (!isset($orders[$order])) {
    $order = 'last';
}
$mine = is_logged() && query_int('mine') === 1;
$filterParams = ['id' => $nid, 'prefix' => $prefix ?: null, 'order' => $order !== 'last' ? $order : null, 'mine' => $mine ? 1 : null];
$hasFilters = $prefix !== 0 || $order !== 'last' || $mine;

$where = ['t.node_id = :n'];
$params = ['n' => $nid];
if (!$isMod) {
    $where[] = 't.is_deleted = 0';
}
if ($prefix > 0) {
    $where[] = 't.prefix_id = :p';
    $params['p'] = $prefix;
} elseif ($prefix === -1) {
    $where[] = 't.prefix_id IS NULL';
}
if ($mine) {
    $where[] = 't.user_id = :u';
    $params['u'] = uid();
}
// Модули могут добавить свои условия: [условия, параметры]
list($where, $params) = hook_filter('forum_threads_where', [$where, $params], $node);
$w = implode(' AND ', $where);

$children = node_visible_children($nid);
$anyThreads = (int)db_val('SELECT COUNT(*) FROM threads WHERE node_id = :n' . ($isMod ? '' : ' AND is_deleted = 0'), ['n' => $nid]);
// Раздел-контейнер: только подфорумы, без списка тем
$container = (int)$node['thread_level'] >= 80 && $children && !$anyThreads;

$pinned = [];
$threads = [];
$pg = paginate(0, fp_threads_per_page(), 1);
if (!$container) {
    $total = (int)db_val('SELECT COUNT(*) FROM threads t WHERE ' . $w . ' AND t.is_pinned = 0', $params);
    $pg = paginate($total, fp_threads_per_page(), query_int('page', 1));
    if ($pg['page'] === 1) {
        $pinned = db_all(fp_thread_select_sql() . ' WHERE ' . $w . ' AND t.is_pinned = 1 ORDER BY ' . $orders[$order][1], $params);
    }
    $threads = db_all(fp_thread_select_sql() . ' WHERE ' . $w . ' AND t.is_pinned = 0 ORDER BY ' . $orders[$order][1]
        . ' LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
}

// Кнопка «Создать тему» в заголовке
$titleActions = '';
if (!$container) {
    if (node_can_thread($node)) {
        $titleActions = '<a class="btn btn-accent btn-pill" href="' . e(url('/forum/new-thread.php', ['node' => $nid])) . '">' . icon('edit') . ' Создать тему</a>';
    } elseif (!is_logged() && !$node['is_closed'] && (int)$node['thread_level'] <= (int)(groups_all()[WRP_USER_GROUP]['level'] ?? 10)) {
        $titleActions = '<a class="btn btn-black btn-pill" href="' . e(url('/forum/login.php', ['return' => current_url()])) . '">' . icon('login') . ' Войдите, чтобы создать тему</a>';
    }
}

forum_header([
    'title' => $node['title'],
    'meta_html' => $node['description'] ? e($node['description']) : '',
    'crumbs' => node_crumbs($nid),
    'title_actions' => $titleActions,
    'nav' => 'forums',
    'css' => ['thread.css'],
    'js' => ['thread.js'],
    'description' => $node['description'] ?: null,
]);

if ($children) {
    echo '<section class="node-cat"><h2 class="node-cat-title">Подфорумы</h2>' . render_node_list($children) . '</section>';
}

if (!$container):
    $pagesHtml = fp_pagination($pg, '/forum/forum.php', $filterParams);
    $watched = node_is_watched($nid);
?>
<div class="page-actions forum-actions">
  <?= $pagesHtml ?>
  <?php if (is_logged()): ?>
  <form method="post" action="<?= e(url('/forum/mark-read.php')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="node" value="<?= $nid ?>">
    <button class="btn btn-black btn-pill" type="submit"><?= icon('check-all') ?> Отметить прочитанным</button>
  </form>
  <form method="post" action="<?= e(url('/forum/forum.php', ['id' => $nid])) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="watch">
    <button class="btn btn-black btn-pill<?= $watched ? ' is-on' : '' ?>" type="submit" title="Оповещения о новых темах в разделе"><?= icon('bell') ?> <?= $watched ? 'Не отслеживать' : 'Отслеживать' ?></button>
  </form>
  <?php endif; ?>
  <?= hook_html('forum_actions', $node) ?>
</div>

<?= hook_html('forum_before_list', $node) ?>

<section class="block thread-list" data-node="<?= $nid ?>">
  <div class="block-head thread-list-head">
    <div class="filter-tags">
      <?php if ($prefix > 0): ?>
      <a class="tag filter-tag" href="<?= e(url('/forum/forum.php', array_merge($filterParams, ['prefix' => null]))) ?>" title="Убрать фильтр">Префикс: <?= prefix_html($prefix) ?><?= icon('x') ?></a>
      <?php elseif ($prefix === -1): ?>
      <a class="tag filter-tag" href="<?= e(url('/forum/forum.php', array_merge($filterParams, ['prefix' => null]))) ?>" title="Убрать фильтр">Без префикса<?= icon('x') ?></a>
      <?php endif; ?>
      <?php if ($order !== 'last'): ?>
      <a class="tag filter-tag" href="<?= e(url('/forum/forum.php', array_merge($filterParams, ['order' => null]))) ?>" title="Убрать сортировку">Сортировка: <?= e($orders[$order][0]) ?><?= icon('x') ?></a>
      <?php endif; ?>
      <?php if ($mine): ?>
      <a class="tag filter-tag" href="<?= e(url('/forum/forum.php', array_merge($filterParams, ['mine' => null]))) ?>" title="Убрать фильтр">Только мои темы<?= icon('x') ?></a>
      <?php endif; ?>
      <span class="muted small thread-count"><?= $hasFilters ? 'Найдено' : 'Всего' ?>: <?= num($pg['total'] + count($pinned)) ?> <?= plural($pg['total'] + count($pinned), 'тема', 'темы', 'тем') ?></span>
    </div>
    <div class="dropdown filter-dd">
      <button class="btn btn-ghost btn-sm" type="button" data-dropdown><?= icon('filter') ?> Фильтры <?= icon('chevron-down') ?></button>
      <div class="dropdown-menu dropdown-right filter-menu">
        <form method="get" action="<?= e(url('/forum/forum.php')) ?>" class="form">
          <input type="hidden" name="id" value="<?= $nid ?>">
          <?php if ($nodePrefixes || $prefix > 0): ?>
          <div class="form-row">
            <label class="label" for="f-prefix">Префикс</label>
            <select class="select" id="f-prefix" name="prefix">
              <option value="">Любой</option>
              <option value="-1"<?= $prefix === -1 ? ' selected' : '' ?>>Без префикса</option>
              <?php foreach ($nodePrefixes as $id => $pr): ?>
              <option value="<?= (int)$id ?>"<?= $prefix === (int)$id ? ' selected' : '' ?>><?= e($pr['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="form-row">
            <label class="label" for="f-order">Сортировка</label>
            <select class="select" id="f-order" name="order">
              <?php foreach ($orders as $k => $o): ?>
              <option value="<?= e($k) ?>"<?= $order === $k ? ' selected' : '' ?>><?= e($o[0]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (is_logged()): ?>
          <label class="check"><input type="checkbox" name="mine" value="1"<?= $mine ? ' checked' : '' ?>><span>Только мои темы</span></label>
          <?php endif; ?>
          <div class="form-actions">
            <button class="btn btn-accent btn-sm" type="submit">Применить</button>
            <?php if ($hasFilters): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/forum/forum.php', ['id' => $nid])) ?>">Сбросить</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($pinned): ?>
  <div class="trow-group" data-group="pinned-<?= $nid ?>">
    <button class="trow-group-head" type="button" data-group-toggle><span><?= icon('pin') ?> Закреплено <span class="muted">(<?= count($pinned) ?>)</span></span><?= icon('chevron-down', 'trow-group-caret') ?></button>
    <div class="trow-group-body"><?= fp_thread_rows($pinned) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($threads): ?>
  <div class="trow-group">
    <div class="trow-group-head is-static"><span><?= icon('chats') ?> Темы</span></div>
    <div class="trow-group-body"><?= fp_thread_rows($threads) ?></div>
  </div>
  <?php elseif (!$pinned): ?>
  <div class="empty">
    <?= icon('chats') ?>
    <div><?= $hasFilters ? 'Нет тем, подходящих под фильтры.' : 'В этом разделе пока нет тем.' ?></div>
    <?php if (!$hasFilters && node_can_thread($node)): ?>
    <a class="btn btn-accent btn-pill mt-2" href="<?= e(url('/forum/new-thread.php', ['node' => $nid])) ?>"><?= icon('edit') ?> Создать первую тему</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?= hook_html('forum_list_after', $node, $pg) ?>

<?php if ($pg['pages'] > 1): ?>
<div class="page-actions forum-actions"><?= $pagesHtml ?></div>
<?php endif; ?>
<?php
endif;

forum_footer();
