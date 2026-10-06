<?php
// Обновления базы сайта: файлы sql/migrations/NNNN_имя.sql.
// Установщик применяет их сразу после schema.sql и seed.sql, на работающем сайте -
// кнопка «Обновить базу» в админ-панели или консоль: php tools/migrate.php
//
// Правила для файла обновления:
// - номер по порядку, применённые файлы не менять (новые изменения - новым файлом);
// - каждый запрос заканчивается «;» в конце строки, комментарии - строками с «--»;
// - данные по умолчанию вставлять через INSERT IGNORE / UPDATE, чтобы файл не ломался,
//   если такие строки уже есть.

function migrations_dir()
{
    return WRP_ROOT . '/sql/migrations';
}

// [имя файла => путь], по порядку
function migrations_all()
{
    $out = [];
    foreach (glob(migrations_dir() . '/*.sql') ?: [] as $file) {
        $out[basename($file)] = $file;
    }
    ksort($out, SORT_STRING);
    return $out;
}

function migrations_table(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `name` VARCHAR(128) NOT NULL,
        `applied_at` DATETIME NOT NULL,
        PRIMARY KEY (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function migrations_applied(PDO $pdo)
{
    migrations_table($pdo);
    return $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
}

function migrations_pending(PDO $pdo)
{
    $applied = array_flip(migrations_applied($pdo));
    $out = [];
    foreach (migrations_all() as $name => $file) {
        if (!isset($applied[$name])) {
            $out[$name] = $file;
        }
    }
    return $out;
}

// Разбивает SQL-текст на запросы: запрос кончается «;» в конце строки
function sql_statements($sql)
{
    $sql = preg_replace('~^\xEF\xBB\xBF~', '', (string)$sql);
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $out = [];
    $buf = '';
    foreach (explode("\n", $sql) as $line) {
        $t = trim($line);
        if ($buf === '' && ($t === '' || strpos($t, '--') === 0 || strpos($t, '#') === 0)) {
            continue;
        }
        if ($t !== '' && strpos($t, '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $out[] = trim($buf);
            $buf = '';
        }
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

// Применяет все новые обновления. Возвращает список применённых. При ошибке - исключение с именем файла.
function migrations_run(PDO $pdo = null)
{
    $pdo = $pdo ?: db();
    $done = [];
    foreach (migrations_pending($pdo) as $name => $file) {
        $sql = @file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Не удалось прочитать обновление ' . $name);
        }
        $pdo->exec('SET NAMES utf8mb4');
        foreach (sql_statements($sql) as $i => $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // Повторный запуск после сбоя: «уже есть» не считается ошибкой
                // (1050 таблица, 1060 колонка, 1061 индекс, 1091 нечего удалять)
                $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
                if (in_array($code, [1050, 1060, 1061, 1091], true)) {
                    continue;
                }
                throw new RuntimeException('Обновление ' . $name . ', запрос ' . ($i + 1) . ': ' . $e->getMessage());
            }
        }
        $st = $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)');
        $st->execute([$name, date('Y-m-d H:i:s')]);
        $done[] = $name;
    }
    return $done;
}
