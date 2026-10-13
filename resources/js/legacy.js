/* flocms-admin: translation dialogs, slugs, delete confirmation. No dependencies. */
export function initializeLegacy() {

  var panel = document.querySelector('.fa-panel');
  if (!panel || panel.dataset.adminInitialized) { return; }
  panel.dataset.adminInitialized = 'true';

  function canModal(dialog) { return typeof dialog.showModal === 'function'; }

  /* ---- Translation dialogs ------------------------------------------- */

  function controls(dialog) {
    return Array.prototype.slice.call(dialog.querySelectorAll('input, textarea'));
  }

  function refreshBadge(dialog) {
    var badge = panel.querySelector('[data-dialog="' + dialog.id + '"]');
    if (!badge) { return; }
    var missing = [];
    controls(dialog).forEach(function (input) {
      var lang = input.getAttribute('lang');
      var code = null;
      Array.prototype.forEach.call(badge.querySelectorAll('.fa-code'), function (el) {
        if (el.textContent === lang) { code = el; }
      });
      var filled = input.value.trim() !== '';
      if (!filled) { missing.push(lang); }
      if (code && !code.classList.contains('is-default')) {
        code.classList.toggle('is-filled', filled);
        code.classList.toggle('is-empty', !filled);
      }
    });
    var title = missing.length ? (badge.getAttribute('data-missing-text') || 'Missing: ') + missing.join(', ') : (badge.getAttribute('data-complete-text') || '');
    if (title) { badge.title = title; }
  }

  function openDialog(dialog) {
    var main = document.getElementById(dialog.getAttribute('data-main'));
    var mirror = dialog.querySelector('[data-mirror]');
    if (main && mirror) { mirror.value = main.value; }
    dialog._snapshot = controls(dialog).map(function (input) { return input.value; });
    if (canModal(dialog)) { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
    var first = dialog.querySelector('[aria-invalid="true"]') || controls(dialog)[0];
    if (first) { first.focus(); }
  }

  function closeDialog(dialog, save) {
    if (!save && dialog._snapshot) {
      controls(dialog).forEach(function (input, i) { input.value = dialog._snapshot[i]; });
    }
    if (save) {
      var main = document.getElementById(dialog.getAttribute('data-main'));
      var mirror = dialog.querySelector('[data-mirror]');
      if (main && mirror) {
        main.value = mirror.value;
        main.dispatchEvent(new Event('input', { bubbles: true }));
      }
      refreshBadge(dialog);
    }
    dialog._snapshot = null;
    if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
    var badge = panel.querySelector('[data-dialog="' + dialog.id + '"]');
    if (badge) { badge.focus(); }
  }

  Array.prototype.forEach.call(panel.querySelectorAll('.fa-lang-dialog'), function (dialog) {
    var badge = panel.querySelector('[data-dialog="' + dialog.id + '"]');
    if (badge) {
      badge.addEventListener('click', function () { openDialog(dialog); });
    }
    dialog.querySelector('[data-dialog-save]').addEventListener('click', function () { closeDialog(dialog, true); });
    dialog.querySelector('[data-dialog-cancel]').addEventListener('click', function () { closeDialog(dialog, false); });
    // Esc: restore the values, like Cancel
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); closeDialog(dialog, false); });
    // Enter in a one-line input saves instead of submitting the whole form
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.tagName === 'INPUT') { event.preventDefault(); closeDialog(dialog, true); }
    });
    // Keep the badge in step with the visible input
    var main = document.getElementById(dialog.getAttribute('data-main'));
    if (main) {
      main.addEventListener('input', function () {
        var mirror = dialog.querySelector('[data-mirror]');
        if (mirror && !dialog.open) { mirror.value = main.value; refreshBadge(dialog); }
      });
    }
  });

  var invalid = panel.querySelector('.fa-lang-dialog[data-open-on-load]');
  if (invalid) { openDialog(invalid); }

  /* ---- Slugs ---------------------------------------------------------- */

  function slugify(text) {
    var value = text.toLowerCase().trim();
    if (value.normalize) { value = value.normalize('NFKD').replace(/[̀-ͯ]/g, ''); }
    try {
      value = value.replace(/[^\p{L}\p{N}]+/gu, '-');
    } catch (e) {
      value = value.replace(/[^a-z0-9À-￿]+/g, '-');
    }
    return value.replace(/^-+|-+$/g, '');
  }

  Array.prototype.forEach.call(panel.querySelectorAll('[data-slug-from]'), function (slug) {
    var source = document.getElementById(slug.getAttribute('data-slug-from'));
    if (!source) { return; }
    var touched = slug.value !== '';
    slug.addEventListener('input', function () { touched = slug.value !== ''; });
    slug.addEventListener('blur', function () { slug.value = slugify(slug.value); });
    source.addEventListener('input', function () { if (!touched) { slug.value = slugify(source.value); } });
  });

  /* ---- Delete confirmation -------------------------------------------- */

  var confirmDialog = document.getElementById('fa-confirm');
  var pending = null;

  Array.prototype.forEach.call(panel.querySelectorAll('form[data-confirm]'), function (form) {
    form.addEventListener('submit', function (event) {
      if (form._confirmed || !confirmDialog || !canModal(confirmDialog)) { return; }
      event.preventDefault();
      pending = form;
      confirmDialog.showModal();
    });
  });

  if (confirmDialog) {
    confirmDialog.querySelector('[data-confirm-yes]').addEventListener('click', function () {
      confirmDialog.close();
      if (pending) { pending._confirmed = true; pending.requestSubmit(); }
    });
    confirmDialog.querySelector('[data-confirm-no]').addEventListener('click', function () {
      pending = null;
      confirmDialog.close();
    });
  }
}
