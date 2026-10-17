/**
 * The media library's browser side (0.18): settings from the panel, JSON requests,
 * and uploads (images in one request, videos in parts) with a folder and the
 * duplicate check.
 */
import { fileSize } from '../filedrop';

export type MediaItem = {
  id: number; kind: 'image' | 'video'; url: string; thumb: string; title: string; label: string; alt: string;
  alts?: Record<string, string>; name: string; mime?: string; bytes?: number; width: number | null; height: number | null;
  thumbWidth: number | null; thumbHeight: number | null; folder?: number | null; focalX?: number | null; focalY?: number | null;
  hasAlt?: boolean; created?: string | null; used?: number; reused?: boolean; editUrl?: string;
};
export type Details = MediaItem & {
  usage: { label: string; url: string | null }[]; inText: boolean; folderLabel: string; uploader: string | null;
  size: string; dims: string; date: string; absoluteUrl: string; editUrl: string; saveUrl: string; replaceUrl: string;
  canReplace: boolean; canDelete: boolean;
};
export type Folder = { id: number; parent: number | null; name: string; depth: number; count: number; children: number };
export type Folders = { tree: Folder[]; counts: Record<string, number> };
export type Language = { code: string; name: string; dir: 'ltr' | 'rtl' };
export type Config = {
  urls: Record<'lookup' | 'details' | 'save' | 'move' | 'folders' | 'delete' | 'upload' | 'video' | 'pending' | 'replace' | 'page', string>;
  csrf: string; maxImage: number; maxVideo: number; chunk: number;
  can: { create: boolean; update: boolean; delete: boolean };
  languages: Language[]; defaultLanguage: string;
  strings: Record<string, string>;
};
export type Duplicate = { token: string; name: string; existing: MediaItem; message: string };
export type UploadOutcome = { item: MediaItem } | { duplicate: Duplicate };

export const VIDEO = /^video\/(mp4|webm)$/;
export const IMAGE = /^image\/(jpeg|png|gif|webp)$/;

export class ApiError extends Error {
  constructor(message: string, readonly status = 0, readonly errors: Record<string, string> = {}, readonly received: number | null = null) { super(message); }
}

let cached: { raw: string; config: Config } | null = null;

/** The panel's media settings (data-admin-media), or null when the library is off for this user. */
export function config(): Config | null {
  const raw = document.querySelector<HTMLElement>('.fa-panel')?.dataset.adminMedia;
  if (!raw) return null;
  if (cached?.raw === raw) return cached.config;
  try { cached = { raw, config: JSON.parse(raw) as Config }; return cached.config; } catch { return null; }
}

/** A text from the panel's media strings, with :name placeholders filled. */
export function t(cfg: Config, key: string, values: Record<string, string | number> = {}): string {
  const text = cfg.strings[key] ?? key;
  return text.replace(/:([a-z_]+)/g, (match, name: string) => (name in values ? String(values[name]) : match));
}

export { fileSize };

export function element<K extends keyof HTMLElementTagNameMap>(tag: K, className = '', text = ''): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

const PATHS: Record<string, string> = {
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  check: '<path d="m5 12 5 5 9-10"/>',
  folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  folderPlus: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10v6M9 13h6"/>',
  folderMove: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M10 13h6M14 11l2 2-2 2"/>',
  library: '<rect x="3" y="7" width="14" height="14" rx="2"/><path d="M7 3h12a2 2 0 0 1 2 2v12"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
  video: '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>',
  play: '<path d="M7 4v16l13-8z" fill="currentColor" stroke="none"/>',
  upload: '<path d="M12 15V4M7 9l5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  pencil: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
  trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
  copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
  target: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
  warn: '<path d="M12 3 2 21h20z"/><path d="M12 10v5M12 18h.01"/>',
  more: '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"/>',
  grip: '<circle cx="9" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="15" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="9" cy="18" r="1.5" fill="currentColor" stroke="none"/><circle cx="15" cy="18" r="1.5" fill="currentColor" stroke="none"/>',
  chevronDown: '<path d="m6 9 6 6 6-6"/>',
  chevronStart: '<path d="m15 18-6-6 6-6"/>',
  chevronEnd: '<path d="m9 18 6-6-6-6"/>',
  external: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
  retry: '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
};

/** A stroke icon in the text colour; rtl flips arrows that point to a side. */
export function icon(name: keyof typeof PATHS | string, size = 16, flip = false): string {
  return `<svg class="fa-icon${flip ? ' fa-flip-rtl' : ''}" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${PATHS[name] ?? ''}</svg>`;
}

