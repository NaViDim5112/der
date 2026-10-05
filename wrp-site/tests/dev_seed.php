<?php
// Тестовые данные для проверки вида форума: php tests/dev_seed.php
// Создаёт админа admin / admin12345, несколько пользователей, тем и сообщений.
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$now = time();
$users = [
    ['admin', 'admin@wrp.local', 10, null],
    ['Kuzya_Kabanov', 'kuzya@wrp.local', 2, '4'],
    ['Roxas_Alexandro', 'roxas@wrp.local', 2, null],
    ['Ricardo_Calump', 'ricardo@wrp.local', 7, null],
    ['Diego_Bacardi', 'diego@wrp.local', 6, null],
    ['Martin_Line', 'martin@wrp.local', 5, null],
];
$ids = [];
foreach ($users as $i => $u) {
    $id = db_val('SELECT id FROM users WHERE username = :n', ['n' => $u[0]]);
    if (!$id) {
        $id = db_insert('users', [
            'username' => $u[0], 'email' => $u[1], 'password_hash' => password_hash($u[0] === 'admin' ? 'admin12345' : 'test12345', PASSWORD_DEFAULT),
            'group_id' => $u[2], 'secondary_groups' => $u[3], 'created_at' => date('Y-m-d H:i:s', $now - 86400 * (400 - $i * 50)),
            'last_activity' => date('Y-m-d H:i:s', $now - $i * 120),
            'custom_title' => $u[0] === 'Kuzya_Kabanov' ? 'Ex - Governor' : null,
            'signature' => $u[0] === 'Roxas_Alexandro' ? "[center][i]Я не исчезаю после поражения. Я возвращаюсь сильнее![/i][/center]" : null,
        ]);
    }
    $ids[$u[0]] = (int)$id;
}

function mkthread($node, $user, $title, $body, $prefix, $ago, $replies)
{
    $t = time() - $ago;
    $tid = db_insert('threads', ['node_id' => $node, 'user_id' => $user, 'title' => $title, 'prefix_id' => $prefix, 'last_post_at' => date('Y-m-d H:i:s', $t), 'created_at' => date('Y-m-d H:i:s', $t), 'views' => mt_rand(10, 900)]);
    db_insert('posts', ['thread_id' => $tid, 'user_id' => $user, 'body' => $body, 'created_at' => date('Y-m-d H:i:s', $t), 'ip' => '127.0.0.1']);
    $k = 0;
    foreach ($replies as $r) {
        $k++;
        db_insert('posts', ['thread_id' => $tid, 'user_id' => $r[0], 'body' => $r[1], 'created_at' => date('Y-m-d H:i:s', $t + $k * 600), 'ip' => '127.0.0.1']);
    }
    thread_rebuild($tid);
    return $tid;
}

if (!db_val('SELECT COUNT(*) FROM threads')) {
    mkthread(11, $ids['admin'], 'Обновление 1.2: новые работы и система семей', "[center][size=5][b]Обновление 1.2[/b][/size][/center]\n\n[list]\n[*]Добавлена работа дальнобойщика\n[*]Новая система семей\n[*]Исправлены вылеты при входе в интерьеры\n[/list]", 9, 86400 * 2, [[$ids['Roxas_Alexandro'], 'Наконец-то семьи!']]);
    mkthread(52, $ids['Roxas_Alexandro'], 'Жалоба на Martin_Line', "[b]1. Ваш игровой ник:[/b] Roxas_Alexandro\n[b]2. Игровой ник нарушителя:[/b] Martin_Line\n[b]3. Суть нарушения:[/b] DM в зелёной зоне\n[b]4. Дата и время нарушения (МСК):[/b] 05.10.2026 21:10\n[b]5. Доказательства:[/b] https://youtu.be/dQw4w9WgXcQ", 1, 3600, []);
    mkthread(53, $ids['Roxas_Alexandro'], 'Жалоба на администратора Ricardo_Calump | Причина: неадекват', "[b]Тип жалобы:[/b] Жалоба на администрацию\n\n[b]Ваш игровой ник:[/b] Roxas_Alexandro\n[b]Игровой ник администратора:[/b] Ricardo_Calump\n\n[b]Причина наказания:[/b] неадекват\n[b]Суть жалобы:[/b] Просто стоял, вижу прилетает 120 минут за \"неадекват\". За что - без понятия.", 1, 7200,
        [[$ids['Kuzya_Kabanov'], "[center]Доброго времени суток, я администратор, который выдал вам наказание.\nВели себя неподобающе, вследствие чего были применены санкции.\n\n[spoiler=Нарушение]Тайм-код 00:20 по 00:40.[/spoiler]\n\nОжидайте ответа старшей администрации.[/center]"]]);
    mkthread(55, $ids['Diego_Bacardi'], 'Обжалование наказания Diego_Bacardi', "[b]1. Ваш игровой ник:[/b] Diego_Bacardi\n[b]3. Наказание:[/b] мут 60 минут", 3, 86400 * 3, []);
    mkthread(21, $ids['Martin_Line'], 'Вылетает игра при входе на сервер', "Игра закрывается сразу после загрузки.", 11, 120, []);
    mkthread(40, $ids['Diego_Bacardi'], '[Фракции] Предложение по Армии', "[b]1. Суть предложения:[/b] Добавить учения раз в неделю", 1, 240, []);
    mkthread(83, $ids['Kuzya_Kabanov'], 'RP BIO | Kuzya Kabanov', "[b]Имя и фамилия:[/b] Kuzya Kabanov", 12, 300, []);
    mkthread(32, $ids['admin'], 'Правила форума', "[center][b]Правила форума World Role Play[/b][/center]\n\n[list=1]\n[*]Уважайте других участников.\n[*]Запрещён флуд и оффтоп в тематических разделах.\n[*]Жалобы оформляются строго по форме.\n[/list]", 7, 86400 * 20, []);
    mkthread(81, $ids['Ricardo_Calump'], 'Кто сегодня на мероприятие?', "Собираемся в 20:00 у мэрии.", null, 60, [[$ids['Martin_Line'], 'Я буду!'], [$ids['Diego_Bacardi'], '+']]);
    mkthread(90, $ids['admin'], 'Служебное: график дежурств', "Только для команды.", null, 500, []);
}
foreach (db_all('SELECT id FROM nodes') as $n) {
    node_rebuild($n['id']);
}
foreach ($ids as $uid) {
    user_rebuild_counts($uid);
}
echo "ok\n";
