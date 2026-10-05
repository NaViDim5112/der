<?php
require __DIR__ . '/../app/bootstrap.php';

$launcher = trim((string)setting('launcher_url'));
$launcher = preg_match('~^https?://~i', $launcher) ? $launcher : '';
$android = trim((string)setting('android_url'));
$android = preg_match('~^https?://~i', $android) ? $android : '';
$client = trim((string)setting('client_url'));
$client = preg_match('~^https?://~i', $client) ? $client : '';
$tech = site_setting_node('tech_node_id');
$newsNode = site_setting_node('news_node_id');
$nickArticle = wiki_article_by_slug('pravilnyy-rp-nik');
$startArticle = wiki_article_by_slug('kak-nachat-igrat');
$supportUrl = $tech ? node_url($tech) : url('/forum/');

// Кнопка скачивания лаунчера: ссылка из настроек или неактивная «Скоро»
$launcherBtn = function ($lg) use ($launcher) {
    $cls = 'site-btn site-btn-accent' . ($lg ? ' site-btn-lg' : '');
    if ($launcher !== '') {
        return '<a class="' . $cls . '" href="' . e($launcher) . '" rel="noopener">' . icon('download') . ' Скачать лаунчер</a>';
    }
    return '<span class="' . $cls . ' is-disabled" aria-disabled="true" title="Лаунчер появится к открытию сервера">' . icon('download') . ' Скачать лаунчер <span class="soon-tag">Скоро</span></span>';
};
$phoneIcon = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>';

site_header([
    'title' => 'Начать игру',
    'active' => 'start',
    'description' => 'Как начать играть на ' . setting('site_name') . ': GTA San Andreas 1.0, лаунчер World RP, регистрация и первый вход.',
]);

site_page_hero(
    'Начать игру',
    'Как начать играть',
    'Играть на сервере можно через собственный лаунчер World RP. Он сам установит всё нужное, проверит файлы и подключит тебя к серверу.',
    $launcherBtn(true) . '<a class="site-btn site-btn-glass site-btn-lg" href="#guide">' . icon('list') . ' Инструкция</a>'
);
?>
<section class="content-section" id="guide">
  <div class="site-container">
    <div class="guide">
      <article class="guide-step reveal">
        <div class="guide-num">1</div>
        <div>
          <h2>Подготовь GTA San Andreas 1.0 (US)</h2>
          <p>Для игры нужна <b>GTA San Andreas версии 1.0 (US)</b>, установленная на компьютере с Windows. Лаунчер не распространяет файлы GTA - игра должна быть у тебя.</p>
          <div class="two-cols">
            <div class="mini-card">
              <h3><?= icon('monitor') ?> Компьютер с Windows</h3>
              <p>Лаунчер и игра работают на Windows. Ставь игру в папку без русских букв, например <code>C:\Games\GTA San Andreas</code>.</p>
            </div>
            <div class="mini-card">
              <h3><?= icon('check') ?> Версия 1.0 (US)</h3>
              <p>Версии из Steam и Rockstar Games Launcher новее - их нужно понизить до 1.0 US. Definitive Edition не подходит.</p>
            </div>
          </div>
        </div>
      </article>

      <article class="guide-step reveal">
        <div class="guide-num">2</div>
        <div>
          <h2>Скачай лаунчер World RP</h2>
          <p>Лаунчер - основной способ играть на сервере. Он подготовит игру и будет держать её в актуальном состоянии.</p>
          <ul class="guide-list">
            <li>Ставит клиент <b>SA-MP 0.3.7</b>, ядро и интерфейс World RP и файлы сервера.</li>
            <li>Проверяет все файлы по контрольным суммам <b>SHA-256</b>.</li>
            <li>Обновляется сам - вручную ничего скачивать не нужно.</li>
            <li>Переносит сторонние CLEO- и ASI-файлы в карантин, при желании их можно вернуть.</li>
          </ul>
          <div class="dl-grid">
            <div class="dl-card">
              <div class="dl-card-head">
                <span class="dl-icon"><?= icon('monitor') ?></span>
                <div><b>Лаунчер для Windows</b><small>Рекомендуемый способ</small></div>
              </div>
              <?= $launcherBtn(false) ?>
            </div>
            <div class="dl-card<?= $android === '' ? ' is-muted' : '' ?>">
              <div class="dl-card-head">
                <span class="dl-icon"><?= $phoneIcon ?></span>
                <div><b>Android</b><small><?= $android === '' ? 'Мобильной версии пока нет' : 'Мобильная версия' ?></small></div>
              </div>
              <?php if ($android !== ''): ?>
              <a class="site-btn site-btn-glass" href="<?= e($android) ?>" rel="noopener"><?= icon('download') ?> Скачать для Android</a>
              <?php else: ?>
              <span class="site-btn site-btn-glass is-disabled" aria-disabled="true">Скоро</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($launcher === ''): ?>
          <div class="note note-info"><?= icon('info') ?><div>Лаунчер появится к открытию сервера, следите за новостями<?php if ($newsNode && site_node_public($newsNode)): ?> в разделе <a href="<?= e(node_url($newsNode)) ?>"><?= e($newsNode['title']) ?></a><?php endif; ?>.</div></div>
          <?php endif; ?>
        </div>
      </article>

      <article class="guide-step reveal">
        <div class="guide-num">3</div>
        <div>
          <h2>Создай аккаунт и нажми «Играть»</h2>
          <p>Регистрация и вход - прямо в лаунчере. После входа нажми <b>«Играть»</b>: лаунчер сам подключит тебя к серверу, вводить пароль в игре не нужно.</p>
          <div class="two-cols">
            <div class="mini-card">
              <h3><?= icon('user') ?> Ник: Имя_Фамилия</h3>
              <p>Ник - это имя персонажа: латиница, имя и фамилия через нижнее подчёркивание, например <code>John_Smith</code>. Без цифр, имён знаменитостей и оскорблений.<?php if ($nickArticle && $nickArticle['is_published']): ?> <a class="inline-link" href="<?= e(wiki_article_url($nickArticle)) ?>">Подробнее</a><?php endif; ?></p>
            </div>
            <div class="mini-card">
              <h3><?= icon('lock') ?> Надёжный пароль</h3>
              <p>Придумай длинный пароль, которого нет на других сайтах. Администрация никогда не спрашивает пароли - никому его не сообщай.</p>
            </div>
          </div>
        </div>
      </article>

      <article class="guide-step reveal">
        <div class="guide-num">4</div>
        <div>
          <h2>Первый вход в игру</h2>
          <p>Новый персонаж прилетает в международный аэропорт Лос-Сантоса (LSIA). Дальше всё просто:</p>
          <ul class="guide-list">
            <li>Выбери внешность персонажа.</li>
            <li>Выйди из терминала прилёта.</li>
            <li>Подойди к стойке <b>Центра адаптации</b> и нажми <code>ALT</code> - там подскажут, с чего начать.</li>
          </ul>
          <?php if ($startArticle && $startArticle['is_published']): ?>
          <div class="guide-actions"><a class="link-arrow" href="<?= e(wiki_article_url($startArticle)) ?>">Статья «<?= e($startArticle['title']) ?>» в базе знаний <?= icon('arrow-right') ?></a></div>
          <?php endif; ?>
        </div>
      </article>
    </div>
  </div>
