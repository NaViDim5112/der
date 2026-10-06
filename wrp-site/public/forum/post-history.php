<?php
// История правок сообщения: все версии (новые сначала) и что изменилось в каждой.
// Видят автор сообщения и команда форума. Нужна для жалоб: видно, что доказательства не меняли.
require __DIR__ . '/../../app/bootstrap.php';
require_login();
mdr_require_ready();

$post = fp_post_get(query_int('id'));
$thread = $post ? thread_get($post['thread_id']) : null;
$node = $thread ? node_get($thread['node_id']) : null;
if (!$post || !$thread || !$node) {
    abort(404, 'Сообщение не найдено или было удалено.');
}
if (!node_can_view($node)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}
if (!fp_post_visible($post, $thread, $node)) {
    abort(404, 'Сообщение не найдено или было удалено.');
}
$pid = (int)$post['id'];
$isAuthor = (int)$post['user_id'] === uid();
if (!$isAuthor && !is_staff()) {
    abort(403, 'Историю правок видят автор сообщения и команда форума.');
}

$versions = mdr_post_versions($post);
$users = mdr_users_by_ids(array_map(function ($v) {
    return (int)$v['by'];
}, $versions));
$count = count($versions);
$hasEarly = false;
foreach ($versions as $v) {
    if ($v['kind'] === 'early') {
        $hasEarly = true;
    }
}
$author = fp_author($post);

$crumbs = node_crumbs($node['id']);
$crumbs[] = [$thread['title'], thread_url($thread['id'])];
$crumbs[] = ['История правок', current_url()];
forum_header([
    'title' => 'История правок',
    'meta_html' => icon('user') . '<span>' . fp_user_link($author) . '</span>' . icon('chat') . '<a href="' . e(post_url($pid)) . '">' . e($thread['title']) . '</a>',
    'crumbs' => $crumbs,
    'right' => false,
    'nav' => 'forums',
    'css' => ['moderation.css'],
    'js' => ['moderation.js'],
]);
$kinds = ['current' => 'Текущая', 'original' => 'Исходная', 'early' => 'Самая ранняя из сохранённых', 'edit' => 'Промежуточная'];
?>
<div class="mdr-history">
  <div class="card mdr-history-top">
    <div class="mdr-history-sum">
      <?= icon('clock') ?>
      <div>
        <b><?= $count ?> <?= plural($count, 'версия', 'версии', 'версий') ?></b>
        <div class="muted small">Новые сверху. Под каждой версией - что изменилось по сравнению с предыдущей: <span class="mdr-diff-add">зелёным</span> добавленные строки, <span class="mdr-diff-del">красным</span> удалённые.</div>
      </div>
    </div>
    <a class="btn btn-black btn-pill" href="<?= e(post_url($pid)) ?>"><?= icon('chevron-left') ?> К сообщению</a>
  </div>
  <?php if ($count === 1 && !empty($post['edited_at'])): ?>
  <div class="flash flash-info"><?= icon('info') ?><div>Сообщение правили до того, как на форуме включили историю правок, поэтому старые версии не сохранились.</div></div>
  <?php elseif ($hasEarly): ?>
  <div class="flash flash-info"><?= icon('info') ?><div>Первые правки сделаны до включения истории: самая старая сохранённая версия может отличаться от исходного текста.</div></div>
  <?php endif; ?>

  <?php foreach ($versions as $i => $v):
      $num = $count - $i;
      $by = $users[(int)$v['by']] ?? mdr_user_row(0, null);
      $older = $versions[$i + 1] ?? null;
  ?>
  <article class="card mdr-version<?= $v['kind'] === 'current' ? ' is-current' : '' ?>" id="v<?= $num ?>">
    <header class="mdr-version-head">
      <div class="mdr-version-who"><?= avatar($by, 's') ?><div>
        <div><b>Версия <?= $num ?></b> <span class="tag<?= $v['kind'] === 'current' ? ' mdr-tag-active' : '' ?>"><?= e($kinds[$v['kind']]) ?></span></div>
        <div class="muted small"><?= $v['kind'] === 'original' ? 'Написал(а)' : 'Изменил(а)' ?> <?= fp_user_link($by) ?> · <?= e(date('d.m.Y H:i:s', strtotime($v['at']))) ?></div>
      </div></div>
    </header>
    <?php if ($older): ?>
    <details class="mdr-version-diff" open>
      <summary><?= icon('list') ?> Что изменилось по сравнению с версией <?= $num - 1 ?></summary>
      <?= mdr_diff_html($older['body'], $v['body']) ?>
    </details>
    <?php endif; ?>
    <details class="mdr-version-view"<?= $older ? '' : ' open' ?>>
      <summary><?= icon('eye') ?> Как выглядело сообщение</summary>
      <div class="bb mdr-version-body"><?= bbcode($v['body']) ?></div>
    </details>
    <details class="mdr-version-raw">
      <summary><?= icon('code') ?> Исходный текст с BB-кодами</summary>
      <pre class="mdr-raw"><?= e($v['body']) ?></pre>
    </details>
  </article>
  <?php endforeach; ?>
</div>
<?php
forum_footer();
