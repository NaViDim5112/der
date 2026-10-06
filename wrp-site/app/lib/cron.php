<?php
// Планировщик фоновых задач (автозакрытие, архив, истечение предупреждений, трофеи...).
// Задачи запускаются сами после ответа посетителю (не чаще раза в минуту на весь сайт)
// или из консоли: php tools/cron.php (можно повесить на «Планировщик задач» OpenServer / Windows).
//
//   cron_register('autolock', 600, function () { ... });   // раз в 10 минут

function &cron_jobs()
{
    static $jobs = [];
    return $jobs;
}

function cron_register($name, $intervalSeconds, $callback)
{
    $jobs = &cron_jobs();
    $jobs[$name] = ['interval' => max(60, (int)$intervalSeconds), 'cb' => $callback];
}

// Выполнить задачи, у которых подошёл срок. $force - все сразу (консоль).
function cron_run_due($force = false)
{
    $jobs = &cron_jobs();
    if (!$jobs) {
        return [];
    }
    if (!(int)db_val('SELECT GET_LOCK(:n, 0)', ['n' => 'wrp_cron'])) {
        return [];
    }
    $ran = [];
    try {
        $state = [];
        foreach (db_all('SELECT name, last_run FROM cron_state') as $r) {
            $state[$r['name']] = $r['last_run'] ? strtotime($r['last_run']) : 0;
        }
        foreach ($jobs as $name => $job) {
            $last = $state[$name] ?? 0;
            if (!$force && $last > time() - $job['interval']) {
                continue;
            }
            $status = 'ok';
            try {
                call_user_func($job['cb']);
            } catch (Exception $e) {
                $status = mb_substr('ошибка: ' . $e->getMessage(), 0, 250);
                error_log('[cron ' . $name . '] ' . $e);
            }
            db_exec('INSERT INTO cron_state (name, last_run, last_status) VALUES (:n, :t, :s)
                     ON DUPLICATE KEY UPDATE last_run = VALUES(last_run), last_status = VALUES(last_status)',
                ['n' => $name, 't' => now(), 's' => $status]);
            $ran[$name] = $status;
        }
    } finally {
        db_val('SELECT RELEASE_LOCK(:n)', ['n' => 'wrp_cron']);
    }
    return $ran;
}

// Вызывается в конце страницы: не чаще раза в минуту, после отправки ответа
function cron_maybe_run()
{
    if (!cron_jobs() || PHP_SAPI === 'cli') {
        return;
    }
    $mark = WRP_STORAGE . '/cache/cron.last';
    $last = is_file($mark) ? (int)@filemtime($mark) : 0;
    if ($last > time() - 60) {
        return;
    }
    @touch($mark);
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(30);
        try {
            cron_run_due(false);
        } catch (Exception $e) {
            error_log('[cron] ' . $e);
        }
    });
}
