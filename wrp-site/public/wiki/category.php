<?php
require __DIR__ . '/../../app/bootstrap.php';

$cat = wiki_category_by_slug(query_str('slug'));
if (!$cat) {
    abort(404, 'Такого раздела в базе знаний нет.');
}
$isAdmin = can_admin();
$articles = wiki_category_articles($cat['id'], $isAdmin);
$published = 0;
$views = 0;
foreach ($articles as $a) {
    if ($a['is_published']) {
        $published++;
        $views += (int)$a['views'];
    }
}
$others = array_filter(wiki_categories(), function ($c) use ($cat) {
    return (int)$c['id'] !== (int)$cat['id'];
});

$actions = '';
if ($isAdmin) {
    $actions = '<a class="btn btn-ghost btn-sm" href="' . e(url('/admin/wiki.php', ['category' => (int)$cat['id']])) . '">' . icon('edit') . ' Редактировать категорию</a>'
        . '<a class="btn btn-accent btn-sm" href="' . e(url('/admin/wiki.php', ['category' => (int)$cat['id'], 'new' => 1])) . '">' . icon('plus') . ' Добавить статью</a>';
}

forum_header([
    'title' => $cat['title'] . ' | База знаний',
    'h1_html' => e($cat['title']),
    'crumbs' => [['База знаний', url('/wiki/')], [$cat['title'], wiki_category_url($cat)]],
    'nav' => 'wiki',
    'css' => ['wiki.css'],
    'right' => false,
    'description' => $cat['description'] ?: ('Раздел базы знаний ' . setting('site_name') . ': ' . $cat['title']),
    'title_actions' => $actions,
]);
?>
<div class="wiki-cat-head">
  <span class="wiki-cat-icon"><?= icon($cat['icon']) ?></span>
  <div class="wiki-cat-head-text">
    <p><?= e($cat['description'] ?: 'Статьи раздела «' . $cat['title'] . '».') ?></p>
    <div class="wiki-cat-head-stats">
      <span><?= icon('file') ?> <?= num($published) ?> <?= e(wiki_articles_word($published)) ?></span>
      <span><?= icon('eye') ?> <?= num($views) ?> <?= e(plural($views, 'просмотр', 'просмотра', 'просмотров')) ?></span>
    </div>
  </div>
</div>

<?php if ($articles): ?>
<div class="wiki-articles">
  <?php foreach ($articles as $a): ?>
  <a class="wiki-row" href="<?= e(wiki_article_url($a)) ?>">
    <span class="wiki-row-icon"><?= icon('file') ?></span>
    <span class="wiki-row-text">
      <span class="wiki-row-title"><?= e($a['title']) ?><?php if (!$a['is_published']): ?><span class="wiki-draft">Черновик</span><?php endif; ?></span>
      <?php if ($a['summary']): ?><span class="wiki-row-summary"><?= e($a['summary']) ?></span><?php endif; ?>
    </span>
    <span class="wiki-row-stats">
      <span title="Обновлено"><?= icon('clock') ?> <?= e(fday($a['updated_at'])) ?></span>
      <span title="Просмотры"><?= icon('eye') ?> <?= num($a['views']) ?></span>
    </span>
    <?= icon('chevron-right') ?>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card empty wiki-empty">
  <?= icon('book') ?>
  <div>В этом разделе пока нет статей.</div>
  <?php if ($isAdmin): ?><a class="btn btn-white btn-pill" href="<?= e(url('/admin/wiki.php', ['category' => (int)$cat['id'], 'new' => 1])) ?>"><?= icon('plus') ?> Добавить статью</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($others): ?>
<section class="wiki-block" style="margin-top:26px">
  <div class="wiki-block-head"><h2>Другие разделы</h2></div>
  <div class="wiki-chips">
    <?php foreach ($others as $c): ?>
    <a href="<?= e(wiki_category_url($c)) ?>"><?= icon($c['icon']) ?> <?= e($c['title']) ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php
forum_footer();
