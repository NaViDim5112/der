<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка модуля «Модерация»: php tests/moderation_test.php
// Нужна база с демо-данными (tests/dev_seed.php) и применённым обновлением 0200.
// Все изменения в базе делаются в транзакции и откатываются в конце.
// С BASE=http://127.0.0.1:8082 дополнительно проверяются права страниц через HTTP (только чтение).
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$fails = 0;
$total = 0;
function t($name, $cond, $extra = '')
{
    global $fails, $total;
    $total++;
    if (!$cond) {
        $fails++;
    }
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . ($cond || $extra === '' ? '' : "\n  " . $extra) . "\n";
}

// Войти под пользователем (без сессии: только для проверки прав в функциях)
function as_user($name)
{
    $u = $name === null ? null : user_by_name($name);
    if ($name !== null && !$u) {
        echo "FAIL: нет демо-пользователя $name (запустите tests/dev_seed.php)\n";
        exit(1);
    }
    $GLOBALS['wrp_user'] = $u;
    return $u;
}

if (!mdr_ready()) {
    echo "FAIL: обновление базы 0200_moderation.sql не применено (php tools/migrate.php)\n";
    exit(1);
}

$admin = as_user('admin');
$ricardo = user_by_name('Ricardo_Calump');
$diego = user_by_name('Diego_Bacardi');
$martin = user_by_name('Martin_Line');
$roxas = user_by_name('Roxas_Alexandro');
$kuzya = user_by_name('Kuzya_Kabanov');

