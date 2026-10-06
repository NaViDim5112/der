<?php
// Подсказка ников для упоминаний (@ник в редакторе): до 8 пользователей, JSON. Только для вошедших.
require __DIR__ . '/../../app/bootstrap.php';

header('Cache-Control: private, no-store');
if (!is_logged()) {
    cnt_json_fail('Войдите, чтобы упоминать пользователей.', 401);
}
$q = ltrim(query_str('q'), '@');
$q = mb_substr(preg_replace('~[\x00-\x1F\x7F]~u', '', $q), 0, 24);
if ($q === '') {
    json_out(['ok' => true, 'users' => []]);
}
if (!rate_ok('user_suggest', 120, 60)) {
    cnt_json_fail('Слишком часто. Подождите немного.', 429);
}
rate_hit('user_suggest');

$esc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
$sql = 'SELECT u.id, u.username, u.avatar, g.color AS group_color FROM users u LEFT JOIN user_groups g ON g.id = u.group_id
        WHERE u.username LIKE :q ESCAPE \'!\'';
// Сначала ники, которые начинаются с введённого, потом содержащие его
$rows = db_all($sql . ' ORDER BY u.username = :exact DESC, u.last_activity DESC LIMIT 8', ['q' => $esc . '%', 'exact' => $q]);
if (count($rows) < 8 && mb_strlen($q) >= 2) {
    $have = array_map('intval', array_column($rows, 'id'));
    $more = db_all($sql . ' ORDER BY u.last_activity DESC LIMIT 16', ['q' => '%' . $esc . '%']);
    foreach ($more as $r) {
        if (count($rows) >= 8) {
            break;
        }
        if (!in_array((int)$r['id'], $have, true)) {
            $rows[] = $r;
        }
    }
}
$users = [];
foreach ($rows as $r) {
    $users[] = [
        'id' => (int)$r['id'],
        'name' => (string)$r['username'],
        'color' => $r['group_color'] ? (string)$r['group_color'] : null,
        'avatar' => avatar($r, 'xs'),
    ];
}
json_out(['ok' => true, 'users' => $users]);
