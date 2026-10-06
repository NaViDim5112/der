<?php
// Все награды форума и лестница званий (модуль «Сообщество»)
require __DIR__ . '/../../app/bootstrap.php';

if (!com_ready()) {
    abort(404, 'Раздел наград ещё не готов: администрации нужно обновить базу сайта.');
}

$all = com_trophies_all();
$counts = [];
foreach (db_all('SELECT trophy_id, COUNT(*) AS n FROM com_user_trophies GROUP BY trophy_id') as $r) {
    $counts[(int)$r['trophy_id']] = (int)$r['n'];
}
$usersTotal = max(1, (int)db_val('SELECT COUNT(*) FROM users'));
$mine = is_logged() ? array_flip(com_user_trophy_ids(uid())) : [];
$recent = db_all('SELECT ut.trophy_id, ut.awarded_at, u.id, u.username, u.avatar, g.color AS group_color
    FROM com_user_trophies ut JOIN users u ON u.id = ut.user_id JOIN user_groups g ON g.id = u.group_id
    ORDER BY ut.awarded_at DESC, ut.trophy_id DESC LIMIT 12');
$ranks = com_ranks_all();
$myPoints = is_logged() ? user_points(user()) : null;
$myRank = $myPoints !== null ? com_rank_for($myPoints) : null;

forum_header([
    'title' => 'Награды',
    'description' => 'Награды и звания форума ' . setting('site_name') . ': за что их выдают и сколько участников их получили.',
    'crumbs' => [['Участники', url('/forum/members.php')], ['Награды', url('/forum/trophies.php')]],
    'nav' => 'members',
    'right' => false,
    'css' => ['account.css'],
]);
?>
<div class="com-trophies-layout">
  <div class="com-trophies-main">
    <section class="block">
      <div class="block-head">
        <h2><?= icon('trophy') ?> Награды форума</h2>
        <span class="muted small"><?= num(count($all)) ?> <?= plural(count($all), 'награда', 'награды', 'наград') ?><?= is_logged() ? ' · у вас ' . num(count($mine)) : '' ?></span>
      </div>
      <?php if (!$all): ?>
      <div class="empty"><?= icon('trophy') ?><div>Наград пока нет.</div></div>
      <?php else: ?>
      <div class="com-trophy-list">
        <?php foreach ($all as $id => $t): $n = $counts[$id] ?? 0; $has = isset($mine[$id]); ?>
        <div class="com-trophy-row<?= $has ? ' is-mine' : '' ?>">
          <?= com_trophy_badge($t, 'l') ?>
          <div class="com-trophy-body">
            <div class="com-trophy-title"><?= e($t['title']) ?><?php if ($has): ?> <span class="com-have"><?= icon('check') ?> у вас есть</span><?php endif; ?></div>
            <?php if ((string)$t['description'] !== ''): ?><div class="com-trophy-desc"><?= e($t['description']) ?></div><?php endif; ?>
            <div class="com-trophy-meta"><?= icon($t['criteria'] === 'manual' ? 'shield' : 'check') ?> <?= e(com_trophy_goal_text($t)) ?></div>
          </div>
          <div class="com-trophy-side">
            <div class="com-trophy-points"><?= (int)$t['points'] ? '+' . num($t['points']) : '0' ?><small><?= plural($t['points'], 'балл', 'балла', 'баллов') ?></small></div>
            <div class="com-trophy-holders" title="<?= num($n) ?> из <?= num($usersTotal) ?> участников"><?= num($n) ?> <?= plural($n, 'участник', 'участника', 'участников') ?><span class="meter"><span style="width:<?= min(100, max($n ? 2 : 0, (int)round($n * 100 / $usersTotal))) ?>%"></span></span></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <aside class="com-trophies-side">
    <section class="card">
      <h3 class="acc-card-title"><?= icon('award') ?> Звания</h3>
      <p class="muted small">Звание зависит от баллов: темы x3, сообщения, реакции x2 и баллы за награды.</p>
      <?php if ($ranks): ?>
      <ol class="com-ladder">
        <?php foreach ($ranks as $r): $cur = $myRank && (int)$myRank['id'] === (int)$r['id']; ?>
        <li class="<?= $cur ? 'is-current' : '' ?>"><?= com_rank_html($r) ?><span class="muted small">от <?= num($r['min_points']) ?></span></li>
        <?php endforeach; ?>
      </ol>
      <?php else: ?><div class="muted small">Званий пока нет.</div><?php endif; ?>
      <?php if ($myPoints !== null): ?>
      <div class="com-my-points">У вас <b><?= num($myPoints) ?></b> <?= plural($myPoints, 'балл', 'балла', 'баллов') ?>. <a href="<?= e(url('/forum/member.php', ['id' => uid(), 'tab' => 'trophies'])) ?>">Мои награды</a></div>
      <?php endif; ?>
    </section>
    <?php if ($recent): ?>
    <section class="card">
      <h3 class="acc-card-title"><?= icon('clock') ?> Недавно получили</h3>
      <div class="com-recent">
        <?php foreach ($recent as $r): $t = $all[(int)$r['trophy_id']] ?? null; if (!$t) { continue; } ?>
        <div class="com-recent-row">
          <?= com_trophy_badge($t, 's') ?>
          <div class="com-recent-body"><?= user_link($r) ?><div class="muted small"><?= e($t['title']) ?> · <?= e(fdate($r['awarded_at'], false)) ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php
forum_footer();
