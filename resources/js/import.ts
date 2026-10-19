/**
 * The Import page (0.25): drag-and-drop with a chunked upload and a progress
 * bar, the batches of a running check, import or undo, and the match screen's
 * state badges. Every screen also works without JavaScript: the upload form
 * posts the file, and the Continue button runs one batch per click.
 */

const CHUNK_FALLBACK = 2 * 1024 * 1024;

/** Open a panel page the way a link click would (panel navigation, or a normal load). */
function go(url: string): void {
  const link = document.createElement('a');
  link.href = url;
  link.hidden = true;
  (document.querySelector('.fa-panel') || document.body).append(link);
  link.click();
  link.remove();
}

async function post(url: string, data: Record<string, string | Blob>, csrf: string): Promise<{ status: number; body: Record<string, unknown> }> {
  const body = new FormData();
  for (const [name, value] of Object.entries(data)) body.append(name, value);
  body.append('_token', csrf);
  const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
  let json: Record<string, unknown> = {};
  try { json = await response.json() as Record<string, unknown>; } catch { /* not JSON */ }
  return { status: response.status, body: json };
}

/* ---------------------------------------------------------------- upload */

function initializeUpload(form: HTMLFormElement): void {
  if (form.dataset.importReady) return;
  form.dataset.importReady = 'true';
  const found = form.querySelector<HTMLInputElement>('[data-import-file]');
  const zone = form.querySelector<HTMLElement>('[data-import-drop]');
  const chosen = form.querySelector<HTMLElement>('[data-import-chosen]');
  const bar = form.querySelector<HTMLProgressElement>('[data-import-bar]');
  const error = form.querySelector<HTMLElement>('[data-import-error]');
  const submit = form.querySelector<HTMLButtonElement>('[data-import-submit]');
  if (!found || !zone) return;
  const input: HTMLInputElement = found;
  const drop: HTMLElement = zone;
  let file: File | null = null;
  let busy = false;

  const showError = (message: string) => {
    if (!error) return;
    error.textContent = message;
    error.hidden = message === '';
  };
  const choose = (next: File | null) => {
    file = next;
    showError('');
    if (chosen) { chosen.textContent = next ? next.name : ''; chosen.hidden = !next; }
    drop.classList.toggle('has-file', Boolean(next));
    const max = Number(form.dataset.maxBytes || 0);
    if (next && max > 0 && next.size > max) { showError(form.dataset.tooLarge || ''); file = null; }
    if (file) void send();
  };

  input.addEventListener('change', () => choose(input.files?.[0] || null));
  ['dragenter', 'dragover'].forEach(name => drop.addEventListener(name, event => {
    event.preventDefault();
    if (!busy) drop.classList.add('is-over');
  }));
  ['dragleave', 'dragend'].forEach(name => drop.addEventListener(name, () => drop.classList.remove('is-over')));
  drop.addEventListener('drop', event => {
    event.preventDefault();
    drop.classList.remove('is-over');
    if (busy) return;
    const dropped = (event as DragEvent).dataTransfer?.files?.[0];
    if (dropped) choose(dropped);
  });
  form.addEventListener('submit', event => {
    // With a file chosen, the chunked upload sends it; without one the server says so.
    if (!file && !input.files?.length) return;
    event.preventDefault();
    if (!file && input.files?.[0]) choose(input.files[0]);
    else if (file && !busy) void send();
  });

  async function send(): Promise<void> {
    if (!file || busy) return;
    busy = true;
    const csrf = form.dataset.csrf || '';
    const base = form.dataset.uploadUrl || '';
    const failed = form.dataset.failed || 'Upload failed.';
    form.setAttribute('aria-busy', 'true');
    if (submit) submit.disabled = true;
    input.disabled = true;
    if (bar) { bar.hidden = false; bar.value = 0; bar.max = Math.max(1, file.size); }
    if (chosen && form.dataset.sending) chosen.textContent = form.dataset.sending.replace(':name', file.name);
    let uploadId = '';
    try {
      const started = await post(base + '/start', { name: file.name, bytes: String(file.size) }, csrf);
      if (started.status !== 200 || typeof started.body.upload_id !== 'string') throw new Error(String(started.body.error || failed));
      uploadId = started.body.upload_id;
      const size = Number(started.body.chunk_size) || CHUNK_FALLBACK;
      let offset = Number(started.body.received) || 0;
      let retries = 0;
      while (offset < file.size) {
        const part = file.slice(offset, offset + size);
        const sent = await post(base + '/chunk', { upload_id: uploadId, offset: String(offset), chunk: new File([part], 'chunk') }, csrf);
        if (sent.status === 409 && typeof sent.body.received === 'number' && retries < 3) { offset = sent.body.received; retries++; continue; }
        if (sent.status !== 200) throw new Error(String(sent.body.error || failed));
        offset = Number(sent.body.received) || offset + part.size;
        retries = 0;
        if (bar) bar.value = offset;
      }
      const done = await post(base + '/complete', { upload_id: uploadId }, csrf);
      if (done.status !== 200 || typeof done.body.url !== 'string') { uploadId = ''; throw new Error(String(done.body.error || failed)); }
      uploadId = '';
      go(done.body.url);
    } catch (problem) {
      if (uploadId) void post(base + '/abort', { upload_id: uploadId }, csrf).catch(() => {});
      showError(problem instanceof Error && problem.message ? problem.message : failed);
      if (bar) bar.hidden = true;
      if (chosen) { chosen.textContent = file?.name || ''; }
      file = null;
      input.value = '';
    } finally {
      busy = false;
      form.removeAttribute('aria-busy');
      if (submit) submit.disabled = false;
      input.disabled = false;
    }
  }
}

