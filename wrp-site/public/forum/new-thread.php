<?php
// Создание темы: обычная форма (заголовок + редактор) или анкета раздела (жалобы, заявления)
require __DIR__ . '/../../app/bootstrap.php';

$node = node_get(query_int('node') ?: input_int('node'));
if (!$node || $node['type'] !== 'forum') {
    abort(404, 'Такого раздела нет.');
}
require_login();
if (!node_can_view($node)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}
if (!node_can_thread($node)) {
    abort(403, is_banned() ? 'Ваш аккаунт заблокирован, создавать темы нельзя.' : 'В этом разделе вы не можете создавать темы.');
}
$nid = (int)$node['id'];
$isMod = can_moderate();
$allPrefixes = prefixes_all();
$prefixes = node_prefixes($nid);
$defaultPrefix = (int)$node['default_prefix_id'];
if ($defaultPrefix && !isset($allPrefixes[$defaultPrefix])) {
    $defaultPrefix = 0;
}
if ($isMod && $defaultPrefix && !isset($prefixes[$defaultPrefix])) {
    $prefixes = [$defaultPrefix => $allPrefixes[$defaultPrefix]] + $prefixes;
}
// Обычный пользователь не выбирает префикс, если у раздела есть префикс по умолчанию
$fixedPrefix = $defaultPrefix && !$isMod;
$showPrefixSelect = !$fixedPrefix && $prefixes;

$form = form_get($node);
$free = $isMod && query_int('free') === 1;
$useForm = $form && !$free;

$title = '';
$body = (string)$node['thread_template'];
$prefixId = $defaultPrefix;
$pin = false;
$lock = false;
$watch = true;
$values = [];
$errors = [];
$error = '';

if (is_post()) {
    csrf_check();
    if ($useForm) {
        list($values, $errors) = form_input($form, $_POST);
        $title = fp_clean_title(form_title($form, $values));
        if (mb_strlen($title) < 3) {
            $title = $node['title'];
        }
        $body = form_body($form, $values);
    } else {
        $title = fp_clean_title(input('title'));
        $body = input_text('body');
        $err = fp_title_error($title);
        if ($err) {
            $errors['title'] = $err;
        }
        $err = fp_body_error($body);
        if ($err) {
            $errors['body'] = $err;
        }
    }
    if ($fixedPrefix) {
        $prefixId = $defaultPrefix;
    } elseif ($showPrefixSelect) {
        $prefixId = input_int('prefix_id');
        if ($prefixId && !isset($prefixes[$prefixId])) {
            $errors['prefix'] = 'Этот префикс нельзя использовать в разделе.';
        } elseif (!$prefixId && $node['require_prefix']) {
            $errors['prefix'] = 'Выберите префикс темы.';
        }
    } else {
        $prefixId = 0;
    }
    $pin = $isMod && input_bool('pin');
    $lock = $isMod && input_bool('lock');
    $watch = input_bool('watch');
    if (!$errors) {
        $wait = fp_flood_wait();
        if ($wait > 0) {
            $error = 'Подождите ' . $wait . ' сек. перед созданием темы.';
        }
    }
    if (!$errors && $error === '') {
        $tid = fp_create_thread($node, $title, $prefixId, $body, ['pinned' => $pin, 'locked' => $lock, 'watch' => $watch]);
        flash('success', 'Тема создана.');
        redirect(thread_url($tid));
    }
    if ($errors && $error === '') {
        $error = $useForm ? 'Проверьте поля анкеты: некоторые заполнены неверно.' : implode(' ', $errors);
    }
}

$crumbs = node_crumbs($nid);
forum_header([
    'title' => $useForm ? $node['title'] : 'Создать тему',
    'meta_html' => $useForm ? '' : 'в разделе <a href="' . e(node_url($node)) . '">' . e($node['title']) . '</a>',
    'crumbs' => $crumbs,
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'js' => ['thread.js'],
]);

