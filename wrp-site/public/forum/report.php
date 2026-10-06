<?php
// Жалоба на сообщение форума, запись или комментарий в профиле, личное сообщение или профиль.
// Без JS - обычная страница. С JS (moderation.js) форма открывается окном: GET ?modal=1 отдаёт только форму,
// POST с X-Requested-With отвечает JSON.
require __DIR__ . '/../../app/bootstrap.php';

$ajax = fp_is_ajax();
$modal = $ajax && query_str('modal') === '1';
if (!mdr_ready()) {
    if ($ajax) {
        json_out(['ok' => false, 'error' => 'Жалобы пока недоступны: нужно обновить базу сайта.'], 503);
    }
    mdr_require_ready();
}

$type = is_post() ? input('type') : query_str('type');
$id = is_post() ? input_int('id') : query_int('id');
$types = mdr_content_types();
if (!isset($types[$type])) {
    $type = '';
}

if (!is_logged()) {
    if ($ajax) {
        json_out(['ok' => false, 'error' => 'Войдите, чтобы отправить жалобу.'], 403);
    }
    require_login();
}

$c = $type !== '' && $id > 0 ? mdr_content_load($type, $id) : null;
$denied = mdr_report_denied($c);
if ($denied === '' && mdr_report_already($c['type'], $c['id'], uid())) {
    $denied = 'Вы уже пожаловались на это. Жалоба ещё рассматривается - о решении придёт оповещение.';
}
$back = $c && $c['link'] !== '' ? $c['link'] : url('/forum/');
$v = ['reason' => '', 'comment' => ''];
$error = '';

if (is_post()) {
    csrf_check();
    $v['reason'] = input('reason');
    $v['comment'] = input_text('comment');
    list($ok, $msg) = mdr_report_submit($type, $id, $v['reason'], $v['comment']);
    if ($ajax) {
        json_out(['ok' => $ok, 'message' => $ok ? $msg : '', 'error' => $ok ? '' : $msg], $ok ? 200 : 400);
    }
    if ($ok) {
        flash('success', $msg);
        redirect($back);
    }
    $error = $msg;
}

// Форма (общая для окна и страницы)
$formInner = function ($inModal) use ($c, $type, $id, $v, $error, $denied, $types, $back) {
    $h = '';
    if ($inModal) {
        $h .= '<div class="modal-head"><span>' . icon('flag') . ' Жалоба</span><button type="button" class="modal-x" data-mdr-close aria-label="Закрыть">' . icon('x') . '</button></div>';
    }
    $h .= '<div class="' . ($inModal ? 'modal-body ' : '') . 'form mdr-report-form-body">';
    if ($denied !== '') {
        $h .= '<div class="mdr-report-denied">' . icon('info') . '<div>' . e($denied) . '</div></div></div>';
        if ($inModal) {
            $h .= '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-mdr-close>Закрыть</button></div>';
        } else {
            $h .= '<div class="form-actions mt-2"><a class="btn btn-ghost btn-pill" href="' . e($back) . '">' . icon('chevron-left') . ' Назад</a></div>';
        }
        return $h;
    }
    $author = $c['author'];
    $snippet = bbcode_plain($c['body'], 220);
    $h .= '<div class="mdr-report-target">' . avatar($author, 's') . '<div><div class="small"><b>' . e($types[$c['type']]) . '</b> от ' . fp_user_link($author) . '</div>'
        . ($snippet !== '' ? '<div class="mdr-report-snippet">' . e($snippet) . '</div>' : '') . '</div></div>';
    $h .= '<div class="mdr-form-error flash flash-error"' . ($error === '' ? ' hidden' : '') . '>' . icon('alert') . '<div>' . e($error) . '</div></div>';
    $h .= '<div class="form-row"><span class="label">Что случилось?</span><div class="mdr-reasons">';
    foreach (mdr_report_reasons() as $k => $label) {
        $h .= '<label class="mdr-reason-opt"><input type="radio" name="reason" value="' . e($k) . '"' . ($v['reason'] === $k ? ' checked' : '') . ' required><span>' . e($label) . '</span></label>';
    }
    $h .= '</div></div>';
    $h .= '<div class="form-row"><label class="label" for="mdr-r-comment">Комментарий <span class="muted">(необязательно, для «Другое» - обязательно)</span></label>'
        . '<textarea class="textarea mdr-report-comment" id="mdr-r-comment" name="comment" rows="3" maxlength="1000" placeholder="Коротко: что не так и где это видно">' . e($v['comment']) . '</textarea></div>';
    $h .= '<div class="hint">Жалобу видят только модераторы. Автор не узнает, кто пожаловался. Ложные жалобы тоже нарушение.</div>';
    $h .= '</div>';
    if ($inModal) {
        $h .= '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-mdr-close>Отмена</button><button type="submit" class="btn btn-accent">' . icon('send') . ' Отправить</button></div>';
    } else {
        $h .= '<div class="form-actions mt-2"><button type="submit" class="btn btn-accent btn-pill">' . icon('send') . ' Отправить жалобу</button><a class="btn btn-ghost btn-pill" href="' . e($back) . '">Отмена</a></div>';
    }
    return $h;
};

$formOpen = '<form method="post" action="' . e(url('/forum/report.php')) . '" class="mdr-report-form" data-mdr-report-form>' . csrf_field()
    . '<input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . (int)$id . '">';

if ($modal) {
    header('Content-Type: text/html; charset=utf-8');
    echo $formOpen . $formInner(true) . '</form>';
    exit;
}

forum_header([
    'title' => 'Жалоба',
    'crumbs' => [['Жалоба', current_url()]],
    'right' => false,
    'nav' => '',
    'css' => ['moderation.css'],
    'js' => ['moderation.js'],
]);
?>
<div class="mdr-narrow">
  <section class="card">
    <h2 class="card-title"><?= icon('flag') ?> Пожаловаться</h2>
    <?= $formOpen . $formInner(false) ?></form>
  </section>
</div>
<?php
forum_footer();
