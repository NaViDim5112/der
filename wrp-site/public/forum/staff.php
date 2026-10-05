<?php
require __DIR__ . '/../../app/bootstrap.php';

$staffGroups = db_all('SELECT * FROM user_groups WHERE is_staff = 1 ORDER BY level DESC, display_order DESC, id DESC');
$blocks = [];
$total = 0;
foreach ($staffGroups as $g) {
    $c = acc_group_sql($g['id']);
    $members = db_all(user_select_sql() . ' WHERE ' . $c['sql'] . ' AND u.is_banned = 0 ORDER BY u.group_id = :pg DESC, u.username ASC',
        array_merge($c['params'], ['pg' => (int)$g['id']]));
    if ($members) {
        $blocks[] = ['group' => $g, 'members' => $members];
        $total += count($members);
    }
}

forum_header([
    'title' => 'Администрация проекта',
    'crumbs' => [['Участники', url('/forum/members.php')], ['Администрация', url('/forum/staff.php')]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
]);
?>
<div class="announcement acc-staff-intro"><?= icon('shield') ?><div>Здесь собрана команда проекта: администраторы, модераторы форума и хелперы. По вопросам игры пишите в соответствующие разделы форума, а с личными вопросами - в личные сообщения. Администрация никогда не спрашивает ваш пароль.</div></div>

<?php if (!$blocks): ?>
<div class="card empty"><?= icon('shield') ?><div>Список администрации пока пуст.</div></div>
<?php endif; ?>

<?php foreach ($blocks as $b): $g = $b['group']; ?>
<section class="block acc-staff-group">
  <div class="acc-staff-head" style="--c:<?= e($g['color']) ?>">
    <span class="acc-gdot"></span>
    <h2 style="color:<?= e($g['color']) ?>"><?= e($g['name']) ?></h2>
    <span class="muted"><?= num(count($b['members'])) ?> <?= plural(count($b['members']), 'человек', 'человека', 'человек') ?></span>
  </div>
  <div class="acc-grid" style="padding:0 8px 8px">
    <?php foreach ($b['members'] as $u):
        $online = acc_is_online($u);
        $foot = '<div class="acc-mcard-foot"><span class="acc-seen' . ($online ? ' on' : '') . '">' . e(acc_seen_text($u)) . '</span>';
        if (is_logged() && (int)$u['id'] !== uid() && !is_banned()) {
            $foot .= '<a class="btn btn-sm btn-outline" href="' . e(url('/forum/conversations.php', ['new' => 1, 'to' => $u['username']])) . '">' . icon('mail') . ' Написать</a>';
        }
        $foot .= '</div>';
        echo acc_member_card($u, $foot);
    endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<?php
forum_footer();
