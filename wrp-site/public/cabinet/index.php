<?php
require __DIR__ . '/../../app/bootstrap.php';

require_login();

$myId = uid();
$cabUrl = url('/cabinet/');
$gameOn = game_enabled();
$errors = [];
$nick = '';

if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'game_link') {
        $nick = acc_clean_line(input('game_nick'));
        $gamePass = (string)($_POST['game_password'] ?? '');
        if (!$gameOn) {
            $errors['_'] = 'Привязка игрового аккаунта сейчас недоступна.';
        } elseif (is_banned()) {
            $errors['_'] = 'Пока аккаунт заблокирован, привязать игровой аккаунт нельзя.';
        } elseif (!empty(user()['game_nick'])) {
            $errors['_'] = 'Игровой аккаунт уже привязан. Сначала отвяжите текущий.';
        } elseif (!rate_ok('game_link', 5, 900)) {
            $errors['_'] = 'Слишком много попыток. Подождите 15 минут и попробуйте снова.';
        } else {
            if (!acc_game_nick_valid($nick)) {
                $errors['game_nick'] = 'Ник в игре пишется латиницей в формате Имя_Фамилия (3-24 символа).';
            }
            if ($gamePass === '') {
                $errors['game_password'] = 'Введите пароль от игрового аккаунта.';
            }
            if (!$errors) {
                rate_hit('game_link');
                list($acc, $err) = acc_game_call('game_account', [$nick]);
                $ok = false;
                if ($err === '' && $acc) {
                    list($ok, $err) = acc_game_call('game_check_password', [$nick, $gamePass]);
                }
                if ($err !== '') {
                    $errors['_'] = $err;
                } elseif (!$acc || !$ok) {
                    $errors['_'] = 'Неверный ник или пароль игрового аккаунта.';
                } else {
                    $canonical = (string)($acc[cfg('game_db.col_name')] ?? $nick);
                    if ($canonical === '' || mb_strlen($canonical) > 32) {
                        $canonical = $nick;
                    }
                    if (db_val('SELECT id FROM users WHERE game_nick = :n AND id <> :id', ['n' => $canonical, 'id' => $myId])) {
                        $errors['_'] = 'Этот игровой аккаунт уже привязан к другому пользователю форума. Если это ваш аккаунт, обратитесь к администрации.';
                    } else {
                        try {
                            db_update('users', ['game_nick' => $canonical], 'id = :id', ['id' => $myId]);
                            flash('success', 'Игровой аккаунт ' . $canonical . ' привязан к форуму.');
                            redirect($cabUrl);
                        } catch (PDOException $ex) {
                            $errors['_'] = 'Этот игровой аккаунт уже привязан к другому пользователю форума.';
                        }
                    }
                }
            }
        }
    } elseif ($action === 'game_unlink') {
        if (!empty(user()['game_nick'])) {
            db_update('users', ['game_nick' => null], 'id = :id', ['id' => $myId]);
            flash('success', 'Игровой аккаунт отвязан.');
        }
        redirect($cabUrl);
    } else {
        abort(400, 'Неизвестное действие.');
    }
}

$me = user();
$linked = (string)($me['game_nick'] ?? '');
$stats = null;
$statsError = '';
if ($gameOn && $linked !== '') {
    list($stats, $statsError) = acc_game_call('game_stats', [$linked]);
}
$convUnread = conversations_unread_count();
$st = server_status();
$cover = user_cover_url($me);

