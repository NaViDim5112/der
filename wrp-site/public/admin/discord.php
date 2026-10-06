<?php
// Админ-панель: Discord-вебхуки (модуль «Сообщество»)
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/discord.php');
if (!com_ready()) {
    admin_header('Discord', 'discord');
    echo '<div class="card"><div class="adm-note">' . icon('alert') . '<div>Нужно обновить базу сайта: откройте <a href="' . e(url('/admin/')) . '">Обзор</a> и нажмите «Обновить базу».</div></div></div>';
    admin_footer();
    exit;
}

$events = com_discord_events();
$errors = [];
$form = null;

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM com_webhooks WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Вебхук не найден.');
            redirect($self);
        }
        $url = trim(input('url'));
        $nodeIds = [];
        foreach (adm_post_ints('node_ids') as $nid) {
            if (node_get($nid)) {
                $nodeIds[] = $nid;
            }
        }
        $prefixIds = [];
        $allPrefixes = prefixes_all();
        foreach (adm_post_ints('prefix_ids') as $pid) {
            if (isset($allPrefixes[$pid])) {
                $prefixIds[] = $pid;
            }
        }
        $ev = [];
        foreach (array_keys($events) as $k) {
            if (input_bool('event_' . $k)) {
                $ev[] = $k;
            }
        }
        $form = [
            'id' => $id,
            'name' => com_clean_line(input('name')),
            'url_mask' => $old ? $old['url_mask'] : '',
            'node_ids' => implode(',', $nodeIds),
            'events' => implode(',', $ev),
            'prefix_ids' => implode(',', $prefixIds),
            'is_internal' => input_bool('is_internal') ? 1 : 0,
            'is_active' => input_bool('is_active') ? 1 : 0,
        ];
        if ($form['name'] === '' || !adm_len_ok($form['name'], 80)) {
            $errors['name'] = 'Название - от 1 до 80 символов.';
        }
        if ($url === '' && !$old) {
            $errors['url'] = 'Вставьте адрес вебхука из настроек канала Discord.';
        } elseif ($url !== '' && !com_discord_url_ok($url)) {
            $errors['url'] = 'Нужен адрес вида https://discord.com/api/webhooks/123.../abc...';
        }
        if (!$nodeIds) {
            $errors['node_ids'] = 'Отметьте хотя бы один раздел.';
        }
        if (!$ev) {
            $errors['events'] = 'Отметьте хотя бы одно событие.';
        }
        if (!$errors) {
            $data = [
                'name' => $form['name'],
                'node_ids' => $form['node_ids'],
                'events' => $form['events'],
                'prefix_ids' => $form['prefix_ids'],
                'is_internal' => $form['is_internal'],
                'is_active' => $form['is_active'],
            ];
            if ($url !== '') {
                $data['url_enc'] = com_encrypt($url, 'webhook');
                $data['url_mask'] = com_discord_mask($url);
            }
            if ($id) {
                db_update('com_webhooks', $data, 'id = :id', ['id' => $id]);
            } else {
                $data['created_at'] = now();
                $id = db_insert('com_webhooks', $data);
            }
            if (trim((string)setting('com_discord_site_url')) === '') {
                setting_set('com_discord_site_url', site_origin());
            }
            flash('success', 'Вебхук «' . $form['name'] . '» сохранён.');
            redirect($self . '#hook-' . $id);
        }
    }

    if ($action === 'delete') {
        $h = db_one('SELECT * FROM com_webhooks WHERE id = :id', ['id' => input_int('id')]);
        if ($h) {
            db_exec('DELETE FROM com_webhook_queue WHERE webhook_id = :w', ['w' => (int)$h['id']]);
            db_exec('DELETE FROM com_webhooks WHERE id = :w', ['w' => (int)$h['id']]);
            flash('success', 'Вебхук «' . $h['name'] . '» удалён.');
        }
        redirect($self);
    }

    if ($action === 'test') {
        $h = db_one('SELECT * FROM com_webhooks WHERE id = :id', ['id' => input_int('id')]);
        if ($h) {
            $url = com_decrypt($h['url_enc'], 'webhook');
            $payload = [
                'username' => mb_substr((string)setting('site_name'), 0, 80),
                'content' => 'Проверка связи с форумом: вебхук «' . $h['name'] . '» работает.',
                'allowed_mentions' => ['parse' => []],
                'embeds' => [[
                    'title' => 'Проверка вебхука',
                    'url' => com_discord_origin() . url('/forum/'),
                    'description' => 'Сюда будут приходить новые темы и смена статусов из выбранных разделов.',
                    'color' => COM_DISCORD_COLOR,
                    'footer' => ['text' => mb_substr(setting('site_name') . ' · форум', 0, 100)],
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                ]],
            ];
            $err = '';
            $retry = 0;
            $ok = $url !== null && com_discord_send($url, json_encode($payload, JSON_UNESCAPED_UNICODE), $err, $retry);
            db_update('com_webhooks', ['last_status' => $ok ? 'ok' : mb_substr($err !== '' ? $err : 'Не удалось расшифровать адрес', 0, 250), 'last_sent_at' => $ok ? now() : $h['last_sent_at']], 'id = :id', ['id' => (int)$h['id']]);
            flash($ok ? 'success' : 'error', $ok ? 'Проверочное сообщение отправлено в Discord.' : 'Не отправилось: ' . ($err !== '' ? $err : 'адрес не расшифровался, сохраните его заново') . '.');
        }
        redirect($self . '#hook-' . input_int('id'));
    }

    if ($action === 'process') {
        $s = com_discord_process(20, 25);
        flash('success', 'Отправлено: ' . $s['sent'] . ($s['failed'] ? ', не отправлено: ' . $s['failed'] : '') . '.');
        redirect($self . '#queue');
    }

    if ($action === 'retry') {
        db_exec('UPDATE com_webhook_queue SET status = :p, attempts = 0, next_try_at = :t WHERE status = :f', ['p' => 'pending', 't' => now(), 'f' => 'failed']);
        flash('success', 'Неотправленные сообщения снова в очереди.');
        redirect($self . '#queue');
    }

    if ($action === 'settings') {
        $u = rtrim(trim(input('com_discord_site_url')), '/');
        if ($u !== '' && !preg_match('~^https?://[A-Za-z0-9.\-]+(?::\d+)?$~', $u)) {
            flash('error', 'Адрес сайта: только схема и домен, например https://worldrp.ru');
        } else {
            setting_set('com_discord_site_url', $u);
            flash('success', 'Сохранено.');
        }
        redirect($self . '#settings');
    }
}

