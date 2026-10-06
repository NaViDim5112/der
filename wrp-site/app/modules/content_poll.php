<?php
// Модуль «Контент»: опросы в темах.
// Опрос создаётся вместе с темой (блок «Опрос» в форме), показывается над сообщениями темы.
// Голосовать могут вошедшие пользователи, которые видят раздел. Голос можно изменить, пока опрос открыт.
// Автор темы и модераторы закрывают опрос и меняют варианты (только пока нет ни одного голоса), удаляют - модераторы.

const CNT_POLL_MIN_OPTIONS = 2;
const CNT_POLL_MAX_OPTIONS = 10;

// Можно ли создать опрос в разделе (настройка poll_nodes: auto - все разделы без анкет, иначе список id)
function cnt_poll_allowed($node)
{
    if (!$node || $node['type'] !== 'forum' || !cnt_ready()) {
        return false;
    }
    $mode = trim((string)setting('poll_nodes', 'auto'));
    if ($mode === '' || $mode === 'auto') {
        return trim((string)$node['form_json']) === '';
    }
    $ids = array_map('intval', explode(',', $mode));
    return in_array((int)$node['id'], $ids, true);
}

// Данные опроса из формы (массив poll[...]). ['empty' => true], если ничего не заполнено.
function cnt_poll_input($raw)
{
    $raw = is_array($raw) ? $raw : [];
    $q = isset($raw['question']) && is_string($raw['question']) ? trim(preg_replace('~\s+~u', ' ', $raw['question'])) : '';
    $opts = [];
    foreach ((isset($raw['options']) && is_array($raw['options']) ? $raw['options'] : []) as $o) {
        if (!is_string($o)) {
            continue;
        }
        $o = trim(preg_replace('~\s+~u', ' ', $o));
        if ($o !== '') {
            $opts[] = $o;
        }
        if (count($opts) > 30) {
            break;
        }
    }
    $days = isset($raw['close_days']) && is_numeric($raw['close_days']) ? (int)$raw['close_days'] : 0;
    return [
        'sent' => !empty($raw['sent']),
        'empty' => $q === '' && !$opts,
        'question' => $q,
        'options' => $opts,
        'multiple' => !empty($raw['multiple']),
        'public' => !empty($raw['sent']) ? !empty($raw['public']) : true,
        'close_days' => $days,
    ];
}

// Проверка опроса: '' или текст ошибки
function cnt_poll_error(array $d)
{
    $len = mb_strlen($d['question']);
    if ($len < 3) {
        return 'Опрос: напишите вопрос (минимум 3 символа).';
    }
    if ($len > 200) {
        return 'Опрос: вопрос слишком длинный (максимум 200 символов).';
    }
    if (count($d['options']) < CNT_POLL_MIN_OPTIONS) {
        return 'Опрос: нужно минимум ' . CNT_POLL_MIN_OPTIONS . ' варианта ответа.';
    }
    if (count($d['options']) > CNT_POLL_MAX_OPTIONS) {
        return 'Опрос: не больше ' . CNT_POLL_MAX_OPTIONS . ' вариантов ответа.';
    }
    $seen = [];
    foreach ($d['options'] as $o) {
        if (mb_strlen($o) > 150) {
            return 'Опрос: вариант «' . str_limit($o, 30) . '» слишком длинный (максимум 150 символов).';
        }
        $k = mb_strtolower($o);
        if (isset($seen[$k])) {
            return 'Опрос: вариант «' . str_limit($o, 30) . '» повторяется.';
        }
        $seen[$k] = true;
    }
    if ($d['close_days'] < 0 || $d['close_days'] > 365) {
        return 'Опрос: закрыть можно через 0-365 дней (0 - не закрывать).';
    }
    return '';
}

// Опрос, проверенный при создании темы в этом запросе (его сохранит thread_created)
function cnt_poll_pending($set = false, $value = null)
{
    static $pending = null;
    if ($set) {
        $pending = $value;
    }
    return $pending;
}

