<?php
require __DIR__ . '/../../app/bootstrap.php';

if (is_logged()) {
    flash('info', 'Вы уже вошли. Сменить пароль можно в настройках безопасности.');
    redirect(url('/forum/account.php', ['tab' => 'security']));
}

$techUrl = acc_setting_node_url('tech_node_id');
$discord = trim((string)setting('discord_url'));
if ($discord !== '' && !preg_match('~^https?://~i', $discord)) {
    $discord = '';
}
$gameOn = game_enabled();
$mailOn = mail_enabled() && mail_links_ok() && email_confirm_ready();
$errors = [];
$v = ['username' => '', 'game_nick' => '', 'login' => ''];

// Восстановление по email: ответ всегда одинаковый, чтобы по нему нельзя было узнать, есть ли такой аккаунт
if ($mailOn && is_post() && input('method') === 'email') {
    csrf_check();
    $v['login'] = acc_clean_line(input('login'));
    if ($v['login'] === '') {
        $errors['login'] = 'Введите email или имя пользователя.';
    } elseif (!rate_ok('lost_email', 5, 900)) {
        $errors['_mail'] = 'Слишком много попыток. Подождите 15 минут и попробуйте снова.';
    } else {
        rate_hit('lost_email');
        $u = strpos($v['login'], '@') !== false
            ? db_one('SELECT id FROM users WHERE email = :e', ['e' => $v['login']])
            : user_by_name($v['login']);
        $u = $u ? user_by_id($u['id']) : null;
        if ($u && filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL) && !user_token_recent($u['id'], 'reset', 300)) {
            $raw = user_token_create($u['id'], 'reset', 3600);
            $site = (string)setting('site_name');
            mail_send($u['email'], 'Восстановление пароля - ' . $site,
                "Здравствуйте, " . $u['username'] . "!\n\nКто-то (возможно, вы) запросил восстановление пароля на форуме " . $site
                . ". Чтобы задать новый пароль, откройте ссылку (действует 1 час):\n" . site_origin() . url('/forum/reset-password.php', ['token' => $raw])
                . "\n\nЕсли вы не запрашивали восстановление, просто удалите письмо: пароль останется прежним.\n\n" . $site);
        }
        flash('success', 'Если аккаунт с такими данными есть, мы отправили на его почту письмо со ссылкой. Проверьте входящие и папку «Спам».');
        redirect(url('/forum/lost-password.php'));
    }
}

