// Сквозные сценарии в браузере: регистрация -> жалоба по анкете -> модератор ставит «Одобрено» и закрывает -> оповещение автору,
// правка анкеты в админке, личные сообщения, стена профиля, база знаний, новости на главной, «Прочитать всё», поиск по автору.
// Запуск: BASE=http://127.0.0.1:8086 NODE_PATH=/opt/node22/lib/node_modules node tests/q_flow.js
// Созданные данные удаляет tests/q_cleanup.php (пользователи QaUser*, темы и переписки с меткой QA-).
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
  if (login) {
    const [u, p] = login.split(':');
    await page.goto(BASE + '/forum/login.php');
    await page.fill('form.login-form input[name=login]', u);
    await page.fill('form.login-form input[name=password]', p);
    await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  }
  return page;
}

async function noPhp(page, what) {
  const html = await page.content();
  const m = html.match(/(Warning|Notice|Deprecated|Fatal error)<\/b>:|(Warning|Notice|Deprecated|Fatal error): .* on line|SQLSTATE|Stack trace/);
  check(!m, what + ': без ошибок PHP' + (m ? ' (' + m[0] + ')' : ''));
}

(async () => {
  const browser = await chromium.launch();
  const name = 'QaUser' + Math.floor(Math.random() * 90000 + 10000);
  const pass = 'qaPass12345';

  console.log('1. Регистрация ' + name);
  const nu = await open(browser);
  await nu.goto(BASE + '/forum/register.php');
  await nu.fill('#reg-username', name);
  await nu.fill('#reg-email', name.toLowerCase() + '@example.com');
  await nu.fill('#reg-password', pass);
  await nu.fill('#reg-password2', pass);
  const q = await nu.locator('label[for=reg-captcha]').innerText();
  const mm = q.match(/(\d+)\s*([+-])\s*(\d+)/);
  await nu.fill('#reg-captcha', String(mm[2] === '+' ? +mm[1] + +mm[3] : mm[1] - mm[3]));
  await nu.check('input[name=agree]');
  await Promise.all([nu.waitForNavigation(), nu.click('form button[type=submit]:has-text("Зарегистрироваться")')]);
  check(await nu.locator('.user-pill-name').innerText().catch(() => '') === name, 'после регистрации вошёл как ' + name + ' (' + nu.url() + ')');
  await noPhp(nu, 'страница после регистрации');

  console.log('2. Жалоба по анкете в «Жалобы на игроков»');
  await nu.goto(BASE + '/forum/forum.php?id=52');
  const createBtn = nu.locator('a[href*="new-thread.php?node=52"]').first();
  check(await createBtn.count() > 0, 'в разделе есть кнопка создания темы');
  await Promise.all([nu.waitForNavigation(), createBtn.click()]);
  check(await nu.locator('.compose-form-q').count() === 1, 'открылась анкета');
  check(await nu.locator('.compose-prefix-fixed').count() === 1, 'префикс фиксирован (На рассмотрении)');
  const fields = await nu.$$eval('.compose-form-q [name]', (els) => els.map((e) => ({ name: e.name, type: e.type, tag: e.tagName })));
  for (const f of fields) {
    if (f.name === '_token' || f.name === 'watch') continue;
    const sel = '.compose-form-q [name="' + f.name + '"]';
    if (f.type === 'checkbox') await nu.check(sel);
    else if (f.type === 'date') await nu.fill(sel, '2026-10-01');
    else if (f.type === 'url') await nu.fill(sel, 'https://youtu.be/dQw4w9WgXcQ');
    else if (f.tag === 'TEXTAREA') await nu.fill(sel, 'Игрок убил меня в зелёной зоне без причины. ' + TAG);
    else if (/offender/.test(f.name)) await nu.fill(sel, 'Bad_Player');
    else if (/rule/.test(f.name)) await nu.fill(sel, 'DM в зелёной зоне ' + TAG);
    else if (/time/.test(f.name)) await nu.fill(sel, '21:10');
    else await nu.fill(sel, name.replace('QaUser', 'Qa_User'));
  }
  await Promise.all([nu.waitForNavigation(), nu.click('.compose-form-q button[type=submit]')]);
  const threadUrl = nu.url();
  check(/thread\.php\?id=\d+/.test(threadUrl), 'тема создана: ' + threadUrl);
  const h1 = await nu.locator('.titlebar h1').innerText();
  check(/На рассмотрении/.test(h1) && /Bad_Player/.test(h1), 'заголовок с префиксом и ником нарушителя: ' + h1);
  check(await nu.locator('.post').count() === 1, 'в теме одно сообщение');
  const bodyText = await nu.locator('.post .bb').first().innerText().catch(() => '');
  check(bodyText.includes('Bad_Player') && bodyText.includes('Суть жалобы'), 'текст собран из ответов анкеты');
  await noPhp(nu, 'тема жалобы');
  const tid = +threadUrl.match(/id=(\d+)/)[1];

  console.log('3. Модератор Diego: «Одобрено» и закрыть');
  const diego = await open(browser, 'Diego_Bacardi:test12345');
  await diego.goto(threadUrl);
  await diego.click('.mod-dd > button');
  const lockBtn = diego.locator('.mod-status-row:has(.prefix:has-text("Одобрено")) .mod-status-lock');
  check(await lockBtn.count() === 1, 'есть кнопка «Одобрено и закрыть»');
  await Promise.all([diego.waitForNavigation(), lockBtn.click()]);
  const h1d = await diego.locator('.titlebar h1').innerText();
  check(/Одобрено/.test(h1d), 'префикс теперь «Одобрено»: ' + h1d);
  check(await diego.locator('.tmeta-flag:has-text("Закрыта")').count() === 1, 'тема закрыта');
  await diego.goto(BASE + '/forum/forum.php?id=52');
  check(await diego.locator('#thread-' + tid + ' .prefix:has-text("Одобрено")').count() === 1, 'в списке тем префикс «Одобрено»');
  check(await diego.locator('#thread-' + tid + ' .icon-lock, #thread-' + tid + ' [title*="акрыт"]').count() >= 1, 'в списке значок закрытой темы');

  console.log('4. Автор видит оповещение');
  await nu.goto(BASE + '/forum/');
  const badge = await nu.locator('a[title="Оповещения"] .count-badge').innerText().catch(() => '');
  check(+badge >= 1, 'значок оповещений: ' + badge);
  await nu.goto(BASE + '/forum/alerts.php');
  const alertRow = nu.locator('.alert-row', { hasText: 'Bad_Player' }).first();
  check(await alertRow.count() === 1, 'оповещение о теме в списке');
  const alertText = await alertRow.innerText().catch(() => '');
  check(/Одобрено/.test(alertText), 'оповещение про статус «Одобрено»: ' + alertText.replace(/\s+/g, ' '));
  await Promise.all([nu.waitForNavigation(), alertRow.click()]);
  check(nu.url().includes('thread.php?id=' + tid), 'ссылка из оповещения ведёт в тему: ' + nu.url());
  check(await nu.locator('.qr-form').count() === 0, 'автор не может ответить в закрытой теме (нет формы ответа)');
  await noPhp(nu, 'закрытая тема у автора');

  console.log('5. Админ добавляет поле в анкету');
  const admin = await open(browser, 'admin:admin12345');
  await admin.goto(BASE + '/admin/nodes.php?edit=52');
  await admin.click('[data-fb-add]');
  const row = admin.locator('.fb-row').last();
  await row.locator('[data-k=label]').fill('Сервер ' + TAG);
  await row.locator('[data-k=key]').fill('qa_server').catch(() => {});
  await Promise.all([admin.waitForNavigation(), admin.click('.adm-savebar button[type=submit]')]);
  await noPhp(admin, 'сохранение раздела');
  check(await admin.locator('.flash-success').count() >= 1, 'раздел сохранён: ' + (await admin.locator('.flash').first().innerText().catch(() => '')));
  await nu.goto(BASE + '/forum/new-thread.php?node=52');
  check(await nu.locator('.compose-form-q').innerText().then((t) => t.includes('Сервер ' + TAG)), 'новое поле появилось в анкете у пользователя');

  console.log('6. Личные сообщения Roxas -> Kuzya');
  const roxas = await open(browser, 'Roxas_Alexandro:test12345');
  await roxas.goto(BASE + '/forum/conversations.php?new=1');
  await roxas.fill('#cv-to', 'Kuzya_Kabanov');
  await roxas.fill('#cv-title', 'Переписка ' + TAG);
  await roxas.fill('#cv-msg', 'Привет, это проверка ЛС.');
  await Promise.all([roxas.waitForNavigation(), roxas.click('form button[type=submit]:has-text("Начать переписку")')]);
  const convUrl = roxas.url();
  check(/conversations\.php\?id=\d+/.test(convUrl), 'переписка создана: ' + convUrl);
  const kuzya = await open(browser, 'Kuzya_Kabanov:test12345');
  await kuzya.goto(BASE + '/forum/');
  const cb = await kuzya.locator('a[title="Личные сообщения"] .count-badge').innerText().catch(() => '');
  check(+cb >= 1, 'у Kuzya значок новых ЛС: ' + cb);
  await kuzya.goto(BASE + '/forum/conversations.php');
  const convLink = kuzya.locator('a', { hasText: 'Переписка ' + TAG }).first();
  check(await convLink.count() === 1, 'переписка в списке у получателя');
  await Promise.all([kuzya.waitForNavigation(), convLink.click()]);
  check((await kuzya.content()).includes('Привет, это проверка ЛС.'), 'получатель видит сообщение');
  await kuzya.fill('form textarea[name=message]', 'Ответ получен ' + TAG);
  await Promise.all([kuzya.waitForNavigation(), kuzya.click('form:has(input[name=action][value=reply]) button[type=submit]')]);
  await roxas.goto(convUrl);
  check((await roxas.content()).includes('Ответ получен ' + TAG), 'отправитель видит ответ');
  await noPhp(roxas, 'переписка');

  console.log('7. Стена профиля: Kuzya пишет Roxas');
  await kuzya.goto(BASE + '/forum/member.php?id=3');
  await kuzya.click('.acc-composer textarea');
  await kuzya.fill('.acc-composer textarea', 'Сообщение на стене ' + TAG);
  await Promise.all([kuzya.waitForNavigation(), kuzya.click('.acc-composer button[type=submit]')]);
  check((await kuzya.content()).includes('Сообщение на стене ' + TAG), 'сообщение на стене появилось');
  await roxas.goto(BASE + '/forum/alerts.php');
  check(await roxas.locator('.alert-row', { hasText: 'Kuzya_Kabanov' }).count() >= 1, 'Roxas получил оповещение о сообщении в профиле');
  await noPhp(kuzya, 'профиль');

  console.log('8. База знаний: статья и поиск');
  const guest = await open(browser);
  await guest.goto(BASE + '/wiki/');
  await guest.fill('input[name=q]', 'банк');
  await Promise.all([guest.waitForNavigation(), guest.press('input[name=q]', 'Enter')]);
  const wikiRes = guest.locator('a[href*="article.php"]');
  check(await wikiRes.count() >= 1, 'поиск «банк» нашёл статьи: ' + guest.url());
  await noPhp(guest, 'поиск по базе знаний');
  await Promise.all([guest.waitForNavigation(), wikiRes.first().click()]);
  check(/article\.php/.test(guest.url()) && (await guest.locator('h1').innerText()).length > 3, 'статья открылась: ' + guest.url());
  await guest.goto(BASE + '/wiki/?q=zzzzqqq');
  check((await guest.content()).match(/ничего не найдено|не найден/i) !== null, 'пустой поиск по базе знаний показывает сообщение');

  console.log('9. Новость на главной');
  await admin.goto(BASE + '/forum/new-thread.php?node=11');
  await admin.fill('input[name=title]', 'Новость ' + TAG);
  await admin.fill('textarea[name=body]', '[b]Новость[/b] для проверки главной страницы.');
  const sel = admin.locator('select[name=prefix_id]');
  if (await sel.count()) await sel.selectOption({ label: 'Обновление' });
  await Promise.all([admin.waitForNavigation(), admin.click('.compose-submit button')]);
  check(/thread\.php\?id=\d+/.test(admin.url()), 'новость создана: ' + admin.url());
  const newsTid = +(admin.url().match(/id=(\d+)/) || [0, 0])[1];
  await guest.goto(BASE + '/');
  const newsLink = guest.locator('a[href*="thread.php?id=' + newsTid + '"]');
  check(await newsLink.count() >= 1, 'новость видна на главной');
  check((await guest.content()).includes('Новость ' + TAG), 'заголовок новости на главной');
  const allNews = guest.locator('a:has-text("Все новости")');
  if (await allNews.count()) {
    await Promise.all([guest.waitForNavigation(), allNews.first().click()]);
    check(/forum\.php\?id=11/.test(guest.url()), '«Все новости» ведёт в раздел новостей: ' + guest.url());
  }

  console.log('10. Прочитать всё');
  await roxas.goto(BASE + '/forum/');
  const unreadBefore = await roxas.locator('.node-row.unread').count();
  await Promise.all([roxas.waitForNavigation(), roxas.click('.sidebar form.side-form button')]);
  check(await roxas.locator('.flash-success').count() >= 1, 'флеш «отмечен прочитанным»: ' + (await roxas.locator('.flash').first().innerText().catch(() => '')));
  const unreadAfter = await roxas.locator('.node-row.unread').count();
  check(unreadAfter === 0, 'непрочитанных разделов: было ' + unreadBefore + ', стало ' + unreadAfter);
  await roxas.goto(BASE + '/forum/find.php?type=new');
  await noPhp(roxas, 'новые сообщения после «Прочитать всё»');
  check(await roxas.locator('[id^="thread-"]').count() === 0, 'в «Новые сообщения» пусто');

  console.log('11. Поиск по автору');
  await guest.goto(BASE + '/forum/member.php?id=3');
  const findLinks = await guest.$$eval('a[href*="search.php?user="]', (as) => as.map((a) => a.getAttribute('href')));
  check(findLinks.length >= 1, 'в профиле есть ссылки «Найти»: ' + findLinks.join(' '));
  for (const l of findLinks) {
    await guest.goto(new URL(l, BASE).toString());
    await noPhp(guest, 'поиск ' + l);
    const n = await guest.locator('.sresult').count();
    check(n >= 1, 'результаты по ' + l + ': ' + n);
  }
  await guest.goto(BASE + '/forum/search.php');
  await guest.fill('#s-author', name);
  await Promise.all([guest.waitForNavigation(), guest.click('.search-main button[type=submit]')]);
  await noPhp(guest, 'поиск по автору из формы');
  check((await guest.content()).includes('Bad_Player'), 'поиск по автору ' + name + ' находит его жалобу: ' + guest.url());

  for (const [n, p] of [['new', nu], ['diego', diego], ['admin', admin], ['roxas', roxas], ['kuzya', kuzya], ['guest', guest]]) {
    check(p.errors.length === 0, 'JS без ошибок у ' + n + (p.errors.length ? ': ' + p.errors.join(' | ') : ''));
  }
  console.log(failed ? '\nFAILED: ' + failed : '\nALL OK');
  console.log('TAG=' + TAG + ' USER=' + name + ' TID=' + tid + ' NEWS=' + newsTid);
  await browser.close();
})();
