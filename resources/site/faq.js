/* FAQ (flocms-admin 0.34): a link to a question (#faq-3) opens it. */
(function () {
  'use strict';
  function open() {
    if (!location.hash) return;
    var id;
    try { id = decodeURIComponent(location.hash.slice(1)); } catch (e) { return; }
    var item = document.getElementById(id);
    if (item && item.matches('details.flo-faq-item')) { item.open = true; item.scrollIntoView({ block: 'start' }); }
  }
  window.addEventListener('hashchange', open);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', open); else open();
})();
