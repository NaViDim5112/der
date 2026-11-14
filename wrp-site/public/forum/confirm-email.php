<?php
// Подтверждение почты: ссылка из письма (?token=...) или страница «Подтвердите почту» с повторной отправкой письма.
require __DIR__ . '/../../app/bootstrap.php';

$token = query_str('token');
if ($token !== '') {
    $row = email_confirm_ready() ? user_token_find($token, 'confirm') : null;
    if (!$row || !user_token_use($row)) {
        flash('error', 'Ссылка устарела или уже использована. Войдите - и мы предложим отправить письмо ещё раз.');
        redirect(url('/forum/login.php'));
    }
    db_update('users', ['email_confirmed' => 1], 'id = :id', ['id' => (int)$row['user_id']]);
    unset($_SESSION['confirm_uid']);
    $u = user_by_id($row['user_id']);
    if ($u && uid() !== (int)$u['id']) {
        $next = hook_first('login_intercept', $u, false, url('/forum/'));
        if ($next !== null) {
            redirect($next);
        }
        login_user($u, false);
    }
    flash('success', 'Почта подтверждена. Добро пожаловать на форум!');
    redirect(url('/forum/'));
}

// Кому показываем страницу: только что зарегистрировался или пытался войти без подтверждения
$p = $_SESSION['confirm_uid'] ?? null;
$u = is_array($p) && (int)($p['until'] ?? 0) > time() ? user_by_id((int)$p['id']) : null;
if (!$u || !email_unconfirmed($u)) {
    unset($_SESSION['confirm_uid']);
    redirect(url('/forum/login.php'));
}

if (is_post()) {
    csrf_check();
    if (!rate_ok('confirm_resend', 5, 3600) || user_token_recent($u['id'], 'confirm', 300)) {
        flash('error', 'Письмо уже отправлено недавно. Подождите 5 минут и проверьте папку «Спам».');
    } elseif (email_confirm_send($u)) {
        rate_hit('confirm_resend');
        flash('success', 'Письмо отправлено ещё раз.');
    } else {
        rate_hit('confirm_resend');
        flash('error', 'Не удалось отправить письмо. Попробуйте позже или напишите администрации.');
    }
    redirect(url('/forum/confirm-email.php'));
}

// Адрес частично скрыт: страницу видит тот, кто знает пароль, но лишнее показывать незачем
$email = (string)$u['email'];
$at = strpos($email, '@');
$masked = $at > 0 ? mb_substr($email, 0, 1) . str_repeat('*', max(2, $at - 1)) . substr($email, $at) : $email;

forum_header([
    'title' => 'Подтвердите почту',
    'crumbs' => [['Вход', url('/forum/login.php')], ['Подтверждение почты', url('/forum/confirm-email.php')]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
]);
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <h2 class="card-title"><?= icon('mail') ?> Подтвердите почту</h2>
    <p>Мы отправили письмо на <b><?= e($masked) ?></b>. Откройте ссылку из письма: после этого вы сразу войдёте на форум.</p>
    <p class="muted small">Письмо не пришло? Проверьте папку «Спам» и подождите пару минут. Ссылка действует 3 дня.</p>
    <form method="post" action="<?= e(url('/forum/confirm-email.php')) ?>" class="form-actions">
      <?= csrf_field() ?>
      <button class="btn btn-white btn-pill" type="submit"><?= icon('send') ?> Отправить письмо ещё раз</button>
    </form>
  </div>
</div>
<?php
forum_footer();
