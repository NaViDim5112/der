<?php
// Сообщество: дополнительные поля профиля (Discord, ВКонтакте, Telegram, часовой пояс...).
// Поля задаёт администрация (/admin/profile-fields.php), пользователь заполняет их
// в настройках аккаунта на вкладке «Дополнительно».

function com_field_types()
{
    return [
        'text' => ['Текст', 'Одна строка, например ник в Discord'],
        'url' => ['Ссылка', 'Только http:// или https://, в профиле будет ссылкой'],
        'select' => ['Выбор из списка', 'Пользователь выбирает один вариант'],
    ];
}

// Все поля [id => поле], кэш на запрос
function com_fields_all($reset = false)
{
    static $all = null;
    if ($reset) {
        $all = null;
        return [];
    }
    if ($all === null) {
        $all = [];
        if (com_ready()) {
            foreach (db_all('SELECT * FROM com_fields ORDER BY display_order, id') as $f) {
                $all[(int)$f['id']] = $f;
            }
        }
    }
    return $all;
}

// Варианты списка (по строке на вариант)
function com_field_options($f)
{
    $out = [];
    foreach (preg_split('~\r\n|\r|\n|\\\\n~', (string)($f['options'] ?? '')) as $o) {
        $o = com_clean_line($o);
        if ($o !== '' && !in_array($o, $out, true)) {
            $out[] = $o;
        }
    }
    return $out;
}

// Проверка значения. Возвращает очищенное значение ('' - пусто), ошибку пишет в $error.
function com_field_clean(array $f, $raw, &$error)
{
    $error = '';
    $max = max(1, min(255, (int)$f['max_length']));
    $v = com_clean_line(is_string($raw) ? $raw : '');
    if ($v === '') {
        return '';
    }
    if ($f['type'] !== 'select' && mb_strlen($v) > $max) {
        $error = '«' . $f['title'] . '»: не больше ' . $max . ' ' . plural($max, 'символа', 'символов', 'символов') . '.';
        return '';
    }
    if ($f['type'] === 'url') {
        if (!preg_match('~^https?://~i', $v) && preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(/|$)~i', $v)) {
            $v = 'https://' . $v;
        }
        if (!com_http_url_ok($v) || mb_strlen($v) > $max) {
            $error = '«' . $f['title'] . '»: нужна ссылка, которая начинается с https://';
            return '';
        }
    } elseif ($f['type'] === 'select') {
        if (!in_array($v, com_field_options($f), true)) {
            $error = '«' . $f['title'] . '»: выберите вариант из списка.';
            return '';
        }
    }
    return $v;
}

// Значения пользователя [field_id => value]
function com_field_values($uid)
{
    $out = [];
    if (!com_ready() || (int)$uid <= 0) {
        return $out;
    }
    foreach (db_all('SELECT field_id, value FROM com_field_values WHERE user_id = :u', ['u' => (int)$uid]) as $r) {
        $out[(int)$r['field_id']] = $r['value'];
    }
    return $out;
}

