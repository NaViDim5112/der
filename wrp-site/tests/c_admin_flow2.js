// Проверка админ-панели, часть 2: группы, пользователи, база знаний, меню, журнал.
// BASE=http://127.0.0.1:8083 NODE_PATH=/opt/node22/lib/node_modules node tests/c_admin_flow2.js
const { chromium } = require('playwright');
const { execSync } = require('child_process');

const base = process.env.BASE || 'http://127.0.0.1:8083';
const sql = (q) => execSync('mysql --default-character-set=utf8mb4 wrp -N -B -e ' + JSON.stringify(q), { encoding: 'utf8' }).trim();
let failed = 0;
const ok = (cond, msg) => { console.log((cond ? 'OK   ' : 'FAIL ') + msg); if (!cond) failed++; };

async function login(ctx, u, p) {
  const page = await ctx.newPage();
  page.on('dialog', d => d.accept());
  page.on('pageerror', e => { console.log('PAGEERROR ' + e.message); failed++; });
  await page.goto(base + '/forum/login.php');
  await page.fill('form.login-form input[name=login]', u);
  await page.fill('form.login-form input[name=password]', p);
  await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  return page;
}
const flashText = async (page) => (await page.$$eval('.flash', els => els.map(e => e.textContent.trim()).join(' | ')));
const submit = async (page, selector) => Promise.all([page.waitForNavigation(), page.click(selector)]);
const post = async (page, path, data) => {
  const token = await page.$eval('meta[name=csrf-token]', m => m.content);
  return page.request.post(base + path, { form: Object.assign({ _token: token }, data), maxRedirects: 0 });
};

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await login(ctx, 'admin', 'admin12345');
  const logBefore = sql('SELECT COALESCE(MAX(id), 0) FROM mod_log');

  // ---------- Группы ----------
  let gid;
  {
    await page.goto(base + '/admin/groups.php?new=1');
    await page.fill('#name', 'ТЕСТ группа');
    await page.fill('#color-text', '#12ab34');
    ok(await page.$eval('.adm-preview-box .group-badge', e => e.style.getPropertyValue('--c')) === '#12ab34', 'группа: живой предпросмотр цвета');
    ok(await page.inputValue('.input-color') === '#12ab34', 'группа: палитра синхронна с текстом');
    await page.fill('#level', '500');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/выше вашего/.test(await flashText(page)), 'группа: уровень выше своего отклонён');
    await page.fill('#level', '15');
    await page.check('input[name=is_staff]');
    await submit(page, '.adm-savebar button[type=submit]');
    gid = sql("SELECT id FROM user_groups WHERE name='ТЕСТ группа'");
    ok(!!gid && sql('SELECT CONCAT(level, "/", is_staff, "/", color) FROM user_groups WHERE id=' + gid) === '15/1/#12ab34', 'группа создана #' + gid);

    // базовая группа: модерацию и админку включить нельзя
    await page.goto(base + '/admin/groups.php?edit=2');
    ok(await page.isDisabled('input[name=can_admin]'), 'группа «Пользователь»: галочка админки выключена');
    const r = await post(page, '/admin/groups.php', { action: 'save', id: '2', name: 'Пользователь', color: '#b8bccb', level: '10', show_banner: '1', display_order: '2', can_admin: '1', can_moderate: '1' });
    ok(r.status() === 302 && sql('SELECT CONCAT(can_admin, can_moderate) FROM user_groups WHERE id=2') === '00', 'группа «Пользователь»: can_admin через POST не включился');

    // снять админку у своей единственной админ-группы нельзя
    await page.goto(base + '/admin/groups.php?edit=10');
    await page.uncheck('input[name=can_admin]');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/потеряете доступ|ни у кого не останется/.test(await flashText(page)), 'нельзя снять админку с группы, от которой зависит админ');
    ok(sql('SELECT can_admin FROM user_groups WHERE id=10') === '1', 'группа 10 осталась с админкой');

    // системную группу удалить нельзя
    const r2 = await post(page, '/admin/groups.php', { action: 'delete', id: '10' });
    ok(sql('SELECT COUNT(*) FROM user_groups WHERE id=10') === '1', 'системная группа не удалена (' + r2.status() + ')');
  }

  // ---------- Пользователи: доп. группы демо-пользователя ----------
  {
    ok(sql('SELECT secondary_groups FROM users WHERE id=2') === '4', 'Kuzya: исходные доп. группы = 4');
    await page.goto(base + '/admin/users.php?edit=2');
    await page.check('input[name="secondary[]"][value="' + gid + '"]');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(sql('SELECT secondary_groups FROM users WHERE id=2') === '4,' + gid, 'Kuzya: доп. группа добавлена');
    ok(sql("SELECT COUNT(*) FROM mod_log WHERE action='user_group' AND target_id=2 AND id > " + logBefore) === '1', 'журнал: смена групп');
    // удаление группы убирает её из доп. групп
    await page.goto(base + '/admin/groups.php');
    await submit(page, '#group-' + gid + ' form button[type=submit]');
    ok(/удалена/.test(await flashText(page)), 'тестовая группа удалена');
    ok(sql('SELECT secondary_groups FROM users WHERE id=2') === '4', 'Kuzya: доп. группы восстановлены (4)');
  }

  // ---------- Пользователи: поиск ----------
  {
    await page.goto(base + '/admin/users.php?q=' + encodeURIComponent('%'));
    ok((await page.textContent('.adm-count')).includes('Найдено: 0'), 'поиск: % экранируется');
    await page.goto(base + '/admin/users.php?q=kuzya');
    ok((await page.textContent('.adm-count')).includes('Найдено: 1'), 'поиск по email/нику');
    await page.goto(base + '/admin/users.php?group=4');
    ok((await page.textContent('.table')).includes('Kuzya_Kabanov'), 'фильтр по группе учитывает доп. группы');
  }

  // ---------- Пользователи: тестовый аккаунт ----------
  const hash = execSync("php -r 'echo password_hash(\"oldpass123\", PASSWORD_DEFAULT);'", { encoding: 'utf8' });
  sql("INSERT INTO users (username, email, password_hash, group_id, created_at) VALUES ('Test_Throwaway', 'throwaway@wrp.local', '" + hash + "', 2, NOW())");
  const tid = sql("SELECT id FROM users WHERE username='Test_Throwaway'");
  ok(!!tid, 'тестовый пользователь #' + tid);
  {
    // проверки полей
    await page.goto(base + '/admin/users.php?edit=' + tid);
    await page.fill('#username', 'ab');
    await page.fill('#email', 'admin@wrp.local');
    await page.fill('#game_nick', 'Kuzya Kabanov!');
    await page.$eval('form.adm-form', f => { f.noValidate = true; });
    await submit(page, '.adm-savebar button[type=submit]');
    const t = await flashText(page);
    ok(/Ник: 3-24/.test(t) && /email уже у другого/.test(t) && /Игровой ник/.test(t), 'пользователь: ошибки ника, email, игрового ника');
    ok(await page.inputValue('#username') === 'ab', 'пользователь: введённое сохраняется при ошибке');

    // нормальное сохранение + пароль + удаление токенов
    sql("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (" + tid + ", 'aaaaaaaaaaaaaaaaaaaaaaaa', '" + 'b'.repeat(64) + "', NOW() + INTERVAL 1 DAY)");
    await page.goto(base + '/admin/users.php?edit=' + tid);
    await page.fill('#username', 'Test_Throwaway2');
    await page.fill('#custom_title', 'ТЕСТ звание');
    await page.fill('#location', 'Los Santos');
    await page.fill('#status_text', 'Привет');
    await page.selectOption('#group_id', '4');
    await page.fill('#password', 'newpass123');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/сохранены/.test(await flashText(page)), 'пользователь сохранён');
    ok(sql('SELECT CONCAT(username, "/", group_id, "/", custom_title, "/", location, "/", status_text) FROM users WHERE id=' + tid) === 'Test_Throwaway2/4/ТЕСТ звание/Los Santos/Привет', 'поля сохранены');
    ok(sql('SELECT COUNT(*) FROM remember_tokens WHERE user_id=' + tid) === '0', 'смена пароля удалила «Запомнить меня»');
    const c2 = await browser.newContext();
    const p2 = await login(c2, 'Test_Throwaway2', 'newpass123');
    ok(!p2.url().includes('login.php'), 'вход с новым паролем');
    await c2.close();

    // бан на 7 дней кнопкой
    await page.fill('#ban_reason', 'ТЕСТ причина');
    await page.click('[data-ban-days="7"]');
    ok((await page.inputValue('#ban_until')).length === 16, 'кнопка «7 дней» заполнила дату');
    await submit(page, '#ban form button[type=submit]');
    ok(sql('SELECT CONCAT(is_banned, "/", ban_reason, "/", DATEDIFF(ban_until, NOW())) FROM users WHERE id=' + tid) === '1/ТЕСТ причина/7', 'бан на 7 дней');
    await page.goto(base + '/admin/users.php?banned=yes');
    ok((await page.textContent('.table')).includes('Test_Throwaway2'), 'фильтр «заблокированные»');
    await page.goto(base + '/admin/users.php?edit=' + tid);
    await submit(page, '#ban form button[type=submit]');
    ok(sql('SELECT CONCAT(is_banned, COALESCE(ban_until, "-")) FROM users WHERE id=' + tid) === '0-', 'бан снят');
    // бан навсегда
    await page.check('input[name=ban_forever]');
    ok(await page.isDisabled('#ban_until'), '«Навсегда» выключает дату');
    await submit(page, '#ban form button[type=submit]');
    ok(sql('SELECT CONCAT(is_banned, COALESCE(ban_until, "-")) FROM users WHERE id=' + tid) === '1-', 'бан навсегда');
    await submit(page, '#ban form button[type=submit]');
    // прошедшая дата
    await page.fill('#ban_until', '2020-01-01T10:00');
    await submit(page, '#ban form button[type=submit]');
    ok(/уже прошла/.test(await flashText(page)), 'бан: прошедшая дата отклонена');

    // уровень выше моего
    sql("INSERT INTO user_groups (name, color, level, display_order) VALUES ('ТЕСТ высокая', '#ffffff', 1000, 99)");
    const hg = sql("SELECT id FROM user_groups WHERE name='ТЕСТ высокая'");
    sql('UPDATE users SET group_id=' + hg + ' WHERE id=' + tid);
    await page.goto(base + '/admin/users.php?edit=' + tid);
    ok((await flashText(page)).includes('выше вашего'), 'пользователь с уровнем выше: предупреждение');
    ok(await page.isDisabled('#username'), 'пользователь с уровнем выше: форма выключена');
    const r = await post(page, '/admin/users.php', { action: 'ban', id: tid, ban_forever: '1' });
    ok(sql('SELECT is_banned FROM users WHERE id=' + tid) === '0', 'пользователь с уровнем выше: бан через POST отклонён (' + r.status() + ')');
    sql('UPDATE users SET group_id=2 WHERE id=' + tid);
    sql('DELETE FROM user_groups WHERE id=' + hg);

    // нельзя назначить группу выше своего уровня - проверено выше группой 500; себя менять нельзя
    await post(page, '/admin/users.php', { action: 'ban', id: '1', ban_forever: '1' });
    ok(sql('SELECT is_banned FROM users WHERE id=1') === '0', 'себя заблокировать нельзя');
    await post(page, '/admin/users.php', { action: 'save', id: '1', username: 'admin', email: 'admin@wrp.local', group_id: '2' });
    ok(sql('SELECT group_id FROM users WHERE id=1') === '10', 'свою группу понизить нельзя');
    await post(page, '/admin/users.php', { action: 'delete', id: '1' });
    ok(sql('SELECT COUNT(*) FROM users WHERE id=1') === '1', 'себя удалить нельзя');
    await page.goto(base + '/admin/users.php?edit=1');
    ok(await page.isDisabled('#group_id') && !(await page.$('#ban')), 'своя страница: группа заблокирована, бана нет');

    // удалить пользователя с сообщениями нельзя
    await post(page, '/admin/users.php', { action: 'delete', id: '3' });
    ok(sql('SELECT COUNT(*) FROM users WHERE id=3') === '1', 'пользователя с сообщениями удалить нельзя');

    // удаление аватара и обложки (файлы тоже), а имя с путём не трогает чужие файлы
    const fs = require('fs');
    const pub = __dirname + '/../public/uploads/';
    fs.writeFileSync(pub + 'avatars/zz_admtest_' + tid + '.png', 'x');
    fs.writeFileSync(pub + 'covers/zz_admtest_' + tid + '.jpg', 'x');
    sql("UPDATE users SET avatar='zz_admtest_" + tid + ".png', cover='zz_admtest_" + tid + ".jpg' WHERE id=" + tid);
    await page.goto(base + '/admin/users.php?edit=' + tid);
    await page.check('input[name=remove_avatar]');
    await page.check('input[name=remove_cover]');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(sql('SELECT CONCAT(COALESCE(avatar, "-"), COALESCE(cover, "-")) FROM users WHERE id=' + tid) === '--', 'аватар и обложка убраны из профиля');
    ok(!fs.existsSync(pub + 'avatars/zz_admtest_' + tid + '.png') && !fs.existsSync(pub + 'covers/zz_admtest_' + tid + '.jpg'), 'файлы аватара и обложки удалены');
    fs.writeFileSync(pub + 'zz_evil.png', 'x');
    sql("UPDATE users SET avatar='../zz_evil.png' WHERE id=" + tid);
    await page.goto(base + '/admin/users.php?edit=' + tid);
    await page.check('input[name=remove_avatar]');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(fs.existsSync(pub + 'zz_evil.png'), 'имя файла с путём не удаляет файл вне папки');
    fs.unlinkSync(pub + 'zz_evil.png');

    // следы тестового пользователя: подписки, лайк, стена профиля
    const likePost = sql('SELECT p.id FROM posts p WHERE p.user_id=3 AND p.likes_count = (SELECT COUNT(*) FROM post_likes l WHERE l.post_id=p.id) LIMIT 1');
    const likesBefore = sql('SELECT likes_count FROM posts WHERE id=' + likePost);
    const roxasBefore = sql('SELECT CONCAT(posts_count, "/", threads_count, "/", likes_received) FROM users WHERE id=3');
    sql('INSERT INTO post_likes (post_id, user_id, created_at) VALUES (' + likePost + ', ' + tid + ', NOW())');
    sql('UPDATE posts SET likes_count = likes_count + 1 WHERE id=' + likePost);
    sql('INSERT INTO user_follows (user_id, follow_user_id, created_at) VALUES (' + tid + ', 1, NOW()), (1, ' + tid + ', NOW())');
    sql('INSERT INTO user_ignores (user_id, ignored_user_id, created_at) VALUES (3, ' + tid + ', NOW())');
    sql("INSERT INTO profile_posts (profile_user_id, user_id, body, created_at) VALUES (" + tid + ", 1, 'ТЕСТ на стене', NOW())");
    const pp = sql('SELECT id FROM profile_posts WHERE profile_user_id=' + tid);
    sql("INSERT INTO profile_comments (profile_post_id, user_id, body, created_at) VALUES (" + pp + ", 1, 'ТЕСТ коммент', NOW())");
    sql("INSERT INTO profile_likes (content_type, content_id, user_id, created_at) VALUES ('post', " + pp + ", 1, NOW())");
    sql('INSERT INTO thread_watch (user_id, thread_id, created_at) VALUES (' + tid + ', 1, NOW())');
    sql('INSERT INTO node_watch (user_id, node_id, created_at) VALUES (' + tid + ', 11, NOW())');
    sql("INSERT INTO alerts (user_id, actor_id, type, created_at) VALUES (" + tid + ", 1, 'follow', NOW()), (1, " + tid + ", 'follow', NOW())");

    await page.goto(base + '/admin/users.php?edit=' + tid);
    await submit(page, '.adm-danger form[data-confirm*="навсегда"] button[type=submit]');
    ok(/удалён/.test(await flashText(page)), 'тестовый пользователь удалён');
    ok(sql('SELECT COUNT(*) FROM users WHERE id=' + tid) === '0', 'нет в users');
    const left = sql('SELECT CONCAT_WS(",", (SELECT COUNT(*) FROM post_likes WHERE user_id=' + tid + '), (SELECT COUNT(*) FROM user_follows WHERE user_id=' + tid + ' OR follow_user_id=' + tid + '), (SELECT COUNT(*) FROM user_ignores WHERE ignored_user_id=' + tid + '), (SELECT COUNT(*) FROM profile_posts WHERE id=' + pp + '), (SELECT COUNT(*) FROM profile_comments WHERE profile_post_id=' + pp + "), (SELECT COUNT(*) FROM profile_likes WHERE content_type='post' AND content_id=" + pp + '), (SELECT COUNT(*) FROM thread_watch WHERE user_id=' + tid + '), (SELECT COUNT(*) FROM node_watch WHERE user_id=' + tid + '), (SELECT COUNT(*) FROM alerts WHERE user_id=' + tid + '), (SELECT COUNT(*) FROM alerts WHERE actor_id=' + tid + '))');
    ok(left === '0,0,0,0,0,0,0,0,0,0', 'все следы пользователя удалены: ' + left);
    ok(sql('SELECT likes_count FROM posts WHERE id=' + likePost) === likesBefore, 'счётчик лайков сообщения пересчитан (' + likesBefore + ')');
    ok(sql('SELECT CONCAT(posts_count, "/", threads_count, "/", likes_received) FROM users WHERE id=3') === roxasBefore, 'счётчики автора сообщения не испортились');
    sql("DELETE FROM alerts WHERE user_id=1 AND actor_id IS NULL AND type='follow'");
  }

  // ---------- База знаний ----------
  {
    await page.goto(base + '/admin/wiki.php?new_category=1');
    await page.fill('#title', 'ТЕСТ вики');
    ok((await page.getAttribute('#slug', 'placeholder')).startsWith('test-viki'), 'вики: подсказка адреса из названия');
    await submit(page, '.adm-savebar button[type=submit]');
    const c1 = sql("SELECT id FROM wiki_categories WHERE slug='test-viki'");
    ok(!!c1, 'категория создана, адрес test-viki');
    await page.goto(base + '/admin/wiki.php?new_category=1');
    await page.fill('#title', 'ТЕСТ вики');
    await submit(page, '.adm-savebar button[type=submit]');
    const c2 = sql("SELECT id FROM wiki_categories WHERE slug='test-viki-2'");
    ok(!!c2, 'вторая категория получила адрес test-viki-2');
    await page.goto(base + '/admin/wiki.php?category=' + c2);
    await page.fill('#slug', 'Bad Slug!');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/только латинские буквы/.test(await flashText(page)), 'вики: неверный адрес отклонён');
    await page.fill('#slug', 'test-viki');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/уже занят/.test(await flashText(page)), 'вики: занятый адрес отклонён');

    await page.goto(base + '/admin/wiki.php?new_article=1&cat=' + c1);
    ok(await page.inputValue('#category_id') === c1, 'статья: категория подставлена из ссылки');
    await page.fill('#title', 'ТЕСТ статья');
    await page.fill('#summary', 'Кратко');
    await page.fill('#body', '[b]Текст[/b] статьи');
    await page.uncheck('input[name=is_published]');
    await submit(page, '.adm-savebar button[value=stay]');
    const a1 = sql("SELECT id FROM wiki_articles WHERE slug='test-statya'");
    ok(!!a1 && page.url().includes('article=' + a1), 'статья создана, «Сохранить и продолжить» оставил на странице');
    ok(sql('SELECT CONCAT(author_id, "/", is_published, "/", category_id) FROM wiki_articles WHERE id=' + a1) === '1/0/' + c1, 'статья: автор, черновик, категория');
    ok(await page.getAttribute('a[href*="/wiki/article.php?slug=test-statya"]', 'href') !== null, 'статья: ссылка «Открыть»');
    await page.fill('#title', 'ТЕСТ статья изменена');
    await page.check('input[name=is_published]');
    await submit(page, '.adm-savebar button[value=list]');
    ok(sql('SELECT CONCAT(title, "/", slug, "/", is_published) FROM wiki_articles WHERE id=' + a1) === 'ТЕСТ статья изменена/test-statya/1', 'статья изменена, адрес не поменялся');
    // удаление непустой категории
    await post(page, '/admin/wiki.php', { action: 'cat_delete', id: c1 });
    ok(sql('SELECT COUNT(*) FROM wiki_categories WHERE id=' + c1) === '1', 'непустую категорию удалить нельзя');
    await page.goto(base + '/admin/wiki.php?category=' + c1);
    await submit(page, '#article-' + a1 + ' form button[type=submit]');
    ok(sql('SELECT COUNT(*) FROM wiki_articles WHERE id=' + a1) === '0', 'статья удалена');
    for (const c of [c1, c2]) {
      await page.goto(base + '/admin/wiki.php?category=' + c);
      await submit(page, '.adm-danger form button[type=submit]');
    }
    ok(sql("SELECT COUNT(*) FROM wiki_categories WHERE title LIKE 'ТЕСТ%'") === '0', 'тестовые категории удалены');
    const r = await page.goto(base + '/admin/wiki.php?article=4');
    ok(r.status() === 200 && (await page.inputValue('#title')).length > 0, 'глубокая ссылка ?article=ID');
  }

  // ---------- Меню слева ----------
  {
    await page.goto(base + '/admin/nav.php?new=1');
    await page.fill('#title', 'ТЕСТ пункт');
    ok((await page.textContent('.side-link.is-new')).includes('ТЕСТ пункт'), 'меню: живой предпросмотр названия');
    await page.fill('#url', 'example.com');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/Адрес должен начинаться/.test(await flashText(page)), 'меню: адрес без https отклонён');
    await page.fill('#url', 'https://example.com/');
    await page.check('input[name=new_tab]');
    await page.click('.icon-opt[title="youtube"]');
    await submit(page, '.adm-savebar button[type=submit]');
    const nid = sql("SELECT id FROM nav_links WHERE title='ТЕСТ пункт'");
    ok(!!nid && sql('SELECT CONCAT(icon, "/", new_tab) FROM nav_links WHERE id=' + nid) === 'youtube/1', 'пункт меню создан');
    await page.goto(base + '/forum/');
    ok(await page.$eval('a.side-link[href="https://example.com/"]', a => a.target === '_blank' && a.textContent.includes('ТЕСТ пункт')), 'пункт виден в меню форума, в новой вкладке');
    await page.goto(base + '/admin/nav.php');
    await submit(page, 'tr:has-text("ТЕСТ пункт") form button[type=submit]');
    ok(sql("SELECT COUNT(*) FROM nav_links WHERE title='ТЕСТ пункт'") === '0', 'пункт меню удалён');
  }

  // ---------- Журнал ----------
  {
    await page.goto(base + '/admin/modlog.php?action=user_ban');
    ok((await page.textContent('.table')).includes('Блокировка пользователя'), 'журнал: фильтр по действию');
    ok((await page.$$('.table tbody tr')).length >= 2, 'журнал: записи о банах есть');
    await page.goto(base + '/admin/modlog.php?user=1');
    ok((await page.$$eval('.table tbody tr td:first-child', tds => tds.every(t => t.textContent.includes('admin')))), 'журнал: фильтр по пользователю');
  }

  // уборка журнала от тестовых записей (только наших тестовых целей)
  sql('DELETE FROM mod_log WHERE id > ' + logBefore + " AND user_id = 1 AND ((target_type = 'user' AND target_id IN (" + tid + ', 2)) OR (target_type = \'group\' AND target_id = ' + gid + '))');

  await browser.close();
  console.log(failed ? '\nПРОВАЛОВ: ' + failed : '\nВСЁ ОК');
  process.exit(failed ? 1 : 0);
})();
