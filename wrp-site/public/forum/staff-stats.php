<?php
// Статистика рассмотрения: сколько тем взял каждый сотрудник, какие решения вынес, как быстро и сколько просрочил
require __DIR__ . '/../../app/bootstrap.php';

require_login();
if (!wf_ready()) {
    abort(404, 'Статистика пока недоступна: администратору нужно обновить базу сайта.');
}
$staffNodes = wf_my_staff_nodes();
if (!is_staff() && !$staffNodes) {
    abort(403, 'Статистика рассмотрения доступна только администрации.');
}
// Ответственные без статуса администрации видят только свои разделы
$onlyNodes = is_staff() ? null : $staffNodes;

$periods = [7 => '7 дней', 30 => '30 дней', 90 => '90 дней'];
$days = query_int('days', 7);
if (!isset($periods[$days])) {
    $days = 7;
}
$since = date('Y-m-d H:i:s', time() - $days * 86400);

$evNodeSql = '';
$evNodeParams = [];
$tNodeSql = '';
$tNodeParams = [];
if ($onlyNodes !== null) {
    $a = db_in($onlyNodes ?: [0], 'en');
    $evNodeSql = ' AND node_id IN (' . $a['sql'] . ')';
    $evNodeParams = $a['params'];
    $b = db_in($onlyNodes ?: [0], 'tn');
    $tNodeSql = ' AND t.node_id IN (' . $b['sql'] . ')';
    $tNodeParams = $b['params'];
}

// События за период одним запросом, сгруппированные по сотруднику, действию и статусу
$events = db_all('SELECT user_id, action, prefix_id, COUNT(*) AS c, COALESCE(SUM(seconds), 0) AS s, COALESCE(SUM(seconds IS NOT NULL), 0) AS sc, COALESCE(SUM(overdue), 0) AS o
    FROM wf_events WHERE created_at >= :since AND user_id > 0' . $evNodeSql . ' GROUP BY user_id, action, prefix_id',
    array_merge(['since' => $since], $evNodeParams));
$received = db_all("SELECT target_user_id AS user_id, COUNT(*) AS c FROM wf_events
    WHERE action = 'transfer' AND created_at >= :since AND target_user_id IS NOT NULL" . $evNodeSql . ' GROUP BY target_user_id',
    array_merge(['since' => $since], $evNodeParams));
