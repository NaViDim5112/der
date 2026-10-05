<?php
require __DIR__ . '/../../app/bootstrap.php';

if (is_logged()) {
    redirect(url('/forum/'));
}

$open = setting('registration_open') === '1';
$rulesUrl = acc_setting_node_url('rules_node_id');
$errors = [];
$v = ['username' => '', 'email' => '', 'agree' => false];

if ($open && is_post()) {
    csrf_check();
    $v['username'] = acc_clean_line(input('username'));
    $v['email'] = input('email');
    $v['agree'] = input_bool('agree');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $captchaOk = acc_captcha_check('register', input('captcha'));

    if (!rate_ok('register', 3, 3600)) {
        $errors['_'] = 'С вашего адреса недавно уже регистрировались. Попробуйте снова через час.';
    } elseif ((string)($_POST['website'] ?? '') !== '') {
        // Скрытое поле заполняют только боты
        $errors['_'] = 'Проверка не пройдена. Обновите страницу и попробуйте ещё раз.';
    } else {
        if ($e = acc_username_error($v['username'])) {
            $errors['username'] = $e;
        }
        if ($e = acc_email_error($v['email'])) {
            $errors['email'] = $e;
        }
        if ($e = acc_password_error($pass, $v['username'])) {
            $errors['password'] = $e;
        } elseif ($pass !== $pass2) {
            $errors['password2'] = 'Пароли не совпадают.';
        }
        if (!$captchaOk) {
            $errors['captcha'] = 'Неверный ответ. Попробуйте ещё раз.';
        }
        if (!$v['agree']) {
            $errors['agree'] = 'Нужно принять правила проекта.';
        }
    }

    if (!$errors) {
        try {
            $id = db_insert('users', [
                'username' => $v['username'],
                'email' => $v['email'],
                'password_hash' => password_make($pass),
                'group_id' => WRP_USER_GROUP,
                'created_at' => now(),
                'last_activity' => now(),
                'last_ip' => client_ip(),
            ]);
        } catch (PDOException $ex) {
            // Одновременная регистрация с тем же именем или email
            $id = 0;
            $errors['_'] = 'Это имя или email только что заняли. Выберите другие.';
        }
        if ($id) {
            rate_hit('register');
            login_user(user_by_id($id), true);
            flash('success', 'Добро пожаловать, ' . $v['username'] . '! Регистрация завершена.');
            redirect(url('/forum/'));
        }
    }
}

$question = $open ? acc_captcha_question('register', is_post()) : '';

forum_header([
    'title' => 'Регистрация',
    'crumbs' => [['Регистрация', url('/forum/register.php')]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<?php if (!$open): ?>
<div class="auth-wrap auth-wrap-wide">
  <div class="card notice-card">
    <div class="notice-icon"><?= icon('lock') ?></div>
    <div>
      <h2>Регистрация временно закрыта</h2>
      <p class="muted">Администрация проекта приостановила регистрацию новых аккаунтов. Загляните позже или следите за новостями.</p>
      <a class="btn btn-white btn-pill" href="<?= e(url('/forum/')) ?>"><?= icon('chats') ?> На форум</a>
    </div>
  </div>
</div>
<?php else: ?>
<div class="auth-wrap auth-wrap-wide">
  <div class="card auth-card">
    <h2 class="card-title">Регистрация на форуме</h2>
    <?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($errors['_']) ?></div></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('/forum/register.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="label" for="reg-username">Имя пользователя</label>
        <input class="input<?= acc_invalid($errors, 'username') ?>" id="reg-username" name="username" value="<?= e($v['username']) ?>" maxlength="24" autocomplete="username" required autofocus>
        <?= acc_field_error($errors, 'username') ?>
        <div class="hint">Рекомендуем ник как в игре: Имя_Фамилия. Его будет видно в темах и сообщениях.</div>
      </div>
      <div class="form-row">
        <label class="label" for="reg-email">Email</label>
        <input class="input<?= acc_invalid($errors, 'email') ?>" id="reg-email" type="email" name="email" value="<?= e($v['email']) ?>" maxlength="191" autocomplete="email" required>
        <?= acc_field_error($errors, 'email') ?>
        <div class="hint">Не показывается другим пользователям. По нему тоже можно входить.</div>
      </div>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="reg-password">Пароль</label>
          <input class="input<?= acc_invalid($errors, 'password') ?>" id="reg-password" type="password" name="password" maxlength="128" autocomplete="new-password" required data-pw-meter="reg-meter">
          <?= acc_field_error($errors, 'password') ?>
        </div>
        <div class="form-row">
          <label class="label" for="reg-password2">Повтор пароля</label>
          <input class="input<?= acc_invalid($errors, 'password2') ?>" id="reg-password2" type="password" name="password2" maxlength="128" autocomplete="new-password" required>
          <?= acc_field_error($errors, 'password2') ?>
        </div>
      </div>
      <div class="acc-pw-meter" id="reg-meter" hidden><div class="meter"><span></span></div><span class="hint"></span></div>
      <div class="hint acc-hint-top">Не меньше 8 символов. Не используйте пароль от игрового аккаунта и других сайтов.</div>
      <div class="acc-hp" aria-hidden="true">
        <label>Не заполняйте это поле <input name="website" tabindex="-1" autocomplete="off"></label>
      </div>
      <div class="form-row acc-captcha">
        <label class="label" for="reg-captcha">Проверка: <?= e($question) ?></label>
        <input class="input<?= acc_invalid($errors, 'captcha') ?>" id="reg-captcha" name="captcha" inputmode="numeric" maxlength="4" autocomplete="off" required>
        <?= acc_field_error($errors, 'captcha') ?>
      </div>
      <div class="form-row">
        <label class="check"><input type="checkbox" name="agree" value="1"<?= $v['agree'] ? ' checked' : '' ?>><span>Я прочитал(а) и принимаю <?php if ($rulesUrl): ?><a href="<?= e($rulesUrl) ?>" target="_blank" rel="noopener">правила проекта</a><?php else: ?>правила проекта<?php endif; ?></span></label>
        <?= acc_field_error($errors, 'agree') ?>
      </div>
      <button class="btn btn-white btn-pill btn-lg btn-block" type="submit"><?= icon('user-plus') ?> Зарегистрироваться</button>
    </form>
    <div class="auth-alt">
      <span class="muted">Уже есть учётная запись?</span>
      <a class="btn btn-black btn-pill" href="<?= e(url('/forum/login.php')) ?>"><?= icon('login') ?> Войти</a>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
forum_footer();
