// Контраст текста в светлой (или тёмной) теме: ищет надписи с контрастом ниже 3:1 к фону.
// BASE=http://127.0.0.1:8086 NODE_PATH=/opt/node22/lib/node_modules node tests/q_contrast.js [light|dark]
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const theme = process.argv[2] || 'light';
const PAGES = ['/forum/', '/forum/forum.php?id=52', '/forum/thread.php?id=1', '/forum/thread.php?id=2', '/forum/member.php?id=3', '/forum/member.php?id=3&tab=activity',
  '/forum/members.php', '/forum/online.php', '/forum/staff.php', '/forum/profile-posts.php', '/forum/find.php?type=new', '/forum/search.php?user=Roxas_Alexandro',
  '/forum/alerts.php', '/forum/conversations.php', '/forum/account.php', '/forum/account.php?tab=security', '/forum/new-thread.php?node=52', '/forum/new-thread.php?node=81',
  '/cabinet/', '/wiki/', '/wiki/category.php?slug=start', '/wiki/article.php?slug=world-bank', '/admin/', '/admin/settings.php', '/admin/nodes.php', '/admin/nodes.php?edit=52',
  '/admin/prefixes.php', '/admin/groups.php', '/admin/groups.php?edit=6', '/admin/users.php', '/admin/users.php?edit=3', '/admin/nav.php', '/admin/wiki.php', '/admin/modlog.php', '/forum/watched.php'];
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.addCookies([{ name: 'wrp_theme', value: theme, url: BASE }]);
  await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
  const p = await ctx.newPage();
  await p.goto(BASE + '/forum/login.php');
  await p.fill('form.login-form input[name=login]', 'admin'); await p.fill('form.login-form input[name=password]', 'admin12345');
  await Promise.all([p.waitForNavigation(), p.click('form.login-form button[type=submit]')]);
  for (const u of PAGES) {
    await p.goto(BASE + u);
    const bad = await p.evaluate(() => {
      const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: v[0], g: v[1], b: v[2], a: v[3] === undefined ? 1 : v[3] }; };
      const lum = (c) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
      const bgOf = (el) => {
        let layers = [];
        for (let e = el; e; e = e.parentElement) {
          const s = getComputedStyle(e);
          if (s.backgroundImage && s.backgroundImage !== 'none' && !/gradient\(.*rgba?\([^)]*,\s*0\)/.test(s.backgroundImage)) return null; // картинка/градиент - пропускаем
          const c = parse(s.backgroundColor);
          if (c && c.a > 0) { layers.push(c); if (c.a >= 0.99) break; }
        }
        let base = { r: 255, g: 255, b: 255 };
        if (document.documentElement.getAttribute('data-theme') !== 'light') base = { r: 13, g: 11, b: 20 };
        for (let i = layers.length - 1; i >= 0; i--) { const c = layers[i]; base = { r: c.r * c.a + base.r * (1 - c.a), g: c.g * c.a + base.g * (1 - c.a), b: c.b * c.a + base.b * (1 - c.a) }; }
        return base;
      };
      const out = [];
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      const seen = new Set();
      while (walker.nextNode()) {
        const t = walker.currentNode; const el = t.parentElement;
        if (!el || seen.has(el) || !t.textContent.trim()) continue;
        seen.add(el);
        if (el.closest('.dropdown-menu, dialog, template, [hidden], .sidebar-backdrop, option, script, style, .bb, .acc-code')) continue;
        const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0) continue;
        const s = getComputedStyle(el); if (s.visibility === 'hidden' || +s.opacity === 0) continue;
        let fg = parse(s.color); if (!fg) continue;
        const bg = bgOf(el); if (!bg) continue;
        let op = 1; for (let e = el; e; e = e.parentElement) op *= +getComputedStyle(e).opacity;
        let filt = 1; for (let e = el; e; e = e.parentElement) { const m = getComputedStyle(e).filter.match(/brightness\(([\d.]+)\)/); if (m) filt *= +m[1]; }
        fg = { r: Math.min(255, fg.r * filt), g: Math.min(255, fg.g * filt), b: Math.min(255, fg.b * filt) };
        const a = (fg.a === undefined ? 1 : fg.a) * op;
        const mix = { r: fg.r * a + bg.r * (1 - a), g: fg.g * a + bg.g * (1 - a), b: fg.b * a + bg.b * (1 - a) };
        const l1 = lum(mix), l2 = lum(bg);
        const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        const disabled = el.closest('[disabled], .is-disabled, .page-btn.disabled');
        if (ratio < 3 && !disabled) out.push(Math.round(ratio * 10) / 10 + ' ' + el.tagName.toLowerCase() + '.' + String(el.className).split(' ').filter(Boolean).join('.') + ' "' + t.textContent.trim().slice(0, 30) + '"');
      }
      return [...new Set(out)].slice(0, 12);
    });
    if (bad.length) console.log(u + '\n   ' + bad.join('\n   '));
  }
  await b.close();
})();
