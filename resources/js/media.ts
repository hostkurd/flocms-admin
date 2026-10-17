/**
 * The media library in the browser (0.18), built from resources/js/media/:
 *  - the Media page: the media browser in "manage" mode (folders, selection, batch actions,
 *    details, uploads with a queue and the duplicate check);
 *  - the picker (window.FloAdminMedia.pick): the same browser in a dialog, "choose" mode,
 *    for media fields, the rich text editor and settings pages;
 *  - media fields: a framed card with preview, gallery tiles to drag into order, and drop to upload.
 * Without JavaScript the page and the fields work with plain forms.
 */
import { config } from './media/api';
import { MediaBrowser, type Query } from './media/browser';
import { editDetails } from './media/batch';
import { initializeMediaFields } from './media/field';
import { pick } from './media/picker';

export type { MediaItem } from './media/api';

const pages = new WeakSet<HTMLElement>();

function mountPage(root: HTMLElement): void {
  if (pages.has(root)) return;
  const cfg = config();
  if (!cfg) return;
  pages.add(root);
  let start: { query?: Partial<Query> } = {};
  try { start = JSON.parse(root.dataset.mediaBrowser || '{}'); } catch { /* defaults */ }
  const browser: MediaBrowser = new MediaBrowser(root, cfg, {
    mode: 'manage', query: start.query, syncUrl: true, queueHost: document.querySelector<HTMLElement>('.fa-panel') ?? document.body,
    editMany: (items, done) => editDetails(cfg, items, done, { folders: browser.folderData() }),
  });
  // The page's own Upload and New folder buttons work through the browser; the plain upload form is for pages without JavaScript.
  document.querySelector<HTMLElement>('[data-media-upload-form]')?.setAttribute('hidden', '');
  document.querySelector<HTMLElement>('[data-media-upload-button]')?.addEventListener('click', event => { event.preventDefault(); browser.chooseFiles(); });
  document.querySelector<HTMLElement>('[data-media-new-folder]')?.addEventListener('click', event => { event.preventDefault(); browser.newFolder(); });
}

function copyButtons(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('[data-media-copy]').forEach(button => {
    if (button.dataset.mediaReady === 'true') return;
    button.dataset.mediaReady = 'true';
    const field = document.getElementById(button.dataset.mediaCopy ?? '') as HTMLInputElement | null;
    if (!field || !navigator.clipboard) return;
    button.hidden = false;
    const label = button.textContent ?? '';
    button.addEventListener('click', () => {
      void navigator.clipboard.writeText(field.value).then(() => {
        button.textContent = button.dataset.copied ?? label;
        window.setTimeout(() => { button.textContent = label; }, 1500);
      });
    });
  });
}

export function initializeMedia(root: ParentNode): void {
  (window as unknown as { FloAdminMedia?: { pick: typeof pick } }).FloAdminMedia = { pick };
  root.querySelectorAll<HTMLElement>('[data-media-browser]').forEach(mountPage);
  initializeMediaFields(root);
  copyButtons(root);
}
