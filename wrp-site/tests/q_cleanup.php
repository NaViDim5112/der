<?php
// Удаляет данные tests/q_flow.js: пользователей QaUser*, темы/переписки/сообщения профиля с меткой QA-,
// поле анкеты qa_server в разделах, попытки входа с 127.0.0.1, оповещения начиная с указанного времени.
// Запуск: php tests/q_cleanup.php ["2026-10-05 23:30:00"]
if (PHP_SAPI !== 'cli') {
    exit;
}
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$users = array_map('intval', array_column(db_all("SELECT id FROM users WHERE username LIKE 'QaUser%'"), 'id'));
$uin = $users ? db_in($users, 'u') : null;

// Темы
$tids = array_map('intval', array_column(db_all("SELECT id FROM threads WHERE title LIKE '%QA-%'" . ($uin ? ' OR user_id IN (' . $uin['sql'] . ')' : ''), $uin ? $uin['params'] : []), 'id'));
$touched = [];
foreach ($tids as $tid) {
    $pids = array_map('intval', array_column(db_all('SELECT id FROM posts WHERE thread_id = :t', ['t' => $tid]), 'id'));
    foreach (db_all('SELECT DISTINCT user_id FROM posts WHERE thread_id = :t', ['t' => $tid]) as $r) {
        $touched[(int)$r['user_id']] = 1;
    }
    if ($pids) {
        $in = db_in($pids, 'p');
        foreach (db_all('SELECT DISTINCT p.user_id FROM post_likes l JOIN posts p ON p.id = l.post_id WHERE l.post_id IN (' . $in['sql'] . ')', $in['params']) as $r) {
            $touched[(int)$r['user_id']] = 1;
        }
        db_exec('DELETE FROM post_likes WHERE post_id IN (' . $in['sql'] . ')', $in['params']);
        db_exec("DELETE FROM mod_log WHERE target_type = 'post' AND target_id IN (" . $in['sql'] . ')', $in['params']);
    }
    db_exec('DELETE FROM posts WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM alerts WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM thread_watch WHERE thread_id = :t', ['t' => $tid]);
    db_exec('DELETE FROM thread_reads WHERE thread_id = :t', ['t' => $tid]);
    db_exec("DELETE FROM mod_log WHERE target_type = 'thread' AND target_id = :t", ['t' => $tid]);
    db_exec('DELETE FROM threads WHERE id = :t', ['t' => $tid]);
    echo "thread $tid\n";
}

// Переписки
foreach (db_all("SELECT id FROM conversations WHERE title LIKE '%QA-%'") as $c) {
    $cid = (int)$c['id'];
    db_exec('DELETE FROM conversation_messages WHERE conversation_id = :c', ['c' => $cid]);
    db_exec('DELETE FROM conversation_users WHERE conversation_id = :c', ['c' => $cid]);
    db_exec("DELETE FROM alerts WHERE type = 'conversation' AND extra = :c", ['c' => (string)$cid]);
    db_exec('DELETE FROM conversations WHERE id = :c', ['c' => $cid]);
    echo "conversation $cid\n";
}

// Сообщения в профилях
foreach (db_all("SELECT id FROM profile_posts WHERE body LIKE '%QA-%'") as $pp) {
    $id = (int)$pp['id'];
    $cids = array_map('intval', array_column(db_all('SELECT id FROM profile_comments WHERE profile_post_id = :p', ['p' => $id]), 'id'));
    if ($cids) {
        $in = db_in($cids, 'c');
        db_exec("DELETE FROM profile_likes WHERE content_type = 'comment' AND content_id IN (" . $in['sql'] . ')', $in['params']);
        db_exec("DELETE FROM alerts WHERE type IN ('profile_comment') AND post_id IN (" . $in['sql'] . ')', $in['params']);
    }
    db_exec('DELETE FROM profile_comments WHERE profile_post_id = :p', ['p' => $id]);
    db_exec("DELETE FROM profile_likes WHERE content_type <> 'comment' AND content_id = :p", ['p' => $id]);
    db_exec("DELETE FROM alerts WHERE type IN ('profile_post', 'profile_comment', 'profile_like') AND post_id = :p", ['p' => $id]);
    db_exec('DELETE FROM profile_posts WHERE id = :p', ['p' => $id]);
    echo "profile post $id\n";
}

// Комментарии на стенах
foreach (db_all("SELECT id, profile_post_id FROM profile_comments WHERE body LIKE '%QA-%'") as $c) {
    db_exec("DELETE FROM profile_likes WHERE content_type = 'comment' AND content_id = :c", ['c' => $c['id']]);
    db_exec('DELETE FROM profile_comments WHERE id = :c', ['c' => $c['id']]);
    echo "profile comment {$c['id']}\n";
}

// Оповещения, созданные тестами
if (!empty($argv[1]) && strtotime($argv[1])) {
    $n = db_exec('DELETE FROM alerts WHERE created_at >= :t', ['t' => date('Y-m-d H:i:s', strtotime($argv[1]))]);
    echo "alerts since {$argv[1]}\n";
}

// Пользователи
foreach ($users as $id) {
    foreach (['alerts' => 'user_id', 'remember_tokens' => 'user_id', 'online' => 'user_id', 'thread_reads' => 'user_id', 'node_reads' => 'user_id',
        'thread_watch' => 'user_id', 'user_follows' => 'user_id', 'user_ignores' => 'user_id', 'post_likes' => 'user_id', 'profile_likes' => 'user_id',
        'conversation_users' => 'user_id', 'mod_log' => 'user_id'] as $t => $col) {
        try {
            db_exec("DELETE FROM `$t` WHERE `$col` = :u", ['u' => $id]);
        } catch (Exception $ex) {
            echo "skip $t: " . $ex->getMessage() . "\n";
        }
    }
    db_exec('DELETE FROM alerts WHERE actor_id = :u', ['u' => $id]);
    db_exec('DELETE FROM user_follows WHERE follow_user_id = :u', ['u' => $id]);
    db_exec("DELETE FROM mod_log WHERE target_type = 'user' AND target_id = :u", ['u' => $id]);
    db_exec('DELETE FROM users WHERE id = :u', ['u' => $id]);
    echo "user $id\n";
}

// Поле анкеты, добавленное тестом
foreach (db_all('SELECT id, form_json FROM nodes WHERE form_json IS NOT NULL') as $n) {
    $f = json_decode((string)$n['form_json'], true);
    if (!is_array($f) || empty($f['fields'])) {
        continue;
    }
    $before = count($f['fields']);
    $f['fields'] = array_values(array_filter($f['fields'], function ($x) {
        return ($x['key'] ?? '') !== 'qa_server' && strpos((string)($x['label'] ?? ''), 'QA-') === false;
    }));
    if (count($f['fields']) !== $before) {
        db_exec('UPDATE nodes SET form_json = :j WHERE id = :id', ['j' => json_encode($f, JSON_UNESCAPED_UNICODE), 'id' => $n['id']]);
        echo "form of node {$n['id']}\n";
    }
}

db_exec("DELETE FROM rate_limits WHERE ip = '127.0.0.1'");
foreach (db_all('SELECT id FROM nodes') as $n) {
    node_rebuild($n['id']);
}
foreach (array_keys($touched) as $u) {
    if (!in_array($u, $users, true)) {
        user_rebuild_counts($u);
    }
}
echo "ok\n";
