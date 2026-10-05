<?php
// Тема: сообщения, быстрый ответ, отслеживание, модерация
require __DIR__ . '/../../app/bootstrap.php';

$thread = thread_get(query_int('id'));
$isMod = can_moderate();
if (!$thread || ($thread['is_deleted'] && !$isMod)) {
    abort(404, 'Тема не найдена или была удалена.');
}
$node = node_get($thread['node_id']);
if (!$node) {
    abort(404, 'Тема не найдена.');
}
if (!node_can_view($node)) {
    abort(403, 'У вас нет доступа к этому разделу.');
}
$tid = (int)$thread['id'];
$withDeleted = $isMod;

$replyBody = '';
$replyError = '';

// ---------- POST: ответ, отслеживание, модерация ----------
if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'reply') {
        if (!is_logged()) {
            flash('info', 'Войдите, чтобы ответить в теме.');
            redirect(url('/forum/login.php', ['return' => thread_url($tid)]));
        }
        $replyBody = input_text('body');
        $err = fp_reply_denied($thread, $node);
        if ($err === '') {
            $err = fp_body_error($replyBody);
        }
        if ($err === '') {
            $wait = fp_flood_wait();
            if ($wait > 0) {
                $err = 'Подождите ' . $wait . ' сек. перед отправкой следующего сообщения.';
            }
        }
        if ($err === '') {
            $pid = fp_create_post($thread, $node, $replyBody);
            redirect(post_url($pid));
        }
        $replyError = $err;
    } elseif ($action === 'watch') {
        require_login();
        $on = !thread_is_watched($tid);
        thread_watch($tid, $on);
        if (fp_is_ajax()) {
            json_out(['ok' => true, 'watched' => $on]);
        }
        flash('success', $on ? 'Вы отслеживаете тему: об ответах придёт оповещение.' : 'Вы больше не отслеживаете тему.');
        redirect(fp_referer(thread_url($tid)));
    } elseif ($action === 'mod') {
        if (!$isMod) {
            abort(403, 'Действие только для модераторов.');
        }
        list($ok, $msg, $to) = fp_mod_thread($thread, $node, input('do'));
        flash($ok ? 'success' : 'error', $msg);
        redirect($to);
    } else {
        abort(400, 'Неизвестное действие.');
    }
}

// ---------- Переходы: к первому непрочитанному или к последнему ----------
$goto = query_str('goto');
if ($goto === 'unread' || $goto === 'last') {
    $visible = $withDeleted ? '' : ' AND is_deleted = 0';
    $target = null;
    if ($goto === 'unread') {
        if (!is_logged()) {
            redirect(thread_url($tid));
        }
        $rt = thread_read_times([$thread]);
        $target = db_one('SELECT id, thread_id FROM posts WHERE thread_id = :t AND created_at > :r' . $visible . ' ORDER BY id LIMIT 1',
            ['t' => $tid, 'r' => $rt[$tid] ?? read_cutoff()]);
    }
    if (!$target) {
        $target = db_one('SELECT id, thread_id FROM posts WHERE thread_id = :t' . $visible . ' ORDER BY id DESC LIMIT 1', ['t' => $tid]);
    }
    redirect($target ? fp_post_anchor_url($target, $withDeleted) : thread_url($tid));
}

// ---------- Просмотры: один раз за сессию ----------
if (!is_post() && empty($_SESSION['fp_viewed'][$tid])) {
    db_exec('UPDATE threads SET views = views + 1 WHERE id = :id', ['id' => $tid]);
    $thread['views']++;
    $viewed = $_SESSION['fp_viewed'] ?? [];
    $viewed[$tid] = 1;
    if (count($viewed) > 300) {
        $viewed = array_slice($viewed, -300, null, true);
    }
    $_SESSION['fp_viewed'] = $viewed;
}

