// Сценарий форума: создание жалобы по анкете, ответ с цитатой, реакции, оповещения, модерация, поиск.
// Запуск: BASE=http://127.0.0.1:8081 NODE_PATH=/opt/node22/lib/node_modules node tests/a_flow.js
// В конце тестовая тема удаляется через tests/a_cleanup.php (KEEP=1 - оставить).
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const SHOTS = __dirname + '/screens/';
const stamp = Date.now().toString().slice(-6);
let failed = 0;

function check(cond, msg) {
  if (cond) {
    console.log('  ok   ' + msg);
  } else {
    failed++;
    console.log('  FAIL ' + msg);
  }
}

async function open(browser, login, opts) {
  const ctx = await browser.newContext(Object.assign({ viewport: { width: 1440, height: 1000 } }, opts || {}));
  const page = await ctx.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/fonts\.g|ERR_TUNNEL|net::ERR|status of 404/.test(m.text())) page.errors.push(m.text()); });
  page.on('dialog', (d) => d.accept());
  if (login) {
    const [u, p] = login.split(':');
    await page.goto(BASE + '/forum/login.php');
    await page.fill('form.login-form input[name=login]', u);
    await page.fill('form.login-form input[name=password]', p);
    await Promise.all([page.waitForNavigation(), page.click('form.login-form button[type=submit]')]);
  }
  return page;
}

async function phpErrors(page) {
  const html = await page.content();
  return /(Fatal error|Warning|Notice|Deprecated|Uncaught)\b.*?(on line|in \/)/.test(html);
}

