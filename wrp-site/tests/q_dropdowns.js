// Выпадающие меню на телефоне (390 px) не должны вылезать за экран.
// BASE=http://127.0.0.1:8086 NODE_PATH=/opt/node22/lib/node_modules node tests/q_dropdowns.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
(async () => {
  const b = await chromium.launch();
  let failed = 0;
  for (const w of [390, 360, 768]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 800 }, isMobile: w < 500, hasTouch: w < 500 });
    await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
    const p = await ctx.newPage();
    await p.goto(BASE + '/forum/login.php');
    await p.fill('form.login-form input[name=login]', 'Diego_Bacardi'); await p.fill('form.login-form input[name=password]', 'test12345');
    await Promise.all([p.waitForNavigation(), p.click('form.login-form button[type=submit]')]);
    const cases = [['/forum/', '.user-pill'], ['/forum/thread.php?id=1', '.mod-dd > button'], ['/forum/member.php?id=3', '.dropdown:has-text("Найти") > button'], ['/forum/forum.php?id=52', '.dropdown > [data-dropdown]']];
    for (const [u, sel] of cases) {
      await p.goto(BASE + u);
      const btn = p.locator(sel).first();
      if (!(await btn.count())) { console.log(w, u, 'нет кнопки', sel); continue; }
      await btn.click();
      await p.waitForTimeout(250);
      const r = await p.evaluate(() => { const m = document.querySelector('.dropdown.open .dropdown-menu'); if (!m) return null; const b = m.getBoundingClientRect(); return { l: Math.round(b.left), r: Math.round(b.right), vw: document.documentElement.clientWidth }; });
      const ok = r && r.l >= 0 && r.r <= r.vw;
      if (!ok) failed++;
      console.log((ok ? 'ok   ' : 'FAIL ') + w + ' ' + u + ' ' + sel + ' ' + JSON.stringify(r));
    }
    await ctx.close();
  }
  console.log(failed ? 'FAILED ' + failed : 'ALL OK');
  await b.close();
})();
