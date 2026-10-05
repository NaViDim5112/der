<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/settings.php');
$defaults = settings_defaults();
$keys = array_keys($defaults);
$nodeKeys = [
    'news_node_id' => ['Раздел новостей', 'Отсюда берутся новости на главной странице сайта.'],
    'rules_node_id' => ['Раздел правил', 'Куда ведёт кнопка «Правила».'],
    'complaints_node_id' => ['Раздел жалоб', 'Куда ведёт кнопка «Жалобы».'],
    'tech_node_id' => ['Технический раздел', 'Куда ведёт кнопка «Тех. поддержка».'],
];
$checkKeys = ['server_query', 'registration_open'];

$v = [];
foreach ($keys as $k) {
    $v[$k] = (string)setting($k);
}
$errors = [];

if (is_post()) {
    csrf_check();
    $in = [];
    foreach ($keys as $k) {
        if (in_array($k, $checkKeys, true)) {
            $in[$k] = input_bool($k) ? '1' : '0';
        } elseif (in_array($k, ['announcement', 'partners_text', 'site_description'], true)) {
            $in[$k] = input_text($k);
        } else {
            $in[$k] = input($k);
        }
    }
    $v = array_merge($v, $in);

    // Обязательные и длина строк
    $limits = ['site_name' => 60, 'site_subtitle' => 60, 'site_description' => 300, 'forum_title' => 100, 'footer_text' => 300,
        'server_name' => 100, 'server_ip' => 100, 'announcement' => 5000, 'partners_text' => 50000];
    foreach (['site_name' => 'Название сайта', 'forum_title' => 'Заголовок форума', 'server_name' => 'Название сервера', 'server_ip' => 'IP-адрес сервера'] as $k => $label) {
        if ($in[$k] === '') {
            $errors[$k] = 'Заполните поле «' . $label . '».';
        }
    }
    foreach ($limits as $k => $max) {
        if (!isset($errors[$k]) && !adm_len_ok($in[$k], $max)) {
            $errors[$k] = 'Слишком длинно (максимум ' . $max . ' символов).';
        }
    }
    if (!isset($errors['server_ip']) && !preg_match('~^[A-Za-z0-9.\-]+$~', $in['server_ip'])) {
        $errors['server_ip'] = 'IP-адрес или домен: только цифры, латиница, точки и дефисы, без http:// и порта.';
    }
    $ranges = [
        'server_port' => [1, 65535, 'Порт - число от 1 до 65535.'],
        'threads_per_page' => [5, 100, 'Тем на странице - от 5 до 100.'],
        'posts_per_page' => [5, 100, 'Сообщений на странице - от 5 до 100.'],
        'flood_seconds' => [0, 600, 'Пауза между сообщениями - от 0 до 600 секунд.'],
        'edit_window_minutes' => [0, 10080, 'Время на правку - от 0 до 10080 минут (неделя).'],
    ];
    foreach ($ranges as $k => $r) {
        $n = adm_post_range($k, $r[0], $r[1]);
        if ($n === null) {
            $errors[$k] = $r[2];
        } else {
            $in[$k] = (string)$n;
            $v[$k] = (string)$n;
        }
    }
    foreach (['client_url' => 'Клиент SA-MP', 'launcher_url' => 'Лаунчер', 'android_url' => 'Приложение для Android', 'discord_url' => 'Discord', 'vk_url' => 'ВКонтакте', 'telegram_url' => 'Telegram', 'youtube_url' => 'YouTube'] as $k => $label) {
        if ($in[$k] !== '' && (!adm_is_http_url($in[$k]) || !adm_len_ok($in[$k], 255))) {
            $errors[$k] = $label . ': ссылка должна начинаться с http:// или https://';
        }
    }
    if ($in['video_url'] !== '' && !adm_is_youtube($in['video_url'])) {
        $errors['video_url'] = 'Вставьте ссылку на ролик YouTube, например https://youtu.be/xxxxxxx';
    }
    foreach ($nodeKeys as $k => $info) {
        $id = (int)$in[$k];
        if ($id && !node_get($id)) {
            $errors[$k] = 'Такого раздела нет.';
        }
        $in[$k] = (string)$id;
        $v[$k] = (string)$id;
    }

    if (!$errors) {
        $serverChanged = $in['server_ip'] !== (string)setting('server_ip') || $in['server_port'] !== (string)setting('server_port') || $in['server_query'] !== (string)setting('server_query');
        $changed = 0;
        foreach ($keys as $k) {
            if ((string)setting($k) !== $in[$k]) {
                setting_set($k, $in[$k]);
                $changed++;
            }
        }
        if ($serverChanged) {
            $cache = WRP_STORAGE . '/cache/server_status.json';
            if (is_file($cache)) {
                @unlink($cache);
            }
        }
        flash('success', $changed ? 'Настройки сохранены.' : 'Ничего не изменилось.');
        redirect($self);
    }
}

