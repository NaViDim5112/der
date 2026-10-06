/* World Role Play: модуль «Контент» - загрузка картинок в редактор, просмотр картинок на весь экран,
   опросы, закладки, упоминания @ник, мультицитата, уведомление о восстановленном черновике */
(function () {
  'use strict';

  var WRP = window.WRP;
  if (!WRP) return;

  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) {} }
  };

  function el(tag, cls, html) {
    var d = document.createElement(tag);
    if (cls) d.className = cls;
    if (html != null) d.innerHTML = html;
    return d;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var svg = function (p) {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  };
  var I = {
    upload: svg('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>'),
    x: svg('<path d="M18 6 6 18M6 6l12 12"/>'),
    left: svg('<path d="m15 18-6-6 6-6"/>'),
    right: svg('<path d="m9 18 6-6-6-6"/>'),
    external: svg('<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'),
    zoom: svg('<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3M11 8v6M8 11h6"/>'),
    check: svg('<path d="M20 6 9 17l-5-5"/>'),
    alert: svg('<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>'),
    draft: svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>')
  };

  // Отправка формы из JS, ответ JSON. form.action не трогаем: у форм есть поле name="action".
  function sendForm(form) {
    var fd = new FormData(form);
    if (!fd.has('_token')) fd.append('_token', WRP.csrf);
    return fetch(form.getAttribute('action'), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': WRP.csrf }
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Ошибка сервера (' + r.status + ')' }; });
    }).catch(function () {
      return { ok: false, error: 'Ошибка сети, попробуйте ещё раз.' };
    });
  }

  function fail(r, fallback) {
    if (r && r.login) {
      window.location.href = r.login;
      return;
    }
    WRP.toast((r && r.error) || fallback || 'Не получилось, попробуйте ещё раз');
  }

  // Вставка текста в позицию курсора (с поддержкой Ctrl+Z)
  function insertAt(ta, text) {
    var ed = ta.closest('.editor');
    if (ed && ed.classList.contains('previewing')) {
      var pb = ed.querySelector('.editor-preview-btn');
      if (pb) pb.click();
    }
    // В поле ещё не ставили курсор (например, правка сообщения) - вставляем в конец текста
    if (!ta.getAttribute('data-cnt-touched')) ta.selectionStart = ta.selectionEnd = ta.value.length;
    ta.focus();
    var s = ta.selectionStart, e = ta.selectionEnd;
    if (s > 0 && ta.value.charAt(s - 1) !== '\n') text = '\n' + text;
    if (!document.execCommand || !document.execCommand('insertText', false, text)) {
      ta.setRangeText(text, s, e, 'end');
    }
    ta.dispatchEvent(new Event('input'));
  }

  // ======================= Загрузка картинок =======================

  var TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  var ACCEPT = TYPES.join(',');

  function uploadCfg(marker) {
    return { max: +marker.getAttribute('data-max') || 0, maxText: marker.getAttribute('data-max-text') || '' };
  }

  function uploadFile(file, cfg, onProgress) {
    return new Promise(function (resolve) {
      if (TYPES.indexOf(file.type) < 0 && !/\.(jpe?g|png|gif|webp)$/i.test(file.name || '')) {
        resolve({ ok: false, error: 'Можно загружать только картинки JPG, PNG, GIF или WEBP.' });
        return;
      }
      if (cfg.max && file.size > cfg.max) {
        resolve({ ok: false, error: 'Файл слишком большой. Максимум ' + cfg.maxText + '.' });
        return;
      }
      var fd = new FormData();
      fd.append('_token', WRP.csrf);
      fd.append('file', file, file.name || 'screenshot.png');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', WRP.url('/forum/upload.php'));
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('X-CSRF-Token', WRP.csrf);
      if (xhr.upload && onProgress) {
        xhr.upload.onprogress = function (ev) {
          if (ev.lengthComputable) onProgress(ev.loaded / ev.total);
        };
      }
      xhr.onload = function () {
        var r = null;
        try { r = JSON.parse(xhr.responseText); } catch (e) {}
        if (!r) {
          r = { ok: false, error: xhr.status === 413 ? 'Файл слишком большой. Максимум ' + cfg.maxText + '.' : 'Ошибка сервера (' + xhr.status + ').' };
        }
        resolve(r);
      };
      xhr.onerror = function () { resolve({ ok: false, error: 'Ошибка сети, попробуйте ещё раз.' }); };
      xhr.send(fd);
    });
  }

  // Картинки из перетаскивания или буфера обмена
  function imageFiles(list) {
    var out = [];
    Array.prototype.forEach.call(list || [], function (f) {
      if (f && (TYPES.indexOf(f.type) >= 0 || /^image\//.test(f.type))) out.push(f);
    });
    return out;
  }

  function hasFiles(ev) {
    var dt = ev.dataTransfer;
    return dt && Array.prototype.indexOf.call(dt.types || [], 'Files') >= 0;
  }

  // Очередь: файлы уходят по одному, чтобы картинки вставлялись по порядку
  function makeQueue(handler) {
    var q = [], busy = false;
    function next() {
      if (busy || !q.length) return;
      busy = true;
      handler(q.shift()).then(function () { busy = false; next(); });
    }
    return function (files) {
      files.forEach(function (f) { q.push(f); });
      next();
    };
  }

  function setupEditorUpload(ta, cfg) {
    var wrap = ta.closest('.editor');
    if (!wrap || wrap.getAttribute('data-cnt-upload')) return;
    wrap.setAttribute('data-cnt-upload', '1');
    wrap.classList.add('cnt-has-upload');
    ['mousedown', 'keydown', 'touchstart'].forEach(function (n) {
      ta.addEventListener(n, function () { ta.setAttribute('data-cnt-touched', '1'); }, { passive: true });
    });
    var bar = wrap.querySelector('.editor-toolbar');

    var input = el('input');
    input.type = 'file';
    input.accept = ACCEPT;
    input.multiple = true;
    input.hidden = true;
    wrap.appendChild(input);

    var btn = el('button', 'editor-btn cnt-up-btn', I.upload);
    btn.type = 'button';
    btn.title = 'Загрузить картинку с компьютера';
    var imgBtn = null;
    if (bar) {
      bar.querySelectorAll('.editor-btn').forEach(function (b) {
        if (b.title === 'Картинка по ссылке') imgBtn = b;
      });
      if (imgBtn && imgBtn.nextSibling) bar.insertBefore(btn, imgBtn.nextSibling);
      else bar.insertBefore(btn, bar.querySelector('.editor-spacer'));
    }

    var drop = el('div', 'cnt-up-drop', '<div>' + I.upload + '<b>Отпустите, чтобы загрузить</b></div>');
    wrap.appendChild(drop);

    var foot = el('div', 'cnt-up-foot');
    var hint = el('div', 'cnt-up-hint', I.upload + '<span>Перетащите картинку сюда, вставьте из буфера (Ctrl+V) или <button type="button" class="cnt-link">выберите файл</button>. JPG, PNG, GIF, WEBP до ' + esc(cfg.maxText) + '.</span>');
    var chips = el('div', 'cnt-up-chips');
    foot.appendChild(hint);
    foot.appendChild(chips);
    wrap.appendChild(foot);

    var enqueue = makeQueue(function (file) {
      var chip = el('div', 'cnt-up-chip is-loading');
      var local = '';
      try { local = URL.createObjectURL(file); } catch (e) {}
      chip.innerHTML = '<span class="cnt-up-thumb">' + (local ? '<img src="' + local + '" alt="">' : '') + '</span>'
        + '<span class="cnt-up-info"><span class="cnt-up-name">' + esc(file.name || 'Картинка') + '</span>'
        + '<span class="cnt-up-state">Загрузка...</span><span class="cnt-up-progress"><span></span></span></span>'
        + '<button type="button" class="cnt-up-x" title="Скрыть">' + I.x + '</button>';
      chips.appendChild(chip);
      var bar2 = chip.querySelector('.cnt-up-progress span');
      return uploadFile(file, cfg, function (p) { bar2.style.width = Math.round(p * 100) + '%'; }).then(function (r) {
        chip.classList.remove('is-loading');
        var state = chip.querySelector('.cnt-up-state');
        if (!r || !r.ok) {
          chip.classList.add('is-error');
          state.textContent = (r && r.error) || 'Ошибка загрузки';
          WRP.toast((r && r.error) || 'Ошибка загрузки');
          return;
        }
        chip.classList.add('is-done');
        chip.querySelector('.cnt-up-thumb').innerHTML = '<img src="' + esc(r.thumb) + '" alt="">';
        state.innerHTML = I.check + ' Вставлено · ' + esc(r.size_text) + ' · ' + r.width + 'x' + r.height;
        chip.setAttribute('data-bbcode', r.bbcode);
        chip.title = 'Нажмите, чтобы вставить ещё раз';
        insertAt(ta, r.bbcode + '\n');
      }).then(function () {
        if (local) setTimeout(function () { URL.revokeObjectURL(local); }, 3000);
      });
    });

    btn.addEventListener('click', function (ev) { ev.preventDefault(); input.click(); });
    hint.querySelector('.cnt-link').addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      var files = imageFiles(input.files);
      if (!files.length && input.files.length) WRP.toast('Можно загружать только картинки JPG, PNG, GIF или WEBP.');
      enqueue(files);
      input.value = '';
    });
    chips.addEventListener('click', function (ev) {
      var x = ev.target.closest('.cnt-up-x');
      if (x) {
        x.closest('.cnt-up-chip').remove();
        return;
      }
      var chip = ev.target.closest('.cnt-up-chip.is-done');
      if (chip) insertAt(ta, chip.getAttribute('data-bbcode') + '\n');
    });

    var depth = 0;
    wrap.addEventListener('dragenter', function (ev) {
      if (!hasFiles(ev)) return;
      ev.preventDefault();
      depth++;
      wrap.classList.add('is-drag');
    });
    wrap.addEventListener('dragover', function (ev) {
      if (!hasFiles(ev)) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'copy';
    });
    wrap.addEventListener('dragleave', function () {
      depth = Math.max(0, depth - 1);
      if (!depth) wrap.classList.remove('is-drag');
    });
    wrap.addEventListener('drop', function (ev) {
      if (!hasFiles(ev)) return;
      ev.preventDefault();
      depth = 0;
      wrap.classList.remove('is-drag');
      var files = imageFiles(ev.dataTransfer.files);
      if (!files.length) {
        WRP.toast('Можно загружать только картинки JPG, PNG, GIF или WEBP.');
        return;
      }
      enqueue(files);
    });
    ta.addEventListener('paste', function (ev) {
      var cd = ev.clipboardData;
      if (!cd) return;
      var files = imageFiles(cd.files);
      if (!files.length && cd.items) {
        Array.prototype.forEach.call(cd.items, function (it) {
          if (it.kind === 'file' && /^image\//.test(it.type)) {
            var f = it.getAsFile();
            if (f) files.push(f);
          }
        });
      }
      if (!files.length) return;
      ev.preventDefault();
      enqueue(files);
    });
  }

  // Поле-ссылка анкеты (доказательства): кнопка «Загрузить» рядом, в поле - полный адрес картинки
  function setupUrlUpload(input, cfg) {
    if (input.getAttribute('data-cnt-upload')) return;
    input.setAttribute('data-cnt-upload', '1');
    var row = el('div', 'cnt-url-row');
    input.parentNode.insertBefore(row, input);
    row.appendChild(input);
    var btn = el('button', 'btn btn-black cnt-url-btn', I.upload + '<span>Загрузить</span>');
    btn.type = 'button';
    btn.title = 'Загрузить скриншот с компьютера (JPG, PNG, GIF, WEBP до ' + cfg.maxText + ')';
    var file = el('input');
    file.type = 'file';
    file.accept = ACCEPT;
    file.hidden = true;
    row.appendChild(btn);
    row.appendChild(file);
    var status = el('div', 'cnt-url-status');
    status.hidden = true;
    row.parentNode.insertBefore(status, row.nextSibling);

    function go(f) {
      btn.disabled = true;
      btn.classList.add('busy');
      status.hidden = false;
      status.className = 'cnt-url-status';
      status.innerHTML = '<span class="cnt-up-progress"><span></span></span><span>Загрузка...</span>';
      var pb = status.querySelector('.cnt-up-progress span');
      return uploadFile(f, cfg, function (p) { pb.style.width = Math.round(p * 100) + '%'; }).then(function (r) {
        btn.disabled = false;
        btn.classList.remove('busy');
        if (!r || !r.ok) {
          status.className = 'cnt-url-status is-error';
          status.innerHTML = I.alert + '<span>' + esc((r && r.error) || 'Ошибка загрузки') + '</span>';
          return;
        }
        input.value = r.abs;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        status.className = 'cnt-url-status is-done';
        status.innerHTML = '<a href="' + esc(r.url) + '" target="_blank" rel="noopener"><img src="' + esc(r.thumb) + '" alt=""></a><span>' + I.check + ' Скриншот загружен · ' + esc(r.size_text) + '</span>';
      });
    }
    btn.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
      var f = imageFiles(file.files)[0];
      if (f) go(f);
      else if (file.files.length) WRP.toast('Можно загружать только картинки JPG, PNG, GIF или WEBP.');
      file.value = '';
    });
    input.addEventListener('paste', function (ev) {
      var f = ev.clipboardData ? imageFiles(ev.clipboardData.files)[0] : null;
      if (!f) return;
      ev.preventDefault();
      go(f);
    });
    row.addEventListener('dragover', function (ev) {
      if (hasFiles(ev)) { ev.preventDefault(); row.classList.add('is-drag'); }
    });
    row.addEventListener('dragleave', function () { row.classList.remove('is-drag'); });
    row.addEventListener('drop', function (ev) {
      if (!hasFiles(ev)) return;
      ev.preventDefault();
      row.classList.remove('is-drag');
      var f = imageFiles(ev.dataTransfer.files)[0];
      if (f) go(f);
    });
  }

  document.querySelectorAll('[data-cnt-upload][data-max]').forEach(function (marker) {
    var form = marker.closest('form');
    if (!form) return;
    var cfg = uploadCfg(marker);
    form.querySelectorAll('textarea[data-editor]').forEach(function (ta) {
      if (WRP.initEditor && !ta.dataset.ready) WRP.initEditor(ta);
      setupEditorUpload(ta, cfg);
    });
    form.querySelectorAll('.xf-input input[type="url"]').forEach(function (inp) { setupUrlUpload(inp, cfg); });
  });

  // ======================= Просмотр картинок =======================

  var LB = null;

  function lbEligible(img) {
    if (img.closest('a, .editor, .post-sig, .cnt-lb')) return false;
    return !(img.complete && img.naturalWidth > 0 && img.naturalWidth < 40 && img.naturalHeight < 40);
  }

  function lbBuild() {
    var box = el('div', 'cnt-lb');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Просмотр картинки');
    box.tabIndex = -1;
    box.hidden = true;
    box.innerHTML = '<div class="cnt-lb-stage"><img class="cnt-lb-img" alt=""></div>'
      + '<div class="cnt-lb-top"><span class="cnt-lb-count"></span><span class="cnt-lb-tools">'
      + '<button type="button" class="cnt-lb-btn" data-lb="zoom" title="Увеличить (клик по картинке)">' + I.zoom + '</button>'
      + '<a class="cnt-lb-btn" data-lb="orig" target="_blank" rel="noopener" title="Открыть оригинал в новой вкладке">' + I.external + '</a>'
      + '<button type="button" class="cnt-lb-btn" data-lb="close" title="Закрыть (Esc)">' + I.x + '</button></span></div>'
      + '<button type="button" class="cnt-lb-nav cnt-lb-prev" data-lb="prev" title="Предыдущая (стрелка влево)">' + I.left + '</button>'
      + '<button type="button" class="cnt-lb-nav cnt-lb-next" data-lb="next" title="Следующая (стрелка вправо)">' + I.right + '</button>';
    document.body.appendChild(box);
    var st = { box: box, stage: box.querySelector('.cnt-lb-stage'), img: box.querySelector('.cnt-lb-img'), list: [], i: 0 };

    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-lb]');
      if (b) {
        var a = b.getAttribute('data-lb');
        if (a === 'orig') return;
        ev.preventDefault();
        if (a === 'close') lbClose();
        if (a === 'prev') lbShow(st.i - 1);
        if (a === 'next') lbShow(st.i + 1);
        if (a === 'zoom') lbZoom();
        return;
      }
      if (ev.target === st.img) {
        lbZoom(ev);
        return;
      }
      if (ev.target === st.stage || ev.target === box) lbClose();
    });

    // Свайпы: влево/вправо - листать, вниз - закрыть
    var t0 = null;
    st.stage.addEventListener('touchstart', function (ev) {
      if (ev.touches.length !== 1 || box.classList.contains('is-zoomed')) { t0 = null; return; }
      t0 = { x: ev.touches[0].clientX, y: ev.touches[0].clientY, t: Date.now() };
    }, { passive: true });
    st.stage.addEventListener('touchend', function (ev) {
      if (!t0 || !ev.changedTouches.length) return;
      var dx = ev.changedTouches[0].clientX - t0.x, dy = ev.changedTouches[0].clientY - t0.y;
      var quick = Date.now() - t0.t < 800;
      t0 = null;
      if (!quick) return;
      if (Math.abs(dx) > 50 && Math.abs(dy) < 80) lbShow(st.i + (dx < 0 ? 1 : -1));
      else if (dy > 90 && Math.abs(dx) < 60) lbClose();
    }, { passive: true });

    st.img.addEventListener('load', function () {
      box.classList.remove('is-loading');
      var big = st.img.naturalWidth > st.stage.clientWidth || st.img.naturalHeight > st.stage.clientHeight;
      box.classList.toggle('can-zoom', big);
    });
    st.img.addEventListener('error', function () { box.classList.remove('is-loading'); });
    return st;
  }

  function lbShow(i) {
    var st = LB;
    var n = st.list.length;
    if (!n) return;
    i = (i + n) % n;
    st.i = i;
    var src = st.list[i].currentSrc || st.list[i].src;
    st.box.classList.remove('is-zoomed', 'can-zoom');
    st.box.classList.add('is-loading');
    st.img.src = src;
    st.box.querySelector('[data-lb="orig"]').href = src;
    st.box.querySelector('.cnt-lb-count').textContent = n > 1 ? (i + 1) + ' / ' + n : '';
    st.box.classList.toggle('is-single', n < 2);
    // Соседние картинки заранее
    [i - 1, i + 1].forEach(function (k) {
      if (n > 1) (new Image()).src = st.list[(k + n) % n].src;
    });
  }

  function lbZoom(ev) {
    var st = LB;
    if (!st.box.classList.contains('can-zoom') && !st.box.classList.contains('is-zoomed')) return;
    var on = !st.box.classList.contains('is-zoomed');
    var rx = 0.5, ry = 0.5;
    if (on && ev && ev.clientX != null) {
      var r = st.img.getBoundingClientRect();
      rx = (ev.clientX - r.left) / r.width;
      ry = (ev.clientY - r.top) / r.height;
    }
    st.box.classList.toggle('is-zoomed', on);
    if (on) {
      st.stage.scrollLeft = Math.max(0, st.img.naturalWidth * rx - st.stage.clientWidth / 2);
      st.stage.scrollTop = Math.max(0, st.img.naturalHeight * ry - st.stage.clientHeight / 2);
    }
  }

  function lbOpen(list, img) {
    if (!LB) LB = lbBuild();
    LB.list = list;
    LB.box.hidden = false;
    document.documentElement.classList.add('cnt-lb-open');
    lbShow(Math.max(0, list.indexOf(img)));
    LB.box.focus({ preventScroll: true });
  }

  function lbClose() {
    if (!LB || LB.box.hidden) return;
    LB.box.hidden = true;
    LB.img.removeAttribute('src');
    document.documentElement.classList.remove('cnt-lb-open');
  }

  document.addEventListener('click', function (ev) {
    var img = ev.target.closest ? ev.target.closest('img.bb-img') : null;
    if (!img || !lbEligible(img) || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button) return;
    ev.preventDefault();
    var scope = img.closest('.post-body, .wiki-body, .bb') || document.body;
    var list = Array.prototype.filter.call(scope.querySelectorAll('img.bb-img'), lbEligible);
    lbOpen(list.length ? list : [img], img);
  });

  document.addEventListener('keydown', function (ev) {
    if (!LB || LB.box.hidden) return;
    if (ev.key === 'Escape') { ev.preventDefault(); lbClose(); }
    else if (ev.key === 'ArrowLeft') { ev.preventDefault(); lbShow(LB.i - 1); }
    else if (ev.key === 'ArrowRight') { ev.preventDefault(); lbShow(LB.i + 1); }
  });

  // ======================= Опросы =======================

  function pollView(box, view) {
    box.querySelectorAll('[data-poll-pane]').forEach(function (p) {
      p.hidden = p.getAttribute('data-poll-pane') !== view;
    });
  }

  document.addEventListener('click', function (ev) {
    var v = ev.target.closest('[data-poll-view]');
    if (v) {
      ev.preventDefault();
      document.querySelectorAll('.dropdown.open').forEach(function (d) { d.classList.remove('open'); });
      pollView(v.closest('.cnt-poll'), v.getAttribute('data-poll-view'));
      return;
    }
    var add = ev.target.closest('[data-poll-add]');
    if (add) {
      ev.preventDefault();
      var wrap = add.closest('.cnt-poll-fields').querySelector('[data-poll-opts]');
      var n = wrap.querySelectorAll('input').length;
      var max = +wrap.getAttribute('data-max') || 10;
      if (n < max) {
        var inp = el('input', 'input');
        inp.name = 'poll[options][]';
        inp.maxLength = 150;
        inp.placeholder = 'Вариант ' + (n + 1);
        wrap.appendChild(inp);
        inp.focus();
      }
      if (n + 1 >= max) add.hidden = true;
    }
  });

  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest ? ev.target.closest('form[data-poll-ajax]') : null;
    if (!f || ev.defaultPrevented) return;
    ev.preventDefault();
    var box = f.closest('.cnt-poll');
    var btn = f.querySelector('[type="submit"]');
    if (btn) btn.classList.add('busy');
    sendForm(f).then(function (r) {
      if (btn) btn.classList.remove('busy');
      if (!r || !r.ok) {
        fail(r, 'Не удалось сохранить');
        return;
      }
      if (!r.html) {
        box.remove();
        WRP.toast(r.message || 'Готово');
        return;
      }
      var tmp = el('div', '', r.html);
      var fresh = tmp.firstElementChild;
      if (fresh && box) box.parentNode.replaceChild(fresh, box);
    });
  });

  // Админка: список разделов для опросов виден только в режиме «Только в выбранных»
  document.addEventListener('change', function (ev) {
    var r = ev.target;
    if (!r.matches || !r.matches('[data-poll-mode]')) return;
    var box = document.querySelector('[data-poll-nodes]');
    if (box) box.hidden = r.value !== 'list';
  });

  // ======================= Закладки =======================

  function bmSet(postId, on) {
    document.querySelectorAll('.cnt-bm-btn[data-bm="' + postId + '"]').forEach(function (b) {
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.title = on ? 'Убрать из закладок' : 'Добавить в закладки';
    });
  }

  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f.matches || ev.defaultPrevented) return;
    if (f.matches('form.cnt-bm-form')) {
      ev.preventDefault();
      var btn = f.querySelector('.cnt-bm-btn');
      if (btn.classList.contains('busy')) return;
      btn.classList.add('busy');
      sendForm(f).then(function (r) {
        btn.classList.remove('busy');
        if (!r || !r.ok) { fail(r); return; }
        bmSet(btn.getAttribute('data-bm'), !!r.on);
        WRP.toast(r.message);
      });
    } else if (f.matches('form[data-bm-remove]')) {
      ev.preventDefault();
      sendForm(f).then(function (r) {
        if (!r || !r.ok) { fail(r); return; }
        var item = f.closest('.cnt-bm-item');
        if (item) {
          item.classList.add('is-removed');
          setTimeout(function () { item.remove(); }, 250);
        }
        WRP.toast('Закладка удалена');
      });
    } else if (f.matches('form[data-bm-note]')) {
      ev.preventDefault();
      sendForm(f).then(function (r) {
        if (!r || !r.ok) { fail(r); return; }
        WRP.toast(r.message || 'Сохранено');
      });
    }
  });

  // ======================= Мультицитата =======================

  function mqKey(tid) { return 'wrp_mq_' + tid; }

  function mqGet(tid) {
    try {
      var a = JSON.parse(store.get(mqKey(tid)) || '[]');
      return Array.isArray(a) ? a.map(String).filter(function (s) { return /^\d{1,10}$/.test(s); }) : [];
    } catch (e) {
      return [];
    }
  }

  function mqRefresh(tid) {
    var list = mqGet(tid);
    document.querySelectorAll('[data-mq][data-mq-thread="' + tid + '"]').forEach(function (b) {
      var on = list.indexOf(b.getAttribute('data-mq')) >= 0;
      b.classList.toggle('is-on', on);
      var s = b.querySelector('span');
      if (s) s.textContent = on ? 'В цитатах' : 'Цитата';
      b.title = on ? 'Убрать из мультицитаты' : 'Добавить в мультицитату';
    });
    document.querySelectorAll('[data-mq-bar][data-mq-thread="' + tid + '"]').forEach(function (bar) {
      bar.hidden = !list.length;
      var c = bar.querySelector('[data-mq-count]');
      if (c) c.textContent = list.length;
    });
  }

  function mqSet(tid, list) {
    if (list.length) store.set(mqKey(tid), JSON.stringify(list.slice(0, 30)));
    else store.del(mqKey(tid));
    mqRefresh(tid);
  }

  var mqThreads = {};
  document.querySelectorAll('[data-mq-thread]').forEach(function (n) { mqThreads[n.getAttribute('data-mq-thread')] = 1; });
  Object.keys(mqThreads).forEach(mqRefresh);
  window.addEventListener('storage', function (ev) {
    if (ev.key && ev.key.indexOf('wrp_mq_') === 0 && mqThreads[ev.key.slice(7)]) mqRefresh(ev.key.slice(7));
  });

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-mq]');
    if (b) {
      ev.preventDefault();
      var tid = b.getAttribute('data-mq-thread'), id = b.getAttribute('data-mq');
      var list = mqGet(tid);
      var at = list.indexOf(id);
      if (at >= 0) list.splice(at, 1);
      else {
        if (list.length >= 30) { WRP.toast('Можно выбрать не больше 30 сообщений'); return; }
        list.push(id);
      }
      mqSet(tid, list);
      if (at < 0 && !document.querySelector('[data-mq-bar][data-mq-thread="' + tid + '"]')) WRP.toast('Сообщение выбрано для цитаты');
      return;
    }
    var clr = ev.target.closest('[data-mq-clear]');
    if (clr) {
      ev.preventDefault();
      mqSet(clr.closest('[data-mq-bar]').getAttribute('data-mq-thread'), []);
      return;
    }
    var ins = ev.target.closest('[data-mq-insert]');
    if (!ins) return;
    ev.preventDefault();
    var bar = ins.closest('[data-mq-bar]');
    var tid2 = bar.getAttribute('data-mq-thread');
    var ta = bar.closest('form').querySelector('textarea[name="body"]');
    var ids = mqGet(tid2);
    if (!ta || !ids.length || ins.classList.contains('busy')) return;
    ins.classList.add('busy');
    var texts = [];
    var chain = Promise.resolve();
    ids.forEach(function (pid) {
      chain = chain.then(function () {
        return fetch(WRP.url('/forum/post.php?action=quote&id=' + encodeURIComponent(pid)), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (r) {
          if (r && r.ok && r.bbcode) texts.push(r.bbcode);
        }).catch(function () {});
      });
    });
    chain.then(function () {
      ins.classList.remove('busy');
      if (!texts.length) {
        WRP.toast('Не удалось получить цитаты');
        return;
      }
      var ed = ta.closest('.editor');
      if (ed && ed.classList.contains('previewing')) {
        var pb = ed.querySelector('.editor-preview-btn');
        if (pb) pb.click();
      }
      WRP.insert(ta, texts.join('\n'));
      mqSet(tid2, []);
      if (texts.length < ids.length) WRP.toast('Часть сообщений недоступна или удалена');
    });
  });

  // ======================= Упоминания @ник =======================

  var M = { box: null, ta: null, items: [], idx: 0, start: -1, timer: null, seq: 0, off: false };

  function mentionAt(ta) {
    if (ta.selectionStart !== ta.selectionEnd) return null;
    var pos = ta.selectionStart;
    var before = ta.value.slice(Math.max(0, pos - 40), pos);
    var m = /(^|[\s(>,;!?])@([^\s@\[\]<>"',;!?()]{1,24})$/.exec(before);
    if (!m) return null;
    return { q: m[2], start: pos - m[2].length - 1, end: pos };
  }

  // Координаты курсора в textarea (через невидимую копию)
  function caretXY(ta, pos) {
    var cs = window.getComputedStyle(ta);
    var div = el('div');
    ['boxSizing', 'width', 'height', 'overflowX', 'overflowY', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
      'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'fontStyle', 'fontVariant', 'fontWeight', 'fontStretch', 'fontSize',
      'lineHeight', 'fontFamily', 'textAlign', 'textTransform', 'textIndent', 'letterSpacing', 'wordSpacing', 'tabSize'].forEach(function (p) {
      div.style[p] = cs[p];
    });
    div.style.position = 'absolute';
    div.style.visibility = 'hidden';
    div.style.whiteSpace = 'pre-wrap';
    div.style.overflowWrap = 'break-word';
    div.style.top = '0';
    div.style.left = '-9999px';
    div.textContent = ta.value.substring(0, pos);
    var span = el('span');
    span.textContent = ta.value.substring(pos) || '.';
    div.appendChild(span);
    document.body.appendChild(div);
    var lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.5 || 20;
    var out = { top: span.offsetTop - ta.scrollTop, left: span.offsetLeft - ta.scrollLeft, h: lh };
    div.remove();
    return out;
  }

  function mentionClose() {
    if (M.box) M.box.hidden = true;
    M.items = [];
    M.ta = null;
  }

  function mentionRender() {
    if (!M.box) {
      M.box = el('div', 'cnt-mention');
      M.box.setAttribute('role', 'listbox');
      document.body.appendChild(M.box);
      M.box.addEventListener('mousedown', function (ev) {
        var it = ev.target.closest('[data-i]');
        if (!it) return;
        ev.preventDefault();
        mentionPick(+it.getAttribute('data-i'));
      });
    }
    M.box.innerHTML = '';
    M.items.forEach(function (u, i) {
      var row = el('div', 'cnt-mention-item' + (i === M.idx ? ' active' : ''), u.avatar);
      row.setAttribute('data-i', i);
      row.setAttribute('role', 'option');
      var name = el('span', 'cnt-mention-name');
      name.textContent = u.name;
      if (u.color) name.style.color = u.color;
      row.appendChild(name);
      M.box.appendChild(row);
    });
    var hint = el('div', 'cnt-mention-hint', '↑↓ выбрать · Enter вставить · Esc закрыть');
    M.box.appendChild(hint);
    var ta = M.ta;
    var c = caretXY(ta, M.start);
    var r = ta.getBoundingClientRect();
    M.box.hidden = false;
    var vw = document.documentElement.clientWidth;
    var left = Math.max(8, Math.min(r.left + c.left, vw - M.box.offsetWidth - 8));
    var top = r.top + Math.min(c.top + c.h + 4, ta.clientHeight);
    M.box.style.left = (window.scrollX + left) + 'px';
    M.box.style.top = (window.scrollY + top) + 'px';
  }

  function mentionPick(i) {
    var u = M.items[i], ta = M.ta;
    if (!u || !ta) return;
    var text = '[user=' + u.id + ']' + u.name + '[/user] ';
    var cur = mentionAt(ta);
    var s = cur ? cur.start : M.start, e = cur ? cur.end : ta.selectionStart;
    mentionClose();
    ta.focus();
    ta.setSelectionRange(s, e);
    if (!document.execCommand || !document.execCommand('insertText', false, text)) {
      ta.setRangeText(text, s, e, 'end');
    }
    ta.dispatchEvent(new Event('input'));
  }

  function mentionLookup(ta) {
    var cur = mentionAt(ta);
    if (!cur || M.off) { mentionClose(); return; }
    clearTimeout(M.timer);
    M.timer = setTimeout(function () {
      var seq = ++M.seq;
      fetch(WRP.url('/forum/user-suggest.php?q=' + encodeURIComponent(cur.q)), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) {
        if (r.status === 401 || r.status === 403) M.off = true;
        return r.json();
      }).then(function (r) {
        if (seq !== M.seq) return;
        var now = mentionAt(ta);
        if (!r || !r.ok || !r.users || !r.users.length || !now || document.activeElement !== ta) {
          mentionClose();
          return;
        }
        M.ta = ta;
        M.items = r.users;
        M.idx = 0;
        M.start = now.start;
        mentionRender();
      }).catch(function () { mentionClose(); });
    }, 160);
  }

  // Только для вошедших (у гостей нет меню аккаунта в шапке)
  if (document.querySelector('.user-pill')) {
    document.addEventListener('input', function (ev) {
      var ta = ev.target;
      if (ta.tagName === 'TEXTAREA' && ta.hasAttribute('data-editor')) mentionLookup(ta);
    });
    document.addEventListener('keydown', function (ev) {
      if (!M.box || M.box.hidden || ev.target !== M.ta) return;
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        M.idx = (M.idx + (ev.key === 'ArrowDown' ? 1 : -1) + M.items.length) % M.items.length;
        mentionRender();
      } else if ((ev.key === 'Enter' || ev.key === 'Tab') && !ev.ctrlKey && !ev.metaKey) {
        ev.preventDefault();
        ev.stopPropagation();
        mentionPick(M.idx);
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        mentionClose();
      }
    }, true);
    document.addEventListener('click', function (ev) {
      if (M.box && !M.box.hidden && !ev.target.closest('.cnt-mention') && ev.target !== M.ta) mentionClose();
    });
    document.addEventListener('focusout', function (ev) {
      if (ev.target === M.ta) setTimeout(function () { if (document.activeElement !== M.ta) mentionClose(); }, 150);
    });
    window.addEventListener('resize', mentionClose);
  }

  // ======================= Черновики =======================
  // Сам черновик сохраняет и восстанавливает app.js (data-draft). Здесь - заметка «Восстановлен черновик»
  // с кнопкой «Очистить» и время последнего сохранения.

  function ago(ts) {
    var s = Math.max(0, Math.round((Date.now() - ts) / 1000));
    if (s < 60) return 'только что';
    if (s < 3600) return Math.floor(s / 60) + ' мин. назад';
    if (s < 86400) return Math.floor(s / 3600) + ' ч. назад';
    var d = new Date(ts);
    return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  }

  document.querySelectorAll('textarea[data-draft]').forEach(function (ta) {
    var key = ta.getAttribute('data-draft');
    var saved = store.get('draft:' + key);
    var timer = null;
    ta.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (ta.value !== '' && ta.value !== ta.defaultValue) store.set('draft-at:' + key, String(Date.now()));
        else store.del('draft-at:' + key);
      }, 400);
    });
    if (!saved || saved !== ta.value || saved === ta.defaultValue || saved.trim() === '') return;
    var at = +store.get('draft-at:' + key) || 0;
    var note = el('div', 'cnt-draft-note', I.draft + '<span>Восстановлен черновик' + (at ? ' (' + esc(ago(at)) + ')' : '') + '. Текст сохраняется в браузере, пока вы его не отправите.</span>');
    var clear = el('button', 'cnt-link', 'Очистить');
    clear.type = 'button';
    clear.addEventListener('click', function () {
      ta.value = ta.defaultValue;
      ta.dispatchEvent(new Event('input'));
      store.del('draft:' + key);
      store.del('draft-at:' + key);
      note.remove();
      ta.focus();
    });
    note.appendChild(clear);
    var host = ta.closest('.editor') || ta;
    host.parentNode.insertBefore(note, host);
  });
})();
