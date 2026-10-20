/*
 * Users (0.28): the password field's Generate button, and a mailing's page
 * sending its batches while it is open. Without JavaScript the Generate button
 * stays hidden and a mailing sends one batch per click of Continue.
 */
const timers = new WeakMap<Element, number>();

export function initializeUsers(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('[data-admin-generate-password]').forEach(button => button.removeAttribute('hidden'));
  root.querySelectorAll<HTMLElement>('[data-admin-mailing][data-step-url]').forEach(card => schedule(card));
  root.querySelectorAll<HTMLElement>('[data-admin-to]').forEach(field => initializeTo(field));
}

/*
 * The composer's To field (0.28): people as chips, added by typing a name or
 * email, or one group (Everyone, a role, a status) from the Group menu. Without
 * JavaScript the menu and the × buttons post the form instead.
 */
type Person = { id: number; name: string; email: string; avatar: string };
const toFields = new WeakSet<HTMLElement>();

function initializeTo(field: HTMLElement): void {
  if (toFields.has(field)) return;
  toFields.add(field);
  const q = <T extends Element>(selector: string) => field.querySelector<T>(selector)!;
  const input = q<HTMLInputElement>('[data-admin-to-input]');
  const chips = q<HTMLElement>('[data-admin-to-chips]');
  const results = q<HTMLUListElement>('[data-admin-to-results]');
  const typeInput = q<HTMLInputElement>('[data-admin-to-type]');
  const roleInput = q<HTMLInputElement>('[data-admin-to-role]');
  const statusInput = q<HTMLInputElement>('[data-admin-to-status]');
  const usersInput = q<HTMLInputElement>('[data-admin-to-users]');
  const form = field.closest('form');
  const active = field.querySelector<HTMLElement>('[data-admin-to-active]');
  const max = Number(field.dataset.max || '500');
  input.hidden = false;
  field.querySelector<HTMLElement>('[data-admin-to-refresh]')?.setAttribute('hidden', '');
  let found: Person[] = [];
  let current = -1;
  let timer = 0;
  let request = 0;

  const ids = () => [...chips.querySelectorAll<HTMLElement>('.fa-to-chip[data-id]')].map(chip => chip.dataset.id!);
  const sync = () => {
    usersInput.value = ids().join(',');
    if (active) active.hidden = typeInput.value === 'users' || typeInput.value === 'status';
    void recount();
  };
  const removeButton = (label: string, value: string) => {
    const button = document.createElement('button');
    button.type = 'submit'; button.className = 'fa-to-remove'; button.name = '_remove'; button.value = value;
    button.setAttribute('aria-label', `${field.dataset.removeText || 'Remove'} ${label}`);
    button.innerHTML = '<svg class="fa-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    return button;
  };
  const addPerson = (person: Person) => {
    if (typeInput.value !== 'users') { chips.replaceChildren(); typeInput.value = 'users'; }
    if (ids().includes(String(person.id)) || ids().length >= max) return;
    const chip = document.createElement('span');
    chip.className = 'fa-to-chip'; chip.dataset.id = String(person.id); chip.title = person.email;
    const avatar = document.createElement('template'); avatar.innerHTML = person.avatar;
    const name = document.createElement('span'); name.className = 'fa-to-chip-name'; name.textContent = person.name;
    chip.append(avatar.content, name, removeButton(person.name, String(person.id)));
    chips.append(chip);
    sync();
  };
  const setGroup = (value: string, label: string) => {
    const [type, detail = ''] = value.split(':');
    typeInput.value = type;
    roleInput.value = type === 'role' ? detail : '';
    statusInput.value = type === 'status' ? detail : '';
    const chip = document.createElement('span');
    chip.className = 'fa-to-chip is-group'; chip.dataset.group = value;
    const icon = document.createElement('span'); icon.className = 'fa-to-group-icon'; icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg class="fa-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M21 19v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8"/></svg>';
    const name = document.createElement('span'); name.className = 'fa-to-chip-name'; name.textContent = label;
    chip.append(icon, name, removeButton(label, 'group'));
    chips.replaceChildren(chip);
    sync();
  };
  const close = () => { results.hidden = true; results.replaceChildren(); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); current = -1; };
  const highlight = (index: number) => {
    const options = [...results.querySelectorAll<HTMLElement>('[role=option]')];
    if (options.length === 0) return;
    current = (index + options.length) % options.length;
    options.forEach((option, i) => option.setAttribute('aria-selected', String(i === current)));
    input.setAttribute('aria-activedescendant', options[current].id);
    options[current].scrollIntoView({ block: 'nearest' });
  };
  const search = async () => {
    const term = input.value.trim();
    if (term === '') { close(); return; }
    const mine = ++request;
    const url = new URL(field.dataset.searchUrl || '', window.location.href);
    url.searchParams.set('q', term); url.searchParams.set('skip', ids().join(','));
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok || mine !== request) return;
      found = ((await response.json()) as { people: Person[] }).people;
    } catch { return; }
    results.replaceChildren(...found.map((person, index) => {
      const option = document.createElement('li');
      option.id = `fa-to-option-${index}`; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
      option.className = 'fa-to-option'; option.dataset.index = String(index);
      const avatar = document.createElement('template'); avatar.innerHTML = person.avatar;
      const text = document.createElement('span'); text.className = 'fa-to-option-text';
      const name = document.createElement('strong'); name.textContent = person.name;
      const email = document.createElement('small'); email.textContent = person.email; email.dir = 'ltr';
      text.append(name, email);
      option.append(avatar.content, text);
      return option;
    }));
    if (found.length === 0) {
      const none = document.createElement('li'); none.className = 'fa-to-none'; none.textContent = field.dataset.noneText || '';
      if (none.textContent) results.append(none);
    }
    results.hidden = results.children.length === 0;
    input.setAttribute('aria-expanded', String(!results.hidden));
    if (found.length > 0) highlight(0);
  };
  const recount = async () => {
    const url = new URL(field.dataset.countUrl || '', window.location.href);
    url.searchParams.set('to', typeInput.value); url.searchParams.set('role', roleInput.value); url.searchParams.set('status', statusInput.value);
    url.searchParams.set('users', usersInput.value);
    const checkbox = active?.querySelector<HTMLInputElement>('input');
    url.searchParams.set('active_only', checkbox?.checked ? '1' : '');
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) return;
      const data = await response.json() as { countText: string; unsubscribedText: string; sendText: string; note: string; title: string; lead: string };
      const title = form?.querySelector<HTMLElement>('.fa-page-title');
      if (title) title.textContent = data.title;
      const lead = form?.querySelector<HTMLElement>('.fa-page-description');
      if (lead) lead.textContent = data.lead;
      const count = field.querySelector<HTMLElement>('[data-admin-to-count]');
      if (count) { const strong = document.createElement('strong'); strong.textContent = data.countText; count.replaceChildren(strong, data.unsubscribedText ? ` ${data.unsubscribedText}` : ''); }
      const send = form?.querySelector<HTMLElement>('[data-admin-to-send]');
      if (send) send.textContent = data.sendText;
      const note = field.querySelector<HTMLElement>('[data-admin-to-note]');
      if (note) note.textContent = data.note;
    } catch { /* the count stays as it was */ }
  };

  input.addEventListener('input', () => { window.clearTimeout(timer); timer = window.setTimeout(() => void search(), 180); });
  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { if (!results.hidden) { event.preventDefault(); highlight(current + (event.key === 'ArrowDown' ? 1 : -1)); } return; }
    if (event.key === 'Enter') {
      event.preventDefault();
      if (!results.hidden && current >= 0 && found[current]) { addPerson(found[current]); input.value = ''; close(); }
      return;
    }
    if (event.key === 'Escape' && !results.hidden) { event.preventDefault(); close(); return; }
    if (event.key === 'Backspace' && input.value === '') {
      const last = chips.lastElementChild?.querySelector<HTMLButtonElement>('.fa-to-remove');
      if (last) { event.preventDefault(); last.click(); }
    }
  });
  input.addEventListener('blur', () => window.setTimeout(close, 150));
  results.addEventListener('mousedown', event => {
    const option = event.target instanceof Element ? event.target.closest<HTMLElement>('[role=option]') : null;
    if (!option) return;
    event.preventDefault();
    addPerson(found[Number(option.dataset.index)]);
    input.value = ''; close(); input.focus();
  });
  field.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const remove = target?.closest<HTMLButtonElement>('.fa-to-remove');
    if (remove) {
      event.preventDefault();
      if (remove.value === 'group') { chips.replaceChildren(); typeInput.value = 'users'; roleInput.value = ''; statusInput.value = ''; }
      else remove.closest('.fa-to-chip')?.remove();
      sync(); input.focus();
      return;
    }
    const group = target?.closest<HTMLButtonElement>('button[name=_to]');
    if (group) {
      event.preventDefault();
      setGroup(group.value, group.dataset.label || group.textContent || '');
      group.closest('details')?.removeAttribute('open');
      input.focus();
      return;
    }
    if (target?.closest('[data-admin-to-box]') && !target.closest('details, button, a')) input.focus();
  });
  active?.querySelector('input')?.addEventListener('change', () => void recount());
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
