/*
 * The page editor (0.30): the search result and social card previews follow
 * the title, SEO title, description and social image as they are typed, in
 * the language being edited.
 */
function scopeOf(element: Element): HTMLElement | null {
  return element.closest<HTMLElement>('[data-lang-scope]') || element.closest<HTMLElement>('form');
}

function value(form: HTMLElement, name: string, lang: string): string {
  const field = form.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[name="${CSS.escape(`${name}[${lang}]`)}"]`)
    ?? form.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[name="${CSS.escape(name)}"]`);
  return (field?.value || '').trim();
}

function refresh(preview: HTMLElement): void {
  const form = preview.closest<HTMLElement>('form');
  if (!form) return;
  const scope = scopeOf(preview);
  const lang = scope?.dataset.editing || scope?.dataset.defaultLang || document.documentElement.lang || 'en';
  const fallback = scope?.dataset.defaultLang || lang;
  const title = value(form, 'meta_title', lang) || value(form, 'title', lang) || value(form, 'title', fallback) || preview.dataset.emptyTitle || '';
  const description = value(form, 'meta_description', lang);
  preview.querySelectorAll<HTMLElement>('[data-seo-title]').forEach(element => { element.textContent = title; });
  preview.querySelectorAll<HTMLElement>('[data-seo-desc]').forEach(element => { element.textContent = description; });
  const media = form.querySelector<HTMLElement>('[data-field="social_image"] [data-admin-media-field]');
  const image = preview.querySelector<HTMLElement>('[data-seo-image]');
  if (media && image) {
    let thumb = '';
    try { const items = JSON.parse(media.dataset.items || '[]'); thumb = items[0]?.thumb || items[0]?.url || ''; } catch { thumb = ''; }
    image.classList.toggle('has-image', thumb !== '');
    image.style.backgroundImage = thumb ? `url("${thumb.replace(/["\\]/g, '')}")` : '';
  }
}

export function initializePages(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-seo-preview]').forEach(refresh);
}

const refreshAll = (event: Event) => {
  const form = event.target instanceof Element ? event.target.closest('form') : null;
  form?.querySelectorAll<HTMLElement>('[data-seo-preview]').forEach(refresh);
};
document.addEventListener('input', refreshAll);
document.addEventListener('change', refreshAll);
document.addEventListener('click', event => {
  // The language switch changes what the previews show.
  if (event.target instanceof Element && event.target.closest('[data-lang-switch]')) requestAnimationFrame(() => refreshAll(event));
});
