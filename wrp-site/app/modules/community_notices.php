<?php
// Сообщество: объявления-плашки вверху страниц форума и сайта.
// Кому и где показывать, сроки, можно ли закрыть - задаёт администрация (/admin/notices.php).
// Закрытые плашки запоминаются: у пользователей - в базе, у гостей - в cookie wrp_nd.

function com_notice_styles()
{
    return [
        'info' => ['Информация', 'info'],
        'success' => ['Хорошая новость', 'check'],
        'warning' => ['Внимание', 'alert'],
        'danger' => ['Важно', 'ban'],
        'accent' => ['Фирменная', 'megaphone'],
    ];
}

function com_notice_audiences()
{
    return [
        'all' => 'Все',
        'guests' => 'Только гости',
        'users' => 'Пользователи (вошедшие)',
        'staff' => 'Команда проекта',
    ];
}

function com_notice_areas()
{
    return ['forum' => 'Форум', 'site' => 'Сайт', 'both' => 'Форум и сайт'];
}

// Кто смотрит: ['logged' => bool, 'staff' => bool, 'level' => int]
function com_viewer()
{
    return ['logged' => is_logged(), 'staff' => is_staff(), 'level' => user_level()];
}

// Показывать ли плашку этому посетителю в этой части сайта (без учёта «закрыта»)
function com_notice_visible(array $n, $area, array $viewer, $now = null)
{
    if (empty($n['is_active'])) {
        return false;
    }
    $now = $now === null ? time() : (int)$now;
    if (!empty($n['starts_at']) && strtotime($n['starts_at']) > $now) {
        return false;
    }
    if (!empty($n['ends_at']) && strtotime($n['ends_at']) < $now) {
        return false;
    }
    if ($n['area'] !== 'both' && $n['area'] !== $area) {
        return false;
    }
    $logged = !empty($viewer['logged']);
    $level = (int)($viewer['level'] ?? 0);
    switch ($n['audience']) {
        case 'guests':
            if ($logged) {
                return false;
            }
            break;
        case 'users':
            if (!$logged) {
                return false;
            }
            break;
        case 'staff':
            if (empty($viewer['staff'])) {
                return false;
            }
            break;
    }
    if ($n['min_level'] !== null && $n['min_level'] !== '' && $level < (int)$n['min_level']) {
        return false;
    }
    if ($n['max_level'] !== null && $n['max_level'] !== '' && $level > (int)$n['max_level']) {
        return false;
    }
    return true;
}

// Закрытые гостем плашки из cookie: [id => ревизия]
function com_notice_cookie()
{
    $out = [];
    $raw = $_COOKIE['wrp_nd'] ?? '';
    if (!is_string($raw) || strlen($raw) > 600) {
        return $out;
    }
    foreach (explode('.', $raw) as $pair) {
        if (preg_match('~^(\d{1,9})-(\d{1,9})$~', $pair, $m)) {
            $out[(int)$m[1]] = (int)$m[2];
        }
    }
    return $out;
}

function com_notice_cookie_set(array $map)
{
    $map = array_slice($map, -30, null, true);
    $parts = [];
    foreach ($map as $id => $rev) {
        $parts[] = (int)$id . '-' . (int)$rev;
    }
    cookie_set('wrp_nd', implode('.', $parts), time() + 86400 * 365);
}

// Активные плашки (кэш на запрос)
function com_notices_active()
{
    static $list = null;
    if ($list === null) {
        $list = [];
        if (com_ready()) {
            try {
                $list = db_all('SELECT * FROM com_notices WHERE is_active = 1 ORDER BY display_order, id');
            } catch (Exception $e) {
                $list = [];
            }
        }
    }
    return $list;
}

