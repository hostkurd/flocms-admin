type Control = HTMLInputElement | HTMLTextAreaElement;
const initialized = new WeakSet<HTMLDialogElement>();
const snapshots = new WeakMap<HTMLDialogElement, string[]>();
const controls = (dialog: HTMLDialogElement) => [...dialog.querySelectorAll<Control>('input, textarea')];
const main = (dialog: HTMLDialogElement) => document.getElementById(dialog.dataset.main || '') as Control | null;
const badge = (dialog: HTMLDialogElement) => [...document.querySelectorAll<HTMLButtonElement>('.fa-lang-badge')]
  .find(button => button.dataset.dialog === dialog.id);

function refresh(dialog: HTMLDialogElement): void {
  const button = badge(dialog);
  if (!button) return;
  const missing: string[] = [];
  controls(dialog).forEach(input => {
    const filled = input.value.trim() !== '';
    if (!filled) missing.push(input.lang);
    const dot = [...button.querySelectorAll<HTMLElement>('.fa-dot')].find(dot => dot.dataset.lang === input.lang);
    if (dot) {
      dot.classList.toggle('is-filled', filled);
      dot.classList.toggle('is-empty', !filled);
    }
  });
  button.title = missing.length ? (button.dataset.missingText || 'Missing: ') + missing.join(', ') : (button.dataset.completeText || '');
  // The dots are hidden from assistive technology; the label carries the same status.
  if (button.dataset.openText) button.setAttribute('aria-label', `${button.dataset.openText}. ${button.title}`);
}

function open(dialog: HTMLDialogElement): void {
  if (dialog.open) return;
  const mirror = dialog.querySelector<Control>('[data-mirror]');
  if (mirror && main(dialog)) mirror.value = main(dialog)!.value;
  snapshots.set(dialog, controls(dialog).map(input => input.value));
  dialog.showModal();
  (dialog.querySelector<HTMLElement>('[aria-invalid="true"]') || controls(dialog)[0])?.focus();
}

function close(dialog: HTMLDialogElement, save: boolean): void {
  const snapshot = snapshots.get(dialog);
  if (!save && snapshot) controls(dialog).forEach((input, index) => { input.value = snapshot[index]; });
  if (save) {
    const mirror = dialog.querySelector<Control>('[data-mirror]');
    const input = main(dialog);
    if (input && mirror) {
      input.value = mirror.value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    refresh(dialog);
  }
  snapshots.delete(dialog);
  dialog.close();
  badge(dialog)?.focus();
}

// Delegation survives Reactive morphs: badges and inputs can be replaced without
// keeping event handlers that point at a detached translation dialog.
document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const button = event.target.closest<HTMLButtonElement>('.fa-panel [data-dialog]');
  const target = button ? document.getElementById(button.dataset.dialog || '') : null;
  if (target instanceof HTMLDialogElement && target.classList.contains('fa-lang-dialog')) { open(target); return; }
  const dialog = event.target.closest<HTMLDialogElement>('.fa-panel .fa-lang-dialog');
  if (dialog && event.target.closest('[data-dialog-save], [data-dialog-cancel]')) {
    close(dialog, Boolean(event.target.closest('[data-dialog-save]')));
  }
});
document.addEventListener('input', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) || !input.closest('.fa-panel')) return;
  const dialog = [...document.querySelectorAll<HTMLDialogElement>('.fa-lang-dialog')].find(dialog => dialog.dataset.main === input.id);
  const mirror = dialog?.querySelector<Control>('[data-mirror]');
  if (dialog && mirror && !dialog.open) { mirror.value = input.value; refresh(dialog); }
});

/** The input keeps room for its language badge: the badge's own width, measured once it is visible. */
const badgeSpace = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(entries => {
  for (const entry of entries) {
    const badge = entry.target as HTMLElement;
    const width = badge.offsetWidth;
    if (width > 0) badge.parentElement?.style.setProperty('--fa-lang-badge-space', `${width + 13}px`);
  }
});

