/* World Role Play: модуль «Рассмотрение» - форма решения, готовые ответы, голосование за предложения */
(function () {
  'use strict';

  var WRP = window.WRP;
  if (!WRP) return;

  // Подстановки {author} {staff} {thread} {date} из data-атрибутов блока
  function fill(text, holder) {
    if (!holder) return text;
    var map = {
      author: holder.getAttribute('data-author') || '',
      staff: holder.getAttribute('data-staff') || '',
      thread: holder.getAttribute('data-thread') || '',
      date: holder.getAttribute('data-date') || ''
    };
    return String(text).replace(/\{(author|staff|thread|date)\}/g, function (m, k) { return map[k]; });
  }

  function setCheck(form, name, on) {
    var c = form.querySelector('input[name="' + name + '"]');
    if (c && !c.disabled) c.checked = !!on;
  }

  // Редактор в режиме предпросмотра: вернуть к тексту
  function editMode(ta) {
    var ed = ta.closest('.editor');
    if (ed && ed.classList.contains('previewing')) {
      var pb = ed.querySelector('.editor-preview-btn');
      if (pb) pb.click();
    }
  }

  // ---------- Форма решения ----------

  function toggle(id, show) {
    var el = document.getElementById(id);
    if (!el) return;
    el.hidden = show === undefined ? !el.hidden : !show;
    document.querySelectorAll('[data-wf-toggle="' + id + '"]').forEach(function (b) {
      if (b.closest('#' + id)) return;
      b.classList.toggle('is-on', !el.hidden);
      b.setAttribute('aria-expanded', el.hidden ? 'false' : 'true');
    });
    if (!el.hidden) {
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var first = el.querySelector('select[data-wf-macro]') || el.querySelector('textarea');
      if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 50);
    }
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-wf-toggle]');
    if (t) {
      ev.preventDefault();
      toggle(t.getAttribute('data-wf-toggle'));
      return;
    }
    var o = ev.target.closest('[data-wf-open]');
    if (o) {
      ev.preventDefault();
      toggle(o.getAttribute('data-wf-open'), true);
    }
  });
  if (window.location.hash === '#wf-verdict') toggle('wf-verdict', true);

  document.addEventListener('change', function (ev) {
    // Готовый ответ: текст, статус, закрытие и архив как в ответе
    var sel = ev.target.closest('[data-wf-macro]');
    if (sel) {
      var form = sel.closest('form');
      var opt = sel.options[sel.selectedIndex];
      var ta = form ? form.querySelector('textarea[name="body"]') : null;
      if (!ta || !opt || opt.value === '0') return;
      editMode(ta);
      var cur = ta.value.trim();
      if (cur !== '' && cur !== (ta.getAttribute('data-wf-filled') || '') && !window.confirm('Заменить ваш текст готовым ответом?')) {
        sel.value = ta.getAttribute('data-wf-macro-id') || '0';
        return;
      }
      ta.value = fill(opt.getAttribute('data-body') || '', form);
      ta.setAttribute('data-wf-filled', ta.value.trim());
      ta.setAttribute('data-wf-macro-id', opt.value);
      ta.dispatchEvent(new Event('input'));
      var p = opt.getAttribute('data-prefix') || '0';
      var radio = form.querySelector('input[name="prefix_id"][value="' + p + '"]') || form.querySelector('input[name="prefix_id"][value="0"]');
      if (radio) radio.checked = true;
      setCheck(form, 'lock', opt.getAttribute('data-lock') === '1');
      setCheck(form, 'archive', opt.getAttribute('data-archive') === '1');
      return;
    }

    // Окончательный статус: по умолчанию тема закрывается
    var r = ev.target.closest('[data-wf-verdict] input[name="prefix_id"]');
    if (r && r.checked && r.getAttribute('data-final') === '1') {
      setCheck(r.form, 'lock', true);
      return;
    }

    // Готовый ответ в обычной форме ответа
    var ins = ev.target.closest('[data-wf-insert]');
    if (ins) {
      var o = ins.options[ins.selectedIndex];
      var f = ins.closest('form');
      var box = f ? f.querySelector('textarea[name="body"]') : null;
      if (o && o.value && box) {
        editMode(box);
        WRP.insert(box, fill(o.getAttribute('data-body') || '', ins.closest('[data-wf-ph]')));
      }
      ins.value = '';
    }
  });

  // Админка: кнопки подстановок {author} {staff} ... вставляют текст в поле ответа
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-wf-ph-insert]');
    if (!b) return;
    ev.preventDefault();
    var ta = document.getElementById('wf-macro-body');
    if (!ta) return;
    editMode(ta);
    WRP.wrap(ta, b.getAttribute('data-wf-ph-insert'), '', '');
  });

  // ---------- Голосование «За / Против» ----------

  function fmt(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.wf-vote-btn');
    if (!b || b.disabled) return;
    var form = b.closest('form[data-wf-vote]');
    if (!form) return;
    ev.preventDefault();
    if (form.classList.contains('busy')) return;
    form.classList.add('busy');
    var tid = form.querySelector('input[name="thread_id"]').value;
    WRP.post(form.getAttribute('action'), { action: 'vote', thread_id: tid, vote: b.value }).then(function (r) {
      form.classList.remove('busy');
      if (!r || !r.ok) {
        if (r && r.login) {
          window.location.href = r.login;
          return;
        }
        WRP.toast((r && r.error) || 'Не удалось проголосовать');
        return;
      }
      var total = r.up + r.down;
      form.querySelector('[data-wf-up]').textContent = fmt(r.up);
      form.querySelector('[data-wf-down]').textContent = fmt(r.down);
      form.querySelector('[data-wf-meter]').style.width = (total ? Math.round(r.up * 100 / total) : 50) + '%';
      form.querySelector('[data-wf-total]').textContent = total ? 'Голосов: ' + fmt(total) : 'Пока никто не голосовал';
      form.classList.toggle('is-empty', !total);
      form.querySelectorAll('.wf-vote-btn').forEach(function (x) {
        var on = String(r.my) === x.value;
        x.classList.toggle('is-on', on);
        x.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      WRP.toast(r.my === 0 ? 'Голос отменён' : 'Голос учтён');
    }).catch(function () {
      form.classList.remove('busy');
      WRP.toast('Ошибка сети, попробуйте ещё раз');
    });
  });
})();
