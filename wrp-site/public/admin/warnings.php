<?php
// Админка: виды предупреждений, пороги баллов (бан, запрет писать), настройки жалоб, последние предупреждения
require __DIR__ . '/../../app/bootstrap.php';
require_admin();
mdr_require_ready();

$self = url('/admin/warnings.php');
$errors = [];
$typeForm = null;
$ruleForm = null;
$actions = mdr_rule_actions();

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'type_save') {
        $id = input_int('id');
        $typeForm = [
            'id' => $id,
            'title' => trim(preg_replace('~\s+~u', ' ', input('title'))),
            'points' => input('points'),
            'expiry_days' => input('expiry_days'),
            'public_note' => input_bool('public_note') ? 1 : 0,
            'display_order' => input('display_order'),
        ];
        if ($id && !db_val('SELECT 1 FROM mod_warning_types WHERE id = :id', ['id' => $id])) {
            flash('error', 'Вид предупреждения не найден.');
            redirect($self);
        }
        if (mb_strlen($typeForm['title']) < 3 || mb_strlen($typeForm['title']) > 100) {
            $errors['title'] = 'Название: от 3 до 100 символов.';
        }
        $points = adm_post_range('points', 0, 100);
        if ($points === null) {
            $errors['points'] = 'Баллы: целое число от 0 до 100.';
        }
        $days = adm_post_range('expiry_days', 0, 3650);
        if ($days === null) {
            $errors['expiry_days'] = 'Срок: от 0 (бессрочно) до 3650 дней.';
        }
        $order = adm_post_range('display_order', -1000, 1000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = ['title' => $typeForm['title'], 'points' => $points, 'expiry_days' => $days, 'public_note' => $typeForm['public_note'], 'display_order' => $order];
            if ($id) {
                db_update('mod_warning_types', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('mod_warning_types', $data);
            }
            mod_log('warning_type', 'warning_type', $id, $typeForm['title'] . ': ' . $points . ' б., ' . ($days ? $days . ' дн.' : 'бессрочно'));
            flash('success', 'Вид предупреждения «' . $typeForm['title'] . '» сохранён.');
            redirect($self . '#types');
        }
    }

    if ($action === 'type_delete') {
        $t = db_one('SELECT * FROM mod_warning_types WHERE id = :id', ['id' => input_int('id')]);
        if ($t) {
            db_exec('DELETE FROM mod_warning_types WHERE id = :id', ['id' => (int)$t['id']]);
            mod_log('warning_type_del', 'warning_type', $t['id'], $t['title']);
            flash('success', 'Вид «' . $t['title'] . '» удалён. Уже выданные предупреждения остаются.');
        }
        redirect($self . '#types');
    }

    if ($action === 'rule_save') {
        $id = input_int('id');
        $ruleForm = ['id' => $id, 'points' => input('points'), 'action' => input('rule_action'), 'days' => input('days')];
        if ($id && !db_val('SELECT 1 FROM mod_warning_rules WHERE id = :id', ['id' => $id])) {
            flash('error', 'Порог не найден.');
            redirect($self);
        }
        $points = adm_post_range('points', 1, 1000);
        if ($points === null) {
            $errors['rule_points'] = 'Баллы: целое число от 1 до 1000.';
        }
        if (!isset($actions[$ruleForm['action']])) {
            $errors['rule_action'] = 'Выберите, что произойдёт.';
        }
        $days = adm_post_range('days', 0, 3650);
        if ($days === null) {
            $errors['rule_days'] = 'Срок: от 0 (навсегда) до 3650 дней.';
        }
        if (!$errors && db_val('SELECT 1 FROM mod_warning_rules WHERE points = :p AND action = :a AND id <> :id', ['p' => $points, 'a' => $ruleForm['action'], 'id' => $id])) {
            $errors['rule_points'] = 'Такой порог уже есть.';
        }
        if (!$errors) {
            $data = ['points' => $points, 'action' => $ruleForm['action'], 'days' => $days];
            if ($id) {
                db_update('mod_warning_rules', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('mod_warning_rules', $data);
            }
            mod_log('warning_rule', 'warning_rule', $id, $points . ' б.: ' . mdr_rule_text($data));
            flash('success', 'Порог сохранён: ' . $points . ' ' . plural($points, 'балл', 'балла', 'баллов') . ' - ' . mb_strtolower(mdr_rule_text($data)) . '.');
            redirect($self . '#rules');
        }
    }

    if ($action === 'rule_delete') {
        $r = db_one('SELECT * FROM mod_warning_rules WHERE id = :id', ['id' => input_int('id')]);
        if ($r) {
            db_exec('DELETE FROM mod_warning_rules WHERE id = :id', ['id' => (int)$r['id']]);
            db_exec('DELETE FROM mod_warning_triggers WHERE rule_id = :id', ['id' => (int)$r['id']]);
            mod_log('warning_rule_del', 'warning_rule', $r['id'], (int)$r['points'] . ' б.: ' . mdr_rule_text($r));
            flash('success', 'Порог удалён.');
        }
        redirect($self . '#rules');
    }

    if ($action === 'settings') {
        $limit = adm_post_range('mod_report_limit', 1, 200);
        if ($limit === null) {
            $errors['mod_report_limit'] = 'Лимит жалоб: от 1 до 200 в час.';
        } else {
            setting_set('mod_report_limit', (string)$limit);
            setting_set('mod_warn_pm', input_bool('mod_warn_pm') ? '1' : '0');
            mod_log('mod_settings', 'mod_settings', 0, 'Жалоб в час: ' . $limit . ', ЛС о предупреждении: ' . (input_bool('mod_warn_pm') ? 'да' : 'нет'));
            flash('success', 'Настройки сохранены.');
            redirect($self . '#settings');
        }
    }
}

$types = mdr_warning_types();
$rules = mdr_warning_rules();
$usage = [];
foreach (db_all('SELECT type_id, COUNT(*) AS c FROM mod_warnings WHERE type_id IS NOT NULL GROUP BY type_id') as $r) {
    $usage[(int)$r['type_id']] = (int)$r['c'];
}
$editType = query_int('type');
if ($typeForm === null && $editType) {
    $typeForm = db_one('SELECT * FROM mod_warning_types WHERE id = :id', ['id' => $editType]);
}
if ($typeForm === null) {
    $typeForm = ['id' => 0, 'title' => '', 'points' => '1', 'expiry_days' => '30', 'public_note' => 1, 'display_order' => (string)(count($types) + 1)];
}
$editRule = query_int('rule');
if ($ruleForm === null && $editRule) {
    $ruleForm = db_one('SELECT * FROM mod_warning_rules WHERE id = :id', ['id' => $editRule]);
}
if ($ruleForm === null) {
    $ruleForm = ['id' => 0, 'points' => '', 'action' => 'ban', 'days' => '3'];
}
$recent = db_all('SELECT w.*, u.username, u.avatar, g.color AS group_color, iu.username AS issuer_name
    FROM mod_warnings w LEFT JOIN users u ON u.id = w.user_id LEFT JOIN user_groups g ON g.id = u.group_id
    LEFT JOIN users iu ON iu.id = w.issued_by ORDER BY w.id DESC LIMIT 15');
$activeTotal = (int)db_val('SELECT COUNT(*) FROM mod_warnings WHERE revoked_at IS NULL AND is_expired = 0 AND (expires_at IS NULL OR expires_at > :n)', ['n' => now()]);

admin_header('Предупреждения', 'mdr_warnings', [], ['css' => ['admin.css', 'moderation.css'], 'js' => ['admin.js', 'moderation.js']]);
?>
<?= adm_errors_box($errors) ?>

<div class="card" id="types">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('alert') ?> Виды предупреждений</h2>
      <p>Модератор выбирает вид при выдаче. Баллы суммируются, пока предупреждение действует, а по достижении порога срабатывает наказание. Срок 0 - предупреждение не сгорает.</p>
    </div>
  </div>
  <?php if (!$types): ?>
  <div class="adm-empty"><?= icon('alert') ?>Видов пока нет - модераторы смогут выдавать только «своё» предупреждение.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Название</th><th class="num">Баллы</th><th class="num">Срок</th><th class="center hide-sm">Отметка</th><th class="num hide-md">Выдано</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($types as $t): $id = (int)$t['id']; ?>
        <tr>
          <td><b><?= e($t['title']) ?></b></td>
          <td class="num"><?= (int)$t['points'] ?></td>
          <td class="num"><?= (int)$t['expiry_days'] ? (int)$t['expiry_days'] . ' дн.' : 'бессрочно' ?></td>
          <td class="center hide-sm"><?= $t['public_note'] ? '<span class="tag tag-info">под сообщением</span>' : '<span class="muted">-</span>' ?></td>
          <td class="num hide-md"><?= num($usage[$id] ?? 0) ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/warnings.php', ['type' => $id])) ?>#type-form"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить вид «<?= e($t['title']) ?>»? Выданные предупреждения останутся.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="type_delete">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-sm btn-ghost btn-icon" type="submit" title="Удалить"><?= icon('trash') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= e($self) ?>" class="mdr-adm-form" id="type-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="type_save">
    <input type="hidden" name="id" value="<?= (int)$typeForm['id'] ?>">
    <div class="adm-section-title"><?= $typeForm['id'] ? 'Изменить «' . e($typeForm['title']) . '»' : 'Новый вид' ?></div>
    <div class="mdr-adm-grid">
      <div class="form-row mdr-adm-wide">
        <label class="label" for="t-title">Название <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="t-title" name="title" value="<?= e($typeForm['title']) ?>" maxlength="100" required placeholder="Например: Неуважение к администрации">
      </div>
      <div class="form-row">
        <label class="label" for="t-points">Баллы</label>
        <input class="input<?= isset($errors['points']) ? ' is-invalid' : '' ?>" id="t-points" name="points" type="number" min="0" max="100" value="<?= e($typeForm['points']) ?>">
      </div>
      <div class="form-row">
        <label class="label" for="t-days">Срок, дней</label>
        <input class="input<?= isset($errors['expiry_days']) ? ' is-invalid' : '' ?>" id="t-days" name="expiry_days" type="number" min="0" max="3650" value="<?= e($typeForm['expiry_days']) ?>">
      </div>
      <div class="form-row">
        <label class="label" for="t-order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" id="t-order" name="display_order" type="number" value="<?= e($typeForm['display_order']) ?>">
      </div>
    </div>
    <label class="check"><input type="checkbox" name="public_note" value="1"<?= adm_chk($typeForm['public_note']) ?>> По умолчанию показывать под сообщением «Пользователь получил предупреждение»</label>
    <div class="btn-row">
      <button class="btn btn-accent" type="submit"><?= icon('check') ?> <?= $typeForm['id'] ? 'Сохранить' : 'Добавить вид' ?></button>
      <?php if ($typeForm['id']): ?><a class="btn btn-ghost" href="<?= e($self) ?>#types">Отмена</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card" id="rules">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('ban') ?> Пороги баллов</h2>
      <p>Когда действующие баллы пользователя достигают порога, наказание срабатывает один раз. Если баллы сгорят и снова наберутся - сработает снова. Если модератор снимет предупреждение и баллы упадут ниже порога, бан за этот порог снимается.</p>
    </div>
  </div>
  <?php if (!$rules): ?>
  <div class="adm-empty"><?= icon('ban') ?>Порогов нет - баллы копятся без автоматических наказаний.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table mdr-rules-table">
      <thead><tr><th class="num">Баллы</th><th>Что произойдёт</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rules as $r): $id = (int)$r['id']; ?>
        <tr>
          <td class="num"><b><?= (int)$r['points'] ?></b></td>
          <td><span class="tag tag-<?= $r['action'] === 'ban' ? 'danger' : 'warning' ?>"><?= e(mdr_rule_text($r)) ?></span></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/warnings.php', ['rule' => $id])) ?>#rule-form"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить порог <?= (int)$r['points'] ?> баллов?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rule_delete">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-sm btn-ghost btn-icon" type="submit" title="Удалить"><?= icon('trash') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= e($self) ?>" class="mdr-adm-form" id="rule-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="rule_save">
    <input type="hidden" name="id" value="<?= (int)$ruleForm['id'] ?>">
    <div class="adm-section-title"><?= $ruleForm['id'] ? 'Изменить порог' : 'Новый порог' ?></div>
    <div class="mdr-adm-grid">
      <div class="form-row">
        <label class="label" for="r-points">Баллов <span class="req">*</span></label>
        <input class="input<?= isset($errors['rule_points']) ? ' is-invalid' : '' ?>" id="r-points" name="points" type="number" min="1" max="1000" value="<?= e($ruleForm['points']) ?>" required>
      </div>
      <div class="form-row mdr-adm-wide">
        <label class="label" for="r-action">Что произойдёт</label>
        <select class="select<?= isset($errors['rule_action']) ? ' is-invalid' : '' ?>" id="r-action" name="rule_action">
          <?php foreach ($actions as $k => $label): ?><option value="<?= e($k) ?>"<?= adm_sel($ruleForm['action'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label class="label" for="r-days">На сколько дней</label>
        <input class="input<?= isset($errors['rule_days']) ? ' is-invalid' : '' ?>" id="r-days" name="days" type="number" min="0" max="3650" value="<?= e($ruleForm['days']) ?>">
        <div class="hint">0 - навсегда.</div>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn btn-accent" type="submit"><?= icon('check') ?> <?= $ruleForm['id'] ? 'Сохранить' : 'Добавить порог' ?></button>
      <?php if ($ruleForm['id']): ?><a class="btn btn-ghost" href="<?= e($self) ?>#rules">Отмена</a><?php endif; ?>
    </div>
  </form>
</div>

<form method="post" action="<?= e($self) ?>" class="card" id="settings">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="settings">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('settings') ?> Жалобы и оповещения</h2>
      <p>Ограничение защищает модераторов от потока жалоб с одного адреса. На команду форума лимит не действует.</p>
    </div>
  </div>
  <div class="mdr-adm-grid">
    <div class="form-row">
      <label class="label" for="s-limit">Жалоб в час с одного IP</label>
      <input class="input<?= isset($errors['mod_report_limit']) ? ' is-invalid' : '' ?>" id="s-limit" name="mod_report_limit" type="number" min="1" max="200" value="<?= e(setting('mod_report_limit')) ?>">
    </div>
  </div>
  <label class="check mt-1"><input type="checkbox" name="mod_warn_pm" value="1"<?= adm_chk(setting('mod_warn_pm') === '1') ?>> При выдаче предупреждения по умолчанию отмечать «Также отправить личным сообщением»</label>
  <div class="btn-row mt-2"><button class="btn btn-white" type="submit"><?= icon('check') ?> Сохранить</button></div>
</form>

<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('clock') ?> Последние предупреждения</h2>
      <p>Сейчас действует: <?= num($activeTotal) ?>. Полная история пользователя - в его профиле, вкладка «Предупреждения».</p>
    </div>
  </div>
  <?php if (!$recent): ?>
  <div class="adm-empty"><?= icon('check') ?>Предупреждений ещё не выдавали.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Кому</th><th>Предупреждение</th><th class="num">Баллы</th><th class="hide-md">Выдал(а)</th><th>Когда</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $w): $u = mdr_user_row($w['user_id'], $w['username'], $w['avatar'], $w['group_color']); $active = mdr_warning_active($w); ?>
        <tr>
          <td class="nowrap"><div class="adm-cell-main"><?= avatar($u, 'xs') ?><a class="username" href="<?= e(url('/forum/member.php', ['id' => (int)$w['user_id'], 'tab' => 'warnings'])) ?>" style="color:<?= e($w['group_color']) ?>"><?= e($u['username']) ?></a></div></td>
          <td><?= e($w['title']) ?> <?= $w['revoked_at'] !== null ? '<span class="tag">снято</span>' : ($active ? '<span class="tag tag-warning">действует</span>' : '<span class="tag">истекло</span>') ?></td>
          <td class="num">+<?= (int)$w['points'] ?></td>
          <td class="hide-md"><?= e($w['issuer_name'] ?? '-') ?></td>
          <td class="nowrap muted small"><?= e(fdate($w['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