// Префикс: выбор или фиксированный
$prefixHtml = '';
if ($fixedPrefix) {
    $prefixHtml = '<span class="compose-prefix-fixed" title="Префикс ставится автоматически, статус меняет администрация">' . prefix_html($defaultPrefix) . '</span>';
} elseif ($showPrefixSelect) {
    $prefixHtml = '<select class="select compose-prefix' . (isset($errors['prefix']) ? ' has-error' : '') . '" name="prefix_id" aria-label="Префикс">'
        . '<option value="0">' . ($node['require_prefix'] ? 'Префикс...' : 'Без префикса') . '</option>';
    foreach ($prefixes as $id => $pr) {
        $prefixHtml .= '<option value="' . (int)$id . '"' . ((int)$prefixId === (int)$id ? ' selected' : '') . '>' . e($pr['title']) . '</option>';
    }
    $prefixHtml .= '</select>';
}

$modSettings = '';
if ($isMod) {
    $modSettings = '<label class="check"><input type="checkbox" name="pin" value="1"' . ($pin ? ' checked' : '') . '><span>' . icon('pin') . ' Закрепить</span></label>'
        . '<label class="check"><input type="checkbox" name="lock" value="1"' . ($lock ? ' checked' : '') . '><span>' . icon('lock') . ' Закрыть</span></label>';
}
?>
<?php if ($error): ?>
<div class="flash flash-error"><?= icon('alert') ?><div><?= e($error) ?></div></div>
<?php endif; ?>

<?php if ($useForm): ?>
<?php if (trim($form['intro']) !== '' || $node['description']): ?>
<div class="card compose-hint">
  <div class="compose-hint-icon"><?= icon('info') ?></div>
  <div class="bb"><?= trim($form['intro']) !== '' ? bbcode($form['intro']) : e($node['description']) ?></div>
</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/forum/new-thread.php', ['node' => $nid])) ?>" class="compose-form-q">
  <?= csrf_field() ?>
  <input type="hidden" name="watch" value="1">
  <?php if ($prefixHtml !== '' || $isMod): ?>
  <div class="card compose-q-top">
    <?php if ($prefixHtml !== ''): ?><div class="compose-q-prefix"><span class="label">Префикс:</span> <?= $prefixHtml ?></div><?php endif; ?>
    <?php if ($isMod): ?>
    <div class="compose-settings-inline"><?= $modSettings ?></div>
    <a class="small compose-free-link" href="<?= e(url('/forum/new-thread.php', ['node' => $nid, 'free' => 1])) ?>"><?= icon('edit') ?> Создать тему без анкеты</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?= form_fields_html($form, $values, $errors) ?>
  <div class="xf-actions"><button class="btn btn-white btn-pill" type="submit"><?= icon('send') ?> Отправить</button></div>
</form>
<?php else: ?>
<?php if ($node['description']): ?>
<div class="card compose-hint">
  <div class="compose-hint-icon"><?= icon('info') ?></div>
  <div><b><?= e($node['title']) ?></b><div class="muted"><?= e($node['description']) ?></div></div>
  <?php if ($form && $isMod): ?>
  <a class="btn btn-ghost btn-sm compose-hint-btn" href="<?= e(url('/forum/new-thread.php', ['node' => $nid])) ?>"><?= icon('rules') ?> Заполнить анкету</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/forum/new-thread.php', ['node' => $nid] + ($free ? ['free' => 1] : []))) ?>" class="card compose-card">
  <?= csrf_field() ?>
  <div class="compose-title-row">
    <?= $prefixHtml ?>
    <input class="input compose-title-input<?= isset($errors['title']) ? ' has-error' : '' ?>" name="title" value="<?= e($title) ?>" maxlength="150" placeholder="<?= e($node['title_hint'] ?: 'Заголовок темы') ?>" required autofocus>
  </div>
  <textarea class="textarea" name="body" rows="16" data-editor data-draft="thread-<?= $nid ?>"<?= !is_post() && $body !== '' ? ' data-template-default' : '' ?> maxlength="50000" placeholder="Текст темы..."><?= e($body) ?></textarea>
  <div class="compose-settings">
    <span class="compose-settings-label">Настройки:</span>
    <label class="check"><input type="checkbox" name="watch" value="1"<?= $watch ? ' checked' : '' ?>><span>Отслеживать эту тему</span></label>
    <?= $modSettings ?>
  </div>
  <div class="compose-submit">
    <button class="btn btn-white btn-pill btn-lg" type="submit"><?= icon('edit') ?> Создать тему</button>
  </div>
</form>
<?php endif; ?>
<?php
forum_footer();
