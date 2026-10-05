// Проверки страниц темы: страницы, переходы goto, игнор, права и защита запросов.
// Запуск: BASE=http://127.0.0.1:8081 NODE_PATH=/opt/node22/lib/node_modules node tests/a_pages.js <id темы с 20+ сообщениями>
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const TID = process.argv[2];
let failed = 0;
function check(cond, msg) {
  console.log((cond ? '  ok   ' : '  FAIL ') + msg);
  if (!cond) failed++;
}

async function open(browser, login, opts) {
  const ctx = await browser.newContext(Object.assign({ viewport: { width: 1440, height: 1000 } }, opts || {}));
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  if (login) {
    const [u, p] = login.split(':');
    await page.goto(BASE + '/forum/login.php');
    await page.fill('form.login-form input[name=login]', u);
    await page.fill('form.login-form input[name=password]', p);
    await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  }
  return page;
}

// POST из страницы (с токеном или без)
async function post(page, path, data, withToken) {
  return page.evaluate(async ({ path, data, withToken }) => {
    const fd = new FormData();
    if (withToken) fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
    Object.keys(data).forEach((k) => fd.append(k, data[k]));
    const r = await fetch(path, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    let body = '';
    try { body = await r.text(); } catch (e) {}
    return { status: r.status, type: r.type, body };
  }, { path, data, withToken });
}

(async () => {
  const browser = await chromium.launch();

  console.log('1. Страницы темы');
  const martin = await open(browser, 'Martin_Line:test12345');
  await martin.goto(BASE + '/forum/thread.php?id=' + TID);
  check(await martin.locator('.post').count() === 15, 'на первой странице 15 сообщений');
  check(await martin.locator('.thread-actions .fp-pages').count() >= 1, 'есть пагинация');
  check((await martin.locator('.fp-pages').first().innerText()).includes('Вперёд'), 'кнопка «Вперёд»');
  await martin.goto(BASE + '/forum/thread.php?id=' + TID + '&page=2');
  const nums = await martin.locator('.post-num').allInnerTexts();
  check(nums[0] === '#16', 'нумерация продолжается на второй странице: ' + nums[0]);
  await martin.goto(BASE + '/forum/thread.php?id=' + TID + '&page=99');
  check(/page=2$/.test(martin.url()), 'слишком большая страница - переход на последнюю');
  await martin.goto(BASE + '/forum/thread.php?id=' + TID + '&goto=last');
  check(/page=2#post-\d+$/.test(martin.url()), 'goto=last ведёт на последнюю страницу к сообщению: ' + martin.url());
  await martin.goto(BASE + '/forum/forum.php?id=52');
  check(await martin.locator('#thread-' + TID + ' .trow-pages a').count() === 2, 'в списке тем ссылки на страницы 1 2');

  console.log('2. Непрочитанное');
  const diego = await open(browser, 'Diego_Bacardi:test12345');
  await diego.goto(BASE + '/forum/thread.php?id=' + TID + '&goto=unread');
  check(/#post-\d+$/.test(diego.url()), 'goto=unread ведёт к сообщению: ' + diego.url().replace(BASE, ''));
  await diego.goto(BASE + '/forum/thread.php?id=' + TID);
  await diego.goto(BASE + '/forum/thread.php?id=' + TID + '&page=2');
  await diego.goto(BASE + '/forum/forum.php?id=52');
  check(await diego.locator('#thread-' + TID + '.unread').count() === 0, 'после чтения всех страниц тема прочитана');

  console.log('3. Игнор');
  const roxas = await open(browser, 'Roxas_Alexandro:test12345');
  // Roxas (id 3) игнорирует Kuzya (id 2): строка в user_ignores через mysql CLI (только для тестовой базы)
  const sql = (q) => require('child_process').execSync('mysql wrp -e ' + JSON.stringify(q));
  sql('INSERT IGNORE INTO user_ignores (user_id, ignored_user_id, created_at) VALUES (3, 2, NOW())');
  await roxas.goto(BASE + '/forum/thread.php?id=' + TID);
  const collapsed = await roxas.locator('.post-collapsed-ignored').count();
  check(collapsed > 0, 'сообщения игнорируемого свёрнуты: ' + collapsed);
  const box = roxas.locator('.post-collapsed-ignored').first();
  check(await box.locator('.post-collapsed-body').isHidden(), 'текст скрыт');
  await box.locator('[data-collapse-toggle]').click();
  check(await box.locator('.post-collapsed-body').isVisible(), 'кнопка «Показать» раскрывает сообщение');
  await roxas.screenshot({ path: __dirname + '/screens/a-ignored.png', clip: await box.boundingBox() });
  sql('DELETE FROM user_ignores WHERE user_id = 3 AND ignored_user_id = 2');

  console.log('4. Права и защита');
  // Обычный пользователь не может удалять
  const firstPostId = await roxas.locator('.post').nth(1).getAttribute('data-post');
  let r = await post(roxas, '/forum/post.php?action=delete', { id: firstPostId }, true);
  check(r.status === 403, 'пользователь не может удалить сообщение (403)');
  r = await post(roxas, '/forum/post.php?action=restore', { id: firstPostId }, true);
  check(r.status === 403, 'пользователь не может восстановить сообщение (403)');
  r = await post(roxas, '/forum/thread.php?id=' + TID, { action: 'mod', do: 'unlock' }, true);
  check(r.status === 403, 'пользователь не может модерировать тему (403)');
  r = await post(diego, '/forum/thread.php?id=' + TID, { action: 'mod', do: 'unlock' }, false);
  check(r.status === 400, 'без CSRF-токена - 400');
  r = await post(roxas, '/forum/post.php?action=react', { id: firstPostId, reaction: 'evil' }, true);
  check(r.status === 400 && r.body.includes('Неизвестная'), 'неизвестная реакция отклонена');
  // Чужое сообщение нельзя редактировать
  const kuzyaPost = await diego.evaluate(() => {
    const el = [...document.querySelectorAll('.post')].find((p) => p.querySelector('.post-username').textContent.includes('Kuzya'));
    return el ? el.getAttribute('data-post') : null;
  });
  const ed = await roxas.goto(BASE + '/forum/post.php?action=edit&id=' + (kuzyaPost || firstPostId));
  check(ed.status() === 403, 'чужое сообщение редактировать нельзя (403)');
  // Гость
  const guest = await open(browser, null);
  await guest.goto(BASE + '/forum/thread.php?id=' + TID);
  r = await post(guest, '/forum/post.php?action=react', { id: firstPostId, reaction: '' }, true);
  check(r.status === 403 && r.body.includes('login'), 'гость при реакции получает ссылку на вход');
  await guest.goto(BASE + '/forum/thread.php?id=' + TID);
  await guest.locator('.post').first().locator('a.react-btn').click();
  await guest.waitForURL(/login\.php/);
  check(/login\.php/.test(guest.url()), 'гость по кнопке Like уходит на вход');
  const q = await guest.evaluate(async (id) => (await fetch('/forum/post.php?action=quote&id=' + id)).json(), firstPostId);
  check(q.ok && q.bbcode.startsWith('[quote="'), 'цитата отдаётся JSON');
  r = await post(guest, '/forum/thread.php?id=' + TID, { action: 'reply', body: 'привет' }, true);
  check(r.type === 'opaqueredirect' || r.status === 302 || r.status === 0, 'гость не может ответить (перенаправление на вход)');
  const nt = await guest.goto(BASE + '/forum/new-thread.php?node=52');
  check(/login\.php/.test(guest.url()), 'гость не может создать тему');
  const priv = await guest.goto(BASE + '/forum/forum.php?id=90');
  check(priv.status() === 403, 'приватный раздел для гостя - 403');
  const privT = await guest.goto(BASE + '/forum/thread.php?id=10');
  check(privT.status() === 403, 'тема приватного раздела для гостя - 403');
  const privP = await guest.goto(BASE + '/forum/post.php?id=14');
  check(privP.status() === 403, 'сообщение приватного раздела для гостя - 403');
  const pq = await guest.evaluate(async () => (await fetch('/forum/post.php?action=quote&id=14')).json());
  check(!pq.ok, 'цитата из приватного раздела не отдаётся');
  await guest.goto(BASE + '/forum/search.php?q=' + encodeURIComponent('график дежурств'));
  check(await guest.locator('.sresult').count() === 0, 'поиск не находит приватные темы');
  await martin.goto(BASE + '/forum/search.php?q=' + encodeURIComponent('график дежурств'));
  check(await martin.locator('.sresult').count() === 1, 'хелпер находит тему служебного раздела');

  await browser.close();
  console.log(failed ? '\nПРОВАЛЕНО: ' + failed : '\nВсе проверки пройдены');
  process.exit(failed ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(2);
});
