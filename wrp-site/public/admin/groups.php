<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/groups.php');
$me = user();
$myLevel = user_level();
$errors = [];
$form = null;

// Проверка: останется ли доступ к админке, если группы с правом админки будут $adminIds,
// а у текущего администратора будут группы $myGroups. Возвращает текст ошибки или ''.
function adm_groups_admin_guard(array $adminIds, array $myGroups)
{
    if (adm_admins_count($adminIds) < 1) {
        return 'После этого ни у кого не останется доступа к админ-панели. Сначала дайте доступ другой группе.';
    }
    if (!array_intersect($myGroups, $adminIds)) {
        return 'Вы потеряете доступ к админ-панели: у вас нет другой группы с этим правом.';
    }
    return '';
}

if (is_post()) {
    csrf_check();
    $action = input('action');
    $myGroups = adm_user_group_ids(db_one('SELECT group_id, secondary_groups FROM users WHERE id = :id', ['id' => uid()]));

    if ($action === 'delete') {
        $id = input_int('id');
        $g = db_one('SELECT * FROM user_groups WHERE id = :id', ['id' => $id]);
        if (!$g) {
            flash('error', 'Группа не найдена.');
            redirect($self);
        }
        if ($g['is_system']) {
            flash('error', 'Группу «' . $g['name'] . '» удалить нельзя - она системная.');
            redirect($self);
        }
        if ((int)$g['level'] > $myLevel) {
            flash('error', 'Нельзя удалить группу с уровнем выше вашего.');
            redirect($self);
        }
        $adminIds = array_values(array_diff(adm_admin_group_ids(), [$id]));
        $myAfter = array_values(array_diff($myGroups, [$id]));
        if (!$myAfter || (int)$myGroups[0] === $id) {
            $myAfter[] = WRP_USER_GROUP;
        }
        if ($g['can_admin'] && ($err = adm_groups_admin_guard($adminIds, $myAfter)) !== '') {
            flash('error', $err);
            redirect($self);
        }
        $moved = db_exec('UPDATE users SET group_id = :to WHERE group_id = :g', ['to' => WRP_USER_GROUP, 'g' => $id]);
        foreach (db_all('SELECT id, group_id, secondary_groups FROM users WHERE FIND_IN_SET(:g, secondary_groups)', ['g' => (string)$id]) as $u) {
            $ids = array_values(array_diff(user_secondary_ids($u), [$id]));
            db_update('users', ['secondary_groups' => $ids ? implode(',', $ids) : null], 'id = :id', ['id' => (int)$u['id']]);
        }
        db_exec('DELETE FROM user_groups WHERE id = :id', ['id' => $id]);
        mod_log('group_delete', 'group', $id, $g['name'] . ($moved ? ' (пользователей переведено в «Пользователь»: ' . $moved . ')' : ''));
        flash('success', 'Группа «' . $g['name'] . '» удалена.' . ($moved ? ' ' . $moved . ' ' . plural($moved, 'пользователь переведён', 'пользователя переведены', 'пользователей переведены') . ' в группу «Пользователь».' : ''));
        redirect($self);
    }

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM user_groups WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Группа не найдена.');
            redirect($self);
        }
        $base = $id && adm_is_base_group($id);
        $form = [
            'id' => $id,
            'name' => input('name'),
            'color' => strtolower(input('color')),
            'level' => input('level'),
            'is_staff' => input_bool('is_staff') ? 1 : 0,
            'can_moderate' => $base ? 0 : (input_bool('can_moderate') ? 1 : 0),
            'can_admin' => $base ? 0 : (input_bool('can_admin') ? 1 : 0),
            'show_banner' => input_bool('show_banner') ? 1 : 0,
            'display_order' => input('display_order'),
            'is_system' => $old ? (int)$old['is_system'] : 0,
        ];
        if ($old && (int)$old['level'] > $myLevel) {
            $errors['level'] = 'Эту группу может менять только администратор с уровнем не ниже ' . (int)$old['level'] . '.';
        }
        if ($form['name'] === '') {
            $errors['name'] = 'Укажите название группы.';
        } elseif (!adm_len_ok($form['name'], 64)) {
            $errors['name'] = 'Название слишком длинное (максимум 64 символа).';
        } elseif (db_val('SELECT id FROM user_groups WHERE name = :n AND id <> :id', ['n' => $form['name'], 'id' => $id])) {
            $errors['name'] = 'Группа с таким названием уже есть.';
        }
        if (!adm_color_ok($form['color'])) {
            $errors['color'] = 'Цвет в формате #RRGGBB, например #ff4d6d.';
        }
        $level = adm_post_range('level', 0, 1000);
        if ($level === null) {
            $errors['level'] = 'Уровень - число от 0 до 1000.';
        } elseif ($level > $myLevel && (!$old || $level !== (int)$old['level'])) {
            $errors['level'] = 'Нельзя поставить уровень выше вашего (' . $myLevel . ').';
        } else {
            $form['level'] = $level;
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        } else {
            $form['display_order'] = $order;
        }
        if ($old && $old['can_admin'] && !$form['can_admin'] && !$errors) {
            $adminIds = array_values(array_diff(adm_admin_group_ids(), [$id]));
            if (($err = adm_groups_admin_guard($adminIds, $myGroups)) !== '') {
                $errors['can_admin'] = $err;
            }
        }
        if (!$errors) {
            $data = [
                'name' => $form['name'], 'color' => $form['color'], 'level' => (int)$form['level'],
                'is_staff' => $form['is_staff'], 'can_moderate' => $form['can_moderate'], 'can_admin' => $form['can_admin'],
                'show_banner' => $form['show_banner'], 'display_order' => (int)$form['display_order'],
            ];
            if ($id) {
                db_update('user_groups', $data, 'id = :id', ['id' => $id]);
                flash('success', 'Группа «' . $form['name'] . '» сохранена.');
            } else {
                $data['is_system'] = 0;
                $id = db_insert('user_groups', $data);
                flash('success', 'Группа «' . $form['name'] . '» создана. Назначить её можно в меню «Пользователи».');
            }
            redirect($self . '#group-' . $id);
        }
    }
}

