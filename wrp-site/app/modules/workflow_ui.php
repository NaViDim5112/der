<?php
// Модуль «Рассмотрение»: вывод на страницах форума. Логика и права - app/modules/workflow.php.
// Всё, что встраивается в чужие страницы, сначала проверяет wf_ready() (обновление базы применено).

// ---------- Иконки ----------

hook_add('icon_paths', function ($p) {
    $p['wf-inbox'] = '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>';
    $p['wf-claim'] = '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>';
    $p['wf-gavel'] = '<path d="m14.5 12.5-8 8a2.12 2.12 0 1 1-3-3l8-8"/><path d="m16 16 6-6"/><path d="m8 8 6-6"/><path d="m9 7 8 8"/><path d="m21 11-8-8"/>';
    $p['wf-dislike'] = '<path d="M17 14V2"/><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88z"/>';
    $p['wf-chart'] = '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>';
    return $p;
});

// ---------- Стили и скрипт ----------

hook_add('page_head', function ($area, $o) {
    if ($area !== 'forum' || !wf_ready() || in_array('workflow.css', $o['css'] ?? [], true)) {
        return '';
    }
    return '<link rel="stylesheet" href="' . e(asset('css/workflow.css')) . '">';
});

hook_add('page_scripts', function ($area, $o) {
    if ($area !== 'forum' || !wf_ready() || in_array('workflow.js', $o['js'] ?? [], true)) {
        return '';
    }
    return '<script src="' . e(asset('js/workflow.js')) . '"></script>';
});

// ---------- Мелочи вывода ----------

// «12:30» сегодня, «завтра в 12:30», «5 окт в 12:30»
function wf_when($dt)
{
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) {
        return 'сегодня в ' . date('H:i', $ts);
    }
    if ($day === date('Y-m-d', strtotime('+1 day'))) {
        return 'завтра в ' . date('H:i', $ts);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'вчера в ' . date('H:i', $ts);
    }
    $m = ru_months_short();
    return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . (date('Y', $ts) !== date('Y') ? ' ' . date('Y', $ts) : '') . ' в ' . date('H:i', $ts);
}

// «с 12:30» (сегодня) или «с 5 окт»
function wf_since($dt)
{
    $ts = strtotime((string)$dt);
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return 'с ' . date('H:i', $ts);
    }
    $m = ru_months_short();
    return 'с ' . (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)];
}

// Ход рассмотрения темы с кэшем на запрос (кнопки у заголовка и блок над сообщениями)
function wf_state($threadId)
{
    static $cache = [];
    $k = wf_ckey((int)$threadId);
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = wf_thread_state($threadId);
    }
    return $cache[$k];
}

// Плашка срока: открытая тема - «Ответ до ...», решённая - «Рассмотрено за ...»
function wf_deadline_chip(array $thread, $c, $st)
{
    if ($c && wf_is_open($thread, $c)) {
        $dl = wf_deadline($thread, $c);
        if ($dl['state'] === 'overdue') {
            return '<span class="wf-dl wf-dl-overdue" title="Срок ответа: ' . e(wf_when($dl['ts'])) . '">' . icon('alert') . '<span>Просрочено на <b>' . e(wf_dur($dl['left'])) . '</b></span></span>';
        }
        if ($dl['state'] !== 'none') {
            return '<span class="wf-dl wf-dl-' . $dl['state'] . '">' . icon('clock') . '<span>Ответ до <b>' . e(wf_when($dl['ts'])) . '</b> · осталось ' . e(wf_dur($dl['left'])) . '</span></span>';
        }
        return '';
    }
    if ($st && $st['verdict_at']) {
        $sec = strtotime($st['verdict_at']) - strtotime($thread['created_at']);
        return '<span class="wf-dl wf-dl-done">' . icon('check') . '<span>Рассмотрено за <b>' . e(wf_dur($sec)) . '</b></span></span>';
    }
    return '';
}

