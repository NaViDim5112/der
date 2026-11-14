<?php
// Блокировка на форуме модератором (как на форумах SA-MP проектов: модератор - на срок до 30 дней,
// навсегда - администратор от уровня 60). Снять блокировку может тот, кто её выдал, или сотрудник не ниже его уровня.
require __DIR__ . '/../../app/bootstrap.php';
require_moderator();
mdr_require_ready();

$src = is_post() ? $_POST : $_GET;
$target = user_by_id(isset($src['user']) && is_numeric($src['user']) ? (int)$src['user'] : 0);
if (!$target) {
    abort(404, 'Пользователь не найден.');
}
$tid = (int)$target['id'];
$profile = url('/forum/member.php', ['id' => $tid]);
$err = mdr_punish_error($target);
if ($err !== '') {
    abort(403, $err);
}
$terms = mdr_ban_terms();
$banned = !empty($target['is_banned']) && ($target['ban_until'] === null || strtotime($target['ban_until']) > time());
// Кто выдал действующую блокировку: снять её может он или сотрудник не ниже по уровню
$lastBy = $banned ? (int)db_val("SELECT user_id FROM mod_log WHERE target_type = 'user' AND target_id = :u
    AND action IN ('user_ban', 'warning_ban', 'mod_ban') ORDER BY id DESC LIMIT 1", ['u' => $tid]) : 0;
$overrideErr = $banned ? mdr_override_error($lastBy) : '';
$v = ['days' => 3, 'reason' => ''];
$errors = [];

if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'unban') {
        if (!$banned) {
            flash('info', 'Пользователь уже не заблокирован.');
            redirect($profile);
        }
        if ($overrideErr !== '') {
            flash('error', $overrideErr);
            redirect($profile);
        }
        db_update('users', ['is_banned' => 0, 'ban_reason' => null, 'ban_until' => null], 'id = :id', ['id' => $tid]);
        mod_log('mod_unban', 'user', $tid, $target['ban_reason'] ? 'Была причина: ' . $target['ban_reason'] : '');
        flash('success', 'Блокировка ' . $target['username'] . ' снята.');
        redirect($profile);
    }
    $v['days'] = input_int('days', -1);
    $v['reason'] = trim(input('reason'));
    if (!array_key_exists($v['days'], $terms)) {
        $errors['days'] = 'Выберите срок.';
    }
    if ($v['reason'] === '') {
        $errors['reason'] = 'Укажите причину: её увидит пользователь.';
    } elseif (mb_strlen($v['reason']) > 255) {
        $errors['reason'] = 'Причина слишком длинная (не больше 255 символов).';
    }
    // Нельзя заменить более строгую блокировку старшего более мягкой
    if (!$errors && $banned && $overrideErr !== '') {
        $errors['days'] = $overrideErr;
    }
    if (!$errors) {
        $until = $v['days'] > 0 ? date('Y-m-d H:i:s', time() + $v['days'] * 86400) : null;
        db_update('users', ['is_banned' => 1, 'ban_reason' => $v['reason'], 'ban_until' => $until], 'id = :id', ['id' => $tid]);
        mod_log('mod_ban', 'user', $tid, ($until ? 'до ' . date('d.m.Y H:i', strtotime($until)) : 'навсегда') . '. Причина: ' . $v['reason']);
        alert_add($tid, 'mod_penalty', uid(), null, null, 'ban|' . (string)$until);
        flash('success', $target['username'] . ' заблокирован(а) ' . ($until ? 'до ' . fdate($until) : 'навсегда') . '.');
        redirect($profile);
    }
}

forum_header([
    'title' => 'Блокировка: ' . $target['username'],
    'crumbs' => [['Участники', url('/forum/members.php')], [$target['username'], $profile], ['Блокировка', current_url()]],
    'right' => false,
    'nav' => 'members',
    'css' => ['moderation.css'],
]);
?>
<section class="card mdr-ban-page">
  <h2 class="card-title"><?= icon('ban') ?> Блокировка на форуме</h2>
  <p class="muted">Заблокированный читает форум, но не может писать, ставить реакции и отправлять личные сообщения. Обжаловать блокировку он сможет только в разделе обжалований.</p>
  <?php if ($banned): ?>
  <div class="flash flash-error"><?= icon('ban') ?><div><b><?= e($target['username']) ?> уже заблокирован(а)</b> <?= $target['ban_until'] ? 'до ' . e(fdate($target['ban_until'])) : 'навсегда' ?>.<?= $target['ban_reason'] ? ' Причина: ' . e($target['ban_reason']) : '' ?></div></div>
  <?php if ($overrideErr === ''): ?>
  <form method="post" action="<?= e(url('/forum/ban.php')) ?>" class="form-actions" data-confirm="Снять блокировку <?= e($target['username']) ?>?">
    <?= csrf_field() ?>
    <input type="hidden" name="user" value="<?= $tid ?>">
    <input type="hidden" name="action" value="unban">
    <button class="btn btn-pill btn-outline" type="submit"><?= icon('undo') ?> Снять блокировку</button>
  </form>
  <?php else: ?>
  <p class="muted small"><?= e($overrideErr) ?></p>
  <?php endif; ?>
  <?php endif; ?>
  <?php if (!$banned || $overrideErr === ''): ?>
  <form method="post" action="<?= e(url('/forum/ban.php')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="user" value="<?= $tid ?>">
    <input type="hidden" name="action" value="ban">
    <?php if ($errors): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e(implode(' ', $errors)) ?></div></div><?php endif; ?>
    <div class="form-row">
      <label class="label" for="ban-days">Срок</label>
      <select class="select" id="ban-days" name="days">
        <?php foreach ($terms as $d => $label): ?>
        <option value="<?= (int)$d ?>"<?= (int)$v['days'] === (int)$d ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!isset($terms[0])): ?><div class="hint">Навсегда блокирует администратор.</div><?php endif; ?>
    </div>
    <div class="form-row">
      <label class="label" for="ban-reason">Причина</label>
      <input class="input" id="ban-reason" name="reason" maxlength="255" value="<?= e($v['reason']) ?>" placeholder="Например: реклама сторонних проектов" required>
    </div>
    <div class="form-actions">
      <button class="btn btn-danger btn-pill" type="submit"><?= icon('ban') ?> <?= $banned ? 'Изменить блокировку' : 'Заблокировать' ?></button>
      <a class="btn btn-pill btn-outline" href="<?= e($profile) ?>">Отмена</a>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php
forum_footer();
