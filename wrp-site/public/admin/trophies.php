<?php
// Админ-панель: награды, выдача вручную, звания по баллам, настройки сообщества (модуль «Сообщество»)
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/trophies.php');
if (!com_ready()) {
    admin_header('Награды и звания', 'trophies');
    echo '<div class="card"><div class="adm-note">' . icon('alert') . '<div>Нужно обновить базу сайта: откройте <a href="' . e(url('/admin/')) . '">Обзор</a> и нажмите «Обновить базу» (или <code>php tools/migrate.php</code>).</div></div></div>';
    admin_footer();
    exit;
}

$criteria = com_trophy_criteria();
$colors = com_colors();
$errors = [];
$form = null;

// Пользователь по имени
$findUser = function ($name) {
    $name = com_clean_line($name);
    return $name !== '' ? user_by_name($name) : null;
};

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM com_trophies WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Награда не найдена.');
            redirect($self);
        }
        $form = [
            'id' => $id,
            'title' => com_clean_line(input('title')),
            'description' => com_clean_line(input('description')),
            'icon' => input('icon'),
            'color' => input('color'),
            'points' => input('points'),
            'criteria' => input('criteria'),
            'criteria_value' => input('criteria_value'),
            'display_order' => input('display_order'),
        ];
        if ($form['title'] === '') {
            $errors['title'] = 'Укажите название награды.';
        } elseif (!adm_len_ok($form['title'], 80)) {
            $errors['title'] = 'Название - не больше 80 символов.';
        }
        if (!adm_len_ok($form['description'], 255)) {
            $errors['description'] = 'Описание - не больше 255 символов.';
        }
        if (!in_array($form['icon'], icon_names(), true)) {
            $errors['icon'] = 'Выберите иконку.';
        }
        if (!isset($colors[$form['color']])) {
            $errors['color'] = 'Выберите цвет из списка.';
        }
        if (!isset($criteria[$form['criteria']])) {
            $errors['criteria'] = 'Выберите условие.';
        }
        $points = adm_post_range('points', 0, 100000);
        if ($points === null) {
            $errors['points'] = 'Баллы - целое число от 0 до 100000.';
        }
        $value = in_array($form['criteria'], ['manual', 'game_linked'], true) ? ($form['criteria'] === 'game_linked' ? 1 : 0) : adm_post_range('criteria_value', 0, 100000000);
        if ($value === null) {
            $errors['criteria_value'] = 'Сколько нужно - целое число от 0.';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = [
                'title' => $form['title'],
                'description' => $form['description'] !== '' ? $form['description'] : null,
                'icon' => $form['icon'],
                'color' => $form['color'],
                'points' => $points,
                'criteria' => $form['criteria'],
                'criteria_value' => $value,
                'display_order' => $order,
            ];
            if ($id) {
                db_update('com_trophies', $data, 'id = :id', ['id' => $id]);
                if ((int)$old['points'] !== $points) {
                    com_recount_holders($id);
                }
                flash('success', 'Награда «' . $form['title'] . '» сохранена.');
            } else {
                $data['created_at'] = now();
                $id = db_insert('com_trophies', $data);
                flash('success', 'Награда «' . $form['title'] . '» создана.' . ($form['criteria'] !== 'manual' ? ' Её получат все, кто подходит, при следующей проверке (раз в час) или по кнопке «Проверить всех сейчас».' : ''));
            }
            redirect(url('/admin/trophies.php', ['edit' => $id]));
        }
    }

    if ($action === 'delete') {
        $t = db_one('SELECT * FROM com_trophies WHERE id = :id', ['id' => input_int('id')]);
        if ($t) {
            $holders = array_map('intval', array_column(db_all('SELECT user_id FROM com_user_trophies WHERE trophy_id = :t', ['t' => (int)$t['id']]), 'user_id'));
            db_exec('DELETE FROM com_user_trophies WHERE trophy_id = :t', ['t' => (int)$t['id']]);
            db_exec('DELETE FROM com_trophies WHERE id = :t', ['t' => (int)$t['id']]);
            db_exec('DELETE FROM alerts WHERE type = :ty AND extra = :x', ['ty' => 'trophy', 'x' => (string)(int)$t['id']]);
            com_trophies_all(true);
            com_recount_points($holders);
            flash('success', 'Награда «' . $t['title'] . '» удалена' . ($holders ? ', снята у ' . count($holders) . ' ' . plural(count($holders), 'участника', 'участников', 'участников') : '') . '.');
        }
        redirect($self);
    }

    if ($action === 'award') {
        $t = db_one('SELECT * FROM com_trophies WHERE id = :id', ['id' => input_int('trophy_id')]);
        $u = $findUser(input('username'));
        $back = safe_return(input('return'), $self);
        if (!$t) {
            flash('error', 'Выберите награду.');
        } elseif (!$u) {
            flash('error', 'Пользователь «' . com_clean_line(input('username')) . '» не найден.');
        } else {
            $note = mb_substr(com_clean_line(input('note')), 0, 255);
            if (com_award($u['id'], $t['id'], uid(), $note)) {
                mod_log('trophy_award', 'user', $u['id'], $t['title'] . ($note !== '' ? ': ' . $note : ''));
                flash('success', 'Награда «' . $t['title'] . '» выдана: ' . $u['username'] . '.');
            } else {
                flash('info', 'У ' . $u['username'] . ' уже есть награда «' . $t['title'] . '».');
            }
        }
        redirect($back);
    }

    if ($action === 'revoke') {
        $t = db_one('SELECT * FROM com_trophies WHERE id = :id', ['id' => input_int('trophy_id')]);
        $u = user_by_id(input_int('user_id'));
        if ($t && $u && com_revoke($u['id'], $t['id'])) {
            mod_log('trophy_revoke', 'user', $u['id'], $t['title']);
            flash('success', 'Награда «' . $t['title'] . '» снята у ' . $u['username'] . '.');
        }
        redirect(safe_return(input('return'), $self));
    }

    if ($action === 'run_now') {
        $n = com_cron_trophies();
        flash('success', $n ? 'Выдано наград: ' . $n . '.' : 'Все, кто заслужил автоматические награды, уже их получили.');
        redirect($self);
    }

    if ($action === 'ranks_save') {
        $rows = $_POST['rank'] ?? [];
        $clean = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r) || !empty($r['del'])) {
                    continue;
                }
                $title = com_clean_line(is_string($r['title'] ?? null) ? $r['title'] : '');
                $min = is_string($r['min'] ?? null) ? trim($r['min']) : '';
                if ($title === '' && $min === '') {
                    continue;
                }
                if ($title === '' || !adm_len_ok($title, 48)) {
                    $errors['ranks'] = 'У каждого звания должно быть название до 48 символов.';
                    continue;
                }
                if (!preg_match('~^\d{1,9}$~', $min)) {
                    $errors['ranks'] = 'Баллы для звания «' . $title . '» - целое число от 0.';
                    continue;
                }
                $clean[] = ['title' => $title, 'min_points' => (int)$min, 'color' => com_color_key($r['color'] ?? '')];
            }
        }
        if (!$errors && !$clean) {
            $errors['ranks'] = 'Нужно хотя бы одно звание.';
        }
        if (!$errors) {
            usort($clean, function ($a, $b) {
                return $a['min_points'] - $b['min_points'];
            });
            $pdo = db();
            $pdo->beginTransaction();
            try {
                db_exec('DELETE FROM com_ranks');
                foreach ($clean as $r) {
                    db_insert('com_ranks', $r);
                }
                $pdo->commit();
            } catch (Exception $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            flash('success', 'Звания сохранены (' . count($clean) . ').');
            redirect($self . '#ranks');
        }
        flash('error', $errors['ranks']);
        redirect($self . '#ranks');
    }

    if ($action === 'settings_save') {
        $n = adm_post_range('com_post_trophies', 0, 8);
        setting_set('com_post_trophies', (string)($n === null ? 4 : $n));
        setting_set('com_trophy_alerts', input_bool('com_trophy_alerts') ? '1' : '0');
        setting_set('com_staff_widget', input_bool('com_staff_widget') ? '1' : '0');
        flash('success', 'Настройки сохранены.');
        redirect($self . '#settings');
    }
}