function com_field_save($uid, $fieldId, $value)
{
    if ($value === '' || $value === null) {
        db_exec('DELETE FROM com_field_values WHERE user_id = :u AND field_id = :f', ['u' => (int)$uid, 'f' => (int)$fieldId]);
        return;
    }
    db_exec('INSERT INTO com_field_values (user_id, field_id, value) VALUES (:u, :f, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['u' => (int)$uid, 'f' => (int)$fieldId, 'v' => (string)$value]);
}

// Короткий вид ссылки: vk.com/nick
function com_url_short($u, $limit = 40)
{
    $s = preg_replace('~^https?://(www\.)?~i', '', (string)$u);
    return str_limit(rtrim($s, '/'), $limit);
}

// Значение простым текстом (карточка автора)
function com_field_plain(array $f, $v)
{
    return $f['type'] === 'url' ? com_url_short($v, 32) : (string)$v;
}

// Значение для профиля: ссылки - только http(s), с rel="nofollow ugc noopener"
function com_field_html(array $f, $v)
{
    if ($f['type'] === 'url') {
        if (!com_http_url_ok($v)) {
            return '<span class="muted">-</span>';
        }
        return '<a class="com-field-link" href="' . e($v) . '" target="_blank" rel="nofollow ugc noopener">' . e(com_url_short($v)) . icon('external') . '</a>';
    }
    return e($v);
}

// Поля, которые пользователь заполняет сам
function com_fields_editable()
{
    $out = [];
    foreach (com_fields_all() as $id => $f) {
        if (!empty($f['user_editable'])) {
            $out[$id] = $f;
        }
    }
    return $out;
}

// Поле формы
function com_field_input(array $f, $value, $name, $id, $invalid = false)
{
    $cls = $invalid ? ' is-invalid' : '';
    $max = max(1, min(255, (int)$f['max_length']));
    if ($f['type'] === 'select') {
        $h = '<select class="select' . $cls . '" id="' . e($id) . '" name="' . e($name) . '"><option value="">Не указано</option>';
        $opts = com_field_options($f);
        if ((string)$value !== '' && !in_array((string)$value, $opts, true)) {
            $opts[] = (string)$value;
        }
        foreach ($opts as $o) {
            $h .= '<option value="' . e($o) . '"' . ((string)$value === $o ? ' selected' : '') . '>' . e($o) . '</option>';
        }
        return $h . '</select>';
    }
    return '<input class="input' . $cls . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '" maxlength="' . $max . '"'
        . ($f['type'] === 'url' ? ' inputmode="url" spellcheck="false"' : '')
        . (!empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '') . ' autocomplete="off">';
}

// ---------- Точки подключения ----------

// Профиль, «Сведения»: заполненные поля
hook_add('member_info_rows', function ($m) {
    if (!com_ready()) {
        return '';
    }
    $fields = com_fields_all();
    if (!$fields) {
        return '';
    }
    $vals = com_field_values($m['id']);
    $h = '';
    foreach ($fields as $id => $f) {
        $v = $vals[$id] ?? '';
        if (empty($f['show_profile']) || $v === '') {
            continue;
        }
        $h .= '<dt class="com-field-dt">' . e($f['title']) . '</dt><dd class="com-field-val">' . com_field_html($f, $v) . '</dd>';
    }
    return $h;
}, 20);

// Настройки аккаунта: вкладка «Дополнительно»
hook_add('account_tabs', function ($tabs, $me) {
    if (com_ready() && com_fields_editable()) {
        $tabs['details'] = ['Дополнительно', 'list'];
    }
    return $tabs;
});

hook_add('account_tab_content', function ($tab, $me) {
    if ($tab !== 'details' || !com_ready()) {
        return '';
    }
    $fields = com_fields_editable();
    $vals = com_field_values($me['id']);
    $old = $_SESSION['com_fields_old'] ?? null;
    $bad = $_SESSION['com_fields_bad'] ?? [];
    unset($_SESSION['com_fields_old'], $_SESSION['com_fields_bad']);
    $h = '<section class="card"><h2 class="card-title">Дополнительно</h2>';
    $h .= '<p class="muted">Контакты и сведения о себе. Заполняйте только то, что хотите показать другим: всё это видно в вашем профиле. Пустые поля не показываются.</p>';
    if (!$fields) {
        return $h . '<div class="empty">' . icon('list') . '<div>Дополнительных полей пока нет.</div></div></section>';
    }
    $h .= '<form method="post" class="form" action="' . e(url('/forum/account.php', ['tab' => 'details'])) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="com_fields_save"><div class="form-grid">';
    foreach ($fields as $id => $f) {
        $v = is_array($old) && array_key_exists($id, $old) ? (string)$old[$id] : (string)($vals[$id] ?? '');
        $fid = 'com-f-' . $id;
        $where = [];
        if (!empty($f['show_profile'])) {
            $where[] = 'в профиле';
        }
        if (!empty($f['show_post'])) {
            $where[] = 'под аватаром в темах';
        }
        $h .= '<div class="form-row"><label class="label com-field-label" for="' . $fid . '">' . icon($f['icon']) . ' ' . e($f['title']) . '</label>'
            . com_field_input($f, $v, 'f[' . $id . ']', $fid, in_array($id, $bad, true));
        $hint = trim((string)$f['description']);
        if ($where) {
            $hint .= ($hint !== '' ? ' ' : '') . 'Видно ' . implode(' и ', $where) . '.';
        }
        if ($hint !== '') {
            $h .= '<div class="hint">' . e($hint) . '</div>';
        }
        $h .= '</div>';
    }
    $h .= '</div><div class="form-actions"><button class="btn btn-white btn-pill" type="submit">' . icon('check') . ' Сохранить</button></div></form></section>';
    return $h;
});

hook_add('account_action', function ($action, $tab, $me) {
    if ($action !== 'com_fields_save') {
        return;
    }
    $back = url('/forum/account.php', ['tab' => 'details']);
    if (!com_ready()) {
        flash('error', 'Сначала нужно обновить базу сайта.');
        redirect($back);
    }
    if (is_banned()) {
        flash('error', 'Пока аккаунт заблокирован, менять профиль нельзя.');
        redirect($back);
    }
    $in = $_POST['f'] ?? [];
    if (!is_array($in)) {
        $in = [];
    }
    $clean = [];
    $errors = [];
    $bad = [];
    foreach (com_fields_editable() as $id => $f) {
        $err = '';
        $clean[$id] = com_field_clean($f, $in[$id] ?? '', $err);
        if ($err !== '') {
            $errors[] = $err;
            $bad[] = $id;
        }
    }
    if ($errors) {
        $_SESSION['com_fields_old'] = array_map(function ($v) {
            return is_string($v) ? mb_substr($v, 0, 255) : '';
        }, array_intersect_key($in, $clean));
        $_SESSION['com_fields_bad'] = $bad;
        flash('error', implode(' ', $errors));
        redirect($back);
    }
    foreach ($clean as $id => $v) {
        com_field_save($me['id'], $id, $v);
    }
    flash('success', 'Сохранено.');
    redirect($back);
});
