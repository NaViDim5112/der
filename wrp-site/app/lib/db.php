<?php
// Работа с базой: тонкая обёртка над PDO. Все запросы только с параметрами.

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=' . ($c['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function db_stmt($sql, array $params = [])
{
    $st = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $key = is_int($k) ? $k + 1 : (strpos($k, ':') === 0 ? $k : ':' . $k);
        if (is_int($v)) {
            $st->bindValue($key, $v, PDO::PARAM_INT);
        } elseif (is_bool($v)) {
            $st->bindValue($key, $v ? 1 : 0, PDO::PARAM_INT);
        } elseif ($v === null) {
            $st->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $st->bindValue($key, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->execute();
    return $st;
}

// Все строки
function db_all($sql, array $params = [])
{
    return db_stmt($sql, $params)->fetchAll();
}

// Одна строка или null
function db_one($sql, array $params = [])
{
    $row = db_stmt($sql, $params)->fetch();
    return $row === false ? null : $row;
}

// Одно значение или null
function db_val($sql, array $params = [])
{
    $v = db_stmt($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

// Выполнить запрос, вернуть число затронутых строк
function db_exec($sql, array $params = [])
{
    return db_stmt($sql, $params)->rowCount();
}

// Вставка: db_insert('posts', ['thread_id' => 1, ...]) -> id
function db_insert($table, array $data)
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')';
    db_stmt($sql, $data);
    return (int)db()->lastInsertId();
}

// Обновление: db_update('users', ['avatar' => 'x.png'], 'id = :id', ['id' => 5])
function db_update($table, array $data, $where, array $whereParams = [])
{
    $set = [];
    $params = [];
    foreach ($data as $k => $v) {
        $set[] = '`' . $k . '` = :set_' . $k;
        $params['set_' . $k] = $v;
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return db_exec($sql, array_merge($params, $whereParams));
}

// Плейсхолдеры для IN (...): db_in([1,2,3], 'id') -> ['sql' => ':id0, :id1, :id2', 'params' => [...]]
function db_in(array $values, $prefix = 'in')
{
    $ph = [];
    $params = [];
    $i = 0;
    foreach ($values as $v) {
        $ph[] = ':' . $prefix . $i;
        $params[$prefix . $i] = $v;
        $i++;
    }
    if (!$ph) {
        return ['sql' => 'NULL', 'params' => []];
    }
    return ['sql' => implode(', ', $ph), 'params' => $params];
}
