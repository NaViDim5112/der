<?php
require __DIR__ . '/../../app/bootstrap.php';

$m = user_by_id(query_int('id'));
if (!$m) {
    abort(404, 'Такого пользователя нет. Возможно, аккаунт был удалён.');
}
$mid = (int)$m['id'];
$profileUrl = acc_member_url($mid);
$isSelf = uid() === $mid;

// ---------- Действия ----------

if (is_post()) {
    $ajax = acc_is_ajax();
    $fail = function ($msg, $code = 400) use ($ajax, $profileUrl) {
        if ($ajax) {
            json_out(['ok' => false, 'error' => $msg], $code);
        }
        flash('error', $msg);
        redirect(safe_return(input('return'), $profileUrl));
    };
    if ($ajax) {
        $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
            json_out(['ok' => false, 'error' => 'Сессия устарела. Обновите страницу.'], 400);
        }
    } else {
        csrf_check();
    }
    if (!is_logged()) {
        $fail('Войдите, чтобы продолжить.', 403);
    }
    $action = input('action');
    $return = safe_return(input('return'), $profileUrl);

    switch ($action) {
        case 'follow':
            if ($isSelf) {
                $fail('Нельзя подписаться на самого себя.');
            }
            if (db_exec('INSERT IGNORE INTO user_follows (user_id, follow_user_id, created_at) VALUES (:u, :t, :c)', ['u' => uid(), 't' => $mid, 'c' => now()])) {
                acc_alert_once($mid, 'follow');
            }
            flash('success', 'Вы подписались на ' . $m['username'] . '.');
            redirect($profileUrl);
            break;

        case 'unfollow':
            db_exec('DELETE FROM user_follows WHERE user_id = :u AND follow_user_id = :t', ['u' => uid(), 't' => $mid]);
            flash('success', 'Вы отписались от ' . $m['username'] . '.');
            redirect($profileUrl);
            break;

        case 'ignore':
            if (!acc_can_ignore($m)) {
                $fail($isSelf ? 'Нельзя игнорировать самого себя.' : 'Администрацию проекта игнорировать нельзя.');
            }
            db_exec('INSERT IGNORE INTO user_ignores (user_id, ignored_user_id, created_at) VALUES (:u, :t, :c)', ['u' => uid(), 't' => $mid, 'c' => now()]);
            flash('success', 'Вы игнорируете ' . $m['username'] . '. Его сообщения на стенах и в переписках будут скрыты.');
            redirect($profileUrl);
            break;

        case 'unignore':
            db_exec('DELETE FROM user_ignores WHERE user_id = :u AND ignored_user_id = :t', ['u' => uid(), 't' => $mid]);
            flash('success', 'Вы больше не игнорируете ' . $m['username'] . '.');
            redirect($profileUrl);
            break;

        case 'wall_post':
            $body = input_text('body');
            if (!acc_wall_can_post($mid, acc_ignored_by([$mid]))) {
                $fail(is_banned() ? 'Пока аккаунт заблокирован, писать нельзя.' : 'Пользователь ограничил возможность писать на его стене.', 403);
            }
            $_SESSION['acc_wall_draft'] = ['to' => $mid, 'body' => $body];
            if ($body === '') {
                $fail('Напишите текст сообщения.');
            }
            if (mb_strlen($body) > 2000) {
                $fail('Сообщение слишком длинное (не больше 2000 символов).');
            }
            if (!acc_wall_flood_ok()) {
                $fail('Слишком часто. Подождите ' . ACC_WALL_FLOOD_SECONDS . ' секунд перед следующим сообщением.', 429);
            }
            $ppId = db_insert('profile_posts', ['profile_user_id' => $mid, 'user_id' => uid(), 'body' => $body, 'created_at' => now()]);
            unset($_SESSION['acc_wall_draft']);
            if ($isSelf) {
                $status = mb_substr(bbcode_plain($body), 0, 140);
                db_update('users', ['status_text' => $status !== '' ? $status : null], 'id = :id', ['id' => $mid]);
            } else {
                alert_add($mid, 'profile_post', uid(), null, $ppId, (string)$mid);
            }
            redirect($profileUrl . '#profile-post-' . $ppId);
            break;

        case 'comment':
            $pp = db_one('SELECT * FROM profile_posts WHERE id = :id AND is_deleted = 0', ['id' => input_int('cid')]);
            if (!$pp) {
                $fail('Сообщение не найдено или удалено.', 404);
            }
            $owner = (int)$pp['profile_user_id'];
            if (!acc_wall_can_post($owner, acc_ignored_by([$owner]))) {
                $fail(is_banned() ? 'Пока аккаунт заблокирован, писать нельзя.' : 'Пользователь ограничил возможность комментировать на его стене.', 403);
            }
            $body = input_text('body');
            if ($body === '') {
                $fail('Напишите текст комментария.');
            }
            if (mb_strlen($body) > 1000) {
                $fail('Комментарий слишком длинный (не больше 1000 символов).');
            }
            if (!acc_wall_flood_ok()) {
                $fail('Слишком часто. Подождите ' . ACC_WALL_FLOOD_SECONDS . ' секунд перед следующим комментарием.', 429);
            }
            $cid = db_insert('profile_comments', ['profile_post_id' => (int)$pp['id'], 'user_id' => uid(), 'body' => $body, 'created_at' => now()]);
            foreach (array_unique([(int)$pp['user_id'], $owner]) as $to) {
                alert_add($to, 'profile_comment', uid(), null, (int)$pp['id'], (string)$owner);
            }
            $back = safe_return(preg_replace('~#.*$~', '', (string)input('return')), acc_member_url($owner));
            redirect($back . '#profile-comment-' . $cid);
            break;

        case 'wall_delete':
            $pp = db_one('SELECT * FROM profile_posts WHERE id = :id AND is_deleted = 0', ['id' => input_int('cid')]);
            if (!$pp) {
                $fail('Сообщение уже удалено.', 404);
            }
            $own = uid() === (int)$pp['user_id'] || uid() === (int)$pp['profile_user_id'];
            if (!$own && !can_moderate()) {
                $fail('Нет прав на удаление.', 403);
            }
            db_update('profile_posts', ['is_deleted' => 1], 'id = :id', ['id' => (int)$pp['id']]);
            if (!$own) {
                mod_log('profile_post_delete', 'profile_post', (int)$pp['id'], bbcode_plain($pp['body'], 200));
            }
            flash('success', 'Сообщение удалено.');
            redirect(preg_replace('~#.*$~', '', $return));
            break;

        case 'comment_delete':
            $c = db_one('SELECT c.*, pp.profile_user_id FROM profile_comments c JOIN profile_posts pp ON pp.id = c.profile_post_id WHERE c.id = :id AND c.is_deleted = 0', ['id' => input_int('cid')]);
            if (!$c) {
                $fail('Комментарий уже удалён.', 404);
            }
            $own = uid() === (int)$c['user_id'] || uid() === (int)$c['profile_user_id'];
            if (!$own && !can_moderate()) {
                $fail('Нет прав на удаление.', 403);
            }
            db_update('profile_comments', ['is_deleted' => 1], 'id = :id', ['id' => (int)$c['id']]);
            if (!$own) {
                mod_log('profile_comment_delete', 'profile_comment', (int)$c['id'], bbcode_plain($c['body'], 200));
            }
            flash('success', 'Комментарий удалён.');
            redirect(preg_replace('~#.*$~', '', $return) . '#profile-post-' . (int)$c['profile_post_id']);
            break;

        case 'like':
            $type = input('type') === 'comment' ? 'comment' : 'post';
            $cid = input_int('cid');
            if (is_banned()) {
                $fail('Пока аккаунт заблокирован, оценивать нельзя.', 403);
            }
            if ($type === 'post') {
                $row = db_one('SELECT id, user_id, profile_user_id, id AS post_id FROM profile_posts WHERE id = :id AND is_deleted = 0', ['id' => $cid]);
            } else {
                $row = db_one('SELECT c.id, c.user_id, pp.profile_user_id, pp.id AS post_id FROM profile_comments c JOIN profile_posts pp ON pp.id = c.profile_post_id
                    WHERE c.id = :id AND c.is_deleted = 0 AND pp.is_deleted = 0', ['id' => $cid]);
            }
            if (!$row) {
                $fail('Сообщение не найдено или удалено.', 404);
            }
            if ((int)$row['user_id'] === uid()) {
                $fail('Нельзя оценить своё сообщение.', 403);
            }
            $res = acc_wall_like_toggle($type, $cid);
            if ($res['liked']) {
                acc_alert_once((int)$row['user_id'], 'profile_like', (int)$row['post_id'], (string)(int)$row['profile_user_id']);
            }
            if ($ajax) {
                $likes = acc_wall_likes($type === 'post' ? [$cid] : [], $type === 'comment' ? [$cid] : []);
                json_out(['ok' => true, 'liked' => $res['liked'], 'count' => $res['count'], 'html' => acc_likes_line($likes[$type][$cid] ?? [])]);
            }
            redirect($return);
            break;

        default:
            $fail('Неизвестное действие.');
    }
}

