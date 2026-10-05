<?php
// До установки ведём на установщик относительной ссылкой (так работает и в подпапке)
if (!is_file(__DIR__ . '/../config/config.php')) {
    header('Location: install/');
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$siteName = (string)setting('site_name');
$subtitle = (string)setting('site_subtitle');
$media = site_hero_media();
$videoId = site_youtube_id(setting('video_url'));
$srv = site_server_info();
$news = site_news(3);
$socials = social_links();
$stats = forum_stats();
$months = ru_months_short();

// Только то, что уже работает на сервере (без обещаний)
$features = [
    ['home', 'Дома и особняки', 'Более 1300 домов - от небольших квартир до особняков класса S. Мебель, сейф, склад и подвал, комнаты в аренду и аукцион домов.'],
    ['car', 'Автосалон WORLD MOTORS', 'Салон в Вайнвуде: машины на витрине, бесплатный тест-драйв и покупка. До трёх личных машин, эвакуатор и продажа другим игрокам.'],
    ['building', 'World Bank', 'Личный счёт у каждого персонажа. Банкоматы у мэрии, Департамента полиции и в аэропорту, переводы по нику и зарплата на счёт в PayDay.'],
    ['shield', 'Организации', 'Правительство - 12 рангов, Департамент полиции - 14 рангов. Зарплата растёт вместе с рангом, на службе - служебный транспорт.'],
    ['map', 'Прилёт в Лос-Сантос', 'Новые игроки прилетают в аэропорт LSIA, выбирают внешность и получают первые подсказки в Центре адаптации.'],
    ['monitor', 'Свой лаунчер и интерфейс', 'Лаунчер ставит всё в один клик и сам проверяет файлы. В игре - собственные окна интерфейса и спидометр World RP.'],
];
$launcher = trim((string)setting('launcher_url'));

site_header(['active' => 'home', 'body_class' => 'page-home']);
?>
<section class="hero<?= ($media['videos'] || $media['custom_image']) ? ' has-media' : '' ?>" aria-label="<?= e($siteName) ?>">
  <div class="hero-media" aria-hidden="true">
    <?php if ($media['videos']): ?>
    <video autoplay muted loop playsinline preload="auto" poster="<?= e($media['image']) ?>">
      <?php foreach ($media['videos'] as $v): ?>
      <source src="<?= e($v['src']) ?>" type="<?= e($v['type']) ?>">
      <?php endforeach; ?>
    </video>
    <?php else: ?>
    <img src="<?= e($media['image']) ?>" alt="" fetchpriority="high">
    <?php endif; ?>
  </div>

  <div class="hero-inner">
    <h1 class="hero-title hero-in">
      <span><?= e(mb_strtoupper($siteName)) ?></span>
      <?php if ($subtitle !== ''): ?><span class="hero-title-accent"><?= e(mb_strtoupper($subtitle)) ?></span><?php endif; ?>
    </h1>
    <p class="hero-lead hero-in hero-in-2"><?= e(setting('site_description')) ?></p>
    <div class="hero-actions hero-in hero-in-3">
      <a class="site-btn site-btn-accent site-btn-lg" href="<?= e(url('/start.php')) ?>">Начать игру</a>
      <?php if ($videoId !== ''): ?>
      <a class="site-btn site-btn-white site-btn-lg" href="https://www.youtube.com/watch?v=<?= e($videoId) ?>" data-video="<?= e($videoId) ?>" target="_blank" rel="noopener">
        Видео об игре
        <span class="play-dot" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 3.5v17L21 12z"/></svg></span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <a class="hero-scroll hero-in hero-in-4" href="#server" aria-label="Листайте вниз">
    <span class="hero-mouse" aria-hidden="true"></span>
    <span>Листай вниз</span>
  </a>
  <?php if ($media['videos']): ?>
  <button class="hero-video-toggle" type="button" data-hero-video-toggle aria-label="Остановить фоновое видео"></button>
  <?php endif; ?>
</section>

<div class="site-container" id="server">
  <section class="server-strip hero-in hero-in-4" aria-label="Статус игрового сервера">
    <div class="server-cell">
      <span class="server-cell-label">Сервер</span>
      <div class="server-status">
        <span class="big-dot <?= e($srv['class']) ?>" aria-hidden="true"></span>
        <div class="server-status-text">
          <b><?= e($srv['label']) ?></b>
          <small title="<?= e($srv['name']) ?>"><?= e($srv['name']) ?></small>
        </div>
      </div>
    </div>
    <div class="server-cell">
      <span class="server-cell-label">Игроки</span>
      <div class="server-players">
        <?php if ($srv['online']): ?>
        <b><?= num($srv['players']) ?></b><span>/ <?= num($srv['max']) ?></span>
        <?php else: ?>
        <b>-</b><span>сейчас не в сети</span>
        <?php endif; ?>
      </div>
      <div class="meter" role="meter" aria-label="Заполненность сервера" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$srv['percent'] ?>"><span style="width:<?= (int)$srv['percent'] ?>%"></span></div>
    </div>
    <div class="server-cell">
      <span class="server-cell-label">Адрес сервера</span>
      <div class="server-address">
        <code><?= e($srv['address']) ?></code>
        <button class="copy-btn" type="button" data-copy="<?= e($srv['address']) ?>" aria-label="Скопировать адрес сервера" title="Скопировать"><?= icon('copy') ?></button>
      </div>
    </div>
    <div class="server-cell server-connect">
      <a class="site-btn site-btn-accent" href="<?= e(url('/start.php')) ?>"><?= icon('gamepad') ?> Играть</a>
    </div>
  </section>
</div>

<section class="site-section" id="features">
  <div class="section-bg" aria-hidden="true"><div class="glow-orb glow-orb-1"></div></div>
  <div class="site-container">
    <div class="section-head reveal">
      <span class="eyebrow">Возможности</span>
      <h2 class="section-title">Почему <span class="grad-text"><?= e($siteName) ?></span></h2>
      <p class="section-lead">Живой город, где каждый игрок - часть общей истории. Всё, что описано ниже, уже работает на сервере, а город растёт с каждым обновлением.</p>
    </div>
    <div class="features">
      <?php foreach ($features as $i => $f): ?>
      <article class="feature reveal" style="--d:<?= ($i % 3) * 90 ?>ms">
        <div class="feature-icon"><?= icon($f[0]) ?></div>
        <h3><?= e($f[1]) ?></h3>
        <p><?= e($f[2]) ?></p>
        <span class="feature-mark" aria-hidden="true"><?= icon($f[0]) ?></span>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="site-section" id="how">
  <div class="section-bg" aria-hidden="true"><div class="glow-orb glow-orb-2"></div></div>
  <div class="site-container">
    <div class="section-head reveal">
      <span class="eyebrow">Три шага</span>
      <h2 class="section-title">Как начать играть</h2>
      <p class="section-lead">Играть можно через собственный лаунчер World RP: он установит всё нужное и подключит к серверу. Подробная инструкция и ответы на частые вопросы - на странице «Начать игру».</p>
    </div>
    <div class="steps">
      <article class="step reveal">
        <div class="step-head"><span class="step-num">01</span><span class="step-icon"><?= icon('monitor') ?></span></div>
        <h3>Подготовь GTA San Andreas</h3>
        <p>Нужна GTA San Andreas версии 1.0 (US) на компьютере с Windows. Файлы самой игры лаунчер не распространяет.</p>
      </article>
      <article class="step reveal" style="--d:90ms">
        <div class="step-head"><span class="step-num">02</span><span class="step-icon"><?= icon('download') ?></span></div>
        <h3>Скачай лаунчер World RP<?php if ($launcher === ''): ?> <span class="soon-tag">Скоро</span><?php endif; ?></h3>
        <p>Он сам поставит клиент, интерфейс World RP и файлы сервера, проверит их и будет обновлять. Руками ничего ставить не нужно.</p>
      </article>
      <article class="step reveal" style="--d:180ms">
        <div class="step-head"><span class="step-num">03</span><span class="step-icon"><?= icon('gamepad') ?></span></div>
        <h3>Создай аккаунт и играй</h3>
        <p>Зарегистрируйся прямо в лаунчере, ник - в формате <code>Имя_Фамилия</code>. Нажми «Играть», и ты в аэропорту Лос-Сантоса.</p>
      </article>
    </div>
    <div class="section-cta reveal">
      <a class="site-btn site-btn-accent site-btn-lg" href="<?= e(url('/start.php')) ?>">Подробная инструкция <?= icon('arrow-right') ?></a>
      <a class="site-btn site-btn-glass site-btn-lg" href="<?= e(url('/wiki/')) ?>"><?= icon('book') ?> База знаний</a>
    </div>
  </div>
</section>

<?php if ($news['items']): ?>
<section class="site-section" id="news">
  <div class="site-container">
    <div class="section-head section-head-row reveal">
      <div>
        <span class="eyebrow">Новости</span>
        <h2 class="section-title">Что нового в городе</h2>
      </div>
      <a class="link-arrow" href="<?= e(node_url($news['node'])) ?>">Все новости <?= icon('arrow-right') ?></a>
    </div>
    <div class="news-grid news-count-<?= count($news['items']) ?>">
      <?php foreach ($news['items'] as $i => $n): $ts = strtotime($n['created_at']); ?>
      <article class="news-card reveal" style="--d:<?= $i * 90 ?>ms">
        <a class="news-cover" href="<?= e(thread_url($n['id'])) ?>" tabindex="-1" aria-hidden="true">
          <?php if ($n['image'] !== ''): ?>
          <img src="<?= e($n['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
          <?php else: ?>
          <span class="news-cover-icon"><?= icon('megaphone') ?></span>
          <span class="news-cover-date"><b><?= date('d', $ts) ?></b><span><?= e($months[(int)date('n', $ts)]) ?> <?= date('Y', $ts) ?></span></span>
          <?php endif; ?>
        </a>
        <div class="news-body">
          <div class="news-meta"><span><?= icon('calendar') ?> <?= e(fdate($n['created_at'])) ?></span><span><?= icon('chat') ?> <?= num($n['reply_count']) ?></span></div>
          <h3 class="news-title"><?= prefix_html($n['prefix_id']) ?><a href="<?= e(thread_url($n['id'])) ?>"><?= e($n['title']) ?></a></h3>
          <?php if ($n['snippet'] !== ''): ?><p class="news-text"><?= e($n['snippet']) ?></p><?php endif; ?>
          <div class="news-more"><a class="link-arrow" href="<?= e(thread_url($n['id'])) ?>">Читать <?= icon('arrow-right') ?></a></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="site-section" id="community">
  <div class="site-container">
    <div class="community reveal">
      <div>
        <span class="eyebrow">Сообщество</span>
        <h2 class="section-title">Присоединяйся к жителям Los Santos</h2>
        <p class="section-lead">На форуме - новости сервера, жалобы, заявления в организации и общение игроков. В соцсетях - анонсы обновлений и живое общение.</p>
        <div class="community-actions">
          <a class="site-btn site-btn-white site-btn-pill" href="<?= e(url('/forum/')) ?>"><?= icon('chats') ?> Перейти на форум</a>
          <?php foreach ($socials as $s): ?>
          <a class="site-btn site-btn-glass site-btn-pill" href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= icon($s['icon']) ?> <?= e($s['title']) ?></a>
          <?php endforeach; ?>
          <?php if (!$socials): ?>
          <a class="site-btn site-btn-glass site-btn-pill" href="<?= e(url('/wiki/')) ?>"><?= icon('book') ?> База знаний</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="community-stats">
        <div class="stat-tile"><b><?= num($stats['users']) ?></b><span><?= e(plural($stats['users'], 'участник', 'участника', 'участников')) ?> форума</span></div>
        <div class="stat-tile"><b><?= num($stats['threads']) ?></b><span><?= e(plural($stats['threads'], 'тема', 'темы', 'тем')) ?> на форуме</span></div>
        <div class="stat-tile"><b><?= num($stats['posts']) ?></b><span><?= e(plural($stats['posts'], 'сообщение', 'сообщения', 'сообщений')) ?></span></div>
        <div class="stat-tile"><b><?= $srv['online'] ? num($srv['players']) : '-' ?></b><span>игроков на сервере</span></div>
      </div>
    </div>
  </div>
</section>
<?php
site_footer();
