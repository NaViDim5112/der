<?php
// Веб-установщик World Role Play: проверка сервера, создание базы, таблиц, администратора и config/config.php.
// После установки папку public/install нужно удалить.
define('WRP_INSTALLER', true);
require __DIR__ . '/../../app/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');

$configFile = WRP_ROOT . '/config/config.php';
$lockFile = WRP_STORAGE . '/installed.lock';

// ---------- Функции установщика ----------

// Часть адреса до /install: '/wrp/public' для http://localhost/wrp/public/install/
function site_install_url_prefix()
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (preg_match('~^(.*)/install(?:/[^/]*)?$~', $path, $m)) {
        return rtrim($m[1], '/');
    }
    return '';
}

// Работает ли mod_rewrite с нашими .htaccess (public/.htaccess выставляет переменную WRP_REWRITE)
function site_install_has_rewrite()
{
    foreach (['WRP_REWRITE', 'REDIRECT_WRP_REWRITE', 'REDIRECT_REDIRECT_WRP_REWRITE'] as $k) {
        if (!empty($_SERVER[$k]) || getenv($k)) {
            return true;
        }
    }
    return false;
}

// base_path для config.php: адрес без /install и без /public, если работает корневой .htaccess
function site_install_detect_base()
{
    $base = site_install_url_prefix();
    if (substr($base, -7) === '/public' && site_install_has_rewrite()) {
        $base = substr($base, 0, -7);
    }
    return $base;
}

// Папка доступна для записи (если её нет - пробуем создать)
function site_install_writable($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return is_dir($dir) && is_writable($dir);
}

// Проверка требований: [['Название', 'ok' | 'warn' | 'fail', 'пояснение'], ...]
function site_install_requirements()
{
    $r = [];
    $r[] = ['PHP 7.4 или новее', version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'fail', 'Установлена версия ' . PHP_VERSION];
    $r[] = ['Расширение pdo_mysql', extension_loaded('pdo_mysql') ? 'ok' : 'fail', 'Подключение к MySQL / MariaDB'];
    $r[] = ['Расширение mbstring', extension_loaded('mbstring') ? 'ok' : 'fail', 'Работа с русским текстом'];
    $r[] = ['JSON', function_exists('json_encode') ? 'ok' : 'fail', 'Обмен данными и кэш'];
    $r[] = ['Случайные числа (random_bytes или openssl)', (function_exists('random_bytes') || extension_loaded('openssl')) ? 'ok' : 'fail', 'Пароли, токены и ключи'];
    $r[] = ['Расширение GD', extension_loaded('gd') ? 'ok' : 'warn', extension_loaded('gd') ? 'Обработка аватаров' : 'Без GD не будут уменьшаться загруженные аватары'];
    $configDir = WRP_ROOT . '/config';
    $cfgOk = is_file($configDir . '/config.php') ? is_writable($configDir . '/config.php') : is_writable($configDir);
    $r[] = ['Запись в config/', $cfgOk ? 'ok' : 'fail', 'Сюда будет записан config.php'];
    $r[] = ['Запись в storage/', site_install_writable(WRP_STORAGE) ? 'ok' : 'fail', 'Файл installed.lock'];
    $r[] = ['Запись в storage/cache', site_install_writable(WRP_STORAGE . '/cache') ? 'ok' : 'fail', 'Кэш статуса сервера'];
    $r[] = ['Запись в storage/logs', site_install_writable(WRP_STORAGE . '/logs') ? 'ok' : 'fail', 'Журнал ошибок'];
    $r[] = ['Запись в public/uploads/avatars', site_install_writable(WRP_PUBLIC . '/uploads/avatars') ? 'ok' : 'fail', 'Аватары пользователей'];
    $r[] = ['Запись в public/uploads/covers', site_install_writable(WRP_PUBLIC . '/uploads/covers') ? 'ok' : 'warn', 'Обложки профилей'];
    return $r;
}

