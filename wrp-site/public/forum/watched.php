<?php
// Отслеживаемые темы и разделы
require __DIR__ . '/../../app/bootstrap.php';

require_login();

if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'unwatch') {
        thread_watch(input_int('thread_id'), false);
        flash('success', 'Вы больше не отслеживаете тему.');
    } elseif ($action === 'unwatch_all') {
        $n = db_exec('DELETE FROM thread_watch WHERE user_id = :u', ['u' => uid()]);
        flash('success', $n ? 'Вы отписались от всех тем.' : 'Отслеживаемых тем нет.');
    } elseif ($action === 'unwatch_node') {
        node_watch(input_int('node_id'), false);
        flash('success', 'Вы больше не отслеживаете раздел.');
    }
    redirect(fp_referer(url('/forum/watched.php')));
}

$nodeIds = fp_viewable_node_ids();
$in = db_in($nodeIds ?: [0], 'n');
$where = 'w.user_id = :u AND t.node_id IN (' . $in['sql'] . ')' . (can_moderate() ? '' : ' AND t.is_deleted = 0');
$params = array_merge(['u' => uid()], $in['params']);
$total = (int)db_val('SELECT COUNT(*) FROM thread_watch w JOIN threads t ON t.id = w.thread_id WHERE ' . $where, $params);
$pg = paginate($total, fp_threads_per_page(), query_int('page', 1));
$threads = db_all(fp_thread_select_sql('thread_watch w JOIN threads t ON t.id = w.thread_id')
    . ' WHERE ' . $where . ' ORDER BY t.last_post_at DESC, t.id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);

$watchedNodes = [];
foreach (db_all('SELECT node_id, created_at FROM node_watch WHERE user_id = :u ORDER BY created_at DESC', ['u' => uid()]) as $r) {
    $n = node_get($r['node_id']);
    if ($n && node_can_view($n)) {
        $watchedNodes[] = $n;
    }
}

forum_header([
    'title' => 'Отслеживаемое',
    'crumbs' => [['Форумы', url('/forum/')], ['Отслеживаемое', url('/forum/watched.php')]],
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'js' => ['thread.js'],
]);

$unwatchBtn = function ($t) {
    return '<form method="post" action="' . e(url('/forum/watched.php')) . '" class="inline-form">' . csrf_field()
        . '<input type="hidden" name="action" value="unwatch"><input type="hidden" name="thread_id" value="' . (int)$t['id'] . '">'
        . '<button class="trow-action" type="submit" title="Не отслеживать">' . icon('x') . '</button></form>';
};
?>
<?php if ($total): ?>
<div class="page-actions forum-actions">
  <?= fp_pagination($pg, '/forum/watched.php') ?>
  <form method="post" action="<?= e(url('/forum/watched.php')) ?>" class="inline-form" data-confirm="Перестать отслеживать все темы?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="unwatch_all">
    <button class="btn btn-black btn-pill" type="submit"><?= icon('x') ?> Отписаться от всех</button>
  </form>
</div>
<?php endif; ?>

<section class="block thread-list">
  <div class="trow-group">
    <div class="trow-group-head is-static"><span><?= icon('bell') ?> Отслеживаемые темы</span><span class="muted small"><?= num($total) ?></span></div>
    <?php if ($threads): ?>
    <div class="trow-group-body"><?= fp_thread_rows($threads, ['show_node' => true, 'actions' => $unwatchBtn]) ?></div>
    <?php else: ?>
    <div class="empty"><?= icon('bell') ?><div>Вы не отслеживаете ни одной темы. Нажмите «Отслеживать» в теме, чтобы получать оповещения об ответах.</div></div>
    <?php endif; ?>
  </div>
</section>

<?php if ($pg['pages'] > 1): ?>
<div class="page-actions forum-actions"><?= fp_pagination($pg, '/forum/watched.php') ?></div>
<?php endif; ?>

<section class="block thread-list">
  <div class="trow-group">
    <div class="trow-group-head is-static"><span><?= icon('folder') ?> Отслеживаемые разделы</span><span class="muted small"><?= num(count($watchedNodes)) ?></span></div>
    <?php if ($watchedNodes): ?>
    <div class="trow-group-body">
      <?php foreach ($watchedNodes as $n): ?>
      <div class="wnode-row">
        <a class="node-icon" href="<?= e(node_url($n)) ?>"><?= icon($n['icon']) ?></a>
        <div class="wnode-text">
          <a class="node-title" href="<?= e(node_url($n)) ?>"><?= e($n['title']) ?></a>
          <div class="node-desc"><?= $n['description'] ? e($n['description']) : 'Тем: ' . num($n['thread_count']) . ', сообщений: ' . num($n['post_count']) ?></div>
        </div>
        <form method="post" action="<?= e(url('/forum/watched.php')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="unwatch_node">
          <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
          <button class="btn btn-black btn-pill btn-sm" type="submit"><?= icon('x') ?> Не отслеживать</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty"><?= icon('folder') ?><div>Вы не отслеживаете разделы. Нажмите «Отслеживать» в разделе, чтобы узнавать о новых темах.</div></div>
    <?php endif; ?>
  </div>
</section>
<?php
forum_footer();
