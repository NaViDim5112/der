<?php
require __DIR__ . '/../../app/bootstrap.php';

require_login();

$myId = uid();
$listUrl = url('/forum/conversations.php');
$convId = query_int('id');
$isNew = !$convId && isset($_GET['new']);
$errors = [];
$v = ['recipients' => '', 'title' => '', 'message' => ''];

// Проверка ников получателей: [пользователи, ошибка]
$resolveRecipients = function (array $names, array $skipIds = []) use ($myId) {
    $found = [];
    $missing = [];
    foreach ($names as $n) {
        $u = user_by_name($n);
        if (!$u) {
            $missing[] = $n;
            continue;
        }
        if ((int)$u['id'] === $myId) {
            return [[], 'Нельзя отправить сообщение самому себе.'];
        }
        if (in_array((int)$u['id'], $skipIds, true)) {
            return [[], $u['username'] . ' уже участвует в переписке.'];
        }
        if (!is_staff() && acc_is_ignoring($u['id'], $myId)) {
            return [[], $u['username'] . ' ограничил(а) получение ваших сообщений.'];
        }
        $found[(int)$u['id']] = $u;
    }
    if ($missing) {
        return [[], (count($missing) > 1 ? 'Не найдены пользователи: ' : 'Не найден пользователь: ') . implode(', ', $missing) . '.'];
    }
    return [array_values($found), ''];
};

// ---------- Новая переписка ----------

if ($isNew) {
    $v['recipients'] = acc_clean_line(query_str('to'));
    if (is_post()) {
        csrf_check();
        $v['recipients'] = acc_clean_line(input('recipients'));
        $v['title'] = acc_clean_line(input('title'));
        $v['message'] = input_text('message');
        $names = acc_parse_names($v['recipients']);
        $recipients = [];
        if (is_banned()) {
            $errors['_'] = 'Пока аккаунт заблокирован, отправлять сообщения нельзя.';
        } else {
            if (!$names) {
                $errors['recipients'] = 'Укажите хотя бы одного получателя.';
            } elseif (count($names) > ACC_CONV_MAX_RECIPIENTS) {
                $errors['recipients'] = 'Не больше ' . ACC_CONV_MAX_RECIPIENTS . ' получателей.';
            } else {
                list($recipients, $err) = $resolveRecipients($names);
                if ($err !== '') {
                    $errors['recipients'] = $err;
                }
            }
            $len = mb_strlen($v['title']);
            if ($len < 3 || $len > 100) {
                $errors['title'] = 'Заголовок - от 3 до 100 символов.';
            }
            if ($v['message'] === '') {
                $errors['message'] = 'Напишите сообщение.';
            } elseif (mb_strlen($v['message']) > 20000) {
                $errors['message'] = 'Сообщение слишком длинное (не больше 20 000 символов).';
            }
            if (!$errors && !acc_conv_flood_ok()) {
                $errors['_'] = 'Слишком часто. Подождите ' . ACC_CONV_FLOOD_SECONDS . ' секунд перед следующим сообщением.';
            }
        }
        if (!$errors) {
            $now = now();
            db()->beginTransaction();
            $cid = db_insert('conversations', [
                'title' => $v['title'],
                'starter_id' => $myId,
                'reply_count' => 0,
                'last_message_at' => $now,
                'last_message_user_id' => $myId,
                'created_at' => $now,
            ]);
            db_insert('conversation_users', ['conversation_id' => $cid, 'user_id' => $myId, 'last_read_at' => $now, 'is_left' => 0]);
            foreach ($recipients as $r) {
                db_insert('conversation_users', ['conversation_id' => $cid, 'user_id' => (int)$r['id'], 'last_read_at' => null, 'is_left' => 0]);
            }
            acc_conv_add_message($cid, $v['message'], true);
            db()->commit();
            acc_conv_notify($cid, array_map(function ($r) {
                return (int)$r['id'];
            }, $recipients));
            flash('success', 'Переписка создана.');
            redirect(url('/forum/conversations.php', ['id' => $cid]));
        }
    }

    forum_header([
        'title' => 'Новая переписка',
        'crumbs' => [['Личные сообщения', $listUrl], ['Новая переписка', url('/forum/conversations.php', ['new' => 1])]],
        'right' => false,
        'nav' => '',
        'css' => ['account.css'],
        'js' => ['account.js'],
    ]);
    ?>
<section class="card">
  <h2 class="card-title">Новая переписка</h2>
  <?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= icon('alert') ?><div><?= e($errors['_']) ?></div></div><?php endif; ?>
  <?php if (is_banned()): ?>
  <div class="muted">Пока аккаунт заблокирован, отправлять личные сообщения нельзя.</div>
  <?php else: ?>
  <form method="post" class="form" action="<?= e(url('/forum/conversations.php', ['new' => 1])) ?>">
    <?= csrf_field() ?>
    <div class="form-row">
      <label class="label" for="cv-to">Получатели</label>
      <input class="input<?= acc_invalid($errors, 'recipients') ?>" id="cv-to" name="recipients" value="<?= e($v['recipients']) ?>" maxlength="200" placeholder="Ник1, Ник2" autocomplete="off" required<?= $v['recipients'] === '' ? ' autofocus' : '' ?>>
      <?= acc_field_error($errors, 'recipients') ?>
      <div class="hint">Через запятую, до <?= ACC_CONV_MAX_RECIPIENTS ?> человек. Ник нужно писать точно, как на форуме.</div>
    </div>
    <div class="form-row">
      <label class="label" for="cv-title">Заголовок</label>
      <input class="input<?= acc_invalid($errors, 'title') ?>" id="cv-title" name="title" value="<?= e($v['title']) ?>" maxlength="100" required<?= $v['recipients'] !== '' ? ' autofocus' : '' ?>>
      <?= acc_field_error($errors, 'title') ?>
    </div>
    <div class="form-row">
      <label class="label" for="cv-msg">Сообщение</label>
      <textarea class="textarea" id="cv-msg" name="message" rows="8" data-editor data-draft="conv-new"><?= e($v['message']) ?></textarea>
      <?= acc_field_error($errors, 'message') ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-white btn-pill" type="submit"><?= icon('send') ?> Начать переписку</button>
      <a class="btn btn-ghost btn-pill" href="<?= e($listUrl) ?>">Отмена</a>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php
    forum_footer();
    exit;
}

