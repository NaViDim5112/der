<?php
// Админ-панель: оболочка страниц (меню слева) и общие помощники для форм.
// Каждая страница /admin/*.php вызывает require_admin() в самом начале (до обработки POST),
// затем admin_header('Заголовок', 'ключ меню', [крошки]) ... admin_footer().

// Пункты меню админки: ключ => [название, иконка, адрес]
function adm_menu_items()
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }
    // Модули добавляют свои пункты: hook_add('admin_menu', function ($items) { $items['x'] = [...]; return $items; });
    return $items = hook_filter('admin_menu', [
        'index' => ['Обзор', 'monitor', '/admin/'],
        'settings' => ['Настройки', 'settings', '/admin/settings.php'],
        'nodes' => ['Разделы форума', 'folder', '/admin/nodes.php'],
        'prefixes' => ['Префиксы', 'tag', '/admin/prefixes.php'],
        'groups' => ['Группы', 'shield', '/admin/groups.php'],
        'users' => ['Пользователи', 'users', '/admin/users.php'],
        'nav' => ['Меню слева', 'list', '/admin/nav.php'],
        'wiki' => ['База знаний', 'book', '/admin/wiki.php'],
        'modlog' => ['Журнал модерации', 'clock', '/admin/modlog.php'],
    ]);
}

function admin_header($title, $active, $crumbs = [], array $opts = [])
{
    require_admin();
    $items = adm_menu_items();
    $all = $crumbs;
    if ($active !== 'index' && isset($items[$active]) && (!$crumbs || $crumbs[0][0] !== $items[$active][0])) {
        array_unshift($all, [$items[$active][0], url($items[$active][2])]);
    }
    forum_header(array_merge([
        'title' => $title,
        'crumbs' => array_merge([['Админ-панель', url('/admin/')]], $all),
        'right' => false,
        'nav' => '',
        'css' => ['admin.css'],
        'js' => ['admin.js'],
        'body_class' => 'admin-page',
    ], $opts));
    ?>
<div class="adm">
  <nav class="adm-nav" aria-label="Меню админ-панели">
    <div class="adm-nav-title"><?= icon('shield') ?><span>Админ-панель</span></div>
    <div class="adm-nav-list">
      <?php foreach ($items as $key => $it): ?>
      <a class="adm-nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= e(url($it[2])) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($it[1]) ?><span><?= e($it[0]) ?></span></a>
      <?php endforeach; ?>
      <span class="adm-nav-sep" aria-hidden="true"></span>
      <a class="adm-nav-link adm-nav-out" href="<?= e(url('/')) ?>"><?= icon('home') ?><span>На сайт</span></a>
      <a class="adm-nav-link adm-nav-out" href="<?= e(url('/forum/')) ?>"><?= icon('chats') ?><span>На форум</span></a>
    </div>
  </nav>
  <div class="adm-main">
<?php
}

function admin_footer()
{
    echo "  </div>\n</div>\n";
    forum_footer();
}

// ---------- Мелкие помощники для форм ----------

function adm_sel($a, $b)
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function adm_chk($v)
{
    return $v ? ' checked' : '';
}

// Текст ошибки под полем
function adm_err(array $errors, $key)
{
    return isset($errors[$key]) ? '<div class="field-error">' . e($errors[$key]) . '</div>' : '';
}

// Общий блок с ошибками формы
function adm_errors_box(array $errors)
{
    if (!$errors) {
        return '';
    }
    $h = '<div class="flash flash-error">' . icon('alert') . '<div><b>Не сохранено - исправьте ошибки:</b><ul class="adm-err-list">';
    foreach ($errors as $msg) {
        $h .= '<li>' . e($msg) . '</li>';
    }
    return $h . '</ul></div></div>';
}

// Массив целых чисел из POST (чекбоксы name="x[]")
function adm_post_ints($key)
{
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $x) {
        if (is_numeric($x) && (int)$x > 0) {
            $out[] = (int)$x;
        }
    }
    return array_values(array_unique($out));
}

// Число из POST в пределах, иначе null
function adm_post_range($key, $min, $max)
{
    $v = $_POST[$key] ?? null;
    if (!is_string($v) || !preg_match('~^-?\d{1,9}$~', trim($v))) {
        return null;
    }
    $n = (int)trim($v);
    return ($n < $min || $n > $max) ? null : $n;
}

