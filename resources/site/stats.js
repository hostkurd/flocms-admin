/* Stats (flocms-admin 0.34): numbers in the page's digits (Intl), counting up once they come into view;
   no counting with "reduce motion". The number is in the HTML before, for search engines and screen readers. */
(function () {
  'use strict';
  var lang = document.documentElement.lang || undefined;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function format(el, value) {
    var decimals = parseInt(el.getAttribute('data-decimals'), 10) || 0;
    try { el.textContent = new Intl.NumberFormat(lang, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value); }
    catch (e) { el.textContent = value.toFixed(decimals); }
  }
  function count(el) {
    var target = parseFloat(el.getAttribute('data-flo-count')) || 0, start = null;
    function step(time) {
      if (start === null) start = time;
      var t = Math.min(1, (time - start) / 1200);
      format(el, target * (1 - Math.pow(1 - t, 3)));
      if (t < 1) requestAnimationFrame(step); else format(el, target);
    }
    requestAnimationFrame(step);
  }
  function all() {
    var numbers = [].slice.call(document.querySelectorAll('[data-flo-count]'));
    var counting = !reduced && 'IntersectionObserver' in window;
    var observer = counting ? new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { if (entry.isIntersecting) { observer.unobserve(entry.target); count(entry.target); } });
    }, { threshold: 0.4 }) : null;
    numbers.forEach(function (el) {
      var value = parseFloat(el.getAttribute('data-flo-count')) || 0;
      if (observer && el.hasAttribute('data-flo-count-up')) { format(el, 0); observer.observe(el); } else format(el, value);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
