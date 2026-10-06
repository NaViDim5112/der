<?php
// Точки расширения. Модули (app/lib/*.php) подключаются к страницам, не меняя их код:
//
//   hook_add('post_actions_left', function ($p, $ctx) { return '<a ...>Пожаловаться</a>'; });
//   echo hook_html('post_actions_left', $p, $ctx);          // HTML от всех модулей подряд
//   hook_fire('thread_created', $threadId, $node, $postId);  // событие
//   $rows = hook_filter('post_user_rows', $rows, $u);        // значение проходит через все модули
//
// Порядок: по приоритету (меньше - раньше), затем по порядку подключения.
// Список точек и их аргументов - в docs/HOOKS.md.

function &hook_registry()
{
    static $hooks = [];
    return $hooks;
}

function hook_add($name, $callback, $priority = 10)
{
    $hooks = &hook_registry();
    $hooks[$name][(int)$priority][] = $callback;
}

function hook_callbacks($name)
{
    $hooks = &hook_registry();
    if (empty($hooks[$name])) {
        return [];
    }
    ksort($hooks[$name]);
    $out = [];
    foreach ($hooks[$name] as $list) {
        foreach ($list as $cb) {
            $out[] = $cb;
        }
    }
    return $out;
}

// Склеивает HTML, который вернули модули
function hook_html($name, ...$args)
{
    $h = '';
    foreach (hook_callbacks($name) as $cb) {
        $r = call_user_func_array($cb, $args);
        if (is_string($r)) {
            $h .= $r;
        }
    }
    return $h;
}

// Событие: вызывает всех, результат не нужен
function hook_fire($name, ...$args)
{
    foreach (hook_callbacks($name) as $cb) {
        call_user_func_array($cb, $args);
    }
}

// Фильтр: значение проходит через всех модулей по очереди
function hook_filter($name, $value, ...$args)
{
    foreach (hook_callbacks($name) as $cb) {
        $value = call_user_func_array($cb, array_merge([$value], $args));
    }
    return $value;
}

// Первый модуль, вернувший не null, побеждает (например, перехват входа для 2FA)
function hook_first($name, ...$args)
{
    foreach (hook_callbacks($name) as $cb) {
        $r = call_user_func_array($cb, $args);
        if ($r !== null) {
            return $r;
        }
    }
    return null;
}