// ---------- Форма награды ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM com_trophies WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Награда не найдена.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'title' => '', 'description' => '', 'icon' => 'trophy', 'color' => 'orange', 'points' => 10, 'criteria' => 'manual', 'criteria_value' => 0,
                'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM com_trophies')];
        }
    }
    $id = (int)$form['id'];
    $holders = [];
    $holdersTotal = 0;
    if ($id) {
        $holdersTotal = (int)db_val('SELECT COUNT(*) FROM com_user_trophies WHERE trophy_id = :t', ['t' => $id]);
        $holders = db_all('SELECT ut.*, u.username, u.avatar, g.color AS group_color, a.username AS by_name
            FROM com_user_trophies ut JOIN users u ON u.id = ut.user_id JOIN user_groups g ON g.id = u.group_id LEFT JOIN users a ON a.id = ut.awarded_by
            WHERE ut.trophy_id = :t ORDER BY ut.awarded_at DESC LIMIT 100', ['t' => $id]);
    }
    $preview = ['title' => $form['title'] !== '' ? $form['title'] : 'Награда', 'icon' => $form['icon'], 'color' => com_color_key($form['color'])];
    admin_header($id ? 'Награда: ' . $form['title'] : 'Новая награда', 'trophies', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('trophy') ?> <?= $id ? 'Изменение награды' : 'Новая награда' ?></h2><p>Награды видны в профиле на вкладке «Награды» и на странице всех наград. Баллы прибавляются к баллам пользователя и влияют на звание.</p></div>
      <div class="com-adm-preview"><?= com_trophy_badge($preview, 'l') ?></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="title">Название <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="80" required placeholder="Например: Помощь проекту">
        <?= adm_err($errors, 'title') ?>
      </div>
      <div class="form-row">
        <label class="label" for="description">Описание</label>
        <input class="input<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" value="<?= e((string)$form['description']) ?>" maxlength="255" placeholder="За что выдаётся">
        <?= adm_err($errors, 'description') ?>
      </div>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="criteria">Условие</label>
        <select class="select<?= isset($errors['criteria']) ? ' is-invalid' : '' ?>" id="criteria" name="criteria" data-com-criteria>
          <?php foreach ($criteria as $k => $c): ?><option value="<?= e($k) ?>"<?= adm_sel($form['criteria'], $k) ?>><?= e($c[0]) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Автоматические награды выдаются сами раз в час и сразу после нового сообщения.</div>
        <?= adm_err($errors, 'criteria') ?>
      </div>
      <div class="form-row" data-com-criteria-value>
        <label class="label" for="criteria_value">Сколько нужно</label>
        <input class="input<?= isset($errors['criteria_value']) ? ' is-invalid' : '' ?>" type="number" min="0" id="criteria_value" name="criteria_value" value="<?= e($form['criteria_value']) ?>">
        <div class="hint">Например, 100 сообщений или 30 дней. 0 дней - сразу после регистрации.</div>
        <?= adm_err($errors, 'criteria_value') ?>
      </div>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="points">Баллы</label>
        <input class="input<?= isset($errors['points']) ? ' is-invalid' : '' ?>" type="number" min="0" max="100000" id="points" name="points" value="<?= e($form['points']) ?>">
        <?= adm_err($errors, 'points') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
        <div class="hint">Меньшее число - выше в списке.</div>
        <?= adm_err($errors, 'display_order') ?>
      </div>
    </div>
    <div class="form-row">
      <span class="label">Цвет</span>
      <div class="com-color-pick">
        <?php foreach ($colors as $ck => $c): ?>
        <label class="com-color-opt com-c-<?= e($ck) ?>" title="<?= e($c[0]) ?>"><input type="radio" name="color" value="<?= e($ck) ?>"<?= adm_chk($form['color'] === $ck) ?>><span class="com-swatch"></span><span class="com-color-name"><?= e($c[0]) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?= adm_err($errors, 'color') ?>
    </div>
    <div class="form-row">
      <span class="label">Иконка</span>
      <?= adm_icon_picker('icon', $form['icon']) ?>
      <?= adm_err($errors, 'icon') ?>
    </div>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">К списку</a>
  </div>
</form>

<?php if ($id): ?>
<div class="card">
  <div class="adm-card-head"><div><h2><?= icon('users') ?> У кого есть <span class="muted">(<?= num($holdersTotal) ?>)</span></h2><p>Последние 100. Снять награду можно здесь или в профиле пользователя.</p></div></div>
  <form method="post" action="<?= e($self) ?>" class="com-award-form is-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="award">
    <input type="hidden" name="trophy_id" value="<?= $id ?>">
    <input type="hidden" name="return" value="<?= e(url('/admin/trophies.php', ['edit' => $id])) ?>">
    <input class="input" name="username" maxlength="32" placeholder="Имя пользователя" required>
    <input class="input" name="note" maxlength="255" placeholder="За что (необязательно)">
    <button class="btn btn-white" type="submit"><?= icon('gift') ?> Выдать</button>
  </form>
  <?php if ($holders): ?>
  <div class="table-wrap mt-1">
    <table class="table">
      <thead><tr><th>Пользователь</th><th class="hide-sm">Когда</th><th class="hide-md">Кто выдал / за что</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($holders as $hld): ?>
        <tr>
          <td><div class="adm-cell-main"><?= avatar($hld, 's') ?><?= user_link(['id' => $hld['user_id'], 'username' => $hld['username'], 'group_color' => $hld['group_color']]) ?></div></td>
          <td class="hide-sm muted"><?= e(fdate($hld['awarded_at'])) ?></td>
          <td class="hide-md muted small"><?= $hld['by_name'] ? e($hld['by_name']) : 'автоматически' ?><?= $hld['note'] ? ' · ' . e($hld['note']) : '' ?></td>
          <td class="actions">
            <form method="post" action="<?= e($self) ?>" data-confirm="Снять награду у <?= e($hld['username']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="trophy_id" value="<?= $id ?>">
              <input type="hidden" name="user_id" value="<?= (int)$hld['user_id'] ?>">
              <input type="hidden" name="return" value="<?= e(url('/admin/trophies.php', ['edit' => $id])) ?>">
              <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Снять"><?= icon('x') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="adm-empty"><?= icon('trophy') ?>Эту награду ещё никто не получил.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$trophies = db_all('SELECT t.*, (SELECT COUNT(*) FROM com_user_trophies ut WHERE ut.trophy_id = t.id) AS holders FROM com_trophies t ORDER BY t.display_order, t.id');
$ranks = com_ranks_all();
$last = db_one('SELECT last_run, last_status FROM cron_state WHERE name = :n', ['n' => 'com_trophies']);
admin_header('Награды и звания', 'trophies');
?>
<nav class="adm-anchors" aria-label="Разделы">
  <a href="#trophies">Награды</a>
  <a href="#award">Выдать награду</a>
  <a href="#ranks">Звания</a>
  <a href="#settings">Настройки</a>
</nav>

<div class="card" id="trophies">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('trophy') ?> Награды</h2>
      <p>Автоматические награды проверяются раз в час<?= $last && $last['last_run'] ? ' (последний раз: ' . e(fdate($last['last_run'])) . ')' : '' ?> и сразу после сообщения или входа пользователя.</p>
    </div>
    <div class="btn-row">
      <form method="post" action="<?= e($self) ?>" class="com-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="run_now"><button class="btn btn-ghost" type="submit"><?= icon('refresh') ?> Проверить всех сейчас</button></form>
      <a class="btn btn-white" href="<?= e(url('/admin/trophies.php', ['new' => 1])) ?>"><?= icon('plus') ?> Создать награду</a>
    </div>
  </div>
  <?php if (!$trophies): ?>
  <div class="adm-empty"><?= icon('trophy') ?>Наград пока нет.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Награда</th><th class="hide-md">Условие</th><th class="num">Баллы</th><th class="num hide-sm">У кого</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($trophies as $t): $tid = (int)$t['id']; ?>
        <tr>
          <td><a class="adm-cell-main" href="<?= e(url('/admin/trophies.php', ['edit' => $tid])) ?>"><?= com_trophy_badge($t, 's') ?><span class="adm-cell-text com-cell"><span class="adm-cell-title"><?= e($t['title']) ?></span><span class="adm-cell-sub hide-sm"><?= e(str_limit((string)$t['description'], 70)) ?></span></span></a></td>
          <td class="hide-md muted small"><?= e(com_trophy_goal_text($t)) ?></td>
          <td class="num"><?= num($t['points']) ?></td>
          <td class="num hide-sm"><?= num($t['holders']) ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/trophies.php', ['edit' => $tid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить награду «<?= e($t['title']) ?>»?<?= $t['holders'] ? ' Она будет снята у ' . (int)$t['holders'] . ' ' . plural($t['holders'], 'участника', 'участников', 'участников') . '.' : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $tid ?>">
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

<div class="card" id="award">
  <div class="adm-card-head"><div><h2><?= icon('gift') ?> Выдать награду</h2><p>Обычно вручную выдают награды с условием «Выдаёт администрация». Пользователь получит оповещение.</p></div></div>
  <form method="post" action="<?= e($self) ?>" class="com-award-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="award">
    <input class="input" name="username" maxlength="32" placeholder="Имя пользователя" required>
    <select class="select" name="trophy_id" aria-label="Награда">
      <?php foreach (com_trophies_manual_first($trophies) as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['title']) ?><?= $t['criteria'] === 'manual' ? '' : ' (автоматическая)' ?></option><?php endforeach; ?>
    </select>
    <input class="input" name="note" maxlength="255" placeholder="За что (необязательно)">
    <button class="btn btn-white" type="submit"><?= icon('check') ?> Выдать</button>
  </form>
</div>

<form method="post" action="<?= e($self) ?>" class="card" id="ranks">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="ranks_save">
  <div class="adm-card-head"><div><h2><?= icon('award') ?> Звания по баллам</h2><p>Звание показывается в карточке автора в темах и в профиле. Баллы: темы x3 + сообщения + реакции x2 + баллы за награды.</p></div></div>
  <div class="com-ranks-edit">
    <div class="com-ranks-head"><span>Название</span><span>От баллов</span><span>Цвет</span><span></span></div>
    <?php $i = 0; foreach (array_merge($ranks, [['id' => 0, 'title' => '', 'min_points' => '', 'color' => 'gray'], ['id' => 0, 'title' => '', 'min_points' => '', 'color' => 'gray']]) as $r): ?>
    <div class="com-ranks-row">
      <input class="input" name="rank[<?= $i ?>][title]" value="<?= e($r['title']) ?>" maxlength="48" placeholder="<?= $r['id'] ? '' : 'Новое звание' ?>" aria-label="Название">
      <input class="input" type="number" min="0" name="rank[<?= $i ?>][min]" value="<?= e($r['min_points']) ?>" placeholder="0" aria-label="От баллов">
      <select class="select" name="rank[<?= $i ?>][color]" aria-label="Цвет">
        <?php foreach ($colors as $ck => $c): ?><option value="<?= e($ck) ?>"<?= adm_sel($r['color'], $ck) ?>><?= e($c[0]) ?></option><?php endforeach; ?>
      </select>
      <?php if ($r['id']): ?>
      <label class="check com-ranks-del" title="Удалить звание"><input type="checkbox" name="rank[<?= $i ?>][del]" value="1"><span><?= icon('trash') ?></span></label>
      <?php else: ?><span></span><?php endif; ?>
    </div>
    <?php $i++; endforeach; ?>
  </div>
  <div class="hint">Чтобы добавить звание, заполните пустую строку. Чтобы удалить - отметьте корзину. Порядок выстроится по баллам сам.</div>
  <div class="form-actions mt-1"><button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить звания</button></div>
</form>

<form method="post" action="<?= e($self) ?>" class="card" id="settings">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="settings_save">
  <div class="adm-card-head"><div><h2><?= icon('settings') ?> Настройки сообщества</h2></div></div>
  <div class="form-grid">
    <div class="form-row">
      <label class="label" for="com_post_trophies">Значков наград под аватаром в темах</label>
      <input class="input" type="number" min="0" max="8" id="com_post_trophies" name="com_post_trophies" value="<?= e(setting('com_post_trophies')) ?>">
      <div class="hint">0 - не показывать. Показываются последние полученные.</div>
    </div>
    <div class="form-row adm-opts">
      <label class="adm-opt"><input type="checkbox" name="com_trophy_alerts" value="1"<?= adm_chk(setting('com_trophy_alerts') === '1') ?>><span><b>Оповещать о новых наградах</b><small>«Вы получили награду ...» в колокольчике.</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="com_staff_widget" value="1"<?= adm_chk(setting('com_staff_widget') === '1') ?>><span><b>Виджет «Команда проекта онлайн»</b><small>В правой колонке форума, если кто-то из команды на сайте.</small></span></label>
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button></div>
</form>
<?php
admin_footer();
