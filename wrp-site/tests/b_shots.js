// Пачка скриншотов страниц аккаунтов: BASE=... node tests/b_shots.js [фильтр]
// Скриншоты: tests/screens/b-*.png
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:8082';
const only = process.argv[2] || '';

const shots = [
  // [имя, путь, логин, опции]
  ['account-profile', '/forum/account.php', 'Roxas_Alexandro:test12345', { full: true }],
  ['account-signature', '/forum/account.php?tab=signature', 'Roxas_Alexandro:test12345', {}],
  ['account-security', '/forum/account.php?tab=security', 'Roxas_Alexandro:test12345', { full: true }],
  ['account-following', '/forum/account.php?tab=following', 'Roxas_Alexandro:test12345', {}],
  ['account-ignored', '/forum/account.php?tab=ignored', 'Roxas_Alexandro:test12345', {}],
  ['member-roxas', '/forum/member.php?id=3', 'Kuzya_Kabanov:test12345', { full: true }],
  ['member-activity', '/forum/member.php?id=3&tab=activity', null, { full: true }],
  ['member-posts', '/forum/member.php?id=1&tab=posts', null, { full: true }],
  ['member-threads', '/forum/member.php?id=1&tab=threads', null, {}],
  ['member-about', '/forum/member.php?id=2&tab=about', null, {}],
  ['online', '/forum/online.php', 'admin:admin12345', {}],
  ['members2', '/forum/members.php', null, { full: true }],
  ['staff2', '/forum/staff.php', 'Roxas_Alexandro:test12345', {}],
  ['profile-posts', '/forum/profile-posts.php', 'Kuzya_Kabanov:test12345', { full: true }],
  ['profile-posts-search', '/forum/profile-posts.php?search=1', null, {}],
  ['lost-password', '/forum/lost-password.php', null, {}],
  ['conv-list', '/forum/conversations.php', 'Roxas_Alexandro:test12345', {}],
  ['conv-new', '/forum/conversations.php?new=1&to=admin', 'Roxas_Alexandro:test12345', {}],
  ['cabinet-user', '/cabinet/', 'Roxas_Alexandro:test12345', {}],
  // мобильные
  ['m-register', '/forum/register.php', null, { mobile: true, full: true }],
  ['m-member', '/forum/member.php?id=3', 'Kuzya_Kabanov:test12345', { mobile: true, full: true }],
  ['m-account', '/forum/account.php', 'Roxas_Alexandro:test12345', { mobile: true, full: true }],
  ['m-conv-list', '/forum/conversations.php', 'Roxas_Alexandro:test12345', { mobile: true }],
  ['m-conv', '/forum/conversations.php?id=1', 'Roxas_Alexandro:test12345', { mobile: true, full: true }],
  ['m-members', '/forum/members.php', null, { mobile: true, full: true }],
  ['m-cabinet', '/cabinet/', 'admin:admin12345', { mobile: true, full: true }],
  ['m-staff', '/forum/staff.php', null, { mobile: true }],
  // светлая тема
  ['l-member', '/forum/member.php?id=3', 'Kuzya_Kabanov:test12345', { light: true, full: true }],
  ['l-account', '/forum/account.php', 'Roxas_Alexandro:test12345', { light: true }],
  ['l-conv-list', '/forum/conversations.php', 'Roxas_Alexandro:test12345', { light: true }],
  ['l-cabinet', '/cabinet/', 'admin:admin12345', { light: true }],
  ['l-register', '/forum/register.php', null, { light: true }],
];

(async () => {
  const browser = await chromium.launch();
  const sessions = {};
  for (const [name, p, login, o] of shots) {
    if (only && !name.includes(only)) continue;
    const key = (login || 'guest') + (o.mobile ? ':m' : '') + (o.light ? ':l' : '');
    if (!sessions[key]) {
      const ctx = await browser.newContext(o.mobile ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true } : { viewport: { width: 1400, height: 950 } });
      if (o.light) await ctx.addCookies([{ name: 'wrp_theme', value: 'light', url: BASE }]);
      const page = await ctx.newPage();
      page.on('pageerror', e => console.log('  pageerror', e.message));
      if (login) {
        const [u, pw] = login.split(':');
        await page.goto(BASE + '/forum/login.php');
        await page.fill('#login', u);
        await page.fill('#password', pw);
        await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
      }
      sessions[key] = page;
    }
    const page = sessions[key];
    const resp = await page.goto(BASE + p, { waitUntil: 'networkidle' }).catch(e => null);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    await page.screenshot({ path: path.join(__dirname, 'screens', 'b-' + name + '.png'), fullPage: !!o.full });
    console.log(name, resp && resp.status(), overflow > 0 ? 'HORIZONTAL OVERFLOW ' + overflow + 'px' : '');
  }
  await browser.close();
})();