</section>

<section class="content-section" id="connect">
  <div class="site-container">
    <div class="connect-card reveal">
      <div class="connect-card-text">
        <h2>Подключиться напрямую</h2>
        <p>Рекомендуем играть через лаунчер: он проверит файлы и сам войдёт в аккаунт. Если клиент SA-MP 0.3.7 у тебя уже установлен, добавь сервер в избранное по адресу ниже.<?php if ($client !== ''): ?> <a class="inline-link" href="<?= e($client) ?>" target="_blank" rel="noopener">Клиент SA-MP 0.3.7</a><?php endif; ?></p>
      </div>
      <?= site_address_box(true) ?>
      <p class="connect-hint">Кнопка «Подключиться» откроет клиент SA-MP, если он установлен на компьютере.</p>
    </div>
  </div>
</section>

<section class="content-section" id="faq">
  <div class="site-container">
    <div class="section-head reveal">
      <span class="eyebrow">Вопросы</span>
      <h2 class="section-title">Частые вопросы</h2>
    </div>
    <div class="faq reveal">
      <details>
        <summary>Лаунчер пишет, что файлы игры не подходят<?= icon('plus') ?></summary>
        <div class="faq-body">
          <p>Лаунчер работает только с <b>GTA San Andreas версии 1.0 (US)</b>. Версии из Steam и Rockstar Games Launcher нужно понизить до 1.0 US, а Definitive Edition не подходит совсем.</p>
          <p>Укажи в лаунчере папку с подходящей версией игры и запусти проверку ещё раз.</p>
        </div>
      </details>
      <details>
        <summary>Пропали CLEO-скрипты или ASI-плагины<?= icon('plus') ?></summary>
        <div class="faq-body">
          <p>Это нормально: лаунчер переносит сторонние CLEO- и ASI-файлы в карантин, чтобы они не мешали работе клиента. Файлы не удаляются - их можно вернуть из карантина.</p>
        </div>
      </details>
      <details>
        <summary>Игра или лаунчер не запускается<?= icon('plus') ?></summary>
        <div class="faq-body">
          <ul>
            <li>Запусти лаунчер от имени администратора.</li>
            <li>Проверь, что антивирус не заблокировал файлы лаунчера и игры.</li>
            <li>Убедись, что в пути к папке с игрой нет русских букв.</li>
          </ul>
          <p>Не помогло - создай тему в разделе <a href="<?= e($supportUrl) ?>"><?= e($tech ? $tech['title'] : 'форума') ?></a>: опиши проблему и приложи скриншот ошибки.</p>
        </div>
      </details>
      <details>
        <summary>Нужно ли вводить пароль в игре?<?= icon('plus') ?></summary>
        <div class="faq-body">
          <p>Нет. Вход выполняется в лаунчере, а после нажатия «Играть» он подключит тебя к серверу уже авторизованным.</p>
        </div>
      </details>
      <details>
        <summary>Можно ли играть с телефона?<?= icon('plus') ?></summary>
        <div class="faq-body">
          <p>Сейчас играть можно только на компьютере с Windows. Мобильной версии пока нет - если она появится, мы расскажем об этом в новостях.</p>
        </div>
      </details>
    </div>
  </div>
</section>

<section class="content-section">
  <div class="site-container">
    <div class="cta-grid">
      <a class="cta-card reveal" href="<?= e(url('/wiki/')) ?>">
        <span class="feature-icon"><?= icon('book') ?></span>
        <div>
          <h3>База знаний</h3>
          <p>Дома, транспорт, банк, организации и команды сервера - коротко и по делу.</p>
          <span class="link-arrow">Открыть базу знаний <?= icon('arrow-right') ?></span>
        </div>
      </a>
      <a class="cta-card reveal" style="--d:90ms" href="<?= e($supportUrl) ?>">
        <span class="feature-icon"><?= icon('wrench') ?></span>
        <div>
          <h3>Техническая поддержка</h3>
          <p>Не получается запустить игру или лаунчер? Опиши проблему на форуме - поможем разобраться.</p>
          <span class="link-arrow">Задать вопрос <?= icon('arrow-right') ?></span>
        </div>
      </a>
    </div>
  </div>
</section>
<?php
site_footer();
