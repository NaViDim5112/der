<?php
// Закладки: сохранённые сообщения форума с заметками
require __DIR__ . '/../../app/bootstrap.php';

$ajax = fp_is_ajax();
$self = url('/forum/bookmarks.php');

// ---------- POST: добавить / убрать, заметка ----------
if (is_post()) {
    $fail = function ($msg, $code = 400) use ($ajax, $self) {
        if ($ajax) {
            cnt_json_fail($msg, $code);
        }
        flash('error', $msg);
        redirect(fp_referer($self));
    };
    if (!cnt_csrf_ok()) {
        $fail('Сессия устарела. Обновите страницу и попробуйте ещё раз.');
    }
    if (!is_logged()) {
        if ($ajax) {
            cnt_json_fail('Войдите, чтобы добавлять закладки.', 401, ['login' => url('/forum/login.php', ['return' => fp_referer($self)])]);
        }
        redirect(url('/forum/login.php', ['return' => fp_referer($self)]));
    }
    if (!cnt_ready()) {
        $fail('Закладки пока недоступны: администратору нужно обновить базу.', 503);
    }
    $action = input('action');
    $postId = input_int('post_id');

    if ($action === 'toggle' || $action === 'add') {
        $post = $postId ? fp_post_get($postId) : null;
        $thread = $post ? thread_get($post['thread_id']) : null;
        $node = $thread ? node_get($thread['node_id']) : null;
        if (!$post || !fp_post_visible($post, $thread, $node)) {
            $fail('Сообщение не найдено.', 404);
        }
        list($ok, $on, $err) = cnt_bm_toggle($postId, uid(), $action === 'add' ? true : null);
        if (!$ok) {
            $fail($err, 409);
        }
        if ($ajax) {
            json_out(['ok' => true, 'on' => $on, 'message' => $on ? 'Сообщение добавлено в закладки.' : 'Закладка удалена.']);
        }
        flash('success', $on ? 'Сообщение добавлено в закладки.' : 'Закладка удалена.');
        redirect(fp_referer(post_url($postId)));
    }
    if ($action === 'remove') {
        db_exec('DELETE FROM bookmarks WHERE user_id = :u AND post_id = :p', ['u' => uid(), 'p' => $postId]);
        if ($ajax) {
            json_out(['ok' => true, 'on' => false]);
        }
        flash('success', 'Закладка удалена.');
        redirect(fp_referer($self));
    }
    if ($action === 'note') {
        $note = acc_clean_line(input('note'));
        if (mb_strlen($note) > 255) {
            $fail('Заметка слишком длинная (максимум 255 символов).');
        }
        db_exec('UPDATE bookmarks SET note = :n WHERE user_id = :u AND post_id = :p', ['n' => $note !== '' ? $note : null, 'u' => uid(), 'p' => $postId]);
        if ($ajax) {
            json_out(['ok' => true, 'note' => $note, 'message' => 'Заметка сохранена.']);
        }
        flash('success', 'Заметка сохранена.');
        redirect(fp_referer($self));
    }
    $fail('Неизвестное действие.');
}

