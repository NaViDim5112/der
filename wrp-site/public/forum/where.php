<?php
// «Кто где на форуме»: что сейчас делают посетители (модуль «Сообщество»)
require __DIR__ . '/../../app/bootstrap.php';

$since = date('Y-m-d H:i:s', time() - COM_ONLINE_SECONDS);
$users = db_all('SELECT u.id, u.username, u.avatar, u.group_id, u.secondary_groups, u.custom_title, u.last_activity, u.last_location,
                        g.name AS group_name, g.color AS group_color, g.is_staff
                 FROM users u JOIN user_groups g ON g.id = u.group_id
                 WHERE u.last_activity > :t ORDER BY g.is_staff DESC, u.last_activity DESC LIMIT 200', ['t' => $since]);
$guestRows = db_all('SELECT location, COUNT(*) AS n FROM online WHERE user_id IS NULL AND last_activity > :t GROUP BY location ORDER BY n DESC LIMIT 100', ['t' => $since]);

$locs = array_column($users, 'last_location');
foreach ($guestRows as $g) {
    $locs[] = $g['location'];
}
$maps = com_where_resolve(array_filter($locs, 'is_string'));

// Гостей с одинаковым описанием места складываем
$guests = [];
$guestTotal = 0;
foreach ($guestRows as $g) {
    $d = com_where_describe($g['location'], $maps);
    $key = $d['text'] . '|' . ($d['target'] ?? '');
    if (!isset($guests[$key])) {
        $guests[$key] = ['d' => $d, 'n' => 0];
    }
    $guests[$key]['n'] += (int)$g['n'];
    $guestTotal += (int)$g['n'];
}
uasort($guests, function ($a, $b) {
    return $b['n'] - $a['n'];
});

forum_header([
    'title' => 'Кто где на форуме',
    'crumbs' => [['Участники', url('/forum/members.php')], ['Кто где', url('/forum/where.php')]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
]);
?>
<div class="acc-online-summary">
  <div class="acc-stat"><b><?= num(count($users) + $guestTotal) ?></b><span>Всего на форуме</span></div>
  <div class="acc-stat"><b><?= num(count($users)) ?></b><span><?= plural(count($users), 'Пользователь', 'Пользователя', 'Пользователей') ?></span></div>
  <div class="acc-stat"><b><?= num($guestTotal) ?></b><span><?= plural($guestTotal, 'Гость', 'Гостя', 'Гостей') ?></span></div>
</div>

<section class="block">
  <div class="block-head">
    <h2>Пользователи</h2>
    <span class="muted small">За последние 15 минут · <a href="<?= e(url('/forum/online.php')) ?>">карточками</a></span>
  </div>
  <?php if ($users): ?>
  <div class="com-where-list">
    <?php foreach ($users as $u): $d = com_where_describe($u['last_location'], $maps); ?>
    <div class="com-where-row">
      <a class="com-where-ava" href="<?= e(url('/forum/member.php', ['id' => (int)$u['id']])) ?>"><?= avatar($u, 'm') ?></a>
      <div class="com-where-who">
        <?= user_link($u) ?>
        <span class="com-where-title"><?= e(acc_user_title($u)) ?></span>
      </div>
      <div class="com-where-what"><span class="com-where-icon"><?= icon($d['icon']) ?></span><?= com_where_html($d) ?></div>
      <div class="com-where-time muted small"><?= e(fdate($u['last_activity'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= icon('users') ?><div>Сейчас на форуме нет пользователей.</div></div>
  <?php endif; ?>
</section>

<section class="block">
  <div class="block-head">
    <h2>Гости</h2>
    <span class="muted small"><?= num($guestTotal) ?> <?= plural($guestTotal, 'гость', 'гостя', 'гостей') ?></span>
  </div>
  <?php if ($guests): ?>
  <div class="com-where-list">
    <?php foreach ($guests as $g): ?>
    <div class="com-where-row com-where-guest">
      <span class="com-where-ava"><span class="avatar avatar-m com-guest-ava"><?= icon('user') ?></span></span>
      <div class="com-where-who"><b><?= num($g['n']) ?> <?= plural($g['n'], 'гость', 'гостя', 'гостей') ?></b></div>
      <div class="com-where-what"><span class="com-where-icon"><?= icon($g['d']['icon']) ?></span><?= com_where_html($g['d']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty"><?= icon('user') ?><div>Гостей сейчас нет.</div></div>
  <?php endif; ?>
</section>
<?php
forum_footer();