// Скрытые поля формы действия с темой
function wf_form_open($action, $threadId, $class = 'inline-form', $extra = '')
{
    return '<form method="post" action="' . e(url('/forum/workflow.php')) . '" class="' . e($class) . '"' . $extra . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="thread_id" value="' . (int)$threadId . '">';
}

// Подстановки для готовых ответов (JS заменяет их при вставке)
function wf_placeholder_attrs(array $thread)
{
    static $authors = [];
    $aid = (int)$thread['user_id'];
    if (!array_key_exists($aid, $authors)) {
        $authors[$aid] = user_by_id($aid);
    }
    $author = $authors[$aid];
    $me = user();
    return ' data-wf-ph data-author="' . e($author ? $author['username'] : 'автор') . '" data-staff="' . e($me ? $me['username'] : '') . '"'
        . ' data-thread="' . e($thread['title']) . '" data-date="' . e(date('d.m.Y')) . '"';
}

// <option> готовых ответов с данными для JS
function wf_macro_options(array $macros, $c)
{
    $h = '';
    foreach ($macros as $m) {
        $final = $m['prefix_id'] && wf_is_final($c, $m['prefix_id']) ? '1' : '0';
        $h .= '<option value="' . (int)$m['id'] . '" data-body="' . e($m['body']) . '" data-prefix="' . (int)$m['prefix_id'] . '"'
            . ' data-lock="' . (int)$m['do_lock'] . '" data-archive="' . (int)$m['do_archive'] . '" data-final="' . $final . '">' . e($m['title']) . '</option>';
    }
    return $h;
}

// ---------- Тема: кнопка у заголовка ----------

hook_add('thread_actions', function ($thread, $node) {
    if (!wf_ready() || $thread['is_deleted']) {
        return '';
    }
    $c = wf_cfg($node['id']);
    if (!$c || !wf_can_process($node) || !wf_is_open($thread, $c) || wf_recused($thread)) {
        return '';
    }
    $st = wf_state($thread['id']);
    if (!$st || !$st['claimed_by']) {
        return wf_form_open('claim', $thread['id'])
            . '<button class="btn btn-accent btn-pill wf-btn" type="submit">' . icon('wf-claim') . ' Взять на рассмотрение</button></form>';
    }
    if ((int)$st['claimed_by'] === uid()) {
        return '<a class="btn btn-black btn-pill" href="#wf-verdict" data-wf-open="wf-verdict">' . icon('wf-gavel') . ' Вынести решение</a>';
    }
    return '';
});

// ---------- Тема: блок «Ход рассмотрения» над сообщениями ----------

hook_add('thread_before_posts', function ($thread, $node, $pg) {
    if (!wf_ready()) {
        return '';
    }
    try {
        return wf_status_box($thread, $node);
    } catch (Exception $e) {
        error_log('[workflow] ' . $e);
        return '';
    }
});

