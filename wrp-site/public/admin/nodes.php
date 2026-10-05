<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/nodes.php');
$types = adm_node_type_names();

// Значения формы по умолчанию для нового раздела
function adm_node_defaults($type, $parentId)
{
    $parentId = (int)$parentId;
    $max = (int)db_val('SELECT COALESCE(MAX(display_order), 0) FROM nodes WHERE ' . ($parentId ? 'parent_id = :p' : 'parent_id IS NULL'), $parentId ? ['p' => $parentId] : []);
    $userLevel = adm_user_group_level();
    return [
        'id' => 0, 'type' => $type, 'parent_id' => $parentId ?: null, 'title' => '', 'description' => '',
        'icon' => $type === 'link' ? 'link' : 'chats', 'link_url' => '', 'display_order' => $max + 1,
        'view_level' => 0, 'thread_level' => $userLevel, 'reply_level' => $userLevel,
        'hide_if_no_access' => 0, 'is_closed' => 0, 'quick_nav' => 0, 'require_prefix' => 0, 'default_prefix_id' => null,
        'title_hint' => '', 'thread_template' => '', 'form_json' => '', 'thread_count' => 0, 'post_count' => 0,
    ];
}

// Можно ли сделать $parentId родителем раздела $id (нет циклов, родитель не ссылка)
function adm_node_parent_ok($id, $parentId)
{
    if (!$parentId) {
        return true;
    }
    $p = node_get($parentId);
    if (!$p || $p['type'] === 'link') {
        return false;
    }
    if ($id && in_array((int)$parentId, node_subtree_ids($id), true)) {
        return false;
    }
    return true;
}

// ---------- POST ----------

$errors = [];
$form = null;
$prefixSel = [];

