<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка модуля «Рассмотрение» (app/modules/workflow*.php) на базе из config.php:
//   php tests/workflow_test.php
// Нужны демо-пользователи (tests/dev_seed.php) и обновление 0100_workflow.sql.
// Создаёт свои темы «[wf-test] ...» и в конце удаляет всё, что создал.
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$fails = 0;
$total = 0;
function t($name, $cond, $info = '')
{
    global $fails, $total;
    $total++;
    if (!$cond) {
        $fails++;
    }
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . ($cond || $info === '' ? '' : "\n  " . $info) . "\n";
}

// Действовать от имени пользователя (как после входа)
function as_user($name)
{
    $u = $name === null ? null : user_by_name($name);
    if ($name !== null && !$u) {
        fwrite(STDERR, "Нет пользователя $name - запустите tests/dev_seed.php\n");
        exit(1);
    }
    $GLOBALS['wrp_user'] = $u;
    wf_reset_caches();
    return $u;
}

function ago($seconds)
{
    return date('Y-m-d H:i:s', time() - $seconds);
}

if (!wf_ready()) {
    fwrite(STDERR, "Таблицы модуля не найдены: примените sql/migrations/0100_workflow.sql (php tools/migrate.php)\n");
    exit(1);
}

$created = [];
$staffAdded = false;
$roxas = user_by_name('Roxas_Alexandro');
$kuzya = user_by_name('Kuzya_Kabanov');

// Уборка: всё, что создал тест (темы, сообщения, ход рассмотрения, оповещения, журнал)
function wf_test_cleanup()
{
    global $created, $staffAdded, $kuzya, $prevStaff;
    $GLOBALS['wrp_user'] = null;
    if ($created) {
        $in = db_in($created, 't');
        $users = array_map('intval', array_column(db_all('SELECT DISTINCT user_id FROM posts WHERE thread_id IN (' . $in['sql'] . ')', $in['params']), 'user_id'));
        $nodes = array_map('intval', array_column(db_all('SELECT DISTINCT node_id FROM threads WHERE id IN (' . $in['sql'] . ')', $in['params']), 'node_id'));
        $postIn = 'SELECT id FROM posts WHERE thread_id IN (' . $in['sql'] . ')';
        $pids = array_map('intval', array_column(db_all($postIn, $in['params']), 'id'));
        if ($pids) {
            $pin = db_in($pids, 'p');
            db_exec('DELETE FROM post_likes WHERE post_id IN (' . $pin['sql'] . ')', $pin['params']);
            db_exec("DELETE FROM mod_log WHERE target_type = 'post' AND target_id IN (" . $pin['sql'] . ')', $pin['params']);
        }
        foreach (['posts', 'wf_threads', 'wf_events', 'wf_votes', 'alerts', 'thread_watch', 'thread_reads'] as $table) {
            db_exec('DELETE FROM ' . $table . ' WHERE thread_id IN (' . $in['sql'] . ')', $in['params']);
        }
        db_exec("DELETE FROM mod_log WHERE target_type = 'thread' AND target_id IN (" . $in['sql'] . ')', $in['params']);
        db_exec('DELETE FROM threads WHERE id IN (' . $in['sql'] . ')', $in['params']);
        foreach ($nodes as $n) {
            node_rebuild($n);
        }
        foreach (array_unique(array_merge($users, [(int)user_by_name('Roxas_Alexandro')['id'], (int)user_by_name('Diego_Bacardi')['id']])) as $u) {
            user_rebuild_counts($u);
        }
    }
    if ($staffAdded && $kuzya) {
        db_exec('DELETE FROM wf_node_staff WHERE node_id = 62 AND user_id = :u', ['u' => (int)$kuzya['id']]);
        if ($prevStaff) {
            db_insert('wf_node_staff', $prevStaff);
        }
        $staffAdded = false;
    }
    db_exec("DELETE FROM rate_limits WHERE action = 'wf_vote' AND ip = :ip", ['ip' => client_ip()]);
    $created = [];
}
register_shutdown_function('wf_test_cleanup');

