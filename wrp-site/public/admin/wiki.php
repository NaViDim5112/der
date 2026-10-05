<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$self = url('/admin/wiki.php');
$errors = [];
$catForm = null;
$artForm = null;

// Адрес статьи на сайте
function adm_wiki_article_url($slug)
{
    return url('/wiki/article.php', ['slug' => $slug]);
}

// Свободный адрес (slug): base, base-2, base-3...
function adm_wiki_unique_slug($table, $base, $max, $exceptId)
{
    $base = trim(substr($base, 0, $max), '-');
    if ($base === '') {
        $base = 'page';
    }
    $slug = $base;
    $n = 2;
    while (db_val('SELECT id FROM `' . $table . '` WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => (int)$exceptId])) {
        $suffix = '-' . $n++;
        $slug = rtrim(substr($base, 0, $max - strlen($suffix)), '-') . $suffix;
    }
    return $slug;
}

// Проверка slug из формы: пусто - создать из названия. Возвращает [slug, ошибка]
function adm_wiki_slug($table, $input, $title, $max, $id)
{
    $input = strtolower(trim((string)$input));
    if ($input === '') {
        return [adm_wiki_unique_slug($table, slugify($title), $max, $id), ''];
    }
    if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $input) || strlen($input) > $max) {
        return [$input, 'Адрес: только латинские буквы, цифры и дефисы (не больше ' . $max . ' символов), например kak-nachat-igrat.'];
    }
    if (db_val('SELECT id FROM `' . $table . '` WHERE slug = :s AND id <> :id', ['s' => $input, 'id' => (int)$id])) {
        return [$input, 'Такой адрес уже занят. Придумайте другой или оставьте поле пустым.'];
    }
    return [$input, ''];
}

$categories = db_all('SELECT c.*, (SELECT COUNT(*) FROM wiki_articles a WHERE a.category_id = c.id) AS articles FROM wiki_categories c ORDER BY c.display_order, c.id');
$catById = [];
foreach ($categories as $c) {
    $catById[(int)$c['id']] = $c;
}

// ---------- POST ----------