if (is_post()) {
    csrf_check();
    $action = input('action');

    // Живой предпросмотр анкеты (AJAX)
    if ($action === 'form_preview') {
        list($fp, $ferr) = form_validate_json((string)($_POST['form_json'] ?? ''));
        if ($ferr) {
            json_out(['ok' => false, 'error' => $ferr]);
        }
        if (!$fp) {
            json_out(['ok' => true, 'html' => '']);
        }
        json_out(['ok' => true, 'html' => adm_form_preview_html($fp)]);
    }

    if ($action === 'order') {
        $orders = $_POST['order'] ?? [];
        $n = 0;
        if (is_array($orders)) {
            foreach ($orders as $id => $v) {
                if (!node_get($id) || !is_numeric($v)) {
                    continue;
                }
                $v = max(-100000, min(100000, (int)$v));
                if ((int)node_get($id)['display_order'] !== $v) {
                    db_update('nodes', ['display_order' => $v], 'id = :id', ['id' => (int)$id]);
                    $n++;
                }
            }
        }
        nodes_all(true);
        flash('success', $n ? 'Порядок разделов сохранён.' : 'Порядок не изменился.');
        redirect($self);
    }

    if ($action === 'delete') {
        $node = node_get(input_int('id'));
        if (!$node) {
            flash('error', 'Раздел не найден - возможно, его уже удалили.');
            redirect($self);
        }
        $id = (int)$node['id'];
        if (node_children_ids($id)) {
            flash('error', 'У раздела «' . $node['title'] . '» есть подразделы. Сначала удалите или перенесите их.');
            redirect(url('/admin/nodes.php', ['delete' => $id]));
        }
        $threads = (int)db_val('SELECT COUNT(*) FROM threads WHERE node_id = :n', ['n' => $id]);
        $target = null;
        if ($threads > 0) {
            $target = node_get(input_int('target'));
            if (!$target || $target['type'] !== 'forum' || (int)$target['id'] === $id) {
                flash('error', 'Выберите раздел, куда перенести темы.');
                redirect(url('/admin/nodes.php', ['delete' => $id]));
            }
            db_exec('UPDATE threads SET node_id = :t WHERE node_id = :n', ['t' => (int)$target['id'], 'n' => $id]);
        }
        db_exec('DELETE FROM node_prefixes WHERE node_id = :n', ['n' => $id]);
        db_exec('DELETE FROM node_reads WHERE node_id = :n', ['n' => $id]);
        db_exec('DELETE FROM node_watch WHERE node_id = :n', ['n' => $id]);
        db_exec('DELETE FROM nodes WHERE id = :n', ['n' => $id]);
        foreach (['news_node_id', 'rules_node_id', 'complaints_node_id', 'tech_node_id'] as $k) {
            if (setting_int($k) === $id) {
                setting_set($k, '0');
            }
        }
        nodes_all(true);
        if ($target) {
            node_rebuild($target['id']);
        }
        mod_log('node_delete', 'node', $id, $node['title'] . ($target ? ' (темы: ' . $threads . ', перенесены в «' . $target['title'] . '»)' : ''));
        flash('success', 'Раздел «' . $node['title'] . '» удалён.' . ($target ? ' Темы (' . $threads . ') перенесены в «' . $target['title'] . '».' : ''));
        redirect($self);
    }

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? node_get($id) : null;
        if ($id && !$old) {
            flash('error', 'Раздел не найден - возможно, его удалили.');
            redirect($self);
        }
        $type = input('type');
        if (!isset($types[$type])) {
            $type = 'forum';
        }
        $parentId = input_int('parent_id');
        $form = [
            'id' => $id,
            'type' => $type,
            'parent_id' => $parentId ?: null,
            'title' => input('title'),
            'description' => input_text('description'),
            'icon' => input('icon', 'chats'),
            'link_url' => input('link_url'),
            'display_order' => input('display_order'),
            'view_level' => input('view_level'),
            'thread_level' => input('thread_level'),
            'reply_level' => input('reply_level'),
            'hide_if_no_access' => input_bool('hide_if_no_access') ? 1 : 0,
            'is_closed' => input_bool('is_closed') ? 1 : 0,
            'quick_nav' => input('quick_nav'),
            'require_prefix' => input_bool('require_prefix') ? 1 : 0,
            'default_prefix_id' => input_int('default_prefix_id') ?: null,
            'title_hint' => input('title_hint'),
            'thread_template' => input_text('thread_template'),
            'form_json' => input_text('form_json'),
            'thread_count' => $old ? $old['thread_count'] : 0,
            'post_count' => $old ? $old['post_count'] : 0,
        ];
        $prefixSel = adm_post_ints('prefixes');

        if ($form['title'] === '') {
            $errors['title'] = 'Укажите название.';
        } elseif (!adm_len_ok($form['title'], 120)) {
            $errors['title'] = 'Название слишком длинное (максимум 120 символов).';
        }
        if (!adm_len_ok($form['description'], 500)) {
            $errors['description'] = 'Описание слишком длинное (максимум 500 символов).';
        }
        if (!in_array($form['icon'], icon_names(), true)) {
            $form['icon'] = 'chats';
        }
        if ($parentId && !adm_node_parent_ok($id, $parentId)) {
            $errors['parent_id'] = 'Этот родитель не подходит: раздел нельзя вложить в самого себя, в свой подраздел или в ссылку.';
        }
        if ($type === 'link') {
            if ($form['link_url'] === '') {
                $errors['link_url'] = 'Укажите адрес ссылки.';
            } elseif (!adm_link_ok($form['link_url']) || !adm_len_ok($form['link_url'], 255)) {
                $errors['link_url'] = 'Адрес должен начинаться с https:// (другой сайт) или с / (страница этого сайта).';
            }
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        } else {
            $form['display_order'] = $order;
        }
        foreach (['view_level' => 'просмотра', 'thread_level' => 'создания тем', 'reply_level' => 'ответов'] as $k => $what) {
            $v = adm_post_range($k, 0, 1000);
            if ($v === null) {
                $errors[$k] = 'Неверный уровень ' . $what . '.';
            } else {
                $form[$k] = $v;
            }
        }
        $qn = adm_post_range('quick_nav', 0, 1000);
        if ($qn === null) {
            $errors['quick_nav'] = 'Номер в быстрой навигации - число от 0 до 1000.';
        } else {
            $form['quick_nav'] = $qn;
        }
        $allPrefixes = prefixes_all();
        if ($form['default_prefix_id'] && !isset($allPrefixes[$form['default_prefix_id']])) {
            $form['default_prefix_id'] = null;
        }
        $prefixSel = array_values(array_filter($prefixSel, function ($p) use ($allPrefixes) {
            return isset($allPrefixes[$p]);
        }));
        if ($form['default_prefix_id'] && !in_array((int)$form['default_prefix_id'], $prefixSel, true)) {
            $prefixSel[] = (int)$form['default_prefix_id'];
        }
        if ($type === 'forum' && $form['require_prefix'] && !$prefixSel) {
            $errors['require_prefix'] = 'Чтобы префикс был обязательным, отметьте хотя бы один разрешённый префикс.';
        }
        if (!adm_len_ok($form['title_hint'], 120)) {
            $errors['title_hint'] = 'Подсказка слишком длинная (максимум 120 символов).';
        }
        if (!adm_len_ok($form['thread_template'], 20000)) {
            $errors['thread_template'] = 'Шаблон слишком длинный.';
        }
        list($parsedForm, $formErr) = form_validate_json($form['form_json']);
        if ($formErr !== '') {
            $errors['form_json'] = $formErr;
        }
        if ($old) {
            if ($old['type'] === 'forum' && $type !== 'forum') {
                $cnt = (int)db_val('SELECT COUNT(*) FROM threads WHERE node_id = :n', ['n' => $id]);
                if ($cnt > 0) {
                    $errors['type'] = 'В разделе есть темы (' . $cnt . '). Сначала перенесите их, потом меняйте тип.';
                }
            }
            if ($type === 'link' && node_children_ids($id)) {
                $errors['type'] = 'У раздела есть подразделы - ссылкой его сделать нельзя.';
            }
        }

        if (!$errors) {
            $data = [
                'parent_id' => $form['parent_id'] ? (int)$form['parent_id'] : null,
                'type' => $type,
                'title' => $form['title'],
                'description' => $form['description'] !== '' ? $form['description'] : null,
                'icon' => $form['icon'],
                'link_url' => $type === 'link' ? $form['link_url'] : null,
                'display_order' => (int)$form['display_order'],
                'view_level' => (int)$form['view_level'],
                'thread_level' => (int)$form['thread_level'],
                'reply_level' => (int)$form['reply_level'],
                'hide_if_no_access' => $form['hide_if_no_access'],
                'is_closed' => $form['is_closed'],
                'quick_nav' => (int)$form['quick_nav'],
                'require_prefix' => $type === 'forum' ? $form['require_prefix'] : 0,
                'default_prefix_id' => $form['default_prefix_id'] ? (int)$form['default_prefix_id'] : null,
                'title_hint' => adm_null($form['title_hint']),
                'thread_template' => adm_null($form['thread_template']),
                'form_json' => $parsedForm ? json_encode($parsedForm, JSON_UNESCAPED_UNICODE) : null,
            ];
            if ($id) {
                db_update('nodes', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('nodes', $data);
            }
            db_exec('DELETE FROM node_prefixes WHERE node_id = :n', ['n' => $id]);
            foreach ($prefixSel as $p) {
                db_insert('node_prefixes', ['node_id' => $id, 'prefix_id' => (int)$p]);
            }
            nodes_all(true);
            node_rebuild($id);
            flash('success', ($old ? 'Изменения в «' : 'Создан раздел «') . $form['title'] . ($old ? '» сохранены.' : '».'));
            if (input('after') === 'stay') {
                redirect(url('/admin/nodes.php', ['edit' => $id]));
            }
            redirect($self . '#node-' . $id);
        }
    }
}

