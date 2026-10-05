// Проверка установщика на чистой копии проекта.
// BASE=http://127.0.0.1:8094 DB_USER=... DB_PASS=... DB_NAME=wrp_install_test1 NODE_PATH=... node tests/d_install.js out_dir [stage]
//   stage: full (по умолчанию) | nonempty (повторная установка в ту же базу после удаления config.php)
const { chromium } = require('playwright');
const base = process.env.BASE || 'http://127.0.0.1:8094';
const outDir = process.argv[2] || '.';
const stage = process.argv[3] || 'full';
const db = {
  host: process.env.DB_HOST || '127.0.0.1',
  port: process.env.DB_PORT || '3306',
  name: process.env.DB_NAME || 'wrp_install_test1',
  user: process.env.DB_USER || 'wrp_inst',
  pass: process.env.DB_PASS || '',
};
const ok = (cond, msg) => { console.log((cond ? 'OK   ' : 'FAIL ') + msg); if (!cond) process.exitCode = 1; };

async function fill(page, o) {
  for (const [k, v] of Object.entries(o)) {
    if (k === 'db_create') {
      const box = page.locator('input[name=db_create]');
      if ((await box.isChecked()) !== v) await box.click();
    } else {
      await page.fill(`[name=${k}]`, v);
    }
  }
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const good = {
    db_host: db.host, db_port: db.port, db_name: db.name, db_user: db.user, db_pass: db.pass, db_create: true,
    site_name: 'World Role Play', site_subtitle: 'Los Santos', server_ip: '127.0.0.1', server_port: '7777', base_path: '',
    admin_name: 'Dmitry_Admin', admin_email: 'owner@wrp.local', admin_pass: 'secret-pass-1', admin_pass2: 'secret-pass-1',
  };
  const submit = async () => { await Promise.all([page.waitForNavigation(), page.click('.install-submit button')]); };

  if (stage === 'nonempty') {
    await page.goto(base + '/install/');
    await fill(page, good);
    await submit();
    const t = await page.textContent('body');
    ok(/База не пустая/.test(t), 'повторная установка в непустую базу отклонена');
    await page.screenshot({ path: outDir + '/d-install-nonempty.png', fullPage: true });
    await browser.close();
    return;
  }

  // 1. Главная до установки ведёт на установщик
  const r = await page.goto(base + '/');
  ok(/\/install\/$/.test(page.url()), 'главная перенаправляет на /install/ (' + page.url() + ')');
  ok(r.status() === 200, 'страница установщика 200');
  const def = await page.inputValue('[name=db_host]');
  ok(def === '127.0.1.19', 'адрес базы по умолчанию 127.0.1.19');
  ok((await page.inputValue('[name=db_name]')) === 'wrp_site', 'имя базы по умолчанию wrp_site');
  ok((await page.inputValue('[name=base_path]')) === '', 'base_path определён как пустой');
  await page.screenshot({ path: outDir + '/d-install-form.png', fullPage: true });

  // 2. Ошибки полей
  await fill(page, Object.assign({}, good, { admin_name: 'x', admin_email: 'bad', admin_pass: '123', admin_pass2: '123', db_name: 'bad-name!' }));
  await submit();
  let html = await page.content();
  ok(/Исправьте поля/.test(html), 'общая ошибка валидации');
  ok((await page.locator('.field-error').count()) >= 4, 'ошибки у полей: ' + (await page.locator('.field-error').count()));
  ok((await page.inputValue('[name=admin_email]')) === 'bad', 'значения формы сохранены');
  ok((await page.inputValue('[name=db_pass]')) === '', 'пароль базы не выводится обратно');
  await page.screenshot({ path: outDir + '/d-install-errors.png', fullPage: true });

  // 3. Неверный пароль MySQL
  await fill(page, Object.assign({}, good, { db_pass: 'wrong-password' }));
  await submit();
  html = await page.content();
  ok(/неверный пользователь или пароль/.test(html), 'понятная ошибка при неверном пароле');
  ok(!html.includes('wrong-password'), 'пароль не попал в страницу');

  // 4. Базы нет и галочка снята
  await fill(page, Object.assign({}, good, { db_create: false }));
  await submit();
  html = await page.content();
  ok(/Создайте её или отметьте/.test(html), 'база не создаётся без галочки');

  // 5. Успешная установка
  await fill(page, good);
  await submit();
  html = await page.content();
  ok(/Сайт установлен/.test(html), 'установка завершена');
  ok(/public\/install/.test(html), 'есть предупреждение удалить public/install');
  ok(!html.includes(db.pass) || db.pass === '', 'пароль базы не выводится');
  await page.screenshot({ path: outDir + '/d-install-done.png', fullPage: true });

  // 6. Повторный заход - отказ
  await page.goto(base + '/install/');
  html = await page.content();
  ok(/Сайт уже установлен/.test(html), 'повторная установка запрещена');
  await page.screenshot({ path: outDir + '/d-install-again.png', fullPage: true });

  // 7. Сайт работает, вход новым администратором
  const home = await page.goto(base + '/');
  ok(home.status() === 200 && /WORLD ROLE PLAY/.test(await page.content()), 'главная открывается после установки');
  await page.goto(base + '/forum/login.php');
  await page.fill('input[name=login]', good.admin_name);
  await page.fill('input[name=password]', good.admin_pass);
  await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  html = await page.content();
  ok(html.includes(good.admin_name), 'вход под новым администратором');
  const wiki = await page.goto(base + '/wiki/');
  ok(wiki.status() === 200 && /Чем помочь/.test(await page.content()), 'база знаний открывается');
  const adm = await page.goto(base + '/admin/');
  ok(adm.status() === 200, 'админ-панель открывается (' + adm.status() + ')');
  ok(errors.length === 0, 'нет ошибок JS ' + JSON.stringify(errors));
  await browser.close();
})();
