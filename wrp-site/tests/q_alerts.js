// Все виды оповещений и куда ведут их ссылки: подписка, реакция, ответ, цитата, упоминание, новая тема в отслеживаемом разделе,
// перенос темы, комментарий и «Нравится» на стене профиля.
// Запуск: BASE=http://127.0.0.1:8086 NODE_PATH=/opt/node22/lib/node_modules node tests/q_alerts.js
// Потом: php tests/q_cleanup.php "<время старта из вывода>" (темы с меткой QA- и оповещения с этого времени).
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const TAG = 'QA-' + Date.now().toString(36);
let failed = 0;
function check(cond, msg) {
  console.log((cond ? '  ok   ' : '  FAIL ') + msg);
  if (!cond) failed++;
}

async function open(browser, login) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
  const page = await ctx.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/ERR_FAILED/.test(m.text())) page.errors.push(m.text()); });
  page.on('dialog', (d) => d.accept());
  const [u, p] = login.split(':');
  await page.goto(BASE + '/forum/login.php');
  await page.fill('form.login-form input[name=login]', u);
  await page.fill('form.login-form input[name=password]', p);
  await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  return page;
}

async function reply(page, tid, body) {
  await page.goto(BASE + '/forum/thread.php?id=' + tid);
  await page.fill('.qr-form textarea[name=body]', body);
  await Promise.all([page.waitForNavigation(), page.click('.qr-form button[type=submit]')]);
}

