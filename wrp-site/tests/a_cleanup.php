<?php
// Удаляет тестовые темы tests/a_flow.js (по id из аргументов или все «Жалоба на Tester_...») и всё связанное с ними.
// Запуск: php tests/a_cleanup.php [id ...]
if (PHP_SAPI !== 'cli') {
    exit;
}
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';
$ids = array_map('intval', array_slice($argv, 1));
if (!$ids) {
    $ids = array_map('intval', array_column(db_all("SELECT id FROM threads WHERE title LIKE 'Жалоба на Tester!_%' ESCAPE '!'"), 'id'));
}
$users = [];
$nodes = [];
foreach ($ids as $tid) {
    $th = thread_get($tid);
    if (!$th) continue;
    $nodes[$th['node_id']] = 1;
    $pids = array_map('intval', array_column(db_all('SELECT id, user_id FROM posts WHERE thread_id = :t', ['t' => $tid]), 'id'));
    foreach (db_all('SELECT DISTINCT user_id FROM posts WHERE thread_id = :t', ['t' => $tid]) as $r) $users[$r['user_id']] = 1;
    if ($pids) {
        $in = db_in($pids, 'p');
        foreach (db_all('SELECT DISTINCT p.user_id FROM post_likes l JOIN posts p ON p.id = l.post_id WHERE l.post_id IN (' . $in['sql'] . ')', $in['params']) as $r) $users[$r['user_id']] = 1;
        db_exec('DELETE FROM post_likes WHERE post_id IN (' . $in['sql'] . ')', $in['params']);
        db_exec("DELETE FROM mod_log WHERE target_type = 'post' AND target_id IN (" . $in['sql'] . ')', $in['params']);
    }
    db_exec('DELETE FROM posts WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM alerts WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM thread_watch WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM thread_reads WHERE thread_id = :t', ['t' => $tid]);
    db_exec("DELETE FROM mod_log WHERE target_type = 'thread' AND target_id = :t", ['t' => $tid]);
    db_exec('DELETE FROM threads WHERE id = :t', ['t' => $tid]);
    echo "deleted thread $tid\n";
}
foreach (db_all('SELECT id FROM nodes') as $n) node_rebuild($n['id']);
foreach (array_keys($users) as $u) user_rebuild_counts($u);
echo "ok\n";