// ---------- Сообщения страницы ----------
$visibleSql = $withDeleted ? '' : ' AND p.is_deleted = 0';
$total = (int)db_val('SELECT COUNT(*) FROM posts p WHERE p.thread_id = :t' . $visibleSql, ['t' => $tid]);
$pg = paginate($total, fp_posts_per_page(), query_int('page', 1));
if (query_int('page') > $pg['pages'] && !is_post()) {
    redirect(thread_url($tid, $pg['pages']));
}
$posts = db_all(fp_post_select_sql() . ' WHERE p.thread_id = :t' . $visibleSql . ' ORDER BY p.id LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], ['t' => $tid]);

$readAt = null;
if (is_logged()) {
    $rt = thread_read_times([$thread]);
    $readAt = $rt[$tid] ?? null;
}
$hasUnread = $readAt !== null && $thread['last_post_at'] > $readAt;

$ids = array_map(function ($p) {
    return (int)$p['id'];
}, $posts);
$likes = fp_load_likes($ids);
$deletedIds = [];
foreach ($posts as $p) {
    if ($p['is_deleted']) {
        $deletedIds[] = (int)$p['id'];
    }
}
$deletedInfo = $isMod ? fp_deleted_info($deletedIds) : [];
$firstPostId = fp_first_post_id($tid);
$replyDenied = fp_reply_denied($thread, $node);
$watched = thread_is_watched($tid);
$nodePrefixes = node_prefixes($node['id']);

// ---------- Заголовок ----------
$author = user_by_id($thread['user_id']);
$meta = '<span class="tmeta">' . icon('user') . ($author ? '<a href="' . e(url('/forum/member.php', ['id' => (int)$author['id']])) . '">' . e($author['username']) . '</a>' : 'Гость') . '</span>';
$meta .= '<span class="tmeta">' . icon('clock') . '<a href="' . e(thread_url($tid)) . '" title="' . e(date('d.m.Y H:i', strtotime($thread['created_at']))) . '">' . e(fdate($thread['created_at'])) . '</a></span>';
if ($thread['is_locked']) {
    $meta .= '<span class="tmeta tmeta-flag">' . icon('lock') . 'Закрыта</span>';
}
if ($thread['is_pinned']) {
    $meta .= '<span class="tmeta tmeta-flag">' . icon('pin') . 'Закреплена</span>';
}
if ($thread['is_deleted']) {
    $meta .= '<span class="tmeta tmeta-flag tmeta-danger">' . icon('trash') . 'Удалена</span>';
}
$firstBody = $firstPostId ? (string)db_val('SELECT body FROM posts WHERE id = :id', ['id' => $firstPostId]) : '';

forum_header([
    'title' => $thread['title'],
    'h1_html' => prefix_html($thread['prefix_id']) . e($thread['title']),
    'meta_html' => $meta,
    'crumbs' => node_crumbs($node['id']),
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'js' => ['thread.js'],
    'description' => $firstBody !== '' ? bbcode_plain($firstBody, 160) : null,
    'body_class' => 'page-thread',
]);

// Форма модератора
$modForm = function ($do, $label, $iconName, $confirm = '') use ($tid) {
    return '<form method="post" action="' . e(thread_url($tid)) . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="mod"><input type="hidden" name="do" value="' . e($do) . '">'
        . '<button type="submit">' . icon($iconName) . ' ' . e($label) . '</button></form>';
};

$pagesHtml = fp_pagination($pg, '/forum/thread.php', ['id' => $tid]);
?>
<div class="page-actions thread-actions">
  <?= $pagesHtml ?>
  <?php if ($hasUnread): ?>
  <a class="btn btn-black btn-pill" href="<?= e(url('/forum/thread.php', ['id' => $tid, 'goto' => 'unread'])) ?>"><?= icon('arrow-down') ?> Перейти к новому</a>
  <?php endif; ?>
  <?php if (is_logged()): ?>
  <form method="post" action="<?= e(thread_url($tid)) ?>" class="inline-form" data-watch-form>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="watch">
    <button class="btn btn-black btn-pill<?= $watched ? ' is-on' : '' ?>" type="submit" title="Оповещения об ответах в теме"><?= icon('bell') ?> <span><?= $watched ? 'Не отслеживать' : 'Отслеживать' ?></span></button>
  </form>
  <?php endif; ?>
  <?php if ($isMod): ?>
  <div class="dropdown mod-dd">
    <button class="btn btn-black btn-pill" type="button" data-dropdown><?= icon('shield') ?> Модерация <?= icon('chevron-down') ?></button>
    <div class="dropdown-menu dropdown-right mod-menu">
      <?php if ($nodePrefixes): ?>
      <div class="dropdown-label">Статус темы</div>
      <form method="post" action="<?= e(thread_url($tid)) ?>" class="mod-status">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mod">
        <input type="hidden" name="do" value="prefix">
        <?php foreach ($nodePrefixes as $pid => $pr): $cur = (int)$thread['prefix_id'] === (int)$pid; ?>
        <div class="mod-status-row<?= $cur ? ' current' : '' ?>">
          <button type="submit" name="prefix_id" value="<?= (int)$pid ?>" class="mod-status-btn" title="Поставить статус"><?= prefix_html($pid) ?><?= $cur ? icon('check') : '' ?></button>
          <?php if ($node['default_prefix_id'] && (int)$pid !== (int)$node['default_prefix_id']): ?>
          <button type="submit" name="prefix_lock" value="<?= (int)$pid ?>" class="mod-status-lock" title="Поставить статус и закрыть тему"><?= icon('lock') ?> и закрыть</button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </form>
      <div class="dropdown-sep"></div>
      <?php endif; ?>
      <?= $thread['is_pinned'] ? $modForm('unpin', 'Открепить', 'pin') : $modForm('pin', 'Закрепить', 'pin') ?>
      <?= $thread['is_locked'] ? $modForm('unlock', 'Открыть тему', 'unlock') : $modForm('lock', 'Закрыть тему', 'lock') ?>
      <?php if ($nodePrefixes || $thread['prefix_id']): ?>
      <button type="button" data-dialog-open="dlg-prefix"><?= icon('tag') ?> Изменить префикс</button>
      <?php endif; ?>
      <button type="button" data-dialog-open="dlg-move"><?= icon('move') ?> Переместить</button>
      <button type="button" data-dialog-open="dlg-rename"><?= icon('edit') ?> Переименовать</button>
      <div class="dropdown-sep"></div>
      <?= $thread['is_deleted'] ? $modForm('restore', 'Восстановить тему', 'undo') : '<div class="mod-danger">' . $modForm('delete', 'Удалить тему', 'trash', 'Удалить тему «' . $thread['title'] . '»? Её смогут видеть только модераторы.') . '</div>' ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($thread['is_deleted']): ?>
<div class="flash flash-error thread-deleted-note">
  <?= icon('trash') ?>
  <div>Эта тема удалена. Её видят только модераторы.</div>
  <form method="post" action="<?= e(thread_url($tid)) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mod">
    <input type="hidden" name="do" value="restore">
    <button class="btn btn-sm btn-success" type="submit"><?= icon('undo') ?> Восстановить</button>
  </form>
</div>
<?php endif; ?>

<div class="posts">
<?php
$pos = $pg['offset'];
$lastShownAt = null;
foreach ($posts as $p) {
    $pos++;
    $pidN = (int)$p['id'];
    echo fp_render_post_entry($p, [
        'thread' => $thread,
        'node' => $node,
        'pos' => $pos,
        'unread' => $readAt !== null && $p['created_at'] > $readAt && (int)$p['user_id'] !== uid(),
        'likes' => $likes[$pidN] ?? [],
        'is_first' => $pidN === $firstPostId,
        'deleted' => $deletedInfo[$pidN] ?? null,
        'can_quote' => $replyDenied === '',
    ]);
    if ($lastShownAt === null || $p['created_at'] > $lastShownAt) {
        $lastShownAt = $p['created_at'];
    }
}
if (!$posts) {
    echo '<div class="card empty">' . icon('chats') . '<div>В теме нет сообщений.</div></div>';
}
?>
</div>

<?php if ($pg['pages'] > 1): ?>
<div class="page-actions thread-actions"><?= $pagesHtml ?></div>
<?php endif; ?>

<?php if ($replyDenied === ''): $me = user(); ?>
<div class="card quick-reply" id="reply">
  <div class="qr-user"><?= avatar($me, 'xl') ?></div>
  <div class="qr-main">
    <?php if ($thread['is_locked']): ?>
    <div class="qr-note"><?= icon('lock') ?> Тема закрыта. Вы можете ответить как модератор.</div>
    <?php endif; ?>
    <?php if ($replyError): ?>
    <div class="flash flash-error"><?= icon('alert') ?><div><?= e($replyError) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(thread_url($tid, $pg['page'])) ?>#reply" class="qr-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reply">
      <textarea class="textarea" name="body" rows="6" data-editor data-draft="reply-<?= $tid ?>" placeholder="Напишите свой ответ..." maxlength="50000"><?= e($replyBody) ?></textarea>
      <div class="qr-actions">
        <span class="muted small hide-sm">Ctrl + Enter - отправить</span>
        <button class="btn btn-accent" type="submit"><?= icon('reply') ?> Ответить</button>
      </div>
    </form>
  </div>
</div>
<?php elseif ($replyDenied === 'guest'): ?>
<div class="card notice-card qr-guest">
  <div class="notice-icon"><?= icon('chat') ?></div>
  <div>
    <h2>Войдите или зарегистрируйтесь, чтобы ответить</h2>
    <p class="muted">Отвечать в темах могут только зарегистрированные пользователи.</p>
    <div class="btn-row">
      <a class="btn btn-white btn-pill" href="<?= e(url('/forum/login.php', ['return' => thread_url($tid, $pg['page'])])) ?>"><?= icon('login') ?> Вход</a>
      <a class="btn btn-black btn-pill" href="<?= e(url('/forum/register.php')) ?>"><?= icon('user-plus') ?> Регистрация</a>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card notice-card qr-closed">
  <div class="notice-icon"><?= icon($thread['is_locked'] ? 'lock' : 'ban') ?></div>
  <div>
    <h2><?= $thread['is_locked'] ? 'Тема закрыта' : 'Ответ недоступен' ?></h2>
    <p class="muted"><?= e($replyDenied) ?></p>
  </div>
</div>
<?php endif; ?>

<?php if ($isMod): ?>
<dialog class="modal" id="dlg-prefix">
  <form method="post" action="<?= e(thread_url($tid)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mod">
    <input type="hidden" name="do" value="prefix">
    <div class="modal-head"><span>Изменить префикс</span><button type="button" class="modal-x" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button></div>
    <div class="modal-body form">
      <div class="form-row">
        <label class="label" for="m-prefix">Префикс темы</label>
        <select class="select" id="m-prefix" name="prefix_id">
          <option value="0">Без префикса</option>
          <?php foreach ($nodePrefixes as $pid => $pr): ?>
          <option value="<?= (int)$pid ?>"<?= (int)$thread['prefix_id'] === (int)$pid ? ' selected' : '' ?>><?= e($pr['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="hint">Автор темы получит оповещение о смене статуса.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-dialog-close>Отмена</button>
      <button type="submit" class="btn btn-accent"><?= icon('check') ?> Сохранить</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-move">
  <form method="post" action="<?= e(thread_url($tid)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mod">
    <input type="hidden" name="do" value="move">
    <div class="modal-head"><span>Переместить тему</span><button type="button" class="modal-x" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button></div>
    <div class="modal-body form">
      <div class="form-row">
        <label class="label" for="m-node">Раздел</label>
        <select class="select" id="m-node" name="node_id"><?= fp_node_options($node['id']) ?></select>
      </div>
      <div class="hint">Автор темы получит оповещение о переносе.</div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-dialog-close>Отмена</button>
      <button type="submit" class="btn btn-accent"><?= icon('move') ?> Переместить</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-rename">
  <form method="post" action="<?= e(thread_url($tid)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mod">
    <input type="hidden" name="do" value="rename">
    <div class="modal-head"><span>Переименовать тему</span><button type="button" class="modal-x" data-dialog-close aria-label="Закрыть"><?= icon('x') ?></button></div>
    <div class="modal-body form">
      <div class="form-row">
        <label class="label" for="m-title">Заголовок</label>
        <input class="input" id="m-title" name="title" value="<?= e($thread['title']) ?>" minlength="3" maxlength="150" required>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-dialog-close>Отмена</button>
      <button type="submit" class="btn btn-accent"><?= icon('check') ?> Сохранить</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php
if ($lastShownAt !== null) {
    thread_mark_read($tid, $lastShownAt);
}
forum_footer();
