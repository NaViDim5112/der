<?php
// Админка: вложения (картинки в сообщениях), настройки загрузки, опросов и новостей для лаунчера
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/attachments.php');
$ready = cnt_ready();
$errors = [];

$keys = ['attach_enabled', 'attach_max_mb', 'attach_max_px', 'attach_daily_files', 'attach_daily_mb', 'poll_nodes', 'news_api_node2', 'news_api_limit'];
$v = [];
foreach ($keys as $k) {
    $v[$k] = (string)setting($k);
}

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'delete' && $ready) {
        $row = db_one('SELECT * FROM attachments WHERE id = :id', ['id' => input_int('id')]);
        if ($row) {
            cnt_attach_delete($row);
            mod_log('attach_delete', 'attachment', $row['id'], $row['path']);
            flash('success', 'Вложение удалено.');
        }
        redirect(safe_return(input('return'), $self));
    }

    if ($action === 'cleanup' && $ready) {
        $n = cnt_attach_cleanup(5000);
        flash('success', $n ? 'Удалено неиспользованных файлов: ' . $n . '.' : 'Неиспользованных файлов старше суток нет.');
        redirect($self);
    }

    if ($action === 'save') {
        $in = ['attach_enabled' => input_bool('attach_enabled') ? '1' : '0'];
        $ranges = [
            'attach_max_mb' => [1, 64, 'Размер файла - от 1 до 64 МБ.'],
            'attach_max_px' => [800, 8000, 'Сторона картинки - от 800 до 8000 точек.'],
            'attach_daily_files' => [0, 1000, 'Файлов в сутки - от 0 до 1000.'],
            'attach_daily_mb' => [0, 10000, 'МБ в сутки - от 0 до 10000.'],
            'news_api_limit' => [1, 20, 'Новостей по умолчанию - от 1 до 20.'],
        ];
        foreach ($ranges as $k => $r) {
            $n = adm_post_range($k, $r[0], $r[1]);
            if ($n === null) {
                $errors[$k] = $r[2];
                $in[$k] = input($k);
            } else {
                $in[$k] = (string)$n;
            }
        }
        if (input('poll_mode') === 'list') {
            $ids = [];
            foreach (adm_post_ints('poll_node_ids') as $id) {
                $n = node_get($id);
                if ($n && $n['type'] === 'forum') {
                    $ids[] = $id;
                }
            }
            sort($ids);
            $in['poll_nodes'] = $ids ? implode(',', $ids) : '0';
        } else {
            $in['poll_nodes'] = 'auto';
        }
        $node2 = input_int('news_api_node2');
        if ($node2 && !node_get($node2)) {
            $errors['news_api_node2'] = 'Такого раздела нет.';
        }
        $in['news_api_node2'] = (string)max(0, $node2);
        $v = array_merge($v, $in);
        if (!$errors) {
            $changed = 0;
            foreach ($in as $k => $val) {
                if ((string)setting($k) !== $val) {
                    setting_set($k, $val);
                    $changed++;
                }
            }
            flash('success', $changed ? 'Настройки сохранены.' : 'Ничего не изменилось.');
            redirect($self);
        }
    }
}