function wf_status_box(array $thread, array $node)
{
    $tid = (int)$thread['id'];
    $c = wf_cfg($node['id']);
    $st = wf_state($tid);
    if (!$c && !($st && $st['verdict_at'])) {
        return '';
    }
    $open = $c ? wf_is_open($thread, $c) : false;
    $dl = $c ? wf_deadline($thread, $c) : ['state' => 'none', 'ts' => 0, 'left' => 0];
    $author = user_by_id($thread['user_id']);
    $me = uid();
    $claimed = $st && $st['claimed_by'];
    $mine = $claimed && (int)$st['claimed_by'] === $me;
    $ev = setting_int('wf_evidence_prefix_id');
    $evWait = $ev && (int)$thread['prefix_id'] === $ev && !$thread['is_deleted'] && !$thread['is_locked'];

    $cls = $open ? ($dl['state'] === 'overdue' ? 'is-overdue' : 'is-open') : 'is-done';
    $h = '<section class="wf-box ' . $cls . '" id="wf-box">';
    $h .= '<div class="wf-box-head"><div class="wf-box-title">' . icon('wf-claim') . '<span>Ход рассмотрения' . ($c ? ' ' . e(wf_kind($c, 2)) : '') . '</span></div>'
        . wf_deadline_chip($thread, $c, $st) . '</div>';

    // Три шага: подана - рассмотрение - решение
    $h .= '<ol class="wf-steps">';
    $h .= '<li class="wf-step is-done"><span class="wf-step-dot">' . icon('check') . '</span><div class="wf-step-body"><span class="wf-step-label">Подана</span>'
        . '<span class="wf-step-val">' . ($author ? user_link($author) : 'Гость') . ' <span class="muted">· ' . e(wf_when($thread['created_at'])) . '</span></span></div></li>';

    if ($claimed) {
        $cu = ['id' => (int)$st['claimed_by'], 'username' => $st['claimed_name'], 'avatar' => $st['claimed_avatar'], 'group_color' => $st['claimed_color']];
        $h .= '<li class="wf-step ' . ($open ? 'is-current' : 'is-done') . '"><span class="wf-step-dot">' . ($open ? icon('eye') : icon('check')) . '</span><div class="wf-step-body"><span class="wf-step-label">' . ($open ? 'Рассматривает' : 'Рассматривал(а)') . '</span>'
            . '<span class="wf-step-val">' . avatar($cu, 'xs') . ' ' . user_link($cu) . ' <span class="muted">· ' . e(wf_since($st['claimed_at'])) . '</span></span></div></li>';
    } elseif ($open) {
        $h .= '<li class="wf-step is-current is-waiting"><span class="wf-step-dot">' . icon('clock') . '</span><div class="wf-step-body"><span class="wf-step-label">Рассмотрение</span>'
            . '<span class="wf-step-val muted">Ждёт свободного сотрудника</span></div></li>';
    } else {
        $h .= '<li class="wf-step is-done"><span class="wf-step-dot">' . icon('check') . '</span><div class="wf-step-body"><span class="wf-step-label">Рассмотрение</span>'
            . '<span class="wf-step-val muted">Завершено</span></div></li>';
    }

    if (!$open) {
        $pfx = $st && $st['verdict_prefix_id'] ? (int)$st['verdict_prefix_id'] : (int)$thread['prefix_id'];
        $val = $pfx ? prefix_html($pfx) : '<span class="muted">Тема закрыта</span>';
        if ($st && $st['verdict_at']) {
            $vu = ['id' => (int)$st['verdict_by'], 'username' => $st['verdict_name'], 'group_color' => $st['verdict_color']];
            $val .= ' ' . user_link($vu) . ' <span class="muted">· ' . e(wf_when($st['verdict_at'])) . '</span>';
            if ($st['verdict_post_id']) {
                $val .= ' <a class="wf-step-link" href="' . e(post_url($st['verdict_post_id'])) . '">к ответу</a>';
            }
        }
        $h .= '<li class="wf-step is-done is-final"><span class="wf-step-dot">' . icon('wf-gavel') . '</span><div class="wf-step-body"><span class="wf-step-label">Решение</span>'
            . '<span class="wf-step-val">' . $val . '</span></div></li>';
    } else {
        $val = $dl['state'] !== 'none' ? 'Ожидается до ' . e(wf_when($dl['ts'])) : 'Ожидается';
        $h .= '<li class="wf-step"><span class="wf-step-dot">' . icon('wf-gavel') . '</span><div class="wf-step-body"><span class="wf-step-label">Решение</span>'
            . '<span class="wf-step-val muted">' . $val . '</span></div></li>';
    }
    $h .= '</ol>';

    // Ожидание доказательств
    if ($evWait) {
        $hours = max(1, setting_int('wf_evidence_hours'));
        $since = $st && $st['evidence_at'] ? strtotime($st['evidence_at']) : strtotime($thread['last_post_at']);
        $until = $since + $hours * 3600;
        $isAuthor = $me && (int)$thread['user_id'] === $me;
        $h .= '<div class="wf-evidence">' . icon('alert') . '<div>'
            . ($isAuthor ? '<b>Нужны доказательства.</b> Ответьте в этой теме и приложите видео или скриншоты до ' : '<b>Ожидаем доказательства от автора</b> до ')
            . e(wf_when($until)) . ($until > time() ? ' (осталось ' . e(wf_dur($until - time())) . ')' : '')
            . '. Если ответа не будет, тема закроется автоматически.</div></div>';
    }

    // Инструменты сотрудника
    if ($c && wf_can_process($node) && !$thread['is_deleted']) {
        $h .= wf_recused($thread)
            ? '<div class="wf-tools wf-tools-note muted small">' . icon('info') . ' ' . e(wf_recused_text()) . '</div>'
            : wf_tools_html($thread, $node, $c, $st, $open, $mine);
    }
    return $h . '</section>';
}