// Поля опроса (создание темы и правка опроса). $d - из cnt_poll_input или из базы.
function cnt_poll_fields_html(array $d, $error = '')
{
    $opts = $d['options'];
    $show = max(4, min(CNT_POLL_MAX_OPTIONS, count($opts) + 1));
    $h = '<div class="cnt-poll-fields">';
    if ($error !== '') {
        $h .= '<div class="flash flash-error">' . icon('alert') . '<div>' . e($error) . '</div></div>';
    }
    $h .= '<input type="hidden" name="poll[sent]" value="1">';
    $h .= '<div class="form-row"><label class="label">Вопрос</label>'
        . '<input class="input" name="poll[question]" value="' . e($d['question']) . '" maxlength="200" placeholder="Например: Какой район благоустроить следующим?"></div>';
    $h .= '<div class="form-row"><label class="label">Варианты ответа</label><div class="cnt-poll-opt-inputs" data-poll-opts data-max="' . CNT_POLL_MAX_OPTIONS . '">';
    for ($i = 0; $i < $show; $i++) {
        $h .= '<input class="input" name="poll[options][]" value="' . e($opts[$i] ?? '') . '" maxlength="150" placeholder="Вариант ' . ($i + 1) . '">';
    }
    $h .= '</div><div class="cnt-poll-opt-tools"><button type="button" class="btn btn-ghost btn-sm" data-poll-add' . ($show >= CNT_POLL_MAX_OPTIONS ? ' hidden' : '') . '>' . icon('plus') . ' Добавить вариант</button>'
        . '<span class="hint">От ' . CNT_POLL_MIN_OPTIONS . ' до ' . CNT_POLL_MAX_OPTIONS . ' вариантов, пустые не учитываются.</span></div></div>';
    $h .= '<div class="cnt-poll-settings">'
        . '<label class="check"><input type="checkbox" name="poll[multiple]" value="1"' . (!empty($d['multiple']) ? ' checked' : '') . '><span>Можно выбрать несколько вариантов</span></label>'
        . '<label class="check"><input type="checkbox" name="poll[public]" value="1"' . (!empty($d['public']) ? ' checked' : '') . '><span>Показывать результаты до голосования</span></label>'
        . '<label class="cnt-poll-days"><span>Закрыть через</span><input class="input" type="number" name="poll[close_days]" min="0" max="365" value="' . (int)$d['close_days'] . '"><span>дн.</span><span class="hint">0 - не закрывать</span></label>'
        . '</div>';
    return $h . '</div>';
}

// ---------- Создание вместе с темой ----------

hook_add('new_thread_form', function ($node, $useForm, $errors) {
    if (!cnt_poll_allowed($node)) {
        return '';
    }
    $d = cnt_poll_pending();
    if (!$d) {
        $d = cnt_poll_input(is_post() ? ($_POST['poll'] ?? []) : []);
    }
    $error = $errors['poll'] ?? '';
    $open = !$d['empty'] || $error !== '';
    return '<details class="cnt-poll-compose"' . ($open ? ' open' : '') . '>'
        . '<summary>' . icon('poll') . '<b>Добавить опрос</b><span class="muted small">необязательно</span>' . icon('chevron-down', 'cnt-caret') . '</summary>'
        . cnt_poll_fields_html($d, $error) . '</details>';
});

hook_add('new_thread_validate', function ($errors, $node, $useForm) {
    cnt_poll_pending(true, null);
    if (!isset($_POST['poll']) || !cnt_poll_allowed($node)) {
        return $errors;
    }
    $d = cnt_poll_input($_POST['poll']);
    if ($d['empty']) {
        return $errors;
    }
    $err = cnt_poll_error($d);
    if ($err !== '') {
        $errors['poll'] = $err;
    }
    cnt_poll_pending(true, $d);
    return $errors;
});

hook_add('thread_created', function ($threadId, $node, $postId) {
    $d = cnt_poll_pending();
    if (!$d || $d['empty'] || cnt_poll_error($d) !== '' || !cnt_poll_allowed($node)) {
        return;
    }
    cnt_poll_pending(true, null);
    cnt_poll_create($threadId, uid(), $d);
});

// Сохранить опрос. Возвращает id.
function cnt_poll_create($threadId, $userId, array $d)
{
    $pid = db_insert('polls', [
        'thread_id' => (int)$threadId,
        'user_id' => (int)$userId,
        'question' => $d['question'],
        'is_multiple' => $d['multiple'] ? 1 : 0,
        'public_results' => $d['public'] ? 1 : 0,
        'is_closed' => 0,
        'close_at' => $d['close_days'] > 0 ? date('Y-m-d H:i:s', time() + $d['close_days'] * 86400) : null,
        'voters' => 0,
        'created_at' => now(),
    ]);
    cnt_poll_save_options($pid, $d['options']);
    cnt_poll_thread_ids(true);
    return $pid;
}

function cnt_poll_save_options($pollId, array $options)
{
    db_exec('DELETE FROM poll_options WHERE poll_id = :p', ['p' => (int)$pollId]);
    foreach (array_values($options) as $i => $o) {
        db_insert('poll_options', ['poll_id' => (int)$pollId, 'title' => $o, 'votes' => 0, 'display_order' => $i + 1]);
    }
}

