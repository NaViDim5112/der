<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/nav.php');
$errors = [];
$form = null;

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'order') {
        $orders = $_POST['order'] ?? [];
        if (is_array($orders)) {
            foreach ($orders as $id => $v) {
                if (is_numeric($v)) {
                    db_update('nav_links', ['display_order' => max(-100000, min(100000, (int)$v))], 'id = :id', ['id' => (int)$id]);
                }
            }
        }
        flash('success', 'Порядок пунктов меню сохранён.');
        redirect($self);
    }

    if ($action === 'delete') {
        $l = db_one('SELECT * FROM nav_links WHERE id = :id', ['id' => input_int('id')]);
        if ($l) {
            db_exec('DELETE FROM nav_links WHERE id = :id', ['id' => (int)$l['id']]);
            flash('success', 'Пункт «' . $l['title'] . '» удалён из меню.');
        }
        redirect($self);
    }

    if ($action === 'save') {
        $id = input_int('id');
        if ($id && !db_val('SELECT id FROM nav_links WHERE id = :id', ['id' => $id])) {
            flash('error', 'Пункт меню не найден.');
            redirect($self);
        }
        $form = ['id' => $id, 'title' => input('title'), 'url' => input('url'), 'icon' => input('icon', 'link'),
            'display_order' => input('display_order'), 'new_tab' => input_bool('new_tab') ? 1 : 0];
        if ($form['title'] === '') {
            $errors['title'] = 'Укажите название пункта.';
        } elseif (!adm_len_ok($form['title'], 64)) {
            $errors['title'] = 'Название слишком длинное (максимум 64 символа).';
        }
        if ($form['url'] === '') {
            $errors['url'] = 'Укажите адрес.';
        } elseif (!adm_link_ok($form['url']) || !adm_len_ok($form['url'], 255)) {
            $errors['url'] = 'Адрес должен начинаться с / (страница этого сайта) или с https:// (другой сайт).';
        }
        if (!in_array($form['icon'], icon_names(), true)) {
            $form['icon'] = 'link';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = ['title' => $form['title'], 'url' => $form['url'], 'icon' => $form['icon'], 'display_order' => $order, 'new_tab' => $form['new_tab']];
            if ($id) {
                db_update('nav_links', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('nav_links', $data);
            }
            flash('success', 'Пункт «' . $form['title'] . '» сохранён. Он уже виден в меню слева.');
            redirect($self);
        }
    }
}

$links = db_all('SELECT * FROM nav_links ORDER BY display_order, id');

// Мини-копия левого меню: встроенные пункты + пункты из базы
function adm_nav_preview(array $links, $editId = 0, $live = false)
{
    $h = '<div class="adm-side-preview"><nav class="side-nav">';
    foreach ([['home', 'Главная'], ['chats', 'Форумы'], ['users', 'Пользователи'], ['help', 'База знаний']] as $b) {
        $h .= '<span class="side-link" style="opacity:.55">' . icon($b[0]) . '<span>' . e($b[1]) . '</span></span>';
    }
    $shown = false;
    foreach ($links as $l) {
        if ($live && (int)$l['id'] === (int)$editId) {
            $h .= adm_nav_live_item();
            $shown = true;
            continue;
        }
        $h .= '<span class="side-link">' . icon($l['icon']) . '<span>' . e($l['title']) . '</span>' . ($l['new_tab'] ? icon('external', 'ext') : '') . '</span>';
    }
    if ($live && !$shown) {
        $h .= adm_nav_live_item();
    }
    return $h . '</nav></div>';
}

function adm_nav_live_item()
{
    return '<span class="side-link is-new"><span data-bind-icon="icon" style="display:inline-flex">' . icon('link') . '</span><span data-bind-text="title" data-empty="Новый пункт">Новый пункт</span><span data-bind-flag="new_tab" style="margin-left:auto;display:inline-flex">' . icon('external', 'ext') . '</span></span>';
}

