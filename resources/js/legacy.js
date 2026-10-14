/* flocms-admin: slugs and delete confirmation. No dependencies. */
export function initializeLegacy() {

  var panel = document.querySelector('.fa-panel');
  if (!panel || panel.dataset.adminInitialized) { return; }
  panel.dataset.adminInitialized = 'true';

  function canModal(dialog) { return typeof dialog.showModal === 'function'; }

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
