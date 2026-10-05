<?php
require __DIR__ . '/../../app/bootstrap.php';

require_login();

$myId = uid();
$tabs = [
    'profile' => ['Информация', 'user'],
    'signature' => ['Подпись', 'edit'],
    'security' => ['Безопасность', 'shield'],
    'following' => ['Подписки', 'users'],
    'ignored' => ['Игнорирование', 'eye-off'],
];
$tab = query_str('tab', 'profile');
if (!isset($tabs[$tab])) {
    $tab = 'profile';
}
$tabUrl = function ($t) {
    return url('/forum/account.php', ['tab' => $t]);
};
$canTitle = is_staff() || user_level() >= 20;
$avatarMax = acc_upload_limit(ACC_AVATAR_MAX_BYTES);
$coverMax = acc_upload_limit(ACC_COVER_MAX_BYTES);
$errors = [];
$me = user();
$v = [
    'custom_title' => (string)$me['custom_title'],
    'location' => (string)$me['location'],
    'about' => (string)$me['about'],
    'signature' => (string)$me['signature'],
    'email' => '',
];

// Файл больше post_max_size: PHP отдаёт пустой $_POST, токен не доходит
if (is_post() && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'Файл слишком большой для загрузки. Аватар - до ' . acc_bytes_text($avatarMax) . ', обложка - до ' . acc_bytes_text($coverMax) . '.');
    redirect($tabUrl('profile'));
}

// Проверка текущего пароля с ограничением попыток
$checkPassword = function ($plain) use ($me) {
    if (!rate_ok('account_password', 10, 900)) {
        return 'Слишком много неверных попыток. Подождите 15 минут.';
    }
    if ($plain === '' || !password_verify($plain, $me['password_hash'])) {
        rate_hit('account_password');
        return 'Неверный текущий пароль.';
    }
    return '';
};

