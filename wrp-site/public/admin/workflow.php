<?php
// Админ-панель: рассмотрение жалоб, заявлений, обращений и предложений.
// Вкладки: настройки разделов, ответственные за разделы, автоматизация (доказательства, архив, значки).
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/workflow.php');
if (!wf_ready()) {
    admin_header('Рассмотрение', 'workflow');
    echo '<div class="card"><div class="adm-note warn">' . icon('alert') . '<div>Таблицы модуля ещё не созданы. Откройте <a href="' . e(url('/admin/')) . '">Обзор</a> и нажмите «Обновить базу».</div></div></div>';
    admin_footer();
    exit;
}

$kinds = wf_kinds();
$tabs = ['nodes' => ['Разделы', 'folder'], 'staff' => ['Ответственные', 'users'], 'auto' => ['Автоматизация', 'refresh']];
$tab = query_str('tab', 'nodes');
if (!isset($tabs[$tab])) {
    $tab = 'nodes';
}
$errors = [];
$form = null;

// Уровни, которым можно доверить рассмотрение (лидер и выше)
$levelOpts = [];
foreach (adm_level_options() as $l => $label) {
    if ($l >= 20 && $l < 999) {
        $levelOpts[$l] = $label;
    }
}

// ---------- POST ----------

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'save_node') {
        $nid = input_int('node_id');
        $node = node_get($nid);
        if (!$node || $node['type'] !== 'forum') {
            flash('error', 'Раздел не найден.');
            redirect($self);
        }
        $prefixes = node_prefixes($nid);
        $form = [
            'node_id' => $nid,
            'enabled' => input_bool('enabled') ? 1 : 0,
            'kind' => input('kind'),
            'min_level' => input_int('min_level'),
            'deadline_hours' => input('deadline_hours'),
            'claim_prefix_id' => input_int('claim_prefix_id'),
            'final_prefixes' => implode(',', adm_post_ints('finals')),
            'archive_node_id' => input_int('archive_node_id'),
            'archive_days' => input('archive_days'),
            'voting' => input_bool('voting') ? 1 : 0,
        ];
        if (!isset($kinds[$form['kind']])) {
            $errors['kind'] = 'Выберите вид раздела.';
        }
        if (!isset($levelOpts[$form['min_level']])) {
            $errors['min_level'] = 'Выберите, кто рассматривает темы.';
        }
        $hours = adm_post_range('deadline_hours', 0, 2000);
        if ($hours === null) {
            $errors['deadline_hours'] = 'Срок - целое число часов от 0 до 2000.';
        }
        if ($form['claim_prefix_id'] && !isset($prefixes[$form['claim_prefix_id']])) {
            $errors['claim_prefix_id'] = 'Этот префикс не разрешён в разделе.';
        }
        foreach (wf_ids($form['final_prefixes']) as $p) {
            if (!isset($prefixes[$p])) {
                $errors['finals'] = 'Окончательными могут быть только префиксы раздела.';
            }
        }
        if ($form['archive_node_id']) {
            $an = node_get($form['archive_node_id']);
            if (!$an || $an['type'] !== 'forum' || (int)$an['id'] === $nid) {
                $errors['archive_node_id'] = 'Архивом может быть только другой раздел-форум.';
            }
        }
        $adays = adm_post_range('archive_days', 0, 365);
        if ($adays === null) {
            $errors['archive_days'] = 'Число дней от 0 до 365.';
        }
        if (!$errors) {
            db_exec('INSERT INTO wf_nodes (node_id, enabled, kind, min_level, deadline_hours, claim_prefix_id, final_prefixes, archive_node_id, archive_days, voting, updated_at)
                     VALUES (:n, :e, :k, :l, :h, :cp, :f, :a, :ad, :v, :u)
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), kind = VALUES(kind), min_level = VALUES(min_level), deadline_hours = VALUES(deadline_hours),
                        claim_prefix_id = VALUES(claim_prefix_id), final_prefixes = VALUES(final_prefixes), archive_node_id = VALUES(archive_node_id),
                        archive_days = VALUES(archive_days), voting = VALUES(voting), updated_at = VALUES(updated_at)', [
                'n' => $nid, 'e' => $form['enabled'], 'k' => $form['kind'], 'l' => (int)$form['min_level'], 'h' => $hours,
                'cp' => $form['claim_prefix_id'] ?: null, 'f' => $form['final_prefixes'], 'a' => $form['archive_node_id'] ?: null,
                'ad' => $adays, 'v' => $form['voting'], 'u' => now(),
            ]);
            mod_log('wf_settings', 'node', $nid, $form['enabled'] ? 'включено, срок ' . $hours . ' ч' : 'выключено');
            flash('success', 'Настройки раздела «' . $node['title'] . '» сохранены.');
            redirect($self . '#wf-node-' . $nid);
        }
    }

    if ($action === 'staff_add') {
        $nid = input_int('node_id');
        $node = node_get($nid);
        $u = user_by_name(input('username'));
        $note = mb_substr(input('note'), 0, 64);
        if (!$node || $node['type'] !== 'forum' || !wf_cfg($nid)) {
            flash('error', 'Выберите раздел, где включено рассмотрение.');
        } elseif (!$u) {
            flash('error', 'Пользователь «' . input('username') . '» не найден. Укажите ник на форуме точно.');
        } elseif (!empty($u['is_banned'])) {
            flash('error', 'Пользователь заблокирован.');
        } elseif (!fp_user_can_see($u, $node)) {
            flash('error', $u['username'] . ' не видит этот раздел - сначала дайте доступ.');
        } else {
            db_exec('INSERT INTO wf_node_staff (node_id, user_id, note, created_at) VALUES (:n, :u, :note, :c) ON DUPLICATE KEY UPDATE note = VALUES(note)',
                ['n' => $nid, 'u' => (int)$u['id'], 'note' => $note !== '' ? $note : null, 'c' => now()]);
            mod_log('wf_staff', 'node', $nid, 'Ответственный: ' . $u['username'] . ($note !== '' ? ' (' . $note . ')' : ''));
            flash('success', $u['username'] . ' теперь рассматривает темы в разделе «' . $node['title'] . '».');
        }
        redirect($self . '?tab=staff');
    }

    if ($action === 'staff_remove') {
        $nid = input_int('node_id');
        $uidR = input_int('user_id');
        $u = user_by_id($uidR);
        if (db_exec('DELETE FROM wf_node_staff WHERE node_id = :n AND user_id = :u', ['n' => $nid, 'u' => $uidR])) {
            mod_log('wf_staff', 'node', $nid, 'Снят ответственный: ' . ($u ? $u['username'] : '#' . $uidR));
            flash('success', 'Ответственный снят.');
        }
        redirect($self . '?tab=staff');
    }

    if ($action === 'save_auto') {
        $all = prefixes_all();
        $ev = input_int('wf_evidence_prefix_id');
        $refuse = input_int('wf_evidence_refuse_prefix_id');
        $hours = adm_post_range('wf_evidence_hours', 1, 720);
        $text = input_text('wf_evidence_text');
        $botName = input('bot_username');
        $bot = $botName !== '' ? user_by_name($botName) : null;
        if ($ev && !isset($all[$ev])) {
            $errors['ev'] = 'Выберите префикс из списка.';
        }
        if ($refuse && !isset($all[$refuse])) {
            $errors['refuse'] = 'Выберите префикс из списка.';
        }
        if ($hours === null) {
            $errors['hours'] = 'Срок - от 1 до 720 часов.';
        }
        if (mb_strlen($text) < 10 || mb_strlen($text) > 3000) {
            $errors['text'] = 'Текст ответа - от 10 до 3000 символов.';
        }
        if ($botName !== '' && (!$bot || !empty($bot['is_banned']))) {
            $errors['bot'] = 'Пользователь не найден или заблокирован.';
        }
        if (!$errors) {
            setting_set('wf_evidence_prefix_id', $ev);
            setting_set('wf_evidence_refuse_prefix_id', $refuse);
            setting_set('wf_evidence_hours', $hours);
            setting_set('wf_evidence_text', $text);
            setting_set('wf_bot_user_id', $bot ? (int)$bot['id'] : 0);
            setting_set('wf_list_deadlines', input_bool('wf_list_deadlines') ? '1' : '0');
            setting_set('wf_header_icon', input_bool('wf_header_icon') ? '1' : '0');
            mod_log('wf_settings', 'site', 0, 'автоматизация');
            flash('success', 'Настройки автоматизации сохранены.');
            redirect($self . '?tab=auto');
        }
        $tab = 'auto';
    }

    if ($action === 'run_cron') {
        $a = wf_cron_evidence();
        $b = wf_cron_archive();
        flash('success', 'Готово. Закрыто без доказательств: ' . (int)$a . ', перенесено в архив: ' . (int)$b . '.');
        redirect($self . '?tab=auto');
    }
}