// Новая тема от имени пользователя
function new_thread($author, $nodeId, $title, $prefixId, $createdAgo = 0)
{
    global $created;
    as_user($author);
    $tid = fp_create_thread(node_get($nodeId), '[wf-test] ' . $title, $prefixId, 'Текст обращения для проверки модуля рассмотрения.');
    $created[] = $tid;
    if ($createdAgo) {
        db_exec('UPDATE threads SET created_at = :c, last_post_at = :l WHERE id = :id', ['c' => ago($createdAgo), 'l' => ago($createdAgo), 'id' => $tid]);
        db_exec('UPDATE posts SET created_at = :c WHERE thread_id = :id', ['c' => ago($createdAgo), 'id' => $tid]);
    }
    return $tid;
}

// ---------- Настройки разделов ----------

$c52 = wf_cfg(52);
t('настройки раздела 52 есть', $c52 && (int)$c52['min_level'] === 30 && (int)$c52['deadline_hours'] === 48);
t('окончательные статусы жалоб: 2,3,4', $c52 && $c52['finals'] === [2, 3, 4]);
t('у жалоб на игроков есть архив', $c52 && wf_archive_node($c52, node_get(52)) !== null);
t('заявления: срок 72 часа', ($c = wf_cfg(61)) && (int)$c['deadline_hours'] === 72);
t('предложения: голосование и архив 41', ($c = wf_cfg(40)) && (int)$c['voting'] === 1 && (int)$c['archive_node_id'] === 41);
t('готовых ответов не меньше 8', (int)db_val('SELECT COUNT(*) FROM wf_macros WHERE is_active = 1') >= 8);
t('готовые ответы раздела 52 отфильтрованы', isset(wf_macros_for(52)[2]) && !isset(wf_macros_for(52)[9]));

// ---------- Права ----------

as_user(null);
t('гость не рассматривает', !wf_can_process(node_get(52)));
as_user('Roxas_Alexandro');
t('пользователь не рассматривает', !wf_can_process(node_get(52)) && !wf_processable_node_ids());
as_user('Martin_Line');
t('хелпер рассматривает жалобы на игроков', wf_can_process(node_get(52)));
t('хелпер не рассматривает жалобы на администрацию', !wf_can_process(node_get(53)));
t('хелпер не старший', !wf_is_supervisor(node_get(52)));
as_user('Ricardo_Calump');
t('администратор рассматривает жалобы на администрацию', wf_can_process(node_get(53)));
t('администратор - старший в жалобах на игроков', wf_is_supervisor(node_get(52)));
t('администратор не рассматривает набор в администрацию (80)', !wf_can_process(node_get(68)));
// Назначение лидера, если оно уже было (сделано в админке), запоминаем и вернём при уборке
$prevStaff = db_one('SELECT * FROM wf_node_staff WHERE node_id = 62 AND user_id = :u', ['u' => (int)$kuzya['id']]);
db_exec('DELETE FROM wf_node_staff WHERE node_id = 62 AND user_id = :u', ['u' => (int)$kuzya['id']]);
$staffAdded = true;
as_user('Kuzya_Kabanov');
t('лидер без назначения не рассматривает жалобы на сотрудников', !wf_can_process(node_get(62)));
db_exec('INSERT IGNORE INTO wf_node_staff (node_id, user_id, note, created_at) VALUES (62, :u, :n, :c)', ['u' => (int)$kuzya['id'], 'n' => 'wf-test лидер', 'c' => now()]);
as_user('Kuzya_Kabanov');
t('назначенный лидер рассматривает свой раздел', wf_can_process(node_get(62)));
t('назначенный лидер не рассматривает чужой раздел', !wf_can_process(node_get(61)) && !wf_can_process(node_get(52)));
t('назначенному лидеру не открывается модерация ядра', !can_moderate());
t('лидер есть среди кандидатов на передачу в 62', isset(wf_candidates(node_get(62))[(int)$kuzya['id']]));
t('пользователь не кандидат на передачу', !isset(wf_candidates(node_get(52))[(int)$roxas['id']]));