// Строка для LIKE с экранированием % и _
function adm_like($s)
{
    return '%' . addcslashes((string)$s, '\\%_') . '%';
}

// Длина строки (UTF-8) не больше $max
function adm_len_ok($s, $max)
{
    return mb_strlen((string)$s) <= $max;
}

// Внешняя ссылка http(s)://...
function adm_is_http_url($u)
{
    $u = (string)$u;
    if (!preg_match('~^https?://[^\s<>"\'`]+$~iu', $u)) {
        return false;
    }
    $host = parse_url($u, PHP_URL_HOST);
    return is_string($host) && $host !== '';
}

// Внутренний путь сайта: /forum/forum.php?id=30 (но не //example.com)
function adm_is_internal_path($u)
{
    $u = (string)$u;
    return $u !== '' && $u[0] === '/' && !str_starts_with($u, '//') && !preg_match('~[\s<>"\'`\\\\]~u', $u);
}

function adm_link_ok($u)
{
    return adm_is_internal_path($u) || adm_is_http_url($u);
}

// Ссылка на YouTube
function adm_is_youtube($u)
{
    if (!adm_is_http_url($u)) {
        return false;
    }
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    return (bool)preg_match('~(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$~', $host);
}

// ---------- Иконки ----------

// Иконки, которые можно выбрать для разделов, меню и базы знаний (без кнопок редактора)
function adm_icon_choices($current = '')
{
    $skip = ['bold', 'italic', 'underline', 'strike', 'align-center', 'align-left', 'align-right', 'undo', 'redo', 'eraser',
        'type', 'list-ordered', 'chevron-down', 'chevron-up', 'chevron-right', 'chevron-left', 'menu', 'dots', 'toggle',
        'contrast', 'maximize', 'spoiler', 'table', 'quote', 'reply', 'x', 'arrow-up', 'arrow-down', 'palette', 'code'];
    $out = [];
    foreach (icon_names() as $n) {
        if (!in_array($n, $skip, true) || $n === $current) {
            $out[] = $n;
        }
    }
    return $out;
}

// Сетка иконок для выбора (радиокнопки). Выбранная подсвечивается, рядом крупный предпросмотр.
function adm_icon_picker($name, $value, $id = 'icon')
{
    $value = (string)$value;
    if (!in_array($value, icon_names(), true)) {
        $value = 'chats';
    }
    $h = '<div class="icon-field" data-icon-field>';
    $h .= '<div class="icon-current"><span class="icon-current-box" data-icon-preview>' . icon($value) . '</span><span class="icon-current-name" data-icon-name>' . e($value) . '</span></div>';
    $h .= '<div class="icon-picker" role="radiogroup" id="' . e($id) . '">';
    foreach (adm_icon_choices($value) as $n) {
        $h .= '<label class="icon-opt" title="' . e($n) . '"><input type="radio" name="' . e($name) . '" value="' . e($n) . '"' . adm_chk($n === $value) . '>' . icon($n) . '</label>';
    }
    return $h . '</div></div>';
}

// ---------- Разделы форума ----------

// Дерево разделов по порядку: [['node' => ..., 'depth' => 0], ...]. Потерянные (без родителя) в конце.
function adm_node_tree()
{
    $all = nodes_all();
    $out = [];
    $seen = [];
    $walk = function ($parentId, $depth) use (&$walk, &$out, &$seen) {
        foreach (node_children_ids($parentId) as $id) {
            if (isset($seen[$id]) || $depth > 20) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['node' => node_get($id), 'depth' => $depth];
            $walk($id, $depth + 1);
        }
    };
    $walk(null, 0);
    foreach ($all as $id => $n) {
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $out[] = ['node' => $n, 'depth' => 0];
            $walk($id, 1);
        }
    }
    return $out;
}

function adm_node_type_names()
{
    return ['category' => 'Категория', 'forum' => 'Раздел', 'link' => 'Ссылка'];
}

// <option> со всеми разделами с отступами. $exclude - id, которые не показывать; $types - какие типы можно выбрать.
function adm_node_options($selected, array $exclude = [], $types = null)
{
    $h = '';
    $typeNames = adm_node_type_names();
    foreach (adm_node_tree() as $row) {
        $n = $row['node'];
        if (in_array((int)$n['id'], $exclude, true)) {
            continue;
        }
        $label = str_repeat("\u{00A0}\u{00A0}\u{00A0}\u{00A0}", $row['depth']) . $n['title'];
        if ($n['type'] !== 'forum') {
            $label .= ' (' . mb_strtolower($typeNames[$n['type']] ?? $n['type']) . ')';
        }
        $disabled = $types !== null && !in_array($n['type'], $types, true);
        $h .= '<option value="' . (int)$n['id'] . '"' . adm_sel($selected, $n['id']) . ($disabled ? ' disabled' : '') . '>' . e($label) . '</option>';
    }
    return $h;
}

