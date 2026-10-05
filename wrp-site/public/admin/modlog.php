<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/modlog.php');
$userFilter = query_int('user');
$actionFilter = query_str('action');

$actors = db_all('SELECT DISTINCT u.id, u.username FROM mod_log l JOIN users u ON u.id = l.user_id ORDER BY u.username');
$actions = array_column(db_all('SELECT DISTINCT action FROM mod_log ORDER BY action'), 'action');
if ($actionFilter !== '' && !in_array($actionFilter, $actions, true)) {
    $actionFilter = '';
}

$where = [];
$params = [];
if ($userFilter) {
    $where[] = 'l.user_id = :u';
    $params['u'] = $userFilter;
}
if ($actionFilter !== '') {
    $where[] = 'l.action = :a';
    $params['a'] = $actionFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int)db_val('SELECT COUNT(*) FROM mod_log l' . $whereSql, $params);
$p = paginate($total, 50, query_int('page', 1));
$rows = db_all('SELECT l.*, u.username, u.avatar, g.color AS group_color,
        t.title AS thread_title, t.prefix_id AS thread_prefix,
        tu.username AS target_username, tg.color AS target_color,
        pt.id AS post_thread_id, pt.title AS post_thread_title,
        n.title AS node_title
    FROM mod_log l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN user_groups g ON g.id = u.group_id
    LEFT JOIN threads t ON l.target_type = \'thread\' AND t.id = l.target_id
    LEFT JOIN users tu ON l.target_type = \'user\' AND tu.id = l.target_id
    LEFT JOIN user_groups tg ON tg.id = tu.group_id
    LEFT JOIN posts po ON l.target_type = \'post\' AND po.id = l.target_id
    LEFT JOIN threads pt ON pt.id = po.thread_id
    LEFT JOIN nodes n ON l.target_type = \'node\' AND n.id = l.target_id' . $whereSql . '
    ORDER BY l.id DESC LIMIT :lim OFFSET :off', array_merge($params, ['lim' => (int)$p['per_page'], 'off' => (int)$p['offset']]));

// Цель действия: ссылка и подпись
function adm_modlog_target($r)
{
    $id = (int)$r['target_id'];
    switch ($r['target_type']) {
        case 'thread':
            $title = $r['thread_title'] !== null ? prefix_html($r['thread_prefix']) . e(str_limit($r['thread_title'], 60)) : 'Тема #' . $id . ' <span class="muted small">(удалена)</span>';
            return '<span class="muted small">Тема</span><br><a href="' . e(url('/forum/thread.php', ['id' => $id])) . '">' . $title . '</a>';
        case 'post':
            $in = $r['post_thread_title'] !== null ? ' <span class="muted small">в теме «' . e(str_limit($r['post_thread_title'], 50)) . '»</span>' : '';
            return '<span class="muted small">Сообщение</span><br><a href="' . e(url('/forum/post.php', ['id' => $id])) . '">#' . $id . '</a>' . $in;
        case 'user':
            if ($r['target_username'] === null) {
                return '<span class="muted small">Пользователь</span><br>#' . $id . ' <span class="muted small">(удалён)</span>';
            }
            return '<span class="muted small">Пользователь</span><br><a class="username" href="' . e(url('/forum/member.php', ['id' => $id])) . '" style="color:' . e($r['target_color']) . '">' . e($r['target_username']) . '</a>'
                . ' <a class="muted" href="' . e(url('/admin/users.php', ['edit' => $id])) . '" title="Открыть в админ-панели">' . icon('settings') . '</a>';
        case 'node':
            if ($r['node_title'] === null) {
                return '<span class="muted small">Раздел</span><br>#' . $id . ' <span class="muted small">(удалён)</span>';
            }
            return '<span class="muted small">Раздел</span><br><a href="' . e(url('/forum/forum.php', ['id' => $id])) . '">' . e($r['node_title']) . '</a>';
        case 'group':
            return '<span class="muted small">Группа</span><br>#' . $id;
        case 'profile_post':
        case 'profile_comment':
            return '<span class="muted small">' . ($r['target_type'] === 'profile_post' ? 'Сообщение в профиле' : 'Комментарий в профиле') . '</span><br>#' . $id;
    }
    return '<span class="muted small">' . e($r['target_type']) . '</span><br>#' . $id;
}

admin_header('Журнал модерации', 'modlog');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('clock') ?> Журнал модерации</h2>
      <p>Кто из команды и что сделал: блокировки, смена групп, закрытие и перенос тем, удаление сообщений. Записи не редактируются.</p>
    </div>
  </div>
  <form method="get" action="<?= e($self) ?>" class="adm-filters" style="grid-template-columns:repeat(2, minmax(160px, 1fr)) auto">
    <div class="form-row">
      <label class="label" for="user">Кто</label>
      <select class="select" id="user" name="user" data-autosubmit>
        <option value="0">Все</option>
        <?php foreach ($actors as $a): ?><option value="<?= (int)$a['id'] ?>"<?= adm_sel($userFilter, $a['id']) ?>><?= e($a['username']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="label" for="action">Действие</label>
      <select class="select" id="action" name="action" data-autosubmit>
        <option value="">Все действия</option>
        <?php foreach ($actions as $act): ?><option value="<?= e($act) ?>"<?= adm_sel($actionFilter, $act) ?>><?= e(adm_action_label($act)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="btn-row">
      <button class="btn btn-white" type="submit"><?= icon('filter') ?> Показать</button>
      <?php if ($userFilter || $actionFilter !== ''): ?><a class="btn btn-ghost" href="<?= e($self) ?>">Сбросить</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
  <div class="adm-empty"><?= icon('clock') ?><?= $total || $userFilter || $actionFilter !== '' ? 'По этим условиям записей нет.' : 'Журнал пока пуст. Здесь появятся действия модераторов и администраторов.' ?></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Кто</th><th>Действие</th><th>Над чем</th><th class="hide-md">Подробности</th><th>Когда</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap">
            <?php if ($r['username'] !== null): ?>
            <div class="adm-cell-main"><?= avatar($r, 'xs') ?><?= user_link(['id' => $r['user_id'], 'username' => $r['username'], 'group_color' => $r['group_color']]) ?></div>
            <?php else: ?><span class="muted">#<?= (int)$r['user_id'] ?> (удалён)</span><?php endif; ?>
          </td>
          <td><span class="tag tag-<?= e(adm_action_tone($r['action'])) ?>"><?= e(adm_action_label($r['action'])) ?></span></td>
          <td><?= adm_modlog_target($r) ?></td>
          <td class="hide-md small"><?= $r['details'] !== null && $r['details'] !== '' ? e($r['details']) : '<span class="muted">-</span>' ?></td>
          <td class="nowrap muted small" title="<?= e(date('d.m.Y H:i:s', strtotime($r['created_at']))) ?>"><?= e(fdate($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="adm-foot">
    <span class="adm-count">Записей: <?= num($total) ?></span>
    <?= pagination_html($p, '/admin/modlog.php', ['user' => $userFilter ?: null, 'action' => $actionFilter]) ?>
  </div>
</div>
<?php
admin_footer();