export function button(className: string, label: string, iconName?: string, labelHidden = false): HTMLButtonElement {
  const node = element('button', className);
  node.type = 'button';
  if (iconName) node.insertAdjacentHTML('beforeend', icon(iconName));
  if (labelHidden) node.setAttribute('aria-label', label); else node.append(element('span', '', label));
  return node;
}

/** The preview of an item: its thumb, or a video badge. */
export function preview(item: MediaItem, className = 'fa-mb-img'): HTMLElement {
  const box = element('span', className);
  if (item.kind === 'image') {
    const image = element('img');
    image.src = item.thumb; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
    if (item.thumbWidth && item.thumbHeight) { image.width = item.thumbWidth; image.height = item.thumbHeight; }
    if (item.focalX != null && item.focalY != null) image.style.objectPosition = `${item.focalX}% ${item.focalY}%`;
    box.append(image);
  } else {
    const video = element('span', 'fa-mb-video');
    video.innerHTML = icon('video', 28);
    box.append(video);
  }
  return box;
}

export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
  const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal });
  if (!response.ok) throw new ApiError(String(response.status), response.status);
  return await response.json() as T;
}

/** POST form fields (arrays as name[]), as JSON in and out. */
export async function postForm<T>(url: string, data: FormData | Record<string, string | number | null | (string | number)[]>, csrf: string, signal?: AbortSignal): Promise<T> {
  const body = data instanceof FormData ? data : new FormData();
  if (!(data instanceof FormData)) {
    for (const [key, value] of Object.entries(data)) {
      if (Array.isArray(value)) value.forEach(entry => body.append(`${key}[]`, String(entry)));
      else if (value !== null) body.append(key, String(value));
    }
  }
  body.append('_token', csrf);
  const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, signal });
  const result = await response.json().catch(() => ({})) as Record<string, unknown>;
  if (!response.ok) {
    const errors = (result.errors && typeof result.errors === 'object' && !Array.isArray(result.errors) ? result.errors : {}) as Record<string, string>;
    const list = Array.isArray(result.errors) ? result.errors as string[] : [];
    const message = typeof result.error === 'string' ? result.error : Object.values(errors)[0] ?? list[0] ?? '';
    throw new ApiError(message, response.status, errors, typeof result.received === 'number' ? result.received : null);
  }
  return result as T;
}

/** Whether a file can go into the library (optionally only one kind). */
export function fits(file: File, kind: 'image' | 'video' | null): boolean {
  if (kind === 'image') return IMAGE.test(file.type);
  if (kind === 'video') return VIDEO.test(file.type);
  return IMAGE.test(file.type) || VIDEO.test(file.type);
}

export function acceptFor(kind: 'image' | 'video' | null): string {
  if (kind === 'image') return 'image/jpeg,image/png,image/gif,image/webp';
  if (kind === 'video') return 'video/mp4,video/webm';
  return 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm';
}

type UploadOptions = { folder?: number | null; folderPath?: string; duplicates?: 'ask' | 'use' | 'keep'; signal?: AbortSignal };

/**
 * Upload one file into the library. Images go in one request (XHR, for progress),
 * videos in parts. Resolves with the new item, or with a duplicate to decide on.
 */