// Разбивка SQL-файла на запросы: запрос заканчивается «;» в конце строки
function site_install_sql_statements($file)
{
    $sql = @file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Не удалось прочитать файл ' . basename($file) . '.');
    }
    $sql = preg_replace('~^\xEF\xBB\xBF~', '', $sql);
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $out = [];
    $buf = '';
    foreach (explode("\n", $sql) as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '--') === 0 || strpos($t, '#') === 0) {
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

// Понятный текст ошибки подключения (без паролей)
function site_install_db_error(PDOException $e, $host, $port)
{
    $msg = $e->getMessage();
    $code = (int)($e->errorInfo[1] ?? 0);
    if (!$code && preg_match('~\[(\d{4})\]~', $msg, $m)) {
        $code = (int)$m[1];
    }
    if ($code === 1045) {
        return 'MySQL отклонил вход: неверный пользователь или пароль.';
    }
    if ($code === 2002 || $code === 2003 || $code === 2005 || $code === 2006) {
        return 'Не удалось подключиться к MySQL по адресу ' . $host . ':' . $port . '. Проверьте, что модуль MySQL / MariaDB запущен, и адрес указан верно.';
    }
    if ($code === 1049) {
        return 'Базы данных с таким именем нет. Отметьте «Создать базу, если её нет».';
    }
    if ($code === 1044 || $code === 1142) {
        return 'У пользователя MySQL нет прав на эту базу или на создание таблиц.';
    }
    if ($code === 1007) {
        return 'База уже существует.';
    }
    // в сообщении PDO пароля нет, но на всякий случай убираем кавычки с данными входа
    return 'Ошибка MySQL: ' . preg_replace("~'[^']*'@'[^']*'~", '***', $msg);
}

function site_install_pdo($host, $port, $user, $pass, $dbname = null)
{
    $dsn = 'mysql:host=' . $host . ';port=' . (int)$port . ($dbname !== null ? ';dbname=' . $dbname : '') . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function site_install_random_hex($bytes)
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    return bin2hex(openssl_random_pseudo_bytes($bytes));
}

// ---------- Уже установлено? ----------

$prefix = site_install_url_prefix();
$assetUrl = function ($path) use ($prefix) {
    $file = WRP_PUBLIC . '/assets/' . $path;
    return $prefix . '/assets/' . $path . '?v=' . (is_file($file) ? filemtime($file) : 0);
};

$alreadyInstalled = is_file($lockFile) || (is_file($configFile) && !empty($GLOBALS['wrp_config']['installed']));

// Сессия только для защиты формы (CSRF)
session_name('wrp_install');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => ($prefix !== '' ? $prefix : '') . '/install/',
    'secure' => request_is_https(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (!$alreadyInstalled) {
    session_start();
}

// ---------- Обработка формы ----------

$reqs = $alreadyInstalled ? [] : site_install_requirements();
$reqFail = false;
foreach ($reqs as $r) {
    if ($r[1] === 'fail') {
        $reqFail = true;
    }
}

$defaults = [
    'db_host' => '127.0.1.19',
    'db_port' => '3306',
    'db_name' => 'wrp_site',
    'db_user' => 'root',
    'db_create' => '1',
    'site_name' => 'World Role Play',
    'site_subtitle' => 'Los Santos',
    'server_ip' => '127.0.0.1',
    'server_port' => '7777',
    'base_path' => site_install_detect_base(),
    'admin_name' => '',
    'admin_email' => '',
];
$f = $defaults;
$errors = [];
$fatal = '';
$done = null;

if (!$alreadyInstalled && is_post()) {
    foreach ($defaults as $k => $v) {
        $f[$k] = $k === 'db_create' ? (input_bool('db_create') ? '1' : '') : input($k);
    }
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');

    $sent = $_POST['_token'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        $fatal = 'Страница устарела. Проверьте данные и отправьте форму ещё раз.';
    } elseif ($reqFail) {
        $fatal = 'Сервер не прошёл проверку. Исправьте отмеченные пункты и обновите страницу.';
    } else {
        // Проверка полей
        if (!preg_match('~^[A-Za-z0-9.\-_]{1,255}$~', $f['db_host'])) {
            $errors['db_host'] = 'Укажите адрес сервера MySQL, например 127.0.0.1.';
        }
        if (!ctype_digit($f['db_port']) || (int)$f['db_port'] < 1 || (int)$f['db_port'] > 65535) {
            $errors['db_port'] = 'Порт - число от 1 до 65535.';
        }
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $f['db_name'])) {
            $errors['db_name'] = 'Только латинские буквы, цифры и _ (до 64 символов).';
        }
        if ($f['db_user'] === '' || mb_strlen($f['db_user']) > 80) {
            $errors['db_user'] = 'Укажите пользователя MySQL.';
        }
        if ($f['site_name'] === '' || mb_strlen($f['site_name']) > 64) {
            $errors['site_name'] = 'Название сайта - от 1 до 64 символов.';
        }
        if (mb_strlen($f['site_subtitle']) > 64) {
            $errors['site_subtitle'] = 'Не длиннее 64 символов.';
        }
        if (!preg_match('~^[A-Za-z0-9.\-]{1,253}$~', $f['server_ip'])) {
            $errors['server_ip'] = 'IP-адрес или домен игрового сервера.';
        }
        if (!ctype_digit($f['server_port']) || (int)$f['server_port'] < 1 || (int)$f['server_port'] > 65535) {
            $errors['server_port'] = 'Порт - число от 1 до 65535.';
        }
        $f['base_path'] = rtrim($f['base_path'], '/');
        if ($f['base_path'] !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $f['base_path'])) {
            $errors['base_path'] = 'Путь начинается с / и состоит из латиницы, цифр, точек, - и _. Пусто - сайт в корне домена.';
        }
        if (!username_valid($f['admin_name'])) {
            $errors['admin_name'] = 'От 3 до 24 символов: буквы, цифры, пробел, _ - .';
        }
        if (!filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($f['admin_email']) > 191) {
            $errors['admin_email'] = 'Укажите корректный email.';
        }
        if (mb_strlen($adminPass) < 8) {
            $errors['admin_pass'] = 'Пароль - не короче 8 символов.';
        } elseif ($adminPass !== $adminPass2) {
            $errors['admin_pass2'] = 'Пароли не совпадают.';
        }
    }

    if ($fatal === '' && !$errors) {
        $pdo = null;
        $createdDb = false;
        $tablesBefore = [];
        $schemaRan = false;
        $dbName = $f['db_name'];
        try {
            $pdo = site_install_pdo($f['db_host'], $f['db_port'], $f['db_user'], $dbPass);
            $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $exists->execute([$dbName]);
            $dbExists = (bool)$exists->fetchColumn();
            if (!$dbExists) {
                if (!$f['db_create']) {
                    throw new RuntimeException('Базы «' . $dbName . '» нет. Создайте её или отметьте «Создать базу, если её нет».');
                }
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $createdDb = true;
            }
            $pdo = site_install_pdo($f['db_host'], $f['db_port'], $f['db_user'], $dbPass, $dbName);
            $tablesBefore = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('users', array_map('strtolower', $tablesBefore), true)) {
                throw new RuntimeException('В базе «' . $dbName . '» уже есть таблицы сайта (users). База не пустая - выберите пустую базу или удалите таблицы.');
            }

            $schemaRan = true;
            foreach (['schema.sql', 'seed.sql'] as $file) {
                foreach (site_install_sql_statements(WRP_ROOT . '/sql/' . $file) as $stmt) {
                    $pdo->exec($stmt);
                }
            }
            // Обновления базы (sql/migrations): новые таблицы и данные модулей
            migrations_run($pdo);

            $now = date('Y-m-d H:i:s');
            $st = $pdo->prepare('INSERT INTO `users` (`username`, `email`, `password_hash`, `group_id`, `created_at`) VALUES (?, ?, ?, 10, ?)');
            $st->execute([$f['admin_name'], $f['admin_email'], password_hash($adminPass, PASSWORD_DEFAULT), $now]);

            $settings = [
                'site_name' => $f['site_name'],
                'site_subtitle' => $f['site_subtitle'],
                'server_ip' => $f['server_ip'],
                'server_port' => $f['server_port'],
                'server_name' => $f['site_name'] . ($f['site_subtitle'] !== '' ? ' | ' . $f['site_subtitle'] : ''),
                'forum_title' => 'Форум - ' . $f['site_name'],
            ];
            $st = $pdo->prepare('INSERT INTO `settings` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)');
            foreach ($settings as $k => $v) {
                $st->execute([$k, $v]);
            }

            // config/config.php по образцу config.example.php
            $example = require WRP_ROOT . '/config/config.example.php';
            $config = is_array($example) ? $example : [];
            $config['installed'] = true;
            $config['db'] = [
                'host' => $f['db_host'],
                'port' => (int)$f['db_port'],
                'name' => $dbName,
                'user' => $f['db_user'],
                'pass' => $dbPass,
                'charset' => 'utf8mb4',
            ];
            $config['base_path'] = $f['base_path'];
            $config['secret'] = site_install_random_hex(32);
            $config['timezone'] = 'Europe/Moscow';
            $config['debug'] = false;
            $game = isset($example['game_db']) && is_array($example['game_db']) ? $example['game_db'] : [];
            $game['enabled'] = false;
            $config['game_db'] = $game;
            $php = "<?php\n// Настройки сайта " . str_replace(["\n", "\r", '*/', '?>'], ' ', $f['site_name']) . ". Создано установщиком " . date('d.m.Y H:i') . ".\n"
                . "// Здесь пароль от базы данных - не публикуйте этот файл.\n\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
                throw new RuntimeException('Не удалось записать config/config.php. Проверьте права на папку config.');
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($configFile, true);
            }
            $lockOk = @file_put_contents($lockFile, 'installed ' . date('c') . "\n", LOCK_EX) !== false;

            $base = $f['base_path'];
            $done = [
                'site' => $base . '/',
                'forum' => $base . '/forum/',
                'admin' => $base . '/admin/',
                'login' => $base . '/forum/login.php',
                'admin_name' => $f['admin_name'],
                'db' => $dbName,
                'lock' => $lockOk,
            ];
            $_SESSION = [];
        } catch (Exception $e) {
            $fatal = $e instanceof PDOException ? site_install_db_error($e, $f['db_host'], $f['db_port']) : $e->getMessage();
            // Откат: удаляем то, что успели создать
            if ($pdo && $schemaRan) {
                try {
                    if ($createdDb) {
                        $pdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
                    } else {
                        // Удаляем все таблицы, которых не было до установки (схема и обновления)
                        $before = array_map('strtolower', $tablesBefore);
                        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
                            if (!in_array(strtolower($t), $before, true) && preg_match('~^[A-Za-z0-9_]+$~', $t)) {
                                $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
                            }
                        }
                    }
                } catch (Exception $e2) {
                    $fatal .= ' Не удалось убрать созданные таблицы - очистите базу вручную.';
                }
            } elseif ($pdo && $createdDb) {
                try {
                    $pdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
                } catch (Exception $e2) {
                }
            }
        }
    }
}