export function initializeTranslations(root: HTMLElement): void {
  root.querySelectorAll<HTMLElement>('.fa-input-row > .fa-lang-badge').forEach(badge => badgeSpace?.observe(badge));
  root.querySelectorAll<HTMLDialogElement>('.fa-lang-dialog').forEach(dialog => {
    if (initialized.has(dialog)) return;
    initialized.add(dialog);
    dialog.addEventListener('cancel', event => {
      event.preventDefault(); event.stopPropagation(); close(dialog, false);
    });
    dialog.addEventListener('keydown', event => {
      // The resource editor must not trap a child dialog's Tab or Escape.
      event.stopPropagation();
      if (event.key === 'Enter' && event.target instanceof HTMLInputElement) { event.preventDefault(); close(dialog, true); }
      if (event.key === 'Tab') {
        const focusable = [...dialog.querySelectorAll<HTMLElement>('input:not(:disabled), textarea:not(:disabled), button:not(:disabled)')]
          .filter(control => control.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable.at(-1);
        if (first && last && event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (first && last && !event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
  });
  const invalid = root.querySelector<HTMLDialogElement>('.fa-lang-dialog[data-open-on-load]');
  if (invalid && !invalid.open) {
    const editor = invalid.closest<HTMLDialogElement>('.fa-editor');
    if (!editor || editor.open) open(invalid);
  }
  initializeLanguageSwitches(root);
}

/* ---------------------------------------------------------------------------
 * Page-level translation (0.23): the "Editing: language" switch of record
 * pages, Create/Edit dialogs, action forms and settings pages. A scope
 * ([data-lang-scope]) holds one input per language for every translatable
 * field; the switch shows one language at a time, counts what is filled,
 * jumps to the next empty field and locks fields that are the same in every
 * language outside the default one. Without JavaScript every language stays
 * visible with its code. In a Reactive dialog the server renders the same
 * state, so a morph does not undo it; this code then refreshes counts.
 * ------------------------------------------------------------------------- */

type LangControl = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

const scopeOf = (element: Element) => element.closest<HTMLElement>('[data-lang-scope]');
const languageFields = (scope: HTMLElement) => [...scope.querySelectorAll<HTMLElement>('[data-field][data-translatable]')]
  .filter(field => scopeOf(field) === scope);
/** The language parts of a translatable field: input panes, or rich text panels. */
const parts = (field: HTMLElement) => [...field.querySelectorAll<HTMLElement>('[data-lang][data-input]')]
  .filter(part => part.closest('[data-field]') === field);
const controlOf = (part: HTMLElement) => document.getElementById(part.dataset.input || '') as LangControl | null;

function plainText(html: string): string {
  // Like RichText::plain(): a space after each block, so paragraphs don't run together.
  html = html.replace(/<(br|\/p|\/h[2-4]|\/li|\/blockquote|\/pre)\b[^>]*>/gi, '$& ');
  const doc = new DOMParser().parseFromString(html, 'text/html');
  return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
}

function isEmpty(field: HTMLElement, control: LangControl | null): boolean {
  if (!control) return true;
  if (field.hasAttribute('data-rich')) {
    if (/<(img|video|iframe|hr)\b/i.test(control.value)) return false;
    return plainText(control.value.replace(/&nbsp;/g, ' ')) === '';
  }
  return control.value.trim() === '';
}

function counted(scope: HTMLElement): HTMLElement[] {
  return languageFields(scope).filter(field => !field.hasAttribute('data-uncounted') && !field.closest('[hidden]:not([data-admin-tab-panel])'));
}

function refreshCounts(scope: HTMLElement): void {
  const fields = counted(scope);
  const total = fields.length;
  const filled = new Map<string, number>();
  fields.forEach(field => parts(field).forEach(part => {
    const lang = part.dataset.lang || '';
    if (!isEmpty(field, controlOf(part))) filled.set(lang, (filled.get(lang) || 0) + 1);
  }));
  const errors = new Map<string, number>();
  scope.querySelectorAll<HTMLElement>('[data-lang-error]').forEach(part => {
    if (scopeOf(part) !== scope) return;
    const lang = part.dataset.langError || '';
    errors.set(lang, (errors.get(lang) || 0) + 1);
  });
  const switcher = scope.querySelector<HTMLElement>('[data-lang-switch]');
  if (!switcher) return;
  switcher.dataset.total = String(total);
  const countText = (lang: string) => (switcher.dataset.countText || ':filled/:total').replace(':filled', String(filled.get(lang) || 0)).replace(':total', String(total));
  const errorText = (n: number) => (switcher.dataset.errorsText || ':count').replace(':count', String(n));
  switcher.querySelectorAll<HTMLButtonElement>('.fa-seg-btn').forEach(button => {
    const lang = button.dataset.lang || '';
    const n = filled.get(lang) || 0;
    const count = button.querySelector<HTMLElement>('[data-lang-count]');
    if (count) count.textContent = `${n}/${total}`;
    const ring = button.querySelector<SVGElement>('.fa-ring');
    if (ring) {
      ring.classList.toggle('is-full', n >= total);
      ring.classList.toggle('is-empty', n === 0 && total > 0);
      ring.classList.toggle('is-partial', n > 0 && n < total);
      ring.querySelector('.fa-ring-fill')?.setAttribute('stroke-dasharray', `${total ? Math.round(100 * Math.min(n, total) / total) : 0} 100`);
    }
    const bad = errors.get(lang) || 0;
    const badge = button.querySelector<HTMLElement>('[data-lang-errors]');
    if (badge) { badge.hidden = bad === 0; badge.textContent = String(bad); }
    button.classList.toggle('has-error', bad > 0);
    const status = button.querySelector<HTMLElement>('[data-lang-status]');
    if (status) status.textContent = (total ? ', ' + countText(lang) : '') + (bad ? '. ' + errorText(bad) : '');
  });
  switcher.querySelectorAll<HTMLOptionElement>('[data-lang-select] option').forEach(option => {
    const bad = errors.get(option.value) || 0;
    option.textContent = (option.dataset.name || option.value) + (total ? ` · ${filled.get(option.value) || 0}/${total}` : '') + (bad ? ' · ' + errorText(bad) : '');
  });
  const editing = scope.dataset.editing || '';
  const next = switcher.querySelector<HTMLElement>('[data-next-missing]');
  if (next) next.hidden = editing === scope.dataset.defaultLang || (filled.get(editing) || 0) >= total;
}

/** The default language's text under an empty input in another language. */
function refreshReferences(scope: HTMLElement): void {
  const editing = scope.dataset.editing || '';
  scope.querySelectorAll<HTMLElement>('[data-ref-line]').forEach(line => {
    if (scopeOf(line) !== scope) return;
    const field = line.closest<HTMLElement>('[data-field]');
    const source = document.getElementById(line.dataset.refSource || '') as LangControl | null;
    const target = document.getElementById(line.dataset.refInput || '') as LangControl | null;
    if (!field || !source) { line.hidden = true; return; }
    const text = field.hasAttribute('data-rich') ? plainText(source.value) : source.value.trim();
    line.hidden = line.dataset.lang !== editing || text === '' || !isEmpty(field, target);
    if (line.hidden) return;
    const span = line.querySelector<HTMLElement>('[data-ref-text]');
    const more = line.querySelector<HTMLButtonElement>('[data-ref-more]');
    const limit = Number(line.dataset.refLimit || 0);
    const chars = Array.from(text);
    const long = limit > 0 && chars.length > limit;
    const expanded = line.dataset.expanded === 'true';
    if (span) span.textContent = long && !expanded ? chars.slice(0, limit).join('').trimEnd() + '…' : text;
    if (more) {
      more.hidden = !long;
      more.textContent = (expanded ? more.dataset.less : more.dataset.more) || more.textContent;
      more.setAttribute('aria-expanded', String(expanded));
    }
  });
}

function refreshDots(scope: HTMLElement): void {
  const editing = scope.dataset.editing || '';
  languageFields(scope).forEach(field => {
    field.querySelectorAll<HTMLElement>(':scope > .fa-label .fa-dot[data-lang]').forEach(dot => {
      dot.classList.toggle('is-current', dot.dataset.lang === editing);
      const part = parts(field).find(item => item.dataset.lang === dot.dataset.lang);
      if (!part || field.hasAttribute('data-uncounted')) return;
      const filled = !isEmpty(field, controlOf(part));
      dot.classList.toggle('is-filled', filled);
      dot.classList.toggle('is-empty', !filled);
      dot.classList.toggle('is-invalid', part.hasAttribute('data-lang-error'));
    });
  });
}

export function applyEditing(scope: HTMLElement, lang: string, chosen = false): void {
  const languages = [...scope.querySelectorAll<HTMLElement>('[data-lang-switch] [data-lang], [data-lang-switch] option')].map(item => item.dataset.lang || (item as HTMLOptionElement).value);
  if (!languages.includes(lang)) lang = scope.dataset.defaultLang || languages[0] || lang;
  if (scope.dataset.editing !== lang) lastJump.delete(scope);
  scope.dataset.editing = lang;
  scope.querySelectorAll<HTMLInputElement>('[data-lang-editing-value]').forEach(input => {
    if (scopeOf(input) !== scope || input.value === lang) return;
    input.value = lang;
    // A Reactive dialog keeps the language in its state, so any later update renders it.
    if (input.hasAttribute('data-rx-bind')) input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  const isDefault = lang === scope.dataset.defaultLang;
  scope.querySelectorAll<HTMLElement>('[data-lang-panes]').forEach(group => {
    if (scopeOf(group) !== scope) return;
    let current: HTMLElement | null = null;
    group.querySelectorAll<HTMLElement>(':scope > .fa-lang-pane').forEach(pane => {
      const selected = pane.dataset.lang === lang;
      pane.classList.toggle('is-current', selected);
      if (selected) current = pane;
    });
    const label = group.closest('[data-field]')?.querySelector<HTMLLabelElement>(':scope > .fa-label');
    const input = (current as HTMLElement | null)?.dataset.input;
    if (label && input && document.getElementById(input)) label.htmlFor = input;
  });
  // Values that are the same in every language are changed in the default language only.
  scope.querySelectorAll<HTMLElement>('[data-same-all]').forEach(field => {
    if (scopeOf(field) !== scope) return;
    field.classList.toggle('is-locked', !isDefault);
    [...field.children].forEach(child => {
      if (child.matches('.fa-label')) return;
      if (child.matches('.fa-same-note')) { (child as HTMLElement).hidden = isDefault; return; }
      (child as HTMLElement).inert = !isDefault;
    });
  });
  const name = (code: string) => scope.querySelector<HTMLElement>(`[data-lang-switch] .fa-seg-btn[data-lang="${CSS.escape(code)}"] .fa-seg-name`)?.textContent
    || scope.querySelector<HTMLOptionElement>(`[data-lang-switch] option[value="${CSS.escape(code)}"]`)?.dataset.name?.split(' · ')[0] || code;
  scope.querySelectorAll<HTMLElement>('[data-lang-other]').forEach(other => {
    if (scopeOf(other) !== scope) return;
    other.hidden = isDefault || !scope.querySelector('[data-same-all]');
    other.textContent = isDefault ? '' : (other.dataset.template || '').replace(':language', name(lang));
  });
  scope.querySelectorAll<HTMLElement>('[data-admin-rich-editor]').forEach(editor => {
    if (scopeOf(editor) === scope) editor.dispatchEvent(new CustomEvent('flo-admin:editing-language', { detail: lang }));
  });
  scope.querySelectorAll<HTMLButtonElement>('[data-lang-switch] .fa-seg-btn').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.lang === lang)));
  scope.querySelectorAll<HTMLSelectElement>('[data-lang-switch] [data-lang-select]').forEach(select => { select.value = lang; });
  refreshDots(scope);
  refreshReferences(scope);
  refreshCounts(scope);
  // The record page's address keeps the language, so Reload and Back return to it.
  if (chosen && scope.hasAttribute('data-lang-url')) {
    const url = new URL(location.href);
    if (isDefault) url.searchParams.delete('editing'); else url.searchParams.set('editing', lang);
    if (url.href !== location.href) {
      history.replaceState(history.state, '', url);
      document.dispatchEvent(new CustomEvent('flo-admin:url-replaced'));
    }
  }
}

export function initializeLanguageSwitches(root: HTMLElement): void {
  const scopes = [...root.querySelectorAll<HTMLElement>('[data-lang-scope]')];
  if (root.matches('[data-lang-scope]')) scopes.unshift(root);
  scopes.forEach(scope => {
    const switcher = [...scope.querySelectorAll<HTMLElement>('[data-lang-switch]')].find(item => scopeOf(item) === scope);
    if (!switcher) return;
    switcher.hidden = false;
    scope.classList.add('is-switching');
    applyEditing(scope, scope.dataset.editing || scope.dataset.defaultLang || '');
  });
}

const lastJump = new WeakMap<HTMLElement, HTMLElement>();

/** Next missing: the next empty field in the language being edited, after the focused one. */
function nextMissing(scope: HTMLElement): void {
  const lang = scope.dataset.editing || '';
  const empty = counted(scope).map(field => ({ field, part: parts(field).find(part => part.dataset.lang === lang) }))
    .filter((item): item is { field: HTMLElement; part: HTMLElement } => Boolean(item.part) && isEmpty(item.field, controlOf(item.part!)));
  if (!empty.length) return;
  // From the field being edited, else the one the last jump went to (the button itself has focus now).
  const active = document.activeElement instanceof Element ? document.activeElement.closest<HTMLElement>('[data-field]') : null;
  const from = active && scope.contains(active) ? active : lastJump.get(scope) ?? null;
  const target = (from?.isConnected ? empty.find(item => item.field !== from && Boolean(from.compareDocumentPosition(item.field) & Node.DOCUMENT_POSITION_FOLLOWING)) : undefined) || empty[0];
  lastJump.set(scope, target.field);
  // Reveal it first: a form tab that is not shown, or a collapsed section.
  const panel = target.field.closest<HTMLElement>('[data-admin-tab-panel]');
  if (panel?.hidden) scope.querySelector<HTMLButtonElement>(`[data-admin-tab="${CSS.escape(panel.id)}"]`)?.click();
  const details = target.field.closest('details');
  if (details && !details.open) details.open = true;
  const editable = target.part.querySelector<HTMLElement>('[contenteditable="true"]');
  const focusable = editable ?? controlOf(target.part);
  target.field.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  focusable?.focus({ preventScroll: true });
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const button = event.target.closest<HTMLButtonElement>('[data-lang-switch] .fa-seg-btn');
  const scope = button ? scopeOf(button) : null;
  if (button && scope) { applyEditing(scope, button.dataset.lang || '', true); return; }
  const next = event.target.closest<HTMLButtonElement>('[data-next-missing]');
  if (next && scopeOf(next)) { nextMissing(scopeOf(next)!); return; }
  const more = event.target.closest<HTMLButtonElement>('[data-ref-more]');
  const line = more?.closest<HTMLElement>('[data-ref-line]');
  if (more && line) {
    line.dataset.expanded = String(line.dataset.expanded !== 'true');
    const lineScope = scopeOf(line);
    if (lineScope) refreshReferences(lineScope);
  }
});
document.addEventListener('change', event => {
  const select = event.target;
  if (!(select instanceof HTMLSelectElement) || !select.matches('[data-lang-switch] [data-lang-select]')) return;
  const scope = scopeOf(select);
  if (scope) applyEditing(scope, select.value, true);
});
document.addEventListener('input', event => {
  const control = event.target;
  if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement)) return;
  const scope = scopeOf(control);
  if (!scope || !scope.classList.contains('is-switching') || control.closest('[data-lang-switch]')) return;
  // A corrected value is no longer counted as an error in the switch.
  const part = control.closest<HTMLElement>('[data-lang][data-input]');
  if (part && part.dataset.input === control.id) part.removeAttribute('data-lang-error');
  refreshDots(scope);
  refreshReferences(scope);
  refreshCounts(scope);
});
// A Create/Edit dialog opened from a record page being translated (a relation manager)
// starts in the page's language, so a unit is translated like its property.
document.addEventListener('flo-reactive:dialog-opened', event => {
  const root = event.target instanceof HTMLElement ? event.target : null;
  const dialog = root?.querySelector<HTMLElement>('dialog[data-lang-scope]');
  const page = document.querySelector<HTMLElement>('.fa-panel [data-lang-scope][data-lang-url].is-switching');
  const lang = page?.dataset.editing;
  // A page in its default language leaves the dialog in the language last saved in.
  if (!dialog || !lang || lang === page?.dataset.defaultLang || dialog.dataset.editing === lang || dialog.querySelector('[data-lang-error]')) return;
  applyEditing(dialog, lang, true);
});
