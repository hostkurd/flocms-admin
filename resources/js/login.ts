export function initializeLogin(): void {
  const login = document.querySelector<HTMLElement>('.fa-login');
  if (!login) return;
  document.documentElement.lang = login.lang;
  document.documentElement.dir = login.dir;
  login.querySelectorAll<HTMLButtonElement>('[data-admin-password-toggle]').forEach(button => button.removeAttribute('hidden'));
}
document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const button = event.target.closest<HTMLButtonElement>('.fa-login [data-admin-password-toggle]');
  if (!button) return;
  const input = document.getElementById(button.getAttribute('aria-controls') || '');
  if (!(input instanceof HTMLInputElement)) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.setAttribute('aria-pressed', String(show));
  button.textContent = (show ? button.dataset.hideText : button.dataset.showText) || '';
});