function wf_tools_html(array $thread, array $node, $c, $st, $open, $mine)
{
    $tid = (int)$thread['id'];
    $claimed = $st && $st['claimed_by'];
    $super = wf_is_supervisor($node);
    $canVerdict = wf_can_verdict($node, $st);
    // «Взять на рассмотрение» - кнопка у заголовка темы (thread_actions)
    $tools = '';
    if ($canVerdict) {
        $tools .= '<button class="btn btn-sm btn-accent" type="button" data-wf-toggle="wf-verdict" aria-controls="wf-verdict">' . icon('wf-gavel') . ' ' . ($open ? 'Вынести решение' : 'Изменить решение') . '</button>';
    }
    if ($open && (!$claimed || $mine || $super)) {
        $cands = wf_candidates($node);
        unset($cands[(int)($st['claimed_by'] ?? 0)]);
        foreach ($cands as $k => $cu) {
            if (wf_recused($thread, $cu)) {
                unset($cands[$k]);
            }
        }
        if ($cands) {
            $opts = '';
            foreach ($cands as $u) {
                $opts .= '<option value="' . (int)$u['id'] . '">' . e($u['username']) . ' (' . e($u['group_name']) . ')</option>';
            }
            $tools .= '<div class="dropdown wf-transfer"><button class="btn btn-sm btn-ghost" type="button" data-dropdown>' . icon('send') . ' Передать ' . icon('chevron-down') . '</button>'
                . '<div class="dropdown-menu wf-transfer-menu">' . wf_form_open('transfer', $tid, 'form')
                . '<label class="label" for="wf-to-' . $tid . '">Кому передать тему</label><select class="select" id="wf-to-' . $tid . '" name="to">' . $opts . '</select>'
                . '<button class="btn btn-sm btn-accent" type="submit">' . icon('send') . ' Передать</button></form></div></div>';
        }
    }
    if ($claimed && $open && ($mine || $super)) {
        $tools .= wf_form_open('unclaim', $tid, 'inline-form', $mine ? '' : ' data-confirm="Снять тему с ' . e($st['claimed_name']) . '?"')
            . '<button class="btn btn-sm btn-ghost" type="submit">' . icon('x') . ' ' . ($mine ? 'Отказаться' : 'Снять с рассмотрения') . '</button></form>';
    }
    if ($tools === '') {
        return $st && $claimed && $open ? '<div class="wf-tools wf-tools-note muted small">' . icon('info') . ' Тему рассматривает другой сотрудник.</div>' : '';
    }
    $h = '<div class="wf-tools">' . $tools . '</div>';
    if ($canVerdict) {
        $h .= wf_verdict_form($thread, $node, $c, $open);
    }
    return $h;
}

