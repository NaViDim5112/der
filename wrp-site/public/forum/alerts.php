<?php
// Оповещения пользователя
require __DIR__ . '/../../app/bootstrap.php';

require_login();

if (is_post()) {
    csrf_check();
    if (input('action') === 'read_all') {
        db_exec('UPDATE alerts SET is_read = 1 WHERE user_id = :u AND is_read = 0', ['u' => uid()]);
        flash('success', 'Все оповещения отмечены прочитанными.');
    }
    redirect(url('/forum/alerts.php'));
}

$total = (int)db_val('SELECT COUNT(*) FROM alerts WHERE user_id = :u', ['u' => uid()]);
$pg = paginate($total, 25, query_int('page', 1));
$alerts = db_all('SELECT a.*, u.username AS actor_name, u.avatar AS actor_avatar, g.color AS actor_color,
                         t.title AS thread_title, t.node_id AS thread_node_id
                  FROM alerts a
                  LEFT JOIN users u ON u.id = a.actor_id
                  LEFT JOIN user_groups g ON g.id = u.group_id
                  LEFT JOIN threads t ON t.id = a.thread_id
                  WHERE a.user_id = :u
                  ORDER BY a.id DESC
                  LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], ['u' => uid()]);
$unreadTotal = (int)db_val('SELECT COUNT(*) FROM alerts WHERE user_id = :u AND is_read = 0', ['u' => uid()]);

// Открытие страницы отмечает оповещения прочитанными (подсветка остаётся до следующего захода)
if ($unreadTotal) {
    db_exec('UPDATE alerts SET is_read = 1 WHERE user_id = :u AND is_read = 0', ['u' => uid()]);
}

$typeIcons = ['reply' => 'reply', 'quote' => 'quote', 'like' => 'like', 'mention' => 'at', 'prefix' => 'tag', 'move' => 'move',
    'conversation' => 'mail', 'profile_post' => 'edit', 'profile_comment' => 'chat', 'profile_like' => 'like', 'follow' => 'user-plus', 'thread' => 'file'];

forum_header([
    'title' => 'Оповещения',
    'crumbs' => [['Форумы', url('/forum/')], ['Оповещения', url('/forum/alerts.php')]],
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
]);
?>
<div class="page-actions forum-actions">
  <?= fp_pagination($pg, '/forum/alerts.php') ?>
  <form method="post" action="<?= e(url('/forum/alerts.php')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="read_all">
    <button class="btn btn-black btn-pill" type="submit"<?= $unreadTotal ? '' : ' disabled' ?>><?= icon('check-all') ?> Отметить всё прочитанным</button>
  </form>
</div>

<section class="block alerts-block">
  <div class="trow-group-head is-static"><span><?= icon('bell') ?> Оповещения</span><span class="muted small"><?= $unreadTotal ? 'Новых: ' . num($unreadTotal) : 'Новых нет' ?></span></div>
  <?php if ($alerts): ?>
  <div class="alert-list">
    <?php foreach ($alerts as $a):
        // Название темы скрываем, если раздел теперь недоступен
        if (!empty($a['thread_node_id']) && !node_can_view(node_get($a['thread_node_id']))) {
            $a['thread_title'] = 'скрытая тема';
        }
        $actor = $a['actor_id'] && $a['actor_name'] !== null ? fp_list_user($a['actor_id'], $a['actor_name'], $a['actor_avatar'], $a['actor_color']) : null;
        $ico = $typeIcons[$a['type']] ?? 'bell';
    ?>
    <a class="alert-row<?= $a['is_read'] ? '' : ' unread' ?>" href="<?= e(alert_link($a)) ?>">
      <span class="alert-ava">
        <?= $actor && !in_array($a['type'], ['prefix', 'move'], true) ? avatar($actor, 'm') : '<span class="alert-sys">' . icon($ico) . '</span>' ?>
        <span class="alert-type alert-type-<?= e($a['type']) ?>"><?= icon($ico) ?></span>
      </span>
      <span class="alert-text">
        <span class="alert-msg"><?= alert_text($a) ?></span>
        <span class="alert-date muted"><?= icon('clock') ?><?= e(fdate($a['created_at'])) ?></span>
      </span>
      <?php if (!$a['is_read']): ?><span class="alert-dot" title="Новое"></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= icon('bell') ?><div>Оповещений пока нет. Здесь появятся ответы в ваших темах, цитаты, реакции и смена статуса жалоб.</div></div>
  <?php endif; ?>
</section>

<?php if ($pg['pages'] > 1): ?>
<div class="page-actions forum-actions"><?= fp_pagination($pg, '/forum/alerts.php') ?></div>
<?php endif; ?>
<?php
forum_footer();
