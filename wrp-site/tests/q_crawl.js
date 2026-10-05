// Обход всех внутренних ссылок под разными ролями (BFS): статусы, ошибки JS, PHP-предупреждения, переполнение на 390 px.
// Запуск: BASE=http://127.0.0.1:8086 NODE_PATH=/opt/node22/lib/node_modules node tests/q_crawl.js [guest,roxas,diego,admin] [лимит]
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const ROLES = {
  guest: null,
  roxas: 'Roxas_Alexandro:test12345',
  diego: 'Diego_Bacardi:test12345',
  admin: 'admin:admin12345',
  ricardo: 'Ricardo_Calump:test12345',
  martin: 'Martin_Line:test12345',
};
const roles = (process.argv[2] || 'guest,roxas,diego,admin').split(',');
const LIMIT = +(process.argv[3] || 400);
const PER_PATTERN = +(process.env.PER_PATTERN || 6);
const SEEDS = ['/', '/start.php', '/partners.php', '/wiki/', '/forum/', '/cabinet/', '/admin/', '/forum/members.php', '/forum/staff.php', '/forum/online.php',
  '/forum/search.php', '/forum/alerts.php', '/forum/watched.php', '/forum/conversations.php', '/forum/account.php', '/forum/profile-posts.php'];
const SKIP = [/\/logout\.php/, /\/mark-read\.php/, /\/assets\//, /\/uploads\//, /\/install\//, /\/preview\.php/, /action=(delete|undelete|react|quote|unwatch|watch|follow|unfollow|ignore|unignore|leave)/];
const PHP_RE = /(<b>)?(Warning|Notice|Deprecated|Fatal error|Parse error)(<\/b>)?: .{0,200}? in (<b>)?\/[^\s<]+\.php/;

function pattern(u) {
  const x = new URL(u);
  const keys = [];
  for (const [k, v] of x.searchParams) keys.push(k + '=' + (/^\d+$/.test(v) ? 'N' : (k === 'q' || k === 'user' || k === 'return' ? '*' : v)));
  keys.sort();
  return x.pathname + '?' + keys.join('&');
}

function norm(href) {
  try {
    const x = new URL(href, BASE);
    if (x.origin !== new URL(BASE).origin) return null;
    x.hash = '';
    return x.toString();
  } catch (e) { return null; }
}

async function login(page, cred) {
  const [u, p] = cred.split(':');
  await page.goto(BASE + '/forum/login.php');
  await page.fill('form.login-form input[name=login]', u);
  await page.fill('form.login-form input[name=password]', p);
  await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
}

async function crawlRole(browser, role) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.dismiss());
  let cur = null;
  const problems = [];
  page.on('pageerror', (e) => problems.push({ url: cur, kind: 'pageerror', msg: e.message }));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource: net::ERR_FAILED/.test(m.text())) problems.push({ url: cur, kind: 'console', msg: m.text() });
  });
  page.on('response', (r) => {
    const u = r.url();
    if (u.startsWith(BASE) && r.status() >= 400 && r.request().resourceType() !== 'document') problems.push({ url: cur, kind: 'resource ' + r.status(), msg: u });
  });
  if (ROLES[role]) await login(page, ROLES[role]);

  const seen = new Set();
  const patCount = {};
  const queue = SEEDS.map((s) => ({ url: BASE + s, from: 'seed' }));
  const visited = [];
  const statuses = {};
  while (queue.length && visited.length < LIMIT) {
    const { url, from } = queue.shift();
    if (seen.has(url)) continue;
    seen.add(url);
    if (SKIP.some((r) => r.test(url))) continue;
    const pat = pattern(url);
    patCount[pat] = (patCount[pat] || 0) + 1;
    if (patCount[pat] > PER_PATTERN) continue;
    cur = url;
    let status = 0;
    let html = '';
    try {
      const resp = await page.goto(url, { waitUntil: 'load', timeout: 20000 });
      status = resp ? resp.status() : 0;
      html = await page.content();
    } catch (e) {
      problems.push({ url, kind: 'goto', msg: String(e).slice(0, 200) });
    }
    const finalUrl = page.url();
    visited.push({ url, status, from, finalUrl });
    statuses[status] = (statuses[status] || 0) + 1;
    if (status >= 400 && !(status === 403 && role === 'guest') && !(status === 403 && /\/admin\//.test(url) && role !== 'admin')) {
      problems.push({ url, kind: 'status ' + status, msg: 'from ' + from + ' -> ' + (await page.title().catch(() => '')) });
    }
    const m = html.match(PHP_RE);
    if (m) problems.push({ url, kind: 'php', msg: m[0].replace(/<[^>]+>/g, '') });
    if (/Stack trace:|Uncaught |PDOException|SQLSTATE/.test(html)) problems.push({ url, kind: 'exception', msg: (html.match(/(SQLSTATE[^<]{0,200}|Uncaught [^<]{0,200})/) || [''])[0] });
    if (/—/.test(await page.evaluate(() => document.body ? document.body.innerText : '').catch(() => ''))) problems.push({ url, kind: 'mdash', msg: 'long dash in text' });
    const links = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href'))).catch(() => []);
    for (const h of links) {
      if (!h || h.startsWith('#') || h.startsWith('javascript:') || h.startsWith('mailto:')) continue;
      const n = norm(new URL(h, finalUrl).toString());
      if (n && !seen.has(n)) queue.push({ url: n, from: url });
    }
  }
  await ctx.close();
  return { role, visited, statuses, problems, patCount };
}

