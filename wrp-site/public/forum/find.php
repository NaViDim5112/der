<?php
// Поиск тем: новые сообщения, мои темы, темы с моим участием, темы без ответов
require __DIR__ . '/../../app/bootstrap.php';

$types = [
    'new' => ['Новые сообщения', 'chats', false],
    'mine' => ['Мои темы', 'user', true],
    'participated' => ['Темы с моим участием', 'chat', true],
    'unanswered' => ['Темы без ответов', 'help', false],
];
$type = query_str('type', 'new');
if (!isset($types[$type])) {
    $type = 'new';
}
if ($types[$type][2]) {
    require_login();
}
$guestNew = $type === 'new' && !is_logged();
$title = $guestNew ? 'Последние темы' : $types[$type][0];

$nodeIds = fp_viewable_node_ids();
$in = db_in($nodeIds ?: [0], 'n');
$where = ['t.node_id IN (' . $in['sql'] . ')', 't.is_deleted = 0'];
$params = $in['params'];
$join = '';
$order = 't.last_post_at DESC, t.id DESC';

switch ($type) {
    case 'new':
        if (is_logged()) {
            $join = ' LEFT JOIN thread_reads tr ON tr.thread_id = t.id AND tr.user_id = :u1
                      LEFT JOIN node_reads nr ON nr.node_id = t.node_id AND nr.user_id = :u2';
            $params['u1'] = uid();
            $params['u2'] = uid();
            $where[] = 't.last_post_at > :cut';
            $where[] = '(tr.read_at IS NULL OR tr.read_at < t.last_post_at)';
            $where[] = '(nr.read_at IS NULL OR nr.read_at < t.last_post_at)';
            $params['cut'] = read_cutoff();
        }
        break;
    case 'mine':
        $where[] = 't.user_id = :u';
        $params['u'] = uid();
        break;
    case 'participated':
        $where[] = 'EXISTS (SELECT 1 FROM posts pp WHERE pp.thread_id = t.id AND pp.user_id = :u AND pp.is_deleted = 0)';
        $params['u'] = uid();
        break;
    case 'unanswered':
        $where[] = 't.reply_count = 0';
        $order = 't.created_at DESC, t.id DESC';
        break;
}
$w = implode(' AND ', $where);

// Подзапрос с JOIN нужен и для подсчёта
$total = (int)db_val('SELECT COUNT(*) FROM threads t' . $join . ' WHERE ' . $w, $params);
$pg = paginate($total, fp_threads_per_page(), query_int('page', 1));
$sql = fp_thread_select_sql() . $join . ' WHERE ' . $w . ' ORDER BY ' . $order . ' LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'];
$threads = db_all($sql, $params);

forum_header([
    'title' => $title,
    'crumbs' => [['Форумы', url('/forum/')], [$title, url('/forum/find.php', ['type' => $type])]],
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'js' => ['thread.js'],
]);
?>
<div class="tabs find-tabs">
  <?php foreach ($types as $k => $t): if ($t[2] && !is_logged()) { continue; } ?>
  <a class="tab<?= $k === $type ? ' active' : '' ?>" href="<?= e(url('/forum/find.php', ['type' => $k])) ?>"><?= icon($t[1]) ?> <?= e($k === 'new' && !is_logged() ? 'Последние темы' : $t[0]) ?></a>
  <?php endforeach; ?>
</div>

<?php $canMarkRead = $type === 'new' && is_logged() && $total; ?>
<?php if ($pg['pages'] > 1 || $canMarkRead): ?>
<div class="page-actions forum-actions">
  <?= fp_pagination($pg, '/forum/find.php', ['type' => $type]) ?>
  <?php if ($canMarkRead): ?>
  <form method="post" action="<?= e(url('/forum/mark-read.php')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <button class="btn btn-black btn-pill" type="submit"><?= icon('check-all') ?> Отметить всё прочитанным</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<section class="block thread-list">
  <?php if ($threads): ?>
  <div class="trow-group">
    <div class="trow-group-head is-static"><span><?= icon($types[$type][1]) ?> <?= e($title) ?></span><span class="muted small"><?= num($total) ?> <?= plural($total, 'тема', 'темы', 'тем') ?></span></div>
    <div class="trow-group-body"><?= fp_thread_rows($threads, ['show_node' => true]) ?></div>
  </div>
  <?php else: ?>
  <div class="empty">
    <?= icon($types[$type][1]) ?>
    <div><?php
        if ($type === 'new' && is_logged()) {
            echo 'Непрочитанных тем нет. Вы всё прочитали!';
        } elseif ($type === 'mine') {
            echo 'Вы ещё не создавали тем.';
        } elseif ($type === 'participated') {
            echo 'Вы ещё не участвовали в обсуждениях.';
        } elseif ($type === 'unanswered') {
            echo 'Все темы получили ответы.';
        } else {
            echo 'Тем пока нет.';
        }
    ?></div>
  </div>
  <?php endif; ?>
</section>

<?php if ($pg['pages'] > 1): ?>
<div class="page-actions forum-actions"><?= fp_pagination($pg, '/forum/find.php', ['type' => $type]) ?></div>
<?php endif; ?>
<?php
forum_footer();