// ---------- Данные ----------
$stats = ['files' => 0, 'bytes' => 0, 'orphans' => 0, 'polls' => 0, 'votes' => 0, 'bookmarks' => 0];
$rows = [];
$pg = paginate(0, 30, 1);
$fUser = query_str('user');
$fOrphan = query_int('orphan') === 1;
if ($ready) {
    $s = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS b, COALESCE(SUM(post_id IS NULL), 0) AS o FROM attachments');
    $stats['files'] = (int)$s['n'];
    $stats['bytes'] = (int)$s['b'];
    $stats['orphans'] = (int)$s['o'];
    $stats['polls'] = (int)db_val('SELECT COUNT(*) FROM polls');
    $stats['votes'] = (int)db_val('SELECT COUNT(*) FROM poll_votes');
    $stats['bookmarks'] = (int)db_val('SELECT COUNT(*) FROM bookmarks');

    $where = [];
    $params = [];
    if ($fUser !== '') {
        $where[] = 'u.username LIKE :u';
        $params['u'] = adm_like($fUser);
    }
    if ($fOrphan) {
        $where[] = 'a.post_id IS NULL';
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from = ' FROM attachments a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN user_groups g ON g.id = u.group_id';
    $total = (int)db_val('SELECT COUNT(*)' . $from . $w, $params);
    $pg = paginate($total, 30, query_int('page', 1));
    $rows = db_all('SELECT a.*, u.username, g.color AS group_color, p.thread_id, t.title AS thread_title' . $from . '
        LEFT JOIN posts p ON p.id = a.post_id LEFT JOIN threads t ON t.id = p.thread_id' . $w . '
        ORDER BY a.id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
}
$pollMode = ($v['poll_nodes'] === '' || $v['poll_nodes'] === 'auto') ? 'auto' : 'list';
$pollIds = $pollMode === 'list' ? array_map('intval', explode(',', $v['poll_nodes'])) : [];
$phpLimit = min(acc_ini_bytes(ini_get('upload_max_filesize')) ?: PHP_INT_MAX, acc_ini_bytes(ini_get('post_max_size')) ?: PHP_INT_MAX);
$free = @disk_free_space(WRP_PUBLIC . '/uploads');

admin_header('Вложения и опросы', 'attachments');

$field = function ($k, $label, $hint, $attrs) use ($v, $errors) {
    return '<div class="form-row"><label class="label" for="' . e($k) . '">' . $label . '</label>'
        . '<input class="input' . (isset($errors[$k]) ? ' is-invalid' : '') . '" type="number" id="' . e($k) . '" name="' . e($k) . '" value="' . e($v[$k]) . '" ' . $attrs . '>'
        . ($hint !== '' ? '<div class="hint">' . $hint . '</div>' : '') . adm_err($errors, $k) . '</div>';
};
?>
<?php if (!$ready): ?>
<div class="flash flash-error"><?= icon('alert') ?><div>Таблицы вложений, опросов и закладок ещё не созданы. Откройте <a href="<?= e(url('/admin/')) ?>">Обзор</a> и нажмите «Обновить базу».</div></div>
<?php endif; ?>
<?= adm_errors_box($errors) ?>

<div class="adm-tiles">
  <div class="adm-tile" style="--tc:#14b8a6"><span class="adm-tile-icon"><?= icon('image') ?></span><div><b><?= num($stats['files']) ?></b><small>Файлов загружено</small></div></div>
  <div class="adm-tile" style="--tc:#3b82f6"><span class="adm-tile-icon"><?= icon('folder') ?></span><div><b><?= e(cnt_bytes_text($stats['bytes'])) ?></b><small>Занято<?= $free ? ', свободно ' . e(cnt_bytes_text($free)) : '' ?></small></div></div>
  <div class="adm-tile" style="--tc:#f59e0b"><span class="adm-tile-icon"><?= icon('clock') ?></span><div><b><?= num($stats['orphans']) ?></b><small>Не прикреплены к сообщениям</small></div></div>
  <div class="adm-tile" style="--tc:#a855f7"><span class="adm-tile-icon"><?= icon('poll') ?></span><div><b><?= num($stats['polls']) ?></b><small>Опросов, голосов: <?= num($stats['votes']) ?></small></div></div>
  <div class="adm-tile" style="--tc:#ec4899"><span class="adm-tile-icon"><?= icon('bookmark') ?></span><div><b><?= num($stats['bookmarks']) ?></b><small>Закладок у пользователей</small></div></div>
</div>

<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <div class="card" id="sec-attach">
    <div class="adm-card-head"><div><h2><?= icon('image') ?> Загрузка картинок</h2><p>Кнопка «Загрузить», перетаскивание и вставка из буфера в редакторе сообщений и в полях-ссылках анкет (доказательства к жалобам).</p></div></div>
    <label class="adm-opt"><input type="checkbox" name="attach_enabled" value="1"<?= adm_chk($v['attach_enabled'] === '1') ?>><span><b>Загрузка включена</b><small>Если выключить, кнопка пропадёт, а уже загруженные картинки останутся в сообщениях.</small></span></label>
    <div class="form-grid">
      <?= $field('attach_max_mb', 'Размер одного файла, МБ', 'Сейчас PHP пропускает до ' . e(acc_bytes_text($phpLimit)) . ' (upload_max_filesize и post_max_size в php.ini). Итог: ' . e(acc_bytes_text(cnt_attach_max_bytes())) . '.', 'min="1" max="64" required') ?>
      <?= $field('attach_max_px', 'Уменьшать до, точек по длинной стороне', 'Картинки крупнее уменьшаются. Принимаются файлы до 8000 точек по стороне.', 'min="800" max="8000" required') ?>
      <?= $field('attach_daily_files', 'Файлов на пользователя в сутки', '0 - без ограничения. На команду проекта (хелперы и выше) не действует.', 'min="0" max="1000" required') ?>
      <?= $field('attach_daily_mb', 'МБ на пользователя в сутки', '0 - без ограничения.', 'min="0" max="10000" required') ?>
    </div>
    <div class="hint">Все файлы перекодируются (данные EXIF удаляются). GIF сохраняется как PNG с первым кадром. Файлы, которые так и не попали в сообщение, удаляются через сутки.</div>
  </div>

  <div class="card" id="sec-polls">
    <div class="adm-card-head"><div><h2><?= icon('poll') ?> Опросы</h2><p>В каких разделах при создании темы можно добавить опрос.</p></div></div>
    <div class="adm-opts">
      <label class="adm-opt"><input type="radio" name="poll_mode" value="auto"<?= adm_chk($pollMode === 'auto') ?> data-poll-mode><span><b>Во всех разделах, кроме разделов с анкетами</b><small>В жалобах, заявлениях и обращениях в техподдержку опросов не будет.</small></span></label>
      <label class="adm-opt"><input type="radio" name="poll_mode" value="list"<?= adm_chk($pollMode === 'list') ?> data-poll-mode><span><b>Только в выбранных разделах</b><small>Отметьте разделы ниже.</small></span></label>
    </div>
    <div class="adm-checkgrid cnt-adm-nodes" data-poll-nodes<?= $pollMode === 'list' ? '' : ' hidden' ?>>
      <?php foreach (adm_node_tree() as $row): $n = $row['node']; if ($n['type'] !== 'forum') { continue; } ?>
      <label class="adm-checkpill"><input type="checkbox" name="poll_node_ids[]" value="<?= (int)$n['id'] ?>"<?= adm_chk(in_array((int)$n['id'], $pollIds, true)) ?>><span><?= e($n['title']) ?></span><?= trim((string)$n['form_json']) !== '' ? ' <span class="muted small">(анкета)</span>' : '' ?></label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card" id="sec-api">
    <div class="adm-card-head"><div><h2><?= icon('download') ?> Новости для лаунчера и RSS</h2><p>Лаунчер World RP берёт новости из <a href="<?= e(url('/api/news.php')) ?>" target="_blank" rel="noopener">/api/news.php</a>: темы из «Раздела новостей» (Настройки → Форум) и раздела ниже. Отдаются только разделы, открытые гостям.</p></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="news_api_node2">Второй раздел новостей</label>
        <select class="select<?= isset($errors['news_api_node2']) ? ' is-invalid' : '' ?>" id="news_api_node2" name="news_api_node2">
          <option value="0">- Не выбран -</option>
          <?= adm_node_options((int)$v['news_api_node2']) ?>
        </select>
        <div class="hint">Например, «Мероприятия и конкурсы». Темы обоих разделов идут одной лентой по дате.</div>
        <?= adm_err($errors, 'news_api_node2') ?>
      </div>
      <?= $field('news_api_limit', 'Новостей по умолчанию', 'Лаунчер может попросить другое число: ?limit=1...20.', 'min="1" max="20" required') ?>
    </div>
    <div class="hint">RSS-ленты: <a href="<?= e(url('/forum/rss.php')) ?>" target="_blank" rel="noopener">/forum/rss.php</a> (весь форум) и /forum/rss.php?node=ID (раздел). Кнопка RSS есть в каждом открытом разделе.</div>
  </div>

  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить настройки</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">Отменить изменения</a>
  </div>
</form>

<?php if ($ready): ?>
<div class="card" id="sec-list">
  <div class="adm-card-head">
    <div><h2><?= icon('image') ?> Последние загрузки</h2><p>Удаление стирает файл: в сообщении вместо картинки останется пустое место.</p></div>
    <form method="post" action="<?= e($self) ?>" class="inline-form" data-confirm="Удалить все неиспользованные файлы старше суток?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cleanup">
      <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh') ?> Очистить неиспользованные</button>
    </form>
  </div>
  <form method="get" action="<?= e($self) ?>" class="cnt-adm-filter">
    <input class="input" name="user" value="<?= e($fUser) ?>" placeholder="Имя пользователя" aria-label="Имя пользователя">
    <label class="check"><input type="checkbox" name="orphan" value="1"<?= adm_chk($fOrphan) ?>><span>Только не прикреплённые</span></label>
    <button class="btn btn-black btn-sm" type="submit"><?= icon('search') ?> Найти</button>
    <?php if ($fUser !== '' || $fOrphan): ?><a class="btn btn-ghost btn-sm" href="<?= e($self) ?>">Сбросить</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
  <div class="empty"><?= icon('image') ?><div><?= $fUser !== '' || $fOrphan ? 'Ничего не найдено.' : 'Пока никто ничего не загружал.' ?></div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table cnt-adm-table">
      <thead><tr><th>Картинка</th><th>Пользователь</th><th>Сообщение</th><th class="num">Размер</th><th>Загружено</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $full = cnt_attach_url($r['path']); ?>
        <tr<?= $r['post_id'] ? '' : ' class="is-muted"' ?>>
          <td><a class="cnt-adm-thumb" href="<?= e($full) ?>" target="_blank" rel="noopener"><img src="<?= e($r['thumb'] ? cnt_attach_url($r['thumb']) : $full) ?>" alt="" loading="lazy"></a></td>
          <td><?= $r['username'] !== null ? user_link(['id' => $r['user_id'], 'username' => $r['username'], 'group_color' => $r['group_color']]) : '<span class="muted">Удалён</span>' ?></td>
          <td><?php if ($r['post_id']): ?><a href="<?= e(post_url($r['post_id'])) ?>"><?= $r['thread_title'] !== null ? e(str_limit($r['thread_title'], 50)) : 'Сообщение #' . (int)$r['post_id'] ?></a><?php else: ?><span class="muted small">Не прикреплено</span><?php endif; ?></td>
          <td class="num"><?= e(acc_bytes_text((int)$r['size'])) ?><div class="adm-cell-sub"><?= (int)$r['width'] ?>x<?= (int)$r['height'] ?></div></td>
          <td class="nowrap"><?= e(fdate($r['created_at'])) ?></td>
          <td class="actions">
            <form method="post" action="<?= e($self) ?>" class="inline-form" data-confirm="Удалить файл? Из сообщения картинка пропадёт.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="return" value="<?= e(current_url()) ?>">
              <button class="btn btn-danger btn-sm" type="submit" title="Удалить"><?= icon('trash') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_html($pg, '/admin/attachments.php', ['user' => $fUser !== '' ? $fUser : null, 'orphan' => $fOrphan ? 1 : null]) ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
admin_footer();
