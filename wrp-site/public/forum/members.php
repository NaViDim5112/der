<?php
require __DIR__ . '/../../app/bootstrap.php';

$sorts = [
    'new' => ['Новые', 'u.id DESC'],
    'posts' => ['По сообщениям', 'u.posts_count DESC, u.id ASC'],
    'likes' => ['По реакциям', 'u.likes_received DESC, u.id ASC'],
    'name' => ['По алфавиту', 'u.username ASC'],
];
$q = mb_substr(acc_clean_line(query_str('q')), 0, 32);
$sort = query_str('sort', 'new');
if (!isset($sorts[$sort])) {
    $sort = 'new';
}
$groups = groups_all();
$gid = query_int('group');
if ($gid && (!isset($groups[$gid]) || $gid === WRP_GUEST_GROUP)) {
    $gid = 0;
}

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = 'u.username LIKE :q';
    $params['q'] = acc_like($q);
}
if ($gid) {
    $g = acc_group_sql($gid);
    $where[] = $g['sql'];
    $params = array_merge($params, $g['params']);
}
$whereSql = implode(' AND ', $where);
$total = (int)db_val('SELECT COUNT(*) FROM users u WHERE ' . $whereSql, $params);
$p = paginate($total, 30, query_int('page', 1));
$members = db_all(user_select_sql() . ' WHERE ' . $whereSql . ' ORDER BY ' . $sorts[$sort][1] . ' LIMIT :lim OFFSET :off',
    array_merge($params, ['lim' => $p['per_page'], 'off' => $p['offset']]));

// Самые активные - только на первой странице без фильтров
$top = [];
if ($q === '' && !$gid && $p['page'] === 1) {
    $top = db_all(user_select_sql() . ' WHERE u.posts_count > 0 AND u.is_banned = 0 ORDER BY u.posts_count DESC, u.likes_received DESC, u.id ASC LIMIT 5');
}
$filtered = $q !== '' || $gid;

forum_header([
    'title' => 'Участники',
    'crumbs' => [['Участники', url('/forum/members.php')]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<?php if ($top): ?>
<section class="block">
  <div class="block-head"><h2>Самые активные</h2><span class="muted small">по числу сообщений</span></div>
  <div class="acc-top">
    <?php foreach ($top as $i => $u): ?>
    <a class="acc-top-item" href="<?= e(acc_member_url($u['id'])) ?>">
      <span class="acc-top-rank">#<?= $i + 1 ?></span>
      <?= avatar($u, 'xl') ?>
      <span class="username" style="color:<?= e($u['group_color']) ?>"><?= e($u['username']) ?></span>
      <span class="acc-top-count"><b><?= num($u['posts_count']) ?></b> <?= plural($u['posts_count'], 'сообщение', 'сообщения', 'сообщений') ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="block">
  <div class="block-head">
    <h2>Участники <span class="muted small"><?= num($total) ?></span></h2>
  </div>
  <form class="acc-filter" method="get" action="<?= e(url('/forum/members.php')) ?>" style="padding:0 8px 14px">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по имени..." maxlength="32">
    <select class="select" name="sort" aria-label="Сортировка">
      <?php foreach ($sorts as $k => $s): ?><option value="<?= e($k) ?>"<?= $k === $sort ? ' selected' : '' ?>><?= e($s[0]) ?></option><?php endforeach; ?>
    </select>
    <select class="select" name="group" aria-label="Группа">
      <option value="">Все группы</option>
      <?php foreach ($groups as $id => $g): if ($id === WRP_GUEST_GROUP) { continue; } ?>
      <option value="<?= (int)$id ?>"<?= $id === $gid ? ' selected' : '' ?>><?= e($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-white" type="submit"><?= icon('search') ?> Найти</button>
    <?php if ($filtered || $sort !== 'new'): ?><a class="btn btn-ghost" href="<?= e(url('/forum/members.php')) ?>"><?= icon('x') ?> Сбросить</a><?php endif; ?>
  </form>
  <div style="padding:0 8px 8px">
    <?php if ($members): ?>
    <div class="acc-grid">
      <?php foreach ($members as $u): ?>
      <?= acc_member_card($u,
          '<div class="acc-mcard-stats"><span>Сообщения: <b>' . num($u['posts_count']) . '</b></span><span>Реакции: <b>' . num($u['likes_received']) . '</b></span><span>Баллы: <b>' . num(user_points($u)) . '</b></span></div>'
          . '<div class="acc-mcard-stats"><span>Регистрация: <b>' . e(fday($u['created_at'])) . '</b></span></div>') ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty"><?= icon('users') ?><div><?= $filtered ? 'Никого не нашли. Попробуйте изменить условия поиска.' : 'Участников пока нет.' ?></div></div>
    <?php endif; ?>
  </div>
  <?php if ($p['pages'] > 1): ?>
  <div class="block-foot"><?= pagination_html($p, '/forum/members.php', ['q' => $q, 'sort' => $sort !== 'new' ? $sort : null, 'group' => $gid ?: null]) ?></div>
  <?php endif; ?>
</section>
<?php
forum_footer();