// Предпросмотр анкеты: заголовок, вступление, поля (выключены)
function adm_form_preview_html(array $fp)
{
    $h = '';
    if ($fp['title'] !== '') {
        $h .= '<div class="adm-note">' . icon('type') . '<div>Заголовок темы будет таким: <b>' . e($fp['title']) . '</b><br><span class="muted">вместо {ключ} подставится ответ из поля</span></div></div>';
    }
    if (trim($fp['intro']) !== '') {
        $h .= '<div class="card bb fb-intro">' . bbcode($fp['intro']) . '</div>';
    }
    return $h . '<fieldset class="fb-preview-set" disabled>' . form_fields_html($fp) . '</fieldset>';
}

// ---------- Страница удаления ----------

if (isset($_GET['delete'])) {
    $node = node_get(query_int('delete'));
    if (!$node) {
        flash('error', 'Раздел не найден.');
        redirect($self);
    }
    $children = node_children_ids($node['id']);
    $threads = (int)db_val('SELECT COUNT(*) FROM threads WHERE node_id = :n', ['n' => (int)$node['id']]);
    $hasTarget = false;
    foreach (nodes_all() as $n) {
        if ($n['type'] === 'forum' && (int)$n['id'] !== (int)$node['id']) {
            $hasTarget = true;
            break;
        }
    }
    admin_header('Удаление: ' . $node['title'], 'nodes', [['Разделы форума', $self], ['Удаление', current_url()]]);
    ?>
<div class="card adm-danger">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('trash') ?> Удалить «<?= e($node['title']) ?>»?</h2>
      <p><?= e($types[$node['type']] ?? $node['type']) ?> · тем: <?= num($threads) ?> · подразделов: <?= count($children) ?></p>
    </div>
  </div>
  <?php if ($children): ?>
  <div class="adm-note warn"><?= icon('alert') ?><div>У этого раздела есть подразделы:
    <?php $names = []; foreach ($children as $cid) { $names[] = '«' . e(node_get($cid)['title']) . '»'; } echo implode(', ', $names); ?>.
    Сначала удалите их или перенесите в другое место (поле «Родитель» в настройках подраздела).</div></div>
  <div class="form-actions mt-2"><a class="btn" href="<?= e($self) ?>"><?= icon('chevron-left') ?> Назад к разделам</a></div>
  <?php elseif ($threads > 0 && !$hasTarget): ?>
  <div class="adm-note warn"><?= icon('alert') ?><div>В разделе есть темы, а перенести их некуда: создайте другой раздел типа «Раздел».</div></div>
  <div class="form-actions mt-2"><a class="btn" href="<?= e($self) ?>"><?= icon('chevron-left') ?> Назад к разделам</a></div>
  <?php else: ?>
  <form method="post" action="<?= e($self) ?>" class="form" data-confirm="Удалить раздел «<?= e($node['title']) ?>»? Это нельзя отменить.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$node['id'] ?>">
    <?php if ($threads > 0): ?>
    <div class="form-row">
      <label class="label" for="target">Куда перенести темы (<?= num($threads) ?>)</label>
      <select class="select" id="target" name="target" required>
        <option value="">Выберите раздел...</option>
        <?= adm_node_options(0, [(int)$node['id']], ['forum']) ?>
      </select>
      <div class="hint">Все темы этого раздела, вместе с сообщениями, окажутся в выбранном разделе. Категории и ссылки выбрать нельзя.</div>
    </div>
    <?php else: ?>
    <div class="adm-note"><?= icon('info') ?><div>Тем в разделе нет, его можно удалить сразу. Отметки «прочитано» и подписки на раздел тоже удалятся.</div></div>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Удалить раздел</button>
      <a class="btn btn-ghost" href="<?= e($self) ?>">Отмена</a>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php
    admin_footer();
    exit;
}

