// Сквозная проверка аккаунтов: BASE=http://127.0.0.1:8082 IMG=<папка с картинками> node tests/b_flow.js
// Картинки готовит: php tests/b_make_images.php <папка>
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8082';
const IMG = process.env.IMG || path.join(__dirname, 'tmp');
const SHOTS = path.join(__dirname, 'screens');
const results = [];
const check = (name, cond, extra) => { results.push([cond ? 'PASS' : 'FAIL', name, extra || '']); console.log((cond ? 'PASS ' : 'FAIL ') + name + (extra ? ' :: ' + extra : '')); };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function go(page, p) { const r = await page.goto(BASE + p, { waitUntil: 'domcontentloaded' }); return r; }
async function submit(page, selector) { await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click(selector)]); }
async function flashText(page) { return (await page.locator('.flash').allTextContents()).join(' | '); }
async function login(page, u, p) {
  await go(page, '/forum/login.php');
  await page.fill('#login', u);
  await page.fill('#password', p);
  await submit(page, 'form.login-form button[type=submit]');
}
async function token(page) { return page.getAttribute('meta[name=csrf-token]', 'content'); }
async function captcha(page) {
  const t = await page.textContent('label[for=reg-captcha]');
  const m = t.match(/(\d+)\s*([+-])\s*(\d+)/);
  return String(m[2] === '+' ? +m[1] + +m[3] : +m[1] - +m[3]);
}
async function userId(page) {
  const href = await page.getAttribute('.account-menu-head > a', 'href');
  return +(href.match(/id=(\d+)/) || [])[1];
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const newCtx = async () => {
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/fonts\.g|ERR_TUNNEL|net::ERR/.test(m.text())) errors.push('console: ' + m.text()); });
    page.on('dialog', d => d.accept());
    return { ctx, page };
  };

  const suffix = String(Date.now()).slice(-5);
  const NAME = 'Tester_' + suffix;
  const PASS = 'Secret-pass1';
  const NEWPASS = 'Another-pass2';

  // ---------- Регистрация ----------
  const A = await newCtx();
  let page = A.page;
  await go(page, '/forum/register.php');
  await submit(page, 'form.form button[type=submit]');
  check('register: empty form shows field errors', (await page.locator('.field-error').count()) >= 4, String(await page.locator('.field-error').count()));

  const fill = async (o) => {
    await page.fill('#reg-username', o.u);
    await page.fill('#reg-email', o.e);
    await page.fill('#reg-password', o.p);
    await page.fill('#reg-password2', o.p2 === undefined ? o.p : o.p2);
    await page.fill('#reg-captcha', o.c === undefined ? await captcha(page) : o.c);
    if (o.agree !== false) await page.check('input[name=agree]'); else await page.uncheck('input[name=agree]');
    await submit(page, 'form.form button[type=submit]');
  };
  await fill({ u: 'roxas_alexandro', e: 'x' + suffix + '@test.local', p: PASS });
  check('register: taken name (case-insensitive)', (await page.textContent('body')).includes('Это имя уже занято'));
  await fill({ u: 'Модератор', e: 'x' + suffix + '@test.local', p: PASS });
  check('register: reserved name', (await page.textContent('body')).includes('зарезервировано'));
  await fill({ u: 'Rоxas_Fake', e: 'x' + suffix + '@test.local', p: PASS });
  check('register: mixed scripts', (await page.textContent('body')).includes('Не смешивайте'));
  await fill({ u: NAME, e: 'roxas@wrp.local', p: PASS });
  check('register: taken email', (await page.textContent('body')).includes('уже используется'));
  await fill({ u: NAME, e: 'bad-email', p: 'short', p2: 'other' });
  const t1 = await page.textContent('body');
  check('register: bad email + short password', t1.includes('корректный email') && t1.includes('не короче 8'));
  await fill({ u: NAME, e: 'x' + suffix + '@test.local', p: PASS, p2: PASS + 'x' });
  check('register: password mismatch', (await page.textContent('body')).includes('Пароли не совпадают'));
  await fill({ u: NAME, e: 'x' + suffix + '@test.local', p: NAME });
  check('register: password equals name', (await page.textContent('body')).includes('не должен совпадать'));
  await fill({ u: NAME, e: 'x' + suffix + '@test.local', p: PASS, c: '999' });
  check('register: wrong captcha', (await page.textContent('body')).includes('Неверный ответ'));
  await fill({ u: NAME, e: 'x' + suffix + '@test.local', p: PASS, agree: false });
  check('register: rules not accepted', (await page.textContent('body')).includes('принять правила'));
  check('register: values kept after error', (await page.inputValue('#reg-username')) === NAME && (await page.inputValue('#reg-email')) === 'x' + suffix + '@test.local');
  await page.evaluate(() => { document.querySelector('input[name=website]').value = 'http://spam'; });
  await fill({ u: NAME, e: 'x' + suffix + '@test.local', p: PASS });
  check('register: honeypot blocks', (await page.textContent('body')).includes('Проверка не пройдена'));
  await fill({ u: NAME, e: 'x' + suffix + '@test.local', p: PASS });
  check('register: success redirects to forum', page.url().endsWith('/forum/') && (await flashText(page)).includes('Добро пожаловать'), page.url());
  check('register: logged in after registration', (await page.locator('.user-pill-name').textContent()).trim() === NAME);
  const myId = await userId(page);
  await go(page, '/forum/register.php');
  check('register: logged-in user redirected away', !page.url().includes('register.php'));

  // ---------- Выход и вход ----------
  await page.click('.user-pill');
  await submit(page, '.account-menu-logout button');
  check('logout works', (await page.locator('.user-pill').count()) === 0);
  await login(page, NAME, PASS);
  check('login with new account', (await page.locator('.user-pill').count()) === 1);

  // ---------- Аватар ----------
  await go(page, '/forum/account.php');
  await page.setInputFiles('input[name=avatar]', path.join(IMG, 'avatar.png'));
  check('avatar: JS preview shows picked file', (await page.locator('[data-avatar-preview] img').count()) >= 1);
  await submit(page, 'form.acc-upload-form:has(input[name=avatar]) button[type=submit]');
  let fl = await flashText(page);
  check('avatar: upload PNG ok', fl.includes('Аватар обновлён'), fl);
  const av1 = await page.getAttribute('.acc-upload-preview .avatar img', 'src');
  check('avatar: re-encoded file name', /\/uploads\/avatars\/\d+_[a-f0-9]{16}\.(webp|png)$/.test(av1 || ''), av1);
  const r1 = await page.request.get(BASE + av1);
  check('avatar: file served', r1.status() === 200 && /image\/(webp|png)/.test(r1.headers()['content-type'] || ''), r1.headers()['content-type']);
  await page.setInputFiles('input[name=avatar]', path.join(IMG, 'photo.jpg'));
  await submit(page, 'form.acc-upload-form:has(input[name=avatar]) button[type=submit]');
  const av2 = await page.getAttribute('.acc-upload-preview .avatar img', 'src');
  const r1b = await page.request.get(BASE + av1);
  check('avatar: replaced, old file deleted', av2 !== av1 && r1b.status() === 404, r1b.status() + ' ' + av2);
  // Серверные проверки (в обход JS-проверки в браузере)
  const tok = await token(page);
  const up = async (field, file, mime) => page.request.post(BASE + '/forum/account.php?tab=profile', {
    multipart: { _token: tok, action: field + '_upload', [field]: { name: path.basename(file), mimeType: mime, buffer: fs.readFileSync(path.join(IMG, file)) } },
    maxRedirects: 0,
  });
  await up('avatar', 'fake.png', 'image/png');
  await go(page, '/forum/account.php');
  fl = await flashText(page);
  check('avatar: fake image rejected', fl.includes('не является изображением'), fl);
  await up('avatar', 'big.png', 'image/png');
  await go(page, '/forum/account.php');
  fl = await flashText(page);
  check('avatar: >2MB rejected', fl.includes('слишком большой'), fl);
  await up('avatar', 'pic.webp', 'image/webp');
  await go(page, '/forum/account.php');
  check('avatar: webp accepted', (await flashText(page)).includes('Аватар обновлён'));
  await up('avatar', 'small.gif', 'image/gif');
  await go(page, '/forum/account.php');
  check('avatar: gif accepted', (await flashText(page)).includes('Аватар обновлён'));
  const noCsrf = await page.request.post(BASE + '/forum/account.php', { form: { action: 'avatar_delete' }, maxRedirects: 0 });
  check('account: POST without CSRF rejected', noCsrf.status() === 400, String(noCsrf.status()));

  // ---------- Обложка ----------
  await page.setInputFiles('input[name=cover]', path.join(IMG, 'cover.jpg'));
  await submit(page, 'form.acc-upload-form:has(input[name=cover]) button[type=submit]');
  fl = await flashText(page);
  check('cover: upload ok', fl.includes('Обложка профиля обновлена'), fl);
  const cv = await page.getAttribute('.acc-cover-edit img', 'src');
  const cvr = await page.request.get(BASE + cv);
  check('cover: jpg served', /\/uploads\/covers\/\d+_[a-f0-9]{16}\.jpg$/.test(cv || '') && cvr.status() === 200, cv);
  await up('cover', 'tiny-cover.jpg', 'image/jpeg');
  await go(page, '/forum/account.php');
  check('cover: too small rejected', (await flashText(page)).includes('слишком маленькое'));

  // ---------- Информация ----------
  const titleDisabled = await page.isDisabled('#acc-title');
  check('profile: custom title disabled for regular user', titleDisabled);
  await page.fill('#acc-location', 'Лос-Сантос');
  check('profile: live preview of location', (await page.textContent('[data-live-out=location]')) === 'Лос-Сантос');
  await page.fill('#acc-about', 'Привет! [b]Играю[/b] на сервере <script>alert(1)</script>');
  await submit(page, 'form.form:has(input[value=profile_save]) button[type=submit]');
  check('profile: saved', (await flashText(page)).includes('Профиль сохранён'));
  await page.request.post(BASE + '/forum/account.php?tab=profile', { form: { _token: await token(page), action: 'profile_save', custom_title: 'Хакер', location: 'Лос-Сантос', about: 'x' } });
  await go(page, '/forum/member.php?id=' + myId + '&tab=about');
  check('profile: custom title ignored for regular user', !(await page.textContent('.acc-cover-strip')).includes('Хакер'));
  // вернуть «о себе»
  await go(page, '/forum/account.php');
  await page.fill('#acc-about', 'Привет! [b]Играю[/b] на сервере <script>alert(1)</script>');
  await submit(page, 'form.form:has(input[value=profile_save]) button[type=submit]');
  await page.evaluate(() => document.querySelectorAll('[maxlength]').forEach(el => el.removeAttribute('maxlength')));
  await page.fill('#acc-about', 'x'.repeat(3001));
  await submit(page, 'form.form:has(input[value=profile_save]) button[type=submit]');
  check('profile: about > 3000 rejected, value kept', (await page.textContent('body')).includes('не больше 3000') && (await page.inputValue('#acc-about')).length === 3001);

  // ---------- Подпись ----------
  await go(page, '/forum/account.php?tab=signature');
  await page.evaluate(() => document.querySelectorAll('[maxlength]').forEach(el => el.removeAttribute('maxlength')));
  await page.fill('textarea[name=signature]', 'y'.repeat(501));
  await submit(page, 'form.form button[type=submit]');
  check('signature: >500 rejected', (await page.textContent('body')).includes('не больше 500'));
  await page.fill('textarea[name=signature]', '[center][i]Тестовая подпись[/i][/center]');
  await submit(page, 'form.form button[type=submit]');
  check('signature: saved and rendered', (await flashText(page)).includes('Подпись сохранена') && (await page.locator('.acc-signature-demo em').count()) === 1);

  // ---------- Профиль ----------
  await go(page, '/forum/member.php?id=' + myId + '&tab=about');
  const aboutHtml = await page.innerHTML('.acc-about-main');
  check('member about: BB rendered, HTML escaped', aboutHtml.includes('<strong>Играю</strong>') && aboutHtml.includes('&lt;script&gt;'));
  check('member about: signature shown', (await page.textContent('.acc-about-main')).includes('Тестовая подпись'));
  check('member: cover image shown', (await page.locator('.acc-cover-img').count()) === 1);
  check('member: location in strip', (await page.textContent('.acc-cover-strip')).includes('Из Лос-Сантос'));
  await page.screenshot({ path: path.join(SHOTS, 'b-flow-member-own.png') });

  // ---------- Безопасность ----------
  await go(page, '/forum/account.php?tab=security');
  await page.fill('#sec-email', 'new' + suffix + '@test.local');
  await page.fill('#sec-email-pass', 'wrong-pass');
  await submit(page, 'form:has(input[value=email_save]) button[type=submit]');
  check('email: wrong password rejected', (await page.textContent('body')).includes('Неверный текущий пароль'));
  await page.fill('#sec-email', 'new' + suffix + '@test.local');
  await page.fill('#sec-email-pass', PASS);
  await submit(page, 'form:has(input[value=email_save]) button[type=submit]');
  check('email: changed', (await flashText(page)).includes('Email изменён') && (await page.textContent('body')).includes('new' + suffix + '@test.local'));

  // второй браузер с тем же аккаунтом
  const A2 = await newCtx();
  await login(A2.page, NAME, PASS);
  check('second device logged in', (await A2.page.locator('.user-pill').count()) === 1);

  await page.fill('#sec-cur', PASS);
  await page.fill('#sec-new', NEWPASS);
  await page.fill('#sec-new2', NEWPASS);
  check('password meter visible', await page.isVisible('#sec-meter'));
  await submit(page, 'form:has(input[value=password_save]) button[type=submit]');
  check('password: changed, still logged in', (await flashText(page)).includes('Пароль изменён') && (await page.locator('.user-pill').count()) === 1);
  await go(page, '/forum/account.php?tab=security');
  check('password: session valid on next request', (await page.locator('.user-pill').count()) === 1);
  await go(A2.page, '/forum/');
  check('password: other device logged out', (await A2.page.locator('.user-pill').count()) === 0);
  await login(A2.page, NAME, PASS);
  check('password: old password no longer works', (await A2.page.locator('.user-pill').count()) === 0);
  await login(A2.page, NAME, NEWPASS);
  check('password: new password works', (await A2.page.locator('.user-pill').count()) === 1);

  await page.fill('#sec-logout-pass', NEWPASS);
  await submit(page, 'form:has(input[value=logout_all]) button[type=submit]');
  check('logout all: current stays', (await flashText(page)).includes('Вы вышли на всех остальных') && (await page.locator('.user-pill').count()) === 1);
  await go(A2.page, '/forum/');
  check('logout all: other device logged out', (await A2.page.locator('.user-pill').count()) === 0);
  await A2.ctx.close();

  // ---------- Статус из меню ----------
  await go(page, '/forum/members.php');
  await page.click('.user-pill');
  await page.fill('.account-menu-status input[name=status_text]', 'Ищу семью на сервере');
  await Promise.all([page.waitForNavigation(), page.press('.account-menu-status input[name=status_text]', 'Enter')]);
  check('status: saved and redirected back', page.url().includes('/forum/members.php') && (await flashText(page)).includes('Статус обновлён'), page.url());
  await go(page, '/forum/member.php?id=' + myId);
  check('status: shown on profile', (await page.textContent('.acc-profile-status')).includes('Ищу семью'));

  // ---------- Стена ----------
  const B = await newCtx();
  await login(B.page, 'Roxas_Alexandro', 'test12345');
  const roxId = await userId(B.page);
  await go(page, '/forum/member.php?id=' + roxId);
  await page.click('.acc-composer textarea');
  await page.fill('.acc-composer textarea', 'Привет, [b]Roxas[/b]! Как дела?');
  await submit(page, '.acc-composer button[type=submit]');
  check('wall: post on other profile', (await page.locator('.acc-wall-post').first().textContent()).includes('Как дела'));
  const ppId = +(await page.getAttribute('.acc-wall-post', 'id')).replace('profile-post-', '');
  await page.fill('.acc-composer textarea', 'Второе сообщение подряд');
  await submit(page, '.acc-composer button[type=submit]');
  check('wall: flood control 10s', (await flashText(page)).includes('Слишком часто') && (await page.inputValue('.acc-composer textarea')) === 'Второе сообщение подряд');

  await go(B.page, '/forum/member.php?id=' + roxId);
  check('wall: owner sees alert badge', (await B.page.locator('a[href*="alerts.php"] .count-badge').count()) === 1);
  // лайк от владельца (AJAX)
  await B.page.click('#profile-post-' + ppId + ' form[data-acc-like] button');
  await B.page.waitForSelector('[data-likes="post-' + ppId + '"]:not([hidden])');
  check('wall: AJAX like shows line', (await B.page.textContent('[data-likes="post-' + ppId + '"]')).includes('Вы'));
  check('wall: like button toggled', (await B.page.textContent('#profile-post-' + ppId + ' form[data-acc-like] button')).includes('Не нравится'));
  await B.page.reload();
  check('wall: like persisted', (await B.page.textContent('[data-likes="post-' + ppId + '"]')).includes('Вы'));
  // комментарий владельца
  await B.page.click('#profile-post-' + ppId + ' [data-acc-comment-focus]');
  await B.page.fill('#comment-' + ppId, 'Отлично! Заходи в игру');
  await submit(B.page, '#profile-post-' + ppId + ' .acc-comment-form button[type=submit]');
  check('wall: comment added', (await B.page.locator('#profile-post-' + ppId + ' .acc-comment').count()) === 1 && B.page.url().includes('#profile-comment-'));
  const cId = +(await B.page.getAttribute('#profile-post-' + ppId + ' .acc-comment', 'id')).replace('profile-comment-', '');
  await B.page.screenshot({ path: path.join(SHOTS, 'b-flow-wall.png'), fullPage: true });

  // A видит лайк и комментарий, лайкает комментарий
  await go(page, '/forum/member.php?id=' + roxId);
  await page.click('#profile-comment-' + cId + ' form[data-acc-like] button');
  await page.waitForSelector('[data-likes="comment-' + cId + '"]:not([hidden])');
  check('wall: like on comment', (await page.textContent('[data-likes="comment-' + cId + '"]')).includes('Вы'));
  const ownLike = await page.request.post(BASE + '/forum/member.php?id=' + roxId, { form: { _token: await token(page), action: 'like', type: 'post', cid: String(ppId) }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  check('wall: cannot like own post', ownLike.status() === 403, String(ownLike.status()));

  // ---------- Подписка и игнор ----------
  await go(page, '/forum/member.php?id=' + roxId);
  await submit(page, '.acc-profile-actions form:has(input[value=follow]) button');
  check('follow: button switches to unfollow', (await page.locator('.acc-profile-actions input[value=unfollow]').count()) === 1);
  await go(page, '/forum/account.php?tab=following');
  check('follow: listed in account', (await page.textContent('.acc-account-main')).includes('Roxas_Alexandro'));
  await go(page, '/forum/member.php?id=' + roxId);
  await submit(page, '.acc-profile-actions form:has(input[value=ignore]) button');
  check('ignore: comment from ignored user hidden', (await page.locator('#profile-comment-' + cId + ' details.acc-ignored').count()) === 1);
  await go(page, '/forum/member.php?id=1');
  check('ignore: no ignore button on staff profile', (await page.locator('.acc-profile-actions input[value=ignore]').count()) === 0);
  await go(page, '/forum/account.php?tab=ignored');
  await submit(page, 'form:has(input[value=unignore]) button');
  check('ignore: removed from list', (await flashText(page)).includes('убран'));
  await page.fill('.acc-inline-add input[name=username]', 'admin');
  await submit(page, '.acc-inline-add button');
  check('ignore: staff cannot be ignored', (await page.textContent('body')).includes('Администрацию проекта игнорировать нельзя'));

  // ---------- Удаление на стене ----------
  await go(B.page, '/forum/member.php?id=' + roxId);
  await submit(B.page, '#profile-comment-' + cId + ' form:has(input[value=comment_delete]) button');
  check('wall: comment deleted by owner', (await B.page.locator('#profile-comment-' + cId).count()) === 0);

  // ---------- Лента сообщений профилей ----------
  await go(page, '/forum/profile-posts.php');
  check('profile-posts: latest list shows post', (await page.textContent('.acc-wall-list')).includes('Как дела'));
  await go(page, '/forum/profile-posts.php?q=' + encodeURIComponent('дела'));
  check('profile-posts: search finds', (await page.locator('.acc-wall-post').count()) >= 1);
  await go(page, '/forum/profile-posts.php?q=' + encodeURIComponent('%_несуществующее_%'));
  check('profile-posts: LIKE wildcards escaped', (await page.textContent('body')).includes('Ничего не найдено'));

  // ---------- Личные сообщения ----------
  await sleep(10500);
  await go(page, '/forum/conversations.php?new=1&to=Roxas_Alexandro');
  check('conv: recipient prefilled', (await page.inputValue('#cv-to')) === 'Roxas_Alexandro');
  await page.fill('#cv-to', NAME);
  await page.fill('#cv-title', 'Тест');
  await page.fill('#cv-msg', 'Текст');
  await submit(page, 'form.form button[type=submit]');
  check('conv: cannot write to self', (await page.textContent('body')).includes('самому себе'));
  await page.fill('#cv-to', 'Nobody_Here_123, Roxas_Alexandro');
  await submit(page, 'form.form button[type=submit]');
  check('conv: unknown recipient', (await page.textContent('body')).includes('Не найден пользователь: Nobody_Here_123'));
  await page.fill('#cv-to', 'a1, a2, a3, a4, a5, a6');
  await submit(page, 'form.form button[type=submit]');
  check('conv: max 5 recipients', (await page.textContent('body')).includes('Не больше 5'));
  await page.fill('#cv-to', 'Roxas_Alexandro');
  await page.fill('#cv-title', 'Я');
  await submit(page, 'form.form button[type=submit]');
  check('conv: short title', (await page.textContent('body')).includes('от 3 до 100'));
  await page.fill('#cv-title', 'Встреча на сервере');
  await page.fill('#cv-msg', 'Привет! Давай встретимся у мэрии в [b]20:00[/b].');
  await submit(page, 'form.form button[type=submit]');
  check('conv: created', page.url().includes('conversations.php?id=') && (await page.textContent('.acc-conv-main')).includes('встретимся'), page.url());
  const convId = +page.url().match(/id=(\d+)/)[1];

  await go(B.page, '/forum/');
  const badge = await B.page.locator('a[href$="conversations.php"] .count-badge').textContent().catch(() => '');
  check('conv: unread badge for recipient', badge.trim() === '1', badge);
  await go(B.page, '/forum/conversations.php');
  check('conv: unread row in list', (await B.page.locator('.acc-conv-row.unread').count()) >= 1);
  await B.page.screenshot({ path: path.join(SHOTS, 'b-flow-conv-list.png') });
  await go(B.page, '/forum/conversations.php?id=' + convId);
  check('conv: new message marked', (await B.page.locator('.acc-msg.is-new').count()) === 1);
  await go(B.page, '/forum/');
  check('conv: badge cleared after reading', (await B.page.locator('a[href$="conversations.php"] .count-badge').count()) === 0);
  await go(B.page, '/forum/conversations.php?id=' + convId);
  await B.page.fill('.acc-reply textarea', 'Договорились, буду!');
  await submit(B.page, '.acc-reply button[type=submit]');
  check('conv: reply posted', (await B.page.locator('.acc-msg').count()) === 2 && B.page.url().includes('#msg-'));
  await go(page, '/forum/');
  check('conv: starter sees unread reply', (await page.locator('a[href$="conversations.php"] .count-badge').count()) === 1);
  await go(page, '/forum/conversations.php?id=' + convId);
  await page.fill('.acc-conv-side input[name=names]', 'Kuzya_Kabanov');
  await submit(page, '.acc-conv-side form:has(input[value=invite]) button');
  check('conv: invite participant', (await flashText(page)).includes('Kuzya_Kabanov') && (await page.locator('.acc-part').count()) === 3);
  await B.page.reload();
  check('conv: no invite form for non-starter', (await B.page.locator('input[value=invite]').count()) === 0);
  await page.screenshot({ path: path.join(SHOTS, 'b-flow-conv.png'), fullPage: true });

  const K = await newCtx();
  await login(K.page, 'Kuzya_Kabanov', 'test12345');
  await go(K.page, '/forum/conversations.php?id=' + convId);
  check('conv: invited user can read', (await K.page.locator('.acc-msg').count()) === 2);
  await submit(K.page, '.acc-conv-side form:has(input[value=leave]) button');
  check('conv: leave works', K.page.url().endsWith('/forum/conversations.php') && (await flashText(K.page)).includes('покинули'));
  const after = await go(K.page, '/forum/conversations.php?id=' + convId);
  check('conv: left user gets 404', after.status() === 404, String(after.status()));
  const C = await newCtx();
  await login(C.page, 'Martin_Line', 'test12345');
  const foreign = await go(C.page, '/forum/conversations.php?id=' + convId);
  check('conv: non-participant gets 404', foreign.status() === 404, String(foreign.status()));
  await go(page, '/forum/conversations.php?id=' + convId);
  check('conv: left participant marked', (await page.locator('.acc-part.is-left').count()) === 1);

  // ---------- Участники ----------
  await go(page, '/forum/members.php?q=rox');
  check('members: search', (await page.locator('.acc-mcard').count()) === 1 && (await page.textContent('.acc-grid')).includes('Roxas_Alexandro'));
  await go(page, '/forum/members.php?q=' + encodeURIComponent('_'));
  check('members: underscore is literal', (await page.locator('.acc-mcard').count()) >= 5);
  await go(page, '/forum/members.php?group=4');
  check('members: group filter incl. secondary', (await page.textContent('.acc-grid')).includes('Kuzya_Kabanov'));
  await go(page, '/forum/members.php?sort=name');
  const first = (await page.locator('.acc-mcard-name').first().textContent()).trim();
  check('members: sort by name', first.toLowerCase() === 'admin', first);

  console.log('\nJS errors:', JSON.stringify(errors));
  const failed = results.filter(r => r[0] === 'FAIL');
  console.log(`\n${results.length - failed.length}/${results.length} passed`);
  await browser.close();
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
