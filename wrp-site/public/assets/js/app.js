/* World Role Play: общие скрипты (тема, меню, выпадашки, копирование, редактор BB-кодов) */
(function () {
  'use strict';

  var meta = function (name) {
    var m = document.querySelector('meta[name="' + name + '"]');
    return m ? m.getAttribute('content') : '';
  };

  var WRP = window.WRP = {
    csrf: meta('csrf-token'),
    base: meta('base-path') || '',

    url: function (path) {
      return WRP.base + path;
    },

    cookie: function (name, value, days) {
      var d = new Date();
      d.setTime(d.getTime() + (days || 365) * 86400000);
      document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=' + (WRP.base || '') + '/; SameSite=Lax';
    },

    // POST-запрос, ответ JSON
    post: function (path, data) {
      var body = new FormData();
      body.append('_token', WRP.csrf);
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
      return fetch(path.indexOf('/') === 0 && path.indexOf(WRP.base) !== 0 ? WRP.url(path) : path, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': WRP.csrf }
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Ошибка сервера' }; });
      });
    },

    toast: function (text) {
      var t = document.createElement('div');
      t.className = 'toast';
      t.textContent = text;
      document.body.appendChild(t);
      setTimeout(function () { t.remove(); }, 2200);
    },

    copy: function (text) {
      var done = function () { WRP.toast('Скопировано: ' + text); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        ta.remove();
      }
    },

    // Вставка текста в textarea вокруг выделения
    wrap: function (ta, before, after, placeholder) {
      ta.focus();
      var s = ta.selectionStart, e = ta.selectionEnd;
      var sel = ta.value.substring(s, e) || placeholder || '';
      var text = before + sel + (after || '');
      if (!document.execCommand || !document.execCommand('insertText', false, text)) {
        ta.setRangeText(text, s, e, 'end');
      }
      if (!ta.value.substring(s, e) && placeholder) {
        ta.selectionStart = s + before.length;
        ta.selectionEnd = s + before.length + sel.length;
      }
      ta.dispatchEvent(new Event('input'));
    },

    // Вставка в конец (для цитат)
    insert: function (ta, text) {
      ta.focus();
      ta.selectionStart = ta.selectionEnd = ta.value.length;
      var prefix = ta.value && !/\n$/.test(ta.value) ? '\n' : '';
      if (!document.execCommand || !document.execCommand('insertText', false, prefix + text)) {
        ta.value += prefix + text;
      }
      ta.dispatchEvent(new Event('input'));
      ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  };

  var root = document.documentElement;

  // Тема
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-theme-toggle]');
    if (!t) return;
    var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    root.setAttribute('data-theme', next);
    WRP.cookie('wrp_theme', next);
  });

  // Ширина страницы
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-wide-toggle]');
    if (!t) return;
    root.classList.toggle('is-wide');
    WRP.cookie('wrp_wide', root.classList.contains('is-wide') ? '1' : '0');
  });

  // Левое меню
  var body = document.body;
  try {
    if (localStorage.getItem('wrp_sidebar') === 'collapsed' && window.innerWidth > 900) body.classList.add('sidebar-collapsed');
  } catch (e) {}
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-toggle-sidebar]');
    if (!t) return;
    if (window.innerWidth <= 900) {
      body.classList.toggle('sidebar-open');
    } else {
      body.classList.toggle('sidebar-collapsed');
      try { localStorage.setItem('wrp_sidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'); } catch (e) {}
    }
  });

  // Группы в левом меню
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-side-group]');
    if (!t) return;
    t.parentElement.classList.toggle('open');
  });

  // Выпадающие меню
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-dropdown]');
    var openNow = document.querySelectorAll('.dropdown.open');
    if (t) {
      var dd = t.closest('.dropdown');
      var was = dd.classList.contains('open');
      openNow.forEach(function (d) { d.classList.remove('open'); });
      if (!was) dd.classList.add('open');
      ev.preventDefault();
      return;
    }
    if (!ev.target.closest('.dropdown-menu')) {
      openNow.forEach(function (d) { d.classList.remove('open'); });
    }
  });

  // Подфорумы в списке разделов
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-subs-toggle]');
    if (!t) return;
    t.closest('.node-row').classList.toggle('subs-open');
  });

  // Копирование
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-copy]');
    if (!t) return;
    ev.preventDefault();
    WRP.copy(t.getAttribute('data-copy'));
  });

  // Подтверждение действий
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var q = f.getAttribute('data-confirm');
    if (q && !window.confirm(q)) ev.preventDefault();
  });
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('a[data-confirm], button[data-confirm]:not([type="submit"])');
    if (t && !window.confirm(t.getAttribute('data-confirm'))) ev.preventDefault();
  });

  // Кнопки прокрутки
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-scroll]');
    if (!t) return;
    window.scrollTo({ top: t.getAttribute('data-scroll') === 'up' ? 0 : document.body.scrollHeight, behavior: 'smooth' });
  });

  // ---------- Редактор BB-кодов ----------

  var ICONS = {};
  var svg = function (paths) {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>';
  };
  ICONS.eraser = svg('<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7M5 11l9 9"/>');
  ICONS.bold = svg('<path d="M6 12h9a4 4 0 0 1 0 8H6V4h8a4 4 0 0 1 0 8"/>');
  ICONS.italic = svg('<path d="M19 4h-9M14 20H5M15 4 9 20"/>');
  ICONS.underline = svg('<path d="M6 4v6a6 6 0 0 0 12 0V4"/><path d="M4 20h16"/>');
  ICONS.strike = svg('<path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><path d="M4 12h16"/>');
  ICONS.size = svg('<path d="M4 7V4h16v3M9 20h6M12 4v16"/>');
  ICONS.color = svg('<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.56-2.5 5.56-5.55C21.97 6.01 17.46 2 12 2z"/><circle cx="7.5" cy="10.5" r="1" fill="currentColor"/><circle cx="12" cy="7" r="1" fill="currentColor"/><circle cx="16.5" cy="10.5" r="1" fill="currentColor"/>');
  ICONS.left = svg('<path d="M21 6H3M15 12H3M17 18H3"/>');
  ICONS.center = svg('<path d="M21 6H3M17 12H7M19 18H5"/>');
  ICONS.right = svg('<path d="M21 6H3M21 12H9M21 18H7"/>');
  ICONS.list = svg('<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>');
  ICONS.olist = svg('<path d="M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>');
  ICONS.link = svg('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>');
  ICONS.image = svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>');
  ICONS.video = svg('<path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/>');
  ICONS.quote = svg('<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.76-2.02-2-2H4c-1.25 0-2 .75-2 1.97V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .01-1 1.03V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.76-2.02-2-2h-4c-1.25 0-2 .75-2 1.97V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/>');
  ICONS.spoiler = svg('<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><path d="m2 2 20 20"/>');
  ICONS.code = svg('<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>');
  ICONS.table = svg('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>');
  ICONS.hr = svg('<path d="M3 12h18"/>');
  ICONS.undo = svg('<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>');
  ICONS.redo = svg('<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/>');
  ICONS.eye = svg('<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>');

  var COLORS = ['#ffffff', '#c0c0c0', '#808080', '#000000', '#ff4d4d', '#ff0000', '#b91c1c', '#ff8a00',
    '#ffc107', '#ffeb3b', '#22c55e', '#15803d', '#00e5ff', '#3b82f6', '#1e40af', '#a855f7',
    '#ec4899', '#f472b6', '#8b5cf6', '#14b8a6', '#84cc16', '#f59e0b', '#ef4444', '#6366f1'];

  function closePops() {
    document.querySelectorAll('.editor-pop').forEach(function (p) { p.remove(); });
  }
  document.addEventListener('click', function (ev) {
    if (!ev.target.closest('.editor-pop') && !ev.target.closest('[data-pop]')) closePops();
  });

  function pop(btn, html, onClick) {
    closePops();
    var p = document.createElement('div');
    p.className = 'editor-pop';
    p.innerHTML = html;
    document.body.appendChild(p);
    var r = btn.getBoundingClientRect();
    p.style.left = (window.scrollX + r.left) + 'px';
    p.style.top = (window.scrollY + r.bottom + 6) + 'px';
    p.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      ev.preventDefault();
      onClick(b.getAttribute('data-v'));
      closePops();
    });
  }

  function initEditor(ta) {
    if (ta.dataset.ready) return;
    ta.dataset.ready = '1';
    var wrap = document.createElement('div');
    wrap.className = 'editor';
    var bar = document.createElement('div');
    bar.className = 'editor-toolbar';
    var preview = document.createElement('div');
    preview.className = 'editor-preview bb';
    ta.parentNode.insertBefore(wrap, ta);
    wrap.appendChild(bar);
    wrap.appendChild(ta);
    wrap.appendChild(preview);

    var tools = [
      ['eraser', 'Убрать форматирование', function () {
        var s = ta.selectionStart, e = ta.selectionEnd;
        if (s === e) return;
        var clean = ta.value.substring(s, e).replace(/\[\/?(b|i|u|s|color|size|font|center|left|right|justify)(=[^\]]*)?\]/gi, '');
        ta.focus();
        if (!document.execCommand('insertText', false, clean)) ta.setRangeText(clean, s, e, 'end');
      }],
      ['bold', 'Жирный', function () { WRP.wrap(ta, '[b]', '[/b]', 'текст'); }],
      ['italic', 'Курсив', function () { WRP.wrap(ta, '[i]', '[/i]', 'текст'); }],
      ['underline', 'Подчёркнутый', function () { WRP.wrap(ta, '[u]', '[/u]', 'текст'); }],
      ['strike', 'Зачёркнутый', function () { WRP.wrap(ta, '[s]', '[/s]', 'текст'); }],
      ['size', 'Размер текста', function (btn) {
        var h = '<div class="size-list">';
        [[1, 'Очень мелкий'], [2, 'Мелкий'], [3, 'Обычный'], [4, 'Средний'], [5, 'Крупный'], [6, 'Большой'], [7, 'Огромный']].forEach(function (s) {
          h += '<button type="button" data-v="' + s[0] + '" class="bb-size-' + s[0] + '">' + s[1] + '</button>';
        });
        pop(btn, h + '</div>', function (v) { WRP.wrap(ta, '[size=' + v + ']', '[/size]', 'текст'); });
      }],
      ['color', 'Цвет текста', function (btn) {
        var h = '<div class="color-grid">';
        COLORS.forEach(function (c) { h += '<button type="button" data-v="' + c + '" style="background:' + c + '" title="' + c + '"></button>'; });
        pop(btn, h + '</div>', function (v) { WRP.wrap(ta, '[color=' + v + ']', '[/color]', 'текст'); });
      }],
      '|',
      ['left', 'По левому краю', function () { WRP.wrap(ta, '[left]', '[/left]', 'текст'); }],
      ['center', 'По центру', function () { WRP.wrap(ta, '[center]', '[/center]', 'текст'); }],
      ['right', 'По правому краю', function () { WRP.wrap(ta, '[right]', '[/right]', 'текст'); }],
      ['list', 'Список', function () { WRP.wrap(ta, '[list]\n[*]', '\n[*]\n[/list]', 'пункт'); }],
      ['olist', 'Нумерованный список', function () { WRP.wrap(ta, '[list=1]\n[*]', '\n[*]\n[/list]', 'пункт'); }],
      '|',
      ['link', 'Ссылка', function () {
        var u = window.prompt('Адрес ссылки (https://...)', 'https://');
        if (!u || u === 'https://') return;
        WRP.wrap(ta, '[url=' + u + ']', '[/url]', 'текст ссылки');
      }],
      ['image', 'Картинка по ссылке', function () {
        var u = window.prompt('Адрес картинки (https://...)', 'https://');
        if (!u || u === 'https://') return;
        WRP.wrap(ta, '[img]' + u + '[/img]', '', '');
      }],
      ['video', 'Видео YouTube', function () {
        var u = window.prompt('Ссылка на видео YouTube', 'https://youtu.be/');
        if (!u || u === 'https://youtu.be/') return;
        WRP.wrap(ta, '[media]' + u + '[/media]', '', '');
      }],
      ['quote', 'Цитата', function () { WRP.wrap(ta, '[quote]', '[/quote]', 'текст'); }],
      ['spoiler', 'Спойлер', function () {
        var t = window.prompt('Заголовок спойлера (можно оставить пустым)', '');
        if (t === null) return;
        WRP.wrap(ta, t ? '[spoiler=' + t + ']' : '[spoiler]', '[/spoiler]', 'скрытый текст');
      }],
      ['code', 'Код', function () { WRP.wrap(ta, '[code]', '[/code]', 'код'); }],
      ['table', 'Таблица', function () { WRP.wrap(ta, '[table]\n[tr][th]Заголовок 1[/th][th]Заголовок 2[/th][/tr]\n[tr][td]', '[/td][td]Ячейка[/td][/tr]\n[/table]', 'Ячейка'); }],
      ['hr', 'Горизонтальная линия', function () { WRP.wrap(ta, '\n[hr]\n', '', ''); }],
      '|',
      ['undo', 'Отменить', function () { ta.focus(); document.execCommand('undo'); }],
      ['redo', 'Повторить', function () { ta.focus(); document.execCommand('redo'); }]
    ];

    tools.forEach(function (t) {
      if (t === '|') {
        var s = document.createElement('span');
        s.className = 'editor-sep';
        bar.appendChild(s);
        return;
      }
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'editor-btn';
      b.title = t[1];
      b.innerHTML = ICONS[t[0]];
      if (t[0] === 'size' || t[0] === 'color') b.setAttribute('data-pop', '1');
      b.addEventListener('click', function (ev) { ev.preventDefault(); t[2](b); });
      bar.appendChild(b);
    });

    var sp = document.createElement('span');
    sp.className = 'editor-spacer';
    bar.appendChild(sp);

    var pb = document.createElement('button');
    pb.type = 'button';
    pb.className = 'editor-preview-btn';
    pb.innerHTML = ICONS.eye + ' Предпросмотр';
    pb.addEventListener('click', function () {
      if (wrap.classList.contains('previewing')) {
        wrap.classList.remove('previewing');
        pb.classList.remove('active');
        ta.focus();
        return;
      }
      preview.innerHTML = '<span class="muted">Загрузка...</span>';
      wrap.classList.add('previewing');
      pb.classList.add('active');
      WRP.post(WRP.url('/forum/preview.php'), { body: ta.value }).then(function (r) {
        preview.innerHTML = r && r.ok ? (r.html || '<span class="muted">Пусто</span>') : '<span class="muted">' + ((r && r.error) || 'Ошибка') + '</span>';
      });
    });
    bar.appendChild(pb);

    // Ctrl+Enter отправляет форму
    ta.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter' && ta.form) {
        ev.preventDefault();
        if (ta.form.requestSubmit) ta.form.requestSubmit(); else ta.form.submit();
      }
      if ((ev.ctrlKey || ev.metaKey) && !ev.shiftKey && !ev.altKey) {
        var k = ev.key.toLowerCase();
        if (k === 'b') { ev.preventDefault(); WRP.wrap(ta, '[b]', '[/b]', 'текст'); }
        if (k === 'i') { ev.preventDefault(); WRP.wrap(ta, '[i]', '[/i]', 'текст'); }
        if (k === 'u') { ev.preventDefault(); WRP.wrap(ta, '[u]', '[/u]', 'текст'); }
      }
    });

    // Черновик в браузере, чтобы не терять текст.
    // Восстанавливается и поверх шаблона темы; удаляется только после успешной отправки
    // (если после отправки на странице есть ошибка - черновик остаётся).
    var key = ta.getAttribute('data-draft');
    if (key) {
      try {
        var saved = localStorage.getItem('draft:' + key);
        if (saved && saved !== ta.value && (ta.value === '' || ta.value === ta.defaultValue) && !document.querySelector('.flash-error, .field-error')) {
          ta.value = saved;
        }
        ta.addEventListener('input', function () {
          if (ta.value === '' || ta.value === ta.defaultValue) localStorage.removeItem('draft:' + key);
          else localStorage.setItem('draft:' + key, ta.value);
        });
        if (ta.form) ta.form.addEventListener('submit', function () { sessionStorage.setItem('draft-sent', key); });
      } catch (e) {}
    }
  }

  // Черновик отправленной формы: ошибки нет - удаляем, есть - оставляем
  try {
    var sent = sessionStorage.getItem('draft-sent');
    if (sent) {
      sessionStorage.removeItem('draft-sent');
      if (!document.querySelector('.flash-error, .field-error')) localStorage.removeItem('draft:' + sent);
    }
  } catch (e) {}

  WRP.initEditor = initEditor;
  document.querySelectorAll('textarea[data-editor]').forEach(initEditor);
})();
