<?php
require __DIR__ . '/../../app/bootstrap.php';

$since = date('Y-m-d H:i:s', time() - ACC_ONLINE_SECONDS);
$on = online_list(1);
$users = db_all(user_select_sql() . ' WHERE u.last_activity > :t ORDER BY g.is_staff DESC, u.last_activity DESC LIMIT 300', ['t' => $since]);
$staffCount = 0;
foreach ($users as $u) {
    if (!empty($u['is_staff'])) {
        $staffCount++;
    }
}

forum_header([
    'title' => 'Сейчас на форуме',
    'crumbs' => [['Участники', url('/forum/members.php')], ['Сейчас на форуме', url('/forum/online.php')]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
]);
?>
<div class="acc-online-summary">
  <div class="acc-stat"><b><?= num($on['total']) ?></b><span>Всего на форуме</span></div>
  <div class="acc-stat"><b><?= num($on['members']) ?></b><span><?= plural($on['members'], 'Пользователь', 'Пользователя', 'Пользователей') ?></span></div>
  <div class="acc-stat"><b><?= num($on['guests']) ?></b><span><?= plural($on['guests'], 'Гость', 'Гостя', 'Гостей') ?></span></div>
</div>

<section class="block">
  <div class="block-head">
    <h2>Пользователи онлайн</h2>
    <span class="muted small">За последние 15 минут<?= $staffCount ? ' · из них администрация: ' . num($staffCount) : '' ?></span>
  </div>
  <div style="padding:0 8px 8px">
    <?php if ($users): ?>
    <div class="acc-grid">
      <?php foreach ($users as $u): ?>
      <?= acc_member_card($u, '<div class="acc-mcard-foot"><span class="acc-seen' . (strtotime($u['last_activity']) > time() - 300 ? ' on' : '') . '">' . e(acc_ucfirst(fdate($u['last_activity']))) . '</span></div>') ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty"><?= icon('users') ?><div>Сейчас на форуме нет пользователей<?= $on['guests'] ? ', только гости' : '' ?>.</div></div>
    <?php endif; ?>
  </div>
</section>
<?php
forum_footer();