export async function uploadMedia(cfg: Config, file: File, progress: (percent: number) => void, options: UploadOptions = {}): Promise<UploadOutcome> {
  const folder = options.folder ?? null;
  // A field's ->folder('Listings/Villas'): the server makes the folder when it doesn't exist yet.
  const folderPath = folder === null ? options.folderPath ?? '' : '';
  const duplicates = options.duplicates ?? 'ask';
  if (!cfg.can.create) throw new ApiError(cfg.strings.failed ?? '');
  if (VIDEO.test(file.type)) {
    if (file.size > cfg.maxVideo) throw new ApiError(t(cfg, 'too_big', { name: file.name, max: fileSize(cfg.maxVideo) }));
    const begun = await postForm<{ upload_id: string; chunk_size: number; received: number }>(`${cfg.urls.video}/start`, { name: file.name, bytes: file.size }, cfg.csrf, options.signal);
    const id = String(begun.upload_id ?? '');
    const chunk = Number(begun.chunk_size) || cfg.chunk;
    let offset = Number(begun.received) || 0;
    let resyncs = 0;
    try {
      while (offset < file.size) {
        const part = new FormData();
        part.append('upload_id', id); part.append('offset', String(offset));
        part.append('chunk', file.slice(offset, offset + chunk), 'chunk');
        let status: { received: number };
        try {
          status = await postForm<{ received: number }>(`${cfg.urls.video}/chunk`, part, cfg.csrf, options.signal);
        } catch (error) {
          // The server has a different amount (a part sent twice or lost): go on from there.
          if (error instanceof ApiError && error.received !== null && resyncs < 3) { resyncs++; offset = error.received; continue; }
          throw error;
        }
        offset = Number(status.received) || offset + chunk;
        progress(Math.min(99, Math.round(offset / file.size * 100)));
      }
      const result = await postForm<UploadOutcome>(`${cfg.urls.video}/complete`, { upload_id: id, folder: folder === null ? '' : folder, folder_path: folderPath, duplicates }, cfg.csrf, options.signal);
      progress(100);
      return result;
    } catch (error) {
      void postForm(`${cfg.urls.video}/abort`, { upload_id: id }, cfg.csrf).catch(() => undefined);
      throw error;
    }
  }
  if (file.size > cfg.maxImage) throw new ApiError(t(cfg, 'too_big', { name: file.name, max: fileSize(cfg.maxImage) }));
  return await new Promise<UploadOutcome>((resolve, reject) => {
    const request = new XMLHttpRequest();
    const body = new FormData();
    body.append('files[]', file);
    body.append('_token', cfg.csrf);
    body.append('duplicates', duplicates);
    if (folder !== null) body.append('folder', String(folder));
    else if (folderPath) body.append('folder_path', folderPath);
    request.open('POST', cfg.urls.upload);
    request.setRequestHeader('Accept', 'application/json');
    request.setRequestHeader('X-CSRF-TOKEN', cfg.csrf);
    request.upload.addEventListener('progress', event => { if (event.lengthComputable) progress(Math.min(99, Math.round(event.loaded / event.total * 100))); });
    request.addEventListener('load', () => {
      let result: { items?: MediaItem[]; errors?: string[]; duplicates?: Duplicate[] } = {};
      try { result = JSON.parse(request.responseText); } catch { /* not JSON */ }
      if (result.items?.[0]) { progress(100); resolve({ item: result.items[0] }); return; }
      if (result.duplicates?.[0]) { progress(100); resolve({ duplicate: result.duplicates[0] }); return; }
      reject(new ApiError(result.errors?.[0] ?? cfg.strings.failed ?? '', request.status));
    });
    request.addEventListener('error', () => reject(new ApiError(cfg.strings.failed ?? '')));
    request.addEventListener('abort', () => reject(new ApiError('aborted')));
    options.signal?.addEventListener('abort', () => request.abort(), { once: true });
    request.send(body);
  });
}

/** Answer a held duplicate: keep it as a new item, or drop it. */
export async function settleDuplicate(cfg: Config, token: string, keep: boolean): Promise<MediaItem | null> {
  const result = await postForm<{ item?: MediaItem }>(cfg.urls.pending, { token, action: keep ? 'keep' : 'discard' }, cfg.csrf);
  return result.item ?? null;
}

/** "Listings / Villas" for a folder id from the tree. */
export function folderLabel(folders: Folders, id: number | null | undefined): string {
  const names: string[] = [];
  const byId = new Map(folders.tree.map(folder => [folder.id, folder]));
  let current = id == null ? undefined : byId.get(id);
  const seen = new Set<number>();
  while (current && !seen.has(current.id)) { seen.add(current.id); names.unshift(current.name); current = current.parent == null ? undefined : byId.get(current.parent); }
  return names.join(' / ');
}

/** Where an object-fit: contain image is drawn inside its element (client coordinates). */
function drawn(image: HTMLImageElement): { left: number; top: number; width: number; height: number } {
  const box = image.getBoundingClientRect();
  const ratio = image.naturalWidth && image.naturalHeight ? image.naturalWidth / image.naturalHeight : box.width / Math.max(1, box.height);
  const width = Math.min(box.width, box.height * ratio);
  const height = width / ratio;
  return { left: box.left + (box.width - width) / 2, top: box.top + (box.height - height) / 2, width, height };
}

/** The focal point (percent of the picture) where a click landed. */
export function focalFromClick(image: HTMLImageElement, event: MouseEvent): { x: number; y: number } {
  const area = drawn(image);
  return {
    x: Math.round(Math.max(0, Math.min(1, (event.clientX - area.left) / area.width)) * 100),
    y: Math.round(Math.max(0, Math.min(1, (event.clientY - area.top) / area.height)) * 100),
  };
}

/** Put the focal dot over the drawn picture (again once the image has loaded). */
export function placeFocal(frame: HTMLElement, image: HTMLImageElement, dot: HTMLElement, point: { x: number; y: number }): void {
  const place = () => {
    const area = drawn(image);
    const box = frame.getBoundingClientRect();
    dot.style.left = `${area.left - box.left + area.width * point.x / 100}px`;
    dot.style.top = `${area.top - box.top + area.height * point.y / 100}px`;
  };
  if (image.complete && image.naturalWidth) requestAnimationFrame(place); else image.addEventListener('load', place, { once: true });
}
