<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/users.php');
$groups = groups_all();
$myLevel = user_level();
$errors = [];
$form = null;

// Пользователь из базы (сырой) и с правами
function adm_users_target($id)
{
    $raw = db_one('SELECT * FROM users WHERE id = :id', ['id' => (int)$id]);
    if (!$raw) {
        return [null, null];
    }
    return [$raw, user_by_id($id)];
}

// Можно ли назначить группу: уровень не выше моего (или она уже была у пользователя)
function adm_users_group_ok($gid, array $had)
{
    $groups = groups_all();
    if (!isset($groups[$gid])) {
        return false;
    }
    return (int)$groups[$gid]['level'] <= user_level() || in_array((int)$gid, $had, true);
}

function adm_users_secondary_names(array $ids)
{
    $groups = groups_all();
    $names = [];
    foreach ($ids as $id) {
        if (isset($groups[$id])) {
            $names[] = $groups[$id]['name'];
        }
    }
    return $names ? implode(', ', $names) : 'нет';
}

// ---------- POST ----------

if (is_post()) {
    csrf_check();
    $action = input('action');
    $id = input_int('id');
    list($raw, $target) = adm_users_target($id);
    if (!$raw) {
        flash('error', 'Пользователь не найден - возможно, его уже удалили.');
        redirect($self);
    }
    $isSelf = $id === uid();
    $back = url('/admin/users.php', ['edit' => $id]);
    if ((int)$target['level'] > $myLevel) {
        flash('error', 'Нельзя менять пользователя, у которого уровень выше вашего.');
        redirect($back);
    }

    if ($action === 'ban') {
        if ($isSelf) {
            flash('error', 'Нельзя заблокировать самого себя.');
            redirect($back);
        }
        $reason = input('ban_reason');
        $forever = input_bool('ban_forever');
        $until = null;
        if (!adm_len_ok($reason, 255)) {
            $errors['ban_reason'] = 'Причина слишком длинная (максимум 255 символов).';
        }
        if (!$forever) {
            $ts = strtotime(str_replace('T', ' ', input('ban_until')));
            if (input('ban_until') === '' || !$ts) {
                $errors['ban_until'] = 'Укажите, до какого времени бан, или отметьте «Навсегда».';
            } elseif ($ts <= time()) {
                $errors['ban_until'] = 'Дата окончания бана уже прошла.';
            } else {
                $until = date('Y-m-d H:i:s', $ts);
            }
        }
        if (!$errors) {
            db_update('users', ['is_banned' => 1, 'ban_reason' => $reason !== '' ? $reason : null, 'ban_until' => $until], 'id = :id', ['id' => $id]);
            mod_log('user_ban', 'user', $id, ($until ? 'до ' . date('d.m.Y H:i', strtotime($until)) : 'навсегда') . ($reason !== '' ? '. Причина: ' . $reason : ''));
            flash('success', $raw['username'] . ' заблокирован(а) ' . ($until ? 'до ' . fdate($until) : 'навсегда') . '.');
            redirect($back);
        }
        $form = 'ban';
    }

    if ($action === 'unban') {
        db_update('users', ['is_banned' => 0, 'ban_reason' => null, 'ban_until' => null], 'id = :id', ['id' => $id]);
        mod_log('user_unban', 'user', $id, $raw['ban_reason'] ? 'Была причина: ' . $raw['ban_reason'] : '');
        flash('success', 'Бан с ' . $raw['username'] . ' снят.');
        redirect($back);
    }

    if ($action === 'delete') {
        if ($isSelf) {
            flash('error', 'Нельзя удалить самого себя.');
            redirect($back);
        }
        $posts = (int)db_val('SELECT COUNT(*) FROM posts WHERE user_id = :u', ['u' => $id]);
        $threads = (int)db_val('SELECT COUNT(*) FROM threads WHERE user_id = :u', ['u' => $id]);
        if ($posts || $threads) {
            flash('error', 'У пользователя есть темы или сообщения на форуме - удалить его нельзя. Заблокируйте его вместо удаления.');
            redirect($back);
        }
        adm_upload_delete('avatars', (string)$raw['avatar']);
        adm_upload_delete('covers', (string)($raw['cover'] ?? ''));
        // Реакции пользователя: запомним сообщения, чтобы пересчитать их счётчики
        $likedPosts = array_map('intval', array_column(db_all('SELECT post_id FROM post_likes WHERE user_id = :u', ['u' => $id]), 'post_id'));
        db_exec('DELETE FROM post_likes WHERE user_id = :u', ['u' => $id]);
        if ($likedPosts) {
            $in = db_in($likedPosts, 'p');
            db_exec('UPDATE posts SET likes_count = (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = posts.id) WHERE id IN (' . $in['sql'] . ')', $in['params']);
            foreach (array_column(db_all('SELECT DISTINCT user_id FROM posts WHERE id IN (' . $in['sql'] . ')', $in['params']), 'user_id') as $author) {
                user_rebuild_counts($author);
            }
        }
        // Стена профиля: сообщения автора и сообщения на его стене, комментарии, лайки
        $pp = array_map('intval', array_column(db_all('SELECT id FROM profile_posts WHERE user_id = :a OR profile_user_id = :b', ['a' => $id, 'b' => $id]), 'id'));
        $ppIn = db_in($pp, 'pp');
        $pc = array_map('intval', array_column(db_all('SELECT id FROM profile_comments WHERE user_id = :u OR profile_post_id IN (' . $ppIn['sql'] . ')', array_merge(['u' => $id], $ppIn['params'])), 'id'));
        $pcIn = db_in($pc, 'pc');
        $likedPP = array_map('intval', array_column(db_all("SELECT content_id FROM profile_likes WHERE user_id = :u AND content_type = 'post'", ['u' => $id]), 'content_id'));
        $likedPC = array_map('intval', array_column(db_all("SELECT content_id FROM profile_likes WHERE user_id = :u AND content_type = 'comment'", ['u' => $id]), 'content_id'));
        db_exec('DELETE FROM profile_likes WHERE user_id = :u', ['u' => $id]);
        if ($pp) {
            db_exec("DELETE FROM profile_likes WHERE content_type = 'post' AND content_id IN (" . $ppIn['sql'] . ')', $ppIn['params']);
        }
        if ($pc) {
            db_exec("DELETE FROM profile_likes WHERE content_type = 'comment' AND content_id IN (" . $pcIn['sql'] . ')', $pcIn['params']);
            db_exec('DELETE FROM profile_comments WHERE id IN (' . $pcIn['sql'] . ')', $pcIn['params']);
        }
        if ($pp) {
            db_exec('DELETE FROM profile_posts WHERE id IN (' . $ppIn['sql'] . ')', $ppIn['params']);
        }
        if ($likedPP) {
            $in = db_in($likedPP, 'lp');
            db_exec("UPDATE profile_posts SET likes_count = (SELECT COUNT(*) FROM profile_likes l WHERE l.content_type = 'post' AND l.content_id = profile_posts.id) WHERE id IN (" . $in['sql'] . ')', $in['params']);
        }
        if ($likedPC) {
            $in = db_in($likedPC, 'lc');
            db_exec("UPDATE profile_comments SET likes_count = (SELECT COUNT(*) FROM profile_likes l WHERE l.content_type = 'comment' AND l.content_id = profile_comments.id) WHERE id IN (" . $in['sql'] . ')', $in['params']);
        }
        foreach (['remember_tokens', 'thread_watch', 'thread_reads', 'node_reads', 'node_watch', 'conversation_users'] as $table) {
            db_exec('DELETE FROM `' . $table . '` WHERE user_id = :u', ['u' => $id]);
        }
        db_exec('DELETE FROM alerts WHERE user_id = :u', ['u' => $id]);
        db_exec('UPDATE alerts SET actor_id = NULL WHERE actor_id = :u', ['u' => $id]);
        db_exec('DELETE FROM user_follows WHERE user_id = :a OR follow_user_id = :b', ['a' => $id, 'b' => $id]);
        db_exec('DELETE FROM user_ignores WHERE user_id = :a OR ignored_user_id = :b', ['a' => $id, 'b' => $id]);
        db_exec('UPDATE online SET user_id = NULL WHERE user_id = :u', ['u' => $id]);
        db_exec('UPDATE wiki_articles SET author_id = NULL WHERE author_id = :u', ['u' => $id]);
        db_exec('DELETE FROM users WHERE id = :u', ['u' => $id]);
        mod_log('user_delete', 'user', $id, $raw['username'] . ' (' . $raw['email'] . ')');
        flash('success', 'Пользователь ' . $raw['username'] . ' удалён.');
        redirect($self);
    }

    if ($action === 'save') {
        $had = adm_user_group_ids($raw);
        $form = [
            'username' => input('username'),
            'email' => input('email'),
            'custom_title' => input('custom_title'),
            'game_nick' => input('game_nick'),
            'location' => input('location'),
            'status_text' => input('status_text'),
            'group_id' => $isSelf ? (int)$raw['group_id'] : input_int('group_id'),
            'secondary' => $isSelf ? user_secondary_ids($raw) : adm_post_ints('secondary'),
            'password' => (string)($_POST['password'] ?? ''),
            'remove_avatar' => input_bool('remove_avatar'),
            'remove_cover' => input_bool('remove_cover'),
        ];
        if (!username_valid($form['username'])) {
            $errors['username'] = 'Ник: 3-24 символа, буквы, цифры, _ - . и пробел.';
        } elseif (db_val('SELECT id FROM users WHERE username = :n AND id <> :id', ['n' => $form['username'], 'id' => $id])) {
            $errors['username'] = 'Этот ник уже занят.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || !adm_len_ok($form['email'], 191)) {
            $errors['email'] = 'Укажите правильный email.';
        } elseif (db_val('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $form['email'], 'id' => $id])) {
            $errors['email'] = 'Этот email уже у другого пользователя.';
        }
        if (!adm_len_ok($form['custom_title'], 64)) {
            $errors['custom_title'] = 'Звание слишком длинное (максимум 64 символа).';
        }
        if ($form['game_nick'] !== '') {
            if (!preg_match('~^[A-Za-z0-9_\[\]\.\(\)\$@=]{3,24}$~', $form['game_nick'])) {
                $errors['game_nick'] = 'Игровой ник: 3-24 латинских символа, например John_Smith.';
            } elseif (db_val('SELECT id FROM users WHERE game_nick = :g AND id <> :id', ['g' => $form['game_nick'], 'id' => $id])) {
                $errors['game_nick'] = 'Этот игровой ник уже привязан к другому пользователю.';
            }
        }
        if (!adm_len_ok($form['location'], 64)) {
            $errors['location'] = 'Слишком длинно (максимум 64 символа).';
        }
        if (!adm_len_ok($form['status_text'], 140)) {
            $errors['status_text'] = 'Слишком длинно (максимум 140 символов).';
        }
        if (!$isSelf) {
            $gid = (int)$form['group_id'];
            if ($gid === WRP_GUEST_GROUP || !isset($groups[$gid])) {
                $errors['group_id'] = 'Выберите основную группу.';
            } elseif (!adm_users_group_ok($gid, $had)) {
                $errors['group_id'] = 'Нельзя назначить группу с уровнем выше вашего.';
            }
            $sec = [];
            foreach ($form['secondary'] as $sid) {
                if ($sid === $gid || !isset($groups[$sid]) || $groups[$sid]['is_system']) {
                    continue;
                }
                if (!adm_users_group_ok($sid, $had)) {
                    $errors['secondary'] = 'Нельзя назначить группу «' . $groups[$sid]['name'] . '» - её уровень выше вашего.';
                    continue;
                }
                $sec[] = $sid;
            }
            sort($sec);
            $form['secondary'] = $sec;
        }
        if ($form['password'] !== '' && (strlen($form['password']) < 8 || strlen($form['password']) > 200)) {
            $errors['password'] = 'Пароль - не короче 8 символов.';
        }

        if (!$errors) {
            $data = [
                'username' => $form['username'],
                'email' => $form['email'],
                'custom_title' => adm_null($form['custom_title']),
                'game_nick' => adm_null($form['game_nick']),
                'location' => adm_null($form['location']),
                'status_text' => adm_null($form['status_text']),
            ];
            $changed = [];
            $names = ['username' => 'ник', 'email' => 'email', 'custom_title' => 'звание', 'game_nick' => 'игровой ник', 'location' => 'откуда', 'status_text' => 'статус'];
            foreach ($data as $k => $val) {
                if ((string)$raw[$k] !== (string)$val) {
                    $changed[] = $k === 'username' ? 'ник (' . $raw['username'] . ' -> ' . $val . ')' : $names[$k];
                }
            }
            $groupMsg = '';
            if (!$isSelf) {
                $oldSec = user_secondary_ids($raw);
                sort($oldSec);
                $data['group_id'] = (int)$form['group_id'];
                $data['secondary_groups'] = $form['secondary'] ? implode(',', $form['secondary']) : null;
                if ((int)$raw['group_id'] !== (int)$form['group_id'] || $oldSec !== $form['secondary']) {
                    $groupMsg = 'Основная: ' . ($groups[(int)$raw['group_id']]['name'] ?? '?') . ' -> ' . $groups[(int)$form['group_id']]['name']
                        . '; дополнительные: ' . adm_users_secondary_names($oldSec) . ' -> ' . adm_users_secondary_names($form['secondary']);
                }
            }
            if ($form['password'] !== '') {
                $data['password_hash'] = password_make($form['password']);
            }
            if ($form['remove_avatar'] && $raw['avatar']) {
                adm_upload_delete('avatars', (string)$raw['avatar']);
                $data['avatar'] = null;
            }
            if ($form['remove_cover'] && !empty($raw['cover'])) {
                adm_upload_delete('covers', (string)$raw['cover']);
                $data['cover'] = null;
            }
            db_update('users', $data, 'id = :id', ['id' => $id]);

            if ($changed) {
                mod_log('user_edit', 'user', $id, 'Изменено: ' . implode(', ', $changed));
            }
            if ($groupMsg !== '') {
                mod_log('user_group', 'user', $id, $groupMsg);
            }
            if ($form['password'] !== '') {
                db_exec('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => $id]);
                mod_log('user_password', 'user', $id, 'Пароль изменён администратором');
                if ($isSelf) {
                    // свой пароль: остаёмся в системе в этой сессии
                    $_SESSION['pw'] = substr(hash('sha256', $data['password_hash']), 0, 16);
                }
            }
            if (isset($data['avatar'])) {
                mod_log('user_avatar', 'user', $id, 'Аватар удалён');
            }
            if (array_key_exists('cover', $data)) {
                mod_log('user_cover', 'user', $id, 'Обложка удалена');
            }
            flash('success', 'Изменения пользователя ' . $form['username'] . ' сохранены.' . ($form['password'] !== '' ? ' Пароль изменён - на других устройствах нужно войти заново.' : ''));
            redirect($back);
        }
    }
}

// ---------- Страница пользователя ----------

$editId = is_post() ? input_int('id') : query_int('edit');
if ($editId) {
    list($raw, $target) = adm_users_target($editId);
    if (!$raw) {
        flash('error', 'Пользователь не найден.');
        redirect($self);
    }
    $id = (int)$raw['id'];
    $isSelf = $id === uid();
    $canEdit = (int)$target['level'] <= $myLevel;
    $had = adm_user_group_ids($raw);
    $v = is_array($form) ? $form : [
        'username' => $raw['username'], 'email' => $raw['email'], 'custom_title' => (string)$raw['custom_title'],
        'game_nick' => (string)$raw['game_nick'], 'location' => (string)($raw['location'] ?? ''), 'status_text' => (string)($raw['status_text'] ?? ''),
        'group_id' => (int)$raw['group_id'], 'secondary' => user_secondary_ids($raw), 'password' => '',
        'remove_avatar' => false, 'remove_cover' => false,
    ];
    $postsAll = (int)db_val('SELECT COUNT(*) FROM posts WHERE user_id = :u', ['u' => $id]);
    $threadsAll = (int)db_val('SELECT COUNT(*) FROM threads WHERE user_id = :u', ['u' => $id]);
    $likesGiven = (int)db_val('SELECT COUNT(*) FROM post_likes WHERE user_id = :u', ['u' => $id]);
    $profilePosts = (int)db_val('SELECT COUNT(*) FROM profile_posts WHERE profile_user_id = :u AND is_deleted = 0', ['u' => $id]);
    $isOnline = $raw['last_activity'] && strtotime($raw['last_activity']) > time() - 900;
    $banVals = $form === 'ban' ? ['reason' => input('ban_reason'), 'until' => input('ban_until'), 'forever' => input_bool('ban_forever')] : ['reason' => '', 'until' => '', 'forever' => false];
    admin_header('Пользователь: ' . $raw['username'], 'users', [[$raw['username'], current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<div class="card">
  <div class="adm-profile-head">
    <?= avatar($raw, 'l') ?>
    <div class="adm-cell-text">
      <div><span class="adm-cell-title" style="color:<?= e($target['group_color']) ?>"><?= e($raw['username']) ?></span>
        <?php if ($raw['custom_title']): ?><span class="muted"> · <?= e($raw['custom_title']) ?></span><?php endif; ?></div>
      <div class="tags mt-1">
        <?= group_badge($target) ?>
        <?php foreach (user_secondary_ids($raw) as $sid): if (isset($groups[$sid])): ?><?= adm_group_badge($groups[$sid]) ?><?php endif; endforeach; ?>
        <?php if ($raw['is_banned']): ?><span class="tag tag-danger"><?= icon('ban') ?> Бан<?= $raw['ban_until'] ? ' до ' . e(fdate($raw['ban_until'])) : ' навсегда' ?></span><?php endif; ?>
        <?php if ($isOnline): ?><span class="tag tag-success"><?= icon('eye') ?> Онлайн</span><?php endif; ?>
        <?php if ($isSelf): ?><span class="tag tag-info">Это вы</span><?php endif; ?>
      </div>
    </div>
    <div class="btn-row" style="margin-left:auto">
      <a class="btn btn-sm" href="<?= e(url('/forum/member.php', ['id' => $id])) ?>" target="_blank"><?= icon('user') ?> Профиль</a>
      <a class="btn btn-sm" href="<?= e(url('/forum/search.php', ['user' => $raw['username']])) ?>" target="_blank"><?= icon('search') ?> Сообщения</a>
      <a class="btn btn-sm btn-ghost" href="<?= e($self) ?>"><?= icon('chevron-left') ?> К списку</a>
    </div>
  </div>
</div>

<?php if (!$canEdit): ?>
<div class="flash flash-error"><?= icon('lock') ?><div>Уровень этого пользователя (<?= (int)$target['level'] ?>) выше вашего (<?= $myLevel ?>) - менять его может только старшая администрация.</div></div>
<?php endif; ?>

<div class="adm-cols adm-cols-wide">
  <form method="post" action="<?= e($self) ?>" class="adm-form" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $id ?>">
    <fieldset class="adm-fieldset"<?= $canEdit ? '' : ' disabled' ?>>
    <div class="adm-form">
    <div class="card">
      <div class="adm-card-head"><div><h2><?= icon('user') ?> Учётная запись</h2></div></div>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="username">Ник на форуме <span class="req">*</span></label>
          <input class="input<?= isset($errors['username']) ? ' is-invalid' : '' ?>" id="username" name="username" value="<?= e($v['username']) ?>" maxlength="24" required>
          <?= adm_err($errors, 'username') ?>
        </div>
        <div class="form-row">
          <label class="label" for="email">Email <span class="req">*</span></label>
          <input class="input<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="email" name="email" type="email" value="<?= e($v['email']) ?>" maxlength="191" required>
          <?= adm_err($errors, 'email') ?>
        </div>
        <div class="form-row">
          <label class="label" for="custom_title">Звание под ником</label>
          <input class="input<?= isset($errors['custom_title']) ? ' is-invalid' : '' ?>" id="custom_title" name="custom_title" value="<?= e($v['custom_title']) ?>" maxlength="64" placeholder="Например: Ex - Governor">
          <div class="hint">Пусто - показывается название группы.</div>
          <?= adm_err($errors, 'custom_title') ?>
        </div>
        <div class="form-row">
          <label class="label" for="game_nick">Игровой ник</label>
          <input class="input<?= isset($errors['game_nick']) ? ' is-invalid' : '' ?>" id="game_nick" name="game_nick" value="<?= e($v['game_nick']) ?>" maxlength="24" placeholder="Имя_Фамилия">
          <div class="hint">Привязанный аккаунт на сервере. Пусто - не привязан.</div>
          <?= adm_err($errors, 'game_nick') ?>
        </div>
        <div class="form-row">
          <label class="label" for="location">Откуда</label>
          <input class="input<?= isset($errors['location']) ? ' is-invalid' : '' ?>" id="location" name="location" value="<?= e($v['location']) ?>" maxlength="64">
          <?= adm_err($errors, 'location') ?>
        </div>
        <div class="form-row">
          <label class="label" for="status_text">Статус</label>
          <input class="input<?= isset($errors['status_text']) ? ' is-invalid' : '' ?>" id="status_text" name="status_text" value="<?= e($v['status_text']) ?>" maxlength="140">
          <div class="hint">Короткая фраза в профиле.</div>
          <?= adm_err($errors, 'status_text') ?>
        </div>
      </div>
      <?php if ($raw['avatar'] || !empty($raw['cover'])): ?>
      <div class="adm-opts adm-opts-2">
        <?php if ($raw['avatar']): ?>
        <label class="adm-opt"><input type="checkbox" name="remove_avatar" value="1"<?= adm_chk($v['remove_avatar']) ?>><?= avatar($raw, 's') ?><span><b>Удалить аватар</b><small>Например, если он нарушает правила</small></span></label>
        <?php endif; ?>
        <?php if (!empty($raw['cover'])): ?>
        <label class="adm-opt"><input type="checkbox" name="remove_cover" value="1"<?= adm_chk($v['remove_cover']) ?>><span><b>Удалить обложку</b><small>Картинка в шапке профиля</small></span></label>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="adm-card-head"><div><h2><?= icon('shield') ?> Группы</h2><p>Основная группа задаёт цвет ника. Дополнительные добавляют права и плашки.</p></div></div>
      <?php if ($isSelf): ?><div class="adm-note"><?= icon('lock') ?><div>Свои группы менять нельзя - так вы случайно не лишите себя доступа. Попросите другого администратора.</div></div><?php endif; ?>
      <div class="form-row">
        <label class="label" for="group_id">Основная группа</label>
        <select class="select<?= isset($errors['group_id']) ? ' is-invalid' : '' ?>" id="group_id" name="group_id"<?= $isSelf ? ' disabled' : '' ?>>
          <?php foreach ($groups as $gid => $g): if ($gid === WRP_GUEST_GROUP) { continue; } $ok = adm_users_group_ok($gid, $had); ?>
          <option value="<?= (int)$gid ?>"<?= adm_sel($v['group_id'], $gid) ?><?= $ok ? '' : ' disabled' ?>><?= e($g['name']) ?> (уровень <?= (int)$g['level'] ?>)<?= $ok ? '' : ' - выше вашего' ?></option>
          <?php endforeach; ?>
        </select>
        <?= adm_err($errors, 'group_id') ?>
      </div>
      <div class="form-row">
        <span class="label">Дополнительные группы</span>
        <div class="adm-checkgrid">
          <?php foreach ($groups as $gid => $g): if ($g['is_system'] || (int)$gid === (int)$v['group_id']) { continue; } $ok = adm_users_group_ok($gid, $had); $dis = $isSelf || !$ok; ?>
          <label class="adm-checkpill<?= $dis ? ' is-disabled' : '' ?>"<?= $ok ? '' : ' title="Уровень группы выше вашего"' ?>><input type="checkbox" name="secondary[]" value="<?= (int)$gid ?>"<?= adm_chk(in_array((int)$gid, $v['secondary'], true)) ?><?= $dis ? ' disabled' : '' ?>><?= adm_group_badge($g) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="hint">Например, игрок в группе «Пользователь» и дополнительно «Лидер». Системные группы и основная здесь не показываются.</div>
        <?= adm_err($errors, 'secondary') ?>
      </div>
    </div>

    <div class="card">
      <div class="adm-card-head"><div><h2><?= icon('lock') ?> Новый пароль</h2><p>Заполните, только если нужно сменить пароль (например, пользователь потерял доступ).</p></div></div>
      <div class="form-row">
        <label class="label" for="password">Новый пароль</label>
        <input class="input<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" type="text" minlength="8" maxlength="200" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять">
        <div class="hint">Не короче 8 символов. После смены пользователь выйдет на всех устройствах - сообщите ему новый пароль.</div>
        <?= adm_err($errors, 'password') ?>
      </div>
    </div>

    <div class="adm-savebar">
      <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
      <a class="btn btn-ghost" href="<?= e(url('/admin/users.php', ['edit' => $id])) ?>">Отменить изменения</a>
    </div>
    </div>
    </fieldset>
  </form>

  <div class="adm-main">
    <div class="card">
      <div class="adm-card-head"><div><h2><?= icon('info') ?> Сведения</h2></div></div>
      <dl class="adm-kv">
        <dt>ID</dt><dd><?= $id ?></dd>
        <dt>Регистрация</dt><dd><?= e(fdate($raw['created_at'])) ?></dd>
        <dt>Последний визит</dt><dd><?= $raw['last_activity'] ? e(fdate($raw['last_activity'])) : '<span class="muted">никогда</span>' ?></dd>
        <dt>Последний IP</dt><dd><?php if ($raw['last_ip']): ?><a href="<?= e(url('/admin/users.php', ['q' => $raw['last_ip']])) ?>" title="Найти всех с этим IP"><?= e($raw['last_ip']) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></dd>
        <dt>Сообщения</dt><dd><a href="<?= e(url('/forum/search.php', ['user' => $raw['username']])) ?>"><?= num($raw['posts_count']) ?></a><?= $postsAll > (int)$raw['posts_count'] ? ' <span class="muted small">(всего ' . num($postsAll) . ')</span>' : '' ?></dd>
        <dt>Темы</dt><dd><?= num($raw['threads_count']) ?><?= $threadsAll > (int)$raw['threads_count'] ? ' <span class="muted small">(всего ' . num($threadsAll) . ')</span>' : '' ?></dd>
        <dt>Реакции получено</dt><dd><?= num($raw['likes_received']) ?></dd>
        <dt>Реакций поставил</dt><dd><?= num($likesGiven) ?></dd>
        <dt>Сообщений в профиле</dt><dd><?= num($profilePosts) ?></dd>
      </dl>
    </div>

    <?php if (!$isSelf && $canEdit): ?>
    <div class="card adm-danger" id="ban">
      <div class="adm-card-head"><div><h2><?= icon('ban') ?> Блокировка</h2><p>Заблокированный не может писать на форуме, но может читать.</p></div></div>
      <?php if ($raw['is_banned']): ?>
      <div class="adm-ban-box">
        <dl class="adm-kv">
          <dt>Срок</dt><dd><?= $raw['ban_until'] ? 'до ' . e(fdate($raw['ban_until'])) : 'навсегда' ?></dd>
          <dt>Причина</dt><dd><?= $raw['ban_reason'] ? e($raw['ban_reason']) : '<span class="muted">не указана</span>' ?></dd>
        </dl>
        <form method="post" action="<?= e($self) ?>" data-confirm="Снять бан с <?= e($raw['username']) ?>?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="unban">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-success" type="submit"><?= icon('unlock') ?> Снять бан</button>
        </form>
      </div>
      <?php else: ?>
      <form method="post" action="<?= e($self) ?>#ban" class="form" data-confirm="Заблокировать <?= e($raw['username']) ?>?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ban">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row">
          <label class="label" for="ban_reason">Причина</label>
          <input class="input<?= isset($errors['ban_reason']) ? ' is-invalid' : '' ?>" id="ban_reason" name="ban_reason" maxlength="255" value="<?= e($banVals['reason']) ?>" placeholder="Например: оскорбления (п. 2.1 правил форума)">
          <div class="hint">Пользователь увидит её вверху страниц.</div>
          <?= adm_err($errors, 'ban_reason') ?>
        </div>
        <div class="form-row">
          <label class="label" for="ban_until">До какого времени</label>
          <input class="input<?= isset($errors['ban_until']) ? ' is-invalid' : '' ?>" id="ban_until" name="ban_until" type="datetime-local" value="<?= e($banVals['until']) ?>">
          <div class="btn-row">
            <button class="btn btn-sm btn-ghost" type="button" data-ban-days="1">1 день</button>
            <button class="btn btn-sm btn-ghost" type="button" data-ban-days="3">3 дня</button>
            <button class="btn btn-sm btn-ghost" type="button" data-ban-days="7">7 дней</button>
            <button class="btn btn-sm btn-ghost" type="button" data-ban-days="30">30 дней</button>
          </div>
          <label class="check"><input type="checkbox" name="ban_forever" value="1" data-disables="#ban_until"<?= adm_chk($banVals['forever']) ?>><span>Навсегда</span></label>
          <?= adm_err($errors, 'ban_until') ?>
        </div>
        <div class="form-actions"><button class="btn btn-danger" type="submit"><?= icon('ban') ?> Заблокировать</button></div>
      </form>
      <?php endif; ?>
    </div>

    <div class="card adm-danger">
      <div class="adm-card-head"><div><h2><?= icon('trash') ?> Удаление</h2></div></div>
      <?php if ($postsAll || $threadsAll): ?>
      <div class="adm-note"><?= icon('info') ?><div>У пользователя есть темы или сообщения на форуме (<?= num($threadsAll) ?> / <?= num($postsAll) ?>), поэтому удалить его нельзя. Если он нарушает правила - заблокируйте его.</div></div>
      <?php else: ?>
      <p class="muted small">Аккаунт без тем и сообщений можно удалить полностью: вместе с подписками, оповещениями, реакциями и сообщениями в профиле.</p>
      <form method="post" action="<?= e($self) ?>" data-confirm="Удалить пользователя <?= e($raw['username']) ?> навсегда? Это нельзя отменить.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Удалить пользователя</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$q = query_str('q');
$groupFilter = query_int('group');
$banned = query_str('banned');
$sort = query_str('sort', 'new');
$sorts = [
    'new' => ['Сначала новые', 'u.id DESC'],
    'old' => ['Сначала старые', 'u.id ASC'],
    'name' => ['По нику (А-Я)', 'u.username ASC'],
    'posts' => ['Больше сообщений', 'u.posts_count DESC, u.id DESC'],
    'active' => ['Недавно заходили', 'u.last_activity IS NULL, u.last_activity DESC'],
];
if (!isset($sorts[$sort])) {
    $sort = 'new';
}
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE :q1 OR u.email LIKE :q2 OR u.last_ip LIKE :q3 OR u.game_nick LIKE :q4)';
    $like = adm_like($q);
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
}
if ($groupFilter && isset($groups[$groupFilter])) {
    $where[] = '(u.group_id = :g1 OR FIND_IN_SET(:g2, u.secondary_groups))';
    $params += ['g1' => $groupFilter, 'g2' => (string)$groupFilter];
} else {
    $groupFilter = 0;
}
if ($banned === 'yes') {
    $where[] = 'u.is_banned = 1';
} elseif ($banned === 'no') {
    $where[] = 'u.is_banned = 0';
} else {
    $banned = '';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int)db_val('SELECT COUNT(*) FROM users u' . $whereSql, $params);
$p = paginate($total, 30, query_int('page', 1));
$users = db_all(user_select_sql() . $whereSql . ' ORDER BY ' . $sorts[$sort][1] . ' LIMIT :lim OFFSET :off', array_merge($params, ['lim' => (int)$p['per_page'], 'off' => (int)$p['offset']]));
$filterParams = ['q' => $q, 'group' => $groupFilter ?: null, 'banned' => $banned, 'sort' => $sort !== 'new' ? $sort : null];

admin_header('Пользователи', 'users');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('users') ?> Пользователи</h2>
      <p>Поиск по нику, email, игровому нику или IP. Нажмите на ник, чтобы изменить группу, пароль или заблокировать.</p>
    </div>
  </div>
  <form method="get" action="<?= e($self) ?>" class="adm-filters">
    <div class="form-row">
      <label class="label" for="q">Поиск</label>
      <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Ник, email или IP">
    </div>
    <div class="form-row">
      <label class="label" for="group">Группа</label>
      <select class="select" id="group" name="group" data-autosubmit>
        <option value="0">Все группы</option>
        <?php foreach ($groups as $gid => $g): if ($gid === WRP_GUEST_GROUP) { continue; } ?><option value="<?= (int)$gid ?>"<?= adm_sel($groupFilter, $gid) ?>><?= e($g['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="label" for="banned">Блокировка</label>
      <select class="select" id="banned" name="banned" data-autosubmit>
        <option value="">Все</option>
        <option value="yes"<?= adm_sel($banned, 'yes') ?>>Только заблокированные</option>
        <option value="no"<?= adm_sel($banned, 'no') ?>>Без блокировки</option>
      </select>
    </div>
    <div class="form-row">
      <label class="label" for="sort">Сортировка</label>
      <select class="select" id="sort" name="sort" data-autosubmit>
        <?php foreach ($sorts as $sk => $s): ?><option value="<?= e($sk) ?>"<?= adm_sel($sort, $sk) ?>><?= e($s[0]) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="btn-row">
      <button class="btn btn-white" type="submit"><?= icon('search') ?> Найти</button>
      <?php if ($q !== '' || $groupFilter || $banned !== '' || $sort !== 'new'): ?><a class="btn btn-ghost" href="<?= e($self) ?>">Сбросить</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <?php if (!$users): ?>
  <div class="adm-empty"><?= icon('search') ?>Никого не нашлось. Попробуйте изменить условия поиска.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Пользователь</th><th class="hide-md">Email</th><th>Группа</th><th class="num hide-sm">Сообщ.</th><th class="hide-md">Регистрация</th><th class="hide-sm">Был(а)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $online = $u['last_activity'] && strtotime($u['last_activity']) > time() - 900; $secIds = user_secondary_ids($u); ?>
        <tr class="<?= $u['is_banned'] ? 'is-banned' : '' ?>">
          <td>
            <div class="adm-cell-main">
              <?= avatar($u, 's') ?>
              <div class="adm-cell-text">
                <a class="adm-user-name" href="<?= e(url('/admin/users.php', ['edit' => $u['id']])) ?>" style="color:<?= e($u['group_color']) ?>"><?= e($u['username']) ?></a>
                <?php if ($u['is_banned']): ?><span class="tag tag-danger" title="<?= e($u['ban_reason'] ?: 'Причина не указана') ?>"><?= icon('ban') ?> Бан</span><?php endif; ?>
                <?php if ($online): ?><span class="tag tag-success hide-sm">Онлайн</span><?php endif; ?>
                <?php if ($u['game_nick'] || $u['custom_title']): ?><div class="adm-cell-sub"><?= e($u['custom_title'] ?: '') ?><?= $u['custom_title'] && $u['game_nick'] ? ' · ' : '' ?><?= $u['game_nick'] ? icon('gamepad') . ' ' . e($u['game_nick']) : '' ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="hide-md muted"><?= e($u['email']) ?></td>
          <td><?= group_badge($u) ?><?php if ($secIds): ?> <span class="muted small" title="<?= e(adm_users_secondary_names($secIds)) ?>">+<?= count($secIds) ?></span><?php endif; ?></td>
          <td class="num hide-sm"><?= num($u['posts_count']) ?></td>
          <td class="hide-md nowrap"><?= e(fday($u['created_at'])) ?></td>
          <td class="hide-sm nowrap muted"><?= $u['last_activity'] ? e(fdate($u['last_activity'])) : '-' ?></td>
          <td class="actions"><a class="btn btn-sm" href="<?= e(url('/admin/users.php', ['edit' => $u['id']])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="adm-foot">
    <span class="adm-count">Найдено: <?= num($total) ?></span>
    <?= pagination_html($p, '/admin/users.php', $filterParams) ?>
  </div>
</div>
<?php
admin_footer();