// ---------- Просмотр переписки ----------

if ($convId) {
    $conv = acc_conv_load($convId);
    if (!$conv) {
        abort(404, 'Переписка не найдена или вы из неё вышли.');
    }
    $convUrl = url('/forum/conversations.php', ['id' => $convId]);
    $isStarter = (int)$conv['starter_id'] === $myId;

    if (is_post()) {
        csrf_check();
        $action = input('action');
        if ($action === 'reply') {
            $v['message'] = input_text('message');
            if (is_banned()) {
                $errors['message'] = 'Пока аккаунт заблокирован, отправлять сообщения нельзя.';
            } elseif ($v['message'] === '') {
                $errors['message'] = 'Напишите сообщение.';
            } elseif (mb_strlen($v['message']) > 20000) {
                $errors['message'] = 'Сообщение слишком длинное (не больше 20 000 символов).';
            } elseif (!acc_conv_flood_ok()) {
                $errors['message'] = 'Слишком часто. Подождите ' . ACC_CONV_FLOOD_SECONDS . ' секунд перед следующим сообщением.';
            } elseif (!db_val('SELECT 1 FROM conversation_users WHERE conversation_id = :c AND user_id <> :u AND is_left = 0 LIMIT 1', ['c' => $convId, 'u' => $myId])) {
                $errors['message'] = 'В переписке не осталось других участников.';
            }
            if (!$errors) {
                $mid = acc_conv_add_message($convId, $v['message']);
                acc_conv_notify($convId);
                $count = (int)db_val('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = :c', ['c' => $convId]);
                $page = (int)ceil($count / 20);
                redirect(url('/forum/conversations.php', ['id' => $convId, 'page' => $page > 1 ? $page : null]) . '#msg-' . $mid);
            }
        } elseif ($action === 'leave') {
            db_exec('UPDATE conversation_users SET is_left = 1 WHERE conversation_id = :c AND user_id = :u', ['c' => $convId, 'u' => $myId]);
            flash('success', 'Вы покинули переписку «' . $conv['title'] . '».');
            redirect($listUrl);
        } elseif ($action === 'invite') {
            $names = acc_parse_names(input('names'));
            $current = db_all('SELECT user_id, is_left FROM conversation_users WHERE conversation_id = :c', ['c' => $convId]);
            $activeIds = [];
            $leftIds = [];
            foreach ($current as $row) {
                if ((int)$row['is_left']) {
                    $leftIds[] = (int)$row['user_id'];
                } else {
                    $activeIds[] = (int)$row['user_id'];
                }
            }
            if (!$isStarter) {
                $errors['invite'] = 'Приглашать может только создатель переписки.';
            } elseif (is_banned()) {
                $errors['invite'] = 'Пока аккаунт заблокирован, приглашать нельзя.';
            } elseif (!$names) {
                $errors['invite'] = 'Укажите ник.';
            } else {
                list($invite, $err) = $resolveRecipients($names, $activeIds);
                $newCount = 0;
                foreach ($invite as $u) {
                    if (!in_array((int)$u['id'], $leftIds, true)) {
                        $newCount++;
                    }
                }
                if ($err !== '') {
                    $errors['invite'] = $err;
                } elseif (count($current) + $newCount > ACC_CONV_MAX_USERS) {
                    $errors['invite'] = 'В переписке может быть не больше ' . ACC_CONV_MAX_USERS . ' участников.';
                } else {
                    foreach ($invite as $u) {
                        db_exec('INSERT INTO conversation_users (conversation_id, user_id, last_read_at, is_left) VALUES (:c, :u, NULL, 0)
                                 ON DUPLICATE KEY UPDATE is_left = 0', ['c' => $convId, 'u' => (int)$u['id']]);
                    }
                    acc_conv_notify($convId, array_map(function ($u) {
                        return (int)$u['id'];
                    }, $invite));
                    flash('success', 'Приглашено: ' . implode(', ', array_map(function ($u) {
                        return $u['username'];
                    }, $invite)) . '.');
                    redirect($convUrl);
                }
            }
        } else {
            abort(400, 'Неизвестное действие.');
        }
    }

    $users = acc_conv_users($convId);
    $total = (int)db_val('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = :c', ['c' => $convId]);
    $lastRead = $conv['last_read_at'];
    $pageParam = query_int('page', 0);
    if ($pageParam < 1) {
        // Без номера страницы: первая непрочитанная, иначе последняя
        $firstUnread = $lastRead ? db_val('SELECT MIN(id) FROM conversation_messages WHERE conversation_id = :c AND created_at > :t AND user_id <> :u', ['c' => $convId, 't' => $lastRead, 'u' => $myId])
            : db_val('SELECT MIN(id) FROM conversation_messages WHERE conversation_id = :c AND user_id <> :u', ['c' => $convId, 'u' => $myId]);
        if ($firstUnread) {
            $before = (int)db_val('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = :c AND id < :id', ['c' => $convId, 'id' => (int)$firstUnread]);
            $pageParam = (int)floor($before / 20) + 1;
        } else {
            $pageParam = (int)max(1, ceil($total / 20));
        }
    }
    $p = paginate($total, 20, $pageParam);
    $messages = db_all('SELECT m.*, u.username, u.avatar, u.custom_title, u.signature, u.last_activity, g.name AS group_name, g.color AS group_color
        FROM conversation_messages m JOIN users u ON u.id = m.user_id JOIN user_groups g ON g.id = u.group_id
        WHERE m.conversation_id = :c ORDER BY m.id ASC LIMIT :lim OFFSET :off', ['c' => $convId, 'lim' => $p['per_page'], 'off' => $p['offset']]);
    // Отмечаем прочитанным
    db_exec('UPDATE conversation_users SET last_read_at = :t WHERE conversation_id = :c AND user_id = :u',
        ['t' => max(now(), (string)$conv['last_message_at']), 'c' => $convId, 'u' => $myId]);
    db_exec('UPDATE alerts SET is_read = 1 WHERE user_id = :u AND type = :t AND extra = :x AND is_read = 0',
        ['u' => $myId, 't' => 'conversation', 'x' => (string)$convId]);
    $active = array_filter($users, function ($u) {
        return !(int)$u['is_left'];
    });

    forum_header([
        'title' => $conv['title'],
        'meta_html' => icon('users') . '<span>' . num(count($active)) . ' ' . plural(count($active), 'участник', 'участника', 'участников') . '</span>' . icon('calendar') . '<span>Начата ' . e(fdate($conv['created_at'])) . '</span>' . icon('chats') . '<span>' . num($total) . ' ' . plural($total, 'сообщение', 'сообщения', 'сообщений') . '</span>',
        'crumbs' => [['Личные сообщения', $listUrl], [$conv['title'], $convUrl]],
        'right' => false,
        'nav' => '',
        'css' => ['account.css'],
        'js' => ['account.js'],
    ]);
    ?>
<div class="acc-conv">
  <div class="acc-conv-main">
    <?php if ($p['pages'] > 1): ?><div class="page-actions"><?= pagination_html($p, '/forum/conversations.php', ['id' => $convId]) ?></div><?php endif; ?>
    <?php foreach ($messages as $i => $msg):
        $author = ['id' => $msg['user_id'], 'username' => $msg['username'], 'avatar' => $msg['avatar'], 'group_color' => $msg['group_color']];
        $isNewMsg = (int)$msg['user_id'] !== $myId && (!$lastRead || $msg['created_at'] > $lastRead);
        $num = $p['offset'] + $i + 1;
        $body = '<div class="bb">' . bbcode($msg['body']) . '</div>';
    ?>
    <article class="acc-msg<?= $isNewMsg ? ' is-new' : '' ?>" id="msg-<?= (int)$msg['id'] ?>">
      <div class="acc-msg-side">
        <a href="<?= e(acc_member_url($msg['user_id'])) ?>"><?= avatar($author, 'xl') ?></a>
        <?= user_link($author) ?>
        <div class="muted"><?= e(acc_user_title($msg)) ?></div>
      </div>
      <div class="acc-msg-main">
        <div class="acc-msg-head">
          <span class="acc-msg-user"><?= avatar($author, 's') ?><?= user_link($author) ?></span>
          <span><?= e(fdate($msg['created_at'])) ?><?= $isNewMsg ? ' <span class="badge-new">Новое</span>' : '' ?></span>
          <a href="#msg-<?= (int)$msg['id'] ?>">#<?= $num ?></a>
        </div>
        <?= acc_ignored_wrap($body, $msg['user_id']) ?>
        <?= hook_html('conversation_message_actions', $msg, $convId) ?>
      </div>
    </article>
    <?php endforeach; ?>
    <?php if ($p['pages'] > 1): ?><div class="page-actions"><?= pagination_html($p, '/forum/conversations.php', ['id' => $convId]) ?></div><?php endif; ?>

    <section class="card acc-reply" id="reply">
      <?php if (is_banned()): ?>
      <div class="muted"><?= icon('ban') ?> Пока аккаунт заблокирован, отвечать нельзя.</div>
      <?php elseif (count($active) < 2): ?>
      <div class="muted"><?= icon('info') ?> Все остальные участники покинули переписку.<?= $isStarter ? ' Пригласите кого-нибудь, чтобы продолжить.' : '' ?></div>
      <?php else: ?>
      <form method="post" class="form" action="<?= e($convUrl) ?>#reply">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reply">
        <div class="form-row">
          <textarea class="textarea" name="message" rows="5" placeholder="Ваш ответ..." data-editor data-draft="conv-<?= (int)$convId ?>"><?= e($v['message']) ?></textarea>
          <?= acc_field_error($errors, 'message') ?>
        </div>
        <div class="form-actions">
          <span class="hint">Ctrl+Enter - отправить</span>
          <button class="btn btn-white btn-pill" type="submit"><?= icon('reply') ?> Ответить</button>
        </div>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <aside class="acc-conv-side">
    <section class="card">
      <h3 class="acc-card-title">Участники <span class="muted"><?= num(count($active)) ?></span></h3>
      <?php foreach ($users as $u): ?>
      <div class="acc-part<?= (int)$u['is_left'] ? ' is-left' : '' ?>">
        <a href="<?= e(acc_member_url($u['id'])) ?>"><?= avatar($u, 's') ?></a>
        <div class="acc-part-body">
          <?= user_link($u) ?>
          <span class="muted small"><?= (int)$u['id'] === (int)$conv['starter_id'] ? 'Создатель' : ((int)$u['is_left'] ? 'Покинул(а)' : e(acc_seen_text($u))) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if ($isStarter && !is_banned() && count($users) < ACC_CONV_MAX_USERS): ?>
      <form method="post" class="acc-inline-add" action="<?= e($convUrl) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite">
        <input class="input<?= acc_invalid($errors, 'invite') ?>" name="names" maxlength="200" placeholder="Ник участника" aria-label="Пригласить участника по нику" required>
        <button class="btn btn-white" type="submit" title="Пригласить"><?= icon('user-plus') ?></button>
      </form>
      <?= acc_field_error($errors, 'invite') ?>
      <?php endif; ?>
    </section>
    <section class="card">
      <form method="post" action="<?= e($convUrl) ?>" data-confirm="Покинуть переписку? Она исчезнет из вашего списка.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="leave">
        <button class="btn btn-danger btn-block" type="submit"><?= icon('logout') ?> Покинуть переписку</button>
      </form>
      <a class="btn btn-ghost btn-block mt-1" href="<?= e($listUrl) ?>"><?= icon('chevron-left') ?> Все переписки</a>
    </section>
  </aside>
</div>
<?php
    forum_footer();
    exit;
}

// ---------- Список переписок ----------

$total = (int)db_val('SELECT COUNT(*) FROM conversation_users WHERE user_id = :u AND is_left = 0', ['u' => $myId]);
$p = paginate($total, 20, query_int('page', 1));
$convs = db_all('SELECT c.*, cu.last_read_at, lu.username AS last_username, lu.avatar AS last_avatar, lg.color AS last_group_color,
        su.username AS starter_username, sg.color AS starter_group_color
    FROM conversation_users cu
    JOIN conversations c ON c.id = cu.conversation_id
    LEFT JOIN users lu ON lu.id = c.last_message_user_id LEFT JOIN user_groups lg ON lg.id = lu.group_id
    LEFT JOIN users su ON su.id = c.starter_id LEFT JOIN user_groups sg ON sg.id = su.group_id
    WHERE cu.user_id = :u AND cu.is_left = 0
    ORDER BY c.last_message_at DESC, c.id DESC LIMIT :lim OFFSET :off', ['u' => $myId, 'lim' => $p['per_page'], 'off' => $p['offset']]);
$people = [];
if ($convs) {
    $in = db_in(array_map(function ($c) {
        return (int)$c['id'];
    }, $convs), 'c');
    foreach (db_all('SELECT cu.conversation_id, u.id, u.username, u.avatar, g.color AS group_color
        FROM conversation_users cu JOIN users u ON u.id = cu.user_id JOIN user_groups g ON g.id = u.group_id
        WHERE cu.is_left = 0 AND cu.conversation_id IN (' . $in['sql'] . ') ORDER BY u.username', $in['params']) as $r) {
        $people[(int)$r['conversation_id']][] = $r;
    }
}

forum_header([
    'title' => 'Личные сообщения',
    'crumbs' => [['Личные сообщения', $listUrl]],
    'right' => false,
    'nav' => '',
    'css' => ['account.css'],
    'js' => ['account.js'],
]);
?>
<section class="block">
  <div class="block-head">
    <h2>Переписки <span class="muted small"><?= num($total) ?></span></h2>
    <?php if (!is_banned()): ?><a class="btn btn-white btn-pill" href="<?= e(url('/forum/conversations.php', ['new' => 1])) ?>"><?= icon('plus') ?> Новая переписка</a><?php endif; ?>
  </div>
  <?php if ($convs): ?>
  <div style="padding:0 2px 2px">
    <?php foreach ($convs as $c):
        $cid = (int)$c['id'];
        $unread = (int)$c['last_message_user_id'] !== $myId && (!$c['last_read_at'] || $c['last_read_at'] < $c['last_message_at']);
        $others = array_values(array_filter($people[$cid] ?? [], function ($u) use ($myId) {
            return (int)$u['id'] !== $myId;
        }));
        $shown = array_slice($others, 0, 3);
        $last = ['id' => $c['last_message_user_id'], 'username' => $c['last_username'], 'avatar' => $c['last_avatar'], 'group_color' => $c['last_group_color']];
        $href = url('/forum/conversations.php', ['id' => $cid]);
    ?>
    <div class="acc-conv-row<?= $unread ? ' unread' : '' ?>">
      <div class="acc-stack">
        <?php foreach ($shown as $u): ?><?= avatar($u, 's') ?><?php endforeach; ?>
        <?php if (!$shown): ?><?= avatar(user(), 's') ?><?php endif; ?>
        <?php if (count($others) > 3): ?><span class="acc-stack-more">+<?= count($others) - 3 ?></span><?php endif; ?>
      </div>
      <div class="acc-conv-main">
        <a class="acc-conv-title" href="<?= e($href) ?>"><?= $unread ? '<i class="acc-dot-new" title="Есть новые сообщения"></i>' : '' ?><span><?= e($c['title']) ?></span></a>
        <div class="acc-conv-meta"><?php
            $iStarted = (int)$c['starter_id'] === $myId;
            echo $iStarted ? 'Вы начали' : 'Начал(а) ' . user_link(['id' => $c['starter_id'], 'username' => $c['starter_username'], 'group_color' => $c['starter_group_color']]);
            echo ' · ' . e(fday($c['created_at']));
            $rest = array_values(array_filter($others, function ($u) use ($c) {
                return (int)$u['id'] !== (int)$c['starter_id'];
            }));
            if ($rest) {
                echo ' · ' . ($iStarted ? 'с ' : 'и ') . implode(', ', array_map('user_link', array_slice($rest, 0, 4))) . (count($rest) > 4 ? ' и ещё ' . (count($rest) - 4) : '');
            } elseif (!$others) {
                echo ' · других участников нет';
            }
        ?></div>
      </div>
      <div class="acc-conv-stats"><b><?= num($c['reply_count']) ?></b><?= plural($c['reply_count'], 'ответ', 'ответа', 'ответов') ?></div>
      <div class="acc-conv-last">
        <?= $c['last_username'] ? avatar($last, 's') : '' ?>
        <div>
          <a href="<?= e($href) ?>" class="muted"><?= e(fdate($c['last_message_at'])) ?></a>
          <?= $c['last_username'] ? user_link($last) : '' ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($p['pages'] > 1): ?><div class="block-foot"><?= pagination_html($p, '/forum/conversations.php') ?></div><?php endif; ?>
  <?php else: ?>
  <div class="empty"><?= icon('mail') ?><div>У вас пока нет личных переписок.<?php if (!is_banned()): ?> Начните первую - кнопка «Новая переписка» справа вверху или «Написать» в профиле пользователя.<?php endif; ?></div></div>
  <?php endif; ?>
</section>
<?php
forum_footer();