// ---------- Форма создания / изменения ----------

$isEdit = isset($_GET['edit']) || (is_post() && input('action') === 'save' && input_int('id'));
$isNew = isset($_GET['new']) || (is_post() && input('action') === 'save' && !input_int('id'));

if ($isEdit || $isNew) {
    if ($form === null) {
        if ($isEdit) {
            $node = node_get(query_int('edit'));
            if (!$node) {
                flash('error', 'Раздел не найден.');
                redirect($self);
            }
            $form = $node;
            $prefixSel = array_keys(node_prefixes($node['id']));
        } else {
            $t = query_str('type', 'forum');
            $form = adm_node_defaults(isset($types[$t]) ? $t : 'forum', query_int('parent'));
            if ($form['parent_id'] && !adm_node_parent_ok(0, $form['parent_id'])) {
                $form['parent_id'] = null;
            }
        }
    }
    $id = (int)$form['id'];
    $exclude = $id ? node_subtree_ids($id) : [];
    foreach (nodes_all() as $n) {
        if ($n['type'] === 'link') {
            $exclude[] = (int)$n['id'];
        }
    }
    $parsedForm = form_validate_json((string)$form['form_json'])[0];
    $rawJson = $form['form_json'] ? (string)$form['form_json'] : '';
    if ($parsedForm && !isset($errors['form_json'])) {
        $rawJson = json_encode($parsedForm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    if ($id) {
        $title = 'Изменение: ' . node_get($id)['title'];
    } else {
        $title = $form['type'] === 'category' ? 'Новая категория' : ($form['type'] === 'link' ? 'Новая ссылка' : 'Новый раздел');
    }
    admin_header($title, 'nodes', [['Разделы форума', $self], [$id ? 'Изменение' : 'Создание', current_url()]]);
    $lvl = function ($name, $label, $hint) use ($form, $errors) {
        $h = '<div class="form-row"><label class="label" for="' . $name . '">' . e($label) . '</label><select class="select' . (isset($errors[$name]) ? ' is-invalid' : '') . '" id="' . $name . '" name="' . $name . '">';
        foreach (adm_level_options($form[$name]) as $v => $t) {
            $h .= '<option value="' . (int)$v . '"' . adm_sel($form[$name], $v) . '>' . e($t) . '</option>';
        }
        return $h . '</select><div class="hint">' . e($hint) . '</div>' . adm_err($errors, $name) . '</div>';
    };
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live id="node-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">

  <div class="card" id="sec-main">
    <div class="adm-card-head"><div><h2><?= icon('folder') ?> Основное</h2><p>Как раздел называется и где он находится в списке форума.</p></div>
      <?php if ($id && $form['type'] !== 'link'): ?><a class="btn btn-sm btn-ghost" href="<?= e(node_url($form)) ?>" target="_blank"><?= icon('external') ?> Открыть на форуме</a><?php endif; ?>
    </div>

    <div class="form-row">
      <span class="label">Тип</span>
      <div class="adm-types">
        <label class="adm-type"><input type="radio" name="type" value="category"<?= adm_chk($form['type'] === 'category') ?>><?= icon('folder') ?><span><b>Категория</b><small>Заголовок-блок на главной форума, внутри - разделы</small></span></label>
        <label class="adm-type"><input type="radio" name="type" value="forum"<?= adm_chk($form['type'] === 'forum') ?>><?= icon('chats') ?><span><b>Раздел</b><small>Здесь создают темы и пишут ответы</small></span></label>
        <label class="adm-type"><input type="radio" name="type" value="link"<?= adm_chk($form['type'] === 'link') ?>><?= icon('link') ?><span><b>Ссылка</b><small>Строка в списке, ведёт на другую страницу</small></span></label>
      </div>
      <?= adm_err($errors, 'type') ?>
    </div>

    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="title">Название <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="120" required>
        <?= adm_err($errors, 'title') ?>
      </div>
      <div class="form-row">
        <label class="label" for="parent_id">Родитель</label>
        <select class="select<?= isset($errors['parent_id']) ? ' is-invalid' : '' ?>" id="parent_id" name="parent_id">
          <option value="0">- Верхний уровень (без родителя) -</option>
          <?= adm_node_options((int)$form['parent_id'], $exclude) ?>
        </select>
        <div class="hint">Внутри чего показывать. Категории обычно на верхнем уровне.</div>
        <?= adm_err($errors, 'parent_id') ?>
      </div>
    </div>

    <div class="form-row">
      <label class="label" for="description">Описание</label>
      <textarea class="textarea textarea-sm<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" maxlength="500" rows="2"><?= e($form['description']) ?></textarea>
      <div class="hint">Короткая строка под названием. Можно оставить пустым.</div>
      <?= adm_err($errors, 'description') ?>
    </div>

    <div class="form-row" data-show-if="type=link">
      <label class="label" for="link_url">Адрес ссылки <span class="req">*</span></label>
      <input class="input<?= isset($errors['link_url']) ? ' is-invalid' : '' ?>" id="link_url" name="link_url" value="<?= e($form['link_url']) ?>" maxlength="255" placeholder="https://discord.gg/... или /wiki/">
      <div class="hint">Другой сайт - полный адрес с https://. Страница этого сайта - путь, который начинается с /, например /forum/forum.php?id=30</div>
      <?= adm_err($errors, 'link_url') ?>
    </div>

    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" id="display_order" name="display_order" type="number" value="<?= e($form['display_order']) ?>">
        <div class="hint">Меньшее число - выше среди соседних разделов.</div>
        <?= adm_err($errors, 'display_order') ?>
      </div>
      <div class="form-row">
        <label class="label" for="quick_nav">Номер в «Быстрой навигации»</label>
        <input class="input<?= isset($errors['quick_nav']) ? ' is-invalid' : '' ?>" id="quick_nav" name="quick_nav" type="number" min="0" max="1000" value="<?= e($form['quick_nav']) ?>">
        <div class="hint">0 - не показывать в «Быстрой навигации». 1, 2, 3... - место кнопки в блоке справа.</div>
        <?= adm_err($errors, 'quick_nav') ?>
      </div>
    </div>

    <div class="form-row">
      <span class="label">Иконка</span>
      <?= adm_icon_picker('icon', $form['icon']) ?>
      <div class="hint">Показывается слева от названия в списке разделов.</div>
    </div>
  </div>

  <div class="card" id="sec-access">
    <div class="adm-card-head"><div><h2><?= icon('lock') ?> Доступ</h2><p>Кто может видеть раздел и писать в нём. Модераторы и администрация имеют доступ всегда.</p></div></div>
    <div class="form-grid-3">
      <?= $lvl('view_level', 'Кто видит', 'Остальные увидят «Приватный» или не увидят раздел вовсе.') ?>
      <div data-show-if="type=forum"><?= $lvl('thread_level', 'Кто создаёт темы', 'Новые темы в этом разделе.') ?></div>
      <div data-show-if="type=forum"><?= $lvl('reply_level', 'Кто отвечает', 'Ответы в темах раздела.') ?></div>
    </div>
    <div class="adm-opts adm-opts-2">
      <label class="adm-opt"><input type="checkbox" name="hide_if_no_access" value="1"<?= adm_chk($form['hide_if_no_access']) ?>><span><b>Прятать, если нет доступа</b><small>Без галочки раздел виден всем, но с надписью «Приватный»</small></span></label>
      <label class="adm-opt" data-show-if="type=forum"><input type="checkbox" name="is_closed" value="1"<?= adm_chk($form['is_closed']) ?>><span><b>Раздел закрыт</b><small>Раздел закрыт - новые темы и ответы только модераторам</small></span></label>
    </div>
  </div>

  <div class="card" id="sec-threads" data-show-if="type=forum">
    <div class="adm-card-head"><div><h2><?= icon('tag') ?> Темы и префиксы</h2><p>Префиксы - цветные метки перед названием темы: «На рассмотрении», «Одобрено» и т.п.</p></div></div>
    <div class="form-row">
      <span class="label">Разрешённые префиксы</span>
      <?php if (prefixes_all()): ?>
      <div class="adm-checkgrid">
        <?php foreach (prefixes_all() as $pid => $p): ?>
        <label class="adm-checkpill"><input type="checkbox" name="prefixes[]" value="<?= (int)$pid ?>"<?= adm_chk(in_array((int)$pid, array_map('intval', $prefixSel), true)) ?>><?= prefix_html($pid) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="hint">Какие префиксы можно выбрать при создании темы в этом разделе. Создать новые - в меню «<a href="<?= e(url('/admin/prefixes.php')) ?>">Префиксы</a>».</div>
      <?php else: ?>
      <div class="hint">Префиксов пока нет. Создайте их в меню «<a href="<?= e(url('/admin/prefixes.php')) ?>">Префиксы</a>».</div>
      <?php endif; ?>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="default_prefix_id">Префикс по умолчанию</label>
        <select class="select" id="default_prefix_id" name="default_prefix_id">
          <option value="0">Нет</option>
          <?php foreach (prefixes_all() as $pid => $p): ?>
          <option value="<?= (int)$pid ?>"<?= adm_sel($form['default_prefix_id'], $pid) ?>><?= e($p['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Ставится новой теме сам: обычные пользователи не смогут выбрать другой префикс. Например «На рассмотрении» для жалоб.</div>
      </div>
      <div class="form-row">
        <span class="label">&nbsp;</span>
        <label class="adm-opt"><input type="checkbox" name="require_prefix" value="1"<?= adm_chk($form['require_prefix']) ?>><span><b>Префикс обязателен</b><small>Тему нельзя создать без префикса</small></span></label>
        <?= adm_err($errors, 'require_prefix') ?>
      </div>
    </div>
    <div class="form-row">
      <label class="label" for="title_hint">Подсказка в поле «Заголовок»</label>
      <input class="input<?= isset($errors['title_hint']) ? ' is-invalid' : '' ?>" id="title_hint" name="title_hint" value="<?= e($form['title_hint']) ?>" maxlength="120" placeholder="Например: Жалоба на Nick_Name">
      <div class="hint">Серый текст в пустом поле заголовка при создании темы.</div>
      <?= adm_err($errors, 'title_hint') ?>
    </div>
    <div class="form-row">
      <label class="label" for="thread_template">Шаблон текста новой темы</label>
      <textarea class="textarea" id="thread_template" name="thread_template" rows="6" data-editor><?= e($form['thread_template']) ?></textarea>
      <div class="hint">Этот текст уже будет в редакторе, когда пользователь создаёт тему (например, пункты формы жалобы). Если у раздела есть анкета (ниже), вместо редактора показывается анкета.</div>
      <?= adm_err($errors, 'thread_template') ?>
    </div>
  </div>

  <div class="card" id="sec-form" data-show-if="type=forum">
    <div class="adm-card-head"><div><h2><?= icon('rules') ?> Анкета для новых тем</h2><p>Вместо обычного редактора пользователь заполняет поля, а заголовок и текст темы собираются из ответов - как формы жалоб на Аризоне. Чтобы убрать анкету, удалите все поля.</p></div></div>

    <div class="fb" data-form-builder>
      <div class="form-grid">
        <div class="form-row">
          <label class="label" for="fb-title">Шаблон заголовка темы</label>
          <input class="input" id="fb-title" data-fb-title maxlength="200" placeholder="Жалоба на {offender} | {rule}">
          <div class="hint">Используйте {ключ} поля, например: Жалоба на {offender} | {rule}. Пусто - заголовок соберётся из первых двух ответов.</div>
        </div>
        <div class="form-row">
          <span class="label">Ключи полей</span>
          <div class="fb-keys" data-fb-keys><span class="muted small">Добавьте поля - здесь появятся их ключи. Нажмите на ключ, чтобы вставить его в шаблон.</span></div>
        </div>
      </div>
      <div class="form-row">
        <label class="label" for="fb-intro">Текст над анкетой (необязательно)</label>
        <textarea class="textarea textarea-sm" id="fb-intro" data-fb-intro rows="3" data-editor></textarea>
        <div class="hint">Например, правила подачи жалобы. Можно использовать BB-коды.</div>
      </div>

      <div class="fb-list" data-fb-list></div>
      <div class="fb-empty adm-empty" data-fb-empty><?= icon('rules') ?>Анкеты нет - темы создаются обычным редактором. Нажмите «Добавить поле», чтобы сделать анкету.</div>
      <div class="btn-row">
        <button class="btn btn-white" type="button" data-fb-add><?= icon('plus') ?> Добавить поле</button>
        <button class="btn btn-ghost" type="button" data-fb-clear data-confirm="Удалить все поля анкеты?"><?= icon('trash') ?> Убрать анкету</button>
      </div>

      <details class="fb-raw"<?= isset($errors['form_json']) ? ' open' : '' ?>>
        <summary><?= icon('code') ?> Редактировать JSON вручную</summary>
        <div class="form-row mt-1">
          <textarea class="textarea fb-json<?= isset($errors['form_json']) ? ' is-invalid' : '' ?>" name="form_json" id="form_json" rows="10" spellcheck="false" data-fb-json><?= e($rawJson) ?></textarea>
          <div class="hint">Для опытных: конструктор выше и это поле связаны. Правильный JSON сразу попадёт в конструктор.</div>
          <div class="field-error" data-fb-json-error hidden></div>
          <?= adm_err($errors, 'form_json') ?>
        </div>
      </details>
    </div>

    <div class="form-row">
      <span class="label">Предпросмотр анкеты</span>
      <div class="fb-preview" data-fb-preview data-url="<?= e($self) ?>">
        <?= $parsedForm ? adm_form_preview_html($parsedForm) : '<div class="adm-empty">' . icon('eye') . 'Здесь будет видно, как анкета выглядит для пользователя.</div>' ?>
      </div>
    </div>
  </div>

  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit" name="after" value="list"><?= icon('check') ?> Сохранить</button>
    <button class="btn" type="submit" name="after" value="stay">Сохранить и продолжить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">Отмена</a>
    <?php if ($id): ?><a class="btn btn-ghost btn-danger-text" href="<?= e(url('/admin/nodes.php', ['delete' => $id])) ?>"><?= icon('trash') ?> Удалить</a><?php endif; ?>
    <span class="hint">Поля с <span class="req">*</span> обязательны</span>
  </div>
</form>

<template id="fb-row-tpl">
  <div class="fb-row">
    <div class="fb-row-head">
      <span class="fb-num" data-fb-num>1</span>
      <input class="input fb-label" data-k="label" placeholder="Вопрос, например: Игровой ник нарушителя" maxlength="120">
      <select class="select fb-type" data-k="type">
        <?php foreach (form_types() as $tk => $tl): ?><option value="<?= e($tk) ?>"><?= e($tl) ?></option><?php endforeach; ?>
      </select>
      <label class="check fb-req"><input type="checkbox" data-k="required"><span>Обязательно</span></label>
      <span class="fb-tools">
        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-fb-up title="Выше"><?= icon('chevron-up') ?></button>
        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-fb-down title="Ниже"><?= icon('chevron-down') ?></button>
        <button class="btn btn-sm btn-danger btn-icon" type="button" data-fb-remove title="Удалить поле"><?= icon('x') ?></button>
      </span>
    </div>
    <div class="fb-row-body">
      <div class="form-row"><label class="label">Ключ для заголовка</label><input class="input input-sm fb-key" data-k="key" maxlength="32" placeholder="создастся сам" title="Латинские буквы, цифры и _"></div>
      <div class="form-row" data-fb-show="text,textarea,url,number"><label class="label">Подсказка внутри поля</label><input class="input input-sm" data-k="placeholder" maxlength="120" placeholder="Серый текст в пустом поле"></div>
      <div class="form-row" data-fb-show="text,textarea,url,number"><label class="label">Макс. длина</label><input class="input input-sm" data-k="max" type="number" min="1" max="10000" placeholder="по умолчанию"></div>
      <div class="form-row" data-fb-show="checkbox"><label class="label">Текст у галочки</label><input class="input input-sm" data-k="text" maxlength="120" placeholder="Да"></div>
      <div class="form-row fb-wide" data-fb-show="radio,select"><label class="label">Варианты ответа (каждый с новой строки)</label><textarea class="textarea textarea-sm" data-k="options" rows="3" placeholder="Вариант 1&#10;Вариант 2"></textarea></div>
      <div class="form-row fb-wide"><label class="label">Пояснение под полем</label><input class="input input-sm" data-k="hint" maxlength="500" placeholder="Необязательно, можно BB-коды"></div>
    </div>
  </div>
</template>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$tree = adm_node_tree();
$prefixCount = [];
foreach (db_all('SELECT node_id, COUNT(*) AS c FROM node_prefixes GROUP BY node_id') as $r) {
    $prefixCount[(int)$r['node_id']] = (int)$r['c'];
}
admin_header('Разделы форума', 'nodes');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('folder') ?> Разделы форума</h2>
      <p>Категории - это блоки на главной форума, разделы - места для тем. Порядок меняется числами справа: меньшее число - выше.</p>
    </div>
    <div class="btn-row">
      <a class="btn" href="<?= e(url('/admin/nodes.php', ['new' => 1, 'type' => 'category'])) ?>"><?= icon('plus') ?> Создать категорию</a>
      <a class="btn btn-white" href="<?= e(url('/admin/nodes.php', ['new' => 1, 'type' => 'forum'])) ?>"><?= icon('plus') ?> Создать раздел</a>
      <a class="btn btn-ghost" href="<?= e(url('/admin/nodes.php', ['new' => 1, 'type' => 'link'])) ?>"><?= icon('link') ?> Ссылка</a>
    </div>
  </div>

  <?php if (!$tree): ?>
  <div class="adm-empty"><?= icon('folder') ?>Разделов пока нет. Начните с категории, например «Главный раздел».</div>
  <?php else: ?>
  <form method="post" action="<?= e($self) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="order">
    <div class="table-wrap">
      <table class="table adm-tree">
        <thead><tr><th class="tree-col">Раздел</th><th class="num hide-md" title="Темы / сообщения">Темы / сообщ.</th><th class="center hide-sm">Порядок</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tree as $row): $n = $row['node']; $nid = (int)$n['id']; $hasKids = (bool)node_children_ids($nid); $isCat = $n['type'] === 'category'; ?>
          <tr id="node-<?= $nid ?>" class="<?= $isCat ? 'is-cat' : '' ?>">
            <td class="tree-name" style="--d:<?= (int)$row['depth'] ?>">
              <div class="adm-cell-main">
                <?php if ($row['depth'] > 0): ?><span class="tree-corner" aria-hidden="true"></span><?php endif; ?>
                <span class="node-ico"><?= icon($n['icon']) ?></span>
                <div class="adm-cell-text">
                  <a class="adm-cell-title" href="<?= e(url('/admin/nodes.php', ['edit' => $nid])) ?>"><?= e($n['title']) ?></a>
                  <span class="tags" style="display:inline-flex;vertical-align:1px;margin-left:4px">
                    <span class="tag <?= $isCat ? 'tag-purple' : ($n['type'] === 'link' ? 'tag-teal' : 'tag-info') ?>"><?= e($types[$n['type']] ?? $n['type']) ?></span>
                    <?php if ($n['is_closed'] && $n['type'] === 'forum'): ?><span class="tag tag-danger"><?= icon('lock') ?> Закрыт</span><?php endif; ?>
                    <?php if ($n['hide_if_no_access']): ?><span class="tag"><?= icon('eye-off') ?> Скрыт</span><?php endif; ?>
                    <?php if ($n['type'] === 'forum' && !empty($n['form_json'])): ?><span class="tag tag-warning"><?= icon('rules') ?> Анкета</span><?php endif; ?>
                    <?php if ((int)$n['quick_nav']): ?><span class="tag" title="Место в «Быстрой навигации» справа"><?= icon('star') ?> Быстр. нав.: <?= (int)$n['quick_nav'] ?></span><?php endif; ?>
                    <?php if ($n['type'] === 'forum' && !empty($prefixCount[$nid])): ?><span class="tag" title="Разрешённых префиксов<?= $n['require_prefix'] ? ', префикс обязателен' : '' ?>"><?= icon('tag') ?> <?= $prefixCount[$nid] ?><?= $n['require_prefix'] ? ' · обяз.' : '' ?></span><?php endif; ?>
                  </span>
                  <div class="adm-levels">
                    <span>Просмотр: <b><?= e(adm_level_short($n['view_level'])) ?></b></span>
                    <?php if ($n['type'] === 'forum'): ?>
                    <span>Темы: <b><?= e(adm_level_short($n['thread_level'])) ?></b></span>
                    <span>Ответы: <b><?= e(adm_level_short($n['reply_level'])) ?></b></span>
                    <?php elseif ($n['type'] === 'link'): ?>
                    <span class="muted"><?= e(str_limit($n['link_url'], 50)) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
            <td class="num hide-md"><?= $n['type'] === 'forum' ? num($n['thread_count']) . ' <span class="muted">/ ' . num($n['post_count']) . '</span>' : '<span class="muted">-</span>' ?></td>
            <td class="center hide-sm"><input class="input input-order" type="number" name="order[<?= $nid ?>]" value="<?= (int)$n['display_order'] ?>" aria-label="Порядок «<?= e($n['title']) ?>»"></td>
            <td class="actions">
              <div class="adm-row-actions">
                <a class="btn btn-sm" href="<?= e(url('/admin/nodes.php', ['edit' => $nid])) ?>" title="Изменить"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
                <?php if ($n['type'] !== 'link'): ?>
                <a class="btn btn-sm btn-ghost hide-sm" href="<?= e(url('/admin/nodes.php', ['new' => 1, 'type' => 'forum', 'parent' => $nid])) ?>" title="Создать подраздел внутри"><?= icon('plus') ?><span class="hide-sm">Подраздел</span></a>
                <?php endif; ?>
                <?php if ($hasKids): ?>
                <span class="btn btn-sm btn-ghost btn-icon disabled" title="Сначала удалите подразделы"><?= icon('trash') ?></span>
                <?php else: ?>
                <a class="btn btn-sm btn-ghost btn-icon btn-danger-text" href="<?= e(url('/admin/nodes.php', ['delete' => $nid])) ?>" title="Удалить"><?= icon('trash') ?></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="adm-foot">
      <span class="adm-count">Всего: <?= count($tree) ?>. Порядок считается среди разделов с одним родителем.</span>
      <button class="btn btn-white hide-sm" type="submit"><?= icon('check') ?> Сохранить порядок</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php
admin_footer();