// Плашки для посетителя с учётом закрытых
function com_notices_for($area)
{
    $viewer = com_viewer();
    $list = [];
    $dismissible = [];
    foreach (com_notices_active() as $n) {
        if (com_notice_visible($n, $area, $viewer)) {
            $list[] = $n;
            if (!empty($n['dismissible'])) {
                $dismissible[] = (int)$n['id'];
            }
        }
    }
    if (!$dismissible) {
        return $list;
    }
    $closed = [];
    if (is_logged()) {
        $in = db_in($dismissible, 'n');
        foreach (db_all('SELECT notice_id, revision FROM com_notice_dismiss WHERE user_id = :u AND notice_id IN (' . $in['sql'] . ')', array_merge(['u' => uid()], $in['params'])) as $r) {
            $closed[(int)$r['notice_id']] = (int)$r['revision'];
        }
    } else {
        $closed = com_notice_cookie();
    }
    $out = [];
    foreach ($list as $n) {
        if (!empty($n['dismissible']) && isset($closed[(int)$n['id']]) && $closed[(int)$n['id']] === (int)$n['revision']) {
            continue;
        }
        $out[] = $n;
    }
    return $out;
}

// HTML одной плашки. $preview - без кнопки закрытия (админка)
function com_notice_html(array $n, $preview = false)
{
    $styles = com_notice_styles();
    $style = isset($styles[$n['style']]) ? $n['style'] : 'info';
    $h = '<div class="com-notice com-notice-' . e($style) . '" data-notice="' . (int)($n['id'] ?? 0) . '" role="status">';
    $h .= '<span class="com-notice-icon">' . icon($styles[$style][1]) . '</span><div class="com-notice-body">';
    if (trim((string)$n['title']) !== '') {
        $h .= '<div class="com-notice-title">' . e($n['title']) . '</div>';
    }
    if (trim((string)$n['message']) !== '') {
        $h .= '<div class="com-notice-text bb">' . bbcode($n['message']) . '</div>';
    }
    $h .= '</div>';
    $href = com_link_href($n['link_url'] ?? '');
    if ($href !== '') {
        $ext = !str_starts_with((string)$n['link_url'], '/');
        $h .= '<a class="btn btn-sm com-notice-btn" href="' . e($href) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>'
            . e(trim((string)($n['link_text'] ?? '')) !== '' ? $n['link_text'] : 'Подробнее') . icon('arrow-right') . '</a>';
    }
    if (!empty($n['dismissible']) && !$preview) {
        $h .= '<form method="post" action="' . e(url('/forum/community.php')) . '" class="com-notice-close" data-notice-dismiss>' . csrf_field()
            . '<input type="hidden" name="do" value="notice_dismiss"><input type="hidden" name="id" value="' . (int)$n['id'] . '">'
            . '<input type="hidden" name="return" value="' . e(current_url()) . '">'
            . '<button type="submit" title="Скрыть" aria-label="Скрыть объявление">' . icon('x') . '</button></form>';
    } elseif (!empty($n['dismissible'])) {
        $h .= '<span class="com-notice-close"><button type="button" tabindex="-1" aria-hidden="true">' . icon('x') . '</button></span>';
    }
    return $h . '</div>';
}

// Закрыть плашку для текущего посетителя. true, если плашку можно закрывать.
function com_notice_dismiss($id)
{
    if (!com_ready()) {
        return false;
    }
    $n = db_one('SELECT id, revision, dismissible FROM com_notices WHERE id = :id', ['id' => (int)$id]);
    if (!$n || empty($n['dismissible'])) {
        return false;
    }
    if (is_logged()) {
        db_exec('INSERT INTO com_notice_dismiss (notice_id, user_id, revision, created_at) VALUES (:n, :u, :r, :c)
                 ON DUPLICATE KEY UPDATE revision = VALUES(revision), created_at = VALUES(created_at)',
            ['n' => (int)$n['id'], 'u' => uid(), 'r' => (int)$n['revision'], 'c' => now()]);
    } else {
        $map = com_notice_cookie();
        unset($map[(int)$n['id']]);
        $map[(int)$n['id']] = (int)$n['revision'];
        com_notice_cookie_set($map);
    }
    return true;
}

hook_add('page_notices', function ($area, $o) {
    if (!com_ready()) {
        return '';
    }
    try {
        $list = com_notices_for($area === 'site' ? 'site' : 'forum');
    } catch (Exception $e) {
        return '';
    }
    if (!$list) {
        return '';
    }
    $h = '<div class="com-notices ' . ($area === 'site' ? 'com-notices-site site-container' : 'com-notices-forum') . '">';
    foreach ($list as $n) {
        $h .= com_notice_html($n);
    }
    return $h . '</div>';
});
