<?php
// Фоновые задачи сайта: php tools/cron.php
// В OpenServer или Планировщике Windows можно запускать раз в 5-10 минут.
// Без этого задачи всё равно идут сами, когда на сайт заходят посетители.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';
if (empty($GLOBALS['wrp_config']['installed'])) {
    fwrite(STDERR, "Сайт ещё не установлен.\n");
    exit(1);
}
$force = in_array('--all', $argv, true);
foreach (cron_run_due($force) as $name => $status) {
    echo $name . ': ' . $status . "\n";
}