async function overflowCheck(browser, role, urls) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctx.route(/^(?!http:\/\/127\.0\.0\.1)/, (r) => r.abort());
  const page = await ctx.newPage();
  if (ROLES[role]) await login(page, ROLES[role]);
  const out = [];
  for (const u of urls) {
    try {
      await page.goto(u, { waitUntil: 'load', timeout: 20000 });
      const r = await page.evaluate(() => {
        const w = document.documentElement.clientWidth;
        const sw = Math.max(document.documentElement.scrollWidth, document.body.scrollWidth);
        let culprit = '';
        if (sw > w + 1) {
          const els = Array.from(document.querySelectorAll('body *'));
          for (const el of els) {
            const b = el.getBoundingClientRect();
            if (b.right > w + 1 && b.width > 0 && getComputedStyle(el).position !== 'fixed') {
              let p = el.parentElement; let clipped = false;
              while (p && p !== document.body) { const s = getComputedStyle(p); if (/(auto|hidden|scroll|clip)/.test(s.overflowX)) { clipped = true; break; } p = p.parentElement; }
              if (!clipped) { culprit = el.tagName.toLowerCase() + '.' + String(el.className).split(' ').join('.') + ' right=' + Math.round(b.right); break; }
            }
          }
        }
        return { w, sw, culprit };
      });
      if (r.sw > r.w + 1) out.push({ url: u, ...r });
    } catch (e) { out.push({ url: u, err: String(e).slice(0, 100) }); }
  }
  await ctx.close();
  return out;
}

(async () => {
  const browser = await chromium.launch();
  const all = {};
  for (const role of roles) {
    const t = Date.now();
    const r = await crawlRole(browser, role);
    all[role] = r;
    console.log(`\n=== ${role}: visited ${r.visited.length} in ${Math.round((Date.now() - t) / 1000)}s, statuses ${JSON.stringify(r.statuses)}`);
    const uniq = {};
    for (const p of r.problems) { const k = p.kind + ' ' + p.msg; if (!uniq[k]) uniq[k] = []; uniq[k].push(p.url); }
    for (const k of Object.keys(uniq)) console.log('  ' + k + '\n     at ' + uniq[k].slice(0, 3).join('\n     at ') + (uniq[k].length > 3 ? ' (+' + (uniq[k].length - 3) + ')' : ''));
    if (process.env.OVERFLOW !== '0') {
      const sample = []; const pc = {};
      for (const v of r.visited) { if (v.status !== 200) continue; const p = pattern(v.url); pc[p] = (pc[p] || 0) + 1; if (pc[p] <= 1) sample.push(v.url); }
      const of = await overflowCheck(browser, role, sample);
      console.log(`  overflow@390: checked ${sample.length}, overflowing ${of.length}`);
      for (const o of of) console.log('    ' + o.url + ' ' + JSON.stringify(o));
    }
  }
  require('fs').writeFileSync(__dirname + '/screens/q_crawl.json', JSON.stringify(all, null, 1));
  await browser.close();
})();