// ---------- Взять на рассмотрение ----------

$t1 = new_thread('Roxas_Alexandro', 52, 'Жалоба на Nick_Test', 0);
as_user('Martin_Line');
list($ok, $msg) = wf_claim(thread_get($t1), node_get(52));
t('хелпер взял тему', $ok, $msg);
$st = wf_thread_state($t1);
t('записано, кто и когда взял', $st && (int)$st['claimed_by'] === (int)user()['id'] && $st['claimed_at']);
t('при взятии поставлен префикс «На рассмотрении»', (int)thread_get($t1)['prefix_id'] === 1);
t('автор получил оповещение о взятии', (bool)db_val("SELECT 1 FROM alerts WHERE user_id = :u AND type = 'wf_claim' AND thread_id = :t", ['u' => (int)$roxas['id'], 't' => $t1]));
$a = db_one("SELECT a.*, u.username AS actor_name, t.title AS thread_title FROM alerts a LEFT JOIN users u ON u.id = a.actor_id LEFT JOIN threads t ON t.id = a.thread_id WHERE a.type = 'wf_claim' AND a.thread_id = :t", ['t' => $t1]);
t('текст оповещения о взятии', $a && strpos(alert_text($a), 'взял(а) на рассмотрение вашу жалобу') !== false, $a ? alert_text($a) : '');
as_user('Diego_Bacardi');
list($ok) = wf_claim(thread_get($t1), node_get(52));
t('второй сотрудник не может взять ту же тему', !$ok);
list($ok) = wf_unclaim(thread_get($t1), node_get(52));
t('модератор (не старший) не снимает чужую тему', !$ok);
as_user('Roxas_Alexandro');
list($ok) = wf_claim(thread_get($t1), node_get(52));
t('пользователь не может взять тему', !$ok);

// ---------- Сроки ----------

$c = wf_cfg(52);
$dl = wf_deadline(thread_get($t1), $c);
t('срок: открыт, осталось около 48 часов', $dl['state'] === 'ok' && $dl['left'] > 47 * 3600 && $dl['left'] <= 48 * 3600, json_encode($dl));
db_exec('UPDATE threads SET created_at = :c WHERE id = :id', ['c' => ago(50 * 3600), 'id' => $t1]);
$dl = wf_deadline(thread_get($t1), $c);
t('через 50 часов - просрочено на 2 часа', $dl['state'] === 'overdue' && wf_dur($dl['left']) === '2 ч', json_encode($dl) . ' ' . wf_dur($dl['left']));
db_exec('UPDATE threads SET created_at = :c WHERE id = :id', ['c' => ago(40 * 3600), 'id' => $t1]);
t('за 8 часов до срока - «скоро»', wf_deadline(thread_get($t1), $c)['state'] === 'soon');
db_exec('UPDATE threads SET created_at = :c WHERE id = :id', ['c' => ago(50 * 3600), 'id' => $t1]);
as_user('Martin_Line');
$cnt = wf_attention_count();
t('просроченная тема попадает в значок очереди', $cnt >= 1);
t('wf_dur', wf_dur(45 * 60) === '45 мин' && wf_dur(12 * 3600) === '12 ч' && wf_dur(53 * 3600) === '2 д 5 ч');

// ---------- Отказаться и передать ----------

