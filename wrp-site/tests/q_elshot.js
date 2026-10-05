// Скриншот одного элемента: node tests/q_elshot.js out.png /path "css selector" [--login u:p] [--light] [--width N]
const { chromium } = require('playwright');
(async () => {
  const args = process.argv.slice(2);
  const opt = (n, d) => { const i = args.indexOf(n); return i >= 0 ? args[i + 1] : d; };
  const base = process.env.BASE || 'http://127.0.0.1:8080';
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: +opt('--width', 1600), height: +opt('--height', 1000) }, deviceScaleFactor: 2 });
  if (args.includes('--light')) await ctx.addCookies([{ name: 'wrp_theme', value: 'light', url: base }]);
  await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
  const page = await ctx.newPage();
  const login = opt('--login');
  if (login) {
    const [u, p] = login.split(':');
    await page.goto(base + '/forum/login.php');
    await page.fill('form.login-form input[name=login]', u);
    await page.fill('form.login-form input[name=password]', p);
    await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  }
  await page.goto(base + args[1], { waitUntil: 'load' });
  if (opt('--click')) { await page.click(opt('--click')); await page.waitForTimeout(400); }
  const el = await page.$(args[2]);
  if (!el) { console.log('no element'); } else { await el.screenshot({ path: args[0] }); console.log('ok'); }
  await browser.close();
})();
