// Wizards (0.39): every move is a form submit handled by the server, so this
// only arranges the page: the steps menu is folded on phones, and after Back,
// Next or a step button focus goes to the new step's heading (or, when the step
// has errors, stays on the first invalid field the panel already focused).
const phone = window.matchMedia('(max-width: 640px)');

export function initializeWizards(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-admin-wizard]').forEach(wizard => {
    const menu = wizard.querySelector<HTMLDetailsElement>('[data-wizard-menu]');
    if (menu && wizard.dataset.wizardReady !== 'true') menu.open = !phone.matches;
    wizard.dataset.wizardReady = 'true';
  });
}

/** Moves focus to the step that just opened; called once the new page is in place. */
export function focusWizardStep(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-admin-wizard][data-wizard-moved]').forEach(wizard => {
    if (wizard.dataset.wizardFocused === 'true') return;
    wizard.dataset.wizardFocused = 'true';
    const panel = wizard.querySelector<HTMLElement>('.fa-wizard-panel:not([hidden])');
    if (!panel || panel.querySelector('[aria-invalid="true"]')) return;
    panel.querySelector<HTMLElement>('.fa-wizard-title')?.focus();
  });
}

phone.addEventListener('change', () => {
  document.querySelectorAll<HTMLDetailsElement>('[data-wizard-menu]').forEach(menu => { menu.open = !phone.matches; });
});
document.addEventListener('flo-admin:navigated', () => focusWizardStep(document));
document.addEventListener('flo-reactive:updated', () => focusWizardStep(document));
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => focusWizardStep(document));
else focusWizardStep(document);
