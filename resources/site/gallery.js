/* Gallery lightbox (flocms-admin 0.29): a <dialog> with the larger image, arrows, Escape and swipe. */
(function () {
  'use strict';
  var dialog = null, image = null, links = [], index = 0;
  function make() {
    dialog = document.createElement('dialog');
    dialog.className = 'flo-lightbox';
    dialog.innerHTML = '<img alt=""><button type="button" data-prev aria-label="Previous">‹</button><button type="button" data-next aria-label="Next">›</button><button type="button" data-close aria-label="Close">×</button>';
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
  }
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('[data-flo-gallery-item]');
    if (!link || typeof HTMLDialogElement !== 'function') return;
    event.preventDefault();
    if (!dialog) make();
    links = [].slice.call(link.closest('[data-flo-gallery]').querySelectorAll('[data-flo-gallery-item]'));
    show(links.indexOf(link));
    dialog.showModal();
  });
})();