forum_header([
    'title' => 'Личный кабинет',
    'crumbs' => [['Личный кабинет', $cabUrl]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<div class="acc-cabinet">
  <aside class="acc-cab-side">
    <section class="card acc-cab-user">
      <div class="acc-preview-cover<?= $cover ? '' : ' acc-cover-default' ?>"><?php if ($cover): ?><img src="<?= e($cover) ?>" alt=""><?php endif; ?></div>
      <div class="acc-preview-body">
        <a class="acc-preview-avatar" href="<?= e(acc_member_url($myId)) ?>"><?= avatar($me, 'xl') ?></a>
        <div class="acc-preview-name"><?= user_link($me) ?></div>
        <div class="acc-preview-title"><?= e(acc_user_title($me)) ?></div>
        <div class="acc-preview-banners"><?= user_banners($me) ?></div>
        <dl class="acc-preview-stats">
          <div><dt>Сообщения</dt><dd><?= num($me['posts_count']) ?></dd></div>
          <div><dt>Реакции</dt><dd><?= num($me['likes_received']) ?></dd></div>
          <div><dt>Баллы</dt><dd><?= num(user_points($me)) ?></dd></div>
        </dl>
        <div class="muted small mt-1">На форуме с <?= e(fday($me['created_at'])) ?></div>
      </div>
      <nav class="acc-cab-links">
        <a href="<?= e(acc_member_url($myId)) ?>"><?= icon('user') ?> Мой профиль</a>
        <a href="<?= e(url('/forum/account.php')) ?>"><?= icon('settings') ?> Настройки</a>
        <a href="<?= e(url('/forum/conversations.php')) ?>"><?= icon('mail') ?> Личные сообщения<?= $convUnread ? '<span class="count-badge">' . (int)$convUnread . '</span>' : '' ?></a>
        <a href="<?= e(url('/forum/find.php', ['type' => 'mine'])) ?>"><?= icon('list') ?> Мои темы</a>
        <a href="<?= e(url('/forum/account.php', ['tab' => 'security'])) ?>"><?= icon('shield') ?> Безопасность</a>
      </nav>
    </section>
  </aside>

  <div class="acc-cab-main">
    <section class="card">
      <div class="acc-game-head">
        <div class="acc-game-icon<?= $gameOn ? '' : ' off' ?>"><?= icon('gamepad') ?></div>
        <div>
          <h2>Игровой аккаунт</h2>
          <p><?= !$gameOn ? 'Связь форума с игровым сервером' : ($linked !== '' ? 'Персонаж привязан к вашему аккаунту на форуме' : 'Привяжите персонажа с сервера к форуму, чтобы видеть его статистику здесь.') ?></p>
        </div>
      </div>
      <?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($errors['_']) ?></div></div><?php endif; ?>

      <?php if (!$gameOn): ?>
      <div class="acc-step">
        <div class="acc-step-icon"><?= icon('info') ?></div>
        <div>
          <b>Привязка игрового аккаунта пока не подключена</b>
          <p class="muted">Когда администрация включит её, здесь можно будет привязать ник из игры к форуму и смотреть статистику персонажа.<?= $linked !== '' ? ' Сейчас к форуму привязан ник ' . e($linked) . '.' : '' ?></p>
        </div>
      </div>
      <?php if (can_admin()): ?>
      <div class="acc-admin-hint">
        <b><?= icon('shield') ?> Подсказка для администрации</b>
        Чтобы игроки могли привязать игровой аккаунт, включите game_db в config/config.php. Сайт будет только читать базу мода worldrp - создайте для него отдельного пользователя MySQL с правом SELECT на таблицу accounts и сверьте имена колонок с модом.
        <ul>
          <li><b>table</b>, <b>col_name</b>, <b>col_pass</b>, <b>col_salt</b> - таблица и колонки с ником, хэшем пароля и солью;</li>
          <li><b>hash</b> - как мод хранит пароль (auto, bcrypt, sha256_salt, whirlpool, md5...);</li>
          <li><b>stats</b> - какие колонки показать игрокам в кабинете и как их подписать.</li>
        </ul>
        <pre class="acc-code">'game_db' => [
    'enabled'  => true,
    'host'     => '127.0.1.19',
    'name'     => 'worldrp',
    'user'     => 'site_reader',   // только SELECT на accounts
    'pass'     => '...',
    'charset'  => 'cp1251',
    'table'    => 'accounts',
    'col_name' => 'name',
    'col_pass' => 'password',
    'col_salt' => 'salt',
    'hash'     => 'auto',
    'stats'    => ['level' => 'Уровень', 'money' => 'Наличные', 'bank' => 'Банк'],
],</pre>
      </div>
      <?php endif; ?>

      <?php elseif ($linked === ''): ?>
      <form method="post" class="form" action="<?= e($cabUrl) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="game_link">
        <div class="form-grid">
          <div class="form-row">
            <label class="label" for="gm-nick">Ник в игре</label>
            <input class="input<?= acc_invalid($errors, 'game_nick') ?>" id="gm-nick" name="game_nick" value="<?= e($nick) ?>" maxlength="24" placeholder="Имя_Фамилия" autocomplete="off" required>
            <?= acc_field_error($errors, 'game_nick') ?>
          </div>
          <div class="form-row">
            <label class="label" for="gm-pass">Пароль от игрового аккаунта</label>
            <input class="input<?= acc_invalid($errors, 'game_password') ?>" id="gm-pass" type="password" name="game_password" maxlength="128" autocomplete="off" required>
            <?= acc_field_error($errors, 'game_password') ?>
          </div>
        </div>
        <div class="hint">Пароль только проверяется по игровой базе и нигде на сайте не сохраняется. Один игровой аккаунт можно привязать только к одному пользователю форума.</div>
        <div class="form-actions"><button class="btn btn-accent btn-pill" type="submit"><?= icon('link') ?> Привязать аккаунт</button></div>
      </form>

      <?php else: ?>
      <div class="acc-server-row">
        <span class="acc-game-nick"><?= icon('check') ?> <?= e($linked) ?></span>
        <form method="post" action="<?= e($cabUrl) ?>" data-confirm="Отвязать игровой аккаунт <?= e($linked) ?> от форума?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="game_unlink">
          <button class="btn btn-danger btn-sm btn-pill" type="submit"><?= icon('x') ?> Отвязать</button>
        </form>
      </div>
      <div class="mt-2">
        <?php if ($statsError !== ''): ?>
        <div class="flash flash-error"><?= icon('alert') ?><div><?= e($statsError) ?></div></div>
        <?php elseif ($stats === null): ?>
        <div class="flash flash-info"><?= icon('info') ?><div>Аккаунт <?= e($linked) ?> не найден в игровой базе. Возможно, он был переименован или удалён. Отвяжите его и привяжите актуальный.</div></div>
        <?php elseif (!$stats): ?>
        <div class="muted">Статистика персонажа пока не настроена администрацией.</div>
        <?php else: ?>
        <div class="acc-game-stats">
          <?php foreach ($stats as $s): ?>
          <div class="acc-game-stat"><span><?= e($s['label']) ?></span><b><?= e($s['value']) ?></b></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="card acc-server">
      <div class="acc-server-row">
        <div class="acc-server-name"><span class="status-dot <?= $st['online'] ? 'on' : 'off' ?>"></span><?= e($st['hostname'] ?: setting('server_name')) ?></div>
        <span class="tag"><?= $st['online'] ? 'Сервер работает' : ($st['checked'] ? 'Сервер выключен' : 'Статус не проверяется') ?></span>
      </div>
      <?php if ($st['online']): ?>
      <div class="acc-server-online"><b><?= (int)$st['players'] ?></b> / <?= (int)$st['max'] ?> игроков онлайн</div>
      <div class="meter"><span style="width:<?= $st['max'] ? min(100, round($st['players'] * 100 / $st['max'])) : 0 ?>%"></span></div>
      <?php else: ?>
      <div class="acc-server-online">Подключиться можно по адресу ниже, когда сервер будет включён.</div>
      <?php endif; ?>
      <div class="form-actions">
        <button class="btn btn-white btn-pill" type="button" data-copy="<?= e(server_address()) ?>"><?= icon('copy') ?> <?= e(server_address()) ?></button>
        <a class="btn btn-outline btn-pill" href="<?= e(url('/start.php')) ?>"><?= icon('play') ?> Как начать играть</a>
      </div>
    </section>
  </div>
</div>
<?php
forum_footer();