// ---------- Данные страницы ----------

$tabs = ['wall' => 'Сообщения профиля', 'activity' => 'Последняя активность', 'posts' => 'Публикации', 'about' => 'Информация'];
$tab = query_str('tab', 'wall');
if ($tab === 'threads') {
    $tabs['threads'] = 'Темы';
}
if (!isset($tabs[$tab])) {
    $tab = 'wall';
}
$following = is_logged() && !$isSelf && acc_is_following(uid(), $mid);
$ignoring = is_logged() && !$isSelf && in_array($mid, ignored_user_ids(), true);
$online = acc_is_online($m);
$cover = user_cover_url($m);
$nodeIds = acc_viewable_node_ids();
$nin = db_in($nodeIds, 'n');
$page = max(1, query_int('page', 1));
$tabUrl = function ($t, $extra = []) use ($mid) {
    return acc_member_url($mid, array_merge(['tab' => $t === 'wall' ? null : $t], $extra));
};

forum_header([
    'title' => $m['username'],
    'description' => 'Профиль пользователя ' . $m['username'] . ' на форуме ' . setting('site_name'),
    'crumbs' => [['Участники', url('/forum/members.php')], [$m['username'], $profileUrl]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<?php if (!empty($m['is_banned']) && is_staff()): ?>
<div class="flash flash-error"><?= icon('ban') ?><div><b>Пользователь заблокирован</b><?= $m['ban_until'] ? ' до ' . e(fdate($m['ban_until'])) : ' навсегда' ?>.<?= $m['ban_reason'] ? ' Причина: ' . e($m['ban_reason']) : '' ?></div></div>
<?php endif; ?>

<section class="acc-profile">
  <div class="acc-cover<?= $cover ? '' : ' acc-cover-default' ?>">
    <?php if ($cover): ?><img class="acc-cover-img" src="<?= e($cover) ?>" alt=""><?php endif; ?>
    <div class="acc-cover-content">
      <h2 class="acc-profile-name" style="color:<?= e($m['group_color']) ?>"><?= e($m['username']) ?></h2>
      <?php if (trim((string)$m['status_text']) !== ''): ?>
      <div class="acc-profile-status">«<?= e($m['status_text']) ?>»</div>
      <?php endif; ?>
      <div class="acc-banners"><?= user_banners($m) ?></div>
    </div>
    <div class="acc-cover-strip">
      <span><?= e(acc_user_title($m)) ?><?php if (trim((string)$m['location']) !== ''): ?> · Из <?= e($m['location']) ?><?php endif; ?></span>
      <span>Регистрация: <b><?= e(fday($m['created_at'])) ?></b></span>
      <span>Активность: <b><?= e($m['last_activity'] ? fdate($m['last_activity']) : 'нет') ?></b></span>
      <?php if ($online): ?><span class="acc-online"><span class="status-dot on"></span> В сети</span><?php endif; ?>
    </div>
  </div>
  <div class="acc-profile-avatar">
    <?= avatar($m, 'xxl') ?>
    <?php if ($online): ?><span class="acc-dot-online acc-dot-big" title="В сети"></span><?php endif; ?>
  </div>
  <div class="acc-profile-bar">
    <dl class="acc-profile-stats">
      <div><dt>Сообщения</dt><dd><?= num($m['posts_count']) ?></dd></div>
      <div><dt>Реакции</dt><dd><?= num($m['likes_received']) ?></dd></div>
      <div><dt>Баллы</dt><dd><?= num(user_points($m)) ?></dd></div>
    </dl>
    <div class="acc-profile-actions">
      <?php if ($isSelf): ?>
      <a class="btn btn-white btn-pill" href="<?= e(url('/forum/account.php', ['tab' => 'profile'])) ?>"><?= icon('image') ?> Редактировать баннер профиля</a>
      <?php elseif (is_logged()): ?>
      <form method="post" action="<?= e($profileUrl) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $following ? 'unfollow' : 'follow' ?>">
        <button class="btn btn-pill <?= $following ? 'btn-outline' : 'btn-accent' ?>" type="submit"><?= icon($following ? 'check' : 'plus') ?> <?= $following ? 'Отписаться' : 'Подписаться' ?></button>
      </form>
      <?php if ($ignoring || acc_can_ignore($m)): ?>
      <form method="post" action="<?= e($profileUrl) ?>"<?= $ignoring ? '' : ' data-confirm="Игнорировать ' . e($m['username']) . '? Его сообщения на стенах и в переписках будут скрыты."' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $ignoring ? 'unignore' : 'ignore' ?>">
        <button class="btn btn-pill btn-outline" type="submit"><?= $ignoring ? icon('eye') . ' Не игнорировать' : icon('eye-off') . ' Игнор.' ?></button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
      <div class="dropdown">
        <button class="btn btn-pill btn-outline" type="button" data-dropdown><?= icon('search') ?> Найти <?= icon('chevron-down') ?></button>
        <div class="dropdown-menu dropdown-right">
          <a href="<?= e(url('/forum/search.php', ['user' => $m['username']])) ?>"><?= icon('chats') ?> Сообщения пользователя</a>
          <a href="<?= e($tabUrl('threads')) ?>"><?= icon('list') ?> Темы пользователя</a>
          <a href="<?= e($tabUrl('posts')) ?>"><?= icon('file') ?> Публикации</a>
        </div>
      </div>
      <?php if (is_logged() && !$isSelf && !is_banned()): ?>
      <a class="btn btn-pill btn-outline" href="<?= e(url('/forum/conversations.php', ['new' => 1, 'to' => $m['username']])) ?>"><?= icon('mail') ?> Написать</a>
      <?php endif; ?>
      <?php if (can_admin()): ?>
      <a class="btn btn-pill btn-outline" href="<?= e(url('/admin/users.php', ['edit' => $mid])) ?>"><?= icon('shield') ?> Управление</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<nav class="acc-pills" aria-label="Разделы профиля">
  <?php foreach ($tabs as $k => $label): ?>
  <a class="<?= $k === $tab ? 'active' : '' ?>" href="<?= e($tabUrl($k)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php
// ---------- Сообщения профиля ----------
if ($tab === 'wall'):
    $canPost = acc_wall_can_post($mid, acc_ignored_by([$mid]));
    $total = (int)db_val('SELECT COUNT(*) FROM profile_posts WHERE profile_user_id = :u AND is_deleted = 0', ['u' => $mid]);
    $p = paginate($total, 15, $page);
    $posts = acc_wall_query('pp.profile_user_id = :u AND pp.is_deleted = 0', ['u' => $mid], $p['per_page'], $p['offset']);
    $draft = '';
    if (isset($_SESSION['acc_wall_draft']['to']) && (int)$_SESSION['acc_wall_draft']['to'] === $mid) {
        $draft = (string)$_SESSION['acc_wall_draft']['body'];
        unset($_SESSION['acc_wall_draft']);
    }
?>
<div class="acc-wall">
  <?php if ($canPost): ?>
  <form class="acc-composer card<?= $draft !== '' ? ' expanded' : '' ?>" method="post" action="<?= e($profileUrl) ?>" data-acc-expand>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="wall_post">
    <?= avatar(user(), 'l') ?>
    <div class="acc-expand-body">
      <textarea class="textarea acc-expand-input" name="body" rows="1" maxlength="2000" required placeholder="<?= $isSelf ? 'Обновить свой статус...' : 'Напишите что-нибудь...' ?>"><?= e($draft) ?></textarea>
      <div class="acc-expand-actions">
        <span class="hint">Можно BB-коды. Ctrl+Enter - отправить.</span>
        <button class="btn btn-white btn-pill btn-sm" type="submit"><?= icon('send') ?> Отправить</button>
      </div>
    </div>
  </form>
  <?php elseif (!is_logged()): ?>
  <div class="card acc-wall-guest muted"><?= icon('lock') ?> <a href="<?= e(url('/forum/login.php', ['return' => current_url()])) ?>">Войдите</a>, чтобы оставить сообщение в профиле.</div>
  <?php endif; ?>

  <?php if ($posts): ?>
  <div class="acc-wall-list card"><?= acc_wall_render($posts, ['return' => $tabUrl('wall', ['page' => $p['page'] > 1 ? $p['page'] : null])]) ?></div>
  <?= $p['pages'] > 1 ? '<div class="acc-pager">' . pagination_html($p, '/forum/member.php', ['id' => $mid]) . '</div>' : '' ?>
  <?php else: ?>
  <div class="card empty"><?= icon('chat') ?><div>В профиле <?= e($m['username']) ?> пока нет сообщений.</div></div>
  <?php endif; ?>
</div>

<?php
// ---------- Последняя активность ----------
elseif ($tab === 'activity'):
    $items = [];
    $rows = db_all('SELECT p.id, p.body, p.created_at, t.id AS thread_id, t.title, t.prefix_id, t.node_id, t.first_post_id
        FROM posts p JOIN threads t ON t.id = p.thread_id
        WHERE p.user_id = :u AND p.is_deleted = 0 AND t.is_deleted = 0 AND t.node_id IN (' . $nin['sql'] . ')
        ORDER BY p.id DESC LIMIT 25', array_merge(['u' => $mid], $nin['params']));
    foreach ($rows as $r) {
        $items[] = ['kind' => (int)$r['first_post_id'] === (int)$r['id'] ? 'thread' : 'post', 'at' => $r['created_at'], 'row' => $r];
    }
    foreach (acc_wall_query('pp.user_id = :u AND pp.is_deleted = 0', ['u' => $mid], 15) as $r) {
        $items[] = ['kind' => 'wall', 'at' => $r['created_at'], 'row' => $r];
    }
    $rows = db_all('SELECT c.id, c.body, c.created_at, pp.id AS post_id, pp.profile_user_id, pu.username AS owner_username, pg.color AS owner_group_color
        FROM profile_comments c JOIN profile_posts pp ON pp.id = c.profile_post_id
        JOIN users pu ON pu.id = pp.profile_user_id JOIN user_groups pg ON pg.id = pu.group_id
        WHERE c.user_id = :u AND c.is_deleted = 0 AND pp.is_deleted = 0 ORDER BY c.id DESC LIMIT 15', ['u' => $mid]);
    foreach ($rows as $r) {
        $items[] = ['kind' => 'comment', 'at' => $r['created_at'], 'row' => $r];
    }
    usort($items, function ($a, $b) {
        return strcmp($b['at'], $a['at']);
    });
    $items = array_slice($items, 0, 30);
    $who = '<b style="color:' . e($m['group_color']) . '">' . e($m['username']) . '</b>';
?>
<div class="card acc-list">
  <?php if (!$items): ?>
  <div class="empty"><?= icon('clock') ?><div><?= e($m['username']) ?> пока ничего не публиковал(а).</div></div>
  <?php endif; ?>
  <?php foreach ($items as $it): $r = $it['row']; ?>
  <div class="acc-activity">
    <div class="acc-activity-avatar"><?= avatar($m, 'm') ?></div>
    <div class="acc-activity-body">
      <?php if ($it['kind'] === 'thread' || $it['kind'] === 'post'): $node = node_get($r['node_id']); ?>
      <div class="acc-activity-title"><?= $who ?> <?= $it['kind'] === 'thread' ? 'создал(а) тему' : 'ответил(а) в теме' ?> <a href="<?= e(post_url($r['id'])) ?>"><?= prefix_html($r['prefix_id']) ?><?= e($r['title']) ?></a></div>
      <div class="acc-activity-snippet"><?= e(bbcode_plain($r['body'], 220)) ?></div>
      <div class="acc-activity-meta"><?= e(fdate($r['created_at'])) ?><?php if ($node): ?> · <a href="<?= e(node_url($node)) ?>"><?= e($node['title']) ?></a><?php endif; ?></div>
      <?php elseif ($it['kind'] === 'wall'): $own = (int)$r['profile_user_id'] === $mid; ?>
      <div class="acc-activity-title"><?= $who ?> <?= $own ? 'обновил(а) статус' : 'оставил(а) сообщение в профиле ' . user_link(acc_user_from_row($r + ['owner_id' => $r['profile_user_id']], 'owner_')) ?></div>
      <div class="acc-activity-snippet"><?= e(bbcode_plain($r['body'], 220)) ?></div>
      <div class="acc-activity-meta"><a href="<?= e(acc_member_url($r['profile_user_id'])) ?>#profile-post-<?= (int)$r['id'] ?>"><?= e(fdate($r['created_at'])) ?></a></div>
      <?php else: ?>
      <div class="acc-activity-title"><?= $who ?> прокомментировал(а) сообщение в профиле <?= user_link(acc_user_from_row($r + ['owner_id' => $r['profile_user_id']], 'owner_')) ?></div>
      <div class="acc-activity-snippet"><?= e(bbcode_plain($r['body'], 220)) ?></div>
      <div class="acc-activity-meta"><a href="<?= e(acc_member_url($r['profile_user_id'])) ?>#profile-comment-<?= (int)$r['id'] ?>"><?= e(fdate($r['created_at'])) ?></a></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php
// ---------- Публикации ----------
elseif ($tab === 'posts'):
    $total = (int)db_val('SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id
        WHERE p.user_id = :u AND p.is_deleted = 0 AND t.is_deleted = 0 AND t.node_id IN (' . $nin['sql'] . ')', array_merge(['u' => $mid], $nin['params']));
    $p = paginate($total, 20, $page);
    $rows = db_all('SELECT p.id, p.body, p.created_at, p.likes_count, t.id AS thread_id, t.title, t.prefix_id, t.node_id, t.first_post_id
        FROM posts p JOIN threads t ON t.id = p.thread_id
        WHERE p.user_id = :u AND p.is_deleted = 0 AND t.is_deleted = 0 AND t.node_id IN (' . $nin['sql'] . ')
        ORDER BY p.id DESC LIMIT :lim OFFSET :off', array_merge(['u' => $mid, 'lim' => $p['per_page'], 'off' => $p['offset']], $nin['params']));
?>
<div class="card acc-list">
  <div class="acc-list-head"><b>Публикации на форуме</b><span class="muted small"><?= num($total) ?> <?= plural($total, 'сообщение', 'сообщения', 'сообщений') ?></span></div>
  <?php if (!$rows): ?>
  <div class="empty"><?= icon('chats') ?><div>Сообщений на форуме пока нет.</div></div>
  <?php endif; ?>
  <?php foreach ($rows as $r): $node = node_get($r['node_id']); ?>
  <div class="acc-result">
    <div class="acc-result-icon"><?= icon((int)$r['first_post_id'] === (int)$r['id'] ? 'file' : 'reply') ?></div>
    <div class="acc-result-body">
      <a class="acc-result-title" href="<?= e(post_url($r['id'])) ?>"><?= prefix_html($r['prefix_id']) ?><?= e($r['title']) ?></a>
      <div class="acc-activity-snippet"><?= e(bbcode_plain($r['body'], 200)) ?></div>
      <div class="acc-activity-meta"><?= (int)$r['first_post_id'] === (int)$r['id'] ? 'Тема' : 'Ответ' ?> · <?= e(fdate($r['created_at'])) ?><?php if ($node): ?> · <a href="<?= e(node_url($node)) ?>"><?= e($node['title']) ?></a><?php endif; ?><?php if ((int)$r['likes_count']): ?> · <?= icon('like') ?> <?= num($r['likes_count']) ?><?php endif; ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?= $p['pages'] > 1 ? '<div class="acc-pager">' . pagination_html($p, '/forum/member.php', ['id' => $mid, 'tab' => 'posts']) . '</div>' : '' ?>

<?php
// ---------- Темы ----------
elseif ($tab === 'threads'):
    $total = (int)db_val('SELECT COUNT(*) FROM threads t WHERE t.user_id = :u AND t.is_deleted = 0 AND t.node_id IN (' . $nin['sql'] . ')', array_merge(['u' => $mid], $nin['params']));
    $p = paginate($total, 20, $page);
    $rows = db_all('SELECT t.* FROM threads t WHERE t.user_id = :u AND t.is_deleted = 0 AND t.node_id IN (' . $nin['sql'] . ')
        ORDER BY t.created_at DESC, t.id DESC LIMIT :lim OFFSET :off', array_merge(['u' => $mid, 'lim' => $p['per_page'], 'off' => $p['offset']], $nin['params']));
?>
<div class="card acc-list">
  <div class="acc-list-head"><b>Темы пользователя</b><span class="muted small"><?= num($total) ?> <?= plural($total, 'тема', 'темы', 'тем') ?></span></div>
  <?php if (!$rows): ?>
  <div class="empty"><?= icon('list') ?><div>Тем пока нет.</div></div>
  <?php endif; ?>
  <?php foreach ($rows as $t): $node = node_get($t['node_id']); ?>
  <div class="acc-result">
    <div class="acc-result-icon"><?= icon($t['is_locked'] ? 'lock' : ($t['is_pinned'] ? 'pin' : 'chat')) ?></div>
    <div class="acc-result-body">
      <a class="acc-result-title" href="<?= e(thread_url($t['id'])) ?>"><?= prefix_html($t['prefix_id']) ?><?= e($t['title']) ?></a>
      <div class="acc-activity-meta"><?= e(fdate($t['created_at'])) ?><?php if ($node): ?> · <a href="<?= e(node_url($node)) ?>"><?= e($node['title']) ?></a><?php endif; ?> · Ответов: <?= num($t['reply_count']) ?> · Просмотров: <?= num($t['views']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?= $p['pages'] > 1 ? '<div class="acc-pager">' . pagination_html($p, '/forum/member.php', ['id' => $mid, 'tab' => 'threads']) . '</div>' : '' ?>

<?php
// ---------- Информация ----------
else:
    $fc = acc_follow_counts($mid);
    $followersList = acc_follow_list($mid, 'followers', 16);
    $followingList = acc_follow_list($mid, 'following', 16);
?>
<div class="acc-about">
  <div class="acc-about-main">
    <section class="card">
      <h3 class="acc-card-title">О себе</h3>
      <?php if (trim((string)$m['about']) !== ''): ?>
      <div class="bb"><?= bbcode($m['about']) ?></div>
      <?php else: ?>
      <div class="muted"><?= $isSelf ? 'Вы ещё ничего не рассказали о себе. <a href="' . e(url('/forum/account.php', ['tab' => 'profile'])) . '">Заполнить</a>' : e($m['username']) . ' пока ничего не рассказал(а) о себе.' ?></div>
      <?php endif; ?>
    </section>
    <section class="card">
      <h3 class="acc-card-title">Подпись</h3>
      <?php if (trim((string)$m['signature']) !== ''): ?>
      <div class="acc-signature-demo"><div class="bb"><?= bbcode($m['signature']) ?></div></div>
      <?php else: ?>
      <div class="muted">Подпись не задана.</div>
      <?php endif; ?>
    </section>
  </div>
  <aside class="acc-about-side">
    <section class="card">
      <h3 class="acc-card-title">Сведения</h3>
      <dl class="kv acc-kv">
        <dt>Регистрация</dt><dd><?= e(fday($m['created_at'])) ?></dd>
        <dt>Активность</dt><dd><?= e(acc_seen_text($m)) ?></dd>
        <?php if (trim((string)$m['location']) !== ''): ?><dt>Откуда</dt><dd><?= e($m['location']) ?></dd><?php endif; ?>
        <dt>Игровой ник</dt><dd><?= $m['game_nick'] ? e($m['game_nick']) : '<span class="muted">не привязан</span>' ?></dd>
        <dt>Темы</dt><dd><?= num($m['threads_count']) ?></dd>
        <dt>Сообщения</dt><dd><?= num($m['posts_count']) ?></dd>
        <dt>Реакции</dt><dd><?= num($m['likes_received']) ?></dd>
        <dt>Баллы</dt><dd><?= num(user_points($m)) ?></dd>
      </dl>
      <div class="acc-groups"><?= acc_group_badges($m) ?></div>
    </section>
    <section class="card">
      <h3 class="acc-card-title">Подписчики <span class="muted"><?= num($fc['followers']) ?></span></h3>
      <?php if ($followersList): ?>
      <div class="acc-avatar-list"><?php foreach ($followersList as $f): ?><a href="<?= e(acc_member_url($f['id'])) ?>" title="<?= e($f['username']) ?>"><?= avatar($f, 's') ?></a><?php endforeach; ?></div>
      <?php else: ?><div class="muted small">Пока никого.</div><?php endif; ?>
      <h3 class="acc-card-title mt-2">Подписки <span class="muted"><?= num($fc['following']) ?></span></h3>
      <?php if ($followingList): ?>
      <div class="acc-avatar-list"><?php foreach ($followingList as $f): ?><a href="<?= e(acc_member_url($f['id'])) ?>" title="<?= e($f['username']) ?>"><?= avatar($f, 's') ?></a><?php endforeach; ?></div>
      <?php else: ?><div class="muted small">Ни на кого не подписан(а).</div><?php endif; ?>
    </section>
  </aside>
</div>
<?php endif; ?>
<?php
forum_footer();