$err = function ($key) use ($errors) {
    return isset($errors[$key]) ? '<div class="field-error">' . e($errors[$key]) . '</div>' : '';
};
$inv = function ($key) use ($errors) {
    return isset($errors[$key]) ? ' is-invalid' : '';
};
$step = $alreadyInstalled ? 3 : ($done ? 3 : 2);
?>
<!doctype html>
<html lang="ru" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Установка World Role Play</title>
<link rel="icon" href="<?= e($assetUrl('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($assetUrl('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e($assetUrl('css/install.css')) ?>">
</head>
<body class="install">
<div class="bg-glow" aria-hidden="true"></div>
<main class="install-wrap">
  <header class="install-head">
    <img src="<?= e($assetUrl('img/logo.svg')) ?>" alt="" width="56" height="56">
    <div>
      <h1>Установка World Role Play</h1>
      <p>Сайт, форум, база знаний и личный кабинет игрового сервера</p>
    </div>
  </header>

  <ol class="install-steps" aria-label="Шаги установки">
    <li class="<?= $step > 1 ? 'done' : 'active' ?>"><span>1</span>Проверка</li>
    <li class="<?= $step > 2 ? 'done' : ($step === 2 ? 'active' : '') ?>"><span>2</span>Настройка</li>
    <li class="<?= $step === 3 ? 'active' : '' ?>"><span>3</span>Готово</li>
  </ol>