/* ------------------------------------------------------------ the stepper */

type StepResult = { status: string; done: number; total: number; counts: Record<string, number>; finished: boolean; url: string; text: string; error?: string };

function initializeRun(box: HTMLElement): void {
  if (box.dataset.importReady) return;
  box.dataset.importReady = 'true';
  const url = box.dataset.stepUrl || '';
  const csrf = box.dataset.csrf || '';
  const progress = box.querySelector<HTMLProgressElement>('[data-import-progress]');
  const count = box.querySelector<HTMLElement>('[data-import-count]');
  const continueForm = box.querySelector<HTMLElement>('[data-import-continue]');
  // The page moves on by itself; Continue is for pages without JavaScript or after a failure.
  if (continueForm) continueForm.hidden = true;
  let failures = 0;

  const show = (result: StepResult) => {
    if (progress) { progress.max = Math.max(1, result.total); progress.value = Math.min(result.done, progress.max); }
    if (count) count.textContent = result.text;
    box.querySelectorAll<HTMLElement>('[data-import-counter]').forEach(counter => {
      const value = result.counts[counter.dataset.importCounter || ''] || 0;
      counter.textContent = value.toLocaleString(document.documentElement.lang || undefined);
    });
  };

  const tick = async (): Promise<void> => {
    if (!box.isConnected) return;
    try {
      const { status, body } = await post(url, {}, csrf);
      if (!box.isConnected) return;
      const result = body as unknown as StepResult;
      if (status === 409 && typeof result.url === 'string') { go(result.url); return; }
      if (status !== 200 || typeof result.done !== 'number') throw new Error(String(result.error || status));
      failures = 0;
      show(result);
      if (result.finished) { go(result.url); return; }
      window.setTimeout(() => void tick(), 50);
    } catch {
      failures++;
      // A slow server or a dropped connection: try again a little later, then let the person continue.
      if (failures < 4) { window.setTimeout(() => void tick(), 1000 * failures); return; }
      if (continueForm) continueForm.hidden = false;
    }
  };
  void tick();
}

/* --------------------------------------------------------- match badges */

function updateState(select: HTMLSelectElement): void {
  const state = select.closest('tr')?.querySelector<HTMLElement>('[data-import-state]');
  if (!state) return;
  const name = select.value === '' ? 'skipped' : select.value === (select.dataset.auto || '') ? 'matched' : 'chosen';
  state.className = `fa-import-state is-${name}`;
  state.textContent = state.dataset[name] || state.textContent;
  select.removeAttribute('aria-invalid');
}

document.addEventListener('change', event => {
  if (event.target instanceof HTMLSelectElement && event.target.hasAttribute('data-import-target')) updateState(event.target);
  if (event.target instanceof HTMLInputElement && event.target.type === 'radio' && event.target.closest('.fa-import-modes')) {
    event.target.closest('.fa-import-modes')!.querySelectorAll('.fa-import-mode').forEach(mode => {
      mode.classList.toggle('is-on', Boolean(mode.querySelector<HTMLInputElement>('input')?.checked));
    });
  }
});

export function initializeImports(root: HTMLElement): void {
  root.querySelectorAll<HTMLFormElement>('form[data-import-upload]').forEach(initializeUpload);
  root.querySelectorAll<HTMLElement>('[data-import-run]').forEach(initializeRun);
}
