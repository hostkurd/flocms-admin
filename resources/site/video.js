/* Video (flocms-admin 0.34): a YouTube or Vimeo video loads only when its cover is clicked, so nothing
   comes from those sites before; without JavaScript the cover opens the video on its site. */
(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var play = event.target.closest && event.target.closest('[data-flo-video-embed] .flo-video-play');
    if (!play) return;
    var frame = play.closest('[data-flo-video-embed]');
    var src = frame.getAttribute('data-src') || '';
    if (!/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(src)) return;
    event.preventDefault();
    var iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.title = frame.getAttribute('data-title') || '';
    iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    iframe.allowFullscreen = true;
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.classList.remove('is-waiting');
    frame.replaceChildren(iframe);
    iframe.focus();
  });
})();
