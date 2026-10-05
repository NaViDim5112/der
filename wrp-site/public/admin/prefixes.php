<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/prefixes.php');
$colors = prefix_colors();
$errors = [];
$form = null;

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'delete') {
        $id = input_int('id');
        $p = db_one('SELECT * FROM prefixes WHERE id = :id', ['id' => $id]);
        if (!$p) {
            flash('error', 'Префикс не найден - возможно, его уже удалили.');
            redirect($self);
        }
        $used = (int)db_val('SELECT COUNT(*) FROM threads WHERE prefix_id = :p', ['p' => $id]);
        db_exec('UPDATE threads SET prefix_id = NULL WHERE prefix_id = :p', ['p' => $id]);
        db_exec('UPDATE nodes SET default_prefix_id = NULL WHERE default_prefix_id = :p', ['p' => $id]);
        db_exec('DELETE FROM node_prefixes WHERE prefix_id = :p', ['p' => $id]);
        db_exec('DELETE FROM prefixes WHERE id = :p', ['p' => $id]);
        // Разделы, где префикс был обязателен, но разрешённых префиксов не осталось
        db_exec('UPDATE nodes SET require_prefix = 0 WHERE require_prefix = 1 AND id NOT IN (SELECT node_id FROM node_prefixes)');
        nodes_all(true);
        flash('success', 'Префикс «' . $p['title'] . '» удалён.' . ($used ? ' У ' . $used . ' ' . plural($used, 'темы', 'тем', 'тем') . ' префикс снят.' : ''));
        redirect($self);
    }

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM prefixes WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Префикс не найден.');
            redirect($self);
        }
        $form = ['id' => $id, 'title' => input('title'), 'color' => input('color'), 'display_order' => input('display_order')];
        if ($form['title'] === '') {
            $errors['title'] = 'Укажите текст префикса.';
        } elseif (!adm_len_ok($form['title'], 48)) {
            $errors['title'] = 'Слишком длинно (максимум 48 символов).';
        }
        if (!isset($colors[$form['color']])) {
            $errors['color'] = 'Выберите цвет из списка.';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = ['title' => $form['title'], 'color' => $form['color'], 'display_order' => $order];
            if ($id) {
                db_update('prefixes', $data, 'id = :id', ['id' => $id]);
                flash('success', 'Префикс «' . $form['title'] . '» сохранён.');
            } else {
                $id = db_insert('prefixes', $data);
                flash('success', 'Префикс «' . $form['title'] . '» создан. Отметьте его в нужных разделах (Разделы форума - Изменить - «Разрешённые префиксы»).');
            }
            redirect($self . '#prefix-' . $id);
        }
    }
}

// ---------- Форма ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM prefixes WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Префикс не найден.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'title' => '', 'color' => 'gray', 'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM prefixes')];
        }
    }
    $id = (int)$form['id'];
    $usage = $id ? (int)db_val('SELECT COUNT(*) FROM threads WHERE prefix_id = :p', ['p' => $id]) : 0;
    admin_header($id ? 'Префикс: ' . $form['title'] : 'Новый префикс', 'prefixes', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('tag') ?> <?= $id ? 'Изменение префикса' : 'Новый префикс' ?></h2><p>Префикс - цветная метка перед названием темы. Например, статус жалобы: «На рассмотрении», «Одобрено», «Отказано».</p></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="title">Текст <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="48" required placeholder="Например: На рассмотрении">
        <div class="hint">Коротко, 1-3 слова.</div>
        <?= adm_err($errors, 'title') ?>
      </div>
      <div class="form-row">
        <label class="label" for="color">Цвет</label>
        <select class="select<?= isset($errors['color']) ? ' is-invalid' : '' ?>" id="color" name="color">
          <?php foreach ($colors as $ck => $cn): ?><option value="<?= e($ck) ?>"<?= adm_sel($form['color'], $ck) ?>><?= e($cn) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Зелёный - хорошо, красный - плохо, жёлтый - в процессе.</div>
        <?= adm_err($errors, 'color') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
        <div class="hint">Меньшее число - выше в списке выбора префикса.</div>
        <?= adm_err($errors, 'display_order') ?>
      </div>
      <div class="form-row">
        <span class="label">Как это будет выглядеть</span>
        <div class="adm-preview-box">
          <div class="adm-prefix-big"><span class="prefix prefix-<?= e($form['color']) ?>" data-bind-prefix="color"><span data-bind-text="title" data-empty="Префикс"><?= e($form['title'] !== '' ? $form['title'] : 'Префикс') ?></span></span></div>
          <div class="adm-list-title"><span class="prefix prefix-<?= e($form['color']) ?>" data-bind-prefix="color"><span data-bind-text="title" data-empty="Префикс"><?= e($form['title'] !== '' ? $form['title'] : 'Префикс') ?></span></span>Жалоба на игрока Nick_Name</div>
        </div>
      </div>
    </div>
    <?php if ($id && $usage): ?><div class="adm-note"><?= icon('info') ?><div>Префикс стоит у <?= num($usage) ?> <?= plural($usage, 'темы', 'тем', 'тем') ?> - изменения сразу видны у них.</div></div><?php endif; ?>
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

$prefixes = db_all('SELECT p.*, (SELECT COUNT(*) FROM threads t WHERE t.prefix_id = p.id) AS used FROM prefixes p ORDER BY p.display_order, p.id');
$where = [];
foreach (db_all('SELECT np.prefix_id, np.node_id FROM node_prefixes np') as $r) {
    $n = node_get($r['node_id']);
    if ($n) {
        $where[(int)$r['prefix_id']][] = $n;
    }
}
admin_header('Префиксы', 'prefixes');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('tag') ?> Префиксы тем</h2>
      <p>Цветные метки перед названием темы. Где какой префикс можно выбрать, настраивается в разделах форума.</p>
    </div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/prefixes.php', ['new' => 1])) ?>"><?= icon('plus') ?> Создать префикс</a></div>
  </div>
  <?php if (!$prefixes): ?>
  <div class="adm-empty"><?= icon('tag') ?>Префиксов пока нет.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Префикс</th><th class="hide-sm">Цвет</th><th class="num">Тем</th><th class="hide-md">Разрешён в разделах</th><th class="center hide-sm">Порядок</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($prefixes as $p): $pid = (int)$p['id']; ?>
        <tr id="prefix-<?= $pid ?>">
          <td><a href="<?= e(url('/admin/prefixes.php', ['edit' => $pid])) ?>" class="adm-prefix-big"><?= prefix_html($pid) ?></a></td>
          <td class="hide-sm muted"><?= e($colors[$p['color']] ?? $p['color']) ?></td>
          <td class="num"><?= num($p['used']) ?></td>
          <td class="hide-md">
            <?php if (empty($where[$pid])): ?><span class="muted small">нигде</span><?php else: ?>
            <div class="tags">
              <?php foreach (array_slice($where[$pid], 0, 6) as $n): ?><a class="tag" href="<?= e(url('/admin/nodes.php', ['edit' => $n['id']])) ?>"><?= e($n['title']) ?></a><?php endforeach; ?>
              <?php if (count($where[$pid]) > 6): ?><span class="tag">+<?= count($where[$pid]) - 6 ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="center hide-sm"><?= (int)$p['display_order'] ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/prefixes.php', ['edit' => $pid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить префикс «<?= e($p['title']) ?>»?<?= $p['used'] ? ' Он будет снят у ' . (int)$p['used'] . ' ' . plural($p['used'], 'темы', 'тем', 'тем') . '.' : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $pid ?>">
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
