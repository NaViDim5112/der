// База знаний: черновики, счётчик просмотров, 404, поиск (XSS и спецсимволы LIKE).
// BASE=http://127.0.0.1:8084 NODE_PATH=... node tests/d_wiki.js out_dir   (нужна статья-черновик со slug d-test-draft)
const { chromium } = require('playwright');
const base = process.env.BASE || 'http://127.0.0.1:8084';
const out = process.argv[2] || '.';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
(async () => {
  const b = await chromium.launch();
  const guest = await (await b.newContext()).newPage();
  let r = await guest.goto(base + '/wiki/article.php?slug=d-test-draft');
  ok(r.status() === 404, 'черновик скрыт от гостя (404)');
  r = await guest.goto(base + '/wiki/category.php?slug=start');
  ok(!(await guest.content()).includes('d-test-draft'), 'черновика нет в списке раздела у гостя');
  r = await guest.goto(base + '/wiki/article.php?slug=nope-nope');
  ok(r.status() === 404, 'неизвестная статья 404');
  r = await guest.goto(base + '/wiki/category.php?slug=nope');
  ok(r.status() === 404, 'неизвестный раздел 404');
  r = await guest.goto(base + '/wiki/category.php');
  ok(r.status() === 404, 'раздел без slug 404');

  // просмотры: дважды в одной сессии = +1
  const views = async () => { const t = await guest.textContent('.titlebar-meta'); return parseInt((t.match(/(\d+)\s*просмотр/) || [])[1] || '0', 10); };
  await guest.goto(base + '/wiki/article.php?slug=world-bank'); const v1 = await views();
  await guest.goto(base + '/wiki/article.php?slug=world-bank'); const v2 = await views();
  ok(v1 === v2, 'повторный просмотр в той же сессии не считается (' + v1 + ' -> ' + v2 + ')');
  const g2 = await (await b.newContext()).newPage();
  await g2.goto(base + '/wiki/article.php?slug=world-bank'); const t3 = await g2.textContent('.titlebar-meta');
  const v3 = parseInt((t3.match(/(\d+)\s*просмотр/) || [])[1] || '0', 10);
  ok(v3 === v2 + 1, 'новая сессия +1 (' + v3 + ')');

  // поиск
  await guest.goto(base + '/wiki/?q=' + encodeURIComponent('<script>alert(1)</script>'));
  let html = await guest.content();
  ok(!html.includes('<script>alert(1)</script>'), 'запрос в поиске экранирован');
  await guest.goto(base + '/wiki/?q=' + encodeURIComponent('%%'));
  ok(/ничего не нашлось/.test(await guest.content()), '%% не находит всё подряд');
  await guest.goto(base + '/wiki/?q=' + encodeURIComponent('__'));
  ok(/ничего не нашлось/.test(await guest.content()), '__ не находит всё подряд');
  await guest.goto(base + '/wiki/?q=' + encodeURIComponent('a'));
  ok(/не меньше двух символов/.test(await guest.content()), 'короткий запрос - подсказка');
  await guest.goto(base + '/wiki/?q=' + encodeURIComponent('банкомат мэрии'));
  html = await guest.content();
  ok(/World Bank/.test(html) && (html.match(/<mark>/g) || []).length >= 2, 'поиск по двум словам с подсветкой');
  await guest.goto(base + '/wiki/?q=' + encodeURIComponent('Скрытый'));
  ok(/ничего не нашлось/.test(await guest.content()), 'черновики не попадают в поиск');

  // админ
  const admin = await (await b.newContext()).newPage();
  await admin.goto(base + '/forum/login.php');
  await admin.fill('input[name=login]', 'admin'); await admin.fill('input[name=password]', 'admin12345');
  await Promise.all([admin.waitForNavigation(), admin.click('form.login-form button[type=submit]')]);
  r = await admin.goto(base + '/wiki/article.php?slug=d-test-draft');
  html = await admin.content();
  ok(r.status() === 200 && /wiki-draft">Черновик/.test(html), 'админ видит черновик с пометкой');
  ok(!html.includes('<script>alert(1)</script>') && html.includes('&lt;script&gt;'), 'тело статьи экранировано');
  ok(!html.includes('<b>x</b>'), 'заголовок экранирован');
  ok(/admin\/wiki\.php\?article=\d+/.test(html), 'кнопка «Редактировать»');
  await admin.screenshot({ path: out + '/d-wiki-draft-admin.png', fullPage: true });
  await admin.goto(base + '/wiki/category.php?slug=start');
  html = await admin.content();
  ok(/new_article=1&amp;cat=1/.test(html) && /admin\/wiki\.php\?category=1/.test(html), 'кнопки админа в разделе');
  ok(html.includes('d-test-draft'), 'админ видит черновик в списке');
  await b.close();
})();