// ---------- Форма настроек раздела ----------

$editId = query_int('edit');
if ($form !== null || $editId) {
    $nid = $form !== null ? (int)$form['node_id'] : $editId;
    $node = node_get($nid);
    if (!$node || $node['type'] !== 'forum') {
        flash('error', 'Раздел не найден.');
        redirect($self);
    }
    if ($form === null) {
        $all = wf_nodes_cfg();
        $form = $all[$nid] ?? ['node_id' => $nid, 'enabled' => 1, 'kind' => 'complaint', 'min_level' => 30, 'deadline_hours' => 48,
            'claim_prefix_id' => null, 'final_prefixes' => '', 'archive_node_id' => null, 'archive_days' => 0, 'voting' => 0];
    }
    $prefixes = node_prefixes($nid);
    $finals = wf_ids($form['final_prefixes']);
    admin_header('Рассмотрение: ' . $node['title'], 'workflow', [[$node['title'], current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_node">
  <input type="hidden" name="node_id" value="<?= $nid ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('clock') ?> <?= e($node['title']) ?></h2><p>Кто рассматривает темы раздела, сколько времени на ответ, куда переносить решённые темы.</p></div>
      <div class="btn-row"><a class="btn btn-sm btn-ghost" href="<?= e(node_url($node)) ?>"><?= icon('external') ?> Открыть раздел</a></div></div>
    <label class="adm-opt"><input type="checkbox" name="enabled" value="1"<?= adm_chk($form['enabled']) ?>><span><b>Рассмотрение включено</b><small>Кнопка «Взять на рассмотрение», ход рассмотрения, сроки и очередь.</small></span></label>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="kind">Что в разделе</label>
        <select class="select" id="kind" name="kind">
          <?php foreach ($kinds as $k => $kn): ?><option value="<?= e($k) ?>"<?= adm_sel($form['kind'], $k) ?>><?= e($kn[0]) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Нужно для подписей: «Ход рассмотрения жалобы», «взял на рассмотрение ваше заявление».</div>
        <?= adm_err($errors, 'kind') ?>
      </div>
      <div class="form-row">
        <label class="label" for="min_level">Кто рассматривает</label>
        <select class="select<?= isset($errors['min_level']) ? ' is-invalid' : '' ?>" id="min_level" name="min_level">
          <?php foreach ($levelOpts as $l => $label): ?><option value="<?= (int)$l ?>"<?= adm_sel($form['min_level'], $l) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Плюс ответственные за раздел (вкладка «Ответственные»). Старшие с уровня 60 могут снимать и передавать чужие темы.</div>
        <?= adm_err($errors, 'min_level') ?>
      </div>
      <div class="form-row">
        <label class="label" for="deadline_hours">Срок ответа, часов</label>
        <input class="input<?= isset($errors['deadline_hours']) ? ' is-invalid' : '' ?>" type="number" min="0" max="2000" id="deadline_hours" name="deadline_hours" value="<?= e($form['deadline_hours']) ?>">
        <div class="hint">Считается от создания темы до окончательного статуса. 0 - без срока. Жалобы - обычно 48, заявления - 72.</div>
        <?= adm_err($errors, 'deadline_hours') ?>
      </div>
      <div class="form-row">
        <label class="label" for="claim_prefix_id">Префикс, когда тему взяли</label>
        <select class="select<?= isset($errors['claim_prefix_id']) ? ' is-invalid' : '' ?>" id="claim_prefix_id" name="claim_prefix_id">
          <option value="0">Не менять</option>
          <?php foreach ($prefixes as $pid => $p): ?><option value="<?= (int)$pid ?>"<?= adm_sel((int)$form['claim_prefix_id'], $pid) ?>><?= e($p['title']) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Обычно «На рассмотрении». Не ставится, если тема уже решена или ждёт доказательств.</div>
        <?= adm_err($errors, 'claim_prefix_id') ?>
      </div>
    </div>
    <div class="form-row">
      <span class="label">Окончательные статусы</span>
      <?php if ($prefixes): ?>
      <div class="adm-checkgrid">
        <?php foreach ($prefixes as $pid => $p): ?>
        <label class="adm-checkpill"><input type="checkbox" name="finals[]" value="<?= (int)$pid ?>"<?= adm_chk(in_array((int)$pid, $finals, true)) ?>><?= prefix_html($pid) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="hint">С таким статусом тема считается решённой: срок останавливается, тема уходит из очереди и через N дней переносится в архив.</div>
      <?php else: ?>
      <div class="adm-note warn"><?= icon('alert') ?><div>В разделе нет разрешённых префиксов. Отметьте их в <a href="<?= e(url('/admin/nodes.php', ['edit' => $nid])) ?>">настройках раздела</a>.</div></div>
      <?php endif; ?>
      <?= adm_err($errors, 'finals') ?>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="archive_node_id">Архив</label>
        <select class="select<?= isset($errors['archive_node_id']) ? ' is-invalid' : '' ?>" id="archive_node_id" name="archive_node_id">
          <option value="0">Без архива</option>
          <?= adm_node_options((int)$form['archive_node_id'], [$nid], ['forum']) ?>
        </select>
        <div class="hint">Куда переносить решённые темы (галочка «Перенести в архив» в решении и автоперенос).</div>
        <?= adm_err($errors, 'archive_node_id') ?>
      </div>
      <div class="form-row">
        <label class="label" for="archive_days">Автоперенос в архив через, дней</label>
        <input class="input<?= isset($errors['archive_days']) ? ' is-invalid' : '' ?>" type="number" min="0" max="365" id="archive_days" name="archive_days" value="<?= e($form['archive_days']) ?>">
        <div class="hint">Решённая тема без новых ответов столько дней переедет в архив и закроется. 0 - выключено.</div>
        <?= adm_err($errors, 'archive_days') ?>
      </div>
    </div>
    <label class="adm-opt"><input type="checkbox" name="voting" value="1"<?= adm_chk($form['voting']) ?>><span><b>Голосование «За / Против»</b><small>Для предложений: кнопки под первым сообщением, счёт в списке тем и сортировка «По рейтингу».</small></span></label>
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

// ---------- Вкладки ----------

admin_header('Рассмотрение', 'workflow');
?>
<nav class="tabs" aria-label="Разделы настроек">
  <?php foreach ($tabs as $k => $t): ?>
  <a class="tab<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('/admin/workflow.php', ['tab' => $k !== 'nodes' ? $k : null])) ?>"><?= e($t[0]) ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($tab === 'nodes'):
    $cfg = wf_nodes_cfg();
    $all = prefixes_all();
?>
<div class="card">
  <div class="adm-card-head">
    <div><h2><?= icon('clock') ?> Разделы с рассмотрением</h2><p>Жалобы, заявления, техподдержка и предложения: кто рассматривает, срок ответа, архив. Нажмите «Настроить», чтобы включить рассмотрение в любом разделе.</p></div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/forum/queue.php')) ?>"><?= icon('wf-inbox') ?> Очередь</a></div>
  </div>
  <div class="table-wrap">
    <table class="table adm-tree">
      <thead><tr><th class="tree-col">Раздел</th><th class="hide-sm">Рассмотрение</th><th class="hide-sm">Кто</th><th class="num hide-sm">Срок</th><th class="hide-md">Окончательные</th><th class="hide-md">Архив</th><th></th></tr></thead>
      <tbody>
      <?php foreach (adm_node_tree() as $row): $n = $row['node']; $nid = (int)$n['id']; $c = $cfg[$nid] ?? null;
          if ($n['type'] === 'link') { continue; }
          $isCat = $n['type'] !== 'forum'; ?>
        <tr id="wf-node-<?= $nid ?>" class="<?= $isCat ? 'is-cat' : '' ?><?= !$isCat && (!$c || !$c['enabled']) ? ' is-muted' : '' ?>">
          <td class="tree-name" style="--d:<?= (int)$row['depth'] ?>"><div class="adm-cell-main"><?= $row['depth'] ? '<span class="tree-corner"></span>' : '' ?><?php if ($isCat): ?><span class="adm-cell-title"><?= e($n['title']) ?></span><?php else: ?><div class="adm-cell-text"><a class="adm-cell-title" href="<?= e(url('/admin/workflow.php', ['edit' => $nid])) ?>"><?= e($n['title']) ?></a><div class="wf-adm-mprefix"><?= $c && $c['enabled'] ? '<span class="tag tag-success">' . icon('check') . e($kinds[$c['kind']][0] ?? 'Вкл') . '</span>' : '<span class="tag">Выкл</span>' ?></div></div><?php endif; ?></div></td>
          <?php if ($isCat): ?>
          <td colspan="6"></td>
          <?php else: ?>
          <td class="hide-sm"><?= $c && $c['enabled'] ? '<span class="tag tag-success">' . icon('check') . e($kinds[$c['kind']][0] ?? 'Вкл') . '</span>' : '<span class="tag">Выкл</span>' ?><?= $c && $c['voting'] ? ' <span class="tag tag-purple">голосование</span>' : '' ?></td>
          <td class="hide-sm small"><?= $c && $c['enabled'] ? e(wf_level_label(wf_min_level($c))) : '' ?></td>
          <td class="num hide-sm"><?= $c && $c['enabled'] ? ((int)$c['deadline_hours'] ? (int)$c['deadline_hours'] . ' ч' : '<span class="muted">нет</span>') : '' ?></td>
          <td class="hide-md"><?php if ($c && $c['enabled']): ?><div class="tags"><?php foreach ($c['finals'] as $p) { echo isset($all[$p]) ? prefix_html($p) : ''; } ?></div><?php endif; ?></td>
          <td class="hide-md small"><?php if ($c && $c['enabled'] && $c['archive_node_id'] && ($an = node_get($c['archive_node_id']))): ?><?= e($an['title']) ?><?= (int)$c['archive_days'] ? ' <span class="muted">· через ' . (int)$c['archive_days'] . ' дн.</span>' : '' ?><?php endif; ?></td>
          <td class="actions"><a class="btn btn-sm" href="<?= e(url('/admin/workflow.php', ['edit' => $nid])) ?>"><?= icon('settings') ?><span class="hide-sm">Настроить</span></a></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($tab === 'staff'):
    $rows = db_all('SELECT s.node_id, s.user_id, s.note, u.username, u.avatar, g.color AS group_color FROM wf_node_staff s
                    JOIN users u ON u.id = s.user_id JOIN user_groups g ON g.id = u.group_id ORDER BY s.node_id, u.username');
    $byNode = [];
    foreach ($rows as $r) {
        $byNode[(int)$r['node_id']][] = $r;
    }
    $enabled = [];
    foreach (adm_node_tree() as $row) {
        if ($row['node']['type'] === 'forum' && wf_cfg($row['node']['id'])) {
            $enabled[] = $row['node'];
        }
    }
?>
<div class="card">
  <div class="adm-card-head"><div><h2><?= icon('users') ?> Ответственные за разделы</h2><p>Например, лидер Правительства рассматривает заявления на трудоустройство и жалобы на сотрудников мэрии, лидер ДП - заявления в академию и жалобы на сотрудников полиции. Ответственный может брать темы и выносить решения только в своих разделах, обычная модерация ему не открывается.</p></div></div>
  <form method="post" action="<?= e($self) ?>" class="wf-staff-add">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="staff_add">
    <div class="form-row">
      <label class="label" for="st-node">Раздел</label>
      <select class="select" id="st-node" name="node_id" required>
        <?php foreach ($enabled as $n): ?><option value="<?= (int)$n['id'] ?>"><?= e($n['title']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="label" for="st-user">Ник на форуме</label>
      <input class="input" id="st-user" name="username" maxlength="32" required placeholder="Имя_Фамилия">
    </div>
    <div class="form-row">
      <label class="label" for="st-note">Подпись</label>
      <input class="input" id="st-note" name="note" maxlength="64" placeholder="Лидер Правительства">
    </div>
    <button class="btn btn-accent" type="submit"><?= icon('plus') ?> Добавить</button>
  </form>
</div>
<div class="card">
  <?php if (!$byNode): ?>
  <div class="adm-empty"><?= icon('users') ?>Ответственных пока нет. Темы рассматривает администрация по уровню доступа.</div>
  <?php else: ?>
  <?php foreach ($byNode as $nid => $list): $n = node_get($nid); ?>
  <div class="wf-staff-node">
    <h3><?= $n ? e($n['title']) : 'Раздел #' . (int)$nid ?><?= $n && !wf_cfg($nid) ? ' <span class="tag tag-warning">рассмотрение выключено</span>' : '' ?></h3>
    <div class="wf-staff-list">
      <?php foreach ($list as $s): ?>
      <span class="wf-staff-item"><?= avatar(['username' => $s['username'], 'avatar' => $s['avatar']], 'xs') ?><?= user_link(['id' => $s['user_id'], 'username' => $s['username'], 'group_color' => $s['group_color']]) ?>
        <?php if ($s['note']): ?><span class="muted small"><?= e($s['note']) ?></span><?php endif; ?>
        <form method="post" action="<?= e($self) ?>" data-confirm="Снять <?= e($s['username']) ?> с раздела?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="staff_remove">
          <input type="hidden" name="node_id" value="<?= (int)$nid ?>">
          <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
          <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Снять"><?= icon('x') ?></button>
        </form>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php else:
    $all = prefixes_all();
    $botId = setting_int('wf_bot_user_id');
    $bot = $botId ? user_by_id($botId) : null;
    $cron = [];
    foreach (db_all("SELECT name, last_run, last_status FROM cron_state WHERE name IN ('wf_evidence', 'wf_archive')") as $r) {
        $cron[$r['name']] = $r;
    }
    $v = function ($k) {
        return is_post() && input('action') === 'save_auto' ? (string)($_POST[$k] ?? '') : (string)setting($k);
    };
?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_auto">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('alert') ?> Ожидание доказательств</h2><p>Если сотрудник поставил статус ожидания, а автор не ответил вовремя, тема получает отказ, закрывается и автор получает оповещение.</p></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="ev">Статус ожидания</label>
        <select class="select" id="ev" name="wf_evidence_prefix_id">
          <option value="0">Выключено</option>
          <?php foreach ($all as $pid => $p): ?><option value="<?= (int)$pid ?>"<?= adm_sel($v('wf_evidence_prefix_id'), $pid) ?>><?= e($p['title']) ?></option><?php endforeach; ?>
        </select>
        <?= adm_err($errors, 'ev') ?>
      </div>
      <div class="form-row">
        <label class="label" for="evh">Ждать ответа автора, часов</label>
        <input class="input" type="number" min="1" max="720" id="evh" name="wf_evidence_hours" value="<?= e($v('wf_evidence_hours')) ?>">
        <?= adm_err($errors, 'hours') ?>
      </div>
      <div class="form-row">
        <label class="label" for="evr">Статус после автозакрытия</label>
        <select class="select" id="evr" name="wf_evidence_refuse_prefix_id">
          <option value="0">Не менять</option>
          <?php foreach ($all as $pid => $p): ?><option value="<?= (int)$pid ?>"<?= adm_sel($v('wf_evidence_refuse_prefix_id'), $pid) ?>><?= e($p['title']) ?></option><?php endforeach; ?>
        </select>
        <?= adm_err($errors, 'refuse') ?>
      </div>
      <div class="form-row">
        <label class="label" for="bot">От чьего имени ответ системы</label>
        <input class="input" id="bot" name="bot_username" maxlength="32" value="<?= e(is_post() ? input('bot_username') : ($bot ? $bot['username'] : '')) ?>" placeholder="Пусто - кто запросил доказательства">
        <div class="hint">Пусто: ответ пишет сотрудник, который запросил доказательства, иначе главный администратор.</div>
        <?= adm_err($errors, 'bot') ?>
      </div>
    </div>
    <div class="form-row">
      <label class="label" for="evt">Текст ответа при автозакрытии</label>
      <textarea class="textarea textarea-sm" id="evt" name="wf_evidence_text" rows="5" maxlength="3000"><?= e($v('wf_evidence_text')) ?></textarea>
      <div class="hint">BB-коды можно. Подстановки: {author}, {staff}, {thread}, {date}, {hours}.</div>
      <?= adm_err($errors, 'text') ?>
    </div>
  </div>
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('eye') ?> Отображение</h2></div></div>
    <label class="adm-opt"><input type="checkbox" name="wf_list_deadlines" value="1"<?= adm_chk($v('wf_list_deadlines') === '1') ?>><span><b>Сроки в списках тем видят все</b><small>Иначе значок «осталось 12 ч» и «Просрочено» в списках видят только те, кто рассматривает раздел. В самой теме срок видят все.</small></span></label>
    <label class="adm-opt"><input type="checkbox" name="wf_header_icon" value="1"<?= adm_chk($v('wf_header_icon') !== '0') ?>><span><b>Значок очереди в шапке</b><small>Число не взятых и просроченных тем для тех, кто рассматривает.</small></span></label>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
  </div>
</form>
<div class="card">
  <div class="adm-card-head"><div><h2><?= icon('refresh') ?> Фоновые задачи</h2><p>Запускаются сами, пока на сайт заходят посетители, или из консоли: <code>php tools/cron.php</code>.</p></div>
    <form method="post" action="<?= e($self) ?>" class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="run_cron"><button class="btn btn-white" type="submit"><?= icon('play') ?> Запустить сейчас</button></form></div>
  <dl class="adm-kv">
    <dt>Автозакрытие без доказательств (раз в 10 минут)</dt><dd><?= isset($cron['wf_evidence']) && $cron['wf_evidence']['last_run'] ? e(fdate($cron['wf_evidence']['last_run'])) . ' · ' . e($cron['wf_evidence']['last_status']) : 'ещё не запускалось' ?></dd>
    <dt>Перенос решённых тем в архив (раз в час)</dt><dd><?= isset($cron['wf_archive']) && $cron['wf_archive']['last_run'] ? e(fdate($cron['wf_archive']['last_run'])) . ' · ' . e($cron['wf_archive']['last_status']) : 'ещё не запускалось' ?></dd>
  </dl>
</div>
<?php endif;
admin_footer();
