/* Аккаунты: надёжность пароля, предпросмотр аватара и обложки, стена профиля (лайки без перезагрузки) */
(function () {
  'use strict';

  // ---------- Надёжность пароля ----------
  var LEVELS = ['', 'Слабый пароль', 'Так себе', 'Хороший пароль', 'Надёжный пароль'];
  function pwScore(p) {
    if (!p) return 0;
    if (p.length < 8) return 1;
    var kinds = 0;
    if (/[a-zа-яё]/.test(p)) kinds++;
    if (/[A-ZА-ЯЁ]/.test(p)) kinds++;
    if (/\d/.test(p)) kinds++;
    if (/[^A-Za-zА-Яа-яЁё0-9]/.test(p)) kinds++;
    var s = 1;
    if (kinds >= 2) s++;
    if (kinds >= 3 && p.length >= 10) s++;
    if (kinds >= 3 && p.length >= 14) s++;
    if (/^(.)\1+$/.test(p) || /^(?:12345678|password|qwerty|йцукен)/i.test(p)) s = 1;
    return Math.min(4, s);
  }
  document.querySelectorAll('[data-pw-meter]').forEach(function (input) {
    var box = document.getElementById(input.getAttribute('data-pw-meter'));
    if (!box) return;
    var text = box.querySelector('.hint');
    input.addEventListener('input', function () {
      var s = pwScore(input.value);
      box.hidden = !input.value;
      box.className = 'acc-pw-meter lvl-' + s;
      if (text) text.textContent = input.value.length < 8 ? 'Минимум 8 символов' : LEVELS[s];
    });
  });

  // ---------- Файлы: имя, размер, предпросмотр ----------
  document.querySelectorAll('input[type=file][data-file-preview]').forEach(function (input) {
    var label = input.closest('.acc-file');
    var nameEl = label ? label.querySelector('.acc-file-name') : null;
    var kind = input.getAttribute('data-file-preview');
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!f) return;
      var max = parseInt(input.getAttribute('data-max'), 10) || 0;
      if (max && f.size > max) {
        WRP.toast('Файл слишком большой: максимум ' + (Math.round(max / 104857.6) / 10) + ' МБ');
        input.value = '';
        if (nameEl) nameEl.textContent = 'Файл не выбран';
        return;
      }
      if (!/^image\//.test(f.type)) {
        WRP.toast('Выберите картинку (JPG, PNG, WEBP)');
        input.value = '';
        return;
      }
      if (nameEl) nameEl.textContent = f.name;
      var src = URL.createObjectURL(f);
      if (kind === 'avatar') {
        document.querySelectorAll('[data-avatar-preview] .avatar').forEach(function (a) {
          a.classList.remove('avatar-letter');
          a.textContent = '';
          var img = document.createElement('img');
          img.src = src;
          img.alt = '';
          a.appendChild(img);
        });
      } else {
        document.querySelectorAll('[data-cover-preview]').forEach(function (c) {
          c.classList.remove('acc-cover-default');
          var img = c.querySelector('img');
          if (!img) {
            img = document.createElement('img');
            img.alt = '';
            c.appendChild(img);
          }
          img.src = src;
        });
      }
    });
  });

  // ---------- Живой предпросмотр звания и «Откуда» ----------
  document.querySelectorAll('[data-live]').forEach(function (input) {
    var key = input.getAttribute('data-live');
    input.addEventListener('input', function () {
      var v = input.value.trim();
      document.querySelectorAll('[data-live-out="' + key + '"]').forEach(function (o) {
        o.textContent = v || o.getAttribute('data-default') || '';
      });
      document.querySelectorAll('[data-live-wrap="' + key + '"]').forEach(function (w) { w.hidden = !v; });
    });
  });

  // ---------- Счётчик символов ----------
  document.querySelectorAll('textarea[data-count]').forEach(function (ta) {
    var max = parseInt(ta.getAttribute('data-count'), 10);
    var out = document.createElement('div');
    out.className = 'hint';
    var anchor = ta.closest('.editor') || ta;
    anchor.parentNode.insertBefore(out, anchor.nextSibling);
    var upd = function () {
      out.textContent = ta.value.length + ' / ' + max;
      out.style.color = ta.value.length > max ? '#ff7a7a' : '';
    };
    ta.addEventListener('input', upd);
    upd();
  });

  // ---------- Раскрывающиеся поля стены ----------
  function grow(ta) {
    if (!ta.closest('.expanded')) return;
    ta.style.height = 'auto';
    var min = ta.closest('.acc-comment-form') ? 66 : 88;
    ta.style.height = Math.min(360, Math.max(min, ta.scrollHeight + 2)) + 'px';
  }
  document.querySelectorAll('[data-acc-expand]').forEach(function (form) {
    var ta = form.querySelector('.acc-expand-input');
    if (!ta) return;
    ta.addEventListener('focus', function () { form.classList.add('expanded'); grow(ta); });
    ta.addEventListener('input', function () { grow(ta); });
    ta.addEventListener('blur', function () {
      setTimeout(function () {
        if (!ta.value.trim() && !form.contains(document.activeElement)) {
          form.classList.remove('expanded');
          ta.style.height = '';
        }
      }, 150);
    });
    ta.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
        ev.preventDefault();
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
      }
    });
    form.addEventListener('submit', function () {
      var b = form.querySelector('button[type=submit]');
      if (b) setTimeout(function () { b.disabled = true; }, 0);
    });
    if (form.classList.contains('expanded')) grow(ta);
  });
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-acc-comment-focus]');
    if (!t) return;
    var ta = document.getElementById('comment-' + t.getAttribute('data-acc-comment-focus'));
    if (ta) {
      ta.focus();
      ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  // ---------- «Нравится» без перезагрузки ----------
  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('form[data-acc-like]');
    if (!form || !window.fetch) return;
    ev.preventDefault();
    var btn = form.querySelector('button');
    if (btn.disabled) return;
    btn.disabled = true;
    var type = form.querySelector('[name=type]').value;
    var cid = form.querySelector('[name=cid]').value;
    WRP.post(form.getAttribute('action'), { action: 'like', type: type, cid: cid }).then(function (r) {
      btn.disabled = false;
      if (!r || !r.ok) {
        WRP.toast((r && r.error) || 'Не получилось, попробуйте ещё раз');
        return;
      }
      btn.classList.toggle('is-liked', r.liked);
      var label = btn.querySelector('span');
      if (label) label.textContent = r.liked ? 'Не нравится' : 'Нравится';
      var line = document.querySelector('[data-likes="' + type + '-' + cid + '"]');
      if (line) {
        line.innerHTML = r.html || '';
        line.hidden = !r.html;
      }
    }).catch(function () {
      btn.disabled = false;
      WRP.toast('Нет связи с сервером');
    });
  });
})();