// ---------- Чтение ----------

function cnt_poll_by_thread($threadId)
{
    if (!cnt_ready()) {
        return null;
    }
    return db_one('SELECT * FROM polls WHERE thread_id = :t', ['t' => (int)$threadId]);
}

function cnt_poll_get($id)
{
    return db_one('SELECT * FROM polls WHERE id = :id', ['id' => (int)$id]);
}

function cnt_poll_options($pollId)
{
    return db_all('SELECT * FROM poll_options WHERE poll_id = :p ORDER BY display_order, id', ['p' => (int)$pollId]);
}

// id вариантов, за которые голосовал пользователь
function cnt_poll_my_votes($pollId, $userId)
{
    if (!$userId) {
        return [];
    }
    return array_map('intval', array_column(db_all('SELECT option_id FROM poll_votes WHERE poll_id = :p AND user_id = :u', ['p' => (int)$pollId, 'u' => (int)$userId]), 'option_id'));
}

// Темы с опросами (для значка в списках): один запрос на страницу. $reset - сбросить кэш.
function cnt_poll_thread_ids($reset = false)
{
    static $ids = null;
    if ($reset) {
        $ids = null;
        return [];
    }
    if ($ids === null) {
        $ids = [];
        if (cnt_ready()) {
            try {
                foreach (db_all('SELECT thread_id FROM polls') as $r) {
                    $ids[(int)$r['thread_id']] = true;
                }
            } catch (Exception $e) {
                $ids = [];
            }
        }
    }
    return $ids;
}

// Опрос открыт для голосования
function cnt_poll_is_open(array $poll, array $thread)
{
    if ($poll['is_closed']) {
        return false;
    }
    if ($poll['close_at'] && strtotime($poll['close_at']) <= time()) {
        return false;
    }
    return !$thread['is_locked'] && !$thread['is_deleted'];
}

// Автор темы или модератор
function cnt_poll_can_manage(array $poll, array $thread)
{
    if (!is_logged() || is_banned()) {
        return false;
    }
    return can_moderate() || (int)$thread['user_id'] === uid();
}

// Почему нельзя голосовать: '' - можно, 'guest' - нужно войти
function cnt_poll_vote_denied(array $poll, array $thread, $node)
{
    if (!is_logged()) {
        return 'guest';
    }
    if (is_banned()) {
        return 'Ваш аккаунт заблокирован.';
    }
    if (!$node || !node_can_view($node)) {
        return 'Нет доступа к разделу.';
    }
    if (!cnt_poll_is_open($poll, $thread)) {
        return 'Опрос закрыт.';
    }
    return '';
}

// ---------- Голосование ----------

