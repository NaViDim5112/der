<?php
// Выдать предупреждение: за сообщение (?type=post&id=), по жалобе (?report=) или из профиля (?user=)
require __DIR__ . '/../../app/bootstrap.php';
require_moderator();
mdr_require_ready();

$src = is_post() ? $_POST : $_GET;
$rid = isset($src['report']) && is_numeric($src['report']) ? (int)$src['report'] : 0;
$type = isset($src['type']) && is_string($src['type']) ? trim($src['type']) : '';
$cid = isset($src['id']) && is_numeric($src['id']) ? (int)$src['id'] : 0;
$userId = isset($src['user']) && is_numeric($src['user']) ? (int)$src['user'] : 0;

$report = null;
$c = null;
if ($rid) {
    $report = mdr_report_get($rid);
    if (!$report) {
        abort(404, 'Жалоба не найдена.');
    }
    $type = $report['content_type'];
    $cid = (int)$report['content_id'];
    $userId = (int)$report['content_user_id'];
}
if ($type !== '' && $type !== 'user') {
    if (!isset(mdr_content_types()[$type]) || $cid <= 0) {
        abort(404, 'Материал не найден.');
    }
    $c = mdr_content_load($type, $cid);
    if ($c) {
        $userId = (int)$c['user_id'];
        // Личную переписку модератор видит только по жалобе
        if ($c['type'] === 'message' && !$report && empty($c['is_member'])) {
            abort(403, 'Предупреждение за личное сообщение выдаётся только по жалобе.');
        }
        if ($c['type'] === 'post' && !$c['visible']) {
            abort(403, 'Нет доступа к этому сообщению.');
        }
    } elseif (!$report) {
        abort(404, 'Материал не найден или удалён.');
    }
} elseif ($type === 'user' && $cid) {
    $userId = $cid;
}

$target = $userId ? user_by_id($userId) : null;
if (!$target) {
    abort(404, 'Пользователь не найден.');
}
$err = mdr_punish_error($target);
if ($err !== '') {
    abort(403, $err);
}
$tid = (int)$target['id'];

$wtypes = [];
foreach (mdr_warning_types() as $t) {
    $wtypes[(int)$t['id']] = $t;
}
$rules = mdr_warning_rules();
$points = mdr_points_active($tid);
$isPost = $c && $c['type'] === 'post';
$items = $report ? (mdr_report_items([$rid])[$rid] ?? []) : [];
$suggest = $report ? mdr_reason_warning_type(mdr_report_main_reason($items)) : 0;
if (!isset($wtypes[$suggest])) {
    $suggest = $wtypes ? (int)array_key_first($wtypes) : 0;
}

if ($report) {
    $back = url('/forum/reports.php', ['id' => $rid]);
} elseif ($c && $c['link'] !== '') {
    $back = $c['link'];
} else {
    $back = url('/forum/member.php', ['id' => $tid, 'tab' => 'warnings']);
}

$v = [
    'type_id' => $suggest ? (string)$suggest : 'custom',
    'title' => '',
    'points' => '1',
    'days' => '30',
    'message' => '',
    'send_pm' => setting('mod_warn_pm') === '1',
    'public_note' => $suggest && isset($wtypes[$suggest]) ? (bool)$wtypes[$suggest]['public_note'] : true,
    'resolve' => true,
];
$errors = [];

