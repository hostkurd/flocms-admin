/* Slider (flocms-admin 0.29): arrows, dots, swipe, autoplay that pauses on hover, focus and when the tab is hidden; no autoplay with reduced motion. */
(function () {
  'use strict';
  function start(slider) {
    if (slider.dataset.floReady) return;
    slider.dataset.floReady = '1';
    var slides = [].slice.call(slider.querySelectorAll('.flo-slide'));
    var dots = [].slice.call(slider.querySelectorAll('[data-flo-dot]'));
    var pause = slider.querySelector('[data-flo-pause]');
    // Less motion asked for (0.32): no video plays by itself, even a single slide's.
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      [].forEach.call(slider.querySelectorAll('video[data-flo-video]'), function (video) { video.autoplay = false; video.pause(); });
    }
    if (slides.length < 2) return;
    var current = 0, timer = null, held = false, stopped = false;
    var interval = Math.max(3000, parseInt(slider.dataset.interval, 10) || 6000);
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var auto = slider.dataset.autoplay === '1' && !reduced;
    function show(index) {
      index = (index + slides.length) % slides.length;
      slides.forEach(function (slide, i) {
        slide.classList.toggle('is-current', i === index);
        slide.classList.toggle('is-before', i < index);
        if (i === index) { slide.removeAttribute('aria-hidden'); slide.inert = false; } else { slide.setAttribute('aria-hidden', 'true'); slide.inert = true; }
        var img = slide.querySelector('img[loading="lazy"]');
        if (img && Math.abs(i - index) <= 1) img.loading = 'eager';
        var video = slide.querySelector('video[data-flo-video]');
        if (video) { if (i === index && !reduced) { video.preload = 'auto'; var p = video.play(); if (p && p.catch) p.catch(function () {}); } else video.pause(); }
      });
      dots.forEach(function (dot, i) { if (i === index) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current'); });
      current = index;
    }
    function schedule() {
      clearTimeout(timer);
      if (auto && !held && !stopped && !document.hidden) timer = setTimeout(function () { show(current + 1); schedule(); }, interval);
    }
    slider.addEventListener('click', function (event) {
      var target = event.target.closest('button');
      if (!target || !slider.contains(target)) return;
      if (target.hasAttribute('data-flo-prev')) show(current - 1);
      else if (target.hasAttribute('data-flo-next')) show(current + 1);
      else if (target.hasAttribute('data-flo-dot')) show(parseInt(target.getAttribute('data-flo-dot'), 10) || 0);
      else if (target === pause) {
        stopped = !stopped;
        pause.classList.toggle('is-paused', stopped);
        pause.setAttribute('aria-label', stopped ? pause.dataset.playLabel : pause.dataset.pauseLabel);
      }
      schedule();
    });
    slider.addEventListener('keydown', function (event) {
      var rtl = getComputedStyle(slider).direction === 'rtl';
      if (event.key === 'ArrowLeft') { show(current + (rtl ? 1 : -1)); schedule(); }
      if (event.key === 'ArrowRight') { show(current + (rtl ? -1 : 1)); schedule(); }
    });
    var x = null;
    slider.addEventListener('touchstart', function (event) { x = event.touches[0].clientX; }, { passive: true });
    slider.addEventListener('touchend', function (event) {
      if (x === null) return;
      var dx = event.changedTouches[0].clientX - x; x = null;
      if (Math.abs(dx) < 40) return;
      var rtl = getComputedStyle(slider).direction === 'rtl';
      show(current + ((dx < 0) !== rtl ? 1 : -1)); schedule();
    });
    ['mouseenter', 'focusin'].forEach(function (name) { slider.addEventListener(name, function () { held = true; clearTimeout(timer); }); });
    ['mouseleave', 'focusout'].forEach(function (name) { slider.addEventListener(name, function () { held = false; schedule(); }); });
    document.addEventListener('visibilitychange', schedule);
    if (!auto && pause) pause.hidden = true;
    schedule();
  }
  function all() { [].forEach.call(document.querySelectorAll('[data-flo-slider]'), start); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
