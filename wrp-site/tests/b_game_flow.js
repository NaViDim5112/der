// Кабинет и восстановление пароля с включённой игровой базой.
// Подготовка: php tests/b_game_seed.php | mysql --default-character-set=cp1251 wrp_game_test
//             sh tests/b_game_server.sh <копия> 8092
// Запуск:     BASE=http://127.0.0.1:8092 node tests/b_game_flow.js
const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8092';
const SHOTS = path.join(__dirname, 'screens');
const results = [];
const check = (n, c, x) => { results.push(c); console.log((c ? 'PASS ' : 'FAIL ') + n + (x ? ' :: ' + x : '')); };
const sql = (q) => execSync('mysql wrp -N', { input: q }).toString().trim();

(async () => {
  // свои тестовые пользователи
  const hash = execSync(`php -r 'echo password_hash("test12345", PASSWORD_DEFAULT);'`).toString();
  for (const n of ['GameTester_A', 'GameTester_B']) {
    sql(`INSERT IGNORE INTO users (username, email, password_hash, group_id, created_at) VALUES ('${n}', '${n.toLowerCase()}@test.local', '${hash}', 2, NOW())`);
    sql(`UPDATE users SET game_nick = NULL, password_hash = '${hash}' WHERE username = '${n}'`);
  }
  sql(`DELETE FROM rate_limits WHERE ip = '127.0.0.1' AND action IN ('game_link', 'lost_password')`);

  const browser = await chromium.launch();
  const mk = async (opts) => {
    const ctx = await browser.newContext(opts || { viewport: { width: 1400, height: 950 } });
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    return page;
  };
  const submit = async (page, sel) => Promise.all([page.waitForNavigation(), page.click(sel)]);
  const login = async (page, u, p) => {
    await page.goto(BASE + '/forum/login.php');
    await page.fill('#login', u);
    await page.fill('#password', p);
    await submit(page, 'form.login-form button[type=submit]');
  };
  const body = async (page) => page.textContent('body');

  const A = await mk();
  await login(A, 'GameTester_A', 'test12345');
  await A.goto(BASE + '/cabinet/');
  check('cabinet: link form shown when enabled', await A.isVisible('input[name=game_nick]'));
  await A.fill('#gm-nick', 'Иван Петров');
  await A.fill('#gm-pass', 'x');
  await submit(A, 'form.form button[type=submit]');
  check('link: invalid nick format', (await body(A)).includes('латиницей'));
  await A.fill('#gm-nick', 'John_Smith');
  await A.fill('#gm-pass', 'wrong-pass');
  await submit(A, 'form.form button[type=submit]');
  check('link: wrong game password', (await body(A)).includes('Неверный ник или пароль'));
  check('link: nick kept after error', (await A.inputValue('#gm-nick')) === 'John_Smith');
  await A.fill('#gm-pass', 'gamepass1');
  await submit(A, 'form.form button[type=submit]');
  check('link: success', (await body(A)).includes('Игровой аккаунт John_Smith привязан'));
  const stats = await A.textContent('.acc-game-stats');
  check('cabinet: stats from game DB', stats.includes('Уровень') && stats.includes('12') && stats.includes('150 000') && stats.includes('2 500 000'), stats.replace(/\s+/g, ' '));
  check('cabinet: cp1251 text shown in UTF-8', stats.includes('Лос-Сантос'));
  await A.screenshot({ path: path.join(SHOTS, 'b-cabinet-linked.png'), fullPage: true });

  const B = await mk();
  await login(B, 'GameTester_B', 'test12345');
  await B.goto(BASE + '/cabinet/');
  await B.fill('#gm-nick', 'john_smith');
  await B.fill('#gm-pass', 'gamepass1');
  await submit(B, 'form.form button[type=submit]');
  check('link: nick already linked to another user', (await body(B)).includes('уже привязан к другому пользователю'));
  await B.fill('#gm-nick', 'ivan_petrov');
  await B.fill('#gm-pass', 'пароль123');
  await submit(B, 'form.form button[type=submit]');
  check('link: cyrillic password + canonical nick', (await body(B)).includes('Игровой аккаунт Ivan_Petrov привязан'));
  check('db: canonical nick stored', sql("SELECT game_nick FROM users WHERE username = 'GameTester_B'") === 'Ivan_Petrov');
  await B.goto(BASE + '/forum/member.php?id=' + sql("SELECT id FROM users WHERE username = 'GameTester_B'") + '&tab=about');
  check('profile: game nick shown', (await B.textContent('.acc-about-side')).includes('Ivan_Petrov'));

  // ограничение попыток (5 за 15 минут с IP): уже было 4 проверки по базе
  await B.goto(BASE + '/cabinet/');
  await submit(B, 'form[data-confirm] button[type=submit]');
  check('unlink works', await B.isVisible('input[name=game_nick]'));
  for (let i = 0; i < 2; i++) {
    await B.fill('#gm-nick', 'Old_Player');
    await B.fill('#gm-pass', 'bad' + i);
    await submit(B, 'form.form button[type=submit]');
  }
  check('link: rate limit after 5 attempts', (await body(B)).includes('Слишком много попыток'));
  sql(`DELETE FROM rate_limits WHERE ip = '127.0.0.1' AND action = 'game_link'`);
  await B.fill('#gm-nick', 'Old_Player');
  await B.fill('#gm-pass', 'oldpass1');
  await submit(B, 'form.form button[type=submit]');
  check('link: old SHA256+salt account', (await body(B)).includes('Игровой аккаунт Old_Player привязан'));

  // Восстановление пароля через игровой аккаунт
  const G = await mk({ viewport: { width: 1400, height: 950 } });
  await G.goto(BASE + '/forum/lost-password.php');
  check('lost-password: game form shown when enabled', await G.isVisible('#lp-nick'));
  await G.fill('#lp-username', 'GameTester_A');
  await G.fill('#lp-nick', 'Old_Player');
  await G.fill('#lp-gpass', 'oldpass1');
  await G.fill('#lp-pass', 'NewForumPass1');
  await G.fill('#lp-pass2', 'NewForumPass1');
  await submit(G, 'form.form button[type=submit]');
  check('lost-password: nick of another user rejected', (await body(G)).includes('Данные не совпадают'));
  await G.fill('#lp-nick', 'john_smith');
  await G.fill('#lp-gpass', 'gamepass1');
  await G.fill('#lp-pass', 'NewForumPass1');
  await G.fill('#lp-pass2', 'NewForumPass1');
  await G.screenshot({ path: path.join(SHOTS, 'b-lost-password-game.png'), fullPage: true });
  await submit(G, 'form.form button[type=submit]');
  check('lost-password: success redirects to login', G.url().includes('login.php') && (await body(G)).includes('Пароль изменён'));
  await A.goto(BASE + '/forum/');
  check('lost-password: old sessions logged out', (await A.locator('.user-pill').count()) === 0);
  await login(G, 'GameTester_A', 'NewForumPass1');
  check('lost-password: new password works', (await G.locator('.user-pill').count()) === 1);

  const M = await mk({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await login(M, 'GameTester_A', 'NewForumPass1');
  await M.goto(BASE + '/cabinet/');
  await M.screenshot({ path: path.join(SHOTS, 'b-m-cabinet-linked.png'), fullPage: true });
  // уборка
  await G.goto(BASE + '/cabinet/');
  await submit(G, 'form[data-confirm] button[type=submit]');
  check('unlink A', await G.isVisible('input[name=game_nick]'));
  sql(`UPDATE users SET game_nick = NULL WHERE username IN ('GameTester_A', 'GameTester_B')`);
  sql(`DELETE FROM rate_limits WHERE ip = '127.0.0.1' AND action IN ('game_link', 'lost_password')`);

  await browser.close();
  const failed = results.filter(r => !r).length;
  console.log(`\n${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
