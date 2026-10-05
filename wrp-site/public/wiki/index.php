<?php
require __DIR__ . '/../../app/bootstrap.php';

$q = mb_substr(query_str('q'), 0, 100);
$words = $q !== '' ? wiki_query_words($q) : [];
$results = $words ? wiki_search($q) : [];
$cats = wiki_categories();
$popular = $q === '' ? wiki_top_articles('views', 5) : [];
$fresh = $q === '' ? wiki_top_articles('new', 5) : [];
$tech = site_setting_node('tech_node_id');
$askUrl = $tech ? node_url($tech) : url('/forum/');

$crumbs = [['База знаний', url('/wiki/')]];
if ($q !== '') {
    $crumbs[] = ['Поиск', url('/wiki/', ['q' => $q])];
}

forum_header([
    'title' => $q !== '' ? 'Поиск: ' . $q . ' | База знаний' : 'База знаний',
    'h1_html' => e('База знаний'),
    'crumbs' => $crumbs,
    'nav' => 'wiki',
    'css' => ['wiki.css'],
    'right' => false,
    'description' => 'База знаний ' . setting('site_name') . ': как начать играть, дома, транспорт, банк, организации и команды сервера.',
    'title_actions' => can_admin() ? '<a class="btn btn-ghost btn-sm" href="' . e(url('/admin/wiki.php')) . '">' . icon('settings') . ' Управление</a>' : '',
]);
?>
<section class="wiki-hero">
  <div class="wiki-hero-icon"><?= icon('help') ?></div>
  <h2>Чем помочь?</h2>
  <p>Гайды для новичков, ответы на частые вопросы и полезные команды сервера.</p>
  <form class="wiki-search" method="get" action="<?= e(url('/wiki/')) ?>" role="search">
    <?= icon('search') ?>
    <label class="sr-only" for="wiki-q">Поиск по базе знаний</label>
    <input id="wiki-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Например: как купить дом" maxlength="100" autocomplete="off">
    <button class="btn btn-accent btn-pill" type="submit">Найти</button>
  </form>
  <?php if ($popular): ?>
  <div class="wiki-tags">
    <span>Часто ищут:</span>
    <?php foreach (array_slice($popular, 0, 4) as $a): ?>
    <a href="<?= e(wiki_article_url($a)) ?>"><?= e($a['title']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php if ($q !== ''): ?>
<section class="wiki-block">
  <div class="wiki-block-head">
    <h2>Результаты поиска</h2>
    <?php if ($words): ?><span class="muted"><?= $results ? 'Найдено: ' . num(count($results)) . ' ' . e(wiki_articles_word(count($results))) : 'Ничего не найдено' ?> по запросу «<?= e($q) ?>»</span><?php endif; ?>
  </div>
  <?php if (!$words): ?>
  <div class="card empty"><?= icon('search') ?><div>Введите не меньше двух символов.</div></div>
  <?php elseif (!$results): ?>
  <div class="card empty wiki-empty">
    <?= icon('search') ?>
    <div>По запросу «<?= e($q) ?>» ничего не нашлось. Попробуйте другие слова или загляните в разделы ниже.</div>
    <a class="btn btn-white btn-pill" href="<?= e($askUrl) ?>"><?= icon('chat') ?> Задать вопрос на форуме</a>
  </div>
  <?php else: ?>
  <div class="wiki-results">
    <?php foreach ($results as $r): $snippet = wiki_snippet(site_plain($r['body']), $words); ?>
    <a class="wiki-result" href="<?= e(wiki_article_url($r)) ?>">
      <span class="wiki-result-icon"><?= icon($r['cat_icon']) ?></span>
      <div>
        <h3><?= wiki_highlight($r['title'], $words) ?></h3>
        <div class="wiki-result-meta"><?= e($r['cat_title']) ?> · обновлено <?= e(fday($r['updated_at'])) ?></div>
        <p><?= wiki_highlight($snippet !== '' ? $snippet : (string)$r['summary'], $words) ?></p>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="wiki-block">
  <div class="wiki-block-head"><h2>Разделы</h2></div>
  <?php if ($cats): ?>
  <div class="wiki-cats">
    <?php foreach ($cats as $c): $n = (int)$c['articles']; ?>
    <a class="wiki-cat" href="<?= e(wiki_category_url($c)) ?>">
      <span class="wiki-cat-icon"><?= icon($c['icon']) ?></span>
      <span class="wiki-cat-title"><?= e($c['title']) ?></span>
      <?php if ($c['description']): ?><span class="wiki-cat-desc"><?= e($c['description']) ?></span><?php endif; ?>
      <span class="wiki-cat-count"><?= $n ? num($n) . ' ' . e(wiki_articles_word($n)) : 'Пока нет статей' ?><?= icon('arrow-right') ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card empty wiki-empty">
    <?= icon('book') ?>
    <div>База знаний пока пуста.</div>
    <?php if (can_admin()): ?><a class="btn btn-white btn-pill" href="<?= e(url('/admin/wiki.php', ['new_category' => 1])) ?>"><?= icon('plus') ?> Добавить раздел</a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php if ($popular || $fresh): ?>
<section class="wiki-cols">
  <div class="card wiki-panel">
    <h2 class="wiki-panel-title"><?= icon('star') ?> Популярные статьи</h2>
    <ol class="wiki-list">
      <?php foreach ($popular as $i => $a): ?>
      <li><a href="<?= e(wiki_article_url($a)) ?>">
        <span class="wiki-list-num"><?= $i + 1 ?></span>
        <span class="wiki-list-text"><span class="wiki-list-title"><?= e($a['title']) ?></span><span class="wiki-list-meta"><?= e($a['cat_title']) ?> · <?= num($a['views']) ?> <?= e(plural($a['views'], 'просмотр', 'просмотра', 'просмотров')) ?></span></span>
      </a></li>
      <?php endforeach; ?>
    </ol>
  </div>
  <div class="card wiki-panel">
    <h2 class="wiki-panel-title"><?= icon('clock') ?> Новые статьи</h2>
    <ol class="wiki-list">
      <?php foreach ($fresh as $a): ?>
      <li><a href="<?= e(wiki_article_url($a)) ?>">
        <span class="wiki-list-num"><?= icon($a['cat_icon']) ?></span>
        <span class="wiki-list-text"><span class="wiki-list-title"><?= e($a['title']) ?></span><span class="wiki-list-meta"><?= e($a['cat_title']) ?> · <?= e(fday($a['created_at'])) ?></span></span>
      </a></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>
<?php
forum_footer();
