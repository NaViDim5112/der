<?php
// Вывод списка разделов (главная форума, категория, подразделы в разделе)

function render_node_list(array $nodes)
{
    $rows = [];
    $threadIds = [];
    foreach ($nodes as $n) {
        $can = node_can_view($n);
        $agg = $n['type'] === 'link' ? ['threads' => 0, 'posts' => 0, 'last' => null] : node_aggregate($n['id']);
        if ($can && $agg['last']) {
            $threadIds[] = (int)$agg['last']['last_thread_id'];
        }
        $rows[] = ['node' => $n, 'can' => $can, 'agg' => $agg];
    }
    $info = last_threads_info($threadIds);
    $h = '';
    foreach ($rows as $r) {
        $h .= render_node_row($r['node'], $r['can'], $r['agg'], $info);
    }
    return $h;
}

function render_node_row($n, $can, $agg, $info)
{
    $isLink = $n['type'] === 'link';
    $unread = !$isLink && $can && node_has_unread($n['id']);
    $href = node_url($n);
    $subs = [];
    if (!$isLink) {
        foreach (node_visible_children($n['id']) as $c) {
            if ($c['type'] !== 'category') {
                $subs[] = $c;
            }
        }
    }
    $cls = 'node-row' . ($unread ? ' unread' : '') . ($isLink ? ' link-node' : '');
    $target = $isLink && preg_match('~^https?://~i', (string)$n['link_url']) ? ' target="_blank" rel="noopener"' : '';

    $h = '<div class="' . $cls . '" id="node-' . (int)$n['id'] . '">';
    $h .= '<div class="node-main">';
    $h .= '<a class="node-icon" href="' . e($href) . '"' . $target . '>' . icon($can ? $n['icon'] : 'lock') . '</a>';
    $h .= '<div class="node-text">';
    $h .= '<a class="node-title" href="' . e($href) . '"' . $target . ($n['description'] && $subs ? ' title="' . e($n['description']) . '"' : '') . '>' . e($n['title']) . '</a>';
    if ($unread) {
        $h .= ' <span class="badge-new">Новое</span>';
    }
    if ($subs) {
        $h .= '<div><button class="node-subs-toggle" type="button" data-subs-toggle>Подфорумы' . icon('chevron-down') . '</button></div>';
        $h .= '<div class="node-subs">';
        foreach ($subs as $s) {
            $su = $s['type'] !== 'link' && node_can_view($s) && node_has_unread($s['id']);
            $h .= '<a class="' . ($su ? 'unread' : '') . '" href="' . e(node_url($s)) . '">' . e($s['title']) . '</a>';
        }
        $h .= '</div>';
    } elseif ($n['description']) {
        $h .= '<div class="node-desc">' . e($n['description']) . '</div>';
    }
    $h .= '</div></div>';

    $h .= '<div class="node-stats" title="Тем: ' . num($agg['threads']) . ', сообщений: ' . num($agg['posts']) . '">';
    if (!$isLink) {
        $h .= icon('chats') . '<span>' . e(num_short($agg['posts'])) . '</span>';
    }
    $h .= '</div>';

    $h .= '<div class="node-last">';
    if ($isLink) {
        $h .= '<span class="node-last-empty">' . icon('external') . ' Ссылка</span>';
    } elseif (!$can) {
        $h .= '<span class="node-last-private">Приватный</span>';
    } elseif ($agg['last'] && isset($info[(int)$agg['last']['last_thread_id']])) {
        $t = $info[(int)$agg['last']['last_thread_id']];
        $user = $t['user_id'] ? ['id' => $t['user_id'], 'username' => $t['username'], 'avatar' => $t['avatar'], 'group_color' => $t['group_color']] : null;
        $h .= $user ? avatar($user, 's') : '';
        $h .= '<div class="node-last-text">';
        $h .= '<a class="node-last-title" href="' . e(post_url($t['last_post_id'])) . '" title="' . e($t['title']) . '">' . prefix_html($t['prefix_id']) . e($t['title']) . '</a>';
        $h .= '<span class="node-last-meta">' . e(fdate($t['last_post_at'])) . ' · ' . user_link($user) . '</span>';
        $h .= '</div>';
    } else {
        $h .= '<span class="node-last-empty">Нет</span>';
    }
    $h .= '</div>';

    return $h . '</div>';
}

// Блок категории с её разделами
function render_category($cat)
{
    $children = node_visible_children($cat['id']);
    if (!$children) {
        return '';
    }
    $h = '<section class="node-cat" id="cat-' . (int)$cat['id'] . '">';
    $h .= '<h2 class="node-cat-title"><a href="' . e(node_url($cat)) . '">' . e($cat['title']) . '</a></h2>';
    if ($cat['description']) {
        $h .= '<div class="node-cat-desc">' . e($cat['description']) . '</div>';
    }
    $h .= render_node_list($children);
    return $h . '</section>';
}
