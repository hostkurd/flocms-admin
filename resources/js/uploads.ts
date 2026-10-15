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
      const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 60000);
      input.disabled = true;
      try {
        const response = await fetch(form.dataset.uploadUrl!, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
        if (!response.ok) throw new Error(wrapper.dataset.uploadError || 'Upload failed. Check the file type, size, permissions, and session, then try again.');
        const result = await response.json() as { value: string; url: string };
        wrapper.querySelector<HTMLInputElement>('[data-upload-value]')!.value = result.value;
        input.value = ''; status.className = 'fa-help'; status.textContent = wrapper.dataset.uploadReady || 'File ready to save.';
        input.removeAttribute('aria-invalid');
        const remove = wrapper.querySelector<HTMLInputElement>('[data-upload-remove]'); if (remove) { remove.checked = false; remove.closest<HTMLElement>('label')!.hidden = false; }
        const preview = wrapper.querySelector<HTMLImageElement>('.fa-upload-preview'); if (preview) preview.src = result.url;
        else if (wrapper.dataset.uploadImage === 'true') {
          const image = document.createElement('img'); image.className = 'fa-upload-preview'; image.src = result.url;
          image.alt = input.labels?.[0]?.textContent || ''; wrapper.insertBefore(image, status);
        }
      } catch (error) {
        status.className = 'fa-error'; status.textContent = error instanceof Error ? error.message : 'Upload failed.'; input.setAttribute('aria-invalid', 'true'); throw error;
      } finally { clearTimeout(timeout); input.disabled = false; }
    }
  } finally { delete form.dataset.uploadBusy; buttons.forEach(button => { button.disabled = false; }); }
}

document.addEventListener('change', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.matches('[data-upload-field]')) return;
  const form = input.closest<HTMLFormElement>('form[data-admin-draft]');
  if (form && hasFiles(form) && form.dataset.uploadBusy !== 'true') void stageUploads(form).catch(() => {});
});
