<?php
// Поиск по темам и сообщениям
require __DIR__ . '/../../app/bootstrap.php';

$q = mb_substr(fp_clean_title(query_str('q')), 0, 100);
$in = query_str('in') === 'titles' ? 'titles' : 'all';
$nodeId = query_int('node');
$authorName = mb_substr(query_str('author') !== '' ? query_str('author') : query_str('user'), 0, 32);
$prefix = query_int('prefix');
$allPrefixes = prefixes_all();
if ($prefix && !isset($allPrefixes[$prefix])) {
    $prefix = 0;
}

$searched = $q !== '' || $authorName !== '';
$error = '';
$results = [];
$pg = paginate(0, 20, 1);
$params = ['q' => $q !== '' ? $q : null, 'in' => $in !== 'all' ? $in : null, 'node' => $nodeId ?: null, 'author' => $authorName !== '' ? $authorName : null, 'prefix' => $prefix ?: null];

if ($searched) {
    if ($q !== '' && mb_strlen($q) < 3) {
        $error = 'Введите не меньше 3 символов для поиска.';
    }
    $author = null;
    if (!$error && $authorName !== '') {
        $author = user_by_name($authorName);
        if (!$author) {
            $error = 'Пользователь «' . $authorName . '» не найден.';
        }
    }
    // Разделы: выбранный с подразделами или все доступные
    $nodeIds = fp_viewable_node_ids();
    if (!$error && $nodeId) {
        $sel = node_get($nodeId);
        if (!$sel || !node_can_view($sel)) {
            $error = 'Раздел не найден.';
        } else {
            $nodeIds = array_values(array_intersect($nodeIds, node_subtree_ids($nodeId)));
        }
    }
    if (!$error) {
        $nin = db_in($nodeIds ?: [0], 'n');
        $bind = $nin['params'];
        if ($in === 'titles') {
            $where = ['t.is_deleted = 0', 't.node_id IN (' . $nin['sql'] . ')'];
            if ($q !== '') {
                $where[] = "t.title LIKE :q1 ESCAPE '!'";
                $bind['q1'] = fp_like($q);
            }
            if ($author) {
                $where[] = 't.user_id = :au';
                $bind['au'] = (int)$author['id'];
            }
            if ($prefix) {
                $where[] = 't.prefix_id = :pr';
                $bind['pr'] = $prefix;
            }
            $w = implode(' AND ', $where);
            $total = (int)db_val('SELECT COUNT(*) FROM threads t WHERE ' . $w, $bind);
            $pg = paginate($total, 20, query_int('page', 1));
            $results = db_all('SELECT t.id AS thread_id, t.title, t.prefix_id, t.node_id, t.reply_count, t.first_post_id AS id, t.first_post_id,
                                      t.created_at, t.user_id, fp.body, u.username, u.avatar, g.color AS group_color
                               FROM threads t
                               LEFT JOIN posts fp ON fp.id = t.first_post_id
                               LEFT JOIN users u ON u.id = t.user_id
                               LEFT JOIN user_groups g ON g.id = u.group_id
                               WHERE ' . $w . ' ORDER BY t.created_at DESC, t.id DESC
                               LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $bind);
        } else {
            $where = ['p.is_deleted = 0', 't.is_deleted = 0', 't.node_id IN (' . $nin['sql'] . ')'];
            if ($q !== '') {
                $where[] = "(p.body LIKE :q1 ESCAPE '!' OR (p.id = t.first_post_id AND t.title LIKE :q2 ESCAPE '!'))";
                $bind['q1'] = fp_like($q);
                $bind['q2'] = fp_like($q);
            }
            if ($author) {
                $where[] = 'p.user_id = :au';
                $bind['au'] = (int)$author['id'];
            }
            if ($prefix) {
                $where[] = 't.prefix_id = :pr';
                $bind['pr'] = $prefix;
            }
            $w = implode(' AND ', $where);
            $total = (int)db_val('SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE ' . $w, $bind);
            $pg = paginate($total, 20, query_int('page', 1));
            $results = db_all('SELECT p.id, p.body, p.created_at, p.user_id, p.thread_id, t.title, t.prefix_id, t.node_id, t.reply_count, t.first_post_id,
                                      u.username, u.avatar, g.color AS group_color
                               FROM posts p
                               JOIN threads t ON t.id = p.thread_id
                               LEFT JOIN users u ON u.id = p.user_id
                               LEFT JOIN user_groups g ON g.id = u.group_id
                               WHERE ' . $w . ' ORDER BY p.id DESC
                               LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $bind);
        }
    }
}

