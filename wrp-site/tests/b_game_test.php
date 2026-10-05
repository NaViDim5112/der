<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка игровых функций на своей тестовой базе (не трогает config.php):
//   php tests/b_game_seed.php | mysql --default-character-set=cp1251 wrp_game_test
//   php tests/b_game_test.php
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$GLOBALS['wrp_config']['game_db'] = array_merge((array)cfg('game_db'), [
    'enabled' => true,
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => getenv('GAME_DB') ?: 'wrp_game_test',
    'user' => 'wrp_game',
    'pass' => 'wrp_game',
    'charset' => 'cp1251',
    'table' => 'accounts',
    'col_name' => 'name',
    'col_pass' => 'password',
    'col_salt' => 'salt',
    'hash' => 'auto',
    'stats' => ['level' => 'Уровень', 'money' => 'Наличные', 'bank' => 'Банк', 'city' => 'Город'],
]);

$fails = 0;
$total = 0;
function t($name, $cond)
{
    global $fails, $total;
    $total++;
    if (!$cond) {
        $fails++;
    }
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n";
}
function mode($m)
{
    $GLOBALS['wrp_config']['game_db']['hash'] = $m;
}

t('game_enabled() with override', game_enabled());

// auto: bcrypt и SHA256 с солью
mode('auto');
t('auto/bcrypt: right password', game_check_password('John_Smith', 'gamepass1'));
t('auto/bcrypt: wrong password', !game_check_password('John_Smith', 'gamepass2'));
t('auto/sha256_salt: right password', game_check_password('Old_Player', 'oldpass1'));
t('auto/sha256_salt: wrong password', !game_check_password('Old_Player', 'oldpass'));
t('auto/sha256 without salt', game_check_password('Sha_Player', 'shapass1'));
t('auto: cyrillic password via cp1251', game_check_password('Ivan_Petrov', 'пароль123'));
t('auto: cyrillic wrong password', !game_check_password('Ivan_Petrov', 'пароль124'));
t('unknown account', !game_check_password('No_Such_Player', 'x'));
t('nick lookup is case-insensitive (MySQL collation)', game_check_password('john_smith', 'gamepass1'));

// Явные режимы
mode('bcrypt');
t('bcrypt mode', game_check_password('John_Smith', 'gamepass1'));
mode('sha256_salt');
t('sha256_salt mode (uppercase hash)', game_check_password('Old_Player', 'oldpass1'));
mode('whirlpool');
t('whirlpool mode (uppercase hash)', game_check_password('Wp_Player', 'wppass1') && !game_check_password('Wp_Player', 'wppass2'));
mode('md5');
t('md5 mode', game_check_password('Md5_Player', 'md5pass1') && !game_check_password('Md5_Player', 'md5pass2'));
mode('sha1');
t('sha1 mode', game_check_password('Sha1_Player', 'sha1pass1'));
mode('plain');
t('plain mode', game_check_password('Plain_Player', 'plainpass1') && !game_check_password('Plain_Player', 'plainpass'));
t('plain mode: empty stored never matches', !game_check_password('Plain_Player', ''));
mode('auto');

// Статистика и перекодировка
$st = game_stats('Ivan_Petrov');
$map = [];
foreach ((array)$st as $s) {
    $map[$s['label']] = $s['value'];
}
t('stats: labels only from cfg(game_db.stats)', array_keys($map) === ['Уровень', 'Наличные', 'Банк', 'Город']);
t('stats: number formatting', ($map['Наличные'] ?? '') === '7 304 688');
t('stats: cp1251 text converted to UTF-8', ($map['Город'] ?? '') === 'Лас-Вентурас');
$acc = game_account('ivan_petrov');
t('canonical nick from game DB', ($acc['name'] ?? '') === 'Ivan_Petrov');

// Обёртки из accounts.php
list($r, $err) = acc_game_call('game_check_password', ['John_Smith', 'gamepass1']);
t('acc_game_call: result passes through', $r === true && $err === '');
t('acc_game_nick_valid', acc_game_nick_valid('John_Smith') && acc_game_nick_valid('[ADM]Nick') && !acc_game_nick_valid('Иван_Петров') && !acc_game_nick_valid('a b') && !acc_game_nick_valid('ab'));

// Недоступная база: ошибка ловится, страница не падает
$GLOBALS['wrp_config']['game_db']['table'] = 'bad-table;';
list($r, $err) = acc_game_call('game_account', ['John_Smith']);
t('acc_game_call: bad config caught', $r === null && $err !== '');

echo "\n" . ($total - $fails) . '/' . $total . " passed\n";
exit($fails ? 1 : 0);
