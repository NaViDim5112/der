<?php
// Вход на форум с игрового устройства (ноутбук, компьютер, планшет в доме) без пароля.
// Ссылку формирует и подписывает сервер игры:
//   /forum/game-login.php?nick=Имя_Фамилия&ts=ВРЕМЯ&nonce=СЛУЧАЙНО&device=laptop&sig=ПОДПИСЬ[&return=/forum/...]
// Формат подписи и пример на Pawn - docs/INGAME.md. Ссылка одноразовая и живёт 2 минуты.
require __DIR__ . '/../../app/bootstrap.php';

if (!rate_ok('game_sso', 30, 600)) {
    abort(429, 'Слишком много попыток входа из игры. Подождите 10 минут.');
}
try {
    $r = ingame_sso_verify($_GET);
} catch (PDOException $e) {
    error_log((string)$e);
    $r = 'Вход из игры пока не готов: администратору нужно нажать «Обновить базу» в админ-панели.';
}
if (is_string($r)) {
    rate_hit('game_sso');
    abort(400, $r);
}
list($nick, $device, $return) = $r;
ingame_device_set($device);

$u = db_one('SELECT id FROM users WHERE game_nick = :n', ['n' => $nick]);
if ($u) {
    $u = user_by_id($u['id']);
    if (uid() !== (int)$u['id']) {
        // Двухфакторная защита и другие проверки входа действуют и здесь
        $next = hook_first('login_intercept', $u, false, $return);
        if ($next !== null) {
            redirect($next);
        }
        login_user($u, false);
        flash('success', 'Вы вошли на форум как ' . $u['username'] . '.');
    }
    redirect($return);
}

// Ник ещё не привязан: если на форуме уже вошли - привязываем сразу, иначе после входа или регистрации
if (is_logged()) {
    $err = ingame_link_nick(uid(), $nick);
    flash($err === '' ? 'success' : 'error', $err === '' ? 'Игровой аккаунт ' . $nick . ' привязан к форуму.' : $err);
    redirect($return);
}
$_SESSION['ingame_link'] = ['nick' => $nick, 'until' => time() + 900];

forum_header([
    'title' => 'Вход из игры',
    'crumbs' => [['Вход из игры', url('/forum/game-login.php')]],
    'right' => false,
]);
?>
<section class="card ingame-link">
  <h2 class="card-title"><?= icon('gamepad') ?> Игровой аккаунт <?= e($nick) ?></h2>
  <p>Этот игровой аккаунт ещё не привязан к форуму. Войдите в свой аккаунт форума или зарегистрируйтесь, и мы привяжем ник <b><?= e($nick) ?></b> автоматически. Потом с игровых устройств вход будет без пароля.</p>
  <div class="form-actions">
    <a class="btn btn-accent btn-pill" href="<?= e(url('/forum/login.php', ['return' => $return])) ?>"><?= icon('login') ?> Войти</a>
    <a class="btn btn-pill btn-outline" href="<?= e(url('/forum/register.php')) ?>"><?= icon('user-plus') ?> Зарегистрироваться</a>
  </div>
  <p class="muted small">Привязка действует 15 минут. Если не успеете, откройте форум с устройства в игре ещё раз.</p>
</section>
<?php
forum_footer();