function wf_verdict_form(array $thread, array $node, $c, $open)
{
    $tid = (int)$thread['id'];
    $prefixes = node_prefixes($node['id']);
    $macros = wf_macros_for($node['id']);
    $archive = wf_archive_node($c, $node);
    $draft = $_SESSION['wf_draft'][$tid] ?? null;
    $openNow = $draft !== null;
    if ($draft !== null) {
        unset($_SESSION['wf_draft'][$tid]);
    }
    $draft = is_array($draft) ? $draft : [];
    $selPrefix = (int)($draft['prefix_id'] ?? 0);

    $h = '<div class="wf-verdict" id="wf-verdict"' . ($openNow ? '' : ' hidden') . '>';
    $h .= wf_form_open('verdict', $tid, 'form wf-verdict-form', wf_placeholder_attrs($thread) . ' data-wf-verdict');
    $h .= '<div class="form-row"><span class="label">Результат</span><div class="wf-pfx-list" role="radiogroup" aria-label="Результат">';
    $h .= '<label class="wf-pfx"><input type="radio" name="prefix_id" value="0" data-final="0"' . ($selPrefix === 0 ? ' checked' : '') . '><span class="wf-pfx-keep">Не менять</span></label>';
    foreach ($prefixes as $pid => $p) {
        $final = wf_is_final($c, $pid);
        $h .= '<label class="wf-pfx' . ($final ? ' is-final' : '') . '" title="' . ($final ? 'Окончательное решение: срок ответа останавливается' : 'Промежуточный статус') . '">'
            . '<input type="radio" name="prefix_id" value="' . (int)$pid . '" data-final="' . ($final ? '1' : '0') . '"' . ($selPrefix === (int)$pid ? ' checked' : '') . '>' . prefix_html($pid) . '</label>';
    }
    $h .= '</div></div>';
    if ($macros) {
        $h .= '<div class="form-row"><label class="label" for="wf-macro-' . $tid . '">Готовый ответ</label>'
            . '<select class="select" id="wf-macro-' . $tid . '" name="macro_id" data-wf-macro><option value="0">Свой текст</option>' . wf_macro_options($macros, $c) . '</select></div>';
    }
    $h .= '<div class="form-row"><label class="label" for="wf-body-' . $tid . '">Ответ автору</label>'
        . '<textarea class="textarea" id="wf-body-' . $tid . '" name="body" rows="7" maxlength="50000" data-editor placeholder="Напишите решение или выберите готовый ответ. Можно использовать {author}, {staff}, {thread}, {date}.">' . e($draft['body'] ?? '') . '</textarea></div>';
    $h .= '<div class="wf-verdict-opts">'
        . '<label class="check"><input type="checkbox" name="lock" value="1"' . (!empty($draft['lock']) ? ' checked' : '') . '><span>' . icon('lock') . ' Закрыть тему</span></label>';
    if ($archive) {
        $h .= '<label class="check"><input type="checkbox" name="archive" value="1"' . (!empty($draft['archive']) ? ' checked' : '') . '><span>' . icon('folder') . ' Перенести в «' . e($archive['title']) . '»</span></label>';
    } else {
        $h .= '<span class="check is-disabled" title="Архив для раздела не выбран (админ-панель: Рассмотрение)">' . icon('folder') . ' Архив не настроен</span>';
    }
    $h .= '</div>';
    $h .= '<div class="wf-verdict-foot"><span class="hint">Ответ уйдёт от вашего имени, автор получит оповещение.</span>'
        . '<button class="btn btn-ghost" type="button" data-wf-toggle="wf-verdict">Отмена</button>'
        . '<button class="btn btn-accent" type="submit">' . icon('wf-gavel') . ' ' . ($open ? 'Вынести решение' : 'Изменить решение') . '</button></div>';
    $h .= '</form></div>';
    return $h;
}

// ---------- Тема: готовые ответы в форме ответа ----------

hook_add('thread_reply_form', function ($thread, $node) {
    if (!wf_ready() || !wf_can_process($node)) {
        return '';
    }
    $c = wf_cfg($node['id']);
    $macros = wf_macros_for($node['id']);
    if (!$macros) {
        return '';
    }
    return '<div class="wf-qr-macros"' . wf_placeholder_attrs($thread) . '>' . icon('quote')
        . '<select class="select" data-wf-insert aria-label="Вставить готовый ответ"><option value="">Вставить готовый ответ...</option>' . wf_macro_options($macros, $c) . '</select></div>';
});

// ---------- Предложения: «За / Против» под первым сообщением ----------

hook_add('post_footer_after', function ($p, $ctx) {
    if (empty($ctx['is_first']) || !empty($ctx['inner']) || !wf_ready()) {
        return '';
    }
    $c = wf_cfg($ctx['node']['id']);
    if (!$c || !(int)$c['voting']) {
        return '';
    }
    return wf_vote_widget($ctx['thread'], $ctx['node']);
});

