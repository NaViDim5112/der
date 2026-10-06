<?php
// Сообщение: переход к нему, цитата, правка, удаление и восстановление, реакции
require __DIR__ . '/../../app/bootstrap.php';

$action = query_str('action');
if ($action === '' && is_post()) {
    $action = input('action');
}
$id = query_int('id') ?: input_int('id');
$isMod = can_moderate();

// Ответ JSON с ошибкой
$jsonFail = function ($msg, $code = 400, array $extra = []) {
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
};

$post = $id ? fp_post_get($id) : null;
$thread = $post ? thread_get($post['thread_id']) : null;
$node = $thread ? node_get($thread['node_id']) : null;
$visible = $post && fp_post_visible($post, $thread, $node);

// ---------- Переход к сообщению ----------
if ($action === '') {
    if (!$post || !$thread || !$node) {
        abort(404, 'Сообщение не найдено или было удалено.');
    }
    if (!node_can_view($node)) {
        abort(403, 'У вас нет доступа к этому разделу.');
    }
    if (!$visible) {
        abort(404, 'Сообщение не найдено или было удалено.');
    }
    redirect(fp_post_anchor_url($post, $isMod));
}

// ---------- Цитата (JSON) ----------
if ($action === 'quote') {
    if (!$visible) {
        $jsonFail('Сообщение не найдено.', 404);
    }
    json_out(['ok' => true, 'bbcode' => fp_quote_bbcode($post)]);
}

// ---------- Реакция (JSON) ----------
if ($action === 'react') {
    if (!is_post()) {
        $jsonFail('Неверный запрос.');
    }
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        $jsonFail('Сессия устарела, обновите страницу.');
    }
    if (!is_logged()) {
        $jsonFail('Войдите, чтобы оценивать сообщения.', 403, ['login' => url('/forum/login.php', ['return' => $thread ? thread_url($thread['id']) : url('/forum/')])]);
    }
    if (is_banned()) {
        $jsonFail('Ваш аккаунт заблокирован.', 403);
    }
    if (!$visible || $post['is_deleted'] || $thread['is_deleted']) {
        $jsonFail('Сообщение не найдено.', 404);
    }
    if ((int)$post['user_id'] === uid()) {
        $jsonFail('Нельзя оценивать свои сообщения.', 403);
    }
    $reaction = input('reaction');
    if ($reaction !== '' && !isset(fp_reactions()[$reaction])) {
        $jsonFail('Неизвестная реакция.');
    }
    $my = fp_set_reaction($post, $reaction);
    $likes = fp_load_likes([(int)$post['id']]);
    $list = $likes[(int)$post['id']] ?? [];
    json_out([
        'ok' => true,
        'my' => $my,
        'count' => count($list),
        'button' => fp_react_btn_inner($my),
        'html' => fp_reactions_bar((int)$post['id'], $list),
    ]);
}

// Дальше только обычные страницы
if (!$post || !$thread || !$node) {
    abort(404, 'Сообщение не найдено или было удалено.');
}
if (!node_can_view($node)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}
if (!$visible) {
    abort(404, 'Сообщение не найдено или было удалено.');
}
$pid = (int)$post['id'];
$tid = (int)$thread['id'];
$firstId = fp_first_post_id($tid);
$isFirst = $pid === $firstId;

// ---------- Удаление ----------
if ($action === 'delete') {
    require_post();
    if (!$isMod) {
        abort(403, 'Удалять сообщения могут только модераторы.');
    }
    if ($isFirst) {
        if (!$thread['is_deleted']) {
            fp_thread_set_deleted($thread, true);
        }
        flash('success', 'Первое сообщение удалено вместе с темой. Тему видят только модераторы.');
        redirect(node_url($node));
    }
    if (!$post['is_deleted']) {
        db_update('posts', ['is_deleted' => 1], 'id = :id', ['id' => $pid]);
        thread_rebuild($tid);
        node_rebuild($node['id']);
        user_rebuild_counts($post['user_id']);
        mod_log('post_delete', 'post', $pid, $thread['title']);
        hook_fire('post_deleted', $post, $thread, $node);
    }
    flash('success', 'Сообщение удалено.');
    redirect(fp_post_anchor_url($post, true));
}

