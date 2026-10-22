/* Gallery lightbox (flocms-admin 0.29): a <dialog> with the larger image, arrows, Escape and swipe; 0.34: its caption. */
(function () {
  'use strict';
  var dialog = null, image = null, links = [], index = 0;
  function make(gallery) {
    dialog = document.createElement('dialog');
    dialog.className = 'flo-lightbox';
    dialog.innerHTML = '<img alt=""><p class="flo-lightbox-caption" hidden></p><button type="button" data-prev>‹</button><button type="button" data-next>›</button><button type="button" data-close>×</button>';
    ['prev', 'next', 'close'].forEach(function (name) {
      var label = gallery.getAttribute('data-' + (name === 'prev' ? 'previous' : name)) || name;
      dialog.querySelector('[data-' + name + ']').setAttribute('aria-label', label);
    });
    image = dialog.querySelector('img');
    dialog.addEventListener('click', function (event) {
      var button = event.target.closest('button');
      if (event.target === dialog || (button && button.hasAttribute('data-close'))) dialog.close();
      else if (button && button.hasAttribute('data-prev')) show(index - 1);
      else if (button && button.hasAttribute('data-next')) show(index + 1);
    });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') show(index - 1);
      if (event.key === 'ArrowRight') show(index + 1);
    });
    document.body.appendChild(dialog);
  }
  function show(i) {
    index = (i + links.length) % links.length;
    var link = links[index];
    image.src = link.href;
    image.alt = (link.querySelector('img') || {}).alt || '';
    var caption = dialog.querySelector('.flo-lightbox-caption');
    caption.textContent = link.getAttribute('data-caption') || '';
    caption.hidden = !caption.textContent;
  }
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('[data-flo-gallery-item]');
    if (!link || typeof HTMLDialogElement !== 'function') return;
    event.preventDefault();
    var gallery = link.closest('[data-flo-gallery]');
    if (!dialog) make(gallery);
    links = [].slice.call(gallery.querySelectorAll('[data-flo-gallery-item]'));
    show(links.indexOf(link));
    dialog.showModal();
  });
})();