list($ok, $msg) = wf_unclaim(thread_get($t1), node_get(52));
t('хелпер отказался от темы', $ok && !wf_thread_state($t1)['claimed_by'], $msg);
list($ok, $msg) = wf_transfer(thread_get($t1), node_get(52), (int)user_by_name('Roxas_Alexandro')['id']);
t('нельзя передать обычному пользователю', !$ok);
$ricardo = user_by_name('Ricardo_Calump');
list($ok, $msg) = wf_transfer(thread_get($t1), node_get(52), (int)$ricardo['id']);
t('передать администратору', $ok && (int)wf_thread_state($t1)['claimed_by'] === (int)$ricardo['id'], $msg);
t('администратор получил оповещение о передаче', (bool)db_val("SELECT 1 FROM alerts WHERE user_id = :u AND type = 'wf_transfer' AND thread_id = :t", ['u' => (int)$ricardo['id'], 't' => $t1]));

// ---------- Решение в один клик ----------

list($ok, $msg) = wf_verdict(thread_get($t1), node_get(52), ['prefix_id' => 3, 'macro_id' => 2, 'body' => '', 'lock' => true, 'archive' => true]);
t('хелпер не выносит решение по теме администратора', !$ok, $msg);
as_user('Ricardo_Calump');
list($ok, $msg) = wf_verdict(thread_get($t1), node_get(52), ['prefix_id' => 99, 'body' => 'Текст', 'lock' => false]);
t('чужой префикс отклоняется', !$ok);
list($ok, $msg) = wf_verdict(thread_get($t1), node_get(52), ['prefix_id' => 3, 'body' => '', 'macro_id' => 0]);
t('пустой ответ отклоняется', !$ok);
list($ok, $msg) = wf_verdict(thread_get($t1), node_get(52), ['prefix_id' => 3, 'body' => '', 'macro_id' => 9]);
t('готовый ответ другого раздела отклоняется', !$ok);
$archiveId = (int)wf_cfg(52)['archive_node_id'];
list($ok, $msg, $to) = wf_verdict(thread_get($t1), node_get(52), ['prefix_id' => 3, 'macro_id' => 2, 'body' => '', 'lock' => true, 'archive' => true]);
t('решение вынесено', $ok, $msg);
$th = thread_get($t1);
t('статус «Отказано», тема закрыта и в архиве', (int)$th['prefix_id'] === 3 && (int)$th['is_locked'] === 1 && (int)$th['node_id'] === $archiveId, json_encode([$th['prefix_id'], $th['is_locked'], $th['node_id']]));
$post = db_one('SELECT * FROM posts WHERE thread_id = :t ORDER BY id DESC LIMIT 1', ['t' => $t1]);
t('ответ от имени администратора с подстановками', $post && (int)$post['user_id'] === (int)$ricardo['id']
    && strpos($post['body'], 'Здравствуйте, Roxas_Alexandro.') === 0 && strpos($post['body'], 'С уважением, Ricardo_Calump.') !== false, $post ? $post['body'] : '');
t('ответ учтён в теме (счётчики ядра)', (int)$th['reply_count'] === 1 && (int)$th['last_post_id'] === (int)$post['id']);
t('автору одно оповещение - о решении', (int)db_val("SELECT COUNT(*) FROM alerts WHERE user_id = :u AND thread_id = :t AND type IN ('reply', 'wf_verdict', 'prefix', 'move')", ['u' => (int)$roxas['id'], 't' => $t1]) === 1
    && (bool)db_val("SELECT 1 FROM alerts WHERE user_id = :u AND thread_id = :t AND type = 'wf_verdict' AND extra = :e", ['u' => (int)$roxas['id'], 't' => $t1, 'e' => 'Отказано']));
$st = wf_thread_state($t1);
t('ход рассмотрения: решение записано', (int)$st['verdict_by'] === (int)$ricardo['id'] && (int)$st['verdict_prefix_id'] === 3 && (int)$st['verdict_post_id'] === (int)$post['id']);
$ev = db_one("SELECT * FROM wf_events WHERE thread_id = :t AND action = 'verdict' ORDER BY id DESC LIMIT 1", ['t' => $t1]);
t('в истории: решение после срока', $ev && (int)$ev['overdue'] === 1 && (int)$ev['prefix_id'] === 3 && (int)$ev['seconds'] >= 50 * 3600);
t('журнал модерации: решение, префикс, закрытие, перенос', (int)db_val("SELECT COUNT(DISTINCT action) FROM mod_log WHERE target_type = 'thread' AND target_id = :t AND action IN ('wf_verdict', 'thread_prefix', 'thread_lock', 'thread_move')", ['t' => $t1]) === 4);
t('после решения срок не считается', wf_deadline($th, wf_cfg(52))['state'] === 'none');