// ---------- Восстановление ----------
if ($action === 'restore') {
    require_post();
    if (!$isMod) {
        abort(403, 'Восстанавливать сообщения могут только модераторы.');
    }
    if ($post['is_deleted']) {
        db_update('posts', ['is_deleted' => 0], 'id = :id', ['id' => $pid]);
        thread_rebuild($tid);
        node_rebuild($node['id']);
        user_rebuild_counts($post['user_id']);
        mod_log('post_restore', 'post', $pid, $thread['title']);
        hook_fire('post_restored', $post, $thread, $node);
        flash('success', 'Сообщение восстановлено.');
    }
    redirect(fp_post_anchor_url($post, true));
}

// ---------- Редактирование ----------
if ($action !== 'edit') {
    abort(404);
}
require_login();
if (!fp_can_edit_post($post, $thread, $node)) {
    $why = 'Вы не можете редактировать это сообщение.';
    if (is_banned()) {
        $why = 'Ваш аккаунт заблокирован.';
    } elseif ($thread['is_locked']) {
        $why = 'Тема закрыта, редактировать сообщения в ней нельзя.';
    } elseif ((int)$post['user_id'] === uid() && setting_int('edit_window_minutes') > 0) {
        $why = 'Время на редактирование сообщения истекло.';
    }
    abort(403, $why);
}

$canTitle = $isFirst;
$nodePrefixes = node_prefixes($node['id']);
// Префикс: модератор - любой из раздела; автор - если в разделе нет обязательного префикса по умолчанию
$canPrefix = $isFirst && ($isMod || !$node['default_prefix_id']) && ($nodePrefixes || $thread['prefix_id']);

$body = $post['body'];
$title = $thread['title'];
$prefixId = (int)$thread['prefix_id'];
$errors = [];

if (is_post()) {
    csrf_check();
    $body = input_text('body');
    if ($canTitle) {
        $title = fp_clean_title(input('title'));
        $err = fp_title_error($title);
        if ($err) {
            $errors['title'] = $err;
        }
    }
    if ($canPrefix) {
        $prefixId = input_int('prefix_id');
        if ($prefixId && !isset($nodePrefixes[$prefixId]) && $prefixId !== (int)$thread['prefix_id']) {
            $errors['prefix'] = 'Этот префикс нельзя использовать в разделе.';
        } elseif (!$prefixId && $node['require_prefix'] && $nodePrefixes) {
            $errors['prefix'] = 'Выберите префикс.';
        }
    }
    $err = fp_body_error($body);
    if ($err) {
        $errors['body'] = $err;
    }
    $errors = hook_filter('post_edit_validate', $errors, $post, $thread, $node);
    if (!$errors) {
        $changed = false;
        $body = hook_filter('post_body_save', $body, $node, $thread);
        if ($body !== $post['body']) {
            // До сохранения: модули могут сохранить старую версию (история правок)
            hook_fire('post_before_update', $post, $body, $thread, $node);
            db_update('posts', ['body' => $body, 'edited_at' => now(), 'edited_by' => uid()], 'id = :id', ['id' => $pid]);
            $changed = true;
            hook_fire('post_updated', $post, $body, $thread, $node);
        }
        $tData = [];
        if ($canTitle && $title !== $thread['title']) {
            $tData['title'] = $title;
        }
        if ($canPrefix && $prefixId !== (int)$thread['prefix_id']) {
            $tData['prefix_id'] = $prefixId ?: null;
        }
        if ($tData) {
            db_update('threads', $tData, 'id = :id', ['id' => $tid]);
            node_rebuild($node['id']);
            $changed = true;
            if (isset($tData['title']) && $isMod) {
                mod_log('thread_rename', 'thread', $tid, $thread['title'] . ' -> ' . $title);
            }
            if (array_key_exists('prefix_id', $tData)) {
                $all = prefixes_all();
                $ptitle = $prefixId ? $all[$prefixId]['title'] : 'без префикса';
                if ($isMod) {
                    mod_log('thread_prefix', 'thread', $tid, $thread['title'] . ' -> ' . $ptitle);
                }
                alert_add($thread['user_id'], 'prefix', uid(), $tid, null, $ptitle);
            }
        }
        if ($changed && $isMod && (int)$post['user_id'] !== uid()) {
            mod_log('post_edit', 'post', $pid, $thread['title']);
        }
        flash('success', $changed ? 'Изменения сохранены.' : 'Изменений нет.');
        redirect(post_url($pid));
    }
}

