// Проверка интерактивных элементов (тема, ширина, меню, выпадашки, редактор, цитата, реакция, отслеживание, диалоги модерации).
// Реакцию и отслеживание возвращает обратно. Запуск: BASE=http://127.0.0.1:8086 NODE_PATH=/opt/node22/lib/node_modules node tests/q_interact.js
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
let failed = 0;
function check(cond, msg) {
  console.log((cond ? '  ok   ' : '  FAIL ') + msg);
  if (!cond) failed++;
}

async function open(browser, login, vp) {
  const ctx = await browser.newContext({ viewport: vp || { width: 1440, height: 1000 } });
  await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
  const page = await ctx.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/ERR_FAILED/.test(m.text())) page.errors.push(m.text()); });
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

(async () => {
  const browser = await chromium.launch();
  const p = await open(browser, 'Roxas_Alexandro:test12345');

  console.log('1. Тема, ширина, меню');
  await p.goto(BASE + '/forum/');
  await p.click('.titlebar [data-theme-toggle]');
  check(await p.getAttribute('html', 'data-theme') === 'light', 'тема переключилась на светлую');
  await p.reload();
  check(await p.getAttribute('html', 'data-theme') === 'light', 'светлая тема сохраняется после перезагрузки');
  await p.goto(BASE + '/wiki/');
  check(await p.getAttribute('html', 'data-theme') === 'light', 'светлая тема в базе знаний');
  await p.goto(BASE + '/cabinet/');
  check(await p.getAttribute('html', 'data-theme') === 'light', 'светлая тема в кабинете');
  await p.click('.titlebar [data-theme-toggle]');
  check(await p.getAttribute('html', 'data-theme') === 'dark', 'тема вернулась на тёмную');
  await p.click('.titlebar [data-wide-toggle]');
  check(await p.evaluate(() => document.documentElement.classList.contains('is-wide')), 'широкий режим включён');
  await p.click('.titlebar [data-wide-toggle]');
  await p.click('.sidebar-toggle');
  check(await p.evaluate(() => document.body.classList.contains('sidebar-collapsed')), 'левое меню свернулось');
  await p.waitForTimeout(400);
  await p.click('.topbar [data-toggle-sidebar]');
  check(await p.evaluate(() => !document.body.classList.contains('sidebar-collapsed')), 'левое меню развернулось кнопкой в шапке');
  await p.click('.user-pill');
  check(await p.isVisible('.account-menu'), 'меню аккаунта открылось');
  await p.click('h1');
  check(!(await p.isVisible('.account-menu')), 'меню аккаунта закрылось по клику вне');
  const grp = p.locator('.side-group:has-text("Пользователи") > button');
  await grp.click();
  check(await p.isVisible('.side-sub a[href*="members.php"]'), 'группа «Пользователи» раскрылась');

  console.log('2. Редактор и предпросмотр');
  await p.goto(BASE + '/forum/thread.php?id=1');
  const ta = p.locator('.qr-form textarea[name=body]');
  await ta.fill('Проверка [b]жирного[/b]');
  await p.click('.qr-form .editor-preview-btn');
  await p.waitForSelector('.qr-form .editor-preview strong', { timeout: 5000 }).catch(() => {});
  check(await p.locator('.qr-form .editor-preview strong').count() === 1, 'предпросмотр показывает жирный текст');
  await p.click('.qr-form .editor-preview-btn');
  await ta.fill('');
  const qbtn = p.locator('[data-quote]').first();
  if (await qbtn.count()) {
    await qbtn.click();
    await p.waitForTimeout(800);
    check(/\[quote/.test(await ta.inputValue()), 'цитата вставлена в редактор');
    await ta.fill('');
  } else check(false, 'нет кнопки цитаты');
  await p.evaluate(() => { try { sessionStorage.clear(); localStorage.clear(); } catch (e) {} });

  console.log('3. Реакция и отслеживание (с возвратом)');
  const reactId = await p.locator('button[data-react]').first().getAttribute('data-react');
  const otherPost = p.locator('button[data-react="' + reactId + '"]');
  if (await otherPost.count()) {
    const before = await otherPost.innerText();
    await otherPost.click();
    await p.waitForTimeout(800);
    const mid = await otherPost.innerText();
    check(mid !== before, 'реакция поставлена: "' + before.trim() + '" -> "' + mid.trim() + '"');
    await otherPost.click();
    await p.waitForTimeout(800);
    check((await otherPost.innerText()) === before, 'реакция снята');
  } else check(false, 'нет кнопки реакции');
  const wbtn = p.locator('form[data-watch-form] button');
  const w0 = await wbtn.innerText();
  await wbtn.click();
  await p.waitForTimeout(800);
  const w1 = await wbtn.innerText();
  check(w0 !== w1, 'отслеживание переключилось: ' + w0.trim() + ' -> ' + w1.trim());
  await wbtn.click();
  await p.waitForTimeout(800);
  check((await wbtn.innerText()) === w0, 'отслеживание вернулось');

  console.log('4. Модерация: диалоги');
  const d = await open(browser, 'Diego_Bacardi:test12345');
  await d.goto(BASE + '/forum/thread.php?id=1');
  await d.click('.mod-dd > button');
  check(await d.isVisible('.mod-menu'), 'меню модерации открылось');
  await d.click('[data-dialog-open="dlg-move"]');
  check(await d.isVisible('#dlg-move'), 'диалог переноса открылся');
  await d.click('#dlg-move [data-dialog-close]').catch(() => d.keyboard.press('Escape'));
  await d.waitForTimeout(300);
  check(!(await d.isVisible('#dlg-move')), 'диалог переноса закрылся');

  console.log('5. Профиль: «Найти», копирование адреса сервера');
  await p.goto(BASE + '/forum/member.php?id=2');
  await p.click('.dropdown:has-text("Найти") > button');
  check(await p.isVisible('.dropdown.open .dropdown-menu a[href*="search.php"]'), 'меню «Найти» открылось');
  await p.goto(BASE + '/forum/');
  await p.click('.server-widget [data-copy]');
  await p.waitForTimeout(300);
  check(await p.locator('.toast').count() >= 1, 'копирование адреса показывает уведомление');

  console.log('6. Мобильное меню');
  const m = await open(browser, 'Roxas_Alexandro:test12345', { width: 390, height: 844 });
  await m.goto(BASE + '/forum/');
  await m.click('.topbar [data-toggle-sidebar]');
  await m.waitForTimeout(400);
  const sb = await m.locator('#sidebar').boundingBox();
  check(sb && sb.x >= -1, 'левое меню выехало на телефоне (x=' + (sb && Math.round(sb.x)) + ')');
  await m.click('.sidebar-backdrop', { position: { x: 370, y: 400 } });
  await m.waitForTimeout(400);
  check(await m.evaluate(() => !document.body.classList.contains('sidebar-open')), 'меню закрылось по клику на фон');
  await m.goto(BASE + '/');
  const burger = m.locator('[data-site-burger]').first();
  if (await burger.count()) {
    await burger.click();
    await m.waitForTimeout(400);
    check(await m.locator('#site-nav.is-open').count() >= 1, 'меню главной открылось на телефоне');
  } else check(false, 'нет кнопки меню на главной');

  for (const [n, x] of [['roxas', p], ['diego', d], ['mobile', m]]) {
    check(x.errors.length === 0, 'JS без ошибок у ' + n + (x.errors.length ? ': ' + x.errors.join(' | ') : ''));
  }
  console.log(failed ? '\nFAILED: ' + failed : '\nALL OK');
  await browser.close();
})();
