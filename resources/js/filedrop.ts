/**
 * FileDrop (0.18): the panel's file inputs, with drop, preview, progress and
 * type and size checks before anything is sent. Our own code; the markup is
 * a real <input type="file"> in a <label> (src/Ui/FileDrop.php), so forms post
 * the file as usual and everything works without this script too.
 *
 * Upload code reports progress on the input with these events:
 *   fa-upload:progress {loaded, total} · fa-upload:done {url?} · fa-upload:failed {message}
 */

type Strings = Record<'drop' | 'tooBig' | 'wrongType' | 'uploading' | 'ready' | 'removed' | 'files' | 'replace' | 'undo' | 'cancel' | 'remove', string>;

/** "1.5 MB", kept in one left-to-right piece (LRI … PDI) so right-to-left text doesn't show "MB 1.5". */
export function fileSize(bytes: number): string {
  const text = bytes >= 1048576 ? `${Math.round(bytes / 104857.6) / 10} MB` : bytes >= 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${bytes} B`;
  return `⁦${text}⁩`;
}

/** Whether a file matches an accept list of MIME types ("image/*" too). An unknown browser type is left to the server. */
export function accepts(accept: string, file: File): boolean {
  const types = accept.split(',').map(type => type.trim().toLowerCase()).filter(Boolean);
  if (types.length === 0 || file.type === '') return true;
  const type = file.type.toLowerCase();
  return types.some(allowed => allowed === type || (allowed.endsWith('/*') && type.startsWith(allowed.slice(0, -1))));
}

function element<K extends keyof HTMLElementTagNameMap>(tag: K, className = '', text = ''): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

function fill(template: string, values: Record<string, string>): string {
  return template.replace(/:([a-z]+)/g, (match, key: string) => values[key] ?? match);
}

function badge(box: HTMLElement, name: string): void {
  const extension = (name.split('.').pop() || 'file').slice(0, 4).toUpperCase();
  box.replaceChildren();
  box.classList.add('is-file');
  box.classList.toggle('is-pdf', extension === 'PDF');
  box.textContent = extension;
  box.setAttribute('aria-hidden', 'true');
}

function thumb(file: File | null, name: string): HTMLElement {
  const box = element('span', 'fa-filedrop-thumb');
  if (file && file.type.startsWith('image/')) {
    // A preview before uploading; a strict Content-Security-Policy may refuse blob: images,
    // then the type badge shows until the server's own preview arrives.
    const image = element('img');
    image.alt = '';
    const local = URL.createObjectURL(file);
    image.addEventListener('load', () => URL.revokeObjectURL(local), { once: true });
    image.addEventListener('error', () => { URL.revokeObjectURL(local); if (image.src === local) badge(box, name); }, { once: true });
    image.src = local;
    box.append(image);
  } else {
    badge(box, name);
  }
  return box;
}

/** Show the stored file's preview (same origin, so any policy allows it). */
function serverPreview(chosen: HTMLElement, url: string): void {
  const box = chosen.querySelector<HTMLElement>('.fa-filedrop-thumb');
  if (!box || !url || box.dataset.image !== 'true') return;
  const image = element('img');
  image.alt = '';
  image.src = url;
  box.classList.remove('is-file', 'is-pdf');
  box.removeAttribute('aria-hidden');
  box.replaceChildren(image);
}

// Reactive updates morph the markup in place and can drop classes the script added,
// so those are put back on every run; listeners are added once per element.
const enhanced = new WeakSet<HTMLElement>();

function enhance(drop: HTMLElement): void {
  const input = drop.querySelector<HTMLInputElement>('.fa-filedrop-input');
  if (!input) return;
  drop.classList.add('is-enhanced');
  const removeControl = drop.querySelector<HTMLButtonElement>('[data-filedrop-remove]');
  if (removeControl) removeControl.hidden = false;
  if (enhanced.has(drop)) return;
  enhanced.add(drop);
  let strings: Strings;
  try { strings = JSON.parse(drop.dataset.strings || '{}') as Strings; } catch { strings = {} as Strings; }
  const maxBytes = Number(drop.dataset.maxBytes) || 0;
  const accept = drop.dataset.accept || input.accept || '';
  const chosen = drop.querySelector<HTMLElement>('[data-filedrop-chosen]')!;
  const error = drop.querySelector<HTMLElement>('[data-filedrop-error]')!;
  const current = drop.querySelector<HTMLElement>('[data-filedrop-current]');
  const removeBox = drop.querySelector<HTMLInputElement>('[data-filedrop-remove-box]');
  const removeButton = drop.querySelector<HTMLButtonElement>('[data-filedrop-remove]');
  // A form that uploads before saving keeps the stored value in a hidden field; Cancel puts it back.
  const upload = drop.closest<HTMLElement>('[data-admin-upload]');
  const value = upload?.querySelector<HTMLInputElement>('[data-upload-value]') ?? null;
  const initialValue = value?.value ?? null;
  const hadFile = drop.classList.contains('has-file');
  let note: HTMLElement | null = null;

  const showError = (text: string) => {
    error.textContent = text;
    error.hidden = text === '';
    drop.classList.toggle('is-invalid', text !== '');
    if (text) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
  };

  const reset = () => {
    chosen.replaceChildren();
    chosen.hidden = true;
    drop.classList.toggle('has-file', hadFile && !(removeBox?.checked ?? false));
    if (current) current.hidden = removeBox?.checked ?? false;
  };

  const showRemoved = () => {
    note?.remove();
    note = element('p', 'fa-filedrop-note');
    note.setAttribute('role', 'status');
    const undo = element('button', '', strings.undo || 'Undo');
    undo.type = 'button';
    undo.addEventListener('click', () => {
      if (removeBox) { removeBox.checked = false; removeBox.dispatchEvent(new Event('change', { bubbles: true })); }
      note?.remove(); note = null;
      reset();
      removeButton?.focus();
    });
    note.append(element('span', '', strings.removed), undo);
    drop.insertBefore(note, error);
    undo.focus();
  };

  if (removeButton && removeBox) {
    removeButton.hidden = false;
    removeButton.addEventListener('click', () => {
      removeBox.checked = true;
      removeBox.dispatchEvent(new Event('change', { bubbles: true }));
      if (current) current.hidden = true;
      drop.classList.remove('has-file');
      showRemoved();
    });
    if (removeBox.checked) showRemoved();
  }

  const renderChosen = (files: File[]) => {
    chosen.replaceChildren();
    showError('');
    note?.remove(); note = null;
    if (files.length === 0) { reset(); return; }
    if (files.length > 1) {
      const row = element('div', 'fa-filedrop-row');
      const info = element('div', 'fa-filedrop-info');
      info.append(element('span', 'fa-filedrop-name', fill(strings.files || ':count', { count: String(files.length) })),
        element('span', 'fa-filedrop-meta', files.map(file => file.name).join(', ')));
      row.append(thumb(null, 'files'), info);
      chosen.append(row);
    } else {
      const file = files[0];
      const row = element('div', 'fa-filedrop-row');
      const info = element('div', 'fa-filedrop-info');
      const meta = element('span', 'fa-filedrop-meta', fileSize(file.size));
      meta.dataset.filedropMeta = '';
      info.append(element('span', 'fa-filedrop-name', file.name), meta);
      const replace = element('label', 'fa-btn fa-btn-sm', strings.replace || '');
      replace.htmlFor = input.id;
      const cancel = element('button', 'fa-icon-btn');
      cancel.type = 'button';
      cancel.setAttribute('aria-label', fill(strings.remove || ':name', { name: file.name }));
      cancel.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
      cancel.addEventListener('click', () => {
        input.value = '';
        if (value && initialValue !== null) value.value = initialValue;
        reset();
        input.focus();
      });
      const preview = thumb(file, file.name);
      if (file.type.startsWith('image/')) preview.dataset.image = 'true';
      row.append(preview, info);
      if (replace.textContent) row.append(replace);
      row.append(cancel);
      chosen.append(row);
    }
    chosen.hidden = false;
    if (current) current.hidden = true;
    drop.classList.add('has-file');
  };

  input.addEventListener('change', event => {
    const files = [...(input.files ?? [])];
    for (const file of files) {
      const problem = !accepts(accept, file) ? fill(strings.wrongType, { name: file.name })
        : maxBytes > 0 && file.size > maxBytes ? fill(strings.tooBig, { name: file.name, size: fileSize(file.size), max: fileSize(maxBytes) }) : '';
      if (problem) {
        // Refused here: nothing is sent, and the form's own upload code never sees it.
        event.stopImmediatePropagation();
        input.value = '';
        reset();
        showError(problem);
        return;
      }
    }
    renderChosen(files);
  });

  const bar = () => {
    let track = chosen.querySelector<HTMLElement>('.fa-filedrop-bar');
    if (!track) {
      track = element('div', 'fa-filedrop-bar is-waiting');
      track.setAttribute('role', 'progressbar');
      track.setAttribute('aria-valuemin', '0');
      track.setAttribute('aria-valuemax', '100');
      track.append(element('i'));
      chosen.querySelector('.fa-filedrop-info')?.append(track);
    }
    return track;
  };
  input.addEventListener('fa-upload:progress', event => {
    const { loaded, total } = (event as CustomEvent<{ loaded: number; total: number }>).detail;
    const track = bar();
    const meta = chosen.querySelector<HTMLElement>('[data-filedrop-meta]');
    if (meta) meta.textContent = strings.uploading;
    if (total > 0) {
      const percent = Math.min(100, Math.round(loaded / total * 100));
      track.classList.remove('is-waiting');
      track.setAttribute('aria-valuenow', String(percent));
      track.querySelector('i')!.style.width = `${percent}%`;
    }
  });
  input.addEventListener('fa-upload:done', event => {
    chosen.querySelector('.fa-filedrop-bar')?.remove();
    serverPreview(chosen, (event as CustomEvent<{ url?: string }>).detail.url || '');
    const meta = chosen.querySelector<HTMLElement>('[data-filedrop-meta]');
    if (meta) { meta.textContent = strings.ready; meta.classList.add('is-ok'); }
  });
  input.addEventListener('fa-upload:failed', event => {
    chosen.querySelector('.fa-filedrop-bar')?.remove();
    showError((event as CustomEvent<{ message: string }>).detail.message);
  });

  // Dropping files anywhere on the component.
  let depth = 0;
  const carriesFiles = (event: DragEvent) => !input.disabled && Boolean(event.dataTransfer?.types.includes('Files'));
  drop.addEventListener('dragenter', event => {
    if (!carriesFiles(event)) return;
    event.preventDefault();
    depth++;
    drop.classList.add('is-over');
  });
  drop.addEventListener('dragover', event => {
    if (!carriesFiles(event)) return;
    event.preventDefault();
    event.dataTransfer!.dropEffect = 'copy';
  });
  drop.addEventListener('dragleave', () => {
    depth = Math.max(0, depth - 1);
    if (depth === 0) drop.classList.remove('is-over');
  });
  drop.addEventListener('drop', event => {
    if (!carriesFiles(event)) return;
    event.preventDefault();
    depth = 0;
    drop.classList.remove('is-over');
    const dropped = [...event.dataTransfer!.files];
    if (dropped.length === 0) return;
    const transfer = new DataTransfer();
    (input.multiple ? dropped : dropped.slice(0, 1)).forEach(file => transfer.items.add(file));
    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

/** Enhance every FileDrop under root (also after Reactive updates). */
export function initializeFileDrops(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-admin-filedrop]').forEach(enhance);
}