(async () => {
  const pad = (n) => String(n).padStart(2, '0');
  const now = new Date(Date.now() + 3 * 3600 * 1000 - 5000); // время сайта (МСК)
  const start = now.getUTCFullYear() + '-' + pad(now.getUTCMonth() + 1) + '-' + pad(now.getUTCDate()) + ' ' + pad(now.getUTCHours()) + ':' + pad(now.getUTCMinutes()) + ':' + pad(now.getUTCSeconds());
  const browser = await chromium.launch();
  const roxas = await open(browser, 'Roxas_Alexandro:test12345');
  const kuzya = await open(browser, 'Kuzya_Kabanov:test12345');
  const martin = await open(browser, 'Martin_Line:test12345');
  const ricardo = await open(browser, 'Ricardo_Calump:test12345');
  const diego = await open(browser, 'Diego_Bacardi:test12345');

  console.log('Подготовка: тема Roxas в «Общение», отслеживание раздела');
  await roxas.goto(BASE + '/forum/new-thread.php?node=81');
  await roxas.fill('input[name=title]', 'Тема ' + TAG);
  await roxas.fill('textarea[name=body]', 'Первое сообщение ' + TAG);
  await Promise.all([roxas.waitForNavigation(), roxas.click('.compose-submit button')]);
  const tid = +(roxas.url().match(/id=(\d+)/) || [0, 0])[1];
  check(tid > 0, 'тема создана: ' + tid);
  const firstPost = +(await roxas.locator('.post').first().getAttribute('id')).replace(/\D/g, '');
  await roxas.goto(BASE + '/forum/forum.php?id=82');
  const nodeWatch = roxas.locator('form:has(input[name=action][value=watch]) button');
  await Promise.all([roxas.waitForNavigation(), nodeWatch.click()]);
  check((await nodeWatch.innerText()).includes('Не отслеживать'), 'Roxas отслеживает раздел «Творчество»');

  console.log('Действия других пользователей');
  await martin.goto(BASE + '/forum/member.php?id=3');
  await Promise.all([martin.waitForNavigation(), martin.click('form:has(input[name=action][value=follow]) button')]);
  await kuzya.goto(BASE + '/forum/thread.php?id=' + tid);
  await kuzya.click('#post-' + firstPost + ' button[data-react]');
  await kuzya.waitForTimeout(700);
  await reply(kuzya, tid, 'Ответ Kuzya ' + TAG);
  await martin.goto(BASE + '/forum/thread.php?id=' + tid);
  await martin.click('#post-' + firstPost + ' [data-quote]');
  await martin.waitForTimeout(700);
  await martin.locator('.qr-form textarea[name=body]').press('End');
  await martin.locator('.qr-form textarea[name=body]').type('\nЦитирую ' + TAG);
  await Promise.all([martin.waitForNavigation(), martin.click('.qr-form button[type=submit]')]);
  await reply(ricardo, tid, 'Привет [user=3]Roxas_Alexandro[/user], упоминание ' + TAG);
  await kuzya.goto(BASE + '/forum/member.php?id=3');
  const wallPost = kuzya.locator('.acc-wall-post', { hasText: 'Сегодня вечером на сервере' }).first();
  await wallPost.locator('.acc-comment-form textarea').click();
  await wallPost.locator('.acc-comment-form textarea').fill('Комментарий ' + TAG);
  await Promise.all([kuzya.waitForNavigation(), wallPost.locator('.acc-comment-form button[type=submit]').click()]);
  await martin.goto(BASE + '/forum/member.php?id=3');
  const mwp = martin.locator('.acc-wall-post', { hasText: 'Сегодня вечером на сервере' }).first();
  await Promise.all([martin.waitForNavigation().catch(() => {}), mwp.locator('form[data-acc-like] button').first().click()]);
  await martin.waitForTimeout(700);
  await diego.goto(BASE + '/forum/new-thread.php?node=82');
  await diego.fill('input[name=title]', 'Новая тема ' + TAG);
  await diego.fill('textarea[name=body]', 'Текст ' + TAG);
  await Promise.all([diego.waitForNavigation(), diego.click('.compose-submit button')]);
  await diego.goto(BASE + '/forum/thread.php?id=' + tid);
  await diego.click('.mod-dd > button');
  await diego.click('[data-dialog-open="dlg-move"]');
  await diego.selectOption('#dlg-move select', '82');
  await Promise.all([diego.waitForNavigation(), diego.click('#dlg-move button[type=submit]')]);
  check(await diego.locator('.crumbs').innerText().then((t) => t.includes('Творчество')), 'тема перенесена в «Творчество»');

  console.log('Оповещения Roxas');
  await roxas.goto(BASE + '/forum/');
  const badge = await roxas.locator('a[title="Оповещения"] .count-badge').innerText().catch(() => '0');
  await roxas.goto(BASE + '/forum/alerts.php');
  const rows = await roxas.$$eval('.alert-row', (as) => as.map((a) => ({ href: a.getAttribute('href'), text: a.innerText.replace(/\s+/g, ' ').trim(), type: (a.querySelector('.alert-type') || {}).className })));
  const fresh = rows.filter((r) => r.text.includes(TAG) || /подписался|в профиле|сообщение в вашем/.test(r.text));
  console.log('  значок: ' + badge + ', оповещений: ' + rows.length);
  const want = { follow: 'подписался', like: 'оценил(а) ваше сообщение в теме', reply: 'ответил(а) в теме', quote: 'процитировал', mention: 'упомянул', thread: 'создал(а) тему', move: 'перенесена', profile_comment: 'прокомментировал', profile_like: 'оценил(а) ваше сообщение в профиле' };
  for (const [type, txt] of Object.entries(want)) {
    const r = rows.find((x) => x.type && x.type.includes('alert-type-' + type));
    check(!!r && r.text.includes(txt), 'оповещение ' + type + ': ' + (r ? r.text : 'нет'));
    if (!r) continue;
    const resp = await roxas.goto(new URL(r.href, BASE).toString());
    const finalUrl = roxas.url();
    let okTarget = resp && resp.status() === 200;
    const hash = new URL(finalUrl).hash;
    if (hash) okTarget = okTarget && (await roxas.locator(hash.replace(/^#/, '#')).count()) === 1;
    check(okTarget, '  ссылка ' + r.href + ' -> ' + finalUrl.replace(BASE, '') + ' (' + (resp && resp.status()) + ')');
  }

  console.log('Возврат состояния');
  await martin.goto(BASE + '/forum/member.php?id=3');
  await Promise.all([martin.waitForNavigation(), martin.click('form:has(input[name=action][value=unfollow]) button')]);
  const mwp2 = martin.locator('.acc-wall-post', { hasText: 'Сегодня вечером на сервере' }).first();
  await Promise.all([martin.waitForNavigation().catch(() => {}), mwp2.locator('form[data-acc-like] button').first().click()]);
  await martin.waitForTimeout(700);
  await roxas.goto(BASE + '/forum/forum.php?id=82');
  await Promise.all([roxas.waitForNavigation(), roxas.locator('form:has(input[name=action][value=watch]) button').click()]);
  for (const [n, x] of [['roxas', roxas], ['kuzya', kuzya], ['martin', martin], ['ricardo', ricardo], ['diego', diego]]) {
    check(x.errors.length === 0, 'JS без ошибок у ' + n + (x.errors.length ? ': ' + x.errors.join(' | ') : ''));
  }
  console.log(failed ? '\nFAILED: ' + failed : '\nALL OK');
  console.log('START=' + start + ' TAG=' + TAG);
  await browser.close();
})();
