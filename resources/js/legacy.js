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
  // form[data-confirm], or a submit button with data-admin-confirm-submit (one
  // button of a bulk bar). data-confirm-title, -text and -button change the
  // dialog's wording for that form; without them it asks "Delete this record?".

  var confirmDialog = document.getElementById('fa-confirm');
  var pending = null;
  var pendingSubmitter = null;

  function needsConfirm(form, submitter) {
    return form.hasAttribute('data-confirm') || Boolean(submitter && submitter.hasAttribute('data-admin-confirm-submit'));
  }

  function word(element, source, attribute) {
    if (!element) { return; }
    var value = source.getAttribute(attribute);
    element.textContent = value !== null && value !== '' ? value : (element.getAttribute('data-default') || element.textContent);
  }

  panel.addEventListener('submit', function (event) {
    var form = event.target;
    var submitter = event.submitter || null;
    if (!(form instanceof HTMLFormElement) || !needsConfirm(form, submitter)) { return; }
    if (form._confirmed || !confirmDialog || !canModal(confirmDialog)) { return; }
    event.preventDefault();
    pending = form;
    pendingSubmitter = submitter;
    var source = submitter && submitter.hasAttribute('data-admin-confirm-submit') ? submitter : form;
    word(confirmDialog.querySelector('.fa-dialog-title'), source, 'data-confirm-title');
    word(confirmDialog.querySelector('.fa-confirm-text'), source, 'data-confirm-text');
    word(confirmDialog.querySelector('[data-confirm-yes]'), source, 'data-confirm-button');
    // data-confirm-tone="primary": a change that is not a deletion gets a blue button (0.38).
    var yes = confirmDialog.querySelector('[data-confirm-yes]');
    var primary = source.getAttribute('data-confirm-tone') === 'primary';
    yes.classList.toggle('fa-btn-primary', primary);
    yes.classList.toggle('fa-btn-danger', !primary);
    confirmDialog.showModal();
  });

  if (confirmDialog) {
    confirmDialog.querySelector('[data-confirm-yes]').addEventListener('click', function () {
      confirmDialog.close();
      if (!pending) { return; }
      var form = pending;
      form._confirmed = true;
      if (pendingSubmitter) { form.requestSubmit(pendingSubmitter); } else { form.requestSubmit(); }
      pending = null;
      pendingSubmitter = null;
      // A failed or cancelled submit asks again next time.
      setTimeout(function () { form._confirmed = false; }, 0);
    });
    confirmDialog.querySelector('[data-confirm-no]').addEventListener('click', function () {
      pending = null;
      pendingSubmitter = null;
      confirmDialog.close();
    });
  }
}
