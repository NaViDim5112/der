/* Модуль «Модерация»: окно жалобы и форма предупреждения */
(function () {
  'use strict';

  var WRP = window.WRP || {};
  var ajaxHeaders = { 'X-Requested-With': 'XMLHttpRequest' };

  var plural = function (n, one, few, many) {
    n = Math.abs(n) % 100;
    var n1 = n % 10;
    if (n > 10 && n < 20) return many;
    if (n1 > 1 && n1 < 5) return few;
    if (n1 === 1) return one;
    return many;
  };

  // ---------- Окно жалобы ----------

  var dlg = null;
  var lastLink = null;

  var showError = function (form, text) {
    var box = form.querySelector('.mdr-form-error');
    if (!box) {
      window.alert(text);
      return;
    }
    box.hidden = false;
    var t = box.querySelector('div');
    if (t) t.textContent = text;
  };

  var markSent = function (a) {
    if (!a) return;
    a.classList.add('is-sent');
    var s = a.querySelector('span');
    if (s) s.textContent = 'Жалоба отправлена';
    a.removeAttribute('data-mdr-report');
    a.removeAttribute('href');
  };

  var getDialog = function () {
    if (dlg) return dlg;
    dlg = document.createElement('dialog');
    dlg.className = 'mdr-modal';
    dlg.setAttribute('aria-label', 'Жалоба');
    document.body.appendChild(dlg);

    dlg.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-mdr-close]')) {
        ev.preventDefault();
        dlg.close();
        return;
      }
      // Клик по фону закрывает окно
      if (ev.target === dlg) {
        var r = dlg.getBoundingClientRect();
        if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) dlg.close();
      }
    });

    dlg.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey) && ev.target.tagName === 'TEXTAREA') {
        var f = ev.target.closest('form');
        if (f && f.requestSubmit) {
          ev.preventDefault();
          f.requestSubmit();
        }
      }
    });

    dlg.addEventListener('submit', function (ev) {
      var form = ev.target.closest('[data-mdr-report-form]');
      if (!form) return;
      ev.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: ajaxHeaders })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok) {
            dlg.close();
            if (WRP.toast) WRP.toast(res.message);
            markSent(lastLink);
          } else {
            showError(form, (res && res.error) || 'Не получилось отправить жалобу.');
          }
        })
        .catch(function () { showError(form, 'Нет связи с сервером. Попробуйте ещё раз.'); })
        .then(function () { if (btn) btn.disabled = false; });
    });
    return dlg;
  };

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[data-mdr-report]');
    if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0 || !window.fetch) return;
    var d = getDialog();
    if (typeof d.showModal !== 'function') return;
    ev.preventDefault();
    lastLink = a;
    document.querySelectorAll('.dropdown.open').forEach(function (x) { x.classList.remove('open'); });
    d.innerHTML = '<div class="mdr-modal-loading">Загрузка...</div>';
    if (!d.open) d.showModal();
    var href = a.getAttribute('href');
    fetch(href + (href.indexOf('?') >= 0 ? '&' : '?') + 'modal=1', { credentials: 'same-origin', headers: ajaxHeaders })
      .then(function (r) {
        if (!r.ok) throw new Error('status ' + r.status);
        return r.text();
      })
      .then(function (html) {
        d.innerHTML = html;
        var first = d.querySelector('input[type="radio"]') || d.querySelector('[data-mdr-close]');
        if (first) first.focus();
      })
      .catch(function () { window.location.href = href; });
  });

  // ---------- Выдача предупреждения ----------

  var wf = document.querySelector('[data-mdr-warn]');
  if (wf) {
    var custom = wf.querySelector('[data-mdr-custom]');
    var esc = wf.querySelector('[data-mdr-escalation]');
    var pub = wf.querySelector('[data-public-note]');
    var customPoints = wf.querySelector('[data-custom-points]');
    var rules = [];
    try { rules = JSON.parse(wf.getAttribute('data-rules') || '[]'); } catch (e) { rules = []; }
    var cur = parseInt(wf.getAttribute('data-points'), 10) || 0;

    var update = function (typeChanged) {
      var r = wf.querySelector('input[name="type_id"]:checked');
      var isCustom = !!(r && r.hasAttribute('data-custom'));
      if (custom) custom.hidden = !isCustom;
      var add = 0;
      if (isCustom) {
        add = customPoints ? (parseInt(customPoints.value, 10) || 0) : 0;
      } else if (r) {
        add = parseInt(r.getAttribute('data-points'), 10) || 0;
        if (typeChanged && pub) pub.checked = r.getAttribute('data-public') === '1';
      }
      var total = cur + add;
      var hits = rules.filter(function (x) { return x.points > cur && x.points <= total; }).map(function (x) { return x.text.toLowerCase(); });
      if (esc) {
        var text = 'Сейчас у пользователя ' + cur + ' ' + plural(cur, 'балл', 'балла', 'баллов') + '.';
        if (r) text += ' После предупреждения станет ' + total + '.';
        if (hits.length) text += ' Сработает: ' + hits.join(', ') + '.';
        var span = esc.querySelector('span');
        if (span) span.textContent = text;
        esc.classList.toggle('is-alert', hits.length > 0);
      }
    };
    wf.addEventListener('change', function (ev) { update(ev.target.name === 'type_id'); });
    if (customPoints) customPoints.addEventListener('input', function () { update(false); });
    update(false);
  }
})();
