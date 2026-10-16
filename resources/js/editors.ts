/*
 * Rich text editors load their script (Tiptap, about 100 KB gzipped) only on
 * pages that have one. Each editor carries the script address it needs.
 */
type Bundle = { mount: (root: ParentNode) => void };
let loading: Promise<Bundle> | null = null;

function bundle(src: string): Promise<Bundle> {
  const ready = (window as unknown as { FloAdminEditor?: Bundle }).FloAdminEditor;
  if (ready) return Promise.resolve(ready);
  loading ??= new Promise<Bundle>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.defer = true;
    script.addEventListener('load', () => {
      const loaded = (window as unknown as { FloAdminEditor?: Bundle }).FloAdminEditor;
      if (loaded) resolve(loaded); else reject(new Error('Editor script did not start.'));
    });
    script.addEventListener('error', () => { loading = null; reject(new Error('Editor script failed to load.')); });
    document.head.append(script);
  });
  return loading;
}

export function initializeEditors(root: ParentNode = document): void {
  const src = root.querySelector<HTMLElement>('[data-admin-rich-editor]:not(.is-ready)')?.dataset.script;
  if (!src) return;
  let url: URL;
  try { url = new URL(src, location.href); } catch { return; }
  if (url.origin !== location.origin) return;
  // Without the script the plain HTML textareas stay usable.
  bundle(url.href).then(loaded => loaded.mount(root)).catch(() => undefined);
}