$pdo = db();
$pdo->beginTransaction();
try {
    // Чистый лист внутри транзакции
    foreach (['mod_report_items', 'mod_reports', 'mod_warnings', 'mod_warning_triggers', 'mod_reply_bans', 'post_edits'] as $tbl) {
        db_exec('DELETE FROM `' . $tbl . '`');
    }
    db_exec('UPDATE users SET is_banned = 0, ban_reason = NULL, ban_until = NULL');
    db_exec('DELETE FROM rate_limits');
    db_exec('DELETE FROM mod_warning_rules');
    db_exec("INSERT INTO mod_warning_rules (id, points, action, days) VALUES (1, 10, 'ban', 3), (2, 20, 'ban', 14), (3, 30, 'ban', 0)");

    // ---------- Права ----------
    echo "\n-- Права\n";
    as_user('Diego_Bacardi');
    t('модератор не может наказать главного администратора', !mdr_can_punish(user_by_name('admin')));
    t('модератор не может наказать администратора (уровень 60)', !mdr_can_punish($ricardo));
    t('модератор не может наказать другого модератора того же уровня', !mdr_can_punish(array_merge($diego, ['id' => 99999])));
    t('модератор не может наказать себя', !mdr_can_punish($diego));
    t('модератор может наказать пользователя', mdr_can_punish($roxas));
    t('модератор может наказать хелпера (30 < 40)', mdr_can_punish($martin));
    t('уровень с дополнительной группой: Kuzya = 20', mdr_level_of($kuzya) === 20, (string)mdr_level_of($kuzya));
    t('текст ошибки для старшего', mdr_punish_error($admin) !== '');
    as_user('admin');
    t('главный администратор может наказать модератора', mdr_can_punish($diego));
    as_user('Martin_Line');
    t('хелпер (без права модерации) не может выдавать предупреждения', !mdr_can_punish($roxas));
    t('хелпер видит центр жалоб (is_staff)', is_staff());
    as_user('Roxas_Alexandro');
    t('пользователь не видит центр жалоб', !is_staff());

    // ---------- Жалобы ----------
    echo "\n-- Жалобы\n";
    as_user('Roxas_Alexandro');
    list($ok, $msg, $rid) = mdr_report_submit('post', 1, 'insult', 'Оскорбил <b>меня</b>');
    t('жалоба на чужое сообщение принята', $ok, $msg);
    list($ok2, $msg2) = mdr_report_submit('post', 1, 'insult', '');
    t('повторная жалоба того же человека отклонена', !$ok2 && mb_strpos($msg2, 'уже') !== false, $msg2);
    list($ok3, $msg3) = mdr_report_submit('post', 2, 'flood', '');
    t('на своё сообщение пожаловаться нельзя', !$ok3, $msg3);
    list($ok4) = mdr_report_submit('user', (int)$roxas['id'], 'insult', 'x');
    t('на себя пожаловаться нельзя', !$ok4);
    list($ok5) = mdr_report_submit('post', 1, 'nonsense', '');
    t('неизвестная причина отклонена', !$ok5);
    list($ok6) = mdr_report_submit('post', 9, 'other', '');
    t('«Другое» без комментария отклонено', !$ok6);
    as_user('Kuzya_Kabanov');
    list($okK, $msgK, $ridK) = mdr_report_submit('post', 1, 'rules', 'Тоже видел');
    t('жалоба второго человека на то же сообщение', $okK, $msgK);
    t('жалобы на один материал собраны в одну', $ridK === $rid, "$ridK vs $rid");
    $rep = mdr_report_get($rid);
    t('счётчик жалоб = 2', (int)$rep['reports_count'] === 2);
    t('снимок текста сохранён', (string)$rep['content_snapshot'] === (string)db_val('SELECT body FROM posts WHERE id = 1'));
    $items = mdr_report_items([$rid]);
    t('два пожаловавшихся в списке', count($items[$rid] ?? []) === 2);
    t('частая причина определяется', in_array(mdr_report_main_reason($items[$rid]), ['insult', 'rules'], true));
    list($okM, $msgM) = mdr_report_submit('message', 10, 'fraud', 'чужая переписка');
    t('на чужую личную переписку пожаловаться нельзя', !$okM, $msgM);

    // Лимит частоты
    setting_set('mod_report_limit', '1');
    as_user('Roxas_Alexandro');
    list($okR, $msgR) = mdr_report_submit('post', 11, 'flood', '');
    t('лимит жалоб в час срабатывает', !$okR && mb_strpos($msgR, 'Слишком') !== false, $msgR);
    setting_set('mod_report_limit', '10');

    // Видимость ЛС для модератора: только по жалобе, без ссылки на переписку
    as_user('Diego_Bacardi');
    $msgC = mdr_content_load('message', 10);
    t('модератор не участник переписки: ЛС не видно напрямую', $msgC && !$msgC['visible'] && $msgC['link'] === '');

    // Решение
    $before = (int)db_val("SELECT COUNT(*) FROM alerts WHERE type = 'mod_report'");
    list($okRes) = mdr_report_resolve($rep, 'resolved', 'Подтверждено');
    $after = (int)db_val("SELECT COUNT(*) FROM alerts WHERE type = 'mod_report'");
    t('жалоба закрыта', $okRes && mdr_report_get($rid)['status'] === 'resolved');
    t('оба пожаловавшихся получили оповещение', $after - $before === 2, (string)($after - $before));
    $a = db_one("SELECT a.*, NULL AS actor_name, 'Тема' AS thread_title FROM alerts a WHERE type = 'mod_report' ORDER BY id DESC LIMIT 1");
    t('текст оповещения о решении', mb_strpos(alert_text($a), 'нарушение подтверждено') !== false, alert_text($a));
    t('ссылка оповещения ведёт на сообщение', alert_link($a) === post_url(1), alert_link($a));
    list($okAgain) = mdr_report_resolve(mdr_report_get($rid), 'rejected', '');
    t('закрытую жалобу повторно закрыть нельзя', !$okAgain);
    $closedCard = mdr_report_card(mdr_report_get($rid), $items[$rid], mdr_content_load('post', 1), [], '/forum/reports.php');
    t('у закрытой жалобы нет кнопок решения, есть «Открыть снова»', strpos($closedCard, 'value="resolve"') === false && strpos($closedCard, 'value="reopen"') !== false);
    $openCard = mdr_report_card(['id' => 77, 'content_type' => 'post', 'content_id' => 1, 'content_user_id' => 1, 'content_snapshot' => '<b>x</b>', 'status' => 'open',
        'assigned_to' => null, 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null], [['user_id' => 3, 'username' => 'R', 'avatar' => null, 'group_color' => null, 'reason' => 'insult', 'comment' => '<script>x</script>', 'created_at' => now()]], null, [], '/forum/reports.php');
    t('открытая жалоба: кнопки решения, комментарий экранирован', strpos($openCard, 'value="resolve"') !== false && strpos($openCard, '<script>x') === false && strpos($openCard, '&lt;script&gt;x') !== false);
    as_user('Roxas_Alexandro');
    list($okNew, , $ridNew) = mdr_report_submit('post', 1, 'insult', 'снова');
    t('после решения новая жалоба создаёт новую карточку', $okNew && $ridNew !== $rid);

    // ---------- Предупреждения и пороги ----------
    echo "\n-- Предупреждения\n";
    as_user('Diego_Bacardi');
    $rid3 = (int)$roxas['id'];
    $w = function ($points, $title = 'Тест') use ($roxas) {
        return mdr_warn_issue($roxas, ['type_id' => null, 'title' => $title, 'points' => $points, 'expiry_days' => 30, 'message' => '', 'public_note' => 0, 'content_type' => null, 'content_id' => null, 'report_id' => null, 'send_pm' => false]);
    };
    $banned = function () use ($rid3) {
        return db_one('SELECT is_banned, ban_until, ban_reason FROM users WHERE id = :id', ['id' => $rid3]);
    };
    $r1 = $w(3);
    t('3 балла: без бана', $r1['points'] === 3 && !$r1['applied'] && !(int)$banned()['is_banned']);
    $r2 = $w(5);
    t('8 баллов: без бана', $r2['points'] === 8 && !(int)$banned()['is_banned']);
    $r3 = $w(3, 'Третье');
    $b = $banned();
    t('11 баллов: порог 10 - бан на 3 дня', $r3['points'] === 11 && $r3['applied'] === ['Бан на 3 дня'] && (int)$b['is_banned'], json_encode($r3, JSON_UNESCAPED_UNICODE));
    t('срок бана около 3 дней', $b['ban_until'] && abs(strtotime($b['ban_until']) - (time() + 3 * 86400)) < 120);
    t('оповещение о блокировке', (bool)db_val("SELECT 1 FROM alerts WHERE user_id = :u AND type = 'mod_penalty'", ['u' => $rid3]));
    $r4 = $w(1);
    t('12 баллов: порог уже сработал, повторно не срабатывает', $r4['points'] === 12 && !$r4['applied']);
    $r5 = $w(10, 'Крупное');
    $b2 = $banned();
    t('22 балла: порог 20 - бан продлён до 14 дней', $r5['applied'] === ['Бан на 14 дней'] && abs(strtotime($b2['ban_until']) - (time() + 14 * 86400)) < 120);
    t('сработавшие пороги записаны', (int)db_val('SELECT COUNT(*) FROM mod_warning_triggers WHERE user_id = :u', ['u' => $rid3]) === 2);

    // Снятие: баллы ниже 20 - бан возвращается к сроку порога 10
    $wBig = db_one('SELECT * FROM mod_warnings WHERE id = :id', ['id' => $r5['id']]);
    list($okRv, $msgRv) = mdr_warn_revoke($wBig, 'Ошибка');
    $b3 = $banned();
    t('снятие предупреждения: баллы 12', mdr_points_active($rid3) === 12, $msgRv);
    t('бан 14 дней заменён баном порога 10', (int)$b3['is_banned'] && $b3['ban_until'] === $b['ban_until'], json_encode($b3));
    list($okRv2) = mdr_warn_revoke(db_one('SELECT * FROM mod_warnings WHERE id = :id', ['id' => $r5['id']]), '');
    t('повторно снять нельзя', !$okRv2);
    mdr_warn_revoke(db_one('SELECT * FROM mod_warnings WHERE id = :id', ['id' => $r3['id']]), '');
    t('баллы 9 - ниже порога 10: бан снят', mdr_points_active($rid3) === 9 && !(int)$banned()['is_banned']);
    t('пороги забыты', (int)db_val('SELECT COUNT(*) FROM mod_warning_triggers WHERE user_id = :u', ['u' => $rid3]) === 0);
    $r6 = $w(2);
    t('новое пересечение порога 10 срабатывает снова', $r6['points'] === 11 && $r6['applied'] === ['Бан на 3 дня'] && (int)$banned()['is_banned']);

    // Бан, выданный администратором навсегда, не сокращается
    db_update('users', ['is_banned' => 1, 'ban_until' => null, 'ban_reason' => 'Навсегда от администратора'], 'id = :id', ['id' => $rid3]);
    list($applied) = mdr_ban_apply($rid3, 3, 'Тест');
    t('бессрочный бан не заменяется коротким', !$applied && $banned()['ban_until'] === null);

    // Истечение
    db_exec('UPDATE mod_warnings SET expires_at = :t WHERE user_id = :u AND revoked_at IS NULL', ['t' => date('Y-m-d H:i:s', time() - 60), 'u' => $rid3]);
    t('истёкшие не считаются в баллах', mdr_points_active($rid3) === 0);
    $n = mdr_warnings_expire();
    t('крон отметил истёкшие', $n === 1 && (int)db_val('SELECT COUNT(*) FROM mod_warnings WHERE user_id = :u AND is_expired = 1', ['u' => $rid3]) === 4);
    t('крон забыл пороги после истечения', (int)db_val('SELECT COUNT(*) FROM mod_warning_triggers WHERE user_id = :u', ['u' => $rid3]) === 0);
    t('при истечении бан не снимается (у бана свой срок)', (int)$banned()['is_banned'] === 1);

    // Снятие просроченных банов
    db_update('users', ['is_banned' => 1, 'ban_until' => date('Y-m-d H:i:s', time() - 5), 'ban_reason' => 'x'], 'id = :id', ['id' => $rid3]);
    mdr_bans_lift_expired();
    t('крон снимает баны с прошедшим сроком', !(int)$banned()['is_banned']);

    // Бессрочное предупреждение
    $wf = mdr_warn_issue($roxas, ['title' => 'Бессрочное', 'points' => 1, 'expiry_days' => 0]);
    t('бессрочное предупреждение без даты окончания', db_val('SELECT expires_at FROM mod_warnings WHERE id = :id', ['id' => $wf['id']]) === null);

    // Отметка под сообщением (одним запросом на тему)
    $wp = mdr_warn_issue($roxas, ['title' => 'Реклама', 'points' => 5, 'expiry_days' => 60, 'public_note' => 1, 'content_type' => 'post', 'content_id' => 2]);
    $notes = mdr_thread_post_warnings(1);
    t('отметка «получил предупреждение» под сообщением', isset($notes[2]) && $notes[2][0]['title'] === 'Реклама');
    $al = db_one("SELECT a.*, 'Diego' AS actor_name, 'Тема' AS thread_title FROM alerts a WHERE a.type = 'mod_warning' ORDER BY a.id DESC LIMIT 1");
    t('оповещение о предупреждении', mb_strpos(alert_text($al), '«Реклама»') !== false && mb_strpos(alert_text($al), '+5') !== false, alert_text($al));

    // ЛС о предупреждении
    $convBefore = (int)db_val('SELECT COUNT(*) FROM conversations');
    mdr_warn_issue($kuzya, ['title' => 'С ЛС', 'points' => 1, 'expiry_days' => 7, 'message' => 'Пожалуйста, не флудите.', 'send_pm' => true]);
    t('личное сообщение о предупреждении создано', (int)db_val('SELECT COUNT(*) FROM conversations') === $convBefore + 1);

    // Запрет писать как действие порога
    db_exec("INSERT INTO mod_warning_rules (id, points, action, days) VALUES (4, 3, 'noreply', 7)");
    $rk = mdr_warn_issue($kuzya, ['title' => 'Флуд', 'points' => 2, 'expiry_days' => 7]);
    t('порог «запрет писать» выдаёт общий запрет', in_array('Запрет писать на форуме на 7 дней', $rk['applied'], true), json_encode($rk, JSON_UNESCAPED_UNICODE));
    db_exec('DELETE FROM mod_warning_rules WHERE id = 4');

    // ---------- Запрет ответов ----------
    echo "\n-- Запрет ответов\n";
    db_exec('DELETE FROM mod_reply_bans');
    $thread9 = thread_get(9);
    $node9 = node_get($thread9['node_id']);
    mdr_replyban_add(9, (int)$roxas['id'], 3, 'Флуд в теме');
    t('запрет действует в своей теме', mdr_replyban_active((int)$roxas['id'], 9) !== null);
    t('запрет не действует в другой теме', mdr_replyban_active((int)$roxas['id'], 1) === null);
    as_user('Roxas_Alexandro');
    $why = fp_reply_denied($thread9, $node9);
    t('ответ в теме запрещён с причиной', mb_strpos($why, 'Флуд в теме') !== false, $why);
    as_user('Diego_Bacardi');
    t('модератора запрет не касается', hook_filter('thread_reply_denied', '', $thread9, $node9) === '');
    $bid = (int)db_val('SELECT id FROM mod_reply_bans WHERE user_id = :u', ['u' => (int)$roxas['id']]);
    db_exec('UPDATE mod_reply_bans SET expires_at = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s', time() - 5), 'id' => $bid]);
    t('истёкший запрет не действует', mdr_replyban_active((int)$roxas['id'], 3) === null);
    mdr_replyban_add(null, (int)$roxas['id'], 0, 'Везде');
    t('общий запрет действует в любой теме', mdr_replyban_active((int)$roxas['id'], 4) !== null && mdr_replyban_active((int)$roxas['id'], 0) !== null);
    as_user('Roxas_Alexandro');
    $errs = hook_filter('new_thread_validate', [], $node9, false);
    t('общий запрет не даёт создавать темы', !empty($errs['mdr_noreply']));
    t('текст общего запрета', mb_strpos(mdr_replyban_text(mdr_replyban_active((int)$roxas['id'], 0)), 'без срока') !== false);

    // ---------- История правок и сравнение ----------
    echo "\n-- История правок\n";
    as_user('Roxas_Alexandro');
    db_update('posts', ['body' => 'Отличное обновление!', 'edited_at' => null, 'edited_by' => null], 'id = :id', ['id' => 2]);
    $post = fp_post_get(2);
    $orig = $post['body'];
    mdr_history_store($post);
    db_update('posts', ['body' => "Строка 1\nДоказательство: [url=https://imgur.com/a]скрин[/url]", 'edited_at' => now(), 'edited_by' => (int)$roxas['id']], 'id = :id', ['id' => 2]);
    $post = fp_post_get(2);
    mdr_history_store($post);
    db_update('posts', ['body' => "Строка 1\nДоказательство: [url=https://imgur.com/b]скрин[/url]\n<script>alert(1)</script>", 'edited_at' => now(), 'edited_by' => (int)$roxas['id']], 'id = :id', ['id' => 2]);
    $post = fp_post_get(2);
    $v = mdr_post_versions($post);
    t('три версии', count($v) === 3);
    t('новые сверху: текущая, затем исходная внизу', $v[0]['kind'] === 'current' && $v[2]['kind'] === 'original' && $v[2]['body'] === $orig);
    $diff = mdr_diff_html($v[1]['body'], $v[0]['body']);
    t('в сравнении нет живого HTML', strpos($diff, '<script>') === false && strpos($diff, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);
    t('добавленные и удалённые строки размечены', strpos($diff, 'is-add') !== false && strpos($diff, 'is-del') !== false);
    $ops = mdr_diff_lines(['a', 'b', 'c'], ['a', 'x', 'c']);
    t('построчное сравнение', $ops === [['=', 'a'], ['-', 'b'], ['+', 'x'], ['=', 'c']], json_encode($ops));
    t('одинаковые тексты', strpos(mdr_diff_html("a\nb", "a\nb"), 'не изменился') !== false);
    $big = range(1, 800);
    $big2 = $big;
    $big2[400] = 'изменено';
    $ops2 = mdr_diff_lines(array_map('strval', $big), array_map('strval', $big2));
    t('длинный текст сравнивается без зависания', count(array_filter($ops2, function ($o) {
        return $o[0] !== '=';
    })) === 2);
    db_exec('DELETE FROM post_edits WHERE post_id = 999999');
    $fake = ['id' => 999999, 'body' => 'x', 'created_at' => now(), 'edited_at' => now(), 'edited_by' => 1, 'user_id' => 1];
    $vf = mdr_post_versions($fake);
    t('правка до включения истории: одна версия', count($vf) === 1 && $vf[0]['kind'] === 'current');

    // ---------- IP ----------
    echo "\n-- IP\n";
    mdr_ip_log((int)$kuzya['id'], '10.1.2.3');
    mdr_ip_log((int)$kuzya['id'], '10.1.2.3');
    mdr_ip_log((int)$roxas['id'], '10.1.2.3');
    t('IP записан с числом заходов', (int)db_val('SELECT hits FROM user_ips WHERE user_id = :u AND ip = :ip', ['u' => (int)$kuzya['id'], 'ip' => '10.1.2.3']) === 2);
    t('два аккаунта на одном IP', (int)db_val('SELECT COUNT(DISTINCT user_id) FROM user_ips WHERE ip = :ip', ['ip' => '10.1.2.3']) === 2);

    // ---------- Подписи журнала ----------
    t('подписи действий для журнала', adm_action_label('warning_add') === 'Предупреждение' && adm_action_label('reply_ban') === 'Запрет ответов');
    t('цель «жалоба» в журнале', strpos((string)hook_first('modlog_target', ['target_type' => 'report', 'target_id' => 5]), 'reports.php?id=5') !== false);
} finally {
    $pdo->rollBack();
    as_user(null);
}

