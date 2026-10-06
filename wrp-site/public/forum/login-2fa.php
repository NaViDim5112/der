<?php
// Второй шаг входа: код из приложения или резервный код (модуль «Сообщество», двухфакторная защита)
require __DIR__ . '/../../app/bootstrap.php';

if (is_logged()) {
    unset($_SESSION['com_tfa_pending']);
    redirect(url('/forum/'));
}

$p = $_SESSION['com_tfa_pending'] ?? null;
$restart = function ($type, $msg) {
    unset($_SESSION['com_tfa_pending']);
    flash($type, $msg);
    redirect(url('/forum/login.php'));
};
if (!is_array($p) || empty($p['uid'])) {
    redirect(url('/forum/login.php'));
}
if ((int)$p['exp'] < time()) {
    $restart('info', 'Время на ввод кода истекло. Войдите ещё раз.');
}
$u = user_by_id($p['uid']);
// Пароль сменили, пока вводили код - начинаем вход заново
if (!$u || !hash_equals((string)$p['pw'], substr(hash('sha256', (string)$u['password_hash']), 0, 16)) || !com_tfa_enabled($u['id'])) {
    $restart('info', 'Войдите ещё раз.');
}

$error = '';
if (is_post()) {
    csrf_check();
    if (input('action') === 'cancel') {
        unset($_SESSION['com_tfa_pending']);
        redirect(url('/forum/login.php'));
    }
    if (!rate_ok('tfa_login', 10, 900)) {
        $error = 'Слишком много попыток. Подождите 15 минут.';
    } else {
        $r = com_tfa_check($u['id'], input('code'), true);
        if ($r['ok']) {
            unset($_SESSION['com_tfa_pending']);
            login_user($u, !empty($p['remember']));
            if ($r['backup']) {
                flash($r['left'] <= 3 ? 'error' : 'info', 'Вы вошли по резервному коду. Осталось кодов: ' . $r['left'] . '. Новые можно создать в настройках, вкладка «Двухфакторная защита».');
            } else {
                flash('success', 'С возвращением, ' . $u['username'] . '!');
            }
            redirect(safe_return($p['return'] ?? '', url('/forum/')));
        }
        rate_hit('tfa_login');
        $p['tries'] = (int)($p['tries'] ?? 0) + 1;
        if ($p['tries'] >= 5) {
            $restart('error', 'Слишком много неверных кодов. Войдите заново.');
        }
        $_SESSION['com_tfa_pending'] = $p;
        $error = $r['reused'] ? 'Этот код уже использован. Дождитесь следующего кода в приложении.' : 'Код не подошёл. Проверьте время на телефоне и попробуйте ещё раз.';
    }
}

$left = max(0, (int)$p['exp'] - time());
forum_header([
    'title' => 'Подтверждение входа',
    'crumbs' => [['Вход', url('/forum/login.php')], ['Код подтверждения', url('/forum/login-2fa.php')]],
    'right' => false,
    'nav' => '',
]);
?>
<div class="auth-wrap">
  <div class="card auth-card com-2fa-card">
    <div class="com-2fa-head">
      <span class="com-tfa-icon is-on"><?= icon('key') ?></span>
      <div>
        <h2 class="card-title">Подтверждение входа</h2>
        <div class="muted small">Аккаунт <b><?= e($u['username']) ?></b> защищён двухфакторной защитой.</div>
      </div>
    </div>
    <?php if ($error): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
    <form method="post" class="form" action="<?= e(url('/forum/login-2fa.php')) ?>">
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="label" for="tfa-code">Код из приложения</label>
        <input class="input com-code-input com-code-big" id="tfa-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required autofocus placeholder="123 456">
        <div class="hint">6 цифр из Google Authenticator, Яндекс Ключа или другого приложения. Нет телефона под рукой - введите резервный код вида <b>abcd-efgh</b>.</div>
      </div>
      <button class="btn btn-white btn-pill btn-lg btn-block" type="submit"><?= icon('check') ?> Войти</button>
    </form>
    <form method="post" action="<?= e(url('/forum/login-2fa.php')) ?>" class="com-2fa-cancel">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <span class="muted small" data-com-countdown="<?= (int)$left ?>">Код нужно ввести в течение <?= (int)ceil($left / 60) ?> мин.</span>
      <button class="btn btn-ghost btn-sm" type="submit">Отмена</button>
    </form>
  </div>
</div>
<?php
forum_footer();
