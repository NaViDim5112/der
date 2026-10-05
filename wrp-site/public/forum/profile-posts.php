<?php
require __DIR__ . '/../../app/bootstrap.php';

$q = mb_substr(acc_clean_line(query_str('q')), 0, 100);
$author = mb_substr(acc_clean_line(query_str('user')), 0, 32);
$searchMode = isset($_GET['search']) || $q !== '' || $author !== '';
$searched = $q !== '' || $author !== '';
$error = '';

$where = ['pp.is_deleted = 0'];
$params = [];
if ($q !== '') {
    if (mb_strlen($q) < 2) {
        $error = 'Введите хотя бы 2 символа для поиска.';
    }
    $where[] = 'pp.body LIKE :q';
    $params['q'] = acc_like($q);
}
if ($author !== '') {
    $au = user_by_name($author);
    if (!$au) {
        $error = 'Пользователь «' . $author . '» не найден.';
    } else {
        $where[] = 'pp.user_id = :au';
        $params['au'] = (int)$au['id'];
    }
}
$whereSql = implode(' AND ', $where);
$posts = [];
$p = paginate(0, 20, 1);
if ($error === '' && (!$searchMode || $searched)) {
    $total = (int)db_val('SELECT COUNT(*) FROM profile_posts pp WHERE ' . $whereSql, $params);
    $p = paginate($total, 20, query_int('page', 1));
    $posts = acc_wall_query($whereSql, $params, $p['per_page'], $p['offset']);
}
$listParams = ['search' => $searchMode && !$searched ? 1 : null, 'q' => $q, 'user' => $author];
$return = url('/forum/profile-posts.php', array_merge($listParams, ['page' => $p['page'] > 1 ? $p['page'] : null]));
$title = $searchMode ? 'Поиск сообщений профилей' : 'Новые сообщения профилей';

forum_header([
    'title' => $title,
    'crumbs' => [['Участники', url('/forum/members.php')], [$title, url('/forum/profile-posts.php', $searchMode ? ['search' => 1] : [])]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<nav class="acc-pills">
  <a class="<?= !$searchMode ? 'active' : '' ?>" href="<?= e(url('/forum/profile-posts.php')) ?>">Новые сообщения</a>
  <a class="<?= $searchMode ? 'active' : '' ?>" href="<?= e(url('/forum/profile-posts.php', ['search' => 1])) ?>">Поиск</a>
</nav>

<?php if ($searchMode): ?>
<section class="card mb-2">
  <form class="form" method="get" action="<?= e(url('/forum/profile-posts.php')) ?>">
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="pp-q">Текст сообщения</label>
        <input class="input" id="pp-q" type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Что ищем?">
      </div>
      <div class="form-row">
        <label class="label" for="pp-user">Автор (необязательно)</label>
        <input class="input" id="pp-user" name="user" value="<?= e($author) ?>" maxlength="32" placeholder="Имя пользователя">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-white btn-pill" type="submit"><?= icon('search') ?> Найти</button>
      <?php if ($searched): ?><a class="btn btn-ghost btn-pill" href="<?= e(url('/forum/profile-posts.php', ['search' => 1])) ?>"><?= icon('x') ?> Сбросить</a><?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="flash flash-error"><?= icon('alert') ?><div><?= e($error) ?></div></div>
<?php elseif ($searchMode && !$searched): ?>
<div class="card empty"><?= icon('search') ?><div>Введите текст или имя автора, чтобы найти сообщения на стенах профилей.</div></div>
<?php elseif ($posts): ?>
<?php if ($searched): ?><div class="muted small mb-2">Найдено: <?= num($p['total']) ?></div><?php endif; ?>
<div class="acc-wall-list card"><?= acc_wall_render($posts, ['show_owner' => true, 'return' => $return]) ?></div>
<?= $p['pages'] > 1 ? '<div class="acc-pager">' . pagination_html($p, '/forum/profile-posts.php', $listParams) . '</div>' : '' ?>
<?php else: ?>
<div class="card empty"><?= icon('chat') ?><div><?= $searched ? 'Ничего не найдено.' : 'Сообщений в профилях пока нет.' ?></div></div>
<?php endif; ?>
<?php
forum_footer();
