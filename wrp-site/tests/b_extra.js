// Дополнительные проверки: бан, флуд в ЛС, удаление модератором, XSS на стене, гости.
//   BASE=http://127.0.0.1:8082 node tests/b_extra.js
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8082';
const results = [];
const check = (n, c, x) => { results.push(c); console.log((c ? 'PASS ' : 'FAIL ') + n + (x ? ' :: ' + x : '')); };
const sql = (q) => execSync('mysql wrp -N', { input: q }).toString().trim();

(async () => {
  const hash = execSync(`php -r 'echo password_hash("test12345", PASSWORD_DEFAULT);'`).toString();
  for (const n of ['ExtraTester_A', 'ExtraTester_B']) {
    sql(`INSERT IGNORE INTO users (username, email, password_hash, group_id, created_at) VALUES ('${n}', '${n.toLowerCase()}@test.local', '${hash}', 2, NOW())`);
    sql(`UPDATE users SET is_banned = 0, ban_reason = NULL, ban_until = NULL, password_hash = '${hash}' WHERE username = '${n}'`);
  }
  const aId = sql(`SELECT id FROM users WHERE username = 'ExtraTester_A'`);
  const bId = sql(`SELECT id FROM users WHERE username = 'ExtraTester_B'`);
  const browser = await chromium.launch();
  const mk = async () => { const c = await browser.newContext({ viewport: { width: 1300, height: 900 } }); const p = await c.newPage(); p.on('dialog', d => d.accept()); return p; };
  const submit = async (page, sel) => Promise.all([page.waitForNavigation(), page.click(sel)]);
  const login = async (page, u, p) => { await page.goto(BASE + '/forum/login.php'); await page.fill('#login', u); await page.fill('#password', p); await submit(page, 'form.login-form button[type=submit]'); };
  const token = async (page) => page.getAttribute('meta[name=csrf-token]', 'content');
  const flash = async (page) => (await page.locator('.flash').allTextContents()).join(' | ');

  // ---------- XSS на стене ----------
  const A = await mk();
  await login(A, 'ExtraTester_A', 'test12345');
  await A.goto(BASE + '/forum/member.php?id=' + bId);
  await A.click('.acc-composer textarea');
  await A.fill('.acc-composer textarea', '<img src=x onerror=alert(1)> [url=javascript:alert(1)]клик[/url] [b]ок[/b]');
  await submit(A, '.acc-composer button[type=submit]');
  const html = await A.innerHTML('.acc-wall-post .acc-wall-body');
  check('wall: HTML escaped, js: url not linked, BB works', html.includes('&lt;img') && !html.includes('href="javascript') && html.includes('<strong>ок</strong>'), html);
  const ppId = (await A.getAttribute('.acc-wall-post', 'id')).replace('profile-post-', '');

  // ---------- Гость ----------
  const G = await mk();
  await G.goto(BASE + '/forum/member.php?id=' + bId);
  check('guest: no composer, no like buttons', (await G.locator('.acc-composer').count()) === 0 && (await G.locator('form[data-acc-like]').count()) === 0 && (await G.locator('.acc-wall-guest').count()) === 1);
  const gp = await G.request.post(BASE + '/forum/member.php?id=' + bId, { form: { _token: await token(G), action: 'wall_post', body: 'гость' }, maxRedirects: 0 });
  check('guest: POST refused', gp.status() === 302 && !sql(`SELECT COUNT(*) FROM profile_posts WHERE body = 'гость'`).startsWith('1'));
  const gc = await G.goto(BASE + '/forum/conversations.php');
  check('guest: conversations redirect to login', G.url().includes('login.php'));
  await G.goto(BASE + '/forum/account.php');
  check('guest: account redirect to login', G.url().includes('login.php'));
  await G.goto(BASE + '/cabinet/');
  check('guest: cabinet redirect to login', G.url().includes('login.php'));

  // ---------- Модератор удаляет чужое сообщение ----------
  const D = await mk();
  await login(D, 'Diego_Bacardi', 'test12345');
  await D.goto(BASE + '/forum/member.php?id=' + bId);
  check('moderator: sees delete on foreign wall post', (await D.locator('#profile-post-' + ppId + ' input[value=wall_delete]').count()) === 1);
  await submit(D, '#profile-post-' + ppId + ' form:has(input[value=wall_delete]) button');
  check('moderator: post deleted', (await D.locator('#profile-post-' + ppId).count()) === 0);
  check('moderator: mod_log written', sql(`SELECT COUNT(*) FROM mod_log WHERE action = 'profile_post_delete' AND target_id = ${ppId}`) === '1');
  const R = await mk();
  await login(R, 'Roxas_Alexandro', 'test12345');
  await R.goto(BASE + '/forum/member.php?id=' + bId);
  const del = await R.request.post(BASE + '/forum/member.php?id=' + bId, { form: { _token: await token(R), action: 'wall_delete', cid: ppId }, maxRedirects: 0 });
  check('other user: cannot delete foreign wall post', del.status() === 302 && sql(`SELECT is_deleted FROM profile_posts WHERE id = ${ppId}`) === '1');

  // ---------- Флуд в личных сообщениях ----------
  await A.goto(BASE + '/forum/conversations.php?new=1&to=ExtraTester_B');
  await A.fill('#cv-title', 'Проверка флуда');
  await A.fill('#cv-msg', 'Первое');
  await submit(A, 'form.form button[type=submit]');
  const convUrl = A.url();
  await A.fill('.acc-reply textarea', 'Второе сразу');
  await submit(A, '.acc-reply button[type=submit]');
  check('conv: flood control for non-staff', (await A.textContent('.acc-reply')).includes('Слишком часто') && (await A.inputValue('.acc-reply textarea')) === 'Второе сразу');

  // ---------- Бан ----------
  sql(`UPDATE users SET is_banned = 1, ban_reason = 'тест', ban_until = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ${aId}`);
  await A.goto(BASE + '/forum/member.php?id=' + bId);
  check('banned: no composer', (await A.locator('.acc-composer').count()) === 0);
  const bp = await A.request.post(BASE + '/forum/member.php?id=' + bId, { form: { _token: await token(A), action: 'wall_post', body: 'забанен' } });
  check('banned: wall POST refused', sql(`SELECT COUNT(*) FROM profile_posts WHERE body = 'забанен'`) === '0');
  await A.goto(BASE + '/forum/conversations.php?new=1&to=ExtraTester_B');
  check('banned: cannot start conversation', (await A.textContent('main')).includes('Пока аккаунт заблокирован'));
  await A.goto(convUrl);
  check('banned: can read, cannot reply', (await A.locator('.acc-msg').count()) === 1 && (await A.locator('.acc-reply textarea').count()) === 0);
  await A.goto(BASE + '/forum/account.php');
  await A.request.post(BASE + '/forum/account.php', { form: { _token: await token(A), action: 'status', status_text: 'бан-статус' } });
  check('banned: status change refused', sql(`SELECT COALESCE(status_text, '') FROM users WHERE id = ${aId}`) !== 'бан-статус');
  await R.goto(BASE + '/forum/member.php?id=' + aId);
  check('banned: ban notice hidden from regular users', !(await R.textContent('main')).includes('Пользователь заблокирован'));
  await D.goto(BASE + '/forum/member.php?id=' + aId);
  check('banned: ban notice visible to staff', (await D.textContent('main')).includes('Пользователь заблокирован'));
  sql(`UPDATE users SET is_banned = 0, ban_reason = NULL, ban_until = NULL WHERE id = ${aId}`);

  // ---------- Сеансы и CSRF ----------
  const nojs = await A.request.post(BASE + '/forum/member.php?id=' + bId, { form: { action: 'follow' }, maxRedirects: 0 });
  check('member: POST without CSRF -> 400', nojs.status() === 400, String(nojs.status()));
  const ajax = await A.request.post(BASE + '/forum/member.php?id=' + bId, { form: { action: 'like', type: 'post', cid: '1' }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  check('member: AJAX without CSRF -> 400 JSON', ajax.status() === 400 && (await ajax.json()).ok === false);
  const conv404 = await A.goto(BASE + '/forum/conversations.php?id=999999');
  check('conv: missing id -> 404', conv404.status() === 404);
  const m404 = await A.goto(BASE + '/forum/member.php?id=abc');
  check('member: bad id -> 404', m404.status() === 404);

  await browser.close();
  sql(`DELETE FROM profile_posts WHERE profile_user_id = ${bId}`);
  const failed = results.filter(r => !r).length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
