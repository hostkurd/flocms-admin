/* Motion (flocms-admin 0.32): videos that play by themselves (a Hero's background, a Video block
   with autoplay) stay still for visitors who ask for less motion; a Video block shows its controls. */
(function () {
  'use strict';
  if (!window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  function still() {
    [].forEach.call(document.querySelectorAll('video[data-flo-motion]'), function (video) {
      video.autoplay = false;
      video.pause();
      if (video.getAttribute('data-flo-motion') === 'controls') video.controls = true;
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', still); else still();
})();
