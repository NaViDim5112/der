/* World Role Play: скрипты публичного сайта (меню, появление блоков, окно с видео, фоновое видео) */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var nav = document.getElementById('site-nav');

  // Панель навигации плотнее при прокрутке
  if (nav) {
    var onScroll = function () {
      nav.classList.toggle('is-scrolled', window.scrollY > 24);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  // Мобильное меню
  var burger = document.querySelector('[data-site-burger]');
  var panel = document.getElementById('site-nav-panel');
  var setMenu = function (open) {
    if (!nav || !burger || !panel) return;
    nav.classList.toggle('is-open', open);
    panel.hidden = !open;
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
  };
  if (burger) {
    burger.addEventListener('click', function () {
      setMenu(!nav.classList.contains('is-open'));
    });
    document.addEventListener('click', function (ev) {
      if (nav.classList.contains('is-open') && !ev.target.closest('#site-nav')) setMenu(false);
    });
    window.addEventListener('resize', function () {
      if (window.innerWidth > 920 && nav.classList.contains('is-open')) setMenu(false);
    });
  }

  // Флеш-сообщения прячутся сами
  var flashes = document.querySelector('.site-flashes');
  if (flashes) {
    setTimeout(function () { flashes.classList.add('is-hidden'); }, 5000);
    flashes.addEventListener('click', function () { flashes.classList.add('is-hidden'); });
  }

  // Появление блоков при прокрутке
  var items = document.querySelectorAll('.reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    items.forEach(function (el) { el.classList.add('is-visible'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add('is-visible');
          io.unobserve(en.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    items.forEach(function (el) { io.observe(el); });
  }

  // Фоновое видео: пауза по кнопке и при «уменьшить движение»
  var video = document.querySelector('.hero-media video');
  var vbtn = document.querySelector('[data-hero-video-toggle]');
  if (video) {
    var playIcon = '<svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg>';
    var pauseIcon = '<svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>';
    var syncBtn = function () {
      if (!vbtn) return;
      var paused = video.paused;
      vbtn.innerHTML = paused ? playIcon : pauseIcon;
      vbtn.setAttribute('aria-label', paused ? 'Включить фоновое видео' : 'Остановить фоновое видео');
      vbtn.title = vbtn.getAttribute('aria-label');
    };
    if (reduce) {
      video.removeAttribute('autoplay');
      video.pause();
    }
    video.addEventListener('play', syncBtn);
    video.addEventListener('pause', syncBtn);
    syncBtn();
    if (vbtn) {
      vbtn.addEventListener('click', function () {
        if (video.paused) { video.play().catch(function () {}); } else { video.pause(); }
      });
    }
  }

  // Окно с роликом YouTube: кнопки с data-video="id"
  var modal = null;
  var lastFocus = null;
  var closeModal = function () {
    if (!modal) return;
    modal.classList.remove('is-open');
    document.documentElement.classList.remove('modal-lock');
    var frame = modal.querySelector('.video-frame');
    setTimeout(function () { if (frame) frame.innerHTML = ''; }, 250);
    if (lastFocus) lastFocus.focus();
  };
  var openModal = function (id) {
    if (!/^[A-Za-z0-9_-]{11}$/.test(id)) return;
    if (!modal) {
      modal = document.createElement('div');
      modal.className = 'video-modal';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.setAttribute('aria-label', 'Видео об игре');
      modal.innerHTML = '<button class="video-close" type="button" aria-label="Закрыть видео">' +
        '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>' +
        '</button><div class="video-frame"></div>';
      document.body.appendChild(modal);
      modal.addEventListener('click', function (ev) {
        if (ev.target === modal || ev.target.closest('.video-close')) closeModal();
      });
      modal.addEventListener('keydown', function (ev) {
        // фокус не уходит из окна
        if (ev.key !== 'Tab') return;
        var f = modal.querySelectorAll('button, iframe');
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      });
    }
    lastFocus = document.activeElement;
    var frame = modal.querySelector('.video-frame');
    frame.innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1" title="Видео об игре" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>';
    modal.offsetWidth; // запуск анимации
    modal.classList.add('is-open');
    document.documentElement.classList.add('modal-lock');
    modal.querySelector('.video-close').focus();
  };
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-video]');
    if (!t) return;
    ev.preventDefault();
    openModal(t.getAttribute('data-video'));
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    if (modal && modal.classList.contains('is-open')) { closeModal(); return; }
    if (nav && nav.classList.contains('is-open')) { setMenu(false); burger.focus(); }
  });
})();
