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
  // Быстрый выбор срока бана: +N дней от текущего времени
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-ban-days]');
    if (!b) return;
    var input = b.form ? b.form.querySelector('[name="ban_until"]') : null;
    if (!input) return;
    var d = new Date(Date.now() + parseInt(b.getAttribute('data-ban-days'), 10) * 86400000);
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    input.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    var forever = b.form.querySelector('[name="ban_forever"]');
    if (forever && forever.checked) { forever.checked = false; forever.dispatchEvent(new Event('change')); }
  });

  // ---------- Конструктор анкеты (разделы форума) ----------
  (function () {
    var fb = $('[data-form-builder]');
    var tpl = document.getElementById('fb-row-tpl');
    if (!fb || !tpl) return;
    var form = fb.closest('form');
    var list = $('[data-fb-list]', fb);
    var json = $('[data-fb-json]', fb);
    var jsonErr = $('[data-fb-json-error]', fb);
    var titleIn = $('[data-fb-title]', fb);
    var introIn = $('[data-fb-intro]', fb);
    var empty = $('[data-fb-empty]', fb);
    var keysBox = $('[data-fb-keys]', fb);
    var preview = $('[data-fb-preview]', form);
    var keysHint = keysBox ? keysBox.innerHTML : '';

    function keyFrom(label) {
      return slugify(label).replace(/-/g, '_').substring(0, 32).replace(/_+$/, '');
    }
    function rows() { return Array.prototype.slice.call(list.children); }
    function g(row, k) { return row.querySelector('[data-k="' + k + '"]'); }

    function addRow(f, isNew) {
      var row = tpl.content.firstElementChild.cloneNode(true);
      f = f || {};
      g(row, 'label').value = f.label || '';
      g(row, 'type').value = f.type || 'text';
      if (!g(row, 'type').value) g(row, 'type').value = 'text';
      g(row, 'required').checked = !!f.required;
      g(row, 'key').value = f.key || '';
      g(row, 'placeholder').value = f.placeholder || '';
      g(row, 'hint').value = f.hint || '';
      g(row, 'max').value = f.max ? f.max : '';
      g(row, 'text').value = f.text && f.text !== 'Да' ? f.text : '';
      g(row, 'options').value = Array.isArray(f.options) ? f.options.join('\n') : '';
      if (isNew) row.classList.add('is-new');
      list.appendChild(row);
      return row;
    }

    // Поля из конструктора; заодно ключи (как их сохранит сервер)
    function collect() {
      var used = {};
      var fields = [];
      rows().forEach(function (row, i) {
        var label = g(row, 'label').value.trim();
        var type = g(row, 'type').value;
        var key = g(row, 'key').value.trim().toLowerCase();
        var auto = keyFrom(label) || ('f' + i);
        g(row, 'key').placeholder = auto + ' (сам)';
        if (!/^[a-z0-9_]{1,32}$/.test(key)) key = auto;
        var base = key, n = 2;
        while (used[key]) key = base.substring(0, 28) + '_' + (n++);
        used[key] = true;
        row.setAttribute('data-key', key);
        if (!label) return;
        var f = { key: key, label: label, type: type, required: g(row, 'required').checked };
        var ph = g(row, 'placeholder').value.trim();
        var hint = g(row, 'hint').value.trim();
        var max = parseInt(g(row, 'max').value, 10);
        if (ph && ['text', 'textarea', 'url', 'number'].indexOf(type) !== -1) f.placeholder = ph;
        if (hint) f.hint = hint;
        if (max > 0 && ['text', 'textarea', 'url', 'number'].indexOf(type) !== -1) f.max = max;
        if (type === 'radio' || type === 'select') {
          f.options = g(row, 'options').value.split('\n').map(function (o) { return o.trim(); }).filter(Boolean);
        }
        if (type === 'checkbox') f.text = g(row, 'text').value.trim() || 'Да';
        fields.push(f);
      });
      return fields;
    }

    function refreshUi(fields) {
      var all = rows();
      all.forEach(function (row, i) {
        row.querySelector('[data-fb-num]').textContent = i + 1;
        var type = g(row, 'type').value;
        $$('[data-fb-show]', row).forEach(function (el) {
          el.hidden = el.getAttribute('data-fb-show').split(',').indexOf(type) === -1;
        });
        row.querySelector('[data-fb-up]').disabled = i === 0;
        row.querySelector('[data-fb-down]').disabled = i === all.length - 1;
      });
      if (empty) empty.hidden = all.length > 0;
      if (keysBox) {
        if (!fields.length) {
          keysBox.innerHTML = keysHint;
        } else {
          keysBox.innerHTML = '';
          fields.forEach(function (f) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = '{' + f.key + '}';
            b.title = f.label;
            b.setAttribute('data-fb-insert', '{' + f.key + '}');
            keysBox.appendChild(b);
          });
        }
      }
    }

    var timer = null;
    function schedulePreview() {
      if (!preview || !window.WRP) return;
      clearTimeout(timer);
      timer = setTimeout(function () {
        preview.classList.add('is-loading');
        WRP.post(preview.getAttribute('data-url'), { action: 'form_preview', form_json: json.value }).then(function (r) {
          preview.classList.remove('is-loading');
          if (r && r.ok) {
            preview.innerHTML = r.html || '<div class="adm-empty">Анкеты нет - темы создаются обычным редактором.</div>';
          } else {
            preview.innerHTML = '<div class="field-error">' + String((r && r.error) || 'Не удалось построить предпросмотр').replace(/</g, '&lt;') + '</div>';
          }
        });
      }, 450);
    }

    function serialize() {
      var fields = collect();
      if (!fields.length) {
        json.value = '';
      } else {
        json.value = JSON.stringify({ title: titleIn.value.trim(), intro: introIn.value, fields: fields }, null, 2);
      }
      if (jsonErr) jsonErr.hidden = true;
      json.classList.remove('is-invalid');
      refreshUi(fields);
      schedulePreview();
    }

    function load(text) {
      text = String(text || '').trim();
      var o = null;
      if (text) {
        try { o = JSON.parse(text); } catch (e) { return false; }
        if (!o || typeof o !== 'object' || !Array.isArray(o.fields)) return false;
      }
      list.innerHTML = '';
      titleIn.value = o && o.title ? o.title : '';
      introIn.value = o && o.intro ? o.intro : '';
      if (o) o.fields.forEach(function (f) { if (f && typeof f === 'object') addRow(f); });
      refreshUi(collect());
      return true;
    }

    if (!load(json.value)) {
      if (jsonErr) {
        jsonErr.textContent = 'В JSON ошибка - конструктор не может его прочитать. Исправьте JSON или начните заново кнопкой «Убрать анкету».';
        jsonErr.hidden = false;
      }
      var det = json.closest('details');
      if (det) det.open = true;
    }

    list.addEventListener('input', serialize);
    list.addEventListener('change', serialize);
    titleIn.addEventListener('input', serialize);
    introIn.addEventListener('input', serialize);
    json.addEventListener('input', function () {
      if (load(json.value)) {
        if (jsonErr) jsonErr.hidden = true;
        json.classList.remove('is-invalid');
        schedulePreview();
      } else {
        if (jsonErr) { jsonErr.textContent = 'В JSON ошибка - проверьте запятые, кавычки и скобки.'; jsonErr.hidden = false; }
        json.classList.add('is-invalid');
      }
    });

    fb.addEventListener('click', function (ev) {
      if (ev.defaultPrevented) return;
      var t = ev.target.closest('button');
      if (!t || !fb.contains(t)) return;
      var row = t.closest('.fb-row');
      if (t.hasAttribute('data-fb-add')) {
        rows().forEach(function (r) { r.classList.remove('is-new'); });
        var nr = addRow({ type: 'text' }, true);
        serialize();
        g(nr, 'label').focus();
      } else if (t.hasAttribute('data-fb-remove') && row) {
        var lbl = g(row, 'label').value.trim();
        if (lbl && !window.confirm('Удалить поле «' + lbl + '»?')) return;
        row.remove();
        serialize();
      } else if (t.hasAttribute('data-fb-up') && row && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling);
        serialize();
      } else if (t.hasAttribute('data-fb-down') && row && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row);
        serialize();
      } else if (t.hasAttribute('data-fb-clear')) {
        list.innerHTML = '';
        titleIn.value = '';
        introIn.value = '';
        serialize();
      } else if (t.hasAttribute('data-fb-insert')) {
        var v = t.getAttribute('data-fb-insert');
        var s = titleIn.selectionStart != null ? titleIn.selectionStart : titleIn.value.length;
        var e2 = titleIn.selectionEnd != null ? titleIn.selectionEnd : s;
        titleIn.value = titleIn.value.substring(0, s) + v + titleIn.value.substring(e2);
        titleIn.focus();
        titleIn.selectionStart = titleIn.selectionEnd = s + v.length;
        serialize();
      }
    });
  })();
})();