forum_header([
    'title' => $q !== '' && !$error ? 'Поиск: ' . $q : 'Поиск',
    'crumbs' => [['Форумы', url('/forum/')], ['Поиск', url('/forum/search.php')]],
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'js' => ['thread.js'],
]);
?>
<form method="get" action="<?= e(url('/forum/search.php')) ?>" class="card search-card">
  <div class="search-main">
    <span class="search-ico"><?= icon('search') ?></span>
    <input class="input search-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Что ищем? Минимум 3 символа" minlength="3" maxlength="100"<?= $searched ? '' : ' autofocus' ?>>
    <button class="btn btn-accent btn-pill" type="submit"><?= icon('search') ?> Найти</button>
  </div>
  <div class="search-opts">
    <div class="form-row">
      <label class="label" for="s-in">Искать в</label>
      <select class="select" id="s-in" name="in">
        <option value="all"<?= $in === 'all' ? ' selected' : '' ?>>Темы и сообщения</option>
        <option value="titles"<?= $in === 'titles' ? ' selected' : '' ?>>Только заголовки</option>
      </select>
    </div>
    <div class="form-row">
      <label class="label" for="s-node">Раздел</label>
      <select class="select" id="s-node" name="node">
        <option value="">Все разделы</option>
        <?= fp_node_options($nodeId, false) ?>
      </select>
    </div>
    <div class="form-row">
      <label class="label" for="s-author">Автор</label>
      <input class="input" id="s-author" name="author" value="<?= e($authorName) ?>" placeholder="Ник на форуме" maxlength="32">
    </div>
    <div class="form-row">
      <label class="label" for="s-prefix">Префикс</label>
      <select class="select" id="s-prefix" name="prefix">
        <option value="">Любой</option>
        <?php foreach ($allPrefixes as $id => $pr): ?>
        <option value="<?= (int)$id ?>"<?= $prefix === (int)$id ? ' selected' : '' ?>><?= e($pr['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<?php if ($error): ?>
<div class="flash flash-error"><?= icon('alert') ?><div><?= e($error) ?></div></div>
<?php elseif ($searched): ?>
<div class="page-actions forum-actions">
  <?= fp_pagination($pg, '/forum/search.php', $params) ?>
  <span class="muted small search-count">Найдено: <?= num($pg['total']) ?></span>
</div>
<section class="block search-results">
  <?php if ($results): ?>
  <?php foreach ($results as $r):
      $ru = fp_list_user($r['user_id'], $r['username'], $r['avatar'], $r['group_color']);
      $n = node_get($r['node_id']);
      $isThread = (int)$r['id'] === (int)$r['first_post_id'];
      $link = $in === 'titles' ? thread_url($r['thread_id']) : post_url($r['id']);
  ?>
  <article class="sresult">
    <div class="sresult-ava"><?= fp_avatar_link($ru, 'm') ?></div>
    <div class="sresult-main">
      <h3 class="sresult-title"><?= prefix_html($r['prefix_id']) ?><a href="<?= e($link) ?>"><?= fp_highlight($r['title'], $q) ?></a></h3>
      <div class="sresult-snippet"><?= fp_snippet((string)$r['body'], $q) ?></div>
      <div class="sresult-meta">
        <?= fp_user_link($ru) ?>
        <span class="trow-dot">·</span><span><?= $isThread ? 'Тема' : 'Ответ в теме' ?></span>
        <span class="trow-dot">·</span><span><?= e(fdate($r['created_at'])) ?></span>
        <?php if ($n): ?><span class="trow-dot">·</span><span>Раздел: <a href="<?= e(node_url($n)) ?>"><?= e($n['title']) ?></a></span><?php endif; ?>
        <span class="trow-dot">·</span><span>Ответы: <?= num($r['reply_count']) ?></span>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
  <?php else: ?>
  <div class="empty"><?= icon('search') ?><div>Ничего не найдено. Попробуйте изменить запрос или условия поиска.</div></div>
  <?php endif; ?>
</section>
<?php if ($pg['pages'] > 1): ?>
<div class="page-actions forum-actions"><?= fp_pagination($pg, '/forum/search.php', $params) ?></div>
<?php endif; ?>
<?php else: ?>
<div class="card empty search-hint"><?= icon('search') ?><div>Введите слово или фразу. Можно искать только по автору: оставьте запрос пустым и укажите ник.</div></div>
<?php endif; ?>
<?php
forum_footer();
