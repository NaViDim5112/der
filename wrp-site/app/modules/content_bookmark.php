<?php
// Модуль «Контент»: закладки на сообщения и кнопка мультицитаты.
// Закладка - значок в шапке сообщения (AJAX, без JS - обычная форма), список - /forum/bookmarks.php.
// Мультицитата: «+ Цитата» под сообщением собирает сообщения в браузере (localStorage по теме),
// в форме ответа появляется кнопка «Вставить цитаты (N)». Сами цитаты берутся из /forum/post.php?action=quote.

const CNT_BOOKMARKS_MAX = 1000;

// id сообщений в закладках текущего пользователя: один запрос на страницу. $reset - сбросить кэш.
function cnt_bm_ids($reset = false)
{
    static $ids = null;
    if ($reset) {
        $ids = null;
        return [];
    }
    if ($ids === null) {
        $ids = [];
        if (is_logged() && cnt_ready()) {
            try {
                foreach (db_all('SELECT post_id FROM bookmarks WHERE user_id = :u', ['u' => uid()]) as $r) {
                    $ids[(int)$r['post_id']] = true;
                }
            } catch (Exception $e) {
                $ids = [];
            }
        }
    }
    return $ids;
}

// Кнопка закладки: внутренняя часть (иконка и подсказка)
function cnt_bm_button($postId, $on)
{
    return '<button type="submit" class="post-head-btn cnt-bm-btn' . ($on ? ' is-on' : '') . '" data-bm="' . (int)$postId . '" aria-pressed="' . ($on ? 'true' : 'false') . '" title="' . ($on ? 'Убрать из закладок' : 'Добавить в закладки') . '">' . icon('bookmark') . '</button>';
}

// Добавить или убрать закладку. Возвращает [ok, включена ли, текст ошибки].
function cnt_bm_toggle($postId, $userId, $on = null)
{
    $postId = (int)$postId;
    $exists = (bool)db_val('SELECT 1 FROM bookmarks WHERE user_id = :u AND post_id = :p', ['u' => (int)$userId, 'p' => $postId]);
    $want = $on === null ? !$exists : (bool)$on;
    if ($want && !$exists) {
        $count = (int)db_val('SELECT COUNT(*) FROM bookmarks WHERE user_id = :u', ['u' => (int)$userId]);
        if ($count >= CNT_BOOKMARKS_MAX) {
            return [false, false, 'Закладок слишком много (максимум ' . CNT_BOOKMARKS_MAX . '). Удалите ненужные.'];
        }
        db_exec('INSERT IGNORE INTO bookmarks (user_id, post_id, note, created_at) VALUES (:u, :p, NULL, :c)', ['u' => (int)$userId, 'p' => $postId, 'c' => now()]);
    } elseif (!$want && $exists) {
        db_exec('DELETE FROM bookmarks WHERE user_id = :u AND post_id = :p', ['u' => (int)$userId, 'p' => $postId]);
    }
    cnt_bm_ids(true);
    return [true, $want, ''];
}

hook_add('post_head_right', function ($p, $ctx) {
    if (!is_logged() || !cnt_ready()) {
        return '';
    }
    $pid = (int)$p['id'];
    $on = isset(cnt_bm_ids()[$pid]);
    return '<form method="post" action="' . e(url('/forum/bookmarks.php')) . '" class="inline-form cnt-bm-form">' . csrf_field()
        . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="post_id" value="' . $pid . '">'
        . cnt_bm_button($pid, $on) . '</form>';
});

// «+ Цитата» (мультицитата)
hook_add('post_actions_right', function ($p, $ctx) {
    if (!is_logged() || !empty($p['is_deleted']) || empty($ctx['can_quote'])) {
        return '';
    }
    return '<button type="button" class="post-act cnt-mq-btn" data-mq="' . (int)$p['id'] . '" data-mq-thread="' . (int)$ctx['thread']['id'] . '" title="Добавить в мультицитату">'
        . icon('quote-plus') . '<span>Цитата</span></button>';
});

// Кнопка «Вставить цитаты (N)» в форме ответа (видна, когда что-то выбрано)
hook_add('thread_reply_form', function ($thread, $node) {
    return '<div class="cnt-mq-bar" data-mq-bar data-mq-thread="' . (int)$thread['id'] . '" hidden>'
        . '<button type="button" class="btn btn-black btn-pill btn-sm" data-mq-insert>' . icon('quote') . '<span>Вставить цитаты (<span data-mq-count>0</span>)</span></button>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-mq-clear>' . icon('x') . ' Сбросить выбор</button></div>';
});