$inwork = db_all('SELECT w.claimed_by AS user_id, COUNT(*) AS c,
        COALESCE(SUM(n.deadline_hours > 0 AND t.created_at < DATE_SUB(:now, INTERVAL n.deadline_hours HOUR)), 0) AS o
    FROM wf_threads w JOIN threads t ON t.id = w.thread_id JOIN wf_nodes n ON n.node_id = t.node_id
    WHERE w.claimed_by IS NOT NULL AND n.enabled = 1 AND t.is_deleted = 0 AND t.is_locked = 0
      AND (t.prefix_id IS NULL OR FIND_IN_SET(t.prefix_id, n.final_prefixes) = 0)' . $tNodeSql . '
    GROUP BY w.claimed_by', array_merge(['now' => now()], $tNodeParams));
$autoclosed = (int)db_val("SELECT COUNT(*) FROM wf_events WHERE action = 'autoclose' AND created_at >= :since" . $evNodeSql, array_merge(['since' => $since], $evNodeParams));

$stats = [];
$blank = ['claimed' => 0, 'verdicts' => 0, 'by' => [], 'sec' => 0, 'secn' => 0, 'overdue' => 0, 'inwork' => 0, 'inwork_overdue' => 0];
$usedPrefixes = [];
foreach ($events as $r) {
    $id = (int)$r['user_id'];
    $s = $stats[$id] ?? $blank;
    if ($r['action'] === 'claim') {
        $s['claimed'] += (int)$r['c'];
    } elseif ($r['action'] === 'verdict') {
        $p = (int)$r['prefix_id'];
        $s['verdicts'] += (int)$r['c'];
        $s['by'][$p] = ($s['by'][$p] ?? 0) + (int)$r['c'];
        $s['sec'] += (int)$r['s'];
        $s['secn'] += (int)$r['sc'];
        $s['overdue'] += (int)$r['o'];
        $usedPrefixes[$p] = true;
    } else {
        continue;
    }
    $stats[$id] = $s;
}
foreach ($received as $r) {
    $id = (int)$r['user_id'];
    $s = $stats[$id] ?? $blank;
    $s['claimed'] += (int)$r['c'];
    $stats[$id] = $s;
}
foreach ($inwork as $r) {
    $id = (int)$r['user_id'];
    $s = $stats[$id] ?? $blank;
    $s['inwork'] = (int)$r['c'];
    $s['inwork_overdue'] = (int)$r['o'];
    $stats[$id] = $s;
}

// Колонки решений: статусы, которые встречались за период, в порядке префиксов
$cols = [];
foreach (prefixes_all() as $pid => $p) {
    if (isset($usedPrefixes[$pid])) {
        $cols[] = (int)$pid;
    }
}
if (isset($usedPrefixes[0])) {
    $cols[] = 0;
}

$users = [];
if ($stats) {
    $in = db_in(array_keys($stats), 'u');
    foreach (db_all('SELECT u.id, u.username, u.avatar, g.color AS group_color, g.name AS group_name FROM users u
                     JOIN user_groups g ON g.id = u.group_id WHERE u.id IN (' . $in['sql'] . ')', $in['params']) as $u) {
        $users[(int)$u['id']] = $u;
    }
}
$rows = [];
foreach ($stats as $id => $s) {
    $s['id'] = $id;
    $s['name'] = isset($users[$id]) ? $users[$id]['username'] : 'Удалённый пользователь';
    $s['avg'] = $s['secn'] ? (int)round($s['sec'] / $s['secn']) : null;
    $rows[] = $s;
}

// Сортировка на сервере
$sortKeys = ['name' => 'Сотрудник', 'claimed' => 'Взято', 'verdicts' => 'Решений', 'avg' => 'Ср. время', 'overdue' => 'Просрочено', 'inwork' => 'В работе'];
$sort = query_str('sort', 'verdicts');
$isPrefixSort = (bool)preg_match('~^p(\d+)$~', $sort, $pm) && in_array((int)$pm[1], $cols, true);
if (!isset($sortKeys[$sort]) && !$isPrefixSort) {
    $sort = 'verdicts';
}
$dir = query_str('dir', $sort === 'name' || $sort === 'avg' ? 'asc' : 'desc') === 'asc' ? 'asc' : 'desc';
usort($rows, function ($a, $b) use ($sort, $dir, $isPrefixSort, $pm) {
    if ($sort === 'name') {
        $r = strcasecmp($a['name'], $b['name']);
    } else {
        if ($isPrefixSort) {
            $va = $a['by'][(int)$pm[1]] ?? 0;
            $vb = $b['by'][(int)$pm[1]] ?? 0;
        } elseif ($sort === 'avg') {
            // без решений - всегда в конце
            $va = $a['avg'] === null ? PHP_INT_MAX : $a['avg'];
            $vb = $b['avg'] === null ? PHP_INT_MAX : $b['avg'];
            if (($va === PHP_INT_MAX || $vb === PHP_INT_MAX) && $va !== $vb) {
                return $va <=> $vb;
            }
        } else {
            $va = $a[$sort];
            $vb = $b[$sort];
        }
        $r = $va <=> $vb;
    }
    if ($r === 0) {
        return strcasecmp($a['name'], $b['name']);
    }
    return $dir === 'asc' ? $r : -$r;
});

$sum = $blank;
foreach ($rows as $s) {
    foreach (['claimed', 'verdicts', 'sec', 'secn', 'overdue', 'inwork', 'inwork_overdue'] as $k) {
        $sum[$k] += $s[$k];
    }
    foreach ($s['by'] as $p => $n) {
        $sum['by'][$p] = ($sum['by'][$p] ?? 0) + $n;
    }
}

$sortLink = function ($key, $label) use ($days, $sort, $dir) {
    $active = $sort === $key;
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : ($key === 'name' || $key === 'avg' ? 'asc' : 'desc');
    $arrow = $active ? icon($dir === 'asc' ? 'chevron-up' : 'chevron-down') : '';
    return '<a href="' . e(url('/forum/staff-stats.php', ['days' => $days !== 7 ? $days : null, 'sort' => $key, 'dir' => $next])) . '"' . ($active ? ' class="is-sorted"' : '') . '>' . $label . $arrow . '</a>';
};

forum_header([
    'title' => 'Статистика рассмотрения',
    'meta_html' => 'Кто сколько тем взял, какие решения вынес, как быстро отвечал и сколько просрочил.' . ($onlyNodes !== null ? ' Только ваши разделы.' : ''),
    'crumbs' => [['Форумы', url('/forum/')], ['Очередь рассмотрения', url('/forum/queue.php')], ['Статистика', url('/forum/staff-stats.php')]],
    'title_actions' => '<a class="btn btn-black btn-pill" href="' . e(url('/forum/queue.php')) . '">' . icon('wf-inbox') . ' Очередь</a>',
    'nav' => 'forums',
    'right' => false,
    'css' => ['workflow.css'],
    'js' => ['workflow.js'],
]);
$avgAll = $sum['secn'] ? wf_dur((int)round($sum['sec'] / $sum['secn'])) : '-';
?>
<div class="wf-stats-head">
  <nav class="tabs" aria-label="Период">
    <?php foreach ($periods as $d => $label): ?>
    <a class="tab<?= $days === $d ? ' active' : '' ?>" href="<?= e(url('/forum/staff-stats.php', ['days' => $d !== 7 ? $d : null, 'sort' => $sort !== 'verdicts' ? $sort : null, 'dir' => $sort !== 'verdicts' ? $dir : null])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="muted small">С <?= e(fdate($since)) ?></span>
</div>

<div class="wf-stats-cards">
  <div class="wf-stat"><b><?= num($sum['verdicts']) ?></b><small>Решений вынесено</small></div>
  <div class="wf-stat"><b><?= num($sum['claimed']) ?></b><small>Взято на рассмотрение</small></div>
  <div class="wf-stat"><b><?= e($avgAll) ?></b><small>Среднее время до решения</small></div>
  <div class="wf-stat"><b class="<?= $sum['overdue'] ? 'wf-bad' : '' ?>"><?= num($sum['overdue']) ?></b><small>Решений после срока</small></div>
</div>

<section class="block wf-stats">
  <?php if (!$rows): ?>
  <div class="empty"><?= icon('wf-chart') ?><div>За этот период никто ничего не рассматривал.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= $sortLink('name', 'Сотрудник') ?></th>
          <th class="num"><?= $sortLink('claimed', 'Взято') ?></th>
          <th class="num"><?= $sortLink('verdicts', 'Решений') ?></th>
          <?php foreach ($cols as $p): ?>
          <th class="num"><?= $sortLink('p' . $p, $p ? prefix_html($p) : 'Без статуса') ?></th>
          <?php endforeach; ?>
          <th class="num"><?= $sortLink('avg', 'Ср. время') ?></th>
          <th class="num"><?= $sortLink('overdue', 'Просрочено') ?></th>
          <th class="num"><?= $sortLink('inwork', 'В работе') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $s): $u = $users[$s['id']] ?? null; ?>
        <tr>
          <td><div class="wf-stats-user"><?php if ($u): ?><?= avatar($u, 's') ?><div><?= user_link($u) ?><div class="muted small"><?= e($u['group_name']) ?></div></div><?php else: ?><span class="muted">Удалённый пользователь</span><?php endif; ?></div></td>
          <td class="num"><?= num($s['claimed']) ?></td>
          <td class="num"><b><?= num($s['verdicts']) ?></b></td>
          <?php foreach ($cols as $p): ?>
          <td class="num"><?= !empty($s['by'][$p]) ? num($s['by'][$p]) : '<span class="muted">0</span>' ?></td>
          <?php endforeach; ?>
          <td class="num"><?= $s['avg'] !== null ? e(wf_dur($s['avg'])) : '<span class="muted">-</span>' ?></td>
          <td class="num<?= $s['overdue'] ? ' wf-bad' : '' ?>"><?= num($s['overdue']) ?></td>
          <td class="num"><?= num($s['inwork']) ?><?= $s['inwork_overdue'] ? ' <span class="wf-bad small" title="Просрочены сейчас">(' . num($s['inwork_overdue']) . ' просроч.)</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="wf-stats-total">
          <td>Итого</td>
          <td class="num"><?= num($sum['claimed']) ?></td>
          <td class="num"><?= num($sum['verdicts']) ?></td>
          <?php foreach ($cols as $p): ?>
          <td class="num"><?= num($sum['by'][$p] ?? 0) ?></td>
          <?php endforeach; ?>
          <td class="num"><?= e($avgAll) ?></td>
          <td class="num"><?= num($sum['overdue']) ?></td>
          <td class="num"><?= num($sum['inwork']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="block-foot muted small">
    <span>Время считается от создания темы до решения. «Просрочено» - решения, вынесенные после срока ответа раздела.</span>
    <?php if ($autoclosed): ?><span>Закрыто автоматически без доказательств: <?= num($autoclosed) ?></span><?php endif; ?>
  </div>
</section>
<?php
forum_footer();
