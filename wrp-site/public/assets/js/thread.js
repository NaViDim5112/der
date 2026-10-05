/* World Role Play: темы и сообщения (реакции, цитаты, окна модерации, свёрнутые сообщения, группы тем) */
(function () {
  'use strict';

  var WRP = window.WRP;
  if (!WRP) return;

  // ---------- Реакции ----------

  function closePickers(except) {
    document.querySelectorAll('.react-wrap.open').forEach(function (w) {
      if (w !== except) w.classList.remove('open');
    });
  }

  function react(wrap, reaction) {
    var btn = wrap.querySelector('[data-react]');
    if (!btn || btn.classList.contains('busy')) return;
    var id = btn.getAttribute('data-react');
    btn.classList.add('busy');
    wrap.classList.remove('open');
    WRP.post('/forum/post.php?action=react', { id: id, reaction: reaction || '' }).then(function (r) {
      btn.classList.remove('busy');
      if (!r || !r.ok) {
        if (r && r.login) {
          window.location.href = r.login;
          return;
        }
        WRP.toast((r && r.error) || 'Не удалось поставить реакцию');
        return;
      }
      btn.innerHTML = r.button;
      btn.classList.toggle('is-reacted', !!r.my);
      wrap.querySelectorAll('[data-react-pick]').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-react-pick') === r.my);
      });
      var post = wrap.closest('.post');
      var bar = post ? post.querySelector('.reactions-bar') : null;
      if (bar) {
        bar.innerHTML = r.html || '';
        bar.hidden = !r.html;
      }
    }).catch(function () {
      btn.classList.remove('busy');
      WRP.toast('Ошибка сети, попробуйте ещё раз');
    });
  }

  // Долгое нажатие на телефоне открывает выбор реакции
  var pressTimer = null;
  var longPressed = false;
  document.addEventListener('touchstart', function (ev) {
    var btn = ev.target.closest('[data-react]');
    if (!btn) return;
    longPressed = false;
    pressTimer = setTimeout(function () {
      longPressed = true;
      var wrap = btn.closest('.react-wrap');
      closePickers(wrap);
      wrap.classList.add('open');
    }, 450);
  }, { passive: true });
  ['touchend', 'touchmove', 'touchcancel'].forEach(function (name) {
    document.addEventListener(name, function () {
      clearTimeout(pressTimer);
    }, { passive: true });
  });
  document.addEventListener('contextmenu', function (ev) {
    if (ev.target.closest('[data-react]') && longPressed) ev.preventDefault();
  });

  document.addEventListener('click', function (ev) {
    var pick = ev.target.closest('[data-react-pick]');
    if (pick) {
      ev.preventDefault();
      react(pick.closest('.react-wrap'), pick.getAttribute('data-react-pick'));
      return;
    }
    var btn = ev.target.closest('[data-react]');
    if (btn) {
      ev.preventDefault();
      if (longPressed) {
        longPressed = false;
        return;
      }
      react(btn.closest('.react-wrap'), '');
      return;
    }
    if (!ev.target.closest('.react-wrap')) closePickers(null);
  });

  // Список всех, кто отреагировал
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-react-list]');
    if (!t) return;
    ev.preventDefault();
    var bar = t.closest('.reactions-bar');
    var list = bar ? bar.querySelector('.react-list') : null;
    if (list) list.hidden = !list.hidden;
  });

  // ---------- Цитата в быстрый ответ ----------

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-quote]');
    if (!b) return;
    ev.preventDefault();
    var ta = document.querySelector('.qr-form textarea[name="body"]');
    if (!ta) {
      WRP.toast('Ответить в этой теме нельзя');
      return;
    }
    b.classList.add('busy');
    fetch(WRP.url('/forum/post.php?action=quote&id=' + encodeURIComponent(b.getAttribute('data-quote'))), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.json();
    }).then(function (r) {
      b.classList.remove('busy');
      if (!r || !r.ok) {
        WRP.toast((r && r.error) || 'Не удалось получить цитату');
        return;
      }
      var ed = ta.closest('.editor');
      if (ed && ed.classList.contains('previewing')) {
        var pb = ed.querySelector('.editor-preview-btn');
        if (pb) pb.click();
      }
      WRP.insert(ta, r.bbcode);
    }).catch(function () {
      b.classList.remove('busy');
      WRP.toast('Ошибка сети, попробуйте ещё раз');
    });
  });

  // ---------- Свёрнутые сообщения (удалённые, от игнорируемых) ----------

  function toggleCollapsed(box, show) {
    var body = box.querySelector('.post-collapsed-body');
    var btn = box.querySelector('[data-collapse-toggle]');
    if (!body) return;
    body.hidden = show === undefined ? !body.hidden : !show;
    if (btn) {
      var label = btn.querySelector('span');
      if (label) label.textContent = body.hidden ? 'Показать' : 'Скрыть';
    }
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-collapse-toggle]');
    if (!t) return;
    ev.preventDefault();
    toggleCollapsed(t.closest('.post-collapsed'));
  });

  // ---------- Окна модерации ----------

  document.addEventListener('click', function (ev) {
    var open = ev.target.closest('[data-dialog-open]');
    if (open) {
      ev.preventDefault();
      var dlg = document.getElementById(open.getAttribute('data-dialog-open'));
      document.querySelectorAll('.dropdown.open').forEach(function (d) { d.classList.remove('open'); });
      if (dlg && dlg.showModal) {
        dlg.showModal();
        var f = dlg.querySelector('input:not([type="hidden"]), select');
        if (f) f.focus();
      }
      return;
    }
    var close = ev.target.closest('[data-dialog-close]');
    if (close) {
      ev.preventDefault();
      var d = close.closest('dialog');
      if (d) d.close();
      return;
    }
    // Клик по фону окна закрывает его
    if (ev.target.tagName === 'DIALOG' && ev.target.classList.contains('modal')) {
      var r = ev.target.getBoundingClientRect();
      if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) ev.target.close();
    }
  });

  // ---------- Группы тем («Закреплено») ----------

  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };

  document.querySelectorAll('.trow-group[data-group]').forEach(function (g) {
    if (store.get('wrp_grp_' + g.getAttribute('data-group')) === '1') g.classList.add('collapsed');
  });
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-group-toggle]');
    if (!t) return;
    var g = t.closest('.trow-group');
    g.classList.toggle('collapsed');
    if (g.getAttribute('data-group')) store.set('wrp_grp_' + g.getAttribute('data-group'), g.classList.contains('collapsed') ? '1' : '0');
  });

  // ---------- Черновик новой темы поверх шаблона раздела ----------

  document.querySelectorAll('textarea[data-template-default][data-draft]').forEach(function (ta) {
    var draft = store.get('draft:' + ta.getAttribute('data-draft'));
    if (draft && draft.trim() !== '' && draft !== ta.value) {
      ta.value = draft;
      WRP.toast('Восстановлен черновик темы');
    }
  });

  // ---------- Подсветка сообщения по ссылке #post-N ----------

  function highlight() {
    var m = /^#post-(\d+)$/.exec(window.location.hash);
    if (!m) return;
    var el = document.getElementById('post-' + m[1]);
    if (!el) return;
    el.classList.remove('post-target');
    void el.offsetWidth;
    el.classList.add('post-target');
  }
  highlight();
  window.addEventListener('hashchange', highlight);
})();