// ---------- Форма ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM nav_links WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Пункт меню не найден.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'title' => '', 'url' => '', 'icon' => 'link', 'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM nav_links'), 'new_tab' => 0];
        }
    }
    $id = (int)$form['id'];
    admin_header($id ? 'Пункт меню: ' . $form['title'] : 'Новый пункт меню', 'nav', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="adm-cols adm-cols-side">
    <div class="card">
      <div class="adm-card-head"><div><h2><?= icon('list') ?> <?= $id ? 'Изменение пункта' : 'Новый пункт' ?></h2><p>Ссылка в левом меню сайта, под «Базой знаний».</p></div></div>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="title">Название <span class="req">*</span></label>
          <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="64" required placeholder="Например: Жалобы">
          <?= adm_err($errors, 'title') ?>
        </div>
        <div class="form-row">
          <label class="label" for="display_order">Порядок</label>
          <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
          <div class="hint">Меньшее число - выше.</div>
          <?= adm_err($errors, 'display_order') ?>
        </div>
      </div>
      <div class="form-row">
        <label class="label" for="url">Адрес <span class="req">*</span></label>
        <input class="input<?= isset($errors['url']) ? ' is-invalid' : '' ?>" id="url" name="url" value="<?= e($form['url']) ?>" maxlength="255" required placeholder="/forum/forum.php?id=51 или https://discord.gg/...">
        <div class="hint">Страница этого сайта - путь от корня, начинается с /. Совет: откройте нужный раздел форума и скопируйте из адресной строки всё после названия сайта, например /forum/forum.php?id=51. Другой сайт - полный адрес с https://.</div>
        <?= adm_err($errors, 'url') ?>
      </div>
      <label class="adm-opt"><input type="checkbox" name="new_tab" value="1"<?= adm_chk($form['new_tab']) ?>><span><b>Открывать в новой вкладке</b><small>Удобно для внешних ссылок: Discord, ВКонтакте, YouTube</small></span></label>
      <div class="form-row">
        <span class="label">Иконка</span>
        <?= adm_icon_picker('icon', $form['icon']) ?>
      </div>
    </div>
    <div class="card">
      <div class="adm-card-head"><div><h2><?= icon('eye') ?> Предпросмотр</h2><p>Так меню будет выглядеть. Серые пункты встроены и не меняются.</p></div></div>
      <?= adm_nav_preview($links, $id, true) ?>
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

admin_header('Меню слева', 'nav');
?>
<div class="adm-cols adm-cols-side">
  <div class="card">
    <div class="adm-card-head">
      <div>
        <h2><?= icon('list') ?> Пункты меню</h2>
        <p>Дополнительные ссылки в левом меню сайта: правила, жалобы, Discord и т.п.</p>
      </div>
      <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/nav.php', ['new' => 1])) ?>"><?= icon('plus') ?> Добавить пункт</a></div>
    </div>
    <?php if (!$links): ?>
    <div class="adm-empty"><?= icon('list') ?>Своих пунктов пока нет.</div>
    <?php else: ?>
    <form method="post" action="<?= e($self) ?>" id="nav-order">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="order">
    </form>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Пункт</th><th class="hide-sm">Адрес</th><th class="center">Порядок</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($links as $l): $lid = (int)$l['id']; ?>
          <tr>
            <td>
              <div class="adm-cell-main">
                <span class="node-ico"><?= icon($l['icon']) ?></span>
                <div class="adm-cell-text">
                  <a class="adm-cell-title" href="<?= e(url('/admin/nav.php', ['edit' => $lid])) ?>"><?= e($l['title']) ?></a>
                  <?php if ($l['new_tab']): ?><div class="adm-cell-sub"><?= icon('external') ?> в новой вкладке</div><?php endif; ?>
                </div>
              </div>
            </td>
            <td class="hide-sm url-cell"><a class="muted small" href="<?= e(nav_link_href($l['url'])) ?>" target="_blank" rel="noopener" title="<?= e($l['url']) ?>"><?= e($l['url']) ?></a></td>
            <td class="center"><input class="input input-order" type="number" name="order[<?= $lid ?>]" value="<?= (int)$l['display_order'] ?>" form="nav-order" aria-label="Порядок «<?= e($l['title']) ?>»"></td>
            <td class="actions">
              <div class="adm-row-actions">
                <a class="btn btn-sm" href="<?= e(url('/admin/nav.php', ['edit' => $lid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
                <form method="post" action="<?= e($self) ?>" data-confirm="Убрать «<?= e($l['title']) ?>» из меню?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $lid ?>">
                  <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить"><?= icon('trash') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="adm-foot">
      <span class="adm-count">Меньшее число в «Порядке» - выше в меню.</span>
      <button class="btn btn-white" type="submit" form="nav-order"><?= icon('check') ?> Сохранить порядок</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('eye') ?> Как выглядит меню</h2><p>Серые пункты встроены и не меняются.</p></div></div>
    <?= adm_nav_preview($links) ?>
  </div>
</div>
<?php
admin_footer();