<?php if ($alreadyInstalled): ?>
  <section class="install-card install-result">
    <div class="result-icon result-ok"><?= icon('check') ?></div>
    <h2>Сайт уже установлен</h2>
    <p>Повторная установка отключена, чтобы никто не смог перезаписать настройки и базу.</p>
    <div class="install-actions">
      <a class="btn btn-accent btn-pill btn-lg" href="<?= e(rtrim((string)cfg('base_path', ''), '/') . '/') ?>"><?= icon('home') ?> Открыть сайт</a>
    </div>
    <div class="install-warning"><?= icon('alert') ?><div><b>Удалите папку <code>public/install</code></b> с сервера - после установки она больше не нужна. Чтобы установить сайт заново, удалите <code>config/config.php</code> и <code>storage/installed.lock</code>.</div></div>
  </section>

<?php elseif ($done): ?>
  <section class="install-card install-result">
    <div class="result-icon result-ok"><?= icon('check') ?></div>
    <h2>Готово! Сайт установлен</h2>
    <p>Таблицы созданы в базе <b><?= e($done['db']) ?></b>, администратор <b><?= e($done['admin_name']) ?></b> добавлен, настройки записаны в <code>config/config.php</code>.</p>
    <div class="install-links">
      <a href="<?= e($done['site']) ?>"><?= icon('home') ?><span><b>Сайт</b><small><?= e($done['site']) ?></small></span></a>
      <a href="<?= e($done['forum']) ?>"><?= icon('chats') ?><span><b>Форум</b><small><?= e($done['forum']) ?></small></span></a>
      <a href="<?= e($done['admin']) ?>"><?= icon('shield') ?><span><b>Админ-панель</b><small>вход под <?= e($done['admin_name']) ?></small></span></a>
    </div>
    <div class="install-warning install-warning-big"><?= icon('alert') ?><div><b>Обязательно удалите папку <code>public/install</code></b>. Пока она есть на сервере, это лишний риск. Дальше всё настраивается в админ-панели: адрес сервера, ссылки на соцсети и лаунчер, разделы форума.</div></div>
    <?php if (!$done['lock']): ?>
    <div class="flash flash-error"><?= icon('alert') ?><div>Не удалось создать <code>storage/installed.lock</code>. Сайт работает, но проверьте права на папку storage.</div></div>
    <?php endif; ?>
  </section>