// ---------- Ожидание доказательств и автозакрытие ----------

$t2 = new_thread('Roxas_Alexandro', 52, 'Жалоба без доказательств', 1, 30 * 3600);
as_user('Martin_Line');
list($ok, $msg) = wf_verdict(thread_get($t2), node_get(52), ['prefix_id' => 17, 'macro_id' => 5, 'body' => '', 'lock' => false]);
t('запрос доказательств', $ok && (int)thread_get($t2)['prefix_id'] === 17, $msg);
t('отсчёт доказательств начат', (bool)wf_thread_state($t2)['evidence_at']);
t('тема с запросом доказательств не закрыта', !(int)thread_get($t2)['is_locked']);
t('запрос доказательств - промежуточный ответ, не решение', (bool)db_val("SELECT 1 FROM wf_events WHERE thread_id = :t AND action = 'status' AND prefix_id = 17", ['t' => $t2])
    && !wf_thread_state($t2)['verdict_at'] && (bool)db_val("SELECT 1 FROM alerts WHERE thread_id = :t AND type = 'wf_status'", ['t' => $t2]));
t('срок продолжает идти при ожидании доказательств', wf_deadline(thread_get($t2), wf_cfg(52))['state'] !== 'none');
$t3 = new_thread('Roxas_Alexandro', 52, 'Жалоба, автор ответил', 1, 30 * 3600);
as_user('Martin_Line');
wf_verdict(thread_get($t3), node_get(52), ['prefix_id' => 17, 'macro_id' => 5, 'body' => '']);
db_exec('UPDATE wf_threads SET evidence_at = :e WHERE thread_id IN (:a, :b)', ['e' => ago(25 * 3600), 'a' => $t2, 'b' => $t3]);
as_user('Roxas_Alexandro');
fp_create_post(thread_get($t3), node_get(52), 'Вот видео: https://youtu.be/dQw4w9WgXcQ');
as_user(null);
$closed = wf_cron_evidence([$t2, $t3]);
wf_reset_caches();
$th2 = thread_get($t2);
t('без ответа автора за 24 часа: «Отказано» и закрыта', (int)$th2['prefix_id'] === 3 && (int)$th2['is_locked'] === 1, json_encode([$th2['prefix_id'], $th2['is_locked'], $closed]));
$last = db_one('SELECT body, user_id FROM posts WHERE thread_id = :t ORDER BY id DESC LIMIT 1', ['t' => $t2]);
t('ответ системы от того, кто запросил доказательства', $last && (int)$last['user_id'] === (int)user_by_name('Martin_Line')['id'] && strpos($last['body'], 'закрыто автоматически') !== false && strpos($last['body'], 'в течение 24 ч') !== false, $last ? $last['body'] : '');
t('автор получил оповещение об автозакрытии', (bool)db_val("SELECT 1 FROM alerts WHERE user_id = :u AND thread_id = :t AND type = 'wf_autoclose'", ['u' => (int)$roxas['id'], 't' => $t2]));
t('в истории: автозакрытие системой', (bool)db_val("SELECT 1 FROM wf_events WHERE thread_id = :t AND action = 'autoclose' AND user_id = 0", ['t' => $t2]));
t('ответ автора отменяет автозакрытие', (int)thread_get($t3)['prefix_id'] === 17 && !(int)thread_get($t3)['is_locked']);
t('повторный запуск ничего не трогает', wf_cron_evidence([$t2, $t3]) === 0);