function wf_vote_widget(array $thread, array $node)
{
    $tid = (int)$thread['id'];
    $up = (int)($thread['wf_up'] ?? 0);
    $down = (int)($thread['wf_down'] ?? 0);
    $total = $up + $down;
    $my = is_logged() ? (int)db_val('SELECT vote FROM wf_votes WHERE thread_id = :t AND user_id = :u', ['t' => $tid, 'u' => uid()]) : 0;
    $why = '';
    if (!is_logged()) {
        $why = '<a href="' . e(url('/forum/login.php', ['return' => current_url()])) . '">Войдите</a>, чтобы проголосовать.';
    } elseif ($thread['is_locked'] || $thread['is_deleted']) {
        $why = 'Голосование закрыто.';
    } elseif ((int)$thread['user_id'] === uid()) {
        $why = 'За своё предложение голосовать нельзя.';
    } elseif (is_banned() || !node_can_reply($node)) {
        $why = 'Вы не можете голосовать в этом разделе.';
    }
    $dis = $why !== '' ? ' disabled' : '';
    $pct = $total ? (int)round($up * 100 / $total) : 50;
    $h = wf_form_open('vote', $tid, 'wf-vote' . ($total ? '' : ' is-empty'), ' data-wf-vote');
    $h .= '<div class="wf-vote-head"><span class="wf-vote-title">' . icon('star') . ' Поддерживаете предложение?</span>'
        . '<span class="wf-vote-total muted small" data-wf-total>' . ($total ? 'Голосов: ' . num($total) : 'Пока никто не голосовал') . '</span></div>';
    $h .= '<div class="wf-vote-row">'
        . '<button class="wf-vote-btn wf-vote-up' . ($my === 1 ? ' is-on' : '') . '" type="submit" name="vote" value="1"' . $dis . ' aria-pressed="' . ($my === 1 ? 'true' : 'false') . '">' . icon('like') . '<span>За</span><b data-wf-up>' . num($up) . '</b></button>'
        . '<div class="wf-vote-meter" aria-hidden="true"><span data-wf-meter style="width:' . $pct . '%"></span></div>'
        . '<button class="wf-vote-btn wf-vote-down' . ($my === -1 ? ' is-on' : '') . '" type="submit" name="vote" value="-1"' . $dis . ' aria-pressed="' . ($my === -1 ? 'true' : 'false') . '">' . icon('wf-dislike') . '<span>Против</span><b data-wf-down>' . num($down) . '</b></button>'
        . '</div>';
    if ($why !== '') {
        $h .= '<div class="wf-vote-note muted small">' . $why . '</div>';
    }
    return $h . '</form>';
}

// ---------- Списки тем: значки ----------

hook_add('thread_row_title_after', function ($t, $opts) {
    if (!isset($t['id'], $t['node_id']) || !wf_ready()) {
        return '';
    }
    $c = wf_cfg($t['node_id']);
    if (!$c) {
        return '';
    }
    $h = '';
    if ((int)$c['voting']) {
        $up = (int)($t['wf_up'] ?? 0);
        $down = (int)($t['wf_down'] ?? 0);
        if ($up || $down) {
            $h .= ' <span class="wf-chip wf-chip-votes" title="За: ' . $up . ', против: ' . $down . '"><b class="up">+' . $up . '</b><b class="down">-' . $down . '</b></span>';
        }
    }
    if (!wf_is_open($t, $c)) {
        return $h;
    }
    $claim = wf_list_claim($t);
    if ($claim) {
        $h .= ' <span class="wf-chip wf-chip-claim" title="Рассматривает ' . e($claim['username']) . ' ' . e(wf_since($claim['claimed_at'])) . '">' . icon('eye') . e($claim['username']) . '</span>';
    }
    $node = node_get($t['node_id']);
    if (setting('wf_list_deadlines') === '1' || wf_can_process($node)) {
        $dl = wf_deadline($t, $c);
        if ($dl['state'] === 'overdue') {
            $h .= ' <span class="wf-chip wf-chip-overdue" title="Срок ответа прошёл: ' . e(wf_when($dl['ts'])) . '">' . icon('alert') . 'Просрочено ' . e(wf_dur($dl['left'])) . '</span>';
        } elseif ($dl['state'] !== 'none') {
            $h .= ' <span class="wf-chip wf-chip-' . $dl['state'] . '" title="Ответ до ' . e(wf_when($dl['ts'])) . '">' . icon('clock') . e(wf_dur($dl['left'])) . '</span>';
        }
    }
    return $h;
});

