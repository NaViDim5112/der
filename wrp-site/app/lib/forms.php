<?php
// Анкеты для создания тем (жалобы, заявления) - как формы на форуме Аризоны.
// Анкета хранится в nodes.form_json. Если она есть, вместо обычного редактора показывается форма,
// а заголовок и текст темы собираются из ответов.
//
// Формат:
// {
//   "title": "Жалоба на администратора {admin} | Причина: {reason}",  - шаблон заголовка, {key} = ответ поля
//   "intro": "BB-текст над формой (необязательно)",
//   "fields": [
//     {"key": "nick", "label": "Ваш игровой ник", "type": "text", "required": true,
//      "placeholder": "Имя_Фамилия", "hint": "Подсказка под полем", "max": 64},
//     ...
//   ]
// }
// Типы полей: text, textarea, url, date, number, radio, select, checkbox.
// Для radio и select нужен "options": ["Вариант 1", "Вариант 2"], для checkbox - "text": "Да".

function form_types()
{
    return ['text' => 'Строка', 'textarea' => 'Большой текст', 'url' => 'Ссылка (скриншот, видео)', 'date' => 'Дата',
        'number' => 'Число', 'radio' => 'Выбор одного варианта', 'select' => 'Выпадающий список', 'checkbox' => 'Галочка (согласие)'];
}

// Приводит описание анкеты к единому виду. null - если полей нет.
function form_normalize($raw)
{
    if (!is_array($raw) || empty($raw['fields']) || !is_array($raw['fields'])) {
        return null;
    }
    $types = form_types();
    $fields = [];
    $used = [];
    foreach ($raw['fields'] as $i => $f) {
        if (!is_array($f) || empty($f['label'])) {
            continue;
        }
        $type = isset($f['type'], $types[$f['type']]) ? $f['type'] : 'text';
        $key = isset($f['key']) && preg_match('~^[a-z0-9_]{1,32}$~', (string)$f['key']) ? (string)$f['key'] : 'f' . $i;
        if (isset($used[$key])) {
            $key .= '_' . $i;
        }
        $used[$key] = true;
        $options = [];
        if (in_array($type, ['radio', 'select'], true)) {
            foreach ((array)($f['options'] ?? []) as $o) {
                $o = trim((string)$o);
                if ($o !== '') {
                    $options[] = mb_substr($o, 0, 120);
                }
            }
            if (!$options) {
                $type = 'text';
            }
        }
        $fields[] = [
            'key' => $key,
            'label' => mb_substr(trim((string)$f['label']), 0, 120),
            'type' => $type,
            'required' => !empty($f['required']),
            'placeholder' => mb_substr((string)($f['placeholder'] ?? ''), 0, 120),
            'hint' => mb_substr((string)($f['hint'] ?? ''), 0, 500),
            'options' => $options,
            'text' => mb_substr((string)($f['text'] ?? 'Да'), 0, 120),
            'max' => max(1, min($type === 'textarea' ? 10000 : 500, (int)($f['max'] ?? ($type === 'textarea' ? 5000 : 200)))),
        ];
    }
    if (!$fields) {
        return null;
    }
    return [
        'title' => mb_substr(trim((string)($raw['title'] ?? '')), 0, 200),
        'intro' => (string)($raw['intro'] ?? ''),
        'fields' => $fields,
    ];
}

// Анкета раздела или null
function form_get($node)
{
    if (!$node || empty($node['form_json'])) {
        return null;
    }
    $raw = json_decode((string)$node['form_json'], true);
    return form_normalize($raw);
}

// Проверка JSON из админки: [анкета или null, текст ошибки]
function form_validate_json($json)
{
    $json = trim((string)$json);
    if ($json === '') {
        return [null, ''];
    }
    $raw = json_decode($json, true);
    if (!is_array($raw)) {
        return [null, 'Анкета: ошибка в JSON (' . json_last_error_msg() . ').'];
    }
    $form = form_normalize($raw);
    if (!$form) {
        return [null, 'Анкета: нет ни одного поля с названием (label).'];
    }
    return [$form, ''];
}

// Ответы из POST (поля f[key]): [значения, ошибки]
function form_input(array $form, array $post)
{
    $in = isset($post['f']) && is_array($post['f']) ? $post['f'] : [];
    $values = [];
    $errors = [];
    foreach ($form['fields'] as $f) {
        $k = $f['key'];
        $v = isset($in[$k]) && is_string($in[$k]) ? trim(str_replace(["\r\n", "\r"], "\n", $in[$k])) : '';
        if ($f['type'] !== 'textarea') {
            $v = preg_replace('~\s+~u', ' ', $v);
        }
        if ($f['type'] === 'checkbox') {
            $v = $v !== '' ? '1' : '';
        }
        $values[$k] = $v;
        if ($v === '') {
            if ($f['required']) {
                $errors[$k] = 'Заполните это поле.';
            }
            continue;
        }
        if (mb_strlen($v) > $f['max']) {
            $errors[$k] = 'Слишком длинно (максимум ' . $f['max'] . ' символов).';
            continue;
        }
        switch ($f['type']) {
            case 'url':
                if (!preg_match('~^https?://[^\s<>"]+$~i', $v)) {
                    $errors[$k] = 'Вставьте ссылку, которая начинается с http:// или https://';
                }
                break;
            case 'date':
                $d = DateTime::createFromFormat('Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) {
                    $errors[$k] = 'Укажите дату.';
                }
                break;
            case 'number':
                if (!is_numeric($v)) {
                    $errors[$k] = 'Нужно число.';
                }
                break;
            case 'radio':
            case 'select':
                if (!in_array($v, $f['options'], true)) {
                    $errors[$k] = 'Выберите вариант из списка.';
                }
                break;
        }
    }
    return [$values, $errors];
}

