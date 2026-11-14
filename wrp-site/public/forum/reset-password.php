<?php
// Новый пароль по ссылке из письма. Токен сразу убирается из адреса (хранится в сессии в виде хэша),
// ссылка одноразовая и действует 1 час. После смены пароля - выход на всех устройствах.
require __DIR__ . '/../../app/bootstrap.php';

$token = query_str('token');
if ($token !== '') {
    $row = email_confirm_ready() ? user_token_find($token, 'reset') : null;
    if (!$row) {
        flash('error', 'Ссылка устарела или уже использована. Запросите новую.');
        redirect(url('/forum/lost-password.php'));
    }
    $_SESSION['reset_hash'] = $row['token_hash'];
    redirect(url('/forum/reset-password.php'));
}

$row = !empty($_SESSION['reset_hash']) ? user_token_find($_SESSION['reset_hash'], 'reset', true) : null;
$u = $row ? user_by_id($row['user_id']) : null;
if (!$row || !$u) {
    unset($_SESSION['reset_hash']);
    flash('error', 'Ссылка устарела или уже использована. Запросите новую.');
    redirect(url('/forum/lost-password.php'));
}
$errors = [];

if (is_post()) {
    csrf_check();
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if ($e = acc_password_error($pass, $u['username'])) {
        $errors['password'] = $e;
    } elseif ($pass !== $pass2) {
        $errors['password2'] = 'Пароли не совпадают.';
    }
    if (!$errors && user_token_use($row)) {
        // Письмо пришло на эту почту - значит, она подтверждена
        db_update('users', ['password_hash' => password_make($pass), 'email_confirmed' => 1], 'id = :id', ['id' => (int)$u['id']]);
        db_exec('DELETE FROM user_tokens WHERE user_id = :u AND purpose = :p AND used_at IS NULL', ['u' => (int)$u['id'], 'p' => 'reset']);
        db_exec('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => (int)$u['id']]);
        unset($_SESSION['reset_hash']);
        flash('success', 'Пароль изменён, на всех устройствах выполнен выход. Войдите с новым паролем.');
        redirect(url('/forum/login.php'));
    }
}

forum_header([
    'title' => 'Новый пароль',
    'crumbs' => [['Вход', url('/forum/login.php')], ['Новый пароль', url('/forum/reset-password.php')]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h2 class="card-title"><?= icon('lock') ?> Новый пароль</h2>
    <p class="muted">Аккаунт <b><?= e($u['username']) ?></b>. Придумайте новый пароль для форума.</p>
    <form method="post" class="form" action="<?= e(url('/forum/reset-password.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="label" for="rp-pass">Новый пароль</label>
        <input class="input<?= acc_invalid($errors, 'password') ?>" id="rp-pass" type="password" name="password" maxlength="128" autocomplete="new-password" required data-pw-meter="rp-meter">
        <?= acc_field_error($errors, 'password') ?>
      </div>
      <div class="form-row">
        <label class="label" for="rp-pass2">Повтор нового пароля</label>
        <input class="input<?= acc_invalid($errors, 'password2') ?>" id="rp-pass2" type="password" name="password2" maxlength="128" autocomplete="new-password" required>
        <?= acc_field_error($errors, 'password2') ?>
      </div>
      <div class="acc-pw-meter" id="rp-meter" hidden><div class="meter"><span></span></div><span class="hint"></span></div>
      <button class="btn btn-white btn-pill btn-lg btn-block" type="submit"><?= icon('unlock') ?> Сохранить пароль</button>
    </form>
  </div>
</div>
<?php
forum_footer();