// ---------- Раздел: ответственные ----------

hook_add('forum_before_list', function ($node) {
    if (!wf_ready()) {
        return '';
    }
    $c = wf_cfg($node['id']);
    if (!$c) {
        return '';
    }
    $staff = db_all('SELECT s.note, u.id, u.username, u.avatar, g.color AS group_color FROM wf_node_staff s
                     JOIN users u ON u.id = s.user_id JOIN user_groups g ON g.id = u.group_id
                     WHERE s.node_id = :n AND u.is_banned = 0 ORDER BY u.username', ['n' => (int)$node['id']]);
    $names = [];
    foreach ($staff as $s) {
        $names[] = '<span class="wf-resp-user"' . ($s['note'] ? ' title="' . e($s['note']) . '"' : '') . '>' . avatar($s, 'xs') . user_link($s) . '</span>';
    }
    $h = '<div class="wf-resp">' . icon('shield') . '<div class="wf-resp-text"><span class="wf-resp-label">Рассматривают:</span> <b>' . e(wf_level_label(wf_min_level($c))) . '</b>';
    if ($names) {
        $h .= '<span class="wf-resp-sep">·</span><span class="wf-resp-label">Ответственные:</span> ' . implode('', $names);
    }
    if ((int)$c['deadline_hours'] > 0) {
        $h .= '<span class="wf-resp-sep">·</span><span class="muted">Срок ответа: ' . e(wf_hours_label($c['deadline_hours'])) . '</span>';
    }
    $h .= '</div>';
    if (wf_can_process($node)) {
        $h .= '<a class="btn btn-sm btn-ghost wf-resp-queue" href="' . e(url('/forum/queue.php', ['node' => (int)$node['id']])) . '">' . icon('wf-inbox') . ' Очередь раздела</a>';
    }
    return $h . '</div>';
});

// ---------- Раздел: сортировка «По рейтингу» ----------

hook_add('forum_orders', function ($orders, $node) {
    if (!wf_ready()) {
        return $orders;
    }
    $c = wf_cfg($node['id']);
    if ($c && (int)$c['voting']) {
        $orders['rating'] = ['По рейтингу', '(t.wf_up - t.wf_down) DESC, t.wf_up DESC, t.last_post_at DESC'];
    }
    return $orders;
});

// ---------- Шапка и левое меню ----------

hook_add('header_icons', function ($u) {
    if (!wf_ready() || setting('wf_header_icon') === '0' || !wf_processable_node_ids()) {
        return '';
    }
    $n = wf_attention_count();
    return '<a class="btn btn-ghost btn-icon" href="' . e(url('/forum/queue.php')) . '" title="Очередь рассмотрения' . ($n ? ': ждут внимания ' . $n : '') . '">'
        . icon('wf-inbox') . ($n ? '<span class="count-badge">' . ($n > 99 ? '99+' : $n) . '</span>' : '') . '</a>';
});

hook_add('sidebar_user_links', function ($nav, $u) {
    if (!wf_ready() || !wf_processable_node_ids()) {
        return '';
    }
    $n = wf_attention_count();
    return '<a class="side-link" href="' . e(url('/forum/queue.php')) . '">' . icon('wf-inbox') . '<span>Очередь рассмотрения</span>'
        . ($n ? '<span class="wf-side-count">' . ($n > 99 ? '99+' : $n) . '</span>' : '') . '</a>';
});
