<?php
// Админ-панель: дополнительные поля профиля (модуль «Сообщество»)
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/profile-fields.php');
if (!com_ready()) {
    admin_header('Поля профиля', 'profile-fields');
    echo '<div class="card"><div class="adm-note">' . icon('alert') . '<div>Нужно обновить базу сайта: откройте <a href="' . e(url('/admin/')) . '">Обзор</a> и нажмите «Обновить базу».</div></div></div>';
    admin_footer();
    exit;
}

$types = com_field_types();
$errors = [];
$form = null;

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'save') {
        $id = input_int('id');
        if ($id && !db_one('SELECT id FROM com_fields WHERE id = :id', ['id' => $id])) {
            flash('error', 'Поле не найдено.');
            redirect($self);
        }
        $form = [
            'id' => $id,
            'title' => com_clean_line(input('title')),
            'description' => com_clean_line(input('description')),
            'type' => input('type'),
            'options' => input_text('options'),
            'max_length' => input('max_length'),
            'placeholder' => com_clean_line(input('placeholder')),
            'icon' => input('icon'),
            'show_profile' => input_bool('show_profile') ? 1 : 0,
            'show_post' => input_bool('show_post') ? 1 : 0,
            'user_editable' => input_bool('user_editable') ? 1 : 0,
            'display_order' => input('display_order'),
        ];
        if ($form['title'] === '' || !adm_len_ok($form['title'], 64)) {
            $errors['title'] = 'Название - от 1 до 64 символов.';
        }
        if (!adm_len_ok($form['description'], 255)) {
            $errors['description'] = 'Подсказка - не больше 255 символов.';
        }
        if (!adm_len_ok($form['placeholder'], 100)) {
            $errors['placeholder'] = 'Пример - не больше 100 символов.';
        }
        if (!isset($types[$form['type']])) {
            $errors['type'] = 'Выберите тип поля.';
        }
        if (!in_array($form['icon'], icon_names(), true)) {
            $errors['icon'] = 'Выберите иконку.';
        }
        $max = adm_post_range('max_length', 1, 255);
        if ($max === null) {
            $errors['max_length'] = 'Длина - от 1 до 255 символов.';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        $opts = com_field_options(['options' => $form['options']]);
        if ($form['type'] === 'select') {
            if (count($opts) < 2) {
                $errors['options'] = 'Для списка нужно хотя бы 2 варианта, по одному на строку.';
            } elseif (count($opts) > 100) {
                $errors['options'] = 'Не больше 100 вариантов.';
            } else {
                foreach ($opts as $o) {
                    if (mb_strlen($o) > 100) {
                        $errors['options'] = 'Каждый вариант - не больше 100 символов.';
                    }
                }
            }
        }
        if (!$errors) {
            $data = [
                'title' => $form['title'],
                'description' => adm_null($form['description']),
                'type' => $form['type'],
                'options' => $form['type'] === 'select' ? implode("\n", $opts) : null,
                'max_length' => $max,
                'placeholder' => adm_null($form['placeholder']),
                'icon' => $form['icon'],
                'show_profile' => $form['show_profile'],
                'show_post' => $form['show_post'],
                'user_editable' => $form['user_editable'],
                'display_order' => $order,
            ];
            if ($id) {
                db_update('com_fields', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('com_fields', $data);
            }
            flash('success', 'Поле «' . $form['title'] . '» сохранено.');
            redirect($self . '#field-' . $id);
        }
    }

    if ($action === 'delete') {
        $f = db_one('SELECT * FROM com_fields WHERE id = :id', ['id' => input_int('id')]);
        if ($f) {
            $n = db_exec('DELETE FROM com_field_values WHERE field_id = :f', ['f' => (int)$f['id']]);
            db_exec('DELETE FROM com_fields WHERE id = :f', ['f' => (int)$f['id']]);
            flash('success', 'Поле «' . $f['title'] . '» удалено' . ($n ? ' вместе с ' . $n . ' ' . plural($n, 'значением', 'значениями', 'значениями') : '') . '.');
        }
        redirect($self);
    }

    if ($action === 'user_values') {
        $u = user_by_id(input_int('user_id'));
        if (!$u) {
            flash('error', 'Пользователь не найден.');
            redirect($self);
        }
        $in = $_POST['f'] ?? [];
        $in = is_array($in) ? $in : [];
        $errs = [];
        $clean = [];
        foreach (com_fields_all() as $fid => $f) {
            $err = '';
            $clean[$fid] = com_field_clean($f, $in[$fid] ?? '', $err);
            if ($err !== '') {
                $errs[] = $err;
            }
        }
        if ($errs) {
            flash('error', implode(' ', $errs));
        } else {
            foreach ($clean as $fid => $v) {
                com_field_save($u['id'], $fid, $v);
            }
            mod_log('fields_edit', 'user', $u['id'], $u['username']);
            flash('success', 'Поля пользователя ' . $u['username'] . ' сохранены.');
        }
        redirect(url('/admin/profile-fields.php', ['user' => $u['username']]) . '#user-values');
    }
}

// ---------- Форма поля ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM com_fields WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Поле не найдено.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'title' => '', 'description' => '', 'type' => 'text', 'options' => '', 'max_length' => 64, 'placeholder' => '', 'icon' => 'info',
                'show_profile' => 1, 'show_post' => 0, 'user_editable' => 1, 'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM com_fields')];
        }
    }
    $id = (int)$form['id'];
    $used = $id ? (int)db_val('SELECT COUNT(*) FROM com_field_values WHERE field_id = :f', ['f' => $id]) : 0;
    admin_header($id ? 'Поле: ' . $form['title'] : 'Новое поле', 'profile-fields', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('list') ?> <?= $id ? 'Изменение поля' : 'Новое поле' ?></h2><p>Поле появится в настройках аккаунта на вкладке «Дополнительно» и в профиле в «Сведениях».</p></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="title">Название <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="64" required placeholder="Например: Discord">
        <?= adm_err($errors, 'title') ?>
      </div>
      <div class="form-row">
        <label class="label" for="type">Тип</label>
        <select class="select<?= isset($errors['type']) ? ' is-invalid' : '' ?>" id="type" name="type" data-com-ftype>
          <?php foreach ($types as $k => $t): ?><option value="<?= e($k) ?>"<?= adm_sel($form['type'], $k) ?>><?= e($t[0]) ?> - <?= e($t[1]) ?></option><?php endforeach; ?>
        </select>
        <?= adm_err($errors, 'type') ?>
      </div>
    </div>
    <div class="form-row" data-com-options>
      <label class="label" for="options">Варианты (по одному на строку)</label>
      <textarea class="textarea textarea-sm<?= isset($errors['options']) ? ' is-invalid' : '' ?>" id="options" name="options" rows="5"><?= e((string)$form['options']) ?></textarea>
      <?= adm_err($errors, 'options') ?>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="description">Подсказка под полем</label>
        <input class="input<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" value="<?= e((string)$form['description']) ?>" maxlength="255">
        <?= adm_err($errors, 'description') ?>
      </div>
      <div class="form-row">
        <label class="label" for="placeholder">Пример в пустом поле</label>
        <input class="input<?= isset($errors['placeholder']) ? ' is-invalid' : '' ?>" id="placeholder" name="placeholder" value="<?= e((string)$form['placeholder']) ?>" maxlength="100" placeholder="Например: @username">
        <?= adm_err($errors, 'placeholder') ?>
      </div>
      <div class="form-row">
        <label class="label" for="max_length">Максимальная длина</label>
        <input class="input<?= isset($errors['max_length']) ? ' is-invalid' : '' ?>" type="number" min="1" max="255" id="max_length" name="max_length" value="<?= e($form['max_length']) ?>">
        <?= adm_err($errors, 'max_length') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
        <?= adm_err($errors, 'display_order') ?>
      </div>
    </div>
    <div class="adm-opts adm-opts-2">
      <label class="adm-opt"><input type="checkbox" name="show_profile" value="1"<?= adm_chk($form['show_profile']) ?>><span><b>Показывать в профиле</b><small>В блоке «Сведения» на вкладке «Информация».</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="show_post" value="1"<?= adm_chk($form['show_post']) ?>><span><b>Показывать в темах</b><small>Строкой под аватаром автора в каждом сообщении.</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="user_editable" value="1"<?= adm_chk($form['user_editable']) ?>><span><b>Пользователь меняет сам</b><small>Иначе значение задаёт только администрация на этой странице.</small></span></label>
    </div>
    <div class="form-row">
      <span class="label">Иконка</span>
      <?= adm_icon_picker('icon', $form['icon']) ?>
      <?= adm_err($errors, 'icon') ?>
    </div>
    <?php if ($used): ?><div class="adm-note"><?= icon('info') ?><div>Поле заполнено у <?= num($used) ?> <?= plural($used, 'пользователя', 'пользователей', 'пользователей') ?>. Если сменить тип или варианты, старые значения останутся, но при следующем сохранении профиля их придётся поправить.</div></div><?php endif; ?>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">К списку</a>
  </div>
</form>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$fields = db_all('SELECT f.*, (SELECT COUNT(*) FROM com_field_values v WHERE v.field_id = f.id) AS used FROM com_fields f ORDER BY f.display_order, f.id');
$target = null;
$targetVals = [];
$userQuery = com_clean_line(query_str('user'));
if ($userQuery !== '') {
    $target = user_by_name($userQuery);
    if ($target) {
        $targetVals = com_field_values($target['id']);
    }
}
admin_header('Поля профиля', 'profile-fields');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('list') ?> Дополнительные поля профиля</h2>
      <p>Контакты и сведения, которые участники указывают о себе: Discord, ВКонтакте, Telegram, часовой пояс. Пустые поля нигде не показываются.</p>
    </div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/profile-fields.php', ['new' => 1])) ?>"><?= icon('plus') ?> Создать поле</a></div>
  </div>
  <?php if (!$fields): ?>
  <div class="adm-empty"><?= icon('list') ?>Полей пока нет.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Поле</th><th class="hide-sm">Тип</th><th class="hide-md">Где видно</th><th class="num hide-sm">Заполнено</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fields as $f): $fid = (int)$f['id']; ?>
        <tr id="field-<?= $fid ?>">
          <td><a class="adm-cell-main" href="<?= e(url('/admin/profile-fields.php', ['edit' => $fid])) ?>"><span class="com-field-icon"><?= icon($f['icon']) ?></span><span class="adm-cell-text com-cell"><span class="adm-cell-title"><?= e($f['title']) ?></span><?php if (!$f['user_editable']): ?><span class="adm-cell-sub">задаёт администрация</span><?php endif; ?></span></a></td>
          <td class="hide-sm muted"><?= e($types[$f['type']][0] ?? $f['type']) ?></td>
          <td class="hide-md"><div class="tags"><?= $f['show_profile'] ? '<span class="tag">профиль</span>' : '' ?><?= $f['show_post'] ? '<span class="tag">темы</span>' : '' ?><?= !$f['show_profile'] && !$f['show_post'] ? '<span class="muted small">нигде</span>' : '' ?></div></td>
          <td class="num hide-sm"><?= num($f['used']) ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/profile-fields.php', ['edit' => $fid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить поле «<?= e($f['title']) ?>»?<?= $f['used'] ? ' Значения у ' . (int)$f['used'] . ' ' . plural($f['used'], 'пользователя', 'пользователей', 'пользователей') . ' тоже удалятся.' : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $fid ?>">
                <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить"><?= icon('trash') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card" id="user-values">
  <div class="adm-card-head"><div><h2><?= icon('user') ?> Поля пользователя</h2><p>Здесь можно заполнить или исправить поля любого участника, в том числе те, что пользователь не меняет сам.</p></div></div>
  <form method="get" action="<?= e($self) ?>" class="com-award-form is-2">
    <input class="input" name="user" value="<?= e($userQuery) ?>" maxlength="32" placeholder="Имя пользователя" required>
    <button class="btn btn-white" type="submit"><?= icon('search') ?> Открыть</button>
  </form>
  <?php if ($userQuery !== '' && !$target): ?>
  <div class="flash flash-error mt-1"><?= icon('alert') ?><div>Пользователь «<?= e($userQuery) ?>» не найден.</div></div>
  <?php elseif ($target): ?>
  <form method="post" action="<?= e($self) ?>" class="form mt-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="user_values">
    <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
    <div class="adm-cell-main"><?= avatar($target, 's') ?><?= user_link($target) ?></div>
    <div class="form-grid">
      <?php foreach (com_fields_all() as $fid => $f): ?>
      <div class="form-row">
        <label class="label" for="uf-<?= (int)$fid ?>"><?= e($f['title']) ?><?= $f['user_editable'] ? '' : ' <span class="muted">(только администрация)</span>' ?></label>
        <?= com_field_input($f, (string)($targetVals[$fid] ?? ''), 'f[' . (int)$fid . ']', 'uf-' . (int)$fid) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button><a class="btn btn-ghost" href="<?= e(url('/forum/member.php', ['id' => (int)$target['id'], 'tab' => 'about'])) ?>">Профиль</a></div>
  </form>
  <?php endif; ?>
</div>
<?php
admin_footer();
