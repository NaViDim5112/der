<?php
// Админ-панель: объявления-плашки (модуль «Сообщество»)
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/notices.php');
if (!com_ready()) {
    admin_header('Объявления', 'notices');
    echo '<div class="card"><div class="adm-note">' . icon('alert') . '<div>Нужно обновить базу сайта: откройте <a href="' . e(url('/admin/')) . '">Обзор</a> и нажмите «Обновить базу».</div></div></div>';
    admin_footer();
    exit;
}

$styles = com_notice_styles();
$audiences = com_notice_audiences();
$areas = com_notice_areas();
$errors = [];
$form = null;

// Дата из datetime-local или null; $bad = true, если формат неверный
$dateIn = function ($key, &$bad) {
    $v = trim((string)input($key));
    $bad = false;
    if ($v === '') {
        return null;
    }
    $ts = strtotime($v);
    if (!$ts || !preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}~', $v)) {
        $bad = true;
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
};

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM com_notices WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Объявление не найдено.');
            redirect($self);
        }
        $form = [
            'id' => $id,
            'title' => com_clean_line(input('title')),
            'message' => input_text('message'),
            'style' => input('style'),
            'audience' => input('audience'),
            'min_level' => input('min_level'),
            'max_level' => input('max_level'),
            'area' => input('area'),
            'link_url' => trim(input('link_url')),
            'link_text' => com_clean_line(input('link_text')),
            'dismissible' => input_bool('dismissible') ? 1 : 0,
            'starts_at' => input('starts_at'),
            'ends_at' => input('ends_at'),
            'display_order' => input('display_order'),
            'is_active' => input_bool('is_active') ? 1 : 0,
        ];
        if ($form['title'] === '' && $form['message'] === '') {
            $errors['title'] = 'Напишите заголовок или текст.';
        }
        if (!adm_len_ok($form['title'], 120)) {
            $errors['title'] = 'Заголовок - не больше 120 символов.';
        }
        if (!adm_len_ok($form['message'], 2000)) {
            $errors['message'] = 'Текст - не больше 2000 символов.';
        }
        foreach (['style' => $styles, 'audience' => $audiences, 'area' => $areas] as $k => $list) {
            if (!isset($list[$form[$k]])) {
                $errors[$k] = 'Выберите значение из списка.';
            }
        }
        $levels = [];
        foreach (['min_level', 'max_level'] as $k) {
            $v = trim((string)$form[$k]);
            if ($v === '') {
                $levels[$k] = null;
            } elseif (preg_match('~^\d{1,3}$~', $v)) {
                $levels[$k] = (int)$v;
            } else {
                $errors[$k] = 'Уровень - число от 0 до 999 или пусто.';
            }
        }
        if (!$errors && $levels['min_level'] !== null && $levels['max_level'] !== null && $levels['min_level'] > $levels['max_level']) {
            $errors['max_level'] = 'Максимальный уровень меньше минимального.';
        }
        if ($form['link_url'] !== '' && (com_link_href($form['link_url']) === '' || !adm_len_ok($form['link_url'], 255))) {
            $errors['link_url'] = 'Ссылка: путь на сайте (/start.php) или адрес http(s)://';
        }
        if (!adm_len_ok($form['link_text'], 60)) {
            $errors['link_text'] = 'Текст кнопки - не больше 60 символов.';
        }
        $bad = false;
        $starts = $dateIn('starts_at', $bad);
        if ($bad) {
            $errors['starts_at'] = 'Неверная дата начала.';
        }
        $ends = $dateIn('ends_at', $bad);
        if ($bad) {
            $errors['ends_at'] = 'Неверная дата окончания.';
        }
        if ($starts && $ends && strtotime($ends) <= strtotime($starts)) {
            $errors['ends_at'] = 'Окончание должно быть позже начала.';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = [
                'title' => $form['title'],
                'message' => $form['message'] !== '' ? $form['message'] : null,
                'style' => $form['style'],
                'audience' => $form['audience'],
                'min_level' => $levels['min_level'],
                'max_level' => $levels['max_level'],
                'area' => $form['area'],
                'link_url' => adm_null($form['link_url']),
                'link_text' => adm_null($form['link_text']),
                'dismissible' => $form['dismissible'],
                'starts_at' => $starts,
                'ends_at' => $ends,
                'display_order' => $order,
                'is_active' => $form['is_active'],
                'updated_at' => now(),
            ];
            if ($id) {
                db_update('com_notices', $data, 'id = :id', ['id' => $id]);
            } else {
                $data['created_at'] = now();
                $data['revision'] = 1;
                $id = db_insert('com_notices', $data);
            }
            flash('success', 'Объявление сохранено' . ($form['is_active'] ? '.' : ' (выключено - его никто не видит).'));
            redirect($self . '#notice-' . $id);
        }
    }

    if ($action === 'toggle') {
        $n = db_one('SELECT * FROM com_notices WHERE id = :id', ['id' => input_int('id')]);
        if ($n) {
            db_update('com_notices', ['is_active' => $n['is_active'] ? 0 : 1, 'updated_at' => now()], 'id = :id', ['id' => (int)$n['id']]);
            flash('success', $n['is_active'] ? 'Объявление выключено.' : 'Объявление включено.');
        }
        redirect($self . '#notice-' . input_int('id'));
    }

    if ($action === 'reshow') {
        $n = db_one('SELECT * FROM com_notices WHERE id = :id', ['id' => input_int('id')]);
        if ($n) {
            // Новая ревизия: закрытая плашка снова появится у всех (и у гостей с cookie)
            db_update('com_notices', ['revision' => (int)$n['revision'] + 1, 'updated_at' => now()], 'id = :id', ['id' => (int)$n['id']]);
            db_exec('DELETE FROM com_notice_dismiss WHERE notice_id = :n', ['n' => (int)$n['id']]);
            flash('success', 'Объявление снова покажется всем, кто его закрыл.');
        }
        redirect($self . '#notice-' . input_int('id'));
    }

    if ($action === 'delete') {
        $n = db_one('SELECT * FROM com_notices WHERE id = :id', ['id' => input_int('id')]);
        if ($n) {
            db_exec('DELETE FROM com_notice_dismiss WHERE notice_id = :n', ['n' => (int)$n['id']]);
            db_exec('DELETE FROM com_notices WHERE id = :n', ['n' => (int)$n['id']]);
            flash('success', 'Объявление удалено.');
        }
        redirect($self);
    }
}

