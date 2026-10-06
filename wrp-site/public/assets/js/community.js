/* World Role Play: модуль «Сообщество» - QR-код 2FA, закрытие плашек, резервные коды, мелочи админки */
(function () {
  'use strict';

  var WRP = window.WRP || {};
  var toast = function (t) { if (WRP.toast) WRP.toast(t); };

  // QR-код для приложения 2FA (библиотека qrcode-generator, MIT)
  document.querySelectorAll('[data-com-qr]').forEach(function (el) {
    if (typeof window.qrcode !== 'function') return;
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(el.getAttribute('data-com-qr'));
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true, alt: 'QR-код для приложения' });
    } catch (e) {
      el.innerHTML = '<span class="muted small">QR-код не получился - введите ключ вручную.</span>';
    }
  });

  // Плашки на сайте лежат поверх первого экрана: передаём их высоту в CSS
  var siteNotices = function () {
    var box = document.querySelector('.com-notices-site');
    var h = box && box.querySelector('.com-notice') ? box.offsetHeight + 14 : 0;
    document.documentElement.style.setProperty('--com-snh', h + 'px');
  };
  if (document.querySelector('.com-notices-site')) {
    siteNotices();
    window.addEventListener('resize', siteNotices);
    window.addEventListener('load', siteNotices);
  }

  // Закрыть плашку без перезагрузки (без JS форма отправится обычным POST)
  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest('form[data-notice-dismiss]');
    if (!f || !window.fetch || !WRP.post) return;
    ev.preventDefault();
    var box = f.closest('.com-notice');
    var data = {};
    f.querySelectorAll('input[type="hidden"]').forEach(function (i) { if (i.name !== '_token') data[i.name] = i.value; });
    if (box) box.classList.add('is-hiding');
    WRP.post(f.getAttribute('action'), data).then(function (r) {
      if (r && r.ok) {
        setTimeout(function () {
          var wrap = box && box.parentNode;
          if (box) box.remove();
          if (wrap && !wrap.querySelector('.com-notice')) wrap.remove();
          siteNotices();
        }, 200);
      } else {
        if (box) box.classList.remove('is-hiding');
        toast((r && r.error) || 'Не получилось скрыть объявление');
      }
    }).catch(function () {
      if (box) box.classList.remove('is-hiding');
    });
  });

  // Резервные коды: скачать .txt и скопировать
  document.addEventListener('click', function (ev) {
    var d = ev.target.closest('[data-com-download]');
    if (d) {
      var blob = new Blob([d.getAttribute('data-com-download')], { type: 'text/plain;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = d.getAttribute('data-filename') || 'codes.txt';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
      return;
    }
    var c = ev.target.closest('[data-com-copy]');
    if (c) {
      var text = c.getAttribute('data-com-copy');
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () { toast('Коды скопированы'); });
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); toast('Коды скопированы'); } catch (e) { /* нет доступа к буферу */ }
        ta.remove();
      }
    }
  });

  // Сколько осталось на ввод кода 2FA
  var cd = document.querySelector('[data-com-countdown]');
  if (cd) {
    var left = parseInt(cd.getAttribute('data-com-countdown'), 10) || 0;
    var tick = function () {
      if (left <= 0) {
        cd.textContent = 'Время вышло - нажмите «Отмена» и войдите заново.';
        return;
      }
      var m = Math.floor(left / 60);
      var s = left % 60;
      cd.textContent = 'Осталось ' + m + ':' + (s < 10 ? '0' : '') + s;
      left--;
      setTimeout(tick, 1000);
    };
    tick();
  }

  // Админка: поле «Сколько нужно» только для автоматических наград
  var crit = document.querySelector('[data-com-criteria]');
  var critVal = document.querySelector('[data-com-criteria-value]');
  if (crit && critVal) {
    var syncCrit = function () { critVal.hidden = crit.value === 'manual' || crit.value === 'game_linked'; };
    crit.addEventListener('change', syncCrit);
    syncCrit();
  }

  // Админка: варианты списка только для поля «Выбор из списка»
  var ftype = document.querySelector('[data-com-ftype]');
  var fopts = document.querySelector('[data-com-options]');
  if (ftype && fopts) {
    var syncType = function () { fopts.hidden = ftype.value !== 'select'; };
    ftype.addEventListener('change', syncType);
    syncType();
  }

  // Админка: живой предпросмотр значка награды (цвет и иконка)
  var medal = document.querySelector('.com-adm-preview .com-medal');
  if (medal) {
    document.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.name === 'color' && t.checked) {
        medal.className = medal.className.replace(/\bcom-c-[a-z]+\b/, 'com-c-' + t.value);
      }
      if (t.name === 'icon' && t.checked) {
        var svg = t.parentNode.querySelector('svg');
        if (svg) medal.innerHTML = svg.outerHTML;
      }
    });
  }
})();