// ---------- Форма ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM user_groups WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Группа не найдена.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'name' => '', 'color' => '#9aa0b4', 'level' => min(20, $myLevel), 'is_staff' => 0, 'can_moderate' => 0, 'can_admin' => 0,
                'show_banner' => 1, 'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM user_groups'), 'is_system' => 0];
        }
    }
    $id = (int)$form['id'];
    $base = $id && adm_is_base_group($id);
    $locked = $id && (int)(db_val('SELECT level FROM user_groups WHERE id = :id', ['id' => $id]) ?? 0) > $myLevel;
    $levels = [];
    foreach (groups_all() as $g) {
        if ((int)$g['id'] !== $id) {
            $levels[] = e($g['name']) . ' - ' . (int)$g['level'];
        }
    }
    admin_header($id ? 'Группа: ' . $form['name'] : 'Новая группа', 'groups', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    $opt = function ($name, $title, $text, $disabled = false) use ($form) {
        return '<label class="adm-opt' . ($disabled ? ' is-disabled' : '') . '"><input type="checkbox" name="' . $name . '" value="1"' . adm_chk($form[$name]) . ($disabled ? ' disabled' : '') . '><span><b>' . e($title) . '</b><small>' . e($text) . '</small></span></label>';
    };
    ?>
<?= adm_errors_box($errors) ?>
<?php if ($locked): ?><div class="flash flash-error"><?= icon('lock') ?><div>Уровень этой группы выше вашего - изменить её нельзя.</div></div><?php endif; ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('shield') ?> <?= $id ? 'Изменение группы' : 'Новая группа' ?></h2><p>Группа задаёт цвет ника, плашку под аватаром и права на форуме.</p></div>
      <?php if ($form['is_system']): ?><span class="tag tag-info"><?= icon('lock') ?> Системная группа - удалить нельзя</span><?php endif; ?>
    </div>
    <div class="adm-cols">
      <div class="form">
        <div class="form-row">
          <label class="label" for="name">Название <span class="req">*</span></label>
          <input class="input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="64" required placeholder="Например: Лидер">
          <?= adm_err($errors, 'name') ?>
        </div>
        <div class="form-row">
          <label class="label" for="color-text">Цвет ника</label>
          <div class="color-row" data-color-pair>
            <input class="input-color" type="color" value="<?= e(adm_color_ok($form['color']) && strlen($form['color']) === 7 ? $form['color'] : '#9aa0b4') ?>" aria-label="Выбрать цвет">
            <input class="input<?= isset($errors['color']) ? ' is-invalid' : '' ?>" type="text" id="color-text" name="color" value="<?= e($form['color']) ?>" maxlength="7" placeholder="#ff4d6d">
          </div>
          <div class="hint">Нажмите на квадрат, чтобы выбрать цвет, или впишите код вида #ff4d6d.</div>
          <?= adm_err($errors, 'color') ?>
        </div>
        <div class="form-grid">
          <div class="form-row">
            <label class="label" for="level">Уровень (0-1000)</label>
            <input class="input<?= isset($errors['level']) ? ' is-invalid' : '' ?>" type="number" id="level" name="level" min="0" max="1000" value="<?= e($form['level']) ?>">
            <?= adm_err($errors, 'level') ?>
          </div>
          <div class="form-row">
            <label class="label" for="display_order">Порядок</label>
            <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
            <?= adm_err($errors, 'display_order') ?>
          </div>
        </div>
        <div class="hint">Уровень определяет доступ к разделам форума: чем больше, тем больше прав. Пользователь - 10, гость - 0. Уровни других групп: <?= implode(', ', $levels) ?>.</div>
      </div>
      <div class="form-row">
        <span class="label">Как это будет выглядеть</span>
        <div class="adm-preview-box">
          <span class="adm-preview-label">Ник</span>
          <span class="username" style="color:<?= e($form['color']) ?>" data-bind-color="color"><span style="color:var(--c, inherit)" data-bind-color="color">Nick_Name</span></span>
          <span class="adm-preview-label">Значок группы</span>
          <span class="group-badge" style="--c:<?= e($form['color']) ?>" data-bind-color="color"><span data-bind-text="name" data-empty="Группа"><?= e($form['name'] ?: 'Группа') ?></span></span>
          <span class="adm-preview-label">Плашка под аватаром</span>
          <div class="user-banner" style="--c:<?= e($form['color']) ?>" data-bind-color="color"><?= icon('user') ?><span data-bind-text="name" data-empty="Группа" data-upper><?= e(mb_strtoupper($form['name'] ?: 'Группа')) ?></span></div>
        </div>
      </div>
    </div>

    <div class="adm-section-title">Права</div>
    <div class="adm-opts adm-opts-2">
      <?= $opt('is_staff', 'Команда проекта', 'Команда проекта (показывается в «Администрации»). На команду не действует пауза между сообщениями.') ?>
      <?= $opt('show_banner', 'Плашка под аватаром', 'Плашка с названием группы под аватаром в темах.') ?>
      <?= $opt('can_moderate', 'Модерация форума', 'Видит все разделы, закрывает, переносит и удаляет темы и сообщения.', $base) ?>
      <?= $opt('can_admin', 'Доступ к админ-панели', 'Полный доступ к этим настройкам. Давайте только тем, кому доверяете.', $base) ?>
    </div>
    <?php if ($base): ?><div class="adm-note"><?= icon('info') ?><div>Это базовая группа (Гость, Пользователь или Заблокирован): модерацию и админ-панель ей включить нельзя.</div></div><?php endif; ?>
    <?= adm_err($errors, 'can_admin') ?>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"<?= $locked ? ' disabled' : '' ?>><?= icon('check') ?> Сохранить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">Отмена</a>
  </div>
</form>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$counts = [];
foreach (db_all('SELECT group_id, COUNT(*) AS c FROM users GROUP BY group_id') as $r) {
    $counts[(int)$r['group_id']] = (int)$r['c'];
}
$groups = db_all('SELECT * FROM user_groups ORDER BY display_order, id');
admin_header('Группы', 'groups');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('shield') ?> Группы пользователей</h2>
      <p>Каждому пользователю назначается основная группа и, при желании, дополнительные. Права складываются: берётся максимум из всех групп.</p>
    </div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/groups.php', ['new' => 1])) ?>"><?= icon('plus') ?> Создать группу</a></div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Группа</th><th class="num">Уровень</th><th>Права</th><th class="num">Участники</th><th class="hide-md">Плашка</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g): $gid = (int)$g['id']; $sec = (int)db_val('SELECT COUNT(*) FROM users WHERE FIND_IN_SET(:g, secondary_groups)', ['g' => (string)$gid]); ?>
        <tr id="group-<?= $gid ?>">
          <td>
            <a href="<?= e(url('/admin/groups.php', ['edit' => $gid])) ?>"><?= adm_group_badge($g) ?></a>
          </td>
          <td class="num"><b><?= (int)$g['level'] ?></b></td>
          <td>
            <div class="tags">
              <?php if ($g['can_admin']): ?><span class="tag tag-danger"><?= icon('shield') ?> Админ-панель</span><?php endif; ?>
              <?php if ($g['can_moderate']): ?><span class="tag tag-success"><?= icon('check') ?> Модерация</span><?php endif; ?>
              <?php if ($g['is_staff']): ?><span class="tag tag-info"><?= icon('star') ?> Команда</span><?php endif; ?>
              <?php if ($g['is_system']): ?><span class="tag"><?= icon('lock') ?> Системная</span><?php endif; ?>
              <?php if (!$g['can_admin'] && !$g['can_moderate'] && !$g['is_staff'] && !$g['is_system']): ?><span class="muted small">обычные права</span><?php endif; ?>
            </div>
          </td>
          <td class="num">
            <a href="<?= e(url('/admin/users.php', ['group' => $gid])) ?>" title="Показать участников"><?= num($counts[$gid] ?? 0) ?></a><?php if ($sec): ?> <span class="muted small" title="Дополнительная группа">+<?= num($sec) ?> доп.</span><?php endif; ?>
          </td>
          <td class="hide-md adm-banner-cell"><?= $g['show_banner'] ? adm_banner_html($g['name'], $g['color']) : '<span class="muted small">нет</span>' ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/groups.php', ['edit' => $gid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <?php if ($g['is_system']): ?>
              <span class="btn btn-sm btn-ghost btn-icon disabled" title="Системную группу удалить нельзя"><?= icon('lock') ?></span>
              <?php else: ?>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить группу «<?= e($g['name']) ?>»? Её участники (<?= (int)($counts[$gid] ?? 0) ?>) станут обычными пользователями.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $gid ?>">
                <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить"><?= icon('trash') ?></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
admin_footer();
