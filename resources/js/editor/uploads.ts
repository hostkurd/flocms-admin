/*
 * Media uploads for the rich text editor. Images go in one request; videos in
 * parts of 1 MiB (start → chunk… → complete), so PHP's upload_max_filesize
 * does not limit them and a dropped part is sent again from where it stopped.
 */
type Config = { media: string; csrf: string };

export class UploadError extends Error {}

async function post(config: Config, step: string, body: FormData, signal: AbortSignal): Promise<Record<string, unknown>> {
  body.append('_token', config.csrf);
  const response = await fetch(`${config.media}/${step}`, {
    method: 'POST', body, signal, credentials: 'same-origin',
    headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json' },
  });
  let data: Record<string, unknown> = {};
  try { data = await response.json(); } catch { /* not JSON */ }
  if (response.status === 409 && typeof data.received === 'number') return { ...data, conflict: true };
  if (!response.ok) throw new UploadError(typeof data.error === 'string' ? data.error : '');
  return data;
}

export async function uploadImage(config: Config, file: File, signal: AbortSignal): Promise<{ url: string; width?: number; height?: number }> {
  const body = new FormData();
  body.append('file', file);
  const data = await post(config, 'image', body, signal);
  if (typeof data.url !== 'string') throw new UploadError('');
  return { url: data.url, width: typeof data.width === 'number' ? data.width : undefined, height: typeof data.height === 'number' ? data.height : undefined };
}

export async function uploadVideo(config: Config, file: File, signal: AbortSignal, progress: (percent: number) => void): Promise<{ url: string }> {
  const start = new FormData();
  start.append('name', file.name);
  start.append('size', String(file.size));
  const started = await post(config, 'start', start, signal);
  const id = String(started.upload_id ?? '');
  const size = Math.max(65536, Number(started.chunk_size) || 1048576);
  let offset = Number(started.received) || 0;
  let failures = 0;
  try {
    while (offset < file.size) {
      const part = new FormData();
      part.append('upload_id', id);
      part.append('offset', String(offset));
      part.append('chunk', file.slice(offset, offset + size), 'part');
      try {
        const result = await post(config, 'chunk', part, signal);
        offset = Number(result.received);
        failures = 0;
      } catch (error) {
        // A dropped connection: try the same part again a few times.
        if ((error as Error).name === 'AbortError' || error instanceof UploadError || ++failures > 3) throw error;
        await new Promise(resolve => setTimeout(resolve, 1000 * failures));
      }
      progress(Math.min(99, Math.floor((offset / file.size) * 100)));
    }
    const done = await post(config, 'complete', (() => { const body = new FormData(); body.append('upload_id', id); return body; })(), signal);
    if (typeof done.url !== 'string') throw new UploadError('');
    progress(100);
    return { url: done.url };
  } catch (error) {
    if (id !== '') {
      const body = new FormData();
      body.append('upload_id', id);
      void post(config, 'abort', body, new AbortController().signal).catch(() => undefined);
    }
    throw error;
  }
}