if ($gameOn && is_post() && input('method') !== 'email') {
    csrf_check();
    $v['username'] = acc_clean_line(input('username'));
    $v['game_nick'] = acc_clean_line(input('game_nick'));
    $gamePass = (string)($_POST['game_password'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!rate_ok('lost_password', 5, 900)) {
        $errors['_'] = 'Слишком много попыток. Подождите 15 минут и попробуйте снова.';
    } else {
        if ($v['username'] === '') {
            $errors['username'] = 'Введите имя пользователя на форуме.';
        }
        if ($v['game_nick'] === '') {
            $errors['game_nick'] = 'Введите игровой ник.';
        }
        if ($gamePass === '') {
            $errors['game_password'] = 'Введите пароль от игрового аккаунта.';
        }
        if ($e = acc_password_error($pass, $v['username'])) {
            $errors['password'] = $e;
        } elseif ($pass !== $pass2) {
            $errors['password2'] = 'Пароли не совпадают.';
        }
    }

    if (!$errors) {
        rate_hit('lost_password');
        $u = user_by_name($v['username']);
        $match = $u && !empty($u['game_nick']) && mb_strtolower($u['game_nick']) === mb_strtolower($v['game_nick']);
        $ok = false;
        $gameError = '';
        if ($match) {
            list($ok, $gameError) = acc_game_call('game_check_password', [$u['game_nick'], $gamePass]);
        }
        if ($gameError !== '') {
            $errors['_'] = $gameError;
        } elseif (!$match || !$ok) {
            // Не уточняем, что именно не совпало
            $errors['_'] = 'Данные не совпадают. Проверьте ник на форуме, игровой ник и игровой пароль. Восстановление работает, только если игровой аккаунт был привязан в личном кабинете.';
        } else {
            db_update('users', ['password_hash' => password_make($pass)], 'id = :id', ['id' => (int)$u['id']]);
            db_exec('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => (int)$u['id']]);
            flash('success', 'Пароль изменён. Войдите с новым паролем.');
            redirect(url('/forum/login.php'));
        }
    }
}

forum_header([
    'title' => 'Восстановление пароля',
    'crumbs' => [['Вход', url('/forum/login.php')], ['Восстановление пароля', url('/forum/lost-password.php')]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<div class="auth-wrap auth-wrap-wide">
  <div class="card auth-card">
    <h2 class="card-title">Забыли пароль?</h2>
    <?php if ($mailOn): ?>
    <h3 class="acc-subtitle"><?= icon('mail') ?> Восстановить по email</h3>
    <p class="muted small">Укажите почту или имя пользователя: пришлём ссылку, по которой можно задать новый пароль.</p>
    <?php if (!empty($errors['_mail'])): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($errors['_mail']) ?></div></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('/forum/lost-password.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="method" value="email">
      <div class="form-row">
        <label class="label" for="lp-login">Email или имя пользователя</label>
        <input class="input<?= acc_invalid($errors, 'login') ?>" id="lp-login" name="login" value="<?= e($v['login']) ?>" maxlength="191" autocomplete="email" required>
        <?= acc_field_error($errors, 'login') ?>
      </div>
      <button class="btn btn-white btn-pill btn-lg btn-block" type="submit"><?= icon('send') ?> Прислать ссылку</button>
    </form>
    <h3 class="acc-subtitle">Другие способы</h3>
    <?php endif; ?>
    <div class="acc-steps">
      <div class="acc-step">
        <div class="acc-step-icon"><?= icon('shield') ?></div>
        <div>
          <b>Через администрацию</b>
          <p class="muted">Пароль от форума может сбросить администрация проекта. Создайте тему в техническом разделе (можно с другого аккаунта или попросить друга) или напишите нам в Discord. Укажите ник на форуме и email, который был указан при регистрации. Администрация никогда не спрашивает ваш пароль.</p>
          <div class="form-actions">
            <?php if ($techUrl): ?><a class="btn btn-white btn-pill btn-sm" href="<?= e($techUrl) ?>"><?= icon('wrench') ?> Технический раздел</a><?php endif; ?>
            <?php if ($discord): ?><a class="btn btn-black btn-pill btn-sm" href="<?= e($discord) ?>" target="_blank" rel="noopener"><?= icon('discord') ?> Discord</a><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (!$gameOn): ?>
      <div class="acc-step">
        <div class="acc-step-icon"><?= icon('gamepad') ?></div>
        <div>
          <b>Через игровой аккаунт</b>
          <p class="muted">Скоро здесь можно будет восстановить пароль с помощью привязанного игрового аккаунта. Пока эта возможность не подключена.</p>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($gameOn): ?>
    <h3 class="acc-subtitle"><?= icon('gamepad') ?> Восстановить через игровой аккаунт</h3>
    <p class="muted small">Работает, если игровой аккаунт привязан к форуму в личном кабинете. Введите данные от игры и придумайте новый пароль для форума.</p>
    <?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($errors['_']) ?></div></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('/forum/lost-password.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="lp-username">Имя пользователя на форуме</label>
          <input class="input<?= acc_invalid($errors, 'username') ?>" id="lp-username" name="username" value="<?= e($v['username']) ?>" maxlength="32" autocomplete="username" required>
          <?= acc_field_error($errors, 'username') ?>
        </div>
        <div class="form-row">
          <label class="label" for="lp-nick">Игровой ник</label>
          <input class="input<?= acc_invalid($errors, 'game_nick') ?>" id="lp-nick" name="game_nick" value="<?= e($v['game_nick']) ?>" maxlength="24" placeholder="Имя_Фамилия" autocomplete="off" required>
          <?= acc_field_error($errors, 'game_nick') ?>
        </div>
      </div>
      <div class="form-row">
        <label class="label" for="lp-gpass">Пароль от игрового аккаунта</label>
        <input class="input<?= acc_invalid($errors, 'game_password') ?>" id="lp-gpass" type="password" name="game_password" maxlength="128" autocomplete="off" required>
        <?= acc_field_error($errors, 'game_password') ?>
      </div>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="lp-pass">Новый пароль для форума</label>
          <input class="input<?= acc_invalid($errors, 'password') ?>" id="lp-pass" type="password" name="password" maxlength="128" autocomplete="new-password" required data-pw-meter="lp-meter">
          <?= acc_field_error($errors, 'password') ?>
        </div>
        <div class="form-row">
          <label class="label" for="lp-pass2">Повтор нового пароля</label>
          <input class="input<?= acc_invalid($errors, 'password2') ?>" id="lp-pass2" type="password" name="password2" maxlength="128" autocomplete="new-password" required>
          <?= acc_field_error($errors, 'password2') ?>
        </div>
      </div>
      <div class="acc-pw-meter" id="lp-meter" hidden><div class="meter"><span></span></div><span class="hint"></span></div>
      <button class="btn btn-white btn-pill btn-lg btn-block" type="submit"><?= icon('unlock') ?> Сменить пароль</button>
    </form>
    <?php endif; ?>

    <div class="auth-alt">
      <span class="muted">Вспомнили пароль?</span>
      <a class="btn btn-black btn-pill" href="<?= e(url('/forum/login.php')) ?>"><?= icon('login') ?> Войти</a>
    </div>
  </div>
</div>
<?php
forum_footer();