if (is_post()) {
    csrf_check();
    $v['type_id'] = input('type_id');
    $v['title'] = input('title');
    $v['points'] = input('points');
    $v['days'] = input('days');
    $v['message'] = input_text('message');
    $v['send_pm'] = input_bool('send_pm');
    $v['public_note'] = input_bool('public_note');
    $v['resolve'] = input_bool('resolve');
    $data = null;
    if ($v['type_id'] === 'custom') {
        $title = trim(preg_replace('~\s+~u', ' ', $v['title']));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 100) {
            $errors['title'] = 'Название: от 3 до 100 символов.';
        }
        if (!preg_match('~^\d{1,3}$~', $v['points']) || (int)$v['points'] > 100) {
            $errors['points'] = 'Баллы: целое число от 0 до 100.';
        }
        if (!preg_match('~^\d{1,4}$~', $v['days']) || (int)$v['days'] > 3650) {
            $errors['days'] = 'Срок: от 0 (бессрочно) до 3650 дней.';
        }
        $data = ['type_id' => null, 'title' => $title, 'points' => (int)$v['points'], 'expiry_days' => (int)$v['days']];
    } elseif (isset($wtypes[(int)$v['type_id']])) {
        $t = $wtypes[(int)$v['type_id']];
        $data = ['type_id' => (int)$t['id'], 'title' => $t['title'], 'points' => (int)$t['points'], 'expiry_days' => (int)$t['expiry_days']];
    } else {
        $errors['type_id'] = 'Выберите вид предупреждения.';
    }
    if (mb_strlen($v['message']) > 2000) {
        $errors['message'] = 'Сообщение пользователю: не больше 2000 символов.';
    }
    if (!$errors) {
        $res = mdr_warn_issue($target, $data + [
            'message' => $v['message'],
            'public_note' => $isPost && $v['public_note'],
            'content_type' => $c ? $c['type'] : ($type === 'user' ? 'user' : null),
            'content_id' => $c ? $c['id'] : ($type === 'user' ? $tid : null),
            'report_id' => $report ? $rid : null,
            'send_pm' => $v['send_pm'],
        ]);
        if ($report && $v['resolve'] && $report['status'] === 'open') {
            mdr_report_resolve($report, 'resolved', 'Выдано предупреждение «' . $data['title'] . '» (+' . $data['points'] . ').');
        }
        flash('success', 'Предупреждение выдано: «' . $data['title'] . '» (+' . mdr_points_text($data['points']) . '). Сейчас у ' . $target['username'] . ' ' . mdr_points_text($res['points']) . '.'
            . ($res['applied'] ? ' Сработало: ' . mb_strtolower(implode(', ', $res['applied'])) . '.' : ''));
        redirect($back);
    }
}