// ---------- Список ----------
require_login();
$ready = cnt_ready();
$rows = [];
$hidden = 0;
$pg = paginate(0, 20, 1);
if ($ready) {
    $isMod = can_moderate();
    $nodeIds = fp_viewable_node_ids();
    $in = db_in($nodeIds ?: [0], 'n');
    $where = 'b.user_id = :u AND t.node_id IN (' . $in['sql'] . ')' . ($isMod ? '' : ' AND p.is_deleted = 0 AND t.is_deleted = 0');
    $params = array_merge(['u' => uid()], $in['params']);
    $from = ' FROM bookmarks b JOIN posts p ON p.id = b.post_id JOIN threads t ON t.id = p.thread_id';
    $total = (int)db_val('SELECT COUNT(*)' . $from . ' WHERE ' . $where, $params);
    $all = (int)db_val('SELECT COUNT(*) FROM bookmarks WHERE user_id = :u', ['u' => uid()]);
    $hidden = max(0, $all - $total);
    $pg = paginate($total, 20, query_int('page', 1));
    $rows = db_all('SELECT b.post_id, b.note, b.created_at AS bm_at, p.body, p.created_at AS post_at, p.user_id AS author_id, p.is_deleted AS post_deleted,
            t.id AS thread_id, t.title, t.prefix_id, t.node_id, t.is_deleted AS thread_deleted,
            u.username, u.avatar, g.color AS group_color' . $from . '
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN user_groups g ON g.id = u.group_id
        WHERE ' . $where . ' ORDER BY b.id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
}

forum_header([
    'title' => 'Закладки',
    'meta_html' => 'Сохранённые сообщения форума. Добавить сообщение можно значком закладки в его шапке.',
    'crumbs' => [['Закладки', $self]],
    'nav' => 'forums',
    'right' => false,
    'css' => ['thread.css'],
    'body_class' => 'page-bookmarks',
]);
?>
<?php if (!$ready): ?>
<div class="card notice-card"><div class="notice-icon"><?= icon('bookmark') ?></div><div><h2>Закладки скоро появятся</h2><p class="muted">Администратору нужно нажать «Обновить базу» в админ-панели.</p></div></div>
<?php elseif (!$rows): ?>
<div class="card empty cnt-bm-empty">
  <?= icon('bookmark') ?>
  <div><b>Закладок пока нет</b></div>
  <div class="muted">Нажмите значок закладки в шапке любого сообщения, чтобы сохранить его здесь. Удобно для правил, инструкций и важных ответов администрации.</div>
</div>
<?php else: ?>
<?php if ($pg['pages'] > 1): ?><div class="page-actions"><?= fp_pagination($pg, '/forum/bookmarks.php') ?></div><?php endif; ?>
<div class="cnt-bm-list">
  <?php foreach ($rows as $r):
      $pid = (int)$r['post_id'];
      $author = fp_list_user($r['author_id'], $r['username'], $r['avatar'], $r['group_color']);
      $n = node_get($r['node_id']);
      $excerpt = site_plain($r['body'], 320); ?>
  <article class="card cnt-bm-item" data-bm-item="<?= $pid ?>">
    <div class="cnt-bm-head">
      <?= fp_avatar_link($author, 'm') ?>
      <div class="cnt-bm-titles">
        <a class="cnt-bm-thread" href="<?= e(post_url($pid)) ?>"><?= prefix_html($r['prefix_id']) ?><?= e($r['title']) ?></a>
        <div class="cnt-bm-meta muted small">
          <?= fp_user_link($author) ?> · <?= e(fdate($r['post_at'])) ?><?php if ($n): ?> · <a href="<?= e(node_url($n)) ?>"><?= e($n['title']) ?></a><?php endif; ?>
          <?php if ($r['post_deleted'] || $r['thread_deleted']): ?> · <span class="tag tag-deleted"><?= icon('trash') ?>Удалено</span><?php endif; ?>
        </div>
      </div>
      <form method="post" action="<?= e($self) ?>" class="inline-form" data-bm-remove>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="post_id" value="<?= $pid ?>">
        <button class="btn btn-ghost btn-sm" type="submit" title="Убрать из закладок"><?= icon('trash') ?><span class="hide-sm">Убрать</span></button>
      </form>
    </div>
    <a class="cnt-bm-excerpt" href="<?= e(post_url($pid)) ?>"><?= $excerpt !== '' ? e($excerpt) : '<span class="muted">Сообщение без текста (картинка или видео)</span>' ?></a>
    <div class="cnt-bm-foot">
      <form method="post" action="<?= e($self) ?>" class="cnt-bm-note" data-bm-note>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="note">
        <input type="hidden" name="post_id" value="<?= $pid ?>">
        <?= icon('edit') ?>
        <input class="input" name="note" value="<?= e((string)$r['note']) ?>" maxlength="255" placeholder="Заметка для себя (видна только вам)" aria-label="Заметка">
        <button class="btn btn-black btn-sm" type="submit">Сохранить</button>
      </form>
      <span class="muted small cnt-bm-date" title="Когда добавлено в закладки"><?= icon('bookmark') ?> <?= e(fdate($r['bm_at'])) ?></span>
      <a class="btn btn-ghost btn-sm" href="<?= e(post_url($pid)) ?>"><?= icon('arrow-right') ?> К сообщению</a>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php if ($pg['pages'] > 1): ?><div class="page-actions"><?= fp_pagination($pg, '/forum/bookmarks.php') ?></div><?php endif; ?>
<?php endif; ?>
<?php if ($hidden > 0): ?>
<p class="muted small cnt-bm-hidden"><?= icon('eye-off') ?> Ещё <?= num($hidden) ?> <?= plural($hidden, 'закладка скрыта', 'закладки скрыты', 'закладок скрыто') ?>: сообщения удалены или раздел стал недоступен.</p>
<?php endif; ?>
<?php
forum_footer();
