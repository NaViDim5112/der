<?php
// Применить новые обновления базы: php tools/migrate.php
if (PHP_SAPI !== 'cli') {
    exit;
}
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';
if (empty($GLOBALS['wrp_config']['installed'])) {
    fwrite(STDERR, "Сайт ещё не установлен.\n");
    exit(1);
}
try {
    $done = migrations_run(db());
    echo $done ? "Применено: " . implode(', ', $done) . "\n" : "Новых обновлений нет.\n";
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