// Уровни доступа для выбора: 0 - все, уровни групп, 999 - никто
function adm_level_options($current = null)
{
    $byLevel = [];
    foreach (groups_all() as $g) {
        $l = (int)$g['level'];
        if ($l > 0 && $l !== 999) {
            $byLevel[$l][] = $g['name'];
        }
    }
    ksort($byLevel);
    $userLevel = adm_user_group_level();
    $opts = [0 => 'Все, включая гостей'];
    foreach ($byLevel as $l => $names) {
        $who = $l === $userLevel ? 'Пользователи' : implode(' / ', $names);
        $opts[$l] = $who . ' и выше (уровень ' . $l . ')';
    }
    $opts[999] = 'Никто (только модераторы)';
    if ($current !== null && !isset($opts[(int)$current])) {
        $opts[(int)$current] = 'Уровень ' . (int)$current . ' и выше';
    }
    ksort($opts);
    return $opts;
}

function adm_user_group_level()
{
    $groups = groups_all();
    return isset($groups[WRP_USER_GROUP]) ? (int)$groups[WRP_USER_GROUP]['level'] : 10;
}

// Короткая подпись уровня для таблицы: «Все», «Пользователи», «Лидер+», «Никто»
function adm_level_short($level)
{
    $level = (int)$level;
    if ($level <= 0) {
        return 'Все';
    }
    if ($level === 999) {
        return 'Никто';
    }
    if ($level === adm_user_group_level()) {
        return 'Пользователи';
    }
    $names = [];
    foreach (groups_all() as $g) {
        if ((int)$g['level'] === $level) {
            $names[] = $g['name'];
        }
    }
    return $names ? $names[0] . '+' : 'Уровень ' . $level . '+';
}

// ---------- Группы и права ----------

// Сколько незаблокированных пользователей имеют доступ к админке, если доступ дают группы $adminGroupIds
function adm_admins_count(array $adminGroupIds)
{
    $adminGroupIds = array_values(array_unique(array_map('intval', $adminGroupIds)));
    if (!$adminGroupIds) {
        return 0;
    }
    $in = db_in($adminGroupIds, 'g');
    $or = [];
    $params = $in['params'];
    foreach ($adminGroupIds as $i => $gid) {
        $or[] = 'FIND_IN_SET(:s' . $i . ', u.secondary_groups)';
        $params['s' . $i] = (string)$gid;
    }
    return (int)db_val('SELECT COUNT(*) FROM users u WHERE u.is_banned = 0 AND (u.group_id IN (' . $in['sql'] . ') OR ' . implode(' OR ', $or) . ')', $params);
}

// id групп, которые сейчас дают доступ к админке
function adm_admin_group_ids()
{
    $ids = [];
    foreach (groups_all() as $id => $g) {
        if (!empty($g['can_admin'])) {
            $ids[] = (int)$id;
        }
    }
    return $ids;
}

// Все группы пользователя (основная + дополнительные) из базы
function adm_user_group_ids($u)
{
    return array_values(array_unique(array_merge([(int)$u['group_id']], user_secondary_ids($u))));
}

// Базовые группы (Гость, Пользователь, Заблокирован): им нельзя давать модерацию и админку
function adm_is_base_group($id)
{
    return in_array((int)$id, [WRP_GUEST_GROUP, WRP_USER_GROUP, WRP_BANNED_GROUP], true);
}

// Предпросмотр плашки группы (как под аватаром в теме)
function adm_banner_html($name, $color)
{
    return '<div class="user-banner" style="--c:' . e($color) . '">' . icon('user') . '<span>' . e(mb_strtoupper($name)) . '</span></div>';
}

function adm_group_badge($g)
{
    return '<span class="group-badge" style="--c:' . e($g['color']) . '">' . e($g['name']) . '</span>';
}

// Цвет #rgb / #rrggbb
function adm_color_ok($c)
{
    return (bool)preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~i', (string)$c);
}

// ---------- Журнал ----------

