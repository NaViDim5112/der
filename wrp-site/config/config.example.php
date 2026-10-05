<?php
// Скопируйте этот файл в config.php и впишите свои данные.
// Установщик (/install/) создаёт config.php сам.

return [
    'installed' => false,

    // База данных сайта и форума (MySQL / MariaDB из OpenServer)
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'wrp_site',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Путь сайта от корня домена. Пусто, если сайт открывается как http://wrp.local/
    // Если сайт лежит в подпапке, например http://localhost/wrp/, укажите '/wrp'
    'base_path' => '',

    // Секрет для подписи cookie. Установщик генерирует случайный.
    'secret' => 'change-me',

    // Часовой пояс для дат на сайте
    'timezone' => 'Europe/Moscow',

    // Показывать ошибки PHP на экране (только для отладки на своём компьютере)
    'debug' => false,

    // Игровая база сервера (для кабинета: привязка игрового аккаунта и статистика).
    // По умолчанию выключено. Включите, когда впишете таблицу и колонки из мода.
    'game_db' => [
        'enabled'  => false,
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'samp',
        'user'     => 'root',
        'pass'     => '',
        'charset'  => 'utf8mb4',     // база мода в cp1251 - укажите 'cp1251', latin1-колонки с русским текстом - 'latin1'
        'table'    => 'accounts',
        'col_name' => 'Name',        // колонка с ником (Name_Surname)
        'col_pass' => 'Password',    // колонка с паролем или хэшем
        'col_salt' => '',            // колонка с солью, если есть
        // Как мод хранит пароль: bcrypt | sha256 | sha256_salt (SHA256_PassHash) | whirlpool (WP_Hash) | md5 | sha1 | plain
        'hash'     => 'bcrypt',
        // Какие колонки показать в кабинете: 'колонка' => 'Подпись'
        'stats' => [
            'Level' => 'Уровень',
            'Money' => 'Наличные',
            'Bank'  => 'Банк',
        ],
    ],
];
