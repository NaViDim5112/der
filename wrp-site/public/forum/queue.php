<?php
// Очередь рассмотрения: открытые жалобы, заявления и обращения из разделов, где пользователь рассматривает темы
require __DIR__ . '/../../app/bootstrap.php';

require_login();
if (!wf_ready()) {
    abort(404, 'Очередь пока недоступна: администратору нужно обновить базу сайта.');
}
$nodeIds = wf_processable_node_ids();
if (!$nodeIds) {
    abort(403, 'Очередь доступна сотрудникам, которые рассматривают жалобы, заявления и обращения.');
}

$filters = [
    'all' => 'Все',
    'free' => 'Не взятые',
    'mine' => 'Мои',
    'overdue' => 'Просроченные',
    'evidence' => 'Ждут доказательств',
];
$f = query_str('f', 'all');
if (!isset($filters[$f])) {
    $f = 'all';
}
$nodeF = query_int('node');
if ($nodeF && !in_array($nodeF, $nodeIds, true)) {
    $nodeF = 0;
}
$sorts = [
    'deadline' => ['По сроку ответа', '(n.deadline_hours = 0) ASC, DATE_ADD(t.created_at, INTERVAL n.deadline_hours HOUR) ASC, t.id ASC'],
    'new' => ['Сначала новые', 't.created_at DESC, t.id DESC'],
    'activity' => ['По последнему ответу', 't.last_post_at DESC, t.id DESC'],
];
$sort = query_str('sort', 'deadline');
if (!isset($sorts[$sort])) {
    $sort = 'deadline';
}
$keep = ['node' => $nodeF ?: null, 'sort' => $sort !== 'deadline' ? $sort : null];

// Открытые темы: не удалены, не закрыты, без окончательного статуса
$in = db_in($nodeF ? [$nodeF] : $nodeIds, 'n');
$from = 'threads t JOIN wf_nodes n ON n.node_id = t.node_id LEFT JOIN wf_threads w ON w.thread_id = t.id';
$base = 't.node_id IN (' . $in['sql'] . ') AND t.is_deleted = 0 AND t.is_locked = 0 AND (t.prefix_id IS NULL OR FIND_IN_SET(t.prefix_id, n.final_prefixes) = 0)';
$overdueSql = 'n.deadline_hours > 0 AND t.created_at < DATE_SUB(:now, INTERVAL n.deadline_hours HOUR)';
$ev = setting_int('wf_evidence_prefix_id');

$counts = db_one('SELECT COUNT(*) AS c_all,
        COALESCE(SUM(w.claimed_by IS NULL), 0) AS c_free,
        COALESCE(SUM(w.claimed_by = :me), 0) AS c_mine,
        COALESCE(SUM(' . $overdueSql . '), 0) AS c_overdue,
        COALESCE(SUM(t.prefix_id = :ev), 0) AS c_evidence
    FROM ' . $from . ' WHERE ' . $base, array_merge($in['params'], ['me' => uid(), 'now' => now(), 'ev' => $ev]));

$where = $base;
$params = $in['params'];
if ($f === 'free') {
    $where .= ' AND w.claimed_by IS NULL';
} elseif ($f === 'mine') {
    $where .= ' AND w.claimed_by = :me';
    $params['me'] = uid();
} elseif ($f === 'overdue') {
    $where .= ' AND ' . $overdueSql;
    $params['now'] = now();
} elseif ($f === 'evidence') {
    $where .= ' AND t.prefix_id = :ev';
    $params['ev'] = $ev;
}
$total = (int)$counts['c_' . $f];
$pg = paginate($total, fp_threads_per_page(), query_int('page', 1));
$threads = db_all(fp_thread_select_sql($from) . ' WHERE ' . $where . ' ORDER BY ' . $sorts[$sort][1]
    . ' LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
wf_list_prime(array_column($threads, 'id'));

$titleActions = '<a class="btn btn-black btn-pill" href="' . e(url('/forum/staff-stats.php')) . '">' . icon('wf-chart') . ' Статистика</a>';
forum_header([
    'title' => 'Очередь рассмотрения',
    'meta_html' => 'Жалобы, заявления и обращения, которые ждут решения в ваших разделах. Сначала - те, у кого раньше заканчивается срок ответа.',
    'crumbs' => [['Форумы', url('/forum/')], ['Очередь рассмотрения', url('/forum/queue.php')]],
    'title_actions' => $titleActions,
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css', 'workflow.css'],
    'js' => ['thread.js', 'workflow.js'],
]);

$back = current_url();
$rowActions = function ($t) use ($back) {
    if (wf_list_claim($t)) {
        return '';
    }
    return '<form method="post" action="' . e(url('/forum/workflow.php')) . '" class="wf-take">' . csrf_field()
        . '<input type="hidden" name="action" value="claim"><input type="hidden" name="thread_id" value="' . (int)$t['id'] . '">'
        . '<input type="hidden" name="return" value="' . e($back) . '">'
        . '<button type="submit" title="Взять на рассмотрение">' . icon('wf-claim') . '<span class="hide-sm">Взять</span></button></form>';
};
$pagesHtml = fp_pagination($pg, '/forum/queue.php', array_merge($keep, ['f' => $f !== 'all' ? $f : null]));
?>
<nav class="tabs wf-queue-tabs" aria-label="Фильтр очереди">
  <?php foreach ($filters as $k => $label): $n = (int)$counts['c_' . $k]; ?>
  <a class="tab<?= $f === $k ? ' active' : '' ?>" href="<?= e(url('/forum/queue.php', array_merge($keep, ['f' => $k !== 'all' ? $k : null]))) ?>"><?= e($label) ?>
    <span class="wf-tab-count<?= $k === 'overdue' && $n ? ' is-hot' : '' ?>"><?= num($n) ?></span></a>
  <?php endforeach; ?>
</nav>

<section class="block thread-list">
  <div class="block-head thread-list-head">
    <form method="get" action="<?= e(url('/forum/queue.php')) ?>" class="wf-queue-filters">
      <?php if ($f !== 'all'): ?><input type="hidden" name="f" value="<?= e($f) ?>"><?php endif; ?>
      <select class="select" name="node" aria-label="Раздел">
        <option value="">Все мои разделы</option>
        <?php foreach ($nodeIds as $nid): $n = node_get($nid); ?>
        <option value="<?= (int)$nid ?>"<?= $nodeF === $nid ? ' selected' : '' ?>><?= e($n['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="select" name="sort" aria-label="Сортировка">
        <?php foreach ($sorts as $k => $s): ?>
        <option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($s[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-ghost" type="submit"><?= icon('filter') ?> Показать</button>
    </form>
    <span class="muted small thread-count">Найдено: <?= num($total) ?></span>
  </div>
  <?php if ($threads): ?>
  <div class="trow-group-body"><?= fp_thread_rows($threads, ['show_node' => true, 'actions' => $rowActions]) ?></div>
  <?php else: ?>
  <div class="empty wf-queue-empty">
    <?= icon('check-all') ?>
    <div><?= $f === 'all' ? 'Очередь пуста: всё рассмотрено. Отличная работа!' : 'Здесь пусто: подходящих тем нет.' ?></div>
  </div>
  <?php endif; ?>
</section>
<?php if ($pg['pages'] > 1): ?>
<div class="page-actions forum-actions"><?= $pagesHtml ?></div>
<?php endif; ?>
<?php
forum_footer();
