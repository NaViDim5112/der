// Режим игрового устройства: кнопка «Закрыть» сообщает игре, что форум можно закрыть.
// Игра узнаёт об этом одним из способов (какой поддерживает клиент, описано в docs/INGAME.md):
//   - CEF-плагин SA-MP: cef.emit('wrp:forum_close');
//   - событие окна 'wrp:forum_close' (для своего интерфейса, который открыл форум в iframe);
//   - postMessage({type: 'wrp:forum_close'}) родительскому окну, если форум открыт в iframe.
(function () {
  'use strict';

  function notifyClose() {
    try {
      if (window.cef && typeof window.cef.emit === 'function') {
        window.cef.emit('wrp:forum_close');
      }
    } catch (e) {}
    try {
      window.dispatchEvent(new CustomEvent('wrp:forum_close'));
    } catch (e) {}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'wrp:forum_close' }, '*');
      }
    } catch (e) {}
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-ingame-close]') : null;
    if (!btn) {
      return;
    }
    ev.preventDefault();
    notifyClose();
  });

  // Esc на клавиатуре ноутбука или компьютера - тоже «Закрыть», если не открыто окно на странице
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape' || ev.defaultPrevented) {
      return;
    }
    var a = document.activeElement;
    if (a && (a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) {
      return;
    }
    if (document.querySelector('.modal.open, .modal[open], dialog[open], .lightbox.open, [data-lightbox-open]')) {
      return;
    }
    notifyClose();
  });
})();