function adm_action_labels()
{
    return [
        // пользователи (админка)
        'user_ban' => 'Блокировка пользователя',
        'user_unban' => 'Снятие блокировки',
        'user_group' => 'Смена группы',
        'user_password' => 'Смена пароля',
        'user_edit' => 'Изменение профиля',
        'user_delete' => 'Удаление пользователя',
        'user_avatar' => 'Удаление аватара',
        'user_cover' => 'Удаление обложки',
        // разделы и группы (админка)
        'node_delete' => 'Удаление раздела',
        'group_delete' => 'Удаление группы',
        // темы
        'thread_lock' => 'Тема закрыта',
        'thread_unlock' => 'Тема открыта',
        'thread_close' => 'Тема закрыта',
        'thread_open' => 'Тема открыта',
        'thread_pin' => 'Тема закреплена',
        'thread_unpin' => 'Тема откреплена',
        'thread_stick' => 'Тема закреплена',
        'thread_unstick' => 'Тема откреплена',
        'thread_move' => 'Тема перенесена',
        'thread_delete' => 'Тема удалена',
        'thread_restore' => 'Тема восстановлена',
        'thread_undelete' => 'Тема восстановлена',
        'thread_hard_delete' => 'Тема удалена навсегда',
        'thread_purge' => 'Тема удалена навсегда',
        'thread_edit' => 'Тема изменена',
        'thread_title' => 'Изменено название темы',
        'thread_rename' => 'Изменено название темы',
        'thread_prefix' => 'Изменён префикс темы',
        'prefix' => 'Изменён префикс темы',
        'prefix_change' => 'Изменён префикс темы',
        'thread_merge' => 'Темы объединены',
        // сообщения
        'post_edit' => 'Сообщение изменено',
        'post_delete' => 'Сообщение удалено',
        'post_restore' => 'Сообщение восстановлено',
        'post_undelete' => 'Сообщение восстановлено',
        'post_hard_delete' => 'Сообщение удалено навсегда',
        'post_purge' => 'Сообщение удалено навсегда',
        'post_move' => 'Сообщение перенесено',
        // профили
        'profile_post_delete' => 'Удалено сообщение в профиле',
        'profile_comment_delete' => 'Удалён комментарий в профиле',
        'profile_post_edit' => 'Изменено сообщение в профиле',
        // общие
        'lock' => 'Тема закрыта',
        'unlock' => 'Тема открыта',
        'pin' => 'Тема закреплена',
        'unpin' => 'Тема откреплена',
        'move' => 'Перенос',
        'delete' => 'Удаление',
        'restore' => 'Восстановление',
        'edit' => 'Изменение',
        'ban' => 'Блокировка',
        'unban' => 'Снятие блокировки',
        'db_migrate' => 'Обновление базы сайта',
    ] + (array)hook_filter('modlog_labels', []);
}

function adm_action_label($action)
{
    $l = adm_action_labels();
    return $l[$action] ?? str_replace('_', ' ', (string)$action);
}

// Цвет метки действия: красный - удаление/бан, зелёный - восстановление, остальное синее
function adm_action_tone($action)
{
    $a = (string)$action;
    if (preg_match('~(delete|purge|ban|lock|close)~', $a) && !preg_match('~(unban|unlock)~', $a)) {
        return 'danger';
    }
    if (preg_match('~(restore|undelete|unban|unlock|open)~', $a)) {
        return 'success';
    }
    return 'info';
}

// ---------- Пользователи ----------

// Безопасное имя загруженного файла (аватар, обложка): без путей и лишних символов
function adm_upload_name_ok($name)
{
    return is_string($name) && (bool)preg_match('~^[A-Za-z0-9][A-Za-z0-9_\-]{0,58}\.(?:jpe?g|png|gif|webp)$~', $name);
}

// Удалить файл из public/uploads/<dir>/ (только если имя прошло проверку)
function adm_upload_delete($dir, $name)
{
    if (!in_array($dir, ['avatars', 'covers'], true) || !adm_upload_name_ok($name)) {
        return false;
    }
    $path = WRP_PUBLIC . '/uploads/' . $dir . '/' . $name;
    if (is_file($path)) {
        return @unlink($path);
    }
    return true;
}

// Значение для input type=datetime-local
function adm_dt_local($datetime)
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

// Пустая строка -> NULL для необязательных колонок
function adm_null($s)
{
    $s = trim((string)$s);
    return $s === '' ? null : $s;
}
