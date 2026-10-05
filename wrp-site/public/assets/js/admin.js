/* World Role Play: скрипты админ-панели (живой предпросмотр иконок, цветов, префиксов, меню) */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Значение поля формы по имени
  function fieldValue(form, name) {
    var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]:not([type="radio"]):not([type="checkbox"])');
    if (!el) {
      var cb = form.querySelector('[type="checkbox"][name="' + name + '"]');
      return cb ? (cb.checked ? '1' : '') : '';
    }
    return el.value;
  }

  // SVG выбранной иконки из сетки
  function iconSvg(form, name) {
    var r = form.querySelector('.icon-opt input[name="' + name + '"]:checked');
    var svg = r ? r.parentNode.querySelector('svg') : null;
    return svg ? svg.outerHTML : '';
  }

  // Транслитерация как slugify() на сервере
  var MAP = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya' };
  function slugify(s) {
    s = String(s || '').toLowerCase().split('').map(function (c) { return MAP.hasOwnProperty(c) ? MAP[c] : c; }).join('');
    s = s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return s.substring(0, 80);
  }

  function refresh(form) {
    // Тексты: data-bind-text="title" (data-empty - текст, если поле пустое, data-upper - заглавными)
    $$('[data-bind-text]', form).forEach(function (el) {
      var v = fieldValue(form, el.getAttribute('data-bind-text')).trim();
      if (!v) v = el.getAttribute('data-empty') || '';
      if (el.hasAttribute('data-upper')) v = v.toUpperCase();
      el.textContent = v;
    });
    // Иконки: data-bind-icon="icon"
    $$('[data-bind-icon]', form).forEach(function (el) {
      var svg = iconSvg(form, el.getAttribute('data-bind-icon'));
      if (svg) el.innerHTML = svg;
    });
    // Цвет: data-bind-color="color" -> --c
    $$('[data-bind-color]', form).forEach(function (el) {
      var v = fieldValue(form, el.getAttribute('data-bind-color'));
      if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) el.style.setProperty('--c', v);
    });
    // Префикс: data-bind-prefix="color" -> класс prefix-<цвет>
    $$('[data-bind-prefix]', form).forEach(function (el) {
      var v = fieldValue(form, el.getAttribute('data-bind-prefix'));
      el.className = 'prefix prefix-' + (v || 'gray');
    });
    // Показ блоков по значению: data-show-if="type=forum,link"
    $$('[data-show-if]', form).forEach(function (el) {
      var rule = el.getAttribute('data-show-if').split('=');
      var v = fieldValue(form, rule[0]);
      el.hidden = rule[1].split(',').indexOf(v) === -1;
    });
    // Отметка «в новой вкладке»: data-bind-flag="new_tab"
    $$('[data-bind-flag]', form).forEach(function (el) {
      el.hidden = !fieldValue(form, el.getAttribute('data-bind-flag'));
    });
    // Подсказка адреса: data-slug-from="title"
    $$('[data-slug-from]', form).forEach(function (el) {
      var s = slugify(fieldValue(form, el.getAttribute('data-slug-from')));
      el.placeholder = s ? s + ' (создастся сам)' : 'создастся из названия';
    });
  }

  $$('form[data-live]').forEach(function (form) {
    form.addEventListener('input', function () { refresh(form); });
    form.addEventListener('change', function () { refresh(form); });
    refresh(form);
  });

  // Выбор иконки: крупный предпросмотр и название рядом с сеткой
  document.addEventListener('change', function (ev) {
    var r = ev.target;
    if (!r.matches || !r.matches('.icon-opt input')) return;
    var field = r.closest('[data-icon-field]');
    if (!field) return;
    var box = $('[data-icon-preview]', field);
    var svg = r.parentNode.querySelector('svg');
    if (box && svg) box.innerHTML = svg.outerHTML;
    var nm = $('[data-icon-name]', field);
    if (nm) nm.textContent = r.value;
  });
  // Прокрутить сетку к выбранной иконке
  $$('.icon-picker').forEach(function (p) {
    var c = p.querySelector('input:checked');
    if (c) p.scrollTop = Math.max(0, c.parentNode.offsetTop - p.offsetTop - 50);
  });

  // Цвет: <input type=color> и текстовое поле синхронно
  $$('[data-color-pair]').forEach(function (wrap) {
    var picker = $('input[type="color"]', wrap);
    var text = $('input[type="text"]', wrap);
    if (!picker || !text) return;
    picker.addEventListener('input', function () {
      text.value = picker.value;
      text.dispatchEvent(new Event('input', { bubbles: true }));
    });
    text.addEventListener('input', function () {
      var v = text.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(v)) picker.value = v;
      else if (/^#[0-9a-f]{3}$/i.test(v)) picker.value = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    });
  });

  // Чекбокс выключает поле: data-disables="#id"
  $$('[data-disables]').forEach(function (cb) {
    var target = $(cb.getAttribute('data-disables'));
    if (!target) return;
    var sync = function () { target.disabled = cb.checked; };
    cb.addEventListener('change', sync);
    sync();
  });

  // Фильтры списка: отправка формы сразу после выбора
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) el.form.submit(); });
  });
})();