// Префикс ожидания через меню модерации ядра тоже запускает отсчёт
$t4 = new_thread('Roxas_Alexandro', 52, 'Жалоба, модератор ядра', 1);
as_user('Ricardo_Calump');
$_POST = ['prefix_id' => '17'];
list($ok) = fp_mod_thread(thread_get($t4), node_get(52), 'prefix');
$_POST = [];
t('смена префикса ядром: отсчёт доказательств', $ok && (bool)wf_thread_state($t4)['evidence_at']);
$_POST = ['prefix_id' => '2'];
fp_mod_thread(thread_get($t4), node_get(52), 'prefix');
$_POST = [];
$st4 = wf_thread_state($t4);
t('окончательный префикс ядром: решение в статистике', $st4 && !$st4['evidence_at'] && (int)$st4['verdict_prefix_id'] === 2
    && (bool)db_val("SELECT 1 FROM wf_events WHERE thread_id = :t AND action = 'verdict' AND prefix_id = 2", ['t' => $t4]));

// ---------- Автоперенос в архив ----------

$t5 = new_thread('Roxas_Alexandro', 52, 'Старая решённая жалоба', 2, 5 * 86400);
$t6 = new_thread('Roxas_Alexandro', 52, 'Свежая решённая жалоба', 2, 3600);
as_user(null);
$moved = wf_cron_archive([$t5, $t6]);
wf_reset_caches();
t('решённая тема без активности 5 дней в архиве и закрыта', (int)thread_get($t5)['node_id'] === $archiveId && (int)thread_get($t5)['is_locked'] === 1, (string)$moved);
t('свежая решённая тема осталась на месте', (int)thread_get($t6)['node_id'] === 52);
t('счётчики разделов пересчитаны', (int)node_get($archiveId)['thread_count'] >= 2);

// ---------- Голосование за предложения ----------

$t7 = new_thread('Diego_Bacardi', 40, 'Предложение: новые интерьеры домов', 1);
as_user('Roxas_Alexandro');
list($ok, $err, $r) = wf_vote(thread_get($t7), node_get(40), 1);
t('голос «за»', $ok && $r['up'] === 1 && $r['down'] === 0 && $r['my'] === 1, $err);
list($ok, $err, $r) = wf_vote(thread_get($t7), node_get(40), 1);
t('повторный голос снимает', $ok && $r['up'] === 0 && $r['my'] === 0);
list($ok, $err, $r) = wf_vote(thread_get($t7), node_get(40), -1);
t('голос «против»', $ok && $r['down'] === 1 && $r['my'] === -1);
list($ok, $err, $r) = wf_vote(thread_get($t7), node_get(40), 1);
t('смена голоса', $ok && $r['up'] === 1 && $r['down'] === 0 && (int)db_val('SELECT COUNT(*) FROM wf_votes WHERE thread_id = :t', ['t' => $t7]) === 1);
as_user('Martin_Line');
wf_vote(thread_get($t7), node_get(40), 1);
$th7 = thread_get($t7);
t('итоги в теме (для списков без запросов)', (int)$th7['wf_up'] === 2 && (int)$th7['wf_down'] === 0);
as_user('Diego_Bacardi');
list($ok) = wf_vote(thread_get($t7), node_get(40), 1);
t('автор не голосует за своё предложение', !$ok);
as_user(null);
list($ok) = wf_vote(thread_get($t7), node_get(40), 1);
t('гость не голосует', !$ok);
as_user('Roxas_Alexandro');
list($ok) = wf_vote(thread_get($t1), node_get(52), 1);
t('в жалобах голосования нет', !$ok);
$orders = hook_filter('forum_orders', [], node_get(40));
t('сортировка «По рейтингу» в предложениях', isset($orders['rating']) && !isset(hook_filter('forum_orders', [], node_get(52))['rating']));
$ranked = db_all('SELECT t.id, t.wf_up - t.wf_down AS score FROM threads t WHERE t.node_id = 40 AND t.is_deleted = 0 ORDER BY ' . $orders['rating'][1]);
$scores = array_map('intval', array_column($ranked, 'score'));
$sorted = $scores;
rsort($sorted);
t('по рейтингу: сначала больше «за», чем «против»', $scores === $sorted && in_array($t7, array_map('intval', array_column($ranked, 'id')), true), json_encode($ranked));
as_user('Ricardo_Calump');
list($ok, $msg) = wf_verdict(thread_get($t7), node_get(40), ['prefix_id' => 14, 'macro_id' => 11, 'body' => '', 'lock' => false, 'archive' => true]);
t('предложение передано разработчикам и в «Рассмотренные»', $ok && (int)thread_get($t7)['node_id'] === 41 && (int)thread_get($t7)['prefix_id'] === 14, $msg);
as_user('Roxas_Alexandro');
db_exec('UPDATE threads SET is_locked = 1 WHERE id = :id', ['id' => $t7]);
list($ok) = wf_vote(thread_get($t7), node_get(41), -1);
t('в закрытой теме голосовать нельзя', !$ok);