// ---------- Права страниц через HTTP (по желанию) ----------
$base = getenv('BASE');
if ($base) {
    echo "\n-- Страницы ($base)\n";
    $http = function ($jar, $method, $path, array $data = []) use ($base) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };
    $login = function ($name, $pass) use ($http) {
        $jar = tempnam(sys_get_temp_dir(), 'mdr');
        list(, $html) = $http($jar, 'GET', '/forum/login.php');
        preg_match('~name="_token" value="([a-f0-9]+)"~', $html, $m);
        $http($jar, 'POST', '/forum/login.php', ['login' => $name, 'password' => $pass, '_token' => $m[1] ?? '']);
        return $jar;
    };
    $guest = tempnam(sys_get_temp_dir(), 'mdr');
    t('гость: центр жалоб ведёт на вход', $http($guest, 'GET', '/forum/reports.php')[0] === 302);
    $user = $login('Roxas_Alexandro', 'test12345');
    t('пользователь: центр жалоб - 403', $http($user, 'GET', '/forum/reports.php')[0] === 403);
    t('пользователь: выдача предупреждения - 403', $http($user, 'GET', '/forum/warn.php?user=2')[0] === 403);
    t('пользователь: история чужого сообщения - 403', $http($user, 'GET', '/forum/post-history.php?id=1')[0] === 403);
    $mod = $login('Diego_Bacardi', 'test12345');
    t('модератор: центр жалоб открывается', $http($mod, 'GET', '/forum/reports.php')[0] === 200);
    t('модератор: предупреждение администратору - 403', $http($mod, 'GET', '/forum/warn.php?user=1')[0] === 403);
    list(, $page) = $http($mod, 'GET', '/forum/reports.php');
    preg_match('~name="csrf-token" content="([a-f0-9]+)"~', $page, $m);
    t('модератор: POST предупреждения администратору - 403', $http($mod, 'POST', '/forum/warn.php', ['user' => 1, 'type_id' => 1, '_token' => $m[1] ?? ''])[0] === 403);
    t('модератор: IP-адреса - 403', $http($mod, 'GET', '/admin/ips.php')[0] === 403);
    t('модератор: ЛС без жалобы - 403', $http($mod, 'GET', '/forum/warn.php?type=message&id=11')[0] === 403);
    foreach ([$guest, $user, $mod] as $jar) {
        @unlink($jar);
    }
}

echo "\n" . ($fails ? "FAILED: $fails из $total\n" : "ALL OK ($total)\n");
exit($fails ? 1 : 0);
