<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Демо-данные для профилей: сообщения на стенах, подписки, одна личная переписка.
// Только между демо-аккаунтами из tests/dev_seed.php. Повторный запуск ничего не дублирует.
//   php tests/b_demo_seed.php
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$id = function ($name) {
    return (int)db_val('SELECT id FROM users WHERE username = :n', ['n' => $name]);
};
$ago = function ($sec) {
    return date('Y-m-d H:i:s', time() - $sec);
};
$admin = $id('admin');
$kuzya = $id('Kuzya_Kabanov');
$roxas = $id('Roxas_Alexandro');
$diego = $id('Diego_Bacardi');
$martin = $id('Martin_Line');
if (!$admin || !$kuzya || !$roxas || !$diego || !$martin) {
    exit("Нет демо-аккаунтов, сначала php tests/dev_seed.php\n");
}
if (db_val('SELECT COUNT(*) FROM profile_posts')) {
    exit("Сообщения профилей уже есть, пропускаю\n");
}

$post = function ($owner, $author, $body, $sec) {
    return db_insert('profile_posts', ['profile_user_id' => $owner, 'user_id' => $author, 'body' => $body, 'created_at' => date('Y-m-d H:i:s', time() - $sec)]);
};
$comment = function ($pp, $author, $body, $sec) {
    return db_insert('profile_comments', ['profile_post_id' => $pp, 'user_id' => $author, 'body' => $body, 'created_at' => date('Y-m-d H:i:s', time() - $sec)]);
};
$like = function ($type, $cid, $user, $sec) {
    db_exec('INSERT IGNORE INTO profile_likes (content_type, content_id, user_id, reaction, created_at) VALUES (:t, :c, :u, :r, :d)',
        ['t' => $type, 'c' => $cid, 'u' => $user, 'r' => 'like', 'd' => date('Y-m-d H:i:s', time() - $sec)]);
    $table = $type === 'post' ? 'profile_posts' : 'profile_comments';
    db_exec('UPDATE ' . $table . ' SET likes_count = (SELECT COUNT(*) FROM profile_likes WHERE content_type = :t AND content_id = :c) WHERE id = :id', ['t' => $type, 'c' => $cid, 'id' => $cid]);
};

$p1 = $post($roxas, $kuzya, 'Привет! Спасибо, что вчера помог разобраться с правилами, выручил.', 3 * 3600);
$c1 = $comment($p1, $roxas, 'Обращайся! Увидимся на сервере.', 2 * 3600);
$like('post', $p1, $roxas, 2 * 3600);
$like('comment', $c1, $kuzya, 3600);
$like('post', $p1, $martin, 1800);

$p2 = $post($roxas, $roxas, 'Сегодня вечером на сервере, если нужна помощь - пишите в ЛС.', 5400);
db_update('users', ['status_text' => 'Сегодня вечером на сервере, если нужна помощь - пишите в ЛС.'], 'id = :id', ['id' => $roxas]);
$like('post', $p2, $kuzya, 5000);

$p3 = $post($martin, $diego, 'Хорошая работа в техническом разделе, так держать!', 26 * 3600);
$comment($p3, $martin, 'Спасибо, стараюсь!', 25 * 3600);
$like('post', $p3, $martin, 25 * 3600);

$p4 = $post($admin, $admin, '[b]Добро пожаловать на форум World Role Play![/b] Вопросы по сайту - в технический раздел.', 2 * 86400);
$like('post', $p4, $roxas, 86400);
$like('post', $p4, $kuzya, 80000);
$like('post', $p4, $diego, 70000);
$like('post', $p4, $martin, 60000);

foreach ([[$roxas, $kuzya], [$kuzya, $roxas], [$roxas, $admin], [$martin, $admin], [$diego, $admin], [$kuzya, $admin]] as $f) {
    db_exec('INSERT IGNORE INTO user_follows (user_id, follow_user_id, created_at) VALUES (:u, :f, :c)', ['u' => $f[0], 'f' => $f[1], 'c' => $ago(mt_rand(3600, 86400 * 20))]);
}

// Переписка администратора с игроком (прочитана обоими)
$t1 = $ago(7200);
$t2 = $ago(6600);
$cid = db_insert('conversations', ['title' => 'Вопрос по жалобе', 'starter_id' => $admin, 'reply_count' => 1, 'last_message_at' => $t2, 'last_message_user_id' => $roxas, 'created_at' => $t1]);
$m1 = db_insert('conversation_messages', ['conversation_id' => $cid, 'user_id' => $admin, 'body' => 'Здравствуйте! Ваша жалоба рассмотрена, подробности - в теме жалобы.', 'created_at' => $t1]);
$m2 = db_insert('conversation_messages', ['conversation_id' => $cid, 'user_id' => $roxas, 'body' => 'Спасибо за быстрый ответ!', 'created_at' => $t2]);
db_update('conversations', ['last_message_id' => $m2], 'id = :id', ['id' => $cid]);
foreach ([$admin, $roxas] as $u) {
    db_insert('conversation_users', ['conversation_id' => $cid, 'user_id' => $u, 'last_read_at' => $t2, 'is_left' => 0]);
}
echo "Готово: 4 сообщения на стенах, подписки, 1 переписка\n";
