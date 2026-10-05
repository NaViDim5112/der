const { chromium } = require('playwright');
(async () => {
  const base = process.env.BASE || 'http://127.0.0.1:8080';
  const b = await chromium.launch();
  const p = await (await b.newContext({ viewport: { width: 1600, height: 900 } })).newPage();
  await p.goto(base + '/forum/login.php');
  await p.fill('input[name=login]', 'admin'); await p.fill('input[name=password]', 'admin12345');
  await Promise.all([p.waitForNavigation(), p.click('form.login-form button[type=submit]')]);
  await p.click('.user-pill');
  await p.screenshot({ path: 'tests/screens/menu-open.png' });
  await b.close();
})();
