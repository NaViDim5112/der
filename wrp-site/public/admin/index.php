<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$stats = forum_stats();
$today = date('Y-m-d 00:00:00');
$regToday = (int)db_val('SELECT COUNT(*) FROM users WHERE created_at >= :d', ['d' => $today]);
$banned = (int)db_val('SELECT COUNT(*) FROM users WHERE is_banned = 1');
$online = online_list();
$profilePosts = 0;
try {
    $profilePosts = (int)db_val('SELECT COUNT(*) FROM profile_posts WHERE is_deleted = 0');
} catch (Exception $ex) {
    $profilePosts = null; // таблицы ещё нет (старая база)
}

$latestUsers = db_all(user_select_sql() . ' ORDER BY u.id DESC LIMIT 8');
$latestThreads = db_all('SELECT t.id, t.title, t.prefix_id, t.created_at, t.reply_count, t.node_id,
        n.title AS node_title, u.id AS user_id, u.username, u.avatar, g.color AS group_color
    FROM threads t
    JOIN nodes n ON n.id = t.node_id
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN user_groups g ON g.id = u.group_id
    WHERE t.is_deleted = 0
    ORDER BY t.created_at DESC, t.id DESC LIMIT 8');

$st = server_status();

// Проверки окружения: [статус ok|warn|bad, название, значение, подсказка]
$checks = [];
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = [$phpOk ? 'ok' : 'bad', 'Версия PHP', PHP_VERSION, $phpOk ? '' : 'Нужен PHP 7.4 или новее - выберите его в настройках OpenServer.'];
$dbVersion = (string)db_val('SELECT VERSION()');
$checks[] = ['ok', 'Версия MySQL / MariaDB', $dbVersion, ''];
$gd = extension_loaded('gd') && function_exists('imagecreatetruecolor');
$checks[] = [$gd ? 'ok' : 'warn', 'Расширение GD (картинки)', $gd ? 'есть' : 'нет', $gd ? '' : 'Без него не будут уменьшаться аватары. Включите расширение gd в настройках PHP OpenServer.'];
$dirs = [
    'storage/cache' => WRP_STORAGE . '/cache',
    'storage/logs' => WRP_STORAGE . '/logs',
    'public/uploads/avatars' => WRP_PUBLIC . '/uploads/avatars',
    'public/uploads/covers' => WRP_PUBLIC . '/uploads/covers',
];
foreach ($dirs as $label => $path) {
    $w = is_dir($path) && is_writable($path);
    $checks[] = [$w ? 'ok' : 'bad', 'Запись в папку ' . $label, $w ? 'можно' : (is_dir($path) ? 'нельзя' : 'папки нет'), $w ? '' : 'Создайте папку и разрешите запись в неё, иначе часть функций сайта не будет работать.'];
}
if (is_dir(WRP_PUBLIC . '/install')) {
    $checks[] = ['warn', 'Папка установщика', 'public/install', 'Удалите папку public/install после установки - иначе кто-то может запустить установку заново.'];
}
if (cfg('debug')) {
    $checks[] = ['warn', 'Режим отладки включён', "'debug' => true", 'На рабочем сайте выключите его в config/config.php: посетители могут увидеть технические ошибки.'];
}
if ((string)cfg('secret') === '' || cfg('secret') === 'change-me') {
    $checks[] = ['bad', 'Секретный ключ', 'не задан', 'Впишите длинную случайную строку в \'secret\' в config/config.php.'];
}
$problems = 0;
foreach ($checks as $c) {
    if ($c[0] !== 'ok') {
        $problems++;
    }
}

admin_header('Админ-панель', 'index');

$tiles = [
    ['Пользователи', $stats['users'], 'users', '#3b82f6', url('/admin/users.php')],
    ['Темы', $stats['threads'], 'chats', '#a78bfa', url('/forum/')],
    ['Сообщения', $stats['posts'], 'chat', '#14b8a6', url('/forum/find.php', ['type' => 'new'])],
    ['Регистрации сегодня', $regToday, 'user-plus', '#22c55e', url('/admin/users.php', ['sort' => 'new'])],
    ['Сейчас на сайте', $online['total'], 'eye', '#ff5a36', url('/forum/online.php')],
];
if ($profilePosts !== null) {
    $tiles[] = ['Сообщений в профилях', $profilePosts, 'edit', '#f59e0b', url('/forum/profile-posts.php')];
}
?>
<div class="adm-tiles">
  <?php foreach ($tiles as $t): ?>
  <a class="adm-tile" href="<?= e($t[4]) ?>" style="--tc:<?= e($t[3]) ?>">
    <span class="adm-tile-icon"><?= icon($t[2]) ?></span>
    <span><b><?= num($t[1]) ?></b><small><?= e($t[0]) ?></small></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="adm-cols adm-cols-wide">
  <div class="card">
    <div class="adm-card-head">
      <h2><?= icon('server') ?> Игровой сервер</h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/settings.php') . '#sec-server') ?>"><?= icon('settings') ?> Настроить</a>
    </div>
    <div class="adm-server">
      <div class="adm-server-top">
        <span class="status-dot <?= $st['online'] ? 'on' : 'off' ?>"></span>
        <span class="adm-server-name"><?= e($st['hostname'] ?: setting('server_name')) ?></span>
        <?php if ($st['online']): ?><span class="tag tag-success">В сети</span><?php elseif ($st['checked']): ?><span class="tag tag-danger">Не отвечает</span><?php else: ?><span class="tag">Не проверяется</span><?php endif; ?>
      </div>
      <?php if ($st['online']): ?>
      <div class="adm-server-online"><b><?= (int)$st['players'] ?></b> / <?= (int)$st['max'] ?> игроков</div>
      <div class="meter"><span style="width:<?= $st['max'] ? min(100, round($st['players'] * 100 / $st['max'])) : 0 ?>%"></span></div>
      <?php elseif ($st['checked']): ?>
      <div class="muted small">Сервер выключен или адрес указан неверно. Статус проверяется раз в 30 секунд.</div>
      <?php else: ?>
      <div class="muted small">Проверка статуса выключена в настройках («Проверять статус сервера»).</div>
      <?php endif; ?>
      <dl class="adm-kv">
        <dt>Адрес</dt><dd><button class="btn btn-sm btn-ghost" type="button" data-copy="<?= e(server_address()) ?>"><?= icon('copy') ?> <?= e(server_address()) ?></button></dd>
        <?php if ($st['gamemode'] !== ''): ?><dt>Мод</dt><dd><?= e($st['gamemode']) ?></dd><?php endif; ?>
        <?php if ($st['language'] !== ''): ?><dt>Язык</dt><dd><?= e($st['language']) ?></dd><?php endif; ?>
        <?php if ($st['online']): ?><dt>Пароль на входе</dt><dd><?= $st['password'] ? 'есть' : 'нет' ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="adm-card-head">
      <h2><?= icon('users') ?> Сейчас на сайте</h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/forum/online.php')) ?>">Все <?= icon('arrow-right') ?></a>
    </div>
    <dl class="adm-kv">
      <dt>Пользователей</dt><dd><?= num($online['members']) ?></dd>
      <dt>Гостей</dt><dd><?= num($online['guests']) ?></dd>
      <dt>Заблокировано аккаунтов</dt><dd><?php if ($banned): ?><a href="<?= e(url('/admin/users.php', ['banned' => 'yes'])) ?>"><?= num($banned) ?></a><?php else: ?>0<?php endif; ?></dd>
    </dl>
    <?php if ($online['users']): ?>
    <div class="online-names mt-2" style="padding:0">
      <?php $parts = []; foreach (array_slice($online['users'], 0, 20) as $ou) { $parts[] = user_link($ou); } echo implode(', ', $parts); ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="adm-cols">
  <div class="card">
    <div class="adm-card-head">
      <h2><?= icon('user-plus') ?> Новые пользователи</h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/users.php')) ?>">Все <?= icon('arrow-right') ?></a>
    </div>
    <?php if (!$latestUsers): ?>
    <div class="adm-empty"><?= icon('users') ?>Пока никто не зарегистрировался.</div>
    <?php else: ?>
    <div class="adm-list">
      <?php foreach ($latestUsers as $u): ?>
      <div class="adm-list-row">
        <?= avatar($u, 's') ?>
        <div class="adm-list-main">
          <a class="adm-list-title" href="<?= e(url('/admin/users.php', ['edit' => $u['id']])) ?>" style="color:<?= e($u['group_color']) ?>"><?= e($u['username']) ?></a>
          <span class="adm-list-meta"><?= e($u['email']) ?></span>
        </div>
        <div class="adm-list-side">
          <?php if ($u['is_banned']): ?><span class="tag tag-danger">Бан</span><br><?php endif; ?>
          <?= e(fdate($u['created_at'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="adm-card-head">
      <h2><?= icon('chats') ?> Новые темы</h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/forum/find.php', ['type' => 'new'])) ?>">Все <?= icon('arrow-right') ?></a>
    </div>
    <?php if (!$latestThreads): ?>
    <div class="adm-empty"><?= icon('chats') ?>Тем пока нет.</div>
    <?php else: ?>
    <div class="adm-list">
      <?php foreach ($latestThreads as $t): ?>
      <div class="adm-list-row">
        <?= $t['user_id'] ? avatar(['username' => $t['username'], 'avatar' => $t['avatar']], 's') : avatar(['username' => '?'], 's') ?>
        <div class="adm-list-main">
          <a class="adm-list-title" href="<?= e(thread_url($t['id'])) ?>" title="<?= e($t['title']) ?>"><?= prefix_html($t['prefix_id']) ?><?= e($t['title']) ?></a>
          <span class="adm-list-meta"><a href="<?= e(url('/forum/forum.php', ['id' => $t['node_id']])) ?>"><?= e($t['node_title']) ?></a> · <?= $t['user_id'] ? user_link(['id' => $t['user_id'], 'username' => $t['username'], 'group_color' => $t['group_color']]) : 'Гость' ?></span>
        </div>
        <div class="adm-list-side"><?= e(fdate($t['created_at'])) ?><br><?= num($t['reply_count']) ?> <?= plural($t['reply_count'], 'ответ', 'ответа', 'ответов') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon($problems ? 'alert' : 'check') ?> Проверка сервера сайта</h2>
      <p><?= $problems ? 'Есть замечания: ' . $problems . '. Исправьте их, чтобы сайт работал надёжно.' : 'Всё в порядке.' ?></p>
    </div>
  </div>
  <div class="adm-checks">
    <?php foreach ($checks as $c): ?>
    <div class="adm-check <?= $c[0] === 'ok' ? '' : e($c[0]) ?>">
      <?= icon($c[0] === 'ok' ? 'check' : 'alert') ?>
      <div class="adm-check-body">
        <div class="adm-check-name"><?= e($c[1]) ?></div>
        <?php if ($c[3] !== ''): ?><div class="adm-check-hint"><?= e($c[3]) ?></div><?php endif; ?>
      </div>
      <div class="adm-check-val"><?= e($c[2]) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
admin_footer();