// Значение поля для текста и заголовка
function form_value_text($f, $v)
{
    if ($f['type'] === 'checkbox') {
        return $v !== '' ? $f['text'] : '';
    }
    if ($f['type'] === 'date' && $v !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return $d ? $d->format('d.m.Y') : $v;
    }
    return $v;
}

// Заголовок темы по шаблону
function form_title(array $form, array $values)
{
    $tpl = $form['title'];
    if ($tpl === '') {
        $parts = [];
        foreach ($form['fields'] as $f) {
            $t = form_value_text($f, $values[$f['key']] ?? '');
            if ($t !== '' && $f['type'] !== 'textarea' && $f['type'] !== 'url') {
                $parts[] = $t;
            }
            if (count($parts) >= 2) {
                break;
            }
        }
        $title = implode(' | ', $parts);
    } else {
        $title = preg_replace_callback('~\{([a-z0-9_]{1,32})\}~', function ($m) use ($form, $values) {
            foreach ($form['fields'] as $f) {
                if ($f['key'] === $m[1]) {
                    return form_value_text($f, $values[$f['key']] ?? '');
                }
            }
            return '';
        }, $tpl);
    }
    $title = trim(preg_replace('~\s+~u', ' ', str_replace(["\n", "\r"], ' ', $title)));
    return mb_substr($title, 0, 150);
}

// Текст темы в BB-кодах
function form_body(array $form, array $values)
{
    $lines = [];
    foreach ($form['fields'] as $f) {
        $v = form_value_text($f, $values[$f['key']] ?? '');
        if ($v === '') {
            continue;
        }
        $label = '[b]' . str_replace(['[', ']'], ['(', ')'], $f['label']) . ':[/b]';
        if ($f['type'] === 'url') {
            if (preg_match('~\.(png|jpe?g|gif|webp)(\?.*)?$~i', $v)) {
                $lines[] = $label . "\n[img]" . $v . '[/img]';
            } elseif (preg_match('~(youtube\.com/|youtu\.be/)~i', $v)) {
                $lines[] = $label . "\n[media]" . $v . '[/media]';
            } else {
                $lines[] = $label . ' ' . $v;
            }
        } elseif ($f['type'] === 'textarea' && strpos($v, "\n") !== false) {
            $lines[] = $label . "\n" . $v;
        } else {
            $lines[] = $label . ' ' . $v;
        }
    }
    return implode("\n", $lines);
}

// HTML полей анкеты (подписи слева, поля справа)
function form_fields_html(array $form, array $values = [], array $errors = [])
{
    $h = '<div class="xf-form">';
    foreach ($form['fields'] as $f) {
        $k = $f['key'];
        $id = 'f-' . $k;
        $name = 'f[' . $k . ']';
        $v = (string)($values[$k] ?? '');
        $err = $errors[$k] ?? '';
        $req = $f['required'] ? ' required' : '';
        $h .= '<div class="xf-row' . ($err ? ' has-error' : '') . '">';
        $h .= '<div class="xf-label"><label for="' . e($id) . '">' . e($f['label']) . '</label>' . ($f['required'] ? '<span class="xf-req">Обязательно</span>' : '') . '</div>';
        $h .= '<div class="xf-input">';
        switch ($f['type']) {
            case 'textarea':
                $h .= '<textarea class="textarea" id="' . e($id) . '" name="' . e($name) . '" rows="4" maxlength="' . (int)$f['max'] . '" placeholder="' . e($f['placeholder']) . '"' . $req . '>' . e($v) . '</textarea>';
                break;
            case 'radio':
                $h .= '<div class="xf-options">';
                foreach ($f['options'] as $i => $o) {
                    $checked = $v === $o || ($v === '' && $i === 0 && $f['required']) ? ' checked' : '';
                    $h .= '<label class="check"><input type="radio" name="' . e($name) . '" value="' . e($o) . '"' . $checked . '><span>' . e($o) . '</span></label>';
                }
                $h .= '</div>';
                break;
            case 'select':
                $h .= '<select class="select" id="' . e($id) . '" name="' . e($name) . '"' . $req . '><option value="">Выберите...</option>';
                foreach ($f['options'] as $o) {
                    $h .= '<option value="' . e($o) . '"' . ($v === $o ? ' selected' : '') . '>' . e($o) . '</option>';
                }
                $h .= '</select>';
                break;
            case 'checkbox':
                $h .= '<label class="check"><input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($v !== '' ? ' checked' : '') . $req . '><span>' . e($f['text']) . '</span></label>';
                break;
            case 'date':
                $h .= '<input class="input xf-date" type="date" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v) . '"' . $req . '>';
                break;
            default:
                $type = $f['type'] === 'url' ? 'url' : ($f['type'] === 'number' ? 'number' : 'text');
                $h .= '<input class="input" type="' . $type . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v) . '" maxlength="' . (int)$f['max'] . '" placeholder="' . e($f['placeholder']) . '"' . $req . '>';
        }
        if ($f['hint'] !== '') {
            $h .= '<div class="hint">' . bbcode($f['hint']) . '</div>';
        }
        if ($err) {
            $h .= '<div class="field-error">' . e($err) . '</div>';
        }
        $h .= '</div></div>';
    }
    return $h . '</div>';
}