$crumbs = [];
if ($report) {
    $crumbs[] = ['Центр жалоб', url('/forum/reports.php')];
    $crumbs[] = ['Жалоба #' . $rid, $back];
} else {
    $crumbs[] = [$target['username'], url('/forum/member.php', ['id' => $tid])];
}
$crumbs[] = ['Предупреждение', current_url()];
forum_header([
    'title' => 'Предупреждение для ' . $target['username'],
    'crumbs' => $crumbs,
    'right' => false,
    'nav' => '',
    'css' => ['moderation.css'],
    'js' => ['moderation.js'],
]);
$rulesJson = array_map(function ($r) {
    return ['points' => (int)$r['points'], 'text' => mdr_rule_text($r)];
}, $rules);
?>
<?php if ($errors): ?>
<div class="flash flash-error"><?= icon('alert') ?><div><?= e(implode(' ', $errors)) ?></div></div>
<?php endif; ?>
<div class="mdr-warn-page">
  <form method="post" action="<?= e(url('/forum/warn.php')) ?>" class="card form mdr-warn-form" data-mdr-warn data-points="<?= (int)$points ?>" data-rules="<?= e(json_encode($rulesJson, JSON_UNESCAPED_UNICODE)) ?>">
    <?= csrf_field() ?>
    <?php if ($report): ?><input type="hidden" name="report" value="<?= (int)$rid ?>"><?php endif; ?>
    <?php if (!$report && $c): ?><input type="hidden" name="type" value="<?= e($c['type']) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?php endif; ?>
    <?php if (!$report && !$c): ?><input type="hidden" name="user" value="<?= $tid ?>"><?php endif; ?>

    <div class="form-row">
      <span class="label">Вид предупреждения</span>
      <div class="mdr-wtypes" role="radiogroup">
        <?php foreach ($wtypes as $id => $t): ?>
        <label class="mdr-wtype">
          <input type="radio" name="type_id" value="<?= (int)$id ?>"<?= $v['type_id'] === (string)$id ? ' checked' : '' ?> data-points="<?= (int)$t['points'] ?>" data-public="<?= (int)$t['public_note'] ?>">
          <span class="mdr-wtype-box">
            <b><?= e($t['title']) ?></b>
            <span class="muted small">+<?= e(mdr_points_text($t['points'])) ?> · <?= (int)$t['expiry_days'] > 0 ? (int)$t['expiry_days'] . ' ' . plural($t['expiry_days'], 'день', 'дня', 'дней') : 'бессрочно' ?></span>
          </span>
        </label>
        <?php endforeach; ?>
        <label class="mdr-wtype">
          <input type="radio" name="type_id" value="custom"<?= $v['type_id'] === 'custom' ? ' checked' : '' ?> data-custom>
          <span class="mdr-wtype-box"><b>Своё</b><span class="muted small">название, баллы и срок вручную</span></span>
        </label>
      </div>
    </div>

    <div class="mdr-custom" data-mdr-custom>
      <div class="form-row">
        <label class="label" for="w-title">Название</label>
        <input class="input" id="w-title" name="title" value="<?= e($v['title']) ?>" maxlength="100" placeholder="Например: Неуважение к администрации">
      </div>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="w-points">Баллы</label>
          <input class="input" id="w-points" name="points" type="number" min="0" max="100" value="<?= e($v['points']) ?>" data-custom-points>
        </div>
        <div class="form-row">
          <label class="label" for="w-days">Срок, дней</label>
          <input class="input" id="w-days" name="days" type="number" min="0" max="3650" value="<?= e($v['days']) ?>">
          <div class="hint">0 - бессрочно.</div>
        </div>
      </div>
    </div>

    <div class="form-row">
      <label class="label" for="w-message">Сообщение пользователю <span class="muted">(необязательно)</span></label>
      <textarea class="textarea mdr-warn-message" id="w-message" name="message" rows="4" maxlength="2000" placeholder="Что нарушено и как не повторять. Можно BB-коды."><?= e($v['message']) ?></textarea>
      <div class="hint">Пользователь получит оповещение и увидит предупреждение в своём профиле.</div>
    </div>

    <div class="mdr-checks">
      <label class="check"><input type="checkbox" name="send_pm" value="1"<?= $v['send_pm'] ? ' checked' : '' ?>> Также отправить личным сообщением</label>
      <?php if ($isPost): ?>
      <label class="check"><input type="checkbox" name="public_note" value="1"<?= $v['public_note'] ? ' checked' : '' ?> data-public-note> Показать под сообщением «Пользователь получил предупреждение»</label>
      <?php endif; ?>
      <?php if ($report && $report['status'] === 'open'): ?>
      <label class="check"><input type="checkbox" name="resolve" value="1"<?= $v['resolve'] ? ' checked' : '' ?>> Закрыть жалобу #<?= (int)$rid ?>: «Нарушение подтверждено»</label>
      <?php endif; ?>
    </div>

    <div class="mdr-escalation" data-mdr-escalation><?= icon('info') ?><span>Сейчас у пользователя <?= e(mdr_points_text($points)) ?>.</span></div>

    <div class="form-actions">
      <button class="btn btn-accent btn-pill" type="submit"><?= icon('alert') ?> Выдать предупреждение</button>
      <a class="btn btn-ghost btn-pill" href="<?= e($back) ?>">Отмена</a>
    </div>
  </form>

  <aside class="mdr-warn-side">
    <section class="card mdr-target">
      <div class="mdr-target-head">
        <?= avatar($target, 'l') ?>
        <div>
          <?= user_link($target) ?>
          <div class="muted small"><?= e($target['group_name']) ?><?= !empty($target['is_banned']) ? ' · <span class="mdr-red">заблокирован(а)' . ($target['ban_until'] ? ' до ' . e(fdate($target['ban_until'])) : '') . '</span>' : '' ?></div>
        </div>
      </div>
      <div class="mdr-target-points"><b><?= (int)$points ?></b> <?= e(plural($points, 'балл', 'балла', 'баллов')) ?> сейчас</div>
      <?php if ($rules): ?>
      <div class="mdr-thresholds">
        <?php foreach ($rules as $r): ?>
        <span class="mdr-threshold<?= $points >= (int)$r['points'] ? ' is-reached' : '' ?>"><b><?= (int)$r['points'] ?></b> <?= e(mb_strtolower(mdr_rule_text($r))) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <a class="mdr-open-link" href="<?= e(url('/forum/member.php', ['id' => $tid, 'tab' => 'warnings'])) ?>"><?= icon('list') ?> Все предупреждения</a>
    </section>
    <?php if ($c): ?>
    <section class="card">
      <h3 class="mdr-side-title"><?= e(mdr_content_types()[$c['type']]) ?></h3>
      <?php if ($c['context'] !== ''): ?><div class="muted small mdr-ctx"><?= $c['context'] ?></div><?php endif; ?>
      <?= mdr_content_preview($c['body'], 'mdr-preview-short') ?>
    </section>
    <?php elseif ($report): ?>
    <section class="card">
      <h3 class="mdr-side-title">Текст на момент жалобы</h3>
      <?= mdr_content_preview($report['content_snapshot'], 'mdr-preview-short') ?>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php
forum_footer();