admin_header('Настройки', 'settings');

$field = function ($k, $label, $hint = '', $attrs = '', $type = 'text') use ($v, $errors) {
    $h = '<div class="form-row"><label class="label" for="' . e($k) . '">' . $label . '</label>';
    $h .= '<input class="input' . (isset($errors[$k]) ? ' is-invalid' : '') . '" type="' . e($type) . '" id="' . e($k) . '" name="' . e($k) . '" value="' . e($v[$k]) . '" ' . $attrs . '>';
    if ($hint !== '') {
        $h .= '<div class="hint">' . $hint . '</div>';
    }
    return $h . adm_err($errors, $k) . '</div>';
};
?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>

  <nav class="adm-anchors" aria-label="Разделы настроек">
    <a href="#sec-main">Основное</a>
    <a href="#sec-server">Игровой сервер</a>
    <a href="#sec-social">Соцсети</a>
    <a href="#sec-forum">Форум</a>
    <a href="#sec-partners">Страница «Сотрудничество»</a>
  </nav>

  <div class="card" id="sec-main">
    <div class="adm-card-head"><div><h2><?= icon('home') ?> Основное</h2><p>Название проекта, подписи и объявление на форуме.</p></div></div>
    <div class="form-grid">
      <?= $field('site_name', 'Название сайта <span class="req">*</span>', 'Крупно в шапке, например «World Role Play».', 'maxlength="60" required') ?>
      <?= $field('site_subtitle', 'Подзаголовок', 'Мелкая цветная надпись под названием, например «Los Santos».', 'maxlength="60"') ?>
    </div>
    <div class="form-row">
      <label class="label" for="site_description">Описание сайта</label>
      <textarea class="textarea textarea-sm<?= isset($errors['site_description']) ? ' is-invalid' : '' ?>" id="site_description" name="site_description" rows="2" maxlength="300"><?= e($v['site_description']) ?></textarea>
      <div class="hint">Пара предложений о проекте. Показывается в поиске Google/Яндекс и при отправке ссылки в соцсети.</div>
      <?= adm_err($errors, 'site_description') ?>
    </div>
    <div class="form-grid">
      <?= $field('forum_title', 'Заголовок форума <span class="req">*</span>', 'Название вкладки браузера и главной страницы форума.', 'maxlength="100" required') ?>
      <?= $field('footer_text', 'Текст в подвале', 'Мелкий текст внизу каждой страницы.', 'maxlength="300"') ?>
    </div>
    <div class="form-row">
      <label class="label" for="announcement">Объявление на форуме</label>
      <textarea class="textarea<?= isset($errors['announcement']) ? ' is-invalid' : '' ?>" id="announcement" name="announcement" rows="3" data-editor><?= e($v['announcement']) ?></textarea>
      <div class="hint">Если заполнить, оно появится вверху каждой страницы форума (например: «Сегодня в 20:00 - открытие сервера!»). Оставьте пустым, чтобы убрать.</div>
      <?= adm_err($errors, 'announcement') ?>
    </div>
  </div>

  <div class="card" id="sec-server">
    <div class="adm-card-head"><div><h2><?= icon('server') ?> Игровой сервер</h2><p>Адрес сервера и ссылки для кнопок на странице «Начать игру».</p></div></div>
    <div class="form-grid">
      <?= $field('server_name', 'Название сервера <span class="req">*</span>', 'Показывается, если сервер не отвечает на запрос статуса.', 'maxlength="100" required') ?>
      <div class="form-grid">
        <?= $field('server_ip', 'IP-адрес или домен <span class="req">*</span>', 'Без порта, например 185.25.10.5', 'maxlength="100" required') ?>
        <?= $field('server_port', 'Порт', 'Обычно 7777.', 'min="1" max="65535" required', 'number') ?>
      </div>
    </div>
    <label class="adm-opt"><input type="checkbox" name="server_query" value="1"<?= adm_chk($v['server_query'] === '1') ?>><span><b>Проверять статус сервера</b><small>Сайт сам узнаёт онлайн и число игроков (раз в 30 секунд). Выключите, если хостинг не разрешает такие запросы.</small></span></label>
    <div class="form-grid">
      <?= $field('launcher_url', 'Лаунчер World RP для Windows', 'Ссылка на скачивание лаунчера. Пусто - на кнопке будет надпись «Скоро».', 'maxlength="255" placeholder="https://" inputmode="url"') ?>
      <?= $field('android_url', 'Приложение для Android', 'Ссылка на скачивание версии для телефона. Пусто - на кнопке будет надпись «Скоро».', 'maxlength="255" placeholder="https://" inputmode="url"') ?>
    </div>
    <?= $field('client_url', 'Клиент SA-MP для прямого подключения', 'Для тех, кто играет без лаунчера: ссылка на клиент SA-MP 0.3.7.', 'maxlength="255" placeholder="https://" inputmode="url"') ?>
    <?= $field('video_url', 'Видео об игре (YouTube)', 'Ссылка на ролик YouTube для кнопки «Видео об игре». Пусто - кнопки не будет.', 'maxlength="255" placeholder="https://youtu.be/..." inputmode="url"') ?>
  </div>

  <div class="card" id="sec-social">
    <div class="adm-card-head"><div><h2><?= icon('share') ?> Соцсети</h2><p>Ссылки появятся в подвале сайта. Пустое поле - ссылки не будет.</p></div></div>
    <div class="form-grid">
      <?= $field('discord_url', 'Discord', '', 'maxlength="255" placeholder="https://discord.gg/..." inputmode="url"') ?>
      <?= $field('vk_url', 'ВКонтакте', '', 'maxlength="255" placeholder="https://vk.com/..." inputmode="url"') ?>
      <?= $field('telegram_url', 'Telegram', '', 'maxlength="255" placeholder="https://t.me/..." inputmode="url"') ?>
      <?= $field('youtube_url', 'YouTube-канал', '', 'maxlength="255" placeholder="https://youtube.com/@..." inputmode="url"') ?>
    </div>
  </div>

  <div class="card" id="sec-forum">
    <div class="adm-card-head"><div><h2><?= icon('chats') ?> Форум</h2><p>Регистрация, ограничения и важные разделы.</p></div></div>
    <label class="adm-opt"><input type="checkbox" name="registration_open" value="1"<?= adm_chk($v['registration_open'] === '1') ?>><span><b>Регистрация открыта</b><small>Снимите галочку, чтобы временно запретить новые регистрации на сайте.</small></span></label>
    <div class="form-grid">
      <?= $field('threads_per_page', 'Тем на странице', 'Сколько тем показывать в списке раздела (5-100).', 'min="5" max="100" required', 'number') ?>
      <?= $field('posts_per_page', 'Сообщений на странице', 'Сколько сообщений на одной странице темы (5-100).', 'min="5" max="100" required', 'number') ?>
      <?= $field('flood_seconds', 'Пауза между сообщениями, секунд', 'Защита от флуда (0-600). 0 - без паузы. На команду проекта не действует.', 'min="0" max="600" required', 'number') ?>
      <?= $field('edit_window_minutes', 'Время на правку своего сообщения, минут', '0 - без ограничений.', 'min="0" max="10080" required', 'number') ?>
    </div>
    <div class="adm-section-title">Важные разделы</div>
    <div class="form-grid">
      <?php foreach ($nodeKeys as $k => $info): ?>
      <div class="form-row">
        <label class="label" for="<?= e($k) ?>"><?= e($info[0]) ?></label>
        <select class="select<?= isset($errors[$k]) ? ' is-invalid' : '' ?>" id="<?= e($k) ?>" name="<?= e($k) ?>">
          <option value="0">- Не выбран -</option>
          <?= adm_node_options((int)$v[$k]) ?>
        </select>
        <div class="hint"><?= e($info[1]) ?></div>
        <?= adm_err($errors, $k) ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card" id="sec-partners">
    <div class="adm-card-head"><div><h2><?= icon('handshake') ?> Страница «Сотрудничество»</h2><p>Текст страницы для блогеров и контент-мейкеров (<a href="<?= e(url('/partners.php')) ?>" target="_blank">открыть страницу</a>).</p></div></div>
    <div class="form-row">
      <textarea class="textarea<?= isset($errors['partners_text']) ? ' is-invalid' : '' ?>" id="partners_text" name="partners_text" rows="10" data-editor aria-label="Текст страницы «Сотрудничество»"><?= e($v['partners_text']) ?></textarea>
      <div class="hint">Условия сотрудничества, требования к каналу, как связаться. Можно использовать BB-коды: жирный текст, списки, ссылки, видео.</div>
      <?= adm_err($errors, 'partners_text') ?>
    </div>
  </div>

  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить настройки</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">Отменить изменения</a>
    <span class="hint">Изменения видны на сайте сразу после сохранения</span>
  </div>
</form>
<?php
admin_footer();