// ---------- Очередь и вывод ----------

as_user('Martin_Line');
$ids = wf_processable_node_ids();
t('хелпер видит в очереди жалобы на игроков и техразделы', in_array(52, $ids, true) && in_array(21, $ids, true) && !in_array(53, $ids, true));
$t8 = new_thread('Roxas_Alexandro', 52, 'Жалоба <script>alert(1)</script>', 1);
as_user('Martin_Line');
$box = wf_status_box(thread_get($t8), node_get(52));
t('блок хода рассмотрения: срок и форма решения', strpos($box, 'Ответ до') !== false && strpos($box, 'id="wf-verdict"') !== false && strpos($box, 'data-wf-macro') !== false);
t('блок экранирует название темы', strpos($box, '<script>alert(1)') === false);
$t8row = db_one(fp_thread_select_sql() . ' WHERE t.id = :id', ['id' => $t8]);
$badges = hook_html('thread_row_title_after', $t8row, []);
t('значок срока в списке для сотрудника', strpos($badges, 'wf-chip-ok') !== false);
wf_claim(thread_get($t8), node_get(52));
wf_reset_caches();
$badges = hook_html('thread_row_title_after', $t8row, []);
t('значок «рассматривает» в списке (пачкой по разделу)', strpos($badges, 'wf-chip-claim') !== false && strpos($badges, 'Martin_Line') !== false);
as_user('Roxas_Alexandro');
$badges = hook_html('thread_row_title_after', $t8row, []);
t('пользователь не видит срок в списке, но видит, кто рассматривает', strpos($badges, 'wf-chip-ok') === false && strpos($badges, 'wf-chip-claim') !== false);
$box = wf_status_box(thread_get($t8), node_get(52));
t('автор видит ход рассмотрения без инструментов', strpos($box, 'Рассматривает') !== false && strpos($box, 'wf-verdict') === false && strpos($box, 'wf-tools') === false);
as_user('Kuzya_Kabanov');
$t9 = new_thread('Roxas_Alexandro', 62, 'Жалоба на сотрудника мэрии', 1);
as_user('Kuzya_Kabanov');
list($ok, $msg) = wf_claim(thread_get($t9), node_get(62));
t('лидер берёт жалобу на сотрудника своей организации', $ok, $msg);
list($ok, $msg) = wf_verdict(thread_get($t9), node_get(62), ['prefix_id' => 2, 'macro_id' => 1, 'body' => '', 'lock' => true, 'archive' => false]);
t('лидер выносит решение в своём разделе', $ok && (int)thread_get($t9)['is_locked'] === 1, $msg);
list($ok) = wf_claim(thread_get($t8), node_get(52));
t('лидер не берёт темы чужого раздела', !$ok);

echo "\n" . ($fails ? $fails . ' FAILED of ' . $total : 'ALL OK (' . $total . ')') . "\n";
wf_test_cleanup();
exit($fails ? 1 : 0);
