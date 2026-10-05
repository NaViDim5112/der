// Скриншоты публичного сайта с ожиданием анимаций и прокруткой (для блоков .reveal).
// BASE=http://127.0.0.1:8084 NODE_PATH=... node tests/d_shot.js out.png /path [--width 1600] [--height 1000]
//   [--full] [--light] [--login user:pass] [--wait 1200] [--click "css"] [--y 900]
const { chromium } = require('playwright');
(async () => {
  const args = process.argv.slice(2);
  const out = args[0];
  const path = args[1] || '/';
  const opt = (name, def) => { const i = args.indexOf(name); return i >= 0 ? args[i + 1] : def; };
  const flag = (name) => args.includes(name);
  const base = process.env.BASE || 'http://127.0.0.1:8084';
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
  const resp = await page.goto(base + path, { waitUntil: 'load' }).catch(e => { errors.push(String(e)); return null; });
  await page.waitForTimeout(300);
  if (flag('--full')) {
    // прокрутка до конца, чтобы сработали анимации появления
    const h = await page.evaluate(() => document.body.scrollHeight);
    for (let y = 0; y < h; y += 400) {
      await page.evaluate(v => window.scrollTo({ top: v, behavior: 'instant' }), y);
      await page.waitForTimeout(60);
    }
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
  }
  const y = opt('--y');
  if (y) await page.evaluate(v => window.scrollTo({ top: +v, behavior: 'instant' }), y);
  const click = opt('--click');
  if (click) await page.click(click);
  await page.waitForTimeout(+opt('--wait', 1200));
  await page.screenshot({ path: out, fullPage: flag('--full') });
  console.log('status', resp && resp.status(), 'errors', JSON.stringify(errors.filter(e => !/fonts\.g|ERR_TUNNEL|net::ERR|youtube/.test(e))));
  await browser.close();
})();
