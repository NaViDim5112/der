<?php
require __DIR__ . '/../../app/bootstrap.php';

$return = safe_return($_POST['return'] ?? ($_GET['return'] ?? ''), url('/forum/'));
if (is_logged()) {
    redirect($return);
}

$error = '';
$login = '';
if (is_post()) {
    csrf_check();
    $login = input('login');
    $password = (string)($_POST['password'] ?? '');
    if (!rate_ok('login', 10, 900)) {
        $error = 'Слишком много попыток входа. Подождите 15 минут.';
    } else {
        $u = auth_attempt($login, $password);
        if ($u) {
            login_user($u, input_bool('remember'));
            flash('success', 'С возвращением, ' . $u['username'] . '!');
            redirect($return);
        }
        rate_hit('login');
        $error = 'Неверное имя пользователя, email или пароль.';
    }
}

forum_header([
    'title' => 'Вход',
    'crumbs' => [['Вход', url('/forum/login.php')]],
    'right' => false,
    'nav' => '',
]);
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h2 class="card-title">Вход на форум</h2>
    <?php if ($error): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
    <form method="post" class="form login-form" action="<?= e(url('/forum/login.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="return" value="<?= e($return) ?>">
      <div class="form-row">
        <label class="label" for="login">Имя пользователя или email</label>
        <input class="input" id="login" name="login" value="<?= e($login) ?>" autocomplete="username" required autofocus>
      </div>
      <div class="form-row">
        <label class="label" for="password">Пароль</label>
        <input class="input" id="password" type="password" name="password" autocomplete="current-password" required>
      </div>
      <div class="form-row-inline" style="justify-content:space-between">
        <label class="check"><input type="checkbox" name="remember" value="1" checked><span>Запомнить меня</span></label>
        <a class="small" href="<?= e(url('/forum/lost-password.php')) ?>">Забыли пароль?</a>
      </div>
      <button class="btn btn-white btn-pill btn-lg btn-block" type="submit"><?= icon('lock') ?> Войти</button>
    </form>
    <div class="auth-alt">
      <span class="muted">Нет учётной записи?</span>
      <a class="btn btn-black btn-pill" href="<?= e(url('/forum/register.php')) ?>">Зарегистрируйтесь</a>
    </div>
  </div>
</div>
<?php
forum_footer();
