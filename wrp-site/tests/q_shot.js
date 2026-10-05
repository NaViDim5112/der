// Скриншот с прокруткой (для анимаций появления): node tests/q_shot.js out.png /forum/ [--login admin:admin12345] [--width 1600] [--height 1000] [--full] [--light]
const { chromium } = require('playwright');
(async () => {
  const args = process.argv.slice(2);
  const out = args[0];
  const path = args[1] || '/';
  const opt = (name, def) => { const i = args.indexOf(name); return i >= 0 ? args[i + 1] : def; };
  const flag = (name) => args.includes(name);
  const base = process.env.BASE || 'http://127.0.0.1:8080';
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: +opt('--width', 1600), height: +opt('--height', 1000) } });
  if (flag('--light')) await ctx.addCookies([{ name: 'wrp_theme', value: 'light', url: base }]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  const login = opt('--login');
  if (login) {
    const [u, p] = login.split(':');
    await page.goto(base + '/forum/login.php');
    await page.fill('input[name=login]', u);
    await page.fill('input[name=password]', p);
    await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit], main form button[type=submit]')]);
  }
  const resp = await page.goto(base + path, { waitUntil: 'networkidle' }).catch(e => { errors.push(String(e)); return null; });
  await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo({ top: y, behavior: 'instant' }); await new Promise(r => setTimeout(r, 60)); } window.scrollTo({ top: 0, behavior: 'instant' }); await new Promise(r => setTimeout(r, 1800)); });
  await page.screenshot({ path: out, fullPage: flag('--full') });
  console.log('status', resp && resp.status(), 'errors', JSON.stringify(errors.filter(e => !/fonts.googleapis|ERR_TUNNEL|net::ERR/.test(e))));
  await browser.close();
})();
