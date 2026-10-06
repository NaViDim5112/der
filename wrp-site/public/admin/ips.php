<?php
// Админка: IP-адреса пользователей. Поиск по нику (его IP и другие аккаунты с тех же IP) или по IP.
// Только для администраторов: IP никогда не показываются остальным.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();
mdr_require_ready();

$self = url('/admin/ips.php');
$q = mb_substr(query_str('q'), 0, 64);
$mode = '';
$user = null;
$userIps = [];
$related = [];
$ipRows = [];
$matches = [];

$isIpLike = $q !== '' && (bool)preg_match('~^[0-9a-fA-F:.*]+$~', $q) && (strpos($q, '.') !== false || strpos($q, ':') !== false);

if ($q !== '' && !$isIpLike) {
    $user = user_by_name($q);
    if (!$user && preg_match('~^#?(\d{1,10})$~', $q, $m)) {
        $user = user_by_id((int)$m[1]);
    }
    if ($user) {
        $mode = 'user';
        $uid = (int)$user['id'];
        $userIps = db_all('SELECT i.*, (SELECT COUNT(DISTINCT o.user_id) FROM user_ips o WHERE o.ip = i.ip AND o.user_id <> :a) AS others
            FROM user_ips i WHERE i.user_id = :b ORDER BY i.last_seen DESC LIMIT 200', ['a' => $uid, 'b' => $uid]);
        $related = db_all('SELECT o.user_id, COUNT(DISTINCT o.ip) AS shared, MAX(o.last_seen) AS last_seen, GROUP_CONCAT(DISTINCT o.ip ORDER BY o.ip SEPARATOR \', \') AS ips,
                u.username, u.avatar, u.is_banned, u.created_at, g.name AS group_name, g.color AS group_color
            FROM user_ips mine JOIN user_ips o ON o.ip = mine.ip AND o.user_id <> mine.user_id
            JOIN users u ON u.id = o.user_id JOIN user_groups g ON g.id = u.group_id
            WHERE mine.user_id = :u GROUP BY o.user_id, u.username, u.avatar, u.is_banned, u.created_at, g.name, g.color
            ORDER BY shared DESC, last_seen DESC LIMIT 100', ['u' => $uid]);
    } else {
        $mode = 'names';
        $matches = db_all('SELECT u.id, u.username, u.avatar, g.color AS group_color FROM users u JOIN user_groups g ON g.id = u.group_id
            WHERE u.username LIKE :q ORDER BY u.username LIMIT 30', ['q' => adm_like($q)]);
    }
} elseif ($isIpLike) {
    $mode = 'ip';
    $wild = strpos($q, '*') !== false;
    $where = $wild ? 'i.ip LIKE :q' : 'i.ip = :q';
    $param = $wild ? str_replace('*', '%', addcslashes($q, '\\%_')) : $q;
    $ipRows = db_all('SELECT i.*, u.username, u.avatar, u.is_banned, g.name AS group_name, g.color AS group_color
        FROM user_ips i JOIN users u ON u.id = i.user_id JOIN user_groups g ON g.id = u.group_id
        WHERE ' . $where . ' ORDER BY i.last_seen DESC LIMIT 200', ['q' => $param]);
}

$recent = [];
$shared = [];
if ($mode === '') {
    $recent = db_all('SELECT i.*, u.username, u.avatar, g.color AS group_color FROM user_ips i JOIN users u ON u.id = i.user_id JOIN user_groups g ON g.id = u.group_id
        ORDER BY i.last_seen DESC LIMIT 25');
    $shared = db_all('SELECT ip, COUNT(DISTINCT user_id) AS users, MAX(last_seen) AS last_seen FROM user_ips GROUP BY ip HAVING COUNT(DISTINCT user_id) > 1
        ORDER BY users DESC, last_seen DESC LIMIT 25');
}

$ipLink = function ($ip) {
    return '<a class="mdr-ip" href="' . e(url('/admin/ips.php', ['q' => $ip])) . '">' . e($ip) . '</a>';
};
$userCell = function ($r, $idKey = 'user_id') {
    $u = mdr_user_row($r[$idKey], $r['username'], $r['avatar'] ?? null, $r['group_color'] ?? null);
    return '<div class="adm-cell-main">' . avatar($u, 'xs') . '<a class="username" href="' . e(url('/admin/ips.php', ['q' => $u['username']])) . '" style="color:' . e($u['group_color']) . '">' . e($u['username']) . '</a>'
        . (!empty($r['is_banned']) ? ' <span class="tag tag-danger">бан</span>' : '') . '</div>';
};

admin_header('IP-адреса', 'mdr_ips', [], ['css' => ['admin.css', 'moderation.css']]);
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('server') ?> IP-адреса</h2>
      <p>Записываются при входе, регистрации и каждом сообщении на форуме. Помогают найти мультиаккаунты и обход бана.</p>
    </div>
  </div>
  <div class="adm-note warn"><?= icon('lock') ?><div><b>Только для администраторов.</b> Не публикуйте IP-адреса и не пересылайте их. Один IP бывает у соседей, в общежитии, у мобильного интернета и VPN: решайте по совокупности признаков, а не по одному совпадению.</div></div>
  <form method="get" action="<?= e($self) ?>" class="mdr-ip-search">
    <input class="input" name="q" value="<?= e($q) ?>" maxlength="64" placeholder="Ник, #id или IP (можно с *: 192.168.*)" aria-label="Ник или IP" autofocus>
    <button class="btn btn-white" type="submit"><?= icon('search') ?> Найти</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= e($self) ?>">Сбросить</a><?php endif; ?>
  </form>
</div>

<?php if ($mode === 'user'): ?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= avatar($user, 's') ?> <?= e($user['username']) ?></h2>
      <p><?= e($user['group_name']) ?> · регистрация <?= e(fday($user['created_at'])) ?><?= $user['last_ip'] ? ' · последний IP ' . $ipLink($user['last_ip']) : '' ?></p>
    </div>
    <div class="btn-row">
      <a class="btn btn-sm" href="<?= e(url('/forum/member.php', ['id' => (int)$user['id']])) ?>"><?= icon('user') ?> Профиль</a>
      <a class="btn btn-sm" href="<?= e(url('/admin/users.php', ['edit' => (int)$user['id']])) ?>"><?= icon('settings') ?> Управление</a>
    </div>
  </div>
  <?php if (!$userIps): ?>
  <div class="adm-empty"><?= icon('server') ?>IP этого пользователя пока не записаны.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>IP</th><th class="hide-sm">Впервые</th><th>Последний раз</th><th class="num hide-sm">Раз</th><th class="num">Другие аккаунты</th></tr></thead>
      <tbody>
      <?php foreach ($userIps as $r): ?>
        <tr>
          <td class="nowrap"><?= $ipLink($r['ip']) ?></td>
          <td class="hide-sm nowrap muted small"><?= e(fdate($r['first_seen'])) ?></td>
          <td class="nowrap small"><?= e(fdate($r['last_seen'])) ?></td>
          <td class="num hide-sm"><?= num($r['hits']) ?></td>
          <td class="num"><?= (int)$r['others'] ? '<span class="tag tag-warning">' . (int)$r['others'] . '</span>' : '<span class="muted">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('users') ?> Другие аккаунты с этих IP</h2>
      <p>Возможные мультиаккаунты. Сначала те, у кого больше общих адресов.</p>
    </div>
  </div>
  <?php if (!$related): ?>
  <div class="adm-empty"><?= icon('check') ?>Совпадений нет.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Аккаунт</th><th class="num">Общих IP</th><th class="hide-md">Адреса</th><th>Последний раз</th></tr></thead>
      <tbody>
      <?php foreach ($related as $r): ?>
        <tr>
          <td class="nowrap"><?= $userCell($r) ?></td>
          <td class="num"><?= (int)$r['shared'] ?></td>
          <td class="hide-md small mdr-ip-list"><?= implode(', ', array_map($ipLink, array_slice(explode(', ', (string)$r['ips']), 0, 5))) ?></td>
          <td class="nowrap muted small"><?= e(fdate($r['last_seen'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($mode === 'ip'): ?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('server') ?> Аккаунты с IP <?= e($q) ?></h2>
      <p>Найдено: <?= num(count($ipRows)) ?>.</p>
    </div>
  </div>
  <?php if (!$ipRows): ?>
  <div class="adm-empty"><?= icon('search') ?>С этого адреса никто не заходил.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Аккаунт</th><th>IP</th><th class="hide-sm">Впервые</th><th>Последний раз</th><th class="num hide-sm">Раз</th></tr></thead>
      <tbody>
      <?php foreach ($ipRows as $r): ?>
        <tr>
          <td class="nowrap"><?= $userCell($r) ?></td>
          <td class="nowrap"><?= $ipLink($r['ip']) ?></td>
          <td class="hide-sm nowrap muted small"><?= e(fdate($r['first_seen'])) ?></td>
          <td class="nowrap small"><?= e(fdate($r['last_seen'])) ?></td>
          <td class="num hide-sm"><?= num($r['hits']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($mode === 'names'): ?>
<div class="card">
  <div class="adm-card-head"><div><h2><?= icon('users') ?> Пользователи по запросу «<?= e($q) ?>»</h2><p>Точного совпадения ника нет. Выберите пользователя:</p></div></div>
  <?php if (!$matches): ?>
  <div class="adm-empty"><?= icon('search') ?>Никого не нашли.</div>
  <?php else: ?>
  <div class="tags mdr-name-list">
    <?php foreach ($matches as $mu): ?><a class="tag" href="<?= e(url('/admin/ips.php', ['q' => $mu['username']])) ?>"><?= e($mu['username']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="adm-cols">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('users') ?> IP с несколькими аккаунтами</h2><p>Нажмите на адрес, чтобы увидеть аккаунты.</p></div></div>
    <?php if (!$shared): ?>
    <div class="adm-empty"><?= icon('check') ?>Совпадений нет.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>IP</th><th class="num">Аккаунтов</th><th>Последний раз</th></tr></thead>
        <tbody>
        <?php foreach ($shared as $r): ?>
          <tr><td class="nowrap"><?= $ipLink($r['ip']) ?></td><td class="num"><span class="tag tag-warning"><?= (int)$r['users'] ?></span></td><td class="nowrap muted small"><?= e(fdate($r['last_seen'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('clock') ?> Недавняя активность</h2><p>Последние входы и сообщения.</p></div></div>
    <?php if (!$recent): ?>
    <div class="adm-empty"><?= icon('server') ?>Записей пока нет.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Аккаунт</th><th>IP</th><th class="hide-sm">Когда</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr><td class="nowrap"><?= $userCell($r) ?></td><td class="nowrap"><?= $ipLink($r['ip']) ?></td><td class="hide-sm nowrap muted small"><?= e(fdate($r['last_seen'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
admin_footer();
