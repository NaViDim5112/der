<?php
// Настройки сайта из таблицы settings (меняются в админке)

function settings_defaults()
{
    return [
        'site_name' => 'World Role Play',
        'site_subtitle' => 'Los Santos',
        'site_description' => 'Ролевой сервер GTA San Andreas со своим лаунчером и интерфейсом. Прилетай в Лос-Сантос и начни свою историю.',
        'forum_title' => 'Форум - World Role Play',
        'server_name' => 'World Role Play | Los Santos',
        'server_ip' => '127.0.0.1',
        'server_port' => '7777',
        'server_query' => '1',
        'launcher_url' => '',
        'android_url' => '',
        'client_url' => 'https://www.sa-mp.mp/downloads/',
        'video_url' => '',
        'discord_url' => '',
        'vk_url' => '',
        'telegram_url' => '',
        'youtube_url' => '',
        'registration_open' => '1',
        'threads_per_page' => '20',
        'posts_per_page' => '15',
        'flood_seconds' => '15',
        'edit_window_minutes' => '0',
        'news_node_id' => '0',
        'rules_node_id' => '0',
        'complaints_node_id' => '0',
        'tech_node_id' => '0',
        'partners_text' => '',
        'footer_text' => 'World Role Play не связан с Rockstar Games и Take-Two Interactive.',
        'announcement' => '',
    ];
}

function setting($key, $default = null)
{
    static $cache = null;
    if ($key === null) {
        $cache = null;
        return null;
    }
    if ($cache === null) {
        $cache = settings_defaults();
        try {
            foreach (db_all('SELECT k, v FROM settings') as $row) {
                $cache[$row['k']] = $row['v'];
            }
        } catch (Exception $e) {
            // база ещё не готова (установка)
        }
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    return $default;
}

function setting_set($key, $value)
{
    db_exec('INSERT INTO settings (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['k' => $key, 'v' => (string)$value]);
    setting(null);
}

function setting_int($key)
{
    return (int)setting($key, 0);
}
