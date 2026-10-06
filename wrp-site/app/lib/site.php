<?php
// Публичный сайт (лендинг, «Начать игру», «Сотрудничество») и функции базы знаний.
//
//   site_header(['title' => 'Начать игру', 'active' => 'start']);
//   ... HTML страницы ...
//   site_footer();
//
// Файл подключается автоматически вместе с остальными app/lib/*.php,
// поэтому здесь только функции - никакого кода, который выполняется при подключении.

// ---------- Оболочка публичных страниц ----------

function site_nav_items()
{
    return [
        'home' => ['Главная', url('/')],
        'forum' => ['Форум', url('/forum/')],
        'wiki' => ['База знаний', url('/wiki/')],
        'partners' => ['Сотрудничество', url('/partners.php')],
    ];
}

// Ссылка «Кабинет»: вошедшим сразу в кабинет, гостям - вход с возвратом в кабинет
function site_cabinet_url()
{
    return is_logged() ? url('/cabinet/') : url('/forum/login.php', ['return' => url('/cabinet/')]);
}

// samp://ip:port - открывает клиент SA-MP / open.mp, если он установлен
function site_samp_url()
{
    return 'samp://' . server_address();
}

// Медиа для первого экрана: видео (hero.mp4 / hero.webm), картинка (hero.jpg / .webp / .png) или hero.svg
function site_hero_media()
{
    static $media = null;
    if ($media !== null) {
        return $media;
    }
    $dir = WRP_PUBLIC . '/assets/media/';
    $image = '';
    foreach (['jpg', 'jpeg', 'webp', 'png'] as $ext) {
        if (is_file($dir . 'hero.' . $ext)) {
            $image = asset('media/hero.' . $ext);
            break;
        }
    }
    $videos = [];
    foreach (['webm' => 'video/webm', 'mp4' => 'video/mp4'] as $ext => $mime) {
        if (is_file($dir . 'hero.' . $ext)) {
            $videos[] = ['src' => asset('media/hero.' . $ext), 'type' => $mime];
        }
    }
    return $media = [
        'videos' => $videos,
        'image' => $image !== '' ? $image : asset('img/hero.svg'),
        'custom_image' => $image !== '',
    ];
}

// id ролика YouTube из ссылки: watch?v=, youtu.be/, shorts/, embed/, live/ или сам id
function site_youtube_id($link)
{
    $link = trim((string)$link);
    if ($link === '') {
        return '';
    }
    if (preg_match('~^[A-Za-z0-9_\-]{11}$~', $link)) {
        return $link;
    }
    if (preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/|live/|v/)|youtu\.be/)([A-Za-z0-9_\-]{11})(?![A-Za-z0-9_\-])~i', $link, $m)) {
        return $m[1];
    }
    return '';
}

// Раздел открыт гостям: уровень просмотра 0 у него и у всех родителей
function site_node_public($node)
{
    if (!$node) {
        return false;
    }
    $cur = $node;
    $guard = 0;
    while ($cur && $guard++ < 20) {
        if ((int)$cur['view_level'] > 0) {
            return false;
        }
        $cur = $cur['parent_id'] ? node_get($cur['parent_id']) : null;
    }
    return true;
}

// Раздел форума из настройки (news_node_id, rules_node_id, tech_node_id) или null
function site_setting_node($key)
{
    $id = setting_int($key);
    return $id > 0 ? node_get($id) : null;
}