$crumbs = node_crumbs($node['id']);
$crumbs[] = [$thread['title'], thread_url($tid)];
forum_header([
    'title' => 'Редактирование сообщения',
    'crumbs' => $crumbs,
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'js' => ['thread.js'],
]);
$u = fp_author($post);
?>
<form method="post" action="<?= e(url('/forum/post.php', ['action' => 'edit', 'id' => $pid])) ?>" class="card compose-card">
  <?= csrf_field() ?>
  <div class="compose-head">
    <?= avatar($u, 'm') ?>
    <div>
      <div class="compose-title">Сообщение <?= fp_user_link($u) ?> в теме «<a href="<?= e(post_url($pid)) ?>"><?= e($thread['title']) ?></a>»</div>
      <div class="muted small"><?= e(fdate($post['created_at'])) ?><?= $post['edited_at'] ? ' · изменено ' . e(fdate($post['edited_at'])) : '' ?></div>
    </div>
  </div>
  <?php if ($errors): ?>
  <div class="flash flash-error"><?= icon('alert') ?><div><?= e(implode(' ', $errors)) ?></div></div>
  <?php endif; ?>
  <?php if ($canTitle): ?>
  <div class="compose-title-row">
    <?php if ($canPrefix): ?>
    <select class="select compose-prefix" name="prefix_id" aria-label="Префикс">
      <option value="0">Без префикса</option>
      <?php foreach ($nodePrefixes as $id => $pr): ?>
      <option value="<?= (int)$id ?>"<?= $prefixId === (int)$id ? ' selected' : '' ?>><?= e($pr['title']) ?></option>
      <?php endforeach; ?>
      <?php if ($thread['prefix_id'] && !isset($nodePrefixes[(int)$thread['prefix_id']]) && isset(prefixes_all()[(int)$thread['prefix_id']])): ?>
      <option value="<?= (int)$thread['prefix_id'] ?>"<?= $prefixId === (int)$thread['prefix_id'] ? ' selected' : '' ?>><?= e(prefixes_all()[(int)$thread['prefix_id']]['title']) ?></option>
      <?php endif; ?>
    </select>
    <?php elseif ($thread['prefix_id']): ?>
    <span class="compose-prefix-fixed" title="Префикс ставит модератор"><?= prefix_html($thread['prefix_id']) ?></span>
    <?php endif; ?>
    <input class="input compose-title-input<?= isset($errors['title']) ? ' has-error' : '' ?>" name="title" value="<?= e($title) ?>" maxlength="150" placeholder="Заголовок темы" required>
  </div>
  <?php endif; ?>
  <textarea class="textarea" name="body" rows="14" data-editor maxlength="50000"><?= e($body) ?></textarea>
  <?= hook_html('post_edit_form', $post, $thread, $node) ?>
  <div class="compose-actions">
    <a class="btn btn-ghost btn-pill" href="<?= e(post_url($pid)) ?>">Отмена</a>
    <button class="btn btn-white btn-pill" type="submit"><?= icon('check') ?> Сохранить</button>
  </div>
</form>
<?php
forum_footer();
