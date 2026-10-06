<?php
// Админ-панель: готовые ответы для решений по жалобам, заявлениям, обращениям и предложениям
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/macros.php');
if (!wf_ready()) {
    admin_header('Готовые ответы', 'macros');
    echo '<div class="card"><div class="adm-note warn">' . icon('alert') . '<div>Таблицы модуля ещё не созданы. Откройте <a href="' . e(url('/admin/')) . '">Обзор</a> и нажмите «Обновить базу».</div></div></div>';
    admin_footer();
    exit;
}

$errors = [];
$form = null;
$prefixes = prefixes_all();
// Разделы, где включено рассмотрение (по порядку дерева)
$wfNodes = [];
foreach (adm_node_tree() as $row) {
    if ($row['node']['type'] === 'forum' && wf_cfg($row['node']['id'])) {
        $wfNodes[(int)$row['node']['id']] = $row['node'];
    }
}

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'delete') {
        $id = input_int('id');
        $m = db_one('SELECT * FROM wf_macros WHERE id = :id', ['id' => $id]);
        if ($m) {
            db_exec('DELETE FROM wf_macros WHERE id = :id', ['id' => $id]);
            mod_log('wf_macro', 'wf_macro', $id, 'удалён: ' . $m['title']);
            flash('success', 'Готовый ответ «' . $m['title'] . '» удалён.');
        }
        redirect($self);
    }

    if ($action === 'toggle') {
        $id = input_int('id');
        $m = db_one('SELECT * FROM wf_macros WHERE id = :id', ['id' => $id]);
        if ($m) {
            db_update('wf_macros', ['is_active' => $m['is_active'] ? 0 : 1, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            flash('success', 'Готовый ответ «' . $m['title'] . '» ' . ($m['is_active'] ? 'выключен.' : 'включён.'));
        }
        redirect($self . '#macro-' . $id);
    }

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM wf_macros WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Готовый ответ не найден.');
            redirect($self);
        }
        $scopeAll = input('scope') !== 'some';
        $nodeIds = [];
        foreach (adm_post_ints('nodes') as $n) {
            if (isset($wfNodes[$n])) {
                $nodeIds[] = $n;
            }
        }
        $form = [
            'id' => $id,
            'title' => fp_clean_title(input('title')),
            'body' => input_text('body'),
            'node_ids' => $scopeAll ? '' : implode(',', $nodeIds),
            'prefix_id' => input_int('prefix_id'),
            'do_lock' => input_bool('do_lock') ? 1 : 0,
            'do_archive' => input_bool('do_archive') ? 1 : 0,
            'display_order' => input('display_order'),
            'is_active' => input_bool('is_active') ? 1 : 0,
        ];
        if ($form['title'] === '' || mb_strlen($form['title']) > 100) {
            $errors['title'] = 'Название - от 1 до 100 символов.';
        }
        if (mb_strlen($form['body']) < 5 || mb_strlen($form['body']) > 20000) {
            $errors['body'] = 'Текст ответа - от 5 до 20 000 символов.';
        }
        if (!$scopeAll && !$nodeIds) {
            $errors['nodes'] = 'Отметьте хотя бы один раздел или выберите «Во всех разделах».';
        }
        if ($form['prefix_id'] && !isset($prefixes[$form['prefix_id']])) {
            $errors['prefix_id'] = 'Выберите статус из списка.';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = [
                'title' => $form['title'], 'body' => $form['body'], 'node_ids' => $form['node_ids'] !== '' ? $form['node_ids'] : null,
                'prefix_id' => $form['prefix_id'] ?: null, 'do_lock' => $form['do_lock'], 'do_archive' => $form['do_archive'],
                'display_order' => $order, 'is_active' => $form['is_active'], 'updated_at' => now(),
            ];
            if ($id) {
                db_update('wf_macros', $data, 'id = :id', ['id' => $id]);
                mod_log('wf_macro', 'wf_macro', $id, 'изменён: ' . $form['title']);
            } else {
                $data['created_at'] = now();
                $id = db_insert('wf_macros', $data);
                mod_log('wf_macro', 'wf_macro', $id, 'создан: ' . $form['title']);
            }
            flash('success', 'Готовый ответ «' . $form['title'] . '» сохранён.');
            redirect($self . '#macro-' . $id);
        }
    }
}