if (is_post()) {
    csrf_check();
    $action = input('action');

    if ($action === 'cat_delete') {
        $c = $catById[input_int('id')] ?? null;
        if (!$c) {
            flash('error', 'Категория не найдена.');
        } elseif ((int)$c['articles'] > 0) {
            flash('error', 'В категории «' . $c['title'] . '» есть статьи (' . (int)$c['articles'] . '). Сначала удалите их или перенесите в другую категорию.');
            redirect(url('/admin/wiki.php', ['category' => $c['id']]));
        } else {
            db_exec('DELETE FROM wiki_categories WHERE id = :id', ['id' => (int)$c['id']]);
            flash('success', 'Категория «' . $c['title'] . '» удалена.');
        }
        redirect($self);
    }

    if ($action === 'art_delete') {
        $a = db_one('SELECT id, title, category_id FROM wiki_articles WHERE id = :id', ['id' => input_int('id')]);
        if ($a) {
            db_exec('DELETE FROM wiki_articles WHERE id = :id', ['id' => (int)$a['id']]);
            flash('success', 'Статья «' . $a['title'] . '» удалена.');
            redirect(url('/admin/wiki.php', ['cat' => $a['category_id']]));
        }
        redirect($self);
    }

    if ($action === 'cat_save') {
        $id = input_int('id');
        if ($id && !isset($catById[$id])) {
            flash('error', 'Категория не найдена.');
            redirect($self);
        }
        $catForm = ['id' => $id, 'title' => input('title'), 'slug' => input('slug'), 'icon' => input('icon', 'book'),
            'description' => input('description'), 'display_order' => input('display_order')];
        if ($catForm['title'] === '') {
            $errors['title'] = 'Укажите название категории.';
        } elseif (!adm_len_ok($catForm['title'], 120)) {
            $errors['title'] = 'Название слишком длинное (максимум 120 символов).';
        }
        list($slug, $slugErr) = adm_wiki_slug('wiki_categories', $catForm['slug'], $catForm['title'], 64, $id);
        if ($slugErr) {
            $errors['slug'] = $slugErr;
        }
        if (!in_array($catForm['icon'], icon_names(), true)) {
            $catForm['icon'] = 'book';
        }
        if (!adm_len_ok($catForm['description'], 255)) {
            $errors['description'] = 'Описание слишком длинное (максимум 255 символов).';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = ['title' => $catForm['title'], 'slug' => $slug, 'icon' => $catForm['icon'], 'description' => adm_null($catForm['description']), 'display_order' => $order];
            if ($id) {
                db_update('wiki_categories', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('wiki_categories', $data);
            }
            flash('success', 'Категория «' . $catForm['title'] . '» сохранена.');
            redirect(url('/admin/wiki.php', ['cat' => $id]));
        }
    }

    if ($action === 'art_save') {
        $id = input_int('id');
        $old = $id ? db_one('SELECT * FROM wiki_articles WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            flash('error', 'Статья не найдена.');
            redirect($self);
        }
        $artForm = ['id' => $id, 'category_id' => input_int('category_id'), 'title' => input('title'), 'slug' => input('slug'),
            'summary' => input('summary'), 'body' => input_text('body'), 'display_order' => input('display_order'),
            'is_published' => input_bool('is_published') ? 1 : 0, 'views' => $old ? $old['views'] : 0,
            'created_at' => $old ? $old['created_at'] : null, 'updated_at' => $old ? $old['updated_at'] : null, 'author_id' => $old ? $old['author_id'] : null];
        if (!isset($catById[$artForm['category_id']])) {
            $errors['category_id'] = 'Выберите категорию.';
        }
        if ($artForm['title'] === '') {
            $errors['title'] = 'Укажите заголовок статьи.';
        } elseif (!adm_len_ok($artForm['title'], 150)) {
            $errors['title'] = 'Заголовок слишком длинный (максимум 150 символов).';
        }
        list($slug, $slugErr) = adm_wiki_slug('wiki_articles', $artForm['slug'], $artForm['title'], 96, $id);
        if ($slugErr) {
            $errors['slug'] = $slugErr;
        }
        if (!adm_len_ok($artForm['summary'], 255)) {
            $errors['summary'] = 'Краткое описание слишком длинное (максимум 255 символов).';
        }
        if ($artForm['body'] === '') {
            $errors['body'] = 'Напишите текст статьи.';
        } elseif (!adm_len_ok($artForm['body'], 200000)) {
            $errors['body'] = 'Текст слишком длинный.';
        }
        $order = adm_post_range('display_order', -100000, 100000);
        if ($order === null) {
            $errors['display_order'] = 'Порядок - целое число.';
        }
        if (!$errors) {
            $data = ['category_id' => $artForm['category_id'], 'title' => $artForm['title'], 'slug' => $slug, 'summary' => adm_null($artForm['summary']),
                'body' => $artForm['body'], 'display_order' => $order, 'is_published' => $artForm['is_published'], 'updated_at' => now()];
            if ($id) {
                if (!$old['author_id']) {
                    $data['author_id'] = uid();
                }
                db_update('wiki_articles', $data, 'id = :id', ['id' => $id]);
            } else {
                $data['author_id'] = uid();
                $data['created_at'] = now();
                $data['views'] = 0;
                $id = db_insert('wiki_articles', $data);
            }
            flash('success', 'Статья «' . $artForm['title'] . '» сохранена' . ($artForm['is_published'] ? '.' : ' как черновик (на сайте её не видно).'));
            if (input('after') === 'stay') {
                redirect(url('/admin/wiki.php', ['article' => $id]));
            }
            redirect(url('/admin/wiki.php', ['cat' => $artForm['category_id']]) . '#article-' . $id);
        }
    }
}

// Таблица статей
function adm_wiki_articles_table(array $articles, array $catById, $showCat)
{
    if (!$articles) {
        return '<div class="adm-empty">' . icon('file') . 'Статей пока нет.</div>';
    }
    $self = url('/admin/wiki.php');
    $h = '<div class="table-wrap"><table class="table"><thead><tr><th>Статья</th>' . ($showCat ? '<th class="hide-md">Категория</th>' : '')
        . '<th class="hide-sm">Статус</th><th class="num hide-md">Просм.</th><th class="hide-md">Изменена</th><th class="center hide-sm">Порядок</th><th></th></tr></thead><tbody>';
    foreach ($articles as $a) {
        $aid = (int)$a['id'];
        $h .= '<tr id="article-' . $aid . '"' . ($a['is_published'] ? '' : ' class="is-muted"') . '><td><div class="adm-cell-text">'
            . '<a class="adm-cell-title" href="' . e(url('/admin/wiki.php', ['article' => $aid])) . '">' . e($a['title']) . '</a>'
            . ($a['summary'] ? '<div class="adm-cell-sub">' . e(str_limit($a['summary'], 90)) . '</div>' : '') . '</div></td>';
        if ($showCat) {
            $c = $catById[(int)$a['category_id']] ?? null;
            $h .= '<td class="hide-md">' . ($c ? '<a class="tag" href="' . e(url('/admin/wiki.php', ['cat' => $c['id']])) . '">' . icon($c['icon']) . ' ' . e($c['title']) . '</a>' : '<span class="muted">-</span>') . '</td>';
        }
        $h .= '<td class="hide-sm">' . ($a['is_published'] ? '<span class="tag tag-success">Опубликована</span>' : '<span class="tag">Черновик</span>') . '</td>';
        $h .= '<td class="num hide-md">' . num($a['views']) . '</td>';
        $h .= '<td class="hide-md nowrap muted">' . e(fdate($a['updated_at'])) . '</td>';
        $h .= '<td class="center hide-sm">' . (int)$a['display_order'] . '</td>';
        $h .= '<td class="actions"><div class="adm-row-actions">'
            . '<a class="btn btn-sm" href="' . e(url('/admin/wiki.php', ['article' => $aid])) . '">' . icon('edit') . '<span class="hide-sm">Изменить</span></a>'
            . '<a class="btn btn-sm btn-ghost btn-icon" href="' . e(adm_wiki_article_url($a['slug'])) . '" target="_blank" title="Открыть на сайте">' . icon('external') . '</a>'
            . '<form method="post" action="' . e($self) . '" data-confirm="Удалить статью «' . e($a['title']) . '»? Это нельзя отменить.">' . csrf_field()
            . '<input type="hidden" name="action" value="art_delete"><input type="hidden" name="id" value="' . $aid . '">'
            . '<button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить">' . icon('trash') . '</button></form>'
            . '</div></td></tr>';
    }
    return $h . '</tbody></table></div>';
}

// ---------- Статья ----------

$articleId = query_int('article');
if ($artForm !== null || $articleId || isset($_GET['new_article'])) {
    if ($artForm === null) {
        if ($articleId) {
            $artForm = db_one('SELECT * FROM wiki_articles WHERE id = :id', ['id' => $articleId]);
            if (!$artForm) {
                flash('error', 'Статья не найдена.');
                redirect($self);
            }
        } else {
            $cat = query_int('cat');
            $artForm = ['id' => 0, 'category_id' => isset($catById[$cat]) ? $cat : 0, 'title' => '', 'slug' => '', 'summary' => '', 'body' => '',
                'display_order' => $cat ? (int)db_val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM wiki_articles WHERE category_id = :c', ['c' => $cat]) : 1,
                'is_published' => 1, 'views' => 0, 'created_at' => null, 'updated_at' => null, 'author_id' => null];
        }
    }
    $id = (int)$artForm['id'];
    if (!$categories) {
        flash('error', 'Сначала создайте хотя бы одну категорию.');
        redirect(url('/admin/wiki.php', ['new_category' => 1]));
    }
    $savedSlug = $id ? (string)db_val('SELECT slug FROM wiki_articles WHERE id = :id', ['id' => $id]) : '';
    $author = $artForm['author_id'] ? user_by_id($artForm['author_id']) : null;
    admin_header($id ? 'Статья: ' . $artForm['title'] : 'Новая статья', 'wiki', [[$id ? 'Изменение статьи' : 'Новая статья', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="art_save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head">
      <div><h2><?= icon('file') ?> <?= $id ? 'Изменение статьи' : 'Новая статья' ?></h2>
        <?php if ($id): ?><p>Создана <?= e(fdate($artForm['created_at'])) ?><?= $author ? ' · автор ' . e($author['username']) : '' ?> · изменена <?= e(fdate($artForm['updated_at'])) ?> · просмотров: <?= num($artForm['views']) ?></p><?php endif; ?>
      </div>
      <?php if ($savedSlug !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= e(adm_wiki_article_url($savedSlug)) ?>" target="_blank"><?= icon('external') ?> Открыть</a><?php endif; ?>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="title">Заголовок <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($artForm['title']) ?>" maxlength="150" required placeholder="Например: Как начать играть">
        <?= adm_err($errors, 'title') ?>
      </div>
      <div class="form-row">
        <label class="label" for="category_id">Категория <span class="req">*</span></label>
        <select class="select<?= isset($errors['category_id']) ? ' is-invalid' : '' ?>" id="category_id" name="category_id" required>
          <option value="">Выберите...</option>
          <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= adm_sel($artForm['category_id'], $c['id']) ?>><?= e($c['title']) ?></option><?php endforeach; ?>
        </select>
        <?= adm_err($errors, 'category_id') ?>
      </div>
      <div class="form-row">
        <label class="label" for="slug">Адрес страницы</label>
        <input class="input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" id="slug" name="slug" value="<?= e($artForm['slug']) ?>" maxlength="96" data-slug-from="title">
        <div class="hint">Часть ссылки на статью латиницей. Оставьте пустым - создастся из заголовка.</div>
        <?= adm_err($errors, 'slug') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($artForm['display_order']) ?>">
        <div class="hint">Меньшее число - выше в списке статей категории.</div>
        <?= adm_err($errors, 'display_order') ?>
      </div>
    </div>
    <div class="form-row">
      <label class="label" for="summary">Краткое описание</label>
      <input class="input<?= isset($errors['summary']) ? ' is-invalid' : '' ?>" id="summary" name="summary" value="<?= e($artForm['summary']) ?>" maxlength="255" placeholder="Одна строка: о чём статья">
      <div class="hint">Показывается в списке статей под заголовком.</div>
      <?= adm_err($errors, 'summary') ?>
    </div>
    <div class="form-row">
      <label class="label" for="body">Текст статьи <span class="req">*</span></label>
      <textarea class="textarea<?= isset($errors['body']) ? ' is-invalid' : '' ?>" id="body" name="body" rows="18" data-editor><?= e($artForm['body']) ?></textarea>
      <div class="hint">Оформляйте кнопками над текстом: заголовки (размер), списки, спойлеры, картинки и видео. «Предпросмотр» покажет, как это будет выглядеть.</div>
      <?= adm_err($errors, 'body') ?>
    </div>
    <label class="adm-opt"><input type="checkbox" name="is_published" value="1"<?= adm_chk($artForm['is_published']) ?>><span><b>Опубликована</b><small>Без галочки статья - черновик: на сайте её не видно, пока не опубликуете.</small></span></label>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit" name="after" value="list"><?= icon('check') ?> Сохранить</button>
    <button class="btn" type="submit" name="after" value="stay">Сохранить и продолжить</button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/wiki.php', ['cat' => $artForm['category_id'] ?: null])) ?>">Отмена</a>
  </div>
</form>
<?php
    admin_footer();
    exit;
}

// ---------- Категория ----------

$categoryId = query_int('category');
if ($catForm !== null || $categoryId || isset($_GET['new_category'])) {
    if ($catForm === null) {
        if ($categoryId) {
            if (!isset($catById[$categoryId])) {
                flash('error', 'Категория не найдена.');
                redirect($self);
            }
            $catForm = $catById[$categoryId];
        } else {
            $catForm = ['id' => 0, 'title' => '', 'slug' => '', 'icon' => 'book', 'description' => '', 'display_order' => count($categories) + 1];
        }
    }
    $id = (int)$catForm['id'];
    $articles = $id ? db_all('SELECT * FROM wiki_articles WHERE category_id = :c ORDER BY display_order, id', ['c' => $id]) : [];
    admin_header($id ? 'Категория: ' . $catById[$id]['title'] : 'Новая категория', 'wiki', [[$id ? 'Изменение категории' : 'Новая категория', current_url()]]);
    ?>
<?= adm_errors_box($errors) ?>
<form method="post" action="<?= e($self) ?>" class="adm-form" data-live>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="cat_save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="adm-card-head"><div><h2><?= icon('folder') ?> <?= $id ? 'Изменение категории' : 'Новая категория' ?></h2><p>Категория - плитка на главной странице базы знаний, внутри неё статьи.</p></div></div>
    <div class="form-grid">
      <div class="form-row">
        <label class="label" for="title">Название <span class="req">*</span></label>
        <input class="input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title" name="title" value="<?= e($catForm['title']) ?>" maxlength="120" required placeholder="Например: Работы">
        <?= adm_err($errors, 'title') ?>
      </div>
      <div class="form-row">
        <label class="label" for="slug">Адрес</label>
        <input class="input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" id="slug" name="slug" value="<?= e($catForm['slug']) ?>" maxlength="64" data-slug-from="title">
        <div class="hint">Латиница, цифры и дефисы. Пусто - создастся из названия.</div>
        <?= adm_err($errors, 'slug') ?>
      </div>
      <div class="form-row">
        <label class="label" for="description">Описание</label>
        <input class="input<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" value="<?= e($catForm['description']) ?>" maxlength="255" placeholder="Где и как заработать первые деньги">
        <?= adm_err($errors, 'description') ?>
      </div>
      <div class="form-row">
        <label class="label" for="display_order">Порядок</label>
        <input class="input<?= isset($errors['display_order']) ? ' is-invalid' : '' ?>" type="number" id="display_order" name="display_order" value="<?= e($catForm['display_order']) ?>">
        <div class="hint">Меньшее число - раньше среди категорий.</div>
        <?= adm_err($errors, 'display_order') ?>
      </div>
    </div>
    <div class="form-row">
      <span class="label">Иконка</span>
      <?= adm_icon_picker('icon', $catForm['icon']) ?>
    </div>
  </div>
  <div class="adm-savebar">
    <button class="btn btn-accent" type="submit"><?= icon('check') ?> Сохранить</button>
    <a class="btn btn-ghost" href="<?= e($self) ?>">Отмена</a>
  </div>
</form>
<?php if ($id): ?>
<div class="card">
  <div class="adm-card-head">
    <div><h2><?= icon('file') ?> Статьи категории</h2><p><?= count($articles) ? 'Всего: ' . count($articles) . '.' : 'Пока пусто.' ?></p></div>
    <div class="btn-row"><a class="btn btn-white" href="<?= e(url('/admin/wiki.php', ['new_article' => 1, 'cat' => $id])) ?>"><?= icon('plus') ?> Новая статья</a></div>
  </div>
  <?= adm_wiki_articles_table($articles, $catById, false) ?>
</div>
<div class="card adm-danger">
  <div class="adm-card-head"><div><h2><?= icon('trash') ?> Удаление категории</h2></div></div>
  <?php if ($articles): ?>
  <div class="adm-note"><?= icon('info') ?><div>Удалить можно только пустую категорию. Сначала удалите статьи или перенесите их в другую категорию (поле «Категория» в статье).</div></div>
  <?php else: ?>
  <form method="post" action="<?= e($self) ?>" data-confirm="Удалить категорию «<?= e($catById[$id]['title']) ?>»?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cat_delete">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Удалить категорию</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
    admin_footer();
    exit;
}

// ---------- Список ----------

$filter = query_int('cat');
if ($filter && !isset($catById[$filter])) {
    $filter = 0;
}
$articles = $filter
    ? db_all('SELECT * FROM wiki_articles WHERE category_id = :c ORDER BY display_order, id', ['c' => $filter])
    : db_all('SELECT a.* FROM wiki_articles a LEFT JOIN wiki_categories c ON c.id = a.category_id ORDER BY c.display_order, a.category_id, a.display_order, a.id');
$total = (int)db_val('SELECT COUNT(*) FROM wiki_articles');
admin_header('База знаний', 'wiki');
?>
<div class="card">
  <div class="adm-card-head">
    <div><h2><?= icon('folder') ?> Категории</h2><p>Плитки на главной странице <a href="<?= e(url('/wiki/')) ?>" target="_blank">базы знаний</a>.</p></div>
    <div class="btn-row"><a class="btn" href="<?= e(url('/admin/wiki.php', ['new_category' => 1])) ?>"><?= icon('plus') ?> Новая категория</a></div>
  </div>
  <?php if (!$categories): ?>
  <div class="adm-empty"><?= icon('folder') ?>Категорий пока нет. Создайте первую, например «Начало игры».</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Категория</th><th class="hide-md">Адрес</th><th class="num">Статей</th><th class="center hide-sm">Порядок</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($categories as $c): $cid = (int)$c['id']; ?>
        <tr>
          <td>
            <div class="adm-cell-main">
              <span class="node-ico"><?= icon($c['icon']) ?></span>
              <div class="adm-cell-text">
                <a class="adm-cell-title" href="<?= e(url('/admin/wiki.php', ['category' => $cid])) ?>"><?= e($c['title']) ?></a>
                <?php if ($c['description']): ?><div class="adm-cell-sub"><?= e(str_limit($c['description'], 80)) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td class="hide-md muted small"><?= e($c['slug']) ?></td>
          <td class="num"><a href="<?= e(url('/admin/wiki.php', ['cat' => $cid])) ?>#articles"><?= num($c['articles']) ?></a></td>
          <td class="center hide-sm"><?= (int)$c['display_order'] ?></td>
          <td class="actions">
            <div class="adm-row-actions">
              <a class="btn btn-sm" href="<?= e(url('/admin/wiki.php', ['category' => $cid])) ?>"><?= icon('edit') ?><span class="hide-sm">Изменить</span></a>
              <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/wiki.php', ['new_article' => 1, 'cat' => $cid])) ?>" title="Новая статья в этой категории"><?= icon('plus') ?><span class="hide-sm">Статья</span></a>
              <?php if ((int)$c['articles'] === 0): ?>
              <form method="post" action="<?= e($self) ?>" data-confirm="Удалить пустую категорию «<?= e($c['title']) ?>»?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cat_delete">
                <input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn btn-sm btn-ghost btn-icon btn-danger-text" type="submit" title="Удалить"><?= icon('trash') ?></button>
              </form>
              <?php else: ?>
              <span class="btn btn-sm btn-ghost btn-icon disabled" title="В категории есть статьи"><?= icon('trash') ?></span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card" id="articles">
  <div class="adm-card-head">
    <div><h2><?= icon('file') ?> Статьи</h2><p><?= $filter ? 'Категория «' . e($catById[$filter]['title']) . '»: ' . count($articles) : 'Всего статей: ' . $total ?></p></div>
    <div class="btn-row">
      <?php if ($categories): ?>
      <form method="get" action="<?= e($self) ?>#articles">
        <select class="select input-sm" name="cat" data-autosubmit aria-label="Категория" style="height:36px;min-width:200px">
          <option value="0">Все категории</option>
          <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= adm_sel($filter, $c['id']) ?>><?= e($c['title']) ?> (<?= (int)$c['articles'] ?>)</option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm" type="submit">Показать</button></noscript>
      </form>
      <a class="btn btn-white" href="<?= e(url('/admin/wiki.php', ['new_article' => 1, 'cat' => $filter ?: null])) ?>"><?= icon('plus') ?> Новая статья</a>
      <?php endif; ?>
    </div>
  </div>
  <?= adm_wiki_articles_table($articles, $catById, !$filter) ?>
</div>
<?php
admin_footer();