<?php else: ?>
  <?php if ($fatal !== ''): ?>
  <div class="flash flash-error install-flash" role="alert"><?= icon('alert') ?><div><b>Установка не выполнена.</b> <?= e($fatal) ?></div></div>
  <?php elseif ($errors): ?>
  <div class="flash flash-error install-flash" role="alert"><?= icon('alert') ?><div>Исправьте поля, отмеченные красным.</div></div>
  <?php endif; ?>

  <section class="install-card">
    <div class="install-card-head">
      <h2><span class="step-badge">1</span> Проверка сервера</h2>
      <?php if ($reqFail): ?><span class="req-summary fail">Есть проблемы</span><?php else: ?><span class="req-summary ok">Всё в порядке</span><?php endif; ?>
    </div>
    <details class="req-details"<?= $reqFail ? ' open' : '' ?>>
      <summary>Показать <?= count($reqs) ?> <?= e(plural(count($reqs), 'пункт', 'пункта', 'пунктов')) ?> проверки</summary>
      <table class="req-table">
        <?php foreach ($reqs as $r): ?>
        <tr class="req-<?= e($r[1]) ?>">
          <td><span class="req-dot"><?= icon($r[1] === 'ok' ? 'check' : ($r[1] === 'warn' ? 'alert' : 'x')) ?></span><?= e($r[0]) ?></td>
          <td class="muted"><?= e($r[2]) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </details>
    <?php if ($reqFail): ?>
    <div class="install-warning"><?= icon('alert') ?><div>Исправьте пункты с красной отметкой и обновите страницу. В OpenServer расширения PHP включаются в настройках модуля PHP, права на папки обычно уже есть.</div></div>
    <?php endif; ?>
  </section>

  <form method="post" class="install-form" action="<?= e($prefix . '/install/') ?>" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <section class="install-card">
      <div class="install-card-head"><h2><span class="step-badge">2</span> База данных</h2></div>
      <div class="install-grid">
        <div class="form-row">
          <label class="label" for="db_host">Адрес MySQL / MariaDB</label>
          <input class="input<?= $inv('db_host') ?>" id="db_host" name="db_host" value="<?= e($f['db_host']) ?>" required>
          <div class="hint">Адрес модуля MariaDB/MySQL в OpenServer 6 (обычно 127.0.1.x, смотрите в настройках модуля); в OpenServer 5 - 127.0.0.1.</div>
          <?= $err('db_host') ?>
        </div>
        <div class="form-row">
          <label class="label" for="db_port">Порт</label>
          <input class="input<?= $inv('db_port') ?>" id="db_port" name="db_port" value="<?= e($f['db_port']) ?>" inputmode="numeric" required>
          <?= $err('db_port') ?>
        </div>
        <div class="form-row">
          <label class="label" for="db_name">Имя базы</label>
          <input class="input<?= $inv('db_name') ?>" id="db_name" name="db_name" value="<?= e($f['db_name']) ?>" required>
          <div class="hint">Отдельная база сайта, игровую базу worldrp не трогает.</div>
          <?= $err('db_name') ?>
        </div>
        <div class="form-row">
          <label class="label" for="db_user">Пользователь</label>
          <input class="input<?= $inv('db_user') ?>" id="db_user" name="db_user" value="<?= e($f['db_user']) ?>" required>
          <?= $err('db_user') ?>
        </div>
        <div class="form-row">
          <label class="label" for="db_pass">Пароль</label>
          <input class="input" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
          <div class="hint">В OpenServer по умолчанию пользователь root без пароля, если вы его не меняли.<?= is_post() ? ' Введите пароль ещё раз.' : '' ?></div>
        </div>
        <div class="form-row form-row-check">
          <label class="check"><input type="checkbox" name="db_create" value="1"<?= $f['db_create'] ? ' checked' : '' ?>><span>Создать базу, если её нет</span></label>
          <div class="hint">Кодировка utf8mb4. В базе не должно быть таблиц сайта.</div>
        </div>
      </div>
    </section>

    <section class="install-card">
      <div class="install-card-head"><h2><span class="step-badge">3</span> Сайт и игровой сервер</h2></div>
      <div class="install-grid">
        <div class="form-row">
          <label class="label" for="site_name">Название</label>
          <input class="input<?= $inv('site_name') ?>" id="site_name" name="site_name" value="<?= e($f['site_name']) ?>" maxlength="64" required>
          <?= $err('site_name') ?>
        </div>
        <div class="form-row">
          <label class="label" for="site_subtitle">Подзаголовок</label>
          <input class="input<?= $inv('site_subtitle') ?>" id="site_subtitle" name="site_subtitle" value="<?= e($f['site_subtitle']) ?>" maxlength="64">
          <?= $err('site_subtitle') ?>
        </div>
        <div class="form-row">
          <label class="label" for="server_ip">IP игрового сервера</label>
          <input class="input<?= $inv('server_ip') ?>" id="server_ip" name="server_ip" value="<?= e($f['server_ip']) ?>" required>
          <?= $err('server_ip') ?>
        </div>
        <div class="form-row">
          <label class="label" for="server_port">Порт игрового сервера</label>
          <input class="input<?= $inv('server_port') ?>" id="server_port" name="server_port" value="<?= e($f['server_port']) ?>" inputmode="numeric" required>
          <?= $err('server_port') ?>
        </div>
        <div class="form-row form-row-wide">
          <label class="label" for="base_path">Путь сайта (base_path)</label>
          <input class="input<?= $inv('base_path') ?>" id="base_path" name="base_path" value="<?= e($f['base_path']) ?>" placeholder="пусто - сайт в корне домена">
          <div class="hint">Определён по адресу этой страницы. Пусто, если сайт открывается как <code>http://wrp.local/</code>. Для подпапки укажите её, например <code>/wrp</code>. Если домен смотрит в корень проекта и работает корневой .htaccess, <code>/public</code> в пути не нужен; без mod_rewrite оставьте <code>/public</code> в конце.</div>
          <?= $err('base_path') ?>
        </div>
      </div>
    </section>

    <section class="install-card">
      <div class="install-card-head"><h2><span class="step-badge">4</span> Администратор</h2></div>
      <div class="install-grid">
        <div class="form-row">
          <label class="label" for="admin_name">Имя пользователя</label>
          <input class="input<?= $inv('admin_name') ?>" id="admin_name" name="admin_name" value="<?= e($f['admin_name']) ?>" maxlength="24" autocomplete="off" required>
          <?= $err('admin_name') ?>
        </div>
        <div class="form-row">
          <label class="label" for="admin_email">Email</label>
          <input class="input<?= $inv('admin_email') ?>" id="admin_email" name="admin_email" type="email" value="<?= e($f['admin_email']) ?>" maxlength="191" required>
          <?= $err('admin_email') ?>
        </div>
        <div class="form-row">
          <label class="label" for="admin_pass">Пароль</label>
          <input class="input<?= $inv('admin_pass') ?>" id="admin_pass" name="admin_pass" type="password" minlength="8" autocomplete="new-password" required>
          <div class="hint">Не короче 8 символов.</div>
          <?= $err('admin_pass') ?>
        </div>
        <div class="form-row">
          <label class="label" for="admin_pass2">Пароль ещё раз</label>
          <input class="input<?= $inv('admin_pass2') ?>" id="admin_pass2" name="admin_pass2" type="password" minlength="8" autocomplete="new-password" required>
          <?= $err('admin_pass2') ?>
        </div>
      </div>
    </section>

    <div class="install-submit">
      <button class="btn btn-accent btn-pill btn-lg" type="submit"<?= $reqFail ? ' disabled' : '' ?>><?= icon('download') ?> Установить</button>
      <span class="muted small">Будут созданы таблицы, администратор и файл config/config.php.</span>
    </div>
  </form>
<?php endif; ?>

  <footer class="install-foot">World Role Play · установщик сайта</footer>
</main>
</body>
</html>