// Дерево разделов с галочками
$nodeChecks = function (array $selected) {
    $h = '<div class="com-node-checks">';
    foreach (adm_node_tree() as $row) {
        $n = $row['node'];
        if ($n['type'] === 'link') {
            continue;
        }
        $pub = site_node_public($n);
        $h .= '<label class="com-node-check" style="--d:' . (int)$row['depth'] . '"><input type="checkbox" name="node_ids[]" value="' . (int)$n['id'] . '"' . adm_chk(in_array((int)$n['id'], $selected, true)) . '>'
            . '<span>' . e($n['title']) . '</span>' . ($pub ? '' : ' <span class="tag com-tag-lock">' . icon('lock') . ' закрыт</span>') . '</label>';
    }
    return $h . '</div>';
};

// ---------- Форма ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM com_webhooks WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Вебхук не найден.');
                redirect($self);
            }
        } else {
            $form = ['id' => 0, 'name' => '', 'url_mask' => '', 'node_ids' => '', 'events' => 'thread,prefix', 'prefix_ids' => '', 'is_internal' => 0, 'is_active' => 1];
        }
    }
    $id = (int)$form['id'];
    $evSel = explode(',', (string)$form['events']);
    $pfSel = com_ids($form['prefix_ids']);
    admin_header($id ? 'Вебхук: ' . $form['name'] : 'Новый вебхук', 'discord', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('discord') ?> <?= $id ? 'Изменение вебхука' : 'Новый вебхук' ?></h2><p>В Discord: настройки канала - Интеграция - Вебхуки - Новый вебхук - «Копировать URL».</p></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="name">Название <span class="req">*</span></label>
        <input class="input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="80" required placeholder="Например: Канал новостей">
        <?= adm_err($errors, 'name') ?>
      </div>
      <div class="form-row">
        <label class="label" for="url">Адрес вебхука<?= $id ? '' : ' <span class="req">*</span>' ?></label>
        <input class="input<?= isset($errors['url']) ? ' is-invalid' : '' ?>" id="url" name="url" value="" maxlength="255" placeholder="<?= $id ? e($form['url_mask']) : 'https://discord.com/api/webhooks/...' ?>" spellcheck="false">
        <div class="hint"><?= $id ? 'Сохранён: ' . e($form['url_mask']) . '. Оставьте пустым, чтобы не менять.' : 'Адрес хранится зашифрованным и дальше показывается скрытым.' ?></div>
        <?= adm_err($errors, 'url') ?>
      </div>
    </div>
    <div class="form-row">
      <span class="label">События</span>
      <div class="adm-opts adm-opts-2">
        <?php foreach ($events as $k => $ev): ?>
        <label class="adm-opt"><input type="checkbox" name="event_<?= e($k) ?>" value="1"<?= adm_chk(in_array($k, $evSel, true)) ?>><span><b><?= e($ev[0]) ?></b><small><?= e($ev[1]) ?></small></span></label>
        <?php endforeach; ?>
      </div>
      <?= adm_err($errors, 'events') ?>
    </div>
    <div class="form-row">
      <span class="label">Только эти статусы (для «Смена статуса»)</span>
      <div class="adm-checkgrid">
        <?php foreach (prefixes_all() as $pid => $p): ?>
        <label class="adm-checkpill"><input type="checkbox" name="prefix_ids[]" value="<?= (int)$pid ?>"<?= adm_chk(in_array((int)$pid, $pfSel, true)) ?>><?= prefix_html($pid) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="hint">Ничего не отмечено - любой статус. Например, для жалоб можно оставить только «Одобрено» и «Отказано».</div>
    </div>
  </div>

  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('folder') ?> Разделы</h2><p>Подразделы отмеченного раздела тоже попадают в канал.</p></div></div>
    <?= $nodeChecks(com_ids($form['node_ids'])) ?>
    <?= adm_err($errors, 'node_ids') ?>
    <div class="adm-opts adm-opts-2">
      <label class="adm-opt"><input type="checkbox" name="is_internal" value="1"<?= adm_chk($form['is_internal']) ?>><span><b>Служебный канал</b><small>Только для закрытого канала команды: сюда уходят и темы из разделов, закрытых для гостей, и ник модератора.</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="is_active" value="1"<?= adm_chk($form['is_active']) ?>><span><b>Включён</b><small>Выключенный вебхук ничего не отправляет.</small></span></label>
    </div>
    <div class="adm-note"><?= icon('lock') ?><div>Из разделов с пометкой «закрыт» в обычный (не служебный) канал ничего не отправляется, даже если раздел отмечен.</div></div>
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

$hooks = db_all('SELECT * FROM com_webhooks ORDER BY id');
$queue = db_all('SELECT q.*, w.name AS hook_name, t.title AS thread_title FROM com_webhook_queue q LEFT JOIN com_webhooks w ON w.id = q.webhook_id LEFT JOIN threads t ON t.id = q.thread_id ORDER BY q.id DESC LIMIT 20');
$counts = ['pending' => 0, 'failed' => 0, 'sent' => 0];
foreach (db_all('SELECT status, COUNT(*) AS n FROM com_webhook_queue GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['n'];
}
$statusNames = ['pending' => 'в очереди', 'sent' => 'отправлено', 'failed' => 'не отправлено'];
admin_header('Discord', 'discord');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('discord') ?> Discord-вебхуки</h2>
      <p>Новые темы и смена статусов из выбранных разделов уходят в каналы Discord. Отправка идёт в фоне раз в минуту, страницы форума не ждут Discord.</p>
    </div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/discord.php', ['new' => 1])) ?>"><?= icon('plus') ?> Добавить вебхук</a></div>
  </div>
  <?php if (!$hooks): ?>
  <div class="adm-empty"><?= icon('discord') ?>Вебхуков пока нет. Например: новости (раздел «Обновления сервера») - в канал новостей, жалобы - в служебный канал администрации.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Вебхук</th><th class="hide-md">Разделы</th><th class="hide-sm">События</th><th class="hide-sm">Последняя отправка</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($hooks as $h): $hid = (int)$h['id']; ?>
        <tr id="hook-<?= $hid ?>"<?= $h['is_active'] ? '' : ' class="com-row-off"' ?>>
          <td><a class="adm-cell-text com-cell" href="<?= e(url('/admin/discord.php', ['edit' => $hid])) ?>"><span class="adm-cell-title"><?= e($h['name']) ?><?= $h['is_internal'] ? ' <span class="tag com-tag-lock">' . icon('lock') . ' служебный</span>' : '' ?><?= $h['is_active'] ? '' : ' <span class="tag">выключен</span>' ?></span><span class="adm-cell-sub com-mono"><?= e($h['url_mask']) ?></span></a></td>
          <td class="hide-md"><div class="tags"><?php foreach (array_slice(com_ids($h['node_ids']), 0, 3) as $nid): $n = node_get($nid); if ($n): ?><span class="tag"><?= e($n['title']) ?></span><?php endif; endforeach; ?><?= count(com_ids($h['node_ids'])) > 3 ? '<span class="tag">ещё ' . (count(com_ids($h['node_ids'])) - 3) . '</span>' : '' ?></div></td>
          <td class="hide-sm small"><?php $names = []; foreach (explode(',', $h['events']) as $ev) { if (isset($events[$ev])) { $names[] = $events[$ev][2]; } } echo e(acc_ucfirst(implode(', ', $names))); ?></td>
          <td class="hide-sm small"><?php if ($h['last_status'] === 'ok'): ?><span class="com-on"><?= icon('check') ?> <?= e(fdate($h['last_sent_at'])) ?></span><?php elseif ($h['last_status']): ?><span class="field-error"><?= e($h['last_status']) ?></span><?php else: ?><span class="muted">ещё не было</span><?php endif; ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <form method="post" action="<?= e($self) ?>" class="com-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= $hid ?>"><button class="btn btn-sm btn-ghost" type="submit" title="Отправить проверочное сообщение"><?= icon('send') ?><span class="hide-sm">Проверить</span></button></form>
              <a class="btn btn-sm" href="<?= e(url('/admin/discord.php', ['edit' => $hid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <form method="post" action="<?= e($self) ?>" class="com-inline-form" data-confirm="Удалить вебхук «<?= e($h['name']) ?>»?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $hid ?>"><button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить"><?= icon('trash') ?></button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card" id="queue">
  <div class="adm-card-head">
    <div><h2><?= icon('clock') ?> Очередь отправки</h2><p>В очереди: <b><?= num($counts['pending']) ?></b> · отправлено: <?= num($counts['sent']) ?> · не отправлено: <?= num($counts['failed']) ?>. До 3 попыток на сообщение, таймаут 5 секунд.</p></div>
    <div class="btn-row">
      <?php if ($counts['failed']): ?><form method="post" action="<?= e($self) ?>" class="com-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><button class="btn btn-ghost" type="submit"><?= icon('refresh') ?> Повторить неотправленные</button></form><?php endif; ?>
      <form method="post" action="<?= e($self) ?>" class="com-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="process"><button class="btn btn-white" type="submit"<?= $counts['pending'] ? '' : ' disabled' ?>><?= icon('send') ?> Отправить сейчас</button></form>
    </div>
  </div>
  <?php if ($queue): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Тема</th><th class="hide-sm">Куда</th><th>Статус</th><th class="hide-md">Когда</th></tr></thead>
      <tbody>
      <?php foreach ($queue as $q): ?>
        <tr>
          <td><?= $q['thread_title'] !== null ? '<a href="' . e(thread_url($q['thread_id'])) . '">' . e(str_limit($q['thread_title'], 60)) . '</a>' : '<span class="muted">тема удалена</span>' ?><div class="muted small"><?= e($events[$q['event']][0] ?? $q['event']) ?></div></td>
          <td class="hide-sm small"><?= e((string)$q['hook_name']) ?></td>
          <td class="small"><span class="tag<?= $q['status'] === 'sent' ? ' com-tag-on' : ($q['status'] === 'failed' ? ' com-tag-bad' : '') ?>"><?= e($statusNames[$q['status']] ?? $q['status']) ?></span><?= $q['last_error'] ? '<div class="field-error">' . e($q['last_error']) . ' (попыток: ' . (int)$q['attempts'] . ')</div>' : '' ?></td>
          <td class="hide-md muted small"><?= e(fdate($q['sent_at'] ?: $q['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="adm-empty"><?= icon('clock') ?>Очередь пуста.</div>
  <?php endif; ?>
</div>

<form method="post" action="<?= e($self) ?>" class="card" id="settings">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="settings">
  <div class="adm-card-head"><div><h2><?= icon('link') ?> Адрес сайта для ссылок</h2><p>Ссылки на темы в Discord строятся от этого адреса. Укажите настоящий домен с https, когда сайт будет в интернете.</p></div></div>
  <div class="form-row acc-narrow">
    <label class="label" for="com_discord_site_url">Адрес сайта</label>
    <input class="input" id="com_discord_site_url" name="com_discord_site_url" value="<?= e(setting('com_discord_site_url')) ?>" maxlength="120" placeholder="<?= e(site_origin()) ?>">
    <div class="hint">Пусто - адрес, с которого открыт сайт в момент события.</div>
  </div>
  <div class="form-actions"><button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button></div>
</form>
<?php
admin_footer();
