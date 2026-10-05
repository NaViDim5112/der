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
$errors = [];
$v = ['username' => '', 'game_nick' => ''];

if ($gameOn && is_post()) {
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