if (is_post()) {
    csrf_check();
    $action = input('action');
    $contentActions = ['avatar_upload', 'avatar_delete', 'cover_upload', 'cover_delete', 'profile_save', 'signature_save', 'status'];
    if (is_banned() && in_array($action, $contentActions, true)) {
        flash('error', 'Пока аккаунт заблокирован, менять профиль нельзя.');
        redirect($action === 'status' ? acc_member_url($myId) : $tabUrl($tab));
    }

    switch ($action) {
        case 'status':
            $s = mb_substr(acc_clean_line(input('status_text')), 0, 140);
            db_update('users', ['status_text' => $s !== '' ? $s : null], 'id = :id', ['id' => $myId]);
            flash('success', $s !== '' ? 'Статус обновлён.' : 'Статус очищен.');
            redirect(acc_referer_path(acc_member_url($myId)));
            break;

        case 'avatar_upload':
            $err = '';
            $tmp = acc_upload_take('avatar', $avatarMax, $err);
            $name = $tmp !== '' ? acc_avatar_from_path($tmp, $myId, $err) : '';
            if ($name === '') {
                flash('error', $err);
            } else {
                db_update('users', ['avatar' => $name], 'id = :id', ['id' => $myId]);
                acc_upload_remove('avatars', $me['avatar']);
                flash('success', 'Аватар обновлён.');
            }
            redirect($tabUrl('profile'));
            break;

        case 'avatar_delete':
            if ($me['avatar']) {
                db_update('users', ['avatar' => null], 'id = :id', ['id' => $myId]);
                acc_upload_remove('avatars', $me['avatar']);
                flash('success', 'Аватар удалён.');
            }
            redirect($tabUrl('profile'));
            break;

        case 'cover_upload':
            $err = '';
            $tmp = acc_upload_take('cover', $coverMax, $err);
            $name = $tmp !== '' ? acc_cover_from_path($tmp, $myId, $err) : '';
            if ($name === '') {
                flash('error', $err);
            } else {
                db_update('users', ['cover' => $name], 'id = :id', ['id' => $myId]);
                acc_upload_remove('covers', $me['cover']);
                flash('success', 'Обложка профиля обновлена.');
            }
            redirect($tabUrl('profile'));
            break;

        case 'cover_delete':
            if ($me['cover']) {
                db_update('users', ['cover' => null], 'id = :id', ['id' => $myId]);
                acc_upload_remove('covers', $me['cover']);
                flash('success', 'Обложка удалена.');
            }
            redirect($tabUrl('profile'));
            break;

        case 'profile_save':
            $tab = 'profile';
            $v['location'] = acc_clean_line(input('location'));
            $v['about'] = input_text('about');
            if ($canTitle) {
                $v['custom_title'] = acc_clean_line(input('custom_title'));
                if (mb_strlen($v['custom_title']) > 40) {
                    $errors['custom_title'] = 'Звание - не больше 40 символов.';
                }
            }
            if (mb_strlen($v['location']) > 64) {
                $errors['location'] = 'Не больше 64 символов.';
            }
            if (mb_strlen($v['about']) > 3000) {
                $errors['about'] = 'Текст «О себе» - не больше 3000 символов (сейчас ' . num(mb_strlen($v['about'])) . ').';
            }
            if (!$errors) {
                $data = [
                    'location' => $v['location'] !== '' ? $v['location'] : null,
                    'about' => $v['about'] !== '' ? $v['about'] : null,
                ];
                if ($canTitle) {
                    $data['custom_title'] = $v['custom_title'] !== '' ? $v['custom_title'] : null;
                }
                db_update('users', $data, 'id = :id', ['id' => $myId]);
                flash('success', 'Профиль сохранён.');
                redirect($tabUrl('profile'));
            }
            break;

        case 'signature_save':
            $tab = 'signature';
            $v['signature'] = input_text('signature');
            if (mb_strlen($v['signature']) > 500) {
                $errors['signature'] = 'Подпись - не больше 500 символов (сейчас ' . num(mb_strlen($v['signature'])) . ').';
            } elseif (substr_count($v['signature'], "\n") > 8) {
                $errors['signature'] = 'В подписи не больше 9 строк.';
            }
            if (!$errors) {
                db_update('users', ['signature' => $v['signature'] !== '' ? $v['signature'] : null], 'id = :id', ['id' => $myId]);
                flash('success', 'Подпись сохранена.');
                redirect($tabUrl('signature'));
            }
            break;

        case 'email_save':
            $tab = 'security';
            $v['email'] = input('email');
            if ($e = $checkPassword((string)($_POST['current_password'] ?? ''))) {
                $errors['email_password'] = $e;
            }
            if (mb_strtolower($v['email']) === mb_strtolower((string)$me['email'])) {
                $errors['email'] = 'Это и так ваш текущий email.';
            } elseif ($e = acc_email_error($v['email'], $myId)) {
                $errors['email'] = $e;
            }
            if (!$errors) {
                db_update('users', ['email' => $v['email']], 'id = :id', ['id' => $myId]);
                flash('success', 'Email изменён.');
                redirect($tabUrl('security'));
            }
            break;

        case 'password_save':
            $tab = 'security';
            $new = (string)($_POST['new_password'] ?? '');
            $new2 = (string)($_POST['new_password2'] ?? '');
            if ($e = $checkPassword((string)($_POST['current_password'] ?? ''))) {
                $errors['current_password'] = $e;
            }
            if ($e = acc_password_error($new, $me['username'])) {
                $errors['new_password'] = $e;
            } elseif ($new !== $new2) {
                $errors['new_password2'] = 'Пароли не совпадают.';
            } elseif (password_verify($new, $me['password_hash'])) {
                $errors['new_password'] = 'Новый пароль совпадает с текущим.';
            }
            if (!$errors) {
                $remember = !empty($_COOKIE['wrp_remember']);
                db_update('users', ['password_hash' => password_make($new)], 'id = :id', ['id' => $myId]);
                db_exec('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => $myId]);
                // Новый хэш завершает все прочие сеансы, текущий переподписываем
                login_user(user_by_id($myId), $remember);
                flash('success', 'Пароль изменён. На других устройствах нужно будет войти заново.');
                redirect($tabUrl('security'));
            }
            break;

        case 'logout_all':
            $tab = 'security';
            $plain = (string)($_POST['current_password'] ?? '');
            if ($e = $checkPassword($plain)) {
                $errors['logout_password'] = $e;
            } else {
                $remember = !empty($_COOKIE['wrp_remember']);
                // Тот же пароль с новой солью: старые сеансы перестают совпадать с хэшем
                db_update('users', ['password_hash' => password_make($plain)], 'id = :id', ['id' => $myId]);
                db_exec('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => $myId]);
                login_user(user_by_id($myId), $remember);
                flash('success', 'Вы вышли на всех остальных устройствах. Этот браузер остаётся в аккаунте.');
                redirect($tabUrl('security'));
            }
            break;

        case 'unfollow':
            db_exec('DELETE FROM user_follows WHERE user_id = :u AND follow_user_id = :t', ['u' => $myId, 't' => input_int('user_id')]);
            flash('success', 'Вы отписались.');
            redirect($tabUrl('following'));
            break;

        case 'ignore_add':
            $tab = 'ignored';
            $name = acc_clean_line(input('username'));
            $target = $name !== '' ? user_by_name($name) : null;
            if (!$target) {
                $errors['ignore'] = 'Пользователь «' . $name . '» не найден.';
            } elseif ((int)$target['id'] === $myId) {
                $errors['ignore'] = 'Нельзя игнорировать самого себя.';
            } elseif (!empty($target['is_staff'])) {
                $errors['ignore'] = 'Администрацию проекта игнорировать нельзя.';
            } else {
                db_exec('INSERT IGNORE INTO user_ignores (user_id, ignored_user_id, created_at) VALUES (:u, :t, :c)', ['u' => $myId, 't' => (int)$target['id'], 'c' => now()]);
                flash('success', 'Сообщения ' . $target['username'] . ' теперь скрыты.');
                redirect($tabUrl('ignored'));
            }
            break;

        case 'unignore':
            db_exec('DELETE FROM user_ignores WHERE user_id = :u AND ignored_user_id = :t', ['u' => $myId, 't' => input_int('user_id')]);
            flash('success', 'Пользователь убран из списка игнорирования.');
            redirect($tabUrl('ignored'));
            break;

        default:
            abort(400, 'Неизвестное действие.');
    }
}

$me = user();

forum_header([
    'title' => 'Настройки аккаунта',
    'crumbs' => [['Ваш аккаунт', $tabUrl('profile')], [$tabs[$tab][0], $tabUrl($tab)]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<div class="acc-account">
  <nav class="acc-side card" aria-label="Разделы аккаунта">
    <a class="acc-side-head" href="<?= e(acc_member_url($myId)) ?>">
      <?= avatar($me, 'm') ?>
      <span><b><?= e($me['username']) ?></b><small>Открыть профиль</small></span>
    </a>
    <div class="acc-side-title">Ваш аккаунт</div>
    <?php foreach (['profile', 'signature'] as $t): ?>
    <a class="acc-side-link<?= $tab === $t ? ' active' : '' ?>" href="<?= e($tabUrl($t)) ?>"><?= icon($tabs[$t][1]) ?><span><?= e($tabs[$t][0]) ?></span></a>
    <?php endforeach; ?>
    <div class="acc-side-title">Настройки</div>
    <?php foreach (['security', 'following', 'ignored'] as $t): ?>
    <a class="acc-side-link<?= $tab === $t ? ' active' : '' ?>" href="<?= e($tabUrl($t)) ?>"><?= icon($tabs[$t][1]) ?><span><?= e($tabs[$t][0]) ?></span></a>
    <?php endforeach; ?>
    <div class="acc-side-title">Прочее</div>
    <a class="acc-side-link" href="<?= e(url('/forum/conversations.php')) ?>"><?= icon('mail') ?><span>Личные сообщения</span></a>
    <a class="acc-side-link" href="<?= e(url('/cabinet/')) ?>"><?= icon('gamepad') ?><span>Личный кабинет</span></a>
  </nav>

  <div class="acc-account-main">
<?php if ($tab === 'profile'): ?>
    <div class="acc-two">
      <div class="acc-two-main">
        <section class="card">
          <h2 class="card-title">Аватар</h2>
          <div class="acc-upload">
            <div class="acc-upload-preview" data-avatar-preview><?= avatar($me, 'xl') ?></div>
            <div class="acc-upload-body">
              <form method="post" enctype="multipart/form-data" action="<?= e($tabUrl('profile')) ?>" class="acc-upload-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="avatar_upload">
                <label class="acc-file">
                  <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required data-file-preview="avatar" data-max="<?= (int)$avatarMax ?>">
                  <span class="btn btn-outline btn-sm"><?= icon('image') ?> Выбрать файл</span>
                  <span class="acc-file-name muted small">Файл не выбран</span>
                </label>
                <button class="btn btn-white btn-sm" type="submit"><?= icon('download') ?> Загрузить</button>
              </form>
              <div class="hint">JPG, PNG, GIF или WEBP до <?= e(acc_bytes_text($avatarMax)) ?>. Картинка обрежется по центру до квадрата 256 x 256.</div>
              <?php if ($me['avatar']): ?>
              <form method="post" action="<?= e($tabUrl('profile')) ?>" data-confirm="Удалить аватар?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="avatar_delete">
                <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?> Удалить аватар</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="card">
          <h2 class="card-title">Обложка профиля</h2>
          <div class="acc-cover-edit<?= $me['cover'] ? '' : ' acc-cover-default' ?>" data-cover-preview><?php if ($me['cover']): ?><img src="<?= e(user_cover_url($me)) ?>" alt=""><?php endif; ?></div>
          <form method="post" enctype="multipart/form-data" action="<?= e($tabUrl('profile')) ?>" class="acc-upload-form mt-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cover_upload">
            <label class="acc-file">
              <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" required data-file-preview="cover" data-max="<?= (int)$coverMax ?>">
              <span class="btn btn-outline btn-sm"><?= icon('image') ?> Выбрать файл</span>
              <span class="acc-file-name muted small">Файл не выбран</span>
            </label>
            <button class="btn btn-white btn-sm" type="submit"><?= icon('download') ?> Загрузить</button>
            <?php if ($me['cover']): ?>
            <button class="btn btn-danger btn-sm" type="submit" form="cover-delete-form"><?= icon('trash') ?> Удалить</button>
            <?php endif; ?>
          </form>
          <div class="hint mt-1">JPG, PNG или WEBP до <?= e(acc_bytes_text($coverMax)) ?>. Лучший размер - 1600 x 400, картинка обрежется по центру до пропорций 4:1.</div>
          <?php if ($me['cover']): ?>
          <form method="post" action="<?= e($tabUrl('profile')) ?>" id="cover-delete-form" data-confirm="Удалить обложку профиля?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cover_delete">
          </form>
          <?php endif; ?>
        </section>

        <section class="card">
          <h2 class="card-title">Информация</h2>
          <form method="post" class="form" action="<?= e($tabUrl('profile')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile_save">
            <div class="form-grid">
              <div class="form-row">
                <label class="label" for="acc-title">Звание под ником</label>
                <?php if ($canTitle): ?>
                <input class="input<?= acc_invalid($errors, 'custom_title') ?>" id="acc-title" name="custom_title" value="<?= e($v['custom_title']) ?>" maxlength="40" placeholder="<?= e($me['group_name']) ?>" data-live="title">
                <?= acc_field_error($errors, 'custom_title') ?>
                <div class="hint">Пусто - показывается название группы.</div>
                <?php else: ?>
                <input class="input" id="acc-title" value="<?= e(acc_user_title($me)) ?>" disabled>
                <div class="hint">Своё звание доступно лидерам и администрации.</div>
                <?php endif; ?>
              </div>
              <div class="form-row">
                <label class="label" for="acc-location">Откуда</label>
                <input class="input<?= acc_invalid($errors, 'location') ?>" id="acc-location" name="location" value="<?= e($v['location']) ?>" maxlength="64" placeholder="Например: Москва" data-live="location">
                <?= acc_field_error($errors, 'location') ?>
              </div>
            </div>
            <div class="form-row">
              <label class="label" for="acc-about">О себе</label>
              <textarea class="textarea" id="acc-about" name="about" rows="6" maxlength="3000" data-editor><?= e($v['about']) ?></textarea>
              <?= acc_field_error($errors, 'about') ?>
              <div class="hint">До 3000 символов, можно BB-коды. Показывается на вкладке «Информация» в профиле.</div>
            </div>
            <div class="form-actions"><button class="btn btn-white btn-pill" type="submit"><?= icon('check') ?> Сохранить</button></div>
          </form>
        </section>
      </div>

      <aside class="acc-two-side">
        <div class="acc-preview card">
          <div class="acc-preview-label">Так вас видят другие</div>
          <div class="acc-preview-cover<?= $me['cover'] ? '' : ' acc-cover-default' ?>" data-cover-preview><?php if ($me['cover']): ?><img src="<?= e(user_cover_url($me)) ?>" alt=""><?php endif; ?></div>
          <div class="acc-preview-body">
            <div class="acc-preview-avatar" data-avatar-preview><?= avatar($me, 'xl') ?></div>
            <div class="acc-preview-name" style="color:<?= e($me['group_color']) ?>"><?= e($me['username']) ?></div>
            <div class="acc-preview-title"><span data-live-out="title" data-default="<?= e($me['group_name']) ?>"><?= e(acc_user_title($me)) ?></span><span data-live-wrap="location"<?= $v['location'] === '' ? ' hidden' : '' ?>> · Из <span data-live-out="location"><?= e($v['location']) ?></span></span></div>
            <div class="acc-preview-banners"><?= user_banners($me) ?></div>
            <dl class="acc-preview-stats">
              <div><dt>Сообщения</dt><dd><?= num($me['posts_count']) ?></dd></div>
              <div><dt>Реакции</dt><dd><?= num($me['likes_received']) ?></dd></div>
              <div><dt>Баллы</dt><dd><?= num(user_points($me)) ?></dd></div>
            </dl>
          </div>
          <a class="btn btn-ghost btn-sm btn-block" href="<?= e(acc_member_url($myId)) ?>"><?= icon('user') ?> Открыть профиль</a>
        </div>
      </aside>
    </div>

<?php elseif ($tab === 'signature'): ?>
    <section class="card">
      <h2 class="card-title">Подпись</h2>
      <p class="muted">Подпись показывается под каждым вашим сообщением на форуме. До 500 символов и 9 строк, можно BB-коды.</p>
      <form method="post" class="form" action="<?= e($tabUrl('signature')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="signature_save">
        <div class="form-row">
          <textarea class="textarea" name="signature" rows="5" maxlength="500" data-editor data-count="500"><?= e($v['signature']) ?></textarea>
          <?= acc_field_error($errors, 'signature') ?>
        </div>
        <div class="form-actions"><button class="btn btn-white btn-pill" type="submit"><?= icon('check') ?> Сохранить подпись</button></div>
      </form>
    </section>
    <section class="card">
      <h2 class="card-title">Текущая подпись</h2>
      <?php if (trim((string)$me['signature']) !== ''): ?>
      <div class="acc-signature-demo"><div class="bb"><?= bbcode($me['signature']) ?></div></div>
      <?php else: ?>
      <div class="muted">Подпись пока не задана.</div>
      <?php endif; ?>
    </section>

<?php elseif ($tab === 'security'): ?>
    <?php $devices = (int)db_val('SELECT COUNT(*) FROM remember_tokens WHERE user_id = :u AND expires_at > :t', ['u' => $myId, 't' => now()]); ?>
    <section class="card">
      <h2 class="card-title">Email</h2>
      <p class="muted">Текущий email: <b><?= e($me['email']) ?></b>. Его видите только вы и администрация.</p>
      <form method="post" class="form" action="<?= e($tabUrl('security')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email_save">
        <div class="form-grid">
          <div class="form-row">
            <label class="label" for="sec-email">Новый email</label>
            <input class="input<?= acc_invalid($errors, 'email') ?>" id="sec-email" type="email" name="email" value="<?= e($v['email']) ?>" maxlength="191" autocomplete="email" required>
            <?= acc_field_error($errors, 'email') ?>
          </div>
          <div class="form-row">
            <label class="label" for="sec-email-pass">Текущий пароль</label>
            <input class="input<?= acc_invalid($errors, 'email_password') ?>" id="sec-email-pass" type="password" name="current_password" autocomplete="current-password" required>
            <?= acc_field_error($errors, 'email_password') ?>
          </div>
        </div>
        <div class="form-actions"><button class="btn btn-white btn-pill" type="submit"><?= icon('mail') ?> Сменить email</button></div>
      </form>
    </section>

    <section class="card">
      <h2 class="card-title">Пароль</h2>
      <form method="post" class="form" action="<?= e($tabUrl('security')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password_save">
        <div class="form-row acc-narrow">
          <label class="label" for="sec-cur">Текущий пароль</label>
          <input class="input<?= acc_invalid($errors, 'current_password') ?>" id="sec-cur" type="password" name="current_password" autocomplete="current-password" required>
          <?= acc_field_error($errors, 'current_password') ?>
        </div>
        <div class="form-grid">
          <div class="form-row">
            <label class="label" for="sec-new">Новый пароль</label>
            <input class="input<?= acc_invalid($errors, 'new_password') ?>" id="sec-new" type="password" name="new_password" maxlength="128" autocomplete="new-password" required data-pw-meter="sec-meter">
            <?= acc_field_error($errors, 'new_password') ?>
          </div>
          <div class="form-row">
            <label class="label" for="sec-new2">Повтор нового пароля</label>
            <input class="input<?= acc_invalid($errors, 'new_password2') ?>" id="sec-new2" type="password" name="new_password2" maxlength="128" autocomplete="new-password" required>
            <?= acc_field_error($errors, 'new_password2') ?>
          </div>
        </div>
        <div class="acc-pw-meter" id="sec-meter" hidden><div class="meter"><span></span></div><span class="hint"></span></div>
        <div class="hint">После смены пароля этот браузер останется в аккаунте, а на остальных устройствах нужно будет войти заново.</div>
        <div class="form-actions"><button class="btn btn-white btn-pill" type="submit"><?= icon('lock') ?> Сменить пароль</button></div>
      </form>
    </section>

    <section class="card">
      <h2 class="card-title">Сеансы</h2>
      <p class="muted">Запомненных устройств («Запомнить меня»): <b><?= num($devices) ?></b>. Если вы входили с чужого компьютера или подозреваете, что аккаунтом пользуется кто-то ещё, завершите все остальные сеансы и смените пароль.</p>
      <form method="post" class="form" action="<?= e($tabUrl('security')) ?>" data-confirm="Выйти на всех остальных устройствах?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout_all">
        <div class="form-row acc-narrow">
          <label class="label" for="sec-logout-pass">Текущий пароль для подтверждения</label>
          <input class="input<?= acc_invalid($errors, 'logout_password') ?>" id="sec-logout-pass" type="password" name="current_password" autocomplete="current-password" required>
          <?= acc_field_error($errors, 'logout_password') ?>
        </div>
        <div class="form-actions"><button class="btn btn-danger btn-pill" type="submit"><?= icon('logout') ?> Выйти на всех устройствах</button></div>
      </form>
    </section>

<?php elseif ($tab === 'following'): ?>
    <?php $following = acc_follow_list($myId, 'following', 200); $followers = acc_follow_list($myId, 'followers', 200); ?>
    <section class="card">
      <div class="card-head"><h2>Вы подписаны <span class="muted">(<?= num(count($following)) ?>)</span></h2></div>
      <?php if ($following): ?>
      <div class="acc-people">
        <?php foreach ($following as $f): ?>
        <div class="acc-person">
          <a href="<?= e(acc_member_url($f['id'])) ?>"><?= avatar($f, 'm') ?></a>
          <div class="acc-person-body"><?= user_link($f) ?><div class="muted small"><?= e(acc_user_title($f)) ?> · с <?= e(fday($f['followed_at'])) ?></div></div>
          <form method="post" action="<?= e($tabUrl('following')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unfollow">
            <input type="hidden" name="user_id" value="<?= (int)$f['id'] ?>">
            <button class="btn btn-sm" type="submit">Отписаться</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty"><?= icon('users') ?><div>Вы ещё ни на кого не подписаны. Подписаться можно в профиле пользователя.</div></div>
      <?php endif; ?>
    </section>
    <section class="card">
      <div class="card-head"><h2>Ваши подписчики <span class="muted">(<?= num(count($followers)) ?>)</span></h2></div>
      <?php if ($followers): ?>
      <div class="acc-people">
        <?php foreach ($followers as $f): ?>
        <div class="acc-person">
          <a href="<?= e(acc_member_url($f['id'])) ?>"><?= avatar($f, 'm') ?></a>
          <div class="acc-person-body"><?= user_link($f) ?><div class="muted small"><?= e(acc_user_title($f)) ?> · с <?= e(fday($f['followed_at'])) ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty"><?= icon('users') ?><div>На вас пока никто не подписан.</div></div>
      <?php endif; ?>
    </section>

<?php elseif ($tab === 'ignored'): ?>
    <?php $ignored = db_all('SELECT u.id, u.username, u.avatar, u.custom_title, g.name AS group_name, g.color AS group_color, i.created_at AS ignored_at
        FROM user_ignores i JOIN users u ON u.id = i.ignored_user_id JOIN user_groups g ON g.id = u.group_id
        WHERE i.user_id = :u ORDER BY u.username', ['u' => $myId]); ?>
    <section class="card">
      <h2 class="card-title">Игнорирование</h2>
      <p class="muted">Сообщения игнорируемых пользователей на стенах профилей и в личных переписках будут скрыты (их можно раскрыть кликом). Администрацию игнорировать нельзя.</p>
      <form method="post" class="acc-inline-add" action="<?= e($tabUrl('ignored')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ignore_add">
        <input class="input<?= acc_invalid($errors, 'ignore') ?>" name="username" maxlength="32" placeholder="Имя пользователя" required>
        <button class="btn btn-white" type="submit"><?= icon('eye-off') ?> Игнорировать</button>
      </form>
      <?= acc_field_error($errors, 'ignore') ?>
    </section>
    <section class="card">
      <div class="card-head"><h2>Список <span class="muted">(<?= num(count($ignored)) ?>)</span></h2></div>
      <?php if ($ignored): ?>
      <div class="acc-people">
        <?php foreach ($ignored as $f): ?>
        <div class="acc-person">
          <a href="<?= e(acc_member_url($f['id'])) ?>"><?= avatar($f, 'm') ?></a>
          <div class="acc-person-body"><?= user_link($f) ?><div class="muted small">в игноре с <?= e(fday($f['ignored_at'])) ?></div></div>
          <form method="post" action="<?= e($tabUrl('ignored')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unignore">
            <input type="hidden" name="user_id" value="<?= (int)$f['id'] ?>">
            <button class="btn btn-sm" type="submit">Не игнорировать</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty"><?= icon('eye') ?><div>Вы никого не игнорируете.</div></div>
      <?php endif; ?>
    </section>
<?php endif; ?>
  </div>
</div>
<?php
forum_footer();
