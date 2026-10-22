/* Contact and map (flocms-admin 0.34): the map loads only when the visitor asks for it, so nothing
   comes from Google or OpenStreetMap before; without JavaScript "Show map" opens it on their site. */
(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var show = event.target.closest && event.target.closest('[data-flo-map-show]');
    if (!show) return;
    var box = show.closest('[data-flo-map]');
    var src = box ? box.getAttribute('data-src') || '' : '';
    if (!/^https:\/\/(www\.google\.com\/maps\?|www\.openstreetmap\.org\/export\/embed\.html\?)/.test(src)) return;
    event.preventDefault();
    var iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.title = box.getAttribute('data-title') || '';
    iframe.referrerPolicy = 'no-referrer-when-downgrade';
    box.classList.remove('is-waiting');
    box.replaceChildren(iframe);
  });
})();
