<?php
require __DIR__ . '/../app/bootstrap.php';

$custom = trim((string)setting('partners_text'));
$socials = social_links();
$node = node_get(85);
$applyUrl = ($node && $node['type'] !== 'category' && node_can_view($node)) ? node_url($node) : '';

site_header([
    'title' => 'Сотрудничество',
    'active' => 'partners',
    'description' => 'Сотрудничество с ' . setting('site_name') . ' для авторов контента: YouTube, Twitch и TikTok.',
]);

$actions = '';
if ($applyUrl !== '') {
    $actions .= '<a class="site-btn site-btn-accent site-btn-lg" href="' . e($applyUrl) . '">' . icon('handshake') . ' Подать заявку</a>';
}
$actions .= '<a class="site-btn site-btn-glass site-btn-lg" href="#contacts">' . icon('mail') . ' Контакты</a>';

site_page_hero(
    'Сотрудничество',
    'Создаёшь контент? Давай работать вместе',
    'Снимаешь ролики, ведёшь стримы или делаешь короткие видео о GTA San Andreas - мы открыты к сотрудничеству с авторами любого масштаба.',
    $actions
);
?>
<?php if ($custom !== ''): ?>
<section class="content-section">
  <div class="site-container">
    <div class="partners-custom bb reveal"><?= bbcode($custom) ?></div>
  </div>
</section>
<?php else: ?>
<section class="content-section" id="offer">
  <div class="site-container">
    <div class="section-head reveal">
      <span class="eyebrow">Что можно обсудить</span>
      <h2 class="section-title">Растём вместе</h2>
      <p class="section-lead">Условия каждого сотрудничества обсуждаются отдельно. Вот с чего обычно начинаем разговор.</p>
    </div>
    <div class="offer-grid">
      <article class="feature reveal">
        <div class="feature-icon"><?= icon('megaphone') ?></div>
        <h3>Продвижение</h3>
        <p>Интересные ролики и стримы о сервере можем показать в наших соцсетях и новостях на форуме.</p>
      </article>
      <article class="feature reveal" style="--d:80ms">
        <div class="feature-icon"><?= icon('chats') ?></div>
        <h3>Связь с командой</h3>
        <p>Ответим на вопросы о сервере и подскажем, что интересного можно показать в ролике.</p>
      </article>
      <article class="feature reveal" style="--d:160ms">
        <div class="feature-icon"><?= icon('bell') ?></div>
        <h3>Новости из первых рук</h3>
        <p>Расскажем об обновлениях, как только они будут готовы к показу, чтобы обзор вышел вовремя.</p>
      </article>
      <article class="feature reveal" style="--d:240ms">
        <div class="feature-icon"><?= icon('handshake') ?></div>
        <h3>Индивидуальный подход</h3>
        <p>Формат и условия сотрудничества обсуждаем с каждым автором отдельно.</p>
      </article>
    </div>
  </div>
</section>

<section class="content-section" id="platforms">
  <div class="site-container">
    <div class="section-head reveal">
      <span class="eyebrow">Площадки</span>
      <h2 class="section-title">Кого мы ищем</h2>
      <p class="section-lead">Число подписчиков не главное: нам важны качество, регулярность и уважение к игрокам.</p>
    </div>
    <div class="platforms">
      <article class="platform reveal" style="--c:#ff1f3d">
        <div class="platform-head"><span class="platform-badge"><?= icon('youtube') ?></span><h3>YouTube</h3></div>
        <ul class="guide-list">
          <li>Ролики или стримы о GTA San Andreas и SA-MP.</li>
          <li>Регулярные выпуски, а не одно видео.</li>
          <li>Понятный звук и аккуратный монтаж.</li>
        </ul>
      </article>
      <article class="platform reveal" style="--c:#9146ff;--d:90ms">
        <div class="platform-head"><span class="platform-badge"><?= icon('video') ?></span><h3>Twitch</h3></div>
        <ul class="guide-list">
          <li>Стримы по SA-MP или другим частям GTA.</li>
          <li>Стабильное расписание трансляций.</li>
          <li>Живой чат и адекватная модерация.</li>
        </ul>
      </article>
      <article class="platform reveal" style="--c:linear-gradient(135deg, #1fd1d8, #fe2c55);--d:180ms">
        <div class="platform-head"><span class="platform-badge"><?= icon('play') ?></span><h3>TikTok и Shorts</h3></div>
        <ul class="guide-list">
          <li>Короткие ролики об игре на сервере.</li>
          <li>Регулярные публикации.</li>
          <li>Свои идеи и оригинальная подача.</li>
        </ul>
      </article>
    </div>
  </div>
</section>

<section class="content-section" id="apply">
  <div class="site-container">
    <div class="connect-card reveal">
      <div class="two-cols apply-cols">
        <div>
          <h2>Как подать заявку</h2>
          <ol class="apply-steps">
            <li>Создай тему в разделе <b>«Сотрудничество»</b> на форуме.</li>
            <li>Укажи ссылки на каналы, примерную статистику и свой игровой ник.</li>
            <li>Администрация рассмотрит заявку и ответит в теме.</li>
          </ol>
          <?php if ($applyUrl !== ''): ?>
          <div class="guide-actions"><a class="site-btn site-btn-accent" href="<?= e($applyUrl) ?>"><?= icon('handshake') ?> Подать заявку на форуме</a></div>
          <?php endif; ?>
        </div>
        <div>
          <h2>Что недопустимо</h2>
          <ul class="guide-list is-warn">
            <li>Реклама других проектов в роликах о сервере.</li>
            <li>Призывы нарушать правила, показ читов и способов использовать ошибки игры.</li>
            <li>Оскорбления игроков и администрации.</li>
          </ul>
          <div class="note"><?= icon('info') ?><div>Ролики о сервере можно снимать и без заявки - просто соблюдайте <?php $rules = site_setting_node('rules_node_id'); if ($rules): ?><a href="<?= e(node_url($rules)) ?>">правила проекта</a><?php else: ?>правила проекта<?php endif; ?>.</div></div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="content-section" id="contacts">
  <div class="site-container">
    <div class="section-head reveal">
      <span class="eyebrow">Контакты</span>
      <h2 class="section-title">Остались вопросы?</h2>
      <p class="section-lead">Напиши нам в соцсетях или на форуме - ответим и поможем с деталями.</p>
    </div>
    <div class="socials-row reveal" style="justify-content:center">
      <?php foreach ($socials as $s): ?>
      <a class="site-btn site-btn-glass site-btn-pill" href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= icon($s['icon']) ?> <?= e($s['title']) ?></a>
      <?php endforeach; ?>
      <?php if ($applyUrl !== ''): ?>
      <a class="site-btn site-btn-white site-btn-pill" href="<?= e($applyUrl) ?>"><?= icon('chats') ?> Раздел «<?= e($node['title']) ?>»</a>
      <?php else: ?>
      <a class="site-btn site-btn-white site-btn-pill" href="<?= e(url('/forum/')) ?>"><?= icon('chats') ?> Форум</a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
site_footer();
