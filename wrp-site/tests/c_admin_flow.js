// Проверка админ-панели в браузере: node tests/c_admin_flow.js
// BASE=http://127.0.0.1:8083 NODE_PATH=/opt/node22/lib/node_modules
// Создаёт и удаляет только свои тестовые записи (с префиксом «ТЕСТ»).
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

(async () => {
  const browser = await chromium.launch();

  // ---------- Доступ ----------
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    let r = await page.goto(base + '/admin/');
    ok(page.url().includes('/forum/login.php'), 'гость: /admin/ -> вход');
    const p2 = await login(ctx, 'Ricardo_Calump', 'test12345');
    for (const path of ['/admin/', '/admin/settings.php', '/admin/nodes.php', '/admin/users.php?edit=1', '/admin/modlog.php']) {
      r = await p2.goto(base + path);
      ok(r.status() === 403, 'Ricardo_Calump (не админ): ' + path + ' -> ' + r.status());
    }
    // POST без прав
    const token = await p2.$eval('meta[name=csrf-token]', m => m.content);
    const resp = await p2.request.post(base + '/admin/users.php', { form: { _token: token, action: 'ban', id: '3', ban_forever: '1' } });
    ok(resp.status() === 403, 'Ricardo_Calump: POST бан -> ' + resp.status());
    ok(sql("SELECT is_banned FROM users WHERE id=3") === '0', 'Roxas не заблокирован');
    await ctx.close();
  }

  const ctx = await browser.newContext();
  const page = await login(ctx, 'admin', 'admin12345');

  // ---------- CSRF ----------
  {
    const resp = await page.request.post(base + '/admin/nav.php', { form: { _token: 'bad', action: 'delete', id: '1' } });
    ok(resp.status() === 400, 'POST с неверным токеном -> 400');
  }

  // ---------- Настройки ----------
  {
    await page.goto(base + '/admin/settings.php');
    await page.fill('#server_port', '70000');
    await page.fill('#discord_url', 'discord.gg/abc');
    await page.fill('#site_subtitle', 'ТЕСТ подзаголовок');
    await page.$eval('form.adm-form', f => { f.noValidate = true; });
    await submit(page, '.adm-savebar button[type=submit]');
    const t = await flashText(page);
    ok(/Порт - число от 1 до 65535/.test(t) && /Discord: ссылка должна/.test(t), 'настройки: ошибки порта и ссылки');
    ok(await page.inputValue('#site_subtitle') === 'ТЕСТ подзаголовок', 'настройки: значения сохраняются при ошибке');
    ok(sql("SELECT COUNT(*) FROM settings WHERE k='site_subtitle'") === '0', 'настройки: при ошибке ничего не записано');
    await page.fill('#server_port', '7777');
    await page.fill('#discord_url', 'https://discord.gg/test');
    await page.fill('#video_url', 'https://vk.com/video1');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/YouTube/.test(await flashText(page)), 'настройки: видео только YouTube');
    await page.fill('#video_url', 'https://youtu.be/dQw4w9WgXcQ');
    await page.fill('#announcement', '[b]ТЕСТ объявление[/b]');
    await page.fill('#flood_seconds', '20');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/Настройки сохранены/.test(await flashText(page)), 'настройки: сохранены');
    ok(sql("SELECT v FROM settings WHERE k='flood_seconds'") === '20', 'настройки: flood_seconds = 20');
    await page.goto(base + '/forum/');
    ok(await page.$eval('.announcement', e => e.innerHTML.includes('<strong>ТЕСТ объявление</strong>')), 'объявление видно на форуме');
    ok((await page.textContent('.brand-text small')).includes('ТЕСТ ПОДЗАГОЛОВОК'), 'подзаголовок в шапке');
    // восстановление
    await page.goto(base + '/admin/settings.php');
    await page.fill('#site_subtitle', 'Los Santos');
    await page.fill('#discord_url', '');
    await page.fill('#video_url', '');
    await page.fill('#announcement', '');
    await page.fill('#flood_seconds', '15');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(/Настройки сохранены/.test(await flashText(page)), 'настройки: восстановлены');
    // строки, которых не было до теста, убираем (значения и так совпадают со стандартными)
    sql("DELETE FROM settings WHERE k IN ('announcement','discord_url','flood_seconds','site_subtitle','video_url') AND k NOT IN ('news_node_id','rules_node_id','complaints_node_id','tech_node_id','server_name')");
  }

  // ---------- Префиксы ----------
  let prefixId;
  {
    await page.goto(base + '/admin/prefixes.php?new=1');
    await page.fill('#title', 'ТЕСТ префикс');
    await page.selectOption('#color', 'teal');
    ok(await page.$eval('[data-bind-prefix]', e => e.className) === 'prefix prefix-teal', 'префикс: живой предпросмотр цвета');
    await submit(page, '.adm-savebar button[type=submit]');
    prefixId = sql("SELECT id FROM prefixes WHERE title='ТЕСТ префикс'");
    ok(!!prefixId, 'префикс создан #' + prefixId);
    await page.goto(base + '/admin/prefixes.php?edit=' + prefixId);
    await page.fill('#title', 'ТЕСТ префикс 2');
    await page.selectOption('#color', 'orange');
    await submit(page, '.adm-savebar button[type=submit]');
    ok(sql('SELECT CONCAT(title, "/", color) FROM prefixes WHERE id=' + prefixId) === 'ТЕСТ префикс 2/orange', 'префикс изменён');
  }

  // ---------- Разделы ----------
  let catId, forumA, forumB, threadId, postId;
  {
    await page.goto(base + '/admin/nodes.php?new=1&type=category');
    await page.fill('#title', 'ТЕСТ категория');
    await page.fill('#display_order', '99');
    await submit(page, '.adm-savebar button[value=list]');
    catId = sql("SELECT id FROM nodes WHERE title='ТЕСТ категория'");
    ok(!!catId, 'категория создана #' + catId);

    await page.goto(base + '/admin/nodes.php?new=1&type=forum&parent=' + catId);
    ok(await page.inputValue('#parent_id') === catId, 'подраздел: родитель подставлен');
    ok(await page.isHidden('#link_url'), 'тип «Раздел»: поле ссылки скрыто');
    await page.fill('#title', 'ТЕСТ раздел А');
    await page.selectOption('#view_level', '10');
    await page.click('.icon-opt[title="flag"]');
    ok((await page.textContent('[data-icon-name]')) === 'flag', 'иконка: выбор меняет предпросмотр');
    await page.check('input[name="prefixes[]"][value="' + prefixId + '"]');
    // анкета
    await page.click('[data-fb-add]');
    await page.fill('.fb-row:nth-child(1) [data-k=label]', 'Ник нарушителя');
    await page.check('.fb-row:nth-child(1) [data-k=required]');
    await page.click('[data-fb-add]');
    await page.fill('.fb-row:nth-child(2) [data-k=label]', 'Тип нарушения');
    await page.selectOption('.fb-row:nth-child(2) [data-k=type]', 'select');
    ok(await page.isVisible('.fb-row:nth-child(2) [data-k=options]'), 'анкета: варианты видны для списка');
    ok(await page.isHidden('.fb-row:nth-child(1) [data-k=options]'), 'анкета: варианты скрыты для строки');
    await page.fill('.fb-row:nth-child(2) [data-k=options]', 'DM\nDB\nПомеха RP');
    await page.click('[data-fb-keys] button:first-child');
    await page.fill('#fb-title', 'Жалоба на {nik_narushitelya} | {tip_narusheniya}');
    await page.fill('#fb-title', 'Жалоба на {nik_narushitelya} | {tip_narusheniya}');
    await page.waitForTimeout(900);
    ok(await page.$eval('[data-fb-preview]', e => e.innerHTML.includes('Тип нарушения') && e.innerHTML.includes('Помеха RP')), 'анкета: живой предпросмотр');
    // переместить второе поле вверх
    await page.click('.fb-row:nth-child(2) [data-fb-up]');
    ok((await page.inputValue('.fb-row:nth-child(1) [data-k=label]')) === 'Тип нарушения', 'анкета: поле перемещено вверх');
    await submit(page, '.adm-savebar button[value=stay]');
    forumA = sql("SELECT id FROM nodes WHERE title='ТЕСТ раздел А'");
    ok(!!forumA, 'раздел А создан #' + forumA);
    const fj = JSON.parse(sql('SELECT form_json FROM nodes WHERE id=' + forumA));
    ok(fj.fields.length === 2 && fj.fields[0].key === 'tip_narusheniya' && fj.fields[0].type === 'select' && fj.fields[1].required === true, 'анкета сохранена: ключи и типы');
    ok(fj.title === 'Жалоба на {nik_narushitelya} | {tip_narusheniya}', 'анкета: шаблон заголовка');
    ok(sql('SELECT COUNT(*) FROM node_prefixes WHERE node_id=' + forumA + ' AND prefix_id=' + prefixId) === '1', 'разрешённый префикс сохранён');
    ok(sql('SELECT CONCAT(icon, "/", view_level) FROM nodes WHERE id=' + forumA) === 'flag/10', 'иконка и уровень просмотра сохранены');
    ok(await page.$eval('[data-fb-preview]', e => e.innerHTML.includes('Ник нарушителя')), 'анкета: серверный предпросмотр после сохранения');
    // неверный JSON вручную
    await page.click('.fb-raw summary');
    await page.fill('#form_json', '{"fields": [');
    await submit(page, '.adm-savebar button[value=stay]');
    ok(/Анкета: ошибка в JSON/.test(await flashText(page)), 'анкета: ошибка JSON показана');

    // гость видит «Приватный»
    const gctx = await browser.newContext();
    const gp = await gctx.newPage();
    await gp.goto(base + '/forum/');
    ok(await gp.$eval('#node-' + forumA, e => e.textContent.includes('Приватный')), 'гость: раздел с доступом «Пользователи» - «Приватный»');
    await gctx.close();

    // цикл: категорию нельзя вложить в свой подраздел
    await page.goto(base + '/admin/nodes.php?edit=' + catId);
    const opts = await page.$$eval('#parent_id option', os => os.map(o => o.value));
    ok(!opts.includes(forumA) && !opts.includes(catId), 'родитель: себя и потомков выбрать нельзя');
    await page.$eval('#parent_id', (s, v) => { const o = document.createElement('option'); o.value = v; s.appendChild(o); s.value = v; }, forumA);
    await submit(page, '.adm-savebar button[value=list]');
    ok(/нельзя вложить в самого себя/.test(await flashText(page)), 'родитель: цикл отклонён сервером');

    // ссылка: проверка адреса
    await page.goto(base + '/admin/nodes.php?new=1&type=link&parent=' + catId);
    await page.fill('#title', 'ТЕСТ ссылка');
    await page.fill('#link_url', 'javascript:alert(1)');
    await submit(page, '.adm-savebar button[value=list]');
    ok(/Адрес должен начинаться/.test(await flashText(page)), 'ссылка: javascript: отклонён');
    await page.fill('#link_url', '/wiki/');
    await submit(page, '.adm-savebar button[value=list]');
    const linkId = sql("SELECT id FROM nodes WHERE title='ТЕСТ ссылка'");
    ok(!!linkId, 'ссылка создана');

    // второй раздел и тема в разделе А
    await page.goto(base + '/admin/nodes.php?new=1&type=forum&parent=' + catId);
    await page.fill('#title', 'ТЕСТ раздел Б');
    await submit(page, '.adm-savebar button[value=list]');
    forumB = sql("SELECT id FROM nodes WHERE title='ТЕСТ раздел Б'");
    sql("INSERT INTO threads (node_id, user_id, title, last_post_at, created_at) VALUES (" + forumA + ", 1, 'ТЕСТ тема', NOW(), NOW())");
    threadId = sql("SELECT id FROM threads WHERE title='ТЕСТ тема'");
    sql("INSERT INTO posts (thread_id, user_id, body, created_at) VALUES (" + threadId + ", 1, 'ТЕСТ', NOW())");
    postId = sql('SELECT id FROM posts WHERE thread_id=' + threadId);
    sql('UPDATE threads SET first_post_id=' + postId + ', last_post_id=' + postId + ', last_post_user_id=1 WHERE id=' + threadId);

    // тип раздела с темами менять нельзя
    await page.goto(base + '/admin/nodes.php?edit=' + forumA);
    await page.click('.adm-type:has(input[value=category])');
    await submit(page, '.adm-savebar button[value=list]');
    ok(/В разделе есть темы/.test(await flashText(page)), 'тип раздела с темами не меняется');

    // порядок
    await page.goto(base + '/admin/nodes.php');
    await page.fill('input[name="order[' + forumB + ']"]', '0');
    await submit(page, '.adm-foot button[type=submit]');
    ok(sql('SELECT display_order FROM nodes WHERE id=' + forumB) === '0', 'порядок сохранён');

    // удаление категории с подразделами запрещено
    await page.goto(base + '/admin/nodes.php?delete=' + catId);
    ok((await page.textContent('.adm-main')).includes('есть подразделы'), 'категорию с подразделами удалить нельзя');

    // удаление раздела А с переносом тем в Б
    await page.goto(base + '/admin/nodes.php?delete=' + forumA);
    await page.selectOption('#target', forumB);
    await submit(page, 'form[data-confirm] button[type=submit]');
    ok(/Темы \(1\) перенесены/.test(await flashText(page)), 'раздел удалён, темы перенесены');
    ok(sql('SELECT node_id FROM threads WHERE id=' + threadId) === forumB, 'тема в разделе Б');
    ok(sql('SELECT thread_count FROM nodes WHERE id=' + forumB) === '1', 'счётчик раздела Б пересчитан');
    ok(sql('SELECT COUNT(*) FROM node_prefixes WHERE node_id=' + forumA) === '0', 'node_prefixes раздела А удалены');
    ok(sql("SELECT COUNT(*) FROM mod_log WHERE action='node_delete' AND target_id=" + forumA) === '1', 'журнал: удаление раздела');

    // уборка: тема, раздел Б, ссылка, категория
    sql('DELETE FROM posts WHERE thread_id=' + threadId);
    sql('DELETE FROM threads WHERE id=' + threadId);
    for (const id of [forumB, linkId, catId]) {
      await page.goto(base + '/admin/nodes.php?delete=' + id);
      await submit(page, 'form[data-confirm] button[type=submit]');
      ok(/удалён/.test(await flashText(page)), 'удалён раздел #' + id);
    }
    ok(sql("SELECT COUNT(*) FROM nodes WHERE title LIKE 'ТЕСТ%'") === '0', 'тестовые разделы убраны');
    sql("DELETE FROM mod_log WHERE user_id = 1 AND action = 'node_delete' AND target_id IN (" + [catId, forumA, forumB, linkId].join(',') + ") AND details LIKE 'ТЕСТ%'");
  }

  // ---------- Удаление префикса ----------
  {
    await page.goto(base + '/admin/prefixes.php');
    await submit(page, '#prefix-' + prefixId + ' form button[type=submit]');
    ok(/удалён/.test(await flashText(page)), 'префикс удалён');
    ok(sql('SELECT COUNT(*) FROM prefixes WHERE id=' + prefixId) === '0', 'префикса нет в базе');
  }

  await browser.close();
  console.log(failed ? '\nПРОВАЛОВ: ' + failed : '\nВСЁ ОК');
  process.exit(failed ? 1 : 0);
})();
