// Скриншоты частей страницы: node tests/c_shot_scroll.js out-prefix /path selector1 selector2 ... [--login u:p] [--light] [--width N]
const { chromium } = require('playwright');
(async () => {
  const args = process.argv.slice(2);
  const opt = (n, d) => { const i = args.indexOf(n); return i >= 0 ? args[i + 1] : d; };
  const flag = (n) => args.includes(n);
  const out = args[0], path = args[1];
  const sels = args.slice(2).filter((a, i, arr) => !a.startsWith('--') && !(i > 0 && arr[i - 1].startsWith('--') && arr[i - 1] !== '--light'));
  const base = process.env.BASE || 'http://127.0.0.1:8083';
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: +opt('--width', 1600), height: +opt('--height', 1000) } });
  if (flag('--light')) await ctx.addCookies([{ name: 'wrp_theme', value: 'light', url: base }]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  const login = opt('--login', 'admin:admin12345');
  const [u, p] = login.split(':');
  await page.goto(base + '/forum/login.php');
  await page.fill('input[name=login]', u);
  await page.fill('input[name=password]', p);
  await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  await page.goto(base + path, { waitUntil: 'networkidle' });
  let i = 0;
  for (const s of sels) {
    const el = await page.$(s);
    if (!el) { console.log('no element', s); continue; }
    await el.screenshot({ path: out + '-' + (i++) + '.png' });
  }
  console.log('errors', JSON.stringify(errors.filter(e => !/fonts.googleapis|ERR_TUNNEL|net::ERR/.test(e))));
  await browser.close();
})();
