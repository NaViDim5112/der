<?php
require __DIR__ . '/../../app/bootstrap.php';

$a = wiki_article_by_slug(query_str('slug'));
$isAdmin = can_admin();
if (!$a || (!$a['is_published'] && !$isAdmin)) {
    abort(404, 'Статья не найдена или ещё не опубликована.');
}
$id = (int)$a['id'];

// Просмотр считается один раз за сессию
if ($a['is_published']) {
    $seen = $_SESSION['wiki_seen'] ?? [];
    if (!is_array($seen) || count($seen) > 300) {
        $seen = [];
    }
    if (empty($seen[$id])) {
        db_exec('UPDATE wiki_articles SET views = views + 1 WHERE id = :id', ['id' => $id]);
        $a['views'] = (int)$a['views'] + 1;
        $seen[$id] = 1;
        $_SESSION['wiki_seen'] = $seen;
    }
}

$siblings = wiki_category_articles($a['category_id'], $isAdmin);
$prev = null;
$next = null;
foreach ($siblings as $i => $s) {
    if ((int)$s['id'] === $id) {
        $prev = $siblings[$i - 1] ?? null;
        $next = $siblings[$i + 1] ?? null;
        break;
    }
}
$cat = ['id' => $a['category_id'], 'slug' => $a['cat_slug'], 'title' => $a['cat_title']];
$tech = site_setting_node('tech_node_id');
$askUrl = $tech ? node_url($tech) : url('/forum/');

$h1 = e($a['title']) . (!$a['is_published'] ? '<span class="wiki-draft">Черновик</span>' : '');
$meta = '<a href="' . e(wiki_category_url($cat)) . '">' . icon($a['cat_icon']) . e($a['cat_title']) . '</a>'
    . '<span>' . icon('clock') . 'Обновлено ' . e(fday($a['updated_at'])) . '</span>'
    . '<span>' . icon('eye') . num($a['views']) . ' ' . e(plural($a['views'], 'просмотр', 'просмотра', 'просмотров')) . '</span>';
$actions = $isAdmin ? '<a class="btn btn-ghost btn-sm" href="' . e(url('/admin/wiki.php', ['article' => $id])) . '">' . icon('edit') . ' Редактировать</a>' : '';

forum_header([
    'title' => $a['title'] . ' | База знаний',
    'h1_html' => $h1,
    'meta_html' => $meta,
    'crumbs' => [['База знаний', url('/wiki/')], [$a['cat_title'], wiki_category_url($cat)], [$a['title'], wiki_article_url($a)]],
    'nav' => 'wiki',
    'css' => ['wiki.css'],
    'right' => false,
    'description' => $a['summary'] ?: str_limit(site_plain($a['body']), 160),
    'title_actions' => $actions,
]);
?>
<div class="wiki-article-layout">
  <div>
    <article class="card wiki-article">
      <?php if ($a['summary']): ?><p class="wiki-lead"><?= e($a['summary']) ?></p><?php endif; ?>
      <div class="bb wiki-body"><?= bbcode($a['body']) ?></div>
      <div class="wiki-article-foot">
        <span><?= icon('calendar') ?> Опубликовано <?= e(fday($a['created_at'])) ?></span>
        <span><?= icon('refresh') ?> Обновлено <?= e(fdate($a['updated_at'])) ?></span>
      </div>
    </article>

    <?php if ($prev || $next): ?>
    <nav class="wiki-pager" aria-label="Соседние статьи">
      <?php if ($prev): ?>
      <a class="wiki-pager-prev" href="<?= e(wiki_article_url($prev)) ?>"><?= icon('chevron-left') ?><span class="wiki-pager-text"><small>Предыдущая статья</small><b><?= e($prev['title']) ?></b></span></a>
      <?php endif; ?>
      <?php if ($next): ?>
      <a class="wiki-pager-next" href="<?= e(wiki_article_url($next)) ?>"><span class="wiki-pager-text"><small>Следующая статья</small><b><?= e($next['title']) ?></b></span><?= icon('chevron-right') ?></a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>

  <aside class="wiki-side">
    <div class="card wiki-side-card">
      <h2 class="wiki-side-title"><?= icon($a['cat_icon']) ?> <?= e($a['cat_title']) ?></h2>
      <nav class="wiki-side-list" aria-label="Статьи раздела">
        <?php foreach ($siblings as $s): ?>
        <a class="<?= (int)$s['id'] === $id ? 'active' : '' ?>" href="<?= e(wiki_article_url($s)) ?>"<?= (int)$s['id'] === $id ? ' aria-current="page"' : '' ?>><?= e($s['title']) ?><?php if (!$s['is_published']): ?><span class="wiki-draft">Черновик</span><?php endif; ?></a>
        <?php endforeach; ?>
      </nav>
      <a class="wiki-side-all" href="<?= e(wiki_category_url($cat)) ?>">Все статьи раздела <?= icon('arrow-right') ?></a>
    </div>
    <div class="card wiki-help">
      <div class="wiki-hero-icon"><?= icon('chats') ?></div>
      <b>Не нашли ответ?</b>
      <p>Задайте вопрос на форуме - игроки и администрация помогут разобраться.</p>
      <a class="btn btn-accent btn-pill btn-block" href="<?= e($askUrl) ?>"><?= icon('chat') ?> Задать вопрос</a>
    </div>
  </aside>
</div>
<?php
forum_footer();
