/*
 * Users (0.28): the password field's Generate button, and a mailing's page
 * sending its batches while it is open. Without JavaScript the Generate button
 * stays hidden and a mailing sends one batch per click of Continue.
 */
const timers = new WeakMap<Element, number>();

export function initializeUsers(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('[data-admin-generate-password]').forEach(button => button.removeAttribute('hidden'));
  root.querySelectorAll<HTMLElement>('[data-admin-mailing][data-step-url]').forEach(card => schedule(card));
}

/** A password of 16 letters, digits and signs, from the browser's random source. */
export function generatePassword(length = 16): string {
  const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_!?%+=';
  const bytes = new Uint32Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, value => letters[value % letters.length]).join('');
}

document.addEventListener('click', event => {
  const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-admin-generate-password]') : null;
  if (!button) return;
  const input = document.getElementById(button.dataset.adminGeneratePassword || '') as HTMLInputElement | null;
  if (!input) return;
  if (button.dataset.generated === 'true') {
    // Second click: copy what was generated
    if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(() => { button.textContent = button.dataset.copiedText || 'Copied'; }, () => undefined);
    return;
  }
  input.value = generatePassword();
  input.type = 'text';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  button.dataset.generated = 'true';
  button.textContent = button.dataset.copyText || 'Copy';
});

/** A mailing that is sending: ask for the next batch every few seconds and redraw its card. */
function schedule(card: HTMLElement): void {
  if (timers.has(card)) return;
  const continueForm = card.querySelector<HTMLElement>('[data-admin-mailing-continue]');
  if (continueForm) continueForm.hidden = true;
  timers.set(card, window.setTimeout(() => void step(card), 1500));
}

async function step(card: HTMLElement): Promise<void> {
  timers.delete(card);
  if (!card.isConnected) return;
  const url = card.dataset.stepUrl;
  const token = card.querySelector<HTMLInputElement>('input[name="_token"]')?.value ?? '';
  if (!url) return;
  let wait = 3000;
  try {
    const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: new URLSearchParams({ _token: token }), credentials: 'same-origin' });
    if (!response.ok) throw new Error(String(response.status));
    const data = await response.json() as { state: string; html: string };
    const fresh = new DOMParser().parseFromString(data.html, 'text/html').body.firstElementChild as HTMLElement | null;
    if (fresh && card.isConnected) {
      card.replaceWith(fresh);
      card = fresh;
    }
    // The hour's limit reached: try again in a minute.
    if (data.state === 'waiting' || data.state === 'busy') wait = 60000;
    if (card.dataset.stepUrl) timers.set(card, window.setTimeout(() => void step(card), wait));
  } catch {
    const continueForm = card.querySelector<HTMLElement>('[data-admin-mailing-continue]');
    if (continueForm) continueForm.hidden = false;
  }
}