// Голос (или смена голоса). $optionIds - выбранные варианты. '' или текст ошибки.
function cnt_poll_vote(array $poll, array $optionIds, $userId)
{
    $valid = array_map('intval', array_column(cnt_poll_options($poll['id']), 'id'));
    $chosen = [];
    foreach ($optionIds as $o) {
        $o = (int)$o;
        if (!in_array($o, $valid, true)) {
            return 'Такого варианта нет в опросе.';
        }
        $chosen[$o] = true;
    }
    $chosen = array_keys($chosen);
    if (!$chosen) {
        return 'Выберите вариант ответа.';
    }
    if (!$poll['is_multiple'] && count($chosen) > 1) {
        return 'В этом опросе можно выбрать только один вариант.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec('DELETE FROM poll_votes WHERE poll_id = :p AND user_id = :u', ['p' => (int)$poll['id'], 'u' => (int)$userId]);
        foreach ($chosen as $o) {
            db_insert('poll_votes', ['poll_id' => (int)$poll['id'], 'option_id' => $o, 'user_id' => (int)$userId, 'created_at' => now()]);
        }
        cnt_poll_recount($poll['id']);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return '';
}

// Пересчёт голосов по вариантам и числа проголосовавших
function cnt_poll_recount($pollId)
{
    $pollId = (int)$pollId;
    db_exec('UPDATE poll_options o SET o.votes = (SELECT COUNT(*) FROM poll_votes v WHERE v.option_id = o.id AND v.poll_id = :a) WHERE o.poll_id = :b',
        ['a' => $pollId, 'b' => $pollId]);
    db_exec('UPDATE polls SET voters = (SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = :a) WHERE id = :b', ['a' => $pollId, 'b' => $pollId]);
}

// ---------- Вывод ----------

hook_add('thread_before_posts', function ($thread, $node, $pg) {
    try {
        $poll = cnt_poll_by_thread($thread['id']);
    } catch (Exception $e) {
        return '';
    }
    return $poll ? cnt_poll_html($poll, $thread, $node) : '';
});

hook_add('thread_row_title_after', function ($t, $opts) {
    $ids = cnt_poll_thread_ids();
    if (!isset($ids[(int)$t['id']])) {
        return '';
    }
    return ' <span class="tag cnt-poll-tag" title="В теме есть опрос">' . icon('poll') . 'Опрос</span>';
});

// Блок опроса над сообщениями темы. $flash - сообщение после действия (AJAX).
function cnt_poll_html(array $poll, array $thread, $node, $flash = '')
{
    $pid = (int)$poll['id'];
    $tid = (int)$thread['id'];
    $options = cnt_poll_options($pid);
    $mine = cnt_poll_my_votes($pid, uid());
    $voted = (bool)$mine;
    $open = cnt_poll_is_open($poll, $thread);
    $denied = cnt_poll_vote_denied($poll, $thread, $node);
    $canVote = $denied === '';
    $manage = cnt_poll_can_manage($poll, $thread);
    $isMod = can_moderate();
    $voters = (int)$poll['voters'];
    $seeResults = !$open || $voted || $poll['public_results'] || $isMod;
    $action = url('/forum/poll.php');
    $multi = (bool)$poll['is_multiple'];

    // Что показывать сразу: форму голосования или результаты
    $view = ($canVote && !$voted) ? 'vote' : ($seeResults ? 'results' : 'vote');

    $h = '<section class="card cnt-poll" id="poll" data-poll="' . $pid . '">';
    $h .= '<header class="cnt-poll-head"><span class="cnt-poll-ico">' . icon('poll') . '</span><div class="cnt-poll-titles">'
        . '<div class="cnt-poll-label">Опрос</div><h2 class="cnt-poll-q">' . e($poll['question']) . '</h2></div>';

    if ($manage) {
        $form = function ($do, $label, $ico, $confirm = '') use ($action, $pid) {
            return '<form method="post" action="' . e($action) . '" data-poll-ajax' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
                . '<input type="hidden" name="action" value="' . e($do) . '"><input type="hidden" name="poll_id" value="' . $pid . '">'
                . '<button type="submit">' . icon($ico) . ' ' . e($label) . '</button></form>';
        };
        $h .= '<div class="dropdown cnt-poll-manage"><button class="btn btn-ghost btn-sm" type="button" data-dropdown title="Управление опросом">' . icon('settings') . '<span class="hide-sm">Управление</span></button>'
            . '<div class="dropdown-menu dropdown-right">';
        if ($voters === 0) {
            $h .= '<button type="button" data-poll-view="edit">' . icon('edit') . ' Изменить опрос</button>';
        }
        if ($poll['is_closed']) {
            $h .= $form('open', 'Открыть опрос', 'unlock');
        } elseif (!$thread['is_locked']) {
            $h .= $form('close', 'Закрыть опрос', 'lock');
        }
        if ($isMod) {
            $h .= '<div class="dropdown-sep"></div>' . $form('delete', 'Удалить опрос', 'trash', 'Удалить опрос вместе со всеми голосами?');
        }
        $h .= '</div></div>';
    }
    $h .= '</header>';

    // Плашки под вопросом
    $badges = [];
    if ($multi) {
        $badges[] = '<span class="cnt-poll-badge">' . icon('check-all') . 'Несколько вариантов</span>';
    }
    if (!$open) {
        $why = $poll['is_closed'] || ($poll['close_at'] && strtotime($poll['close_at']) <= time()) ? 'Опрос закрыт' : 'Тема закрыта, голосование остановлено';
        $badges[] = '<span class="cnt-poll-badge is-closed">' . icon('lock') . e($why) . '</span>';
    } elseif ($poll['close_at']) {
        $badges[] = '<span class="cnt-poll-badge">' . icon('clock') . 'Закроется ' . e(fdate($poll['close_at'])) . '</span>';
    }
    if (!$poll['public_results'] && $open) {
        $badges[] = '<span class="cnt-poll-badge">' . icon('eye-off') . 'Результаты после голосования</span>';
    }
    if ($badges) {
        $h .= '<div class="cnt-poll-badges">' . implode('', $badges) . '</div>';
    }
    if ($flash !== '') {
        $h .= '<div class="cnt-poll-flash">' . icon('check') . e($flash) . '</div>';
    }

    // Голосование
    $h .= '<form method="post" action="' . e($action) . '" class="cnt-poll-vote" data-poll-ajax data-poll-pane="vote"' . ($view === 'vote' ? '' : ' hidden') . '>'
        . csrf_field() . '<input type="hidden" name="action" value="vote"><input type="hidden" name="poll_id" value="' . $pid . '">';
    $h .= '<div class="cnt-poll-opts">';
    foreach ($options as $o) {
        $oid = (int)$o['id'];
        $h .= '<label class="cnt-poll-opt"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="options[]" value="' . $oid . '"'
            . (in_array($oid, $mine, true) ? ' checked' : '') . (!$canVote ? ' disabled' : '') . '><span>' . e($o['title']) . '</span></label>';
    }
    $h .= '</div><div class="cnt-poll-actions">';
    if ($canVote) {
        $h .= '<button class="btn btn-accent btn-pill" type="submit">' . icon('check') . ' ' . ($voted ? 'Изменить голос' : 'Проголосовать') . '</button>';
    } elseif ($denied === 'guest') {
        $h .= '<a class="btn btn-white btn-pill" href="' . e(url('/forum/login.php', ['return' => thread_url($tid)])) . '">' . icon('login') . ' Войдите, чтобы проголосовать</a>';
    } else {
        $h .= '<span class="muted small">' . e($denied) . '</span>';
    }
    if ($seeResults) {
        $h .= '<button class="btn btn-ghost btn-pill" type="button" data-poll-view="results">' . icon('poll') . ' Результаты</button>';
    } else {
        $h .= '<span class="muted small">Результаты будут видны после голосования.</span>';
    }
    $h .= '</div></form>';

    // Результаты
    if ($seeResults) {
        $max = 0;
        foreach ($options as $o) {
            $max = max($max, (int)$o['votes']);
        }
        $h .= '<div class="cnt-poll-results" data-poll-pane="results"' . ($view === 'results' ? '' : ' hidden') . '>';
        foreach ($options as $o) {
            $n = (int)$o['votes'];
            $pct = $voters > 0 ? (int)round($n * 100 / $voters) : 0;
            $isMine = in_array((int)$o['id'], $mine, true);
            $h .= '<div class="cnt-poll-row' . ($isMine ? ' is-mine' : '') . ($n > 0 && $n === $max ? ' is-top' : '') . '">'
                . '<div class="cnt-poll-row-head"><span class="cnt-poll-row-title">' . ($isMine ? icon('check') : '') . e($o['title']) . '</span>'
                . '<span class="cnt-poll-row-num"><b>' . $pct . '%</b> <span class="muted">(' . num($n) . ')</span></span></div>'
                . '<div class="cnt-poll-bar"><span style="width:' . $pct . '%"></span></div></div>';
        }
        $h .= '<div class="cnt-poll-foot"><span class="muted">Проголосовало: <b>' . num($voters) . '</b></span>';
        if ($canVote) {
            $h .= '<button class="btn btn-ghost btn-sm" type="button" data-poll-view="vote">' . icon('edit') . ' ' . ($voted ? 'Изменить голос' : 'Голосовать') . '</button>';
        } elseif ($denied === 'guest' && $open) {
            $h .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/forum/login.php', ['return' => thread_url($tid)])) . '">' . icon('login') . ' Войдите, чтобы проголосовать</a>';
        }
        $h .= '</div></div>';
    }

    // Правка (пока нет голосов)
    if ($manage && $voters === 0) {
        $d = [
            'question' => $poll['question'],
            'options' => array_column($options, 'title'),
            'multiple' => (bool)$poll['is_multiple'],
            'public' => (bool)$poll['public_results'],
            'close_days' => $poll['close_at'] ? max(0, (int)ceil((strtotime($poll['close_at']) - time()) / 86400)) : 0,
        ];
        $h .= '<form method="post" action="' . e($action) . '" class="cnt-poll-edit" data-poll-ajax data-poll-pane="edit" hidden>' . csrf_field()
            . '<input type="hidden" name="action" value="edit"><input type="hidden" name="poll_id" value="' . $pid . '">'
            . cnt_poll_fields_html($d)
            . '<div class="cnt-poll-actions"><button class="btn btn-accent btn-pill" type="submit">' . icon('check') . ' Сохранить опрос</button>'
            . '<button class="btn btn-ghost btn-pill" type="button" data-poll-view="' . $view . '">Отмена</button></div></form>';
    }
    return $h . '</section>';
}
