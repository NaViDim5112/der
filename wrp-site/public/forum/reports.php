<?php
// Центр жалоб для команды: открытые, мои, закрытые. Взять жалобу, закрыть с решением, выдать предупреждение.
require __DIR__ . '/../../app/bootstrap.php';
require_login();
if (!is_staff()) {
    abort(403, 'Центр жалоб доступен только команде форума.');
}
mdr_require_ready();

$self = url('/forum/reports.php');

if (is_post()) {
    csrf_check();
    $report = mdr_report_get(input_int('id'));
    $back = safe_return(input('return'), $self);
    if (!$report) {
        flash('error', 'Жалоба не найдена.');
        redirect($back);
    }
    $action = input('action');
    if ($action === 'claim' || $action === 'unclaim') {
        list($ok, $msg) = mdr_report_claim($report, $action === 'claim');
    } elseif ($action === 'resolve') {
        $verdict = input('verdict');
        if (!in_array($verdict, ['resolved', 'rejected'], true)) {
            $ok = false;
            $msg = 'Выберите решение.';
        } else {
            list($ok, $msg) = mdr_report_resolve($report, $verdict, input_text('note'));
        }
    } elseif ($action === 'reopen') {
        list($ok, $msg) = mdr_report_reopen($report);
    } else {
        abort(400, 'Неизвестное действие.');
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect($back . '#report-' . (int)$report['id']);
}

$single = query_int('id');
$tabs = ['open' => 'Открытые', 'mine' => 'Мои', 'closed' => 'Закрытые'];
$tab = query_str('tab', 'open');
if (!isset($tabs[$tab])) {
    $tab = 'open';
}
$counts = ['open' => mdr_reports_count('open'), 'mine' => mdr_reports_count('mine')];

if ($single) {
    $one = mdr_report_get($single);
    if (!$one) {
        abort(404, 'Жалоба не найдена.');
    }
    $reports = [$one];
    $pg = paginate(1, 20, 1);
    $total = 1;
} else {
    $total = $tab === 'closed' ? mdr_reports_count('closed') : $counts[$tab];
    $pg = paginate($total, 20, query_int('page', 1));
    $reports = mdr_reports_list($tab, $pg['per_page'], $pg['offset']);
}

$ids = array_map(function ($r) {
    return (int)$r['id'];
}, $reports);
$items = mdr_report_items($ids);
$contents = mdr_content_load_many(array_map(function ($r) {
    return [$r['content_type'], (int)$r['content_id']];
}, $reports));
$userIds = [];
foreach ($reports as $r) {
    $userIds[] = (int)$r['assigned_to'];
    $userIds[] = (int)$r['resolved_by'];
    $userIds[] = (int)$r['content_user_id'];
}
$users = mdr_users_by_ids($userIds);
$return = $single ? url('/forum/reports.php', ['id' => $single]) : url('/forum/reports.php', ['tab' => $tab === 'open' ? null : $tab, 'page' => $pg['page'] > 1 ? $pg['page'] : null]);

forum_header([
    'title' => $single ? 'Жалоба #' . $single : 'Центр жалоб',
    'crumbs' => $single ? [['Центр жалоб', $self], ['Жалоба #' . $single, current_url()]] : [['Центр жалоб', $self]],
    'right' => false,
    'nav' => '',
    'css' => ['moderation.css'],
    'js' => ['moderation.js'],
    'body_class' => 'page-reports',
]);
?>
<div class="mdr-reports">
  <?php if (!$single): ?>
  <div class="mdr-reports-top">
    <nav class="tabs mdr-tabs" aria-label="Жалобы">
      <?php foreach ($tabs as $k => $label): ?>
      <a class="tab<?= $k === $tab ? ' active' : '' ?>" href="<?= e(url('/forum/reports.php', ['tab' => $k === 'open' ? null : $k])) ?>"><?= e($label) ?><?php if (isset($counts[$k]) && $counts[$k]): ?> <span class="mdr-tab-count"><?= num($counts[$k]) ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="muted small mdr-reports-hint"><?= icon('info') ?> Жалобы на одно и то же собираются в одну карточку. Пожаловавшиеся получат оповещение о решении.</div>
  </div>
  <?php else: ?>
  <div class="page-actions"><a class="btn btn-black btn-pill" href="<?= e($self) ?>"><?= icon('chevron-left') ?> Все жалобы</a></div>
  <?php endif; ?>

  <?php if (!$reports): ?>
  <div class="card empty"><?= icon($tab === 'closed' ? 'clock' : 'check') ?><div><?= $tab === 'open' ? 'Открытых жалоб нет. Всё спокойно!' : ($tab === 'mine' ? 'Вы пока не взяли ни одной жалобы.' : 'Закрытых жалоб пока нет.') ?></div></div>
  <?php endif; ?>

  <?php foreach ($reports as $r): ?>
  <?= mdr_report_card($r, $items[(int)$r['id']] ?? [], $contents[$r['content_type'] . ':' . (int)$r['content_id']] ?? null, $users, $return) ?>
  <?php endforeach; ?>

  <?php if (!$single && $pg['pages'] > 1): ?>
  <div class="page-actions"><?= pagination_html($pg, '/forum/reports.php', ['tab' => $tab === 'open' ? null : $tab]) ?></div>
  <?php endif; ?>
</div>
<?php
forum_footer();
