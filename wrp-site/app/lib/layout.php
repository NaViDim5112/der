<?php
// Оболочка форума: левое меню, верхняя панель, заголовок с «хлебными крошками», правая колонка.
// Используется форумом, базой знаний, кабинетом и страницами аккаунта.
// Параметры: title, h1_html, meta_html, title_actions (HTML кнопок справа от заголовка, например «Создать тему»),
// crumbs, nav, right, css, js, body_class, description.
//
//   forum_header(['title' => 'Форум', 'crumbs' => [['Форумы', url('/forum/')]], 'nav' => 'forums']);
//   ... HTML страницы ...
//   forum_footer();

function theme_attr()
{
    $t = $_COOKIE['wrp_theme'] ?? 'dark';
    return $t === 'light' ? 'light' : 'dark';
}

function forum_header(array $o = [])
{
    $GLOBALS['wrp_layout'] = $o;
    $title = $o['title'] ?? setting('forum_title');
    $siteName = setting('site_name');
    $pageTitle = ($title && $title !== setting('forum_title')) ? $title . ' | ' . setting('forum_title') : setting('forum_title');
    $nav = $o['nav'] ?? 'forums';
    $right = $o['right'] ?? true;
    $wide = ($_COOKIE['wrp_wide'] ?? '') === '1';
    $u = user();
    $crumbs = $o['crumbs'] ?? [];
    ?>
<!doctype html>
<html lang="ru" data-theme="<?= theme_attr() ?>"<?= $wide ? ' class="is-wide"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($o['description'] ?? setting('site_description')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(rtrim((string)cfg('base_path', ''), '/')) ?>">
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/forum.css')) ?>">
<?php foreach ($o['css'] ?? [] as $css): ?>
<link rel="stylesheet" href="<?= e(asset('css/' . $css)) ?>">
<?php endforeach; ?>
</head>
<body class="layout-forum <?= e($o['body_class'] ?? '') ?>">
<div class="bg-glow" aria-hidden="true"></div>
<div class="sidebar-backdrop" data-toggle-sidebar></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-top">
    <a class="sidebar-brand" href="<?= e(url('/')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""><span><?= e($siteName) ?></span></a>
    <button class="sidebar-toggle" type="button" data-toggle-sidebar aria-label="Свернуть меню"><?= icon('menu') ?></button>
  </div>
  <nav class="side-nav">
    <a class="side-link<?= $nav === 'home' ? ' active' : '' ?>" href="<?= e(url('/')) ?>"><?= icon('home') ?><span>Главная</span></a>
    <div class="side-group side-split">
      <a class="side-link<?= $nav === 'forums' ? ' active' : '' ?>" href="<?= e(url('/forum/')) ?>"><?= icon('chats') ?><span>Форумы</span></a>
      <button class="side-caret-btn" type="button" data-side-group aria-label="Ещё"><?= icon('chevron-down', 'side-caret') ?></button>
      <div class="side-sub">
        <a href="<?= e(url('/forum/find.php', ['type' => 'new'])) ?>">Новые сообщения</a>
        <a href="<?= e(url('/forum/find.php', ['type' => 'unanswered'])) ?>">Темы без ответов</a>
        <a href="<?= e(url('/forum/search.php')) ?>">Поиск по форуму</a>
      </div>
    </div>
    <div class="side-group<?= $nav === 'members' ? ' open' : '' ?>">
      <button class="side-link<?= $nav === 'members' ? ' active' : '' ?>" type="button" data-side-group><?= icon('users') ?><span>Пользователи</span><?= icon('chevron-down', 'side-caret') ?></button>
      <div class="side-sub">
        <a href="<?= e(url('/forum/members.php')) ?>">Участники</a>
        <a href="<?= e(url('/forum/online.php')) ?>">Сейчас на форуме</a>
        <a href="<?= e(url('/forum/staff.php')) ?>">Администрация</a>
      </div>
    </div>
    <a class="side-link<?= $nav === 'wiki' ? ' active' : '' ?>" href="<?= e(url('/wiki/')) ?>"><?= icon('help') ?><span>База знаний</span></a>
    <?php foreach (nav_links() as $l): ?>
    <a class="side-link<?= $nav === 'link-' . $l['id'] ? ' active' : '' ?>" href="<?= e(nav_link_href($l['url'])) ?>"<?= $l['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= icon($l['icon']) ?><span><?= e($l['title']) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($nav === 'members'): ?>
  <div class="side-section">Раздел навигации</div>
  <nav class="side-nav side-nav-small">
    <a class="side-link" href="<?= e(url('/forum/online.php')) ?>"><?= icon('users') ?><span>Текущие посетители</span></a>
    <a class="side-link" href="<?= e(url('/forum/profile-posts.php')) ?>"><?= icon('edit') ?><span>Новые сообщения профилей</span></a>
    <a class="side-link" href="<?= e(url('/forum/profile-posts.php', ['search' => 1])) ?>"><?= icon('chat') ?><span>Поиск сообщений профилей</span></a>
  </nav>
  <?php elseif ($u): ?>
  <div class="side-section">Раздел навигации</div>
  <nav class="side-nav side-nav-small">
    <div class="side-group">
      <button class="side-link" type="button" data-side-group><?= icon('chat') ?><span>Найти темы</span><?= icon('chevron-down', 'side-caret') ?></button>
      <div class="side-sub">
        <a href="<?= e(url('/forum/find.php', ['type' => 'new'])) ?>">Новые сообщения</a>
        <a href="<?= e(url('/forum/find.php', ['type' => 'mine'])) ?>">Мои темы</a>
        <a href="<?= e(url('/forum/find.php', ['type' => 'participated'])) ?>">Темы с моим участием</a>
        <a href="<?= e(url('/forum/find.php', ['type' => 'unanswered'])) ?>">Темы без ответов</a>
      </div>
    </div>
    <div class="side-group">
      <button class="side-link" type="button" data-side-group><?= icon('bell') ?><span>Отслеживаемое</span><?= icon('chevron-down', 'side-caret') ?></button>
      <div class="side-sub">
        <a href="<?= e(url('/forum/watched.php')) ?>">Отслеживаемые темы</a>
        <a href="<?= e(url('/forum/alerts.php')) ?>">Оповещения</a>
      </div>
    </div>
    <a class="side-link" href="<?= e(url('/forum/search.php')) ?>"><?= icon('search') ?><span>Поиск сообщений</span></a>
    <form method="post" action="<?= e(url('/forum/mark-read.php')) ?>" class="side-form">
      <?= csrf_field() ?>
      <button class="side-link" type="submit"><?= icon('eye-off') ?><span>Прочитать всё</span></button>
    </form>
  </nav>
  <?php endif; ?>
</aside>

<div class="page">
  <div class="container">
    <header class="topbar">
      <button class="btn btn-ghost btn-icon mobile-only" type="button" data-toggle-sidebar aria-label="Меню"><?= icon('menu') ?></button>
      <a class="brand" href="<?= e(url('/forum/')) ?>" title="<?= e($siteName) ?>">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="<?= e($siteName) ?>">
        <span class="brand-text"><b><?= e(mb_strtoupper($siteName)) ?></b><small><?= e(mb_strtoupper((string)setting('site_subtitle'))) ?></small></span>
      </a>
      <div class="topbar-right">
        <?php if ($u): $alerts = alerts_unread_count(); $convs = conversations_unread_count(); ?>
        <div class="btn-group">
          <div class="dropdown">
            <button class="btn btn-ghost user-pill" type="button" data-dropdown><?= avatar($u, 'xs') ?><span class="user-pill-name"><?= e($u['username']) ?></span></button>
            <div class="dropdown-menu dropdown-right account-menu">
              <div class="account-menu-tabs"><span class="active">Ваш аккаунт</span><a href="<?= e(url('/forum/watched.php')) ?>">Закладки</a></div>
              <div class="account-menu-head">
                <a href="<?= e(url('/forum/member.php', ['id' => $u['id']])) ?>"><?= avatar($u, 'xl') ?></a>
                <div class="account-menu-info">
                  <?= user_link($u) ?>
                  <dl class="account-menu-stats">
                    <dt>Сообщения:</dt><dd><?= num($u['posts_count']) ?></dd>
                    <dt>Реакции:</dt><dd><?= num($u['likes_received']) ?></dd>
                    <dt>Баллы:</dt><dd><?= num(user_points($u)) ?></dd>
                  </dl>
                </div>
              </div>
              <div class="account-menu-links">
                <a href="<?= e(url('/forum/member.php', ['id' => $u['id']])) ?>">Мой профиль</a>
                <a href="<?= e(url('/forum/alerts.php')) ?>">Оповещения</a>
                <a href="<?= e(url('/forum/find.php', ['type' => 'mine'])) ?>">Ваши публикации</a>
                <a href="<?= e(url('/forum/watched.php')) ?>">Отслеживаемые темы</a>
              </div>
              <div class="account-menu-links">
                <a href="<?= e(url('/forum/account.php', ['tab' => 'profile'])) ?>">Информация</a>
                <a href="<?= e(url('/forum/account.php', ['tab' => 'signature'])) ?>">Подпись</a>
                <a href="<?= e(url('/forum/account.php', ['tab' => 'security'])) ?>">Безопасность</a>
                <a href="<?= e(url('/forum/account.php', ['tab' => 'following'])) ?>">Подписки</a>
                <a href="<?= e(url('/forum/account.php', ['tab' => 'ignored'])) ?>">Игнорирование</a>
                <a href="<?= e(url('/forum/conversations.php')) ?>">Личные сообщения</a>
                <a href="<?= e(url('/cabinet/')) ?>">Личный кабинет</a>
                <?php if (can_admin()): ?><a href="<?= e(url('/admin/')) ?>">Админ-панель</a><?php endif; ?>
              </div>
              <form method="post" action="<?= e(url('/forum/logout.php')) ?>" class="account-menu-logout">
                <?= csrf_field() ?>
                <button type="submit"><?= icon('logout') ?> Выход</button>
              </form>
              <form method="post" action="<?= e(url('/forum/account.php')) ?>" class="account-menu-status">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input class="input" name="status_text" maxlength="140" placeholder="Обновить свой статус..." value="<?= e($u['status_text'] ?? '') ?>">
              </form>
            </div>
          </div>
          <a class="btn btn-ghost btn-icon" href="<?= e(url('/forum/conversations.php')) ?>" title="Личные сообщения"><?= icon('mail') ?><?= $convs ? '<span class="count-badge">' . $convs . '</span>' : '' ?></a>
          <a class="btn btn-ghost btn-icon" href="<?= e(url('/forum/alerts.php')) ?>" title="Оповещения"><?= icon('bell') ?><?= $alerts ? '<span class="count-badge">' . $alerts . '</span>' : '' ?></a>
        </div>
        <?php else: ?>
        <div class="btn-group">
          <a class="btn btn-ghost" href="<?= e(url('/forum/login.php', ['return' => current_url()])) ?>"><?= icon('login') ?><span>Вход</span></a>
          <a class="btn btn-ghost" href="<?= e(url('/forum/register.php')) ?>"><?= icon('user-plus') ?><span>Регистрация</span></a>
        </div>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/forum/search.php')) ?>"><?= icon('search') ?><span class="hide-sm">Поиск</span></a>
      </div>
    </header>

    <section class="titlebar">
      <div class="titlebar-head">
        <h1><?= isset($o['h1_html']) ? $o['h1_html'] : e($title) ?></h1>
        <?php if (!empty($o['title_actions'])): ?><div class="titlebar-actions"><?= $o['title_actions'] ?></div><?php endif; ?>
      </div>
      <?php if (!empty($o['meta_html'])): ?><div class="titlebar-meta"><?= $o['meta_html'] ?></div><?php endif; ?>
      <div class="titlebar-row">
        <nav class="crumbs" aria-label="Навигация">
          <a class="crumb-home" href="<?= e(url('/forum/')) ?>" title="Форумы"><?= icon('home') ?></a>
          <?php $last = count($crumbs) - 1; foreach ($crumbs as $i => $c): ?>
            <span class="crumb-sep"><?= icon('chevron-right') ?></span>
            <?php if ($i === $last): ?><a class="crumb current" href="<?= e($c[1]) ?>"><?= e($c[0]) ?></a><?php else: ?><a class="crumb" href="<?= e($c[1]) ?>"><?= e($c[0]) ?></a><?php endif; ?>
          <?php endforeach; ?>
        </nav>
        <button class="btn-round" type="button" data-theme-toggle title="Светлая / тёмная тема"><?= icon('toggle') ?></button>
        <button class="btn-round" type="button" data-wide-toggle title="Широкая страница"><?= icon('contrast') ?></button>
      </div>
    </section>

    <?php if (setting('announcement')): ?>
    <div class="announcement"><?= icon('megaphone') ?><div><?= bbcode(setting('announcement')) ?></div></div>
    <?php endif; ?>
    <?php if (is_banned()): ?>
    <div class="flash flash-error"><?= icon('ban') ?><div>Ваш аккаунт заблокирован<?= user()['ban_until'] ? ' до ' . e(fdate(user()['ban_until'])) : '' ?>. <?= user()['ban_reason'] ? 'Причина: ' . e(user()['ban_reason']) : '' ?></div></div>
    <?php endif; ?>
    <?php foreach (flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= icon($f['type'] === 'success' ? 'check' : ($f['type'] === 'error' ? 'alert' : 'info')) ?><div><?= e($f['message']) ?></div></div>
    <?php endforeach; ?>

    <div class="layout<?= $right ? ' has-right' : '' ?>">
      <main class="main">
<?php
}

function forum_footer()
{
    $o = $GLOBALS['wrp_layout'] ?? [];
    $right = $o['right'] ?? true;
    ?>
      </main>
      <?php if ($right): ?>
      <aside class="rightbar">
        <?php forum_right_widgets(); ?>
      </aside>
      <?php endif; ?>
    </div>

    <footer class="footer">
      <div class="footer-links">
        <a href="<?= e(url('/')) ?>">Главная</a>
        <a href="<?= e(url('/forum/')) ?>">Форум</a>
        <a href="<?= e(url('/wiki/')) ?>">База знаний</a>
        <a href="<?= e(url('/start.php')) ?>">Начать игру</a>
        <?php foreach (social_links() as $s): ?><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['title']) ?></a><?php endforeach; ?>
      </div>
      <div class="footer-copy">&copy; <?= date('Y') ?> <?= e(setting('site_name')) ?> · <?= e(setting('footer_text')) ?></div>
    </footer>
  </div>
</div>

<div class="scroll-btns">
  <button type="button" data-scroll="up" aria-label="Наверх"><?= icon('chevron-up') ?></button>
  <button type="button" data-scroll="down" aria-label="Вниз"><?= icon('chevron-down') ?></button>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($o['js'] ?? [] as $js): ?>
<script src="<?= e(asset('js/' . $js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

// Адрес из меню: внутренние пути начинаются с /, остальное как есть
function nav_link_href($u)
{
    $u = (string)$u;
    if ($u !== '' && $u[0] === '/' && !str_starts_with($u, '//')) {
        return url($u);
    }
    return $u;
}

function social_links()
{
    $out = [];
    $map = ['discord_url' => ['Discord', 'discord'], 'vk_url' => ['ВКонтакте', 'vk'], 'telegram_url' => ['Telegram', 'telegram'], 'youtube_url' => ['YouTube', 'youtube']];
    foreach ($map as $k => $v) {
        $link = trim((string)setting($k));
        if ($link !== '' && preg_match('~^https?://~i', $link)) {
            $out[] = ['title' => $v[0], 'icon' => $v[1], 'url' => $link];
        }
    }
    return $out;
}

// Правая колонка: вход, быстрая навигация, сервер, онлайн, статистика
function forum_right_widgets()
{
    $u = user();
    if (!$u): ?>
    <div class="widget">
      <h3 class="widget-title">Вход</h3>
      <form method="post" action="<?= e(url('/forum/login.php')) ?>" class="login-box">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e(current_url()) ?>">
        <label class="label" for="w-login">Имя пользователя или email:</label>
        <input class="input" id="w-login" name="login" autocomplete="username" required>
        <label class="label" for="w-pass">Пароль:</label>
        <input class="input" id="w-pass" type="password" name="password" autocomplete="current-password" required>
        <a class="login-forgot" href="<?= e(url('/forum/lost-password.php')) ?>">Забыли свой пароль?</a>
        <label class="check"><input type="checkbox" name="remember" value="1" checked><span>Запомнить меня</span></label>
        <button class="btn btn-white btn-pill btn-center" type="submit"><?= icon('lock') ?> Войти</button>
      </form>
      <div class="login-register">
        <div class="muted small">У Вас ещё нет учётной записи?</div>
        <a class="btn btn-black btn-pill" href="<?= e(url('/forum/register.php')) ?>">Зарегистрируйтесь</a>
      </div>
    </div>
    <?php endif;

    $quick = quick_nav_nodes();
    if ($quick): ?>
    <div class="widget">
      <h3 class="widget-title">Быстрая навигация</h3>
      <div class="quick-nav">
        <?php foreach ($quick as $n): ?>
        <a class="btn btn-white btn-pill" href="<?= e(node_url($n)) ?>"><?= e($n['title']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif;

    $st = server_status(); ?>
    <div class="widget server-widget">
      <h3 class="widget-title">Игровой сервер</h3>
      <div class="server-line">
        <span class="status-dot <?= $st['online'] ? 'on' : 'off' ?>"></span>
        <b><?= e($st['hostname'] ?: setting('server_name')) ?></b>
      </div>
      <?php if ($st['online']): ?>
      <div class="server-online"><b><?= (int)$st['players'] ?></b> / <?= (int)$st['max'] ?> <span class="muted">игроков</span></div>
      <div class="meter"><span style="width:<?= $st['max'] ? min(100, round($st['players'] * 100 / $st['max'])) : 0 ?>%"></span></div>
      <?php else: ?>
      <div class="muted small"><?= $st['checked'] ? 'Сервер сейчас выключен' : 'Статус не проверяется' ?></div>
      <?php endif; ?>
      <button class="btn btn-ghost btn-sm btn-block" type="button" data-copy="<?= e(server_address()) ?>"><?= icon('copy') ?> <?= e(server_address()) ?></button>
    </div>
    <?php
    $on = online_list(); ?>
    <div class="widget">
      <h3 class="widget-title"><a href="<?= e(url('/forum/online.php')) ?>">Пользователи онлайн</a></h3>
      <?php if ($on['users']): ?>
      <div class="online-names">
        <?php $parts = []; foreach ($on['users'] as $ou) { $parts[] = user_link($ou); } echo implode(', ', $parts); ?><?= $on['more'] ? '<br><span class="muted">...и ещё ' . num($on['more']) . '.</span>' : '' ?>
      </div>
      <?php else: ?>
      <div class="muted small">Сейчас никого нет.</div>
      <?php endif; ?>
      <div class="online-total muted small">Всего: <?= num($on['total']) ?> (пользователей: <?= num($on['members']) ?>, гостей: <?= num($on['guests']) ?>)</div>
    </div>
    <?php
    $s = forum_stats(); ?>
    <div class="widget">
      <h3 class="widget-title">Статистика форума</h3>
      <dl class="stats-list">
        <dt>Темы:</dt><dd><?= num($s['threads']) ?></dd>
        <dt>Сообщения:</dt><dd><?= num($s['posts']) ?></dd>
        <dt>Пользователи:</dt><dd><?= num($s['users']) ?></dd>
        <dt>Новый пользователь:</dt><dd><?= $s['newest'] ? user_link($s['newest']) : '-' ?></dd>
      </dl>
    </div>
    <?php
}

function server_address()
{
    $port = (int)setting('server_port');
    return setting('server_ip') . ($port ? ':' . $port : '');
}