(async () => {
  const browser = await chromium.launch();

  // ---------- 1. Игрок создаёт жалобу по анкете ----------
  console.log('1. Жалоба по анкете (Roxas_Alexandro)');
  const roxas = await open(browser, 'Roxas_Alexandro:test12345');
  await roxas.goto(BASE + '/forum/new-thread.php?node=52');
  check(await roxas.locator('.xf-form').count() === 1, 'анкета показана вместо редактора');
  check((await roxas.locator('.compose-prefix-fixed').innerText()).includes('На рассмотрении'), 'префикс зафиксирован: На рассмотрении');
  await roxas.screenshot({ path: SHOTS + 'a-new-thread-form.png', fullPage: true });
  // Пустая отправка - ошибки
  await roxas.evaluate(() => document.querySelectorAll('.compose-form-q [required]').forEach((el) => el.removeAttribute('required')));
  await Promise.all([roxas.waitForNavigation(), roxas.click('.xf-actions button')]);
  check(await roxas.locator('.xf-row.has-error').count() >= 5, 'пустая анкета - поля подсвечены ошибками');
  await roxas.fill('[name="f[nick]"]', 'Roxas_Alexandro');
  await roxas.fill('[name="f[offender]"]', 'Tester_' + stamp);
  await roxas.fill('[name="f[rule]"]', 'DM в зелёной зоне');
  await roxas.fill('[name="f[essence]"]', 'Убил меня без причины у мэрии. Тест ' + stamp);
  await roxas.fill('[name="f[date]"]', '2026-10-05');
  await roxas.fill('[name="f[time]"]', '21:10');
  await roxas.fill('[name="f[proof]"]', 'https://youtu.be/dQw4w9WgXcQ');
  await roxas.check('[name="f[agree]"]');
  await Promise.all([roxas.waitForNavigation(), roxas.click('.xf-actions button')]);
  const threadUrl = roxas.url();
  const tid = (threadUrl.match(/thread\.php\?id=(\d+)/) || [])[1];
  check(!!tid, 'тема создана: ' + threadUrl);
  const h1 = await roxas.locator('.titlebar h1').innerText();
  check(h1.includes('Жалоба на Tester_' + stamp) && h1.includes('На рассмотрении'), 'заголовок из анкеты и префикс: ' + h1.replace(/\s+/g, ' '));
  check((await roxas.locator('.post-body').first().innerText()).includes('Суть жалобы'), 'текст темы собран из анкеты');
  check(await roxas.locator('[data-watch-form] button').innerText().then((t) => t.includes('Не отслеживать')), 'автор отслеживает свою тему');

  // ---------- 2. Другой игрок отвечает с цитатой и ставит реакцию ----------
  console.log('2. Ответ с цитатой и реакция (Kuzya_Kabanov)');
  const kuzya = await open(browser, 'Kuzya_Kabanov:test12345');
  await kuzya.goto(BASE + '/forum/thread.php?id=' + tid);
  await kuzya.click('.post [data-quote]');
  await kuzya.waitForFunction(() => document.querySelector('.qr-form textarea').value.includes('[quote='));
  const quoted = await kuzya.inputValue('.qr-form textarea');
  check(/\[quote="Roxas_Alexandro, post: \d+, member: \d+"\]/.test(quoted), 'цитата вставлена в редактор');
  await kuzya.locator('.qr-form textarea').press('End');
  await kuzya.locator('.qr-form textarea').type('\nСогласен, видел это. [user=3]Roxas[/user]');
  await Promise.all([kuzya.waitForNavigation(), kuzya.click('.qr-form button[type=submit]')]);
  check(/#post-\d+$/.test(kuzya.url()), 'после ответа переход к новому сообщению');
  check(await kuzya.locator('.post').count() === 2, 'в теме 2 сообщения');
  check(await kuzya.locator('.post .bb-quote').count() === 1, 'цитата отображается');
  // Флуд-контроль: второй ответ сразу
  await kuzya.fill('.qr-form textarea', 'Ещё одно сообщение сразу');
  await Promise.all([kuzya.waitForNavigation(), kuzya.click('.qr-form button[type=submit]')]);
  check((await kuzya.locator('.quick-reply .flash-error').innerText()).includes('Подождите'), 'флуд-контроль: «Подождите N сек.»');
  check((await kuzya.inputValue('.qr-form textarea')).includes('Ещё одно'), 'текст ответа сохранён после ошибки');
  // Реакция: наведение показывает выбор, выбираем «Люблю»
  const first = kuzya.locator('.post').first();
  await first.locator('[data-react]').hover();
  await kuzya.waitForTimeout(700);
  check(await first.locator('.react-picker').isVisible(), 'при наведении появляется выбор реакций');
  await kuzya.screenshot({ path: SHOTS + 'a-react-picker.png', clip: await first.boundingBox() });
  await first.locator('[data-react-pick="love"]').click();
  await kuzya.waitForFunction(() => !document.querySelector('.post .reactions-bar').hidden);
  check((await first.locator('.reactions-bar').innerText()).includes('Вы'), 'полоса реакций: «Вы»');
  check((await first.locator('[data-react]').innerText()).includes('Люблю'), 'кнопка показывает выбранную реакцию');
  // Повторный клик по кнопке снимает реакцию, ещё один ставит лайк
  await first.locator('[data-react]').click();
  await kuzya.waitForFunction(() => document.querySelector('.post .reactions-bar').hidden);
  check(true, 'реакция снята');
  await first.locator('[data-react]').click();
  await kuzya.waitForFunction(() => !document.querySelector('.post .reactions-bar').hidden);
  check((await first.locator('.reactions-bar').innerText()).includes('Вы'), 'лайк поставлен снова');
  check(await kuzya.locator('.post').nth(1).locator('[data-react]').count() === 0, 'на своё сообщение реакцию поставить нельзя');
  check(kuzya.errors.length === 0, 'нет ошибок JS: ' + kuzya.errors.join('; '));

  // ---------- 3. Оповещения автора ----------
  console.log('3. Оповещения автора темы (Roxas_Alexandro)');
  await roxas.goto(BASE + '/forum/alerts.php');
  const alertsText = await roxas.locator('.alert-list').innerText();
  check(alertsText.includes('процитировал'), 'оповещение о цитате');
  check(alertsText.includes('оценил'), 'оповещение о реакции');
  check(await roxas.locator('.alert-row.unread').count() >= 2, 'новые оповещения подсвечены');
  await roxas.screenshot({ path: SHOTS + 'a-alerts.png', fullPage: true });
  await roxas.goto(BASE + '/forum/alerts.php');
  check(await roxas.locator('.alert-row.unread').count() === 0, 'после открытия страницы оповещения прочитаны');

  // ---------- 4. Автор редактирует тему ----------
  console.log('4. Правка первого сообщения автором');
  await roxas.goto(BASE + '/forum/thread.php?id=' + tid);
  await Promise.all([roxas.waitForNavigation(), roxas.locator('.post').first().locator('a.post-act[href*="action=edit"]').click()]);
  check(await roxas.locator('select[name=prefix_id]').count() === 0, 'автор не меняет префикс в разделе с префиксом по умолчанию');
  await roxas.fill('input[name=title]', 'Жалоба на Tester_' + stamp + ' | DM (дополнено)');
  await roxas.fill('textarea[name=body]', (await roxas.inputValue('textarea[name=body]')) + '\nДополнение: есть второе видео.');
  await Promise.all([roxas.waitForNavigation(), roxas.click('.compose-actions button[type=submit]')]);
  check((await roxas.locator('.titlebar h1').innerText()).includes('(дополнено)'), 'заголовок изменён');
  check((await roxas.locator('.post').first().locator('.post-edited').innerText()).includes('Изменено'), 'отметка «Изменено»');

  // ---------- 5. Модератор ----------
  console.log('5. Модерация (Diego_Bacardi)');
  const diego = await open(browser, 'Diego_Bacardi:test12345');
  await diego.goto(BASE + '/forum/thread.php?id=' + tid);
  await diego.click('.mod-dd [data-dropdown]');
  await diego.screenshot({ path: SHOTS + 'a-mod-menu.png' });
  // Одобрено и закрыть
  const approve = diego.locator('.mod-status-row', { hasText: 'Одобрено' }).locator('.mod-status-lock');
  await Promise.all([diego.waitForNavigation(), approve.click()]);
  check((await diego.locator('.titlebar h1').innerText()).includes('Одобрено'), 'статус «Одобрено»');
  check((await diego.locator('.titlebar-meta').innerText()).includes('Закрыта'), 'тема закрыта');
  check(await diego.locator('.qr-note').count() === 1, 'модератор может ответить в закрытой теме');
  // Закрепить
  await diego.click('.mod-dd [data-dropdown]');
  await Promise.all([diego.waitForNavigation(), diego.locator('.mod-menu button', { hasText: 'Закрепить' }).click()]);
  check((await diego.locator('.titlebar-meta').innerText()).includes('Закреплена'), 'тема закреплена');
  // Переименовать через окно
  await diego.click('.mod-dd [data-dropdown]');
  await diego.click('[data-dialog-open="dlg-rename"]');
  check(await diego.locator('#dlg-rename').isVisible(), 'окно переименования открыто');
  await diego.screenshot({ path: SHOTS + 'a-mod-dialog.png' });
  await diego.fill('#m-title', 'Жалоба на Tester_' + stamp + ' | DM');
  await Promise.all([diego.waitForNavigation(), diego.click('#dlg-rename button[type=submit]')]);
  check((await diego.locator('.titlebar h1').innerText()).includes('Tester_' + stamp + ' | DM'), 'тема переименована');
  // Удалить и восстановить ответ
  const reply = diego.locator('.post').nth(1);
  await Promise.all([diego.waitForNavigation(), reply.locator('button', { hasText: 'Удалить' }).click()]);
  check(await diego.locator('.post-collapsed-deleted').count() === 1, 'удалённое сообщение свёрнуто для модератора');
  check((await diego.locator('.post-collapsed-deleted').innerText()).includes('Diego_Bacardi'), 'видно, кто удалил');
  await diego.screenshot({ path: SHOTS + 'a-deleted-post.png', fullPage: true });
  // Гость не видит удалённое сообщение
  const guest = await open(browser, null);
  await guest.goto(BASE + '/forum/thread.php?id=' + tid);
  check(await guest.locator('.post').count() === 1 && await guest.locator('.post-collapsed').count() === 0, 'гость не видит удалённое сообщение');
  check((await guest.locator('.qr-guest').count()) === 1, 'гостю показан блок «Войдите или зарегистрируйтесь»');
  await Promise.all([diego.waitForNavigation(), diego.locator('.post-collapsed-deleted .post-collapsed-head button', { hasText: 'Восстановить' }).click()]);
  check(await diego.locator('.post-collapsed').count() === 0 && await diego.locator('.post').count() === 2, 'сообщение восстановлено');
  // Перенос
  await diego.click('.mod-dd [data-dropdown]');
  await diego.click('[data-dialog-open="dlg-move"]');
  await diego.selectOption('#m-node', '55');
  await Promise.all([diego.waitForNavigation(), diego.click('#dlg-move button[type=submit]')]);
  check((await diego.locator('.crumbs').innerText()).includes('Обжалование наказаний'), 'тема перенесена');
  await diego.click('.mod-dd [data-dropdown]');
  await diego.click('[data-dialog-open="dlg-move"]');
  await diego.selectOption('#m-node', '52');
  await Promise.all([diego.waitForNavigation(), diego.click('#dlg-move button[type=submit]')]);
  // Удалить тему и восстановить
  await diego.click('.mod-dd [data-dropdown]');
  await Promise.all([diego.waitForNavigation(), diego.locator('.mod-menu button', { hasText: 'Удалить тему' }).click()]);
  check(diego.url().includes('forum.php?id=52'), 'после удаления темы переход в раздел');
  check(await diego.locator('#thread-' + tid + '.deleted').count() === 1, 'модератор видит удалённую тему с меткой');
  const g2 = await guest.goto(BASE + '/forum/thread.php?id=' + tid);
  check(g2.status() === 404, 'гость получает 404 на удалённую тему');
  await diego.goto(BASE + '/forum/thread.php?id=' + tid);
  await Promise.all([diego.waitForNavigation(), diego.click('.thread-deleted-note button')]);
  check(await diego.locator('.thread-deleted-note').count() === 0, 'тема восстановлена');
  check(diego.errors.length === 0, 'нет ошибок JS у модератора: ' + diego.errors.join('; '));

  // ---------- 6. Автор видит смену статуса ----------
  console.log('6. Оповещения о модерации');
  await roxas.goto(BASE + '/forum/alerts.php');
  const t2 = await roxas.locator('.alert-list').innerText();
  check(t2.includes('Одобрено'), 'оповещение о смене статуса');
  check(t2.includes('перенесена'), 'оповещение о переносе');
  await roxas.goto(BASE + '/forum/thread.php?id=' + tid);
  check(await roxas.locator('.qr-closed').count() === 1, 'автор не может ответить в закрытой теме');
  check(await roxas.locator('a.post-act[href*="action=edit"]').count() === 0, 'в закрытой теме автор не редактирует');

  // ---------- 7. Поиск ----------
  console.log('7. Поиск');
  await guest.goto(BASE + '/forum/search.php?q=Tester_' + stamp);
  check(await guest.locator('.sresult').count() >= 1, 'поиск находит тему');
  check(await guest.locator('.sresult mark').count() >= 1, 'совпадение подсвечено');
  await guest.screenshot({ path: SHOTS + 'a-search.png', fullPage: true });
  await guest.goto(BASE + '/forum/search.php?q=' + encodeURIComponent('100%_'));
  check(await guest.locator('.sresult').count() === 0, 'символы % и _ экранированы');
  await guest.goto(BASE + '/forum/search.php?q=ab');
  check((await guest.locator('.flash-error').innerText()).includes('3 символов'), 'короткий запрос - ошибка');
  await guest.goto(BASE + '/forum/search.php?q=Tester_' + stamp + '&in=titles');
  check(await guest.locator('.sresult').count() === 1, 'поиск только по заголовкам');

  // ---------- 8. Списки ----------
  console.log('8. Списки тем');
  await roxas.goto(BASE + '/forum/find.php?type=mine');
  check(await roxas.locator('#thread-' + tid).count() === 1, 'find: мои темы');
  await kuzya.goto(BASE + '/forum/find.php?type=participated');
  check(await kuzya.locator('#thread-' + tid).count() === 1, 'find: темы с моим участием');
  await guest.goto(BASE + '/forum/find.php?type=new');
  check(await guest.locator('.trow').count() >= 1, 'find: последние темы для гостя');
  await kuzya.goto(BASE + '/forum/watched.php');
  check(await kuzya.locator('#thread-' + tid).count() === 1, 'watched: ответившему тема добавлена в отслеживаемые');
  await Promise.all([kuzya.waitForNavigation(), kuzya.locator('#thread-' + tid + ' .trow-action').click()]);
  check(await kuzya.locator('#thread-' + tid).count() === 0, 'watched: отписка от темы');
  await guest.goto(BASE + '/forum/forum.php?id=52');
  check(await guest.locator('.trow-group[data-group] #thread-' + tid).count() === 1, 'закреплённая тема в группе «Закреплено»');

  // ---------- 9. XSS ----------
  console.log('9. Экранирование');
  await guest.goto(BASE + '/forum/search.php?q=' + encodeURIComponent('<script>alert(1)</script>') + '&author=' + encodeURIComponent('"><b>x'));
  check(!(await guest.content()).includes('<script>alert(1)</script>'), 'запрос в поиске экранирован');
  check(guest.errors.length === 0, 'нет ошибок JS у гостя: ' + guest.errors.join('; '));

  for (const p of [roxas, kuzya, diego, guest]) {
    if (await phpErrors(p)) check(false, 'PHP предупреждения на ' + p.url());
  }

  await browser.close();
  console.log(failed ? '\nПРОВАЛЕНО: ' + failed : '\nВсе проверки пройдены');
  console.log('THREAD_ID=' + tid);
  // Убираем тестовую тему из базы (KEEP=1 - оставить)
  if (tid && !process.env.KEEP) {
    console.log(require('child_process').execSync('php ' + JSON.stringify(__dirname + '/a_cleanup.php') + ' ' + Number(tid)).toString().trim());
  }
  process.exit(failed ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(2);
});
