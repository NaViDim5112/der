<?php
// SQL с тестовыми игровыми аккаунтами (cp1251), для своей тестовой базы:
//   php tests/b_game_seed.php | mysql --default-character-set=cp1251 wrp_game_test
$cp = function ($s) {
    return mb_convert_encoding($s, 'Windows-1251', 'UTF-8');
};
$q = function ($s) {
    return "'" . addslashes($s) . "'";
};
$rows = [
    // ник, пароль в базе, соль, уровень, деньги, банк, город
    ['John_Smith', password_hash('gamepass1', PASSWORD_BCRYPT), '', 12, 150000, 2500000, 'Лос-Сантос'],
    ['Old_Player', strtoupper(hash('sha256', 'oldpass1' . 'S4lt!x')), 'S4lt!x', 3, 900, 0, 'Сан-Фиерро'],
    ['Ivan_Petrov', password_hash($cp('пароль123'), PASSWORD_BCRYPT), '', 25, 7304688, 99000000, 'Лас-Вентурас'],
    ['Taken_Nick', password_hash('gamepass1', PASSWORD_BCRYPT), '', 5, 10, 20, ''],
    ['Wp_Player', strtoupper(hash('whirlpool', 'wppass1')), '', 1, 0, 0, ''],
    ['Md5_Player', md5('md5pass1'), '', 1, 0, 0, ''],
    ['Sha1_Player', sha1('sha1pass1'), '', 1, 0, 0, ''],
    ['Plain_Player', 'plainpass1', '', 1, 0, 0, ''],
    ['Sha_Player', hash('sha256', 'shapass1'), '', 1, 0, 0, ''],
];
echo "SET NAMES cp1251;\nDELETE FROM accounts;\n";
foreach ($rows as $r) {
    echo 'INSERT INTO accounts (name, password, salt, level, money, bank, city) VALUES ('
        . $q($r[0]) . ', ' . $q($r[1]) . ', ' . $q($r[2]) . ', ' . (int)$r[3] . ', ' . (int)$r[4] . ', ' . (int)$r[5] . ', ' . $q($cp($r[6])) . ");\n";
}
