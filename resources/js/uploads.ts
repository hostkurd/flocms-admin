/** Multipart upload transport for Admin CRUD dialogs; no file bytes in state. */
export function hasFiles(form: HTMLFormElement): boolean {
  return [...form.querySelectorAll<HTMLInputElement>('[data-upload-field]')].some(input => Boolean(input.files?.length));
}
export async function stageUploads(form: HTMLFormElement): Promise<void> {
  if (form.dataset.uploadBusy === 'true') throw new Error('busy');
  form.dataset.uploadBusy = 'true';
  const buttons = [...form.querySelectorAll<HTMLButtonElement>('button[type=submit]:not(:disabled)')];
  buttons.forEach(button => { button.disabled = true; });
  try {
    for (const input of form.querySelectorAll<HTMLInputElement>('[data-upload-field]')) {
      if (!input.files?.length) continue;
      const wrapper = input.closest<HTMLElement>('[data-admin-upload]')!;
      const status = wrapper.querySelector<HTMLElement>('[data-upload-status]')!;
      status.className = 'fa-help'; status.textContent = wrapper.dataset.uploadLoading || 'Uploading…';
      const values: Record<string, string | Record<string, string>> = {};
      for (const [name, value] of new FormData(form)) {
        if (typeof value !== 'string' || name.startsWith('_upload_control[') || name.startsWith('_remove_upload[')) continue;
        const translated = /^([A-Za-z_][A-Za-z0-9_]*)\[([a-z0-9-]+)\]$/.exec(name);
        if (translated) {
          const current = values[translated[1]];
          const group = typeof current === 'object' ? current : {};
          group[translated[2]] = value; values[translated[1]] = group;
        } else if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) values[name] = value;
      }
      const body = new FormData();
      body.append('file', input.files[0]); body.append('_field', input.dataset.uploadField!);
      body.append('_draft', JSON.stringify(values)); body.append('_token', form.dataset.uploadCsrf!);
      body.append('_operation', form.dataset.uploadOperation!); body.append('_placement', form.dataset.uploadPlacement!);
      body.append('_name', form.dataset.uploadAction!); body.append('_id', form.dataset.uploadId!);
      input.disabled = true;
      const notify = (name: string, detail: object) => input.dispatchEvent(new CustomEvent(name, { detail }));
      try {
        // XHR rather than fetch: it reports upload progress (shown by FileDrop).
        const result = await new Promise<{ value: string; url: string }>((resolve, reject) => {
          const request = new XMLHttpRequest();
          request.open('POST', form.dataset.uploadUrl!);
          request.timeout = 120000;
          request.withCredentials = true;
          request.setRequestHeader('Accept', 'application/json');
          const failed = () => reject(new Error(wrapper.dataset.uploadError || 'Upload failed. Check the file type, size, permissions, and session, then try again.'));
          request.upload.addEventListener('progress', event => notify('fa-upload:progress', { loaded: event.loaded, total: event.lengthComputable ? event.total : 0 }));
          request.addEventListener('load', () => {
            if (request.status < 200 || request.status >= 300) { failed(); return; }
            try { resolve(JSON.parse(request.responseText) as { value: string; url: string }); } catch { failed(); }
          });
          request.addEventListener('error', failed);
          request.addEventListener('timeout', failed);
          notify('fa-upload:progress', { loaded: 0, total: 0 });
          request.send(body);
        });
        wrapper.querySelector<HTMLInputElement>('[data-upload-value]')!.value = result.value;
        input.value = ''; status.className = 'fa-help'; status.textContent = wrapper.dataset.uploadReady || 'File ready to save.';
        input.removeAttribute('aria-invalid');
        const remove = wrapper.querySelector<HTMLInputElement>('[data-upload-remove], [data-filedrop-remove-box]'); if (remove) remove.checked = false;
        notify('fa-upload:done', { url: result.url });
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Upload failed.';
        status.className = 'fa-error'; status.textContent = message; input.setAttribute('aria-invalid', 'true');
        notify('fa-upload:failed', { message });
        throw error;
      } finally { input.disabled = false; }
    }
  } finally { delete form.dataset.uploadBusy; buttons.forEach(button => { button.disabled = false; }); }
}

document.addEventListener('change', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.matches('[data-upload-field]')) return;
  const form = input.closest<HTMLFormElement>('form[data-admin-draft]');
  if (form && hasFiles(form) && form.dataset.uploadBusy !== 'true') void stageUploads(form).catch(() => {});
});