// Последние новости: темы из раздела news_node_id и его подразделов (только открытых гостям)
function site_news($limit = 3)
{
    $node = site_setting_node('news_node_id');
    if (!$node || !site_node_public($node)) {
        return ['node' => null, 'items' => []];
    }
    $ids = [];
    foreach (node_subtree_ids($node['id']) as $id) {
        $n = node_get($id);
        if ($n && $n['type'] === 'forum' && site_node_public($n)) {
            $ids[] = (int)$id;
        }
    }
    if (!$ids) {
        return ['node' => $node, 'items' => []];
    }
    $in = db_in($ids, 'n');
    $rows = db_all('SELECT t.id, t.title, t.prefix_id, t.created_at, t.reply_count, t.views, p.body
        FROM threads t
        LEFT JOIN posts p ON p.id = COALESCE(t.first_post_id, (SELECT MIN(p2.id) FROM posts p2 WHERE p2.thread_id = t.id))
        WHERE t.is_deleted = 0 AND t.node_id IN (' . $in['sql'] . ')
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT :lim', array_merge($in['params'], ['lim' => (int)$limit]));
    $items = [];
    foreach ($rows as $r) {
        $body = (string)$r['body'];
        $image = '';
        if (preg_match('~\[img(?:=[0-9x]{1,9})?\](https?://[^\s\[\]"\'<>]+?)\[/img\]~i', $body, $m)) {
            $image = $m[1];
        }
        $r['snippet'] = site_plain($body, 160);
        $r['image'] = $image;
        $items[] = $r;
    }
    return ['node' => $node, 'items' => $items];
}

// Текст без BB-кодов для превью: строки и пункты списков склеиваются через точку
function site_plain($body, $limit = 0)
{
    $t = preg_replace('~\[quote[^\]]*\].*?\[/quote\]~is', ' ', (string)$body);
    $t = preg_replace('~\[(img|youtube|media|code)[^\]]*\].*?\[/\1\]~is', ' ', $t);
    $t = str_replace(['[*]', "\r"], ["\n", ''], $t);
    $out = '';
    foreach (preg_split('~\n+~', $t) as $line) {
        $line = preg_replace('~\s+([.,!?:;)])~u', '$1', bbcode_plain($line));
        if ($line === '') {
            continue;
        }
        if ($out !== '') {
            $out .= preg_match('~[.!?:;,…]$~u', $out) ? ' ' : '. ';
        }
        $out .= $line;
    }
    return $limit ? str_limit($out, $limit) : $out;
}

// Статус сервера для вывода: подписи и проценты
function site_server_info()
{
    $st = server_status();
    $max = (int)$st['max'];
    $players = (int)$st['players'];
    if ($st['online']) {
        $label = 'Сервер онлайн';
        $class = 'on';
    } elseif ($st['checked']) {
        $label = 'Сервер выключен';
        $class = 'off';
    } else {
        $label = 'Статус не проверяется';
        $class = '';
    }
    return [
        'online' => (bool)$st['online'],
        'checked' => (bool)$st['checked'],
        'label' => $label,
        'class' => $class,
        'players' => $players,
        'max' => $max,
        'percent' => $max > 0 ? (int)min(100, round($players * 100 / $max)) : 0,
        'name' => $st['hostname'] !== '' ? $st['hostname'] : (string)setting('server_name'),
        'address' => server_address(),
    ];
}

function site_header(array $o = [])
{
    $GLOBALS['wrp_site'] = $o;
    $siteName = (string)setting('site_name');
    $subtitle = (string)setting('site_subtitle');
    $title = (string)($o['title'] ?? '');
    $pageTitle = $title !== '' ? $title . ' | ' . $siteName : $siteName . ($subtitle !== '' ? ' | ' . $subtitle : '');
    $description = (string)($o['description'] ?? setting('site_description'));
    $active = (string)($o['active'] ?? '');
    $media = site_hero_media();
    $ogImage = $media['custom_image'] ? site_origin() . preg_replace('~\?.*$~', '', $media['image']) : '';
    $canonical = site_origin() . strtok(current_url(), '?');
    ?>
<!doctype html>
<html lang="ru" data-theme="<?= theme_attr() ?>" class="site-html">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="theme-color" content="#0c0a13">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(rtrim((string)cfg('base_path', ''), '/')) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($title !== '' ? $title : $siteName . ($subtitle !== '' ? ' | ' . $subtitle : '')) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:locale" content="ru_RU">
<?php if ($ogImage !== ''): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<?php foreach ($o['css'] ?? [] as $css): ?>
<link rel="stylesheet" href="<?= e(asset('css/' . $css)) ?>">
<?php endforeach; ?>
<script>document.documentElement.classList.add('js');</script>
<?= hook_html('page_head', 'site', $o) ?>
</head>
<body class="layout-site <?= e($o['body_class'] ?? '') ?>">
<a class="skip-link" href="#content">Перейти к содержимому</a>
<header class="site-nav" id="site-nav">
  <div class="site-nav-bar">
    <a class="site-brand" href="<?= e(url('/')) ?>" aria-label="<?= e($siteName) ?> - на главную">
      <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="40" height="40">
      <span class="site-brand-text"><b><?= e(mb_strtoupper($siteName)) ?></b><small><?= e(mb_strtoupper($subtitle)) ?></small></span>
    </a>
    <span class="site-nav-sep" aria-hidden="true"></span>
    <nav class="site-nav-links" aria-label="Основное меню">
      <?php foreach (site_nav_items() as $key => $item): ?>
      <a class="site-nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= e($item[1]) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($item[0]) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="site-nav-actions">
      <a class="site-btn site-btn-black site-nav-cabinet" href="<?= e(site_cabinet_url()) ?>">Кабинет <?= icon('arrow-right') ?></a>
      <a class="site-btn site-btn-accent site-nav-play<?= $active === 'start' ? ' active' : '' ?>" href="<?= e(url('/start.php')) ?>"><span>Начать игру</span> <?= icon('download') ?></a>
      <button class="site-burger" type="button" aria-label="Открыть меню" aria-expanded="false" aria-controls="site-nav-panel" data-site-burger>
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
  <div class="site-nav-panel" id="site-nav-panel" hidden>
    <nav class="site-nav-panel-links" aria-label="Мобильное меню">
      <?php foreach (site_nav_items() as $key => $item): ?>
      <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($item[1]) ?>"><?= e($item[0]) ?><?= icon('chevron-right') ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="site-nav-panel-actions">
      <a class="site-btn site-btn-black" href="<?= e(site_cabinet_url()) ?>">Кабинет <?= icon('arrow-right') ?></a>
      <a class="site-btn site-btn-accent" href="<?= e(url('/start.php')) ?>">Начать игру <?= icon('download') ?></a>
    </div>
  </div>
</header>
<?php $fl = flashes(); if ($fl): ?>
<div class="site-flashes" role="status">
  <?php foreach ($fl as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= icon($f['type'] === 'success' ? 'check' : ($f['type'] === 'error' ? 'alert' : 'info')) ?><div><?= e($f['message']) ?></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<main id="content" class="site-main">
<?php
}

function site_footer()
{
    $o = $GLOBALS['wrp_site'] ?? [];
    $siteName = (string)setting('site_name');
    $socials = social_links();
    $rules = site_setting_node('rules_node_id');
    $tech = site_setting_node('tech_node_id');
    $srv = site_server_info();
    cron_maybe_run();
    ?>
</main>
<footer class="site-footer">
  <div class="site-container">
    <div class="site-footer-grid">
      <div class="site-footer-brand">
        <a class="site-brand" href="<?= e(url('/')) ?>">
          <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="44" height="44">
          <span class="site-brand-text"><b><?= e(mb_strtoupper($siteName)) ?></b><small><?= e(mb_strtoupper((string)setting('site_subtitle'))) ?></small></span>
        </a>
        <p><?= e(setting('site_description')) ?></p>
        <?php if ($socials): ?>
        <div class="site-socials">
          <?php foreach ($socials as $s): ?>
          <a class="site-social" href="<?= e($s['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($s['title']) ?>" title="<?= e($s['title']) ?>"><?= icon($s['icon']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <nav class="site-footer-col" aria-label="Игра">
        <h3>Игра</h3>
        <a href="<?= e(url('/start.php')) ?>">Начать игру</a>
        <a href="<?= e(url('/wiki/')) ?>">База знаний</a>
        <?php if ($rules): ?><a href="<?= e(node_url($rules)) ?>">Правила</a><?php endif; ?>
        <a href="<?= e(url('/partners.php')) ?>">Сотрудничество</a>
      </nav>
      <nav class="site-footer-col" aria-label="Сообщество">
        <h3>Сообщество</h3>
        <a href="<?= e(url('/forum/')) ?>">Форум</a>
        <?php if ($tech): ?><a href="<?= e(node_url($tech)) ?>">Техподдержка</a><?php endif; ?>
        <?php foreach ($socials as $s): ?>
        <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['title']) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="site-footer-col site-footer-server">
        <h3>Сервер</h3>
        <div class="site-footer-status"><span class="status-dot <?= e($srv['class']) ?>"></span><span><?= e($srv['label']) ?><?php if ($srv['online']): ?> · <?= (int)$srv['players'] ?> / <?= (int)$srv['max'] ?><?php endif; ?></span></div>
        <button class="site-address" type="button" data-copy="<?= e($srv['address']) ?>" title="Скопировать адрес">
          <span><?= e($srv['address']) ?></span><?= icon('copy') ?>
        </button>
        <a class="site-footer-connect" href="<?= e(url('/start.php')) ?>"><?= icon('gamepad') ?> Как начать играть</a>
      </div>
    </div>
    <div class="site-footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
      <?php if (trim((string)setting('footer_text')) !== ''): ?><span><?= e(setting('footer_text')) ?></span><?php endif; ?>
    </div>
  </div>
</footer>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/site.js')) ?>"></script>
<?php foreach ($o['js'] ?? [] as $js): ?>
<script src="<?= e(asset('js/' . $js)) ?>"></script>
<?php endforeach; ?>
<?= hook_html('page_scripts', 'site', $o) ?>
</body>
</html>
<?php
}

// Заголовок внутренней страницы сайта (Начать игру, Сотрудничество): фон, надзаголовок, заголовок, текст
function site_page_hero($eyebrow, $title, $lead, $actionsHtml = '')
{
    $media = site_hero_media();
    ?>
<section class="page-hero">
  <div class="page-hero-media" aria-hidden="true"><img src="<?= e($media['image']) ?>" alt=""></div>
  <div class="page-hero-inner site-container">
    <?php if ($eyebrow !== ''): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
    <h1 class="page-hero-title"><?= e($title) ?></h1>
    <?php if ($lead !== ''): ?><p class="page-hero-lead"><?= e($lead) ?></p><?php endif; ?>
    <?php if ($actionsHtml !== ''): ?><div class="hero-actions"><?= $actionsHtml ?></div><?php endif; ?>
  </div>
</section>
<?php
}

// Блок с адресом сервера: адрес крупно, копирование и «Подключиться»
function site_address_box($big = false)
{
    $srv = site_server_info();
    $h = '<div class="address-box' . ($big ? ' address-box-big' : '') . '">';
    $h .= '<div class="address-box-main"><span class="address-box-label">Адрес сервера</span>';
    $h .= '<code class="address-box-value">' . e($srv['address']) . '</code></div>';
    $h .= '<div class="address-box-actions">';
    $h .= '<button class="site-btn site-btn-glass" type="button" data-copy="' . e($srv['address']) . '">' . icon('copy') . ' Скопировать</button>';
    $h .= '<a class="site-btn site-btn-accent" href="' . e(site_samp_url()) . '">' . icon('gamepad') . ' Подключиться</a>';
    $h .= '</div></div>';
    return $h;
}

// ---------- База знаний ----------

function wiki_category_url($c)
{
    return url('/wiki/category.php', ['slug' => $c['slug']]);
}

function wiki_article_url($a)
{
    return url('/wiki/article.php', ['slug' => $a['slug']]);
}

// Категории с числом опубликованных статей
function wiki_categories()
{
    return db_all('SELECT c.*, (SELECT COUNT(*) FROM wiki_articles a WHERE a.category_id = c.id AND a.is_published = 1) AS articles
        FROM wiki_categories c ORDER BY c.display_order, c.id');
}

function wiki_category_by_slug($slug)
{
    $slug = (string)$slug;
    if ($slug === '' || mb_strlen($slug) > 64) {
        return null;
    }
    return db_one('SELECT * FROM wiki_categories WHERE slug = :s', ['s' => $slug]);
}

function wiki_article_by_slug($slug)
{
    $slug = (string)$slug;
    if ($slug === '' || mb_strlen($slug) > 96) {
        return null;
    }
    return db_one('SELECT a.*, c.title AS cat_title, c.slug AS cat_slug, c.icon AS cat_icon
        FROM wiki_articles a JOIN wiki_categories c ON c.id = a.category_id
        WHERE a.slug = :s', ['s' => $slug]);
}

// Опубликованные статьи категории в порядке вывода
function wiki_category_articles($categoryId, $withDrafts = false)
{
    return db_all('SELECT id, category_id, title, slug, summary, is_published, views, created_at, updated_at
        FROM wiki_articles WHERE category_id = :c' . ($withDrafts ? '' : ' AND is_published = 1') . '
        ORDER BY display_order, id', ['c' => (int)$categoryId]);
}

// Список статей: популярные (views) или новые (created_at)
function wiki_top_articles($order, $limit = 5)
{
    $by = $order === 'views' ? 'a.views DESC, a.updated_at DESC' : 'a.created_at DESC, a.id DESC';
    return db_all('SELECT a.id, a.title, a.slug, a.summary, a.views, a.created_at, a.updated_at, c.title AS cat_title, c.icon AS cat_icon
        FROM wiki_articles a JOIN wiki_categories c ON c.id = a.category_id
        WHERE a.is_published = 1 ORDER BY ' . $by . ' LIMIT :lim', ['lim' => (int)$limit]);
}

// Слова запроса: от 2 символов, без повторов, не больше 5
function wiki_query_words($q)
{
    $words = [];
    foreach (preg_split('~\s+~u', mb_strtolower(trim((string)$q))) as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 2 && !in_array($w, $words, true)) {
            $words[] = mb_substr($w, 0, 40);
        }
        if (count($words) >= 5) {
            break;
        }
    }
    return $words;
}

// Значение для LIKE с экранированием % и _
function wiki_like($s)
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$s) . '%';
}

// Поиск по опубликованным статьям: каждое слово должно встретиться в заголовке, описании или тексте
function wiki_search($q, $limit = 30)
{
    $words = wiki_query_words($q);
    if (!$words) {
        return [];
    }
    $where = [];
    $params = [];
    foreach ($words as $i => $w) {
        $like = wiki_like($w);
        $where[] = '(a.title LIKE :t' . $i . ' OR a.summary LIKE :s' . $i . ' OR a.body LIKE :b' . $i . ')';
        $params['t' . $i] = $like;
        $params['s' . $i] = $like;
        $params['b' . $i] = $like;
    }
    $params['o0'] = wiki_like($words[0]);
    $params['lim'] = (int)$limit;
    return db_all('SELECT a.id, a.title, a.slug, a.summary, a.body, a.views, a.updated_at, c.title AS cat_title, c.slug AS cat_slug, c.icon AS cat_icon
        FROM wiki_articles a JOIN wiki_categories c ON c.id = a.category_id
        WHERE a.is_published = 1 AND ' . implode(' AND ', $where) . '
        ORDER BY (a.title LIKE :o0) DESC, a.views DESC, a.id DESC
        LIMIT :lim', $params);
}

// Текст с подсветкой слов: каждый кусок экранируется, совпадения оборачиваются в <mark>
function wiki_highlight($text, array $words)
{
    $text = (string)$text;
    if (!$words || $text === '') {
        return e($text);
    }
    usort($words, function ($a, $b) {
        return mb_strlen($b) - mb_strlen($a);
    });
    $quoted = array_map(function ($w) {
        return preg_quote($w, '~');
    }, $words);
    $parts = preg_split('~(' . implode('|', $quoted) . ')~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return e($text);
    }
    $out = '';
    foreach ($parts as $i => $p) {
        $out .= $i % 2 ? '<mark>' . e($p) . '</mark>' : e($p);
    }
    return $out;
}

// Отрывок текста вокруг первого найденного слова
function wiki_snippet($plain, array $words, $len = 220)
{
    $plain = (string)$plain;
    $pos = false;
    foreach ($words as $w) {
        $p = mb_stripos($plain, $w);
        if ($p !== false && ($pos === false || $p < $pos)) {
            $pos = $p;
        }
    }
    if ($pos === false || $pos < 60) {
        return str_limit($plain, $len);
    }
    $start = max(0, $pos - 70);
    $cut = mb_substr($plain, $start, $len);
    // не режем слово в начале
    $space = mb_strpos($cut, ' ');
    if ($space !== false && $space < 20) {
        $cut = mb_substr($cut, $space + 1);
    }
    return '…' . rtrim($cut) . ($start + $len < mb_strlen($plain) ? '…' : '');
}

// «статья / статьи / статей»
function wiki_articles_word($n)
{
    return plural($n, 'статья', 'статьи', 'статей');
}