// ---------- Форма ----------

$editId = query_int('edit');
if ($form !== null || $editId || isset($_GET['new'])) {
    if ($form === null) {
        if ($editId) {
            $form = db_one('SELECT * FROM com_notices WHERE id = :id', ['id' => $editId]);
            if (!$form) {
                flash('error', 'Объявление не найдено.');
                redirect($self);
            }
            $form['starts_at'] = adm_dt_local($form['starts_at']);
            $form['ends_at'] = adm_dt_local($form['ends_at']);
        } else {
            $form = ['id' => 0, 'title' => '', 'message' => '', 'style' => 'info', 'audience' => 'all', 'min_level' => '', 'max_level' => '', 'area' => 'forum',
                'link_url' => '', 'link_text' => '', 'dismissible' => 1, 'starts_at' => '', 'ends_at' => '', 'is_active' => 1,
                'display_order' => (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM com_notices')];
        }
    }
    $id = (int)$form['id'];
    $closed = $id ? (int)db_val('SELECT COUNT(*) FROM com_notice_dismiss WHERE notice_id = :n', ['n' => $id]) : 0;
    admin_header($id ? 'Объявление: ' . ($form['title'] !== '' ? $form['title'] : '#' . $id) : 'Новое объявление', 'notices', [[$id ? 'Изменение' : 'Создание', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('megaphone') ?> <?= $id ? 'Изменение объявления' : 'Новое объявление' ?></h2><p>Плашка вверху страниц. Не обещайте того, чего нет в игре.</p></div></div>
    <div class="form-row">
      <label class="label" for="title">Заголовок</label>
      <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="120" placeholder="Например: Технические работы в 03:00 МСК" data-com-preview="title">
      <?= adm_err($errors, 'title') ?>
    </div>
    <div class="form-row">
      <label class="label" for="message">Текст</label>
      <textarea class="textarea textarea-sm<?= isset($errors['message']) ? ' is-invalid' : '' ?>" id="message" name="message" rows="3" maxlength="2000" data-editor data-com-preview="message"><?= e((string)$form['message']) ?></textarea>
      <div class="hint">Можно BB-коды: [b], [url=...], [color=...].</div>
      <?= adm_err($errors, 'message') ?>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="link_url">Кнопка: ссылка</label>
        <input class="input<?= isset($errors['link_url']) ? ' is-invalid' : '' ?>" id="link_url" name="link_url" value="<?= e((string)$form['link_url']) ?>" maxlength="255" placeholder="/start.php или https://...">
        <?= adm_err($errors, 'link_url') ?>
      </div>
      <div class="form-row">
        <label class="label" for="link_text">Кнопка: текст</label>
        <input class="input<?= isset($errors['link_text']) ? ' is-invalid' : '' ?>" id="link_text" name="link_text" value="<?= e((string)$form['link_text']) ?>" maxlength="60" placeholder="Подробнее">
        <?= adm_err($errors, 'link_text') ?>
      </div>
    </div>
    <div class="form-row">
      <span class="label">Оформление</span>
      <div class="com-style-pick">
        <?php foreach ($styles as $k => $s): ?>
        <label class="com-style-opt com-notice com-notice-<?= e($k) ?>"><input type="radio" name="style" value="<?= e($k) ?>"<?= adm_chk($form['style'] === $k) ?>><span class="com-notice-icon"><?= icon($s[1]) ?></span><span><?= e($s[0]) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?= adm_err($errors, 'style') ?>
    </div>
  </div>

  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('users') ?> Кому и где</h2></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="audience">Кто видит</label>
        <select class="select<?= isset($errors['audience']) ? ' is-invalid' : '' ?>" id="audience" name="audience">
          <?php foreach ($audiences as $k => $a): ?><option value="<?= e($k) ?>"<?= adm_sel($form['audience'], $k) ?>><?= e($a) ?></option><?php endforeach; ?>
        </select>
        <?= adm_err($errors, 'audience') ?>
      </div>
      <div class="form-row">
        <label class="label" for="area">Где показывать</label>
        <select class="select<?= isset($errors['area']) ? ' is-invalid' : '' ?>" id="area" name="area">
          <?php foreach ($areas as $k => $a): ?><option value="<?= e($k) ?>"<?= adm_sel($form['area'], $k) ?>><?= e($a) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Форум - все страницы форума, кабинета и базы знаний. Сайт - главная и страницы сайта.</div>
        <?= adm_err($errors, 'area') ?>
      </div>
      <div class="form-row">
        <label class="label" for="min_level">Уровень от</label>
        <input class="input<?= isset($errors['min_level']) ? ' is-invalid' : '' ?>" id="min_level" name="min_level" value="<?= e((string)$form['min_level']) ?>" inputmode="numeric" maxlength="3" placeholder="не важно">
        <div class="hint">Уровни групп: 10 пользователь, 20 лидер, 30 хелпер, 40 модератор, 60 администратор... Гость - 0.</div>
        <?= adm_err($errors, 'min_level') ?>
      </div>
      <div class="form-row">
        <label class="label" for="max_level">Уровень до</label>
        <input class="input<?= isset($errors['max_level']) ? ' is-invalid' : '' ?>" id="max_level" name="max_level" value="<?= e((string)$form['max_level']) ?>" inputmode="numeric" maxlength="3" placeholder="не важно">
        <?= adm_err($errors, 'max_level') ?>
      </div>
      <div class="form-row">
        <label class="label" for="starts_at">Показывать с</label>
        <input class="input<?= isset($errors['starts_at']) ? ' is-invalid' : '' ?>" type="datetime-local" id="starts_at" name="starts_at" value="<?= e((string)$form['starts_at']) ?>">
        <?= adm_err($errors, 'starts_at') ?>
      </div>
      <div class="form-row">
        <label class="label" for="ends_at">Показывать до</label>
        <input class="input<?= isset($errors['ends_at']) ? ' is-invalid' : '' ?>" type="datetime-local" id="ends_at" name="ends_at" value="<?= e((string)$form['ends_at']) ?>">
        <div class="hint">Пусто - без срока. Время сайта (<?= e(date_default_timezone_get()) ?>).</div>
        <?= adm_err($errors, 'ends_at') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($form['display_order']) ?>">
        <?= adm_err($errors, 'display_order') ?>
      </div>
    </div>
    <div class="adm-opts adm-opts-2">
      <label class="adm-opt"><input type="checkbox" name="is_active" value="1"<?= adm_chk($form['is_active']) ?>><span><b>Включено</b><small>Выключенное объявление никто не видит.</small></span></label>
      <label class="adm-opt"><input type="checkbox" name="dismissible" value="1"<?= adm_chk($form['dismissible']) ?>><span><b>Можно закрыть</b><small>Крестик справа. Закрытое больше не показывается этому человеку<?= $closed ? ' (уже закрыли: ' . num($closed) . ')' : '' ?>.</small></span></label>
    </div>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">К списку</a>
  </div>
</form>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$notices = db_all('SELECT n.*, (SELECT COUNT(*) FROM com_notice_dismiss d WHERE d.notice_id = n.id AND d.revision = n.revision) AS closed FROM com_notices n ORDER BY n.is_active DESC, n.display_order, n.id');
admin_header('Объявления', 'notices');
?>
<div class="card">
  <div class="adm-card-head">
    <div>
      <h2><?= icon('megaphone') ?> Объявления-плашки</h2>
      <p>Плашки вверху страниц форума и сайта: кому показывать, когда и можно ли закрыть. Простое объявление для всех есть и в <a href="<?= e(url('/admin/settings.php')) ?>">Настройках</a>.</p>
    </div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/notices.php', ['new' => 1])) ?>"><?= icon('plus') ?> Создать объявление</a></div>
  </div>
  <?php if (!$notices): ?>
  <div class="adm-empty"><?= icon('megaphone') ?>Объявлений пока нет.</div>
  <?php endif; ?>
  <div class="com-notice-admin-list">
    <?php foreach ($notices as $n): $nid = (int)$n['id'];
        $state = 'Показывается';
        if (!$n['is_active']) {
            $state = 'Выключено';
        } elseif ($n['starts_at'] && strtotime($n['starts_at']) > time()) {
            $state = 'Начнётся ' . fdate($n['starts_at']);
        } elseif ($n['ends_at'] && strtotime($n['ends_at']) < time()) {
            $state = 'Закончилось ' . fdate($n['ends_at']);
        } ?>
    <div class="com-notice-admin<?= $n['is_active'] ? '' : ' is-off' ?>" id="notice-<?= $nid ?>">
      <?= com_notice_html($n, true) ?>
      <div class="com-notice-admin-meta">
        <span class="tag<?= $state === 'Показывается' ? ' com-tag-on' : '' ?>"><?= e($state) ?></span>
        <span class="tag"><?= e($audiences[$n['audience']] ?? $n['audience']) ?><?= $n['min_level'] !== null ? ', от ' . (int)$n['min_level'] : '' ?><?= $n['max_level'] !== null ? ', до ' . (int)$n['max_level'] : '' ?></span>
        <span class="tag"><?= e($areas[$n['area']] ?? $n['area']) ?></span>
        <?php if ($n['ends_at'] && strtotime($n['ends_at']) >= time()): ?><span class="tag">до <?= e(fdate($n['ends_at'])) ?></span><?php endif; ?>
        <?php if ($n['dismissible']): ?><span class="tag">закрыли: <?= num($n['closed']) ?></span><?php endif; ?>
        <span class="com-notice-admin-actions">
          <a class="btn btn-sm" href="<?= e(url('/admin/notices.php', ['edit' => $nid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
          <form method="post" action="<?= e($self) ?>" class="com-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $nid ?>">
            <button class="btn btn-sm<?= $n['is_active'] ? '' : ' btn-success' ?>" type="submit"><?= icon($n['is_active'] ? 'eye-off' : 'eye') ?><span class="hide-sm"><?= $n['is_active'] ? 'Выключить' : 'Включить' ?></span></button></form>
          <?php if ($n['dismissible'] && $n['closed']): ?>
          <form method="post" action="<?= e($self) ?>" class="com-inline-form" data-confirm="Показать объявление снова всем, кто его закрыл?"><?= csrf_field() ?><input type="hidden" name="action" value="reshow"><input type="hidden" name="id" value="<?= $nid ?>">
            <button class="btn btn-sm btn-ghost" type="submit" title="Показать снова всем"><?= icon('refresh') ?><span class="hide-sm">Показать снова</span></button></form>
          <?php endif; ?>
          <form method="post" action="<?= e($self) ?>" class="com-inline-form" data-confirm="Удалить объявление?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $nid ?>">
            <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить"><?= icon('trash') ?></button></form>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
admin_footer();