// ---------- Форма ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM wf_macros WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Готовый ответ не найден.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'title' => '', 'body' => "Здравствуйте, {author}.\n\n\n\nС уважением, {staff}.", 'node_ids' => '', 'prefix_id' => null,
                'do_lock' => 1, 'do_archive' => 0, 'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM wf_macros'), 'is_active' => 1];
        }
    }
    $id = (int)$form['id'];
    $sel = wf_ids($form['node_ids']);
    admin_header($id ? 'Готовый ответ: ' . $form['title'] : 'Новый готовый ответ', 'macros', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('quote') ?> <?= $id ? 'Изменение ответа' : 'Новый готовый ответ' ?></h2><p>Сотрудник выбирает ответ в форме решения: текст подставляется, статус, закрытие и архив выставляются сами.</p></div></div>
    <div class="form-row">
      <label class="label" for="title">Название <span class="req">*</span></label>
      <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="100" required placeholder="Например: Отказано: недостаточно доказательств">
      <?= adm_err($errors, 'title') ?>
    </div>
    <div class="form-row">
      <label class="label" for="wf-macro-body">Текст ответа <span class="req">*</span></label>
      <div class="wf-ph-keys"><span class="hint">Вставить:</span>
        <button type="button" data-wf-ph-insert="{author}" title="Ник автора темы">{author}</button>
        <button type="button" data-wf-ph-insert="{staff}" title="Ник того, кто отвечает">{staff}</button>
        <button type="button" data-wf-ph-insert="{thread}" title="Название темы">{thread}</button>
        <button type="button" data-wf-ph-insert="{date}" title="Сегодняшняя дата">{date}</button>
      </div>
      <textarea class="textarea<?= isset($errors['body']) ? ' is-invalid' : '' ?>" id="wf-macro-body" name="body" rows="9" maxlength="20000" data-editor required><?= e($form['body']) ?></textarea>
      <?= adm_err($errors, 'body') ?>
    </div>
    <div class="form-row">
      <span class="label">Где доступен</span>
      <div class="adm-opts adm-opts-2">
        <label class="adm-opt"><input type="radio" name="scope" value="all"<?= adm_chk(!$sel) ?>><span><b>Во всех разделах</b><small>Везде, где включено рассмотрение.</small></span></label>
        <label class="adm-opt"><input type="radio" name="scope" value="some"<?= adm_chk((bool)$sel) ?>><span><b>В выбранных разделах</b><small>Отметьте разделы ниже.</small></span></label>
      </div>
      <div class="adm-checkgrid">
        <?php foreach ($wfNodes as $nid => $n): ?>
        <label class="adm-checkpill"><input type="checkbox" name="nodes[]" value="<?= (int)$nid ?>"<?= adm_chk(in_array((int)$nid, $sel, true)) ?>><?= e($n['title']) ?></label>
        <?php endforeach; ?>
      </div>
      <?= adm_err($errors, 'nodes') ?>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="prefix_id">Статус темы</label>
        <select class="select<?= isset($errors['prefix_id']) ? ' is-invalid' : '' ?>" id="prefix_id" name="prefix_id">
          <option value="0">Не менять</option>
          <?php foreach ($prefixes as $pid => $p): ?><option value="<?= (int)$pid ?>"<?= adm_sel((int)$form['prefix_id'], $pid) ?>><?= e($p['title']) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Если статуса нет среди префиксов раздела, сотрудник выберет его сам.</div>
        <?= adm_err($errors, 'prefix_id') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
        <?= adm_err($errors, 'display_order') ?>
      </div>
    </div>
    <div class="adm-opts adm-opts-2">
      <label class="adm-opt"><input type="checkbox" name="do_lock" value="1"<?= adm_chk($form['do_lock']) ?>><span><b>Закрыть тему</b><small>После ответа в теме больше нельзя писать.</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="do_archive" value="1"<?= adm_chk($form['do_archive']) ?>><span><b>Перенести в архив</b><small>В архив раздела из настроек рассмотрения.</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="is_active" value="1"<?= adm_chk($form['is_active']) ?>><span><b>Включён</b><small>Выключенный ответ не показывается сотрудникам.</small></span></label>
    </div>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">Отмена</a>
  </div>
</form>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$macros = db_all('SELECT * FROM wf_macros ORDER BY display_order, id');
admin_header('Готовые ответы', 'macros');
?>
<div class="card">
  <div class="adm-card-head">
    <div><h2><?= icon('quote') ?> Готовые ответы</h2><p>Шаблоны решений для жалоб, заявлений, техподдержки и предложений. Подстановки: {author} - автор темы, {staff} - кто отвечает, {thread} - название темы, {date} - дата.</p></div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/macros.php', ['new' => 1])) ?>"><?= icon('plus') ?> Создать ответ</a></div>
  </div>
  <?php if (!$macros): ?>
  <div class="adm-empty"><?= icon('quote') ?>Готовых ответов пока нет.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Ответ</th><th class="hide-sm">Статус</th><th class="hide-sm">После ответа</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($macros as $m): $mid = (int)$m['id']; $ids = wf_ids($m['node_ids']);
          $where = [];
          foreach ($ids as $nid) {
              $n = node_get($nid);
              if ($n) {
                  $where[] = $n['title'];
              }
          } ?>
        <tr id="macro-<?= $mid ?>"<?= $m['is_active'] ? '' : ' class="is-muted"' ?>>
          <td><a class="adm-cell-title" href="<?= e(url('/admin/macros.php', ['edit' => $mid])) ?>"><?= e($m['title']) ?></a>
            <div class="wf-adm-macro-body"><?= e(bbcode_plain($m['body'], 120)) ?></div>
            <div class="wf-adm-macro-where" title="<?= e(implode(', ', $where)) ?>"><?= icon('folder') ?><?= $where ? e(implode(', ', array_slice($where, 0, 2))) . (count($where) > 2 ? ' и ещё ' . (count($where) - 2) : '') : 'Все разделы с рассмотрением' ?></div>
            <?php if ($m['prefix_id']): ?><div class="wf-adm-mprefix"><?= prefix_html($m['prefix_id']) ?></div><?php endif; ?></td>
          <td class="hide-sm"><?= $m['prefix_id'] ? prefix_html($m['prefix_id']) : '<span class="muted small">не менять</span>' ?></td>
          <td class="hide-sm"><div class="wf-adm-flags">
            <?= $m['do_lock'] ? '<span class="tag">' . icon('lock') . 'закрыть</span>' : '' ?>
            <?= $m['do_archive'] ? '<span class="tag">' . icon('folder') . 'в архив</span>' : '' ?>
            <?= $m['is_active'] ? '' : '<span class="tag tag-warning">выключен</span>' ?>
          </div></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/macros.php', ['edit' => $mid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $mid ?>">
                <button class="btn btn-sm btn-ghost btn-icon" type="submit" title="<?= $m['is_active'] ? 'Выключить' : 'Включить' ?>"><?= icon($m['is_active'] ? 'eye-off' : 'eye') ?></button>
              </form>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить готовый ответ «<?= e($m['title']) ?>»?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $mid ?>">
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
<?php
admin_footer();
