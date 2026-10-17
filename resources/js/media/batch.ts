/**
 * Edit details (0.18): one item, or several stepped through one by one ("3 of 8"),
 * with the preview beside the title, the alt text in every language, the folder and
 * the focal point. The Media page's batch action and a media field's "Edit details".
 */
import { ApiError, button, element, focalFromClick, getJson, placeFocal, postForm, preview, t, type Config, type Details, type Folders, type MediaItem } from './api';
import { announce } from './ui';

export type EditOptions = {
  /** The folder select (the Media page); fields leave the folder alone. */
  folders?: Folders | null;
  /** Open on the focal point editor (a field's "Set focal point"). */
  focal?: boolean;
};

export function editDetails(cfg: Config, items: MediaItem[], done: (changed: MediaItem[]) => void, options: EditOptions = {}): void {
  if (items.length === 0) return;
  const opener = document.activeElement as HTMLElement | null;
  const dialog = element('dialog', 'fa-mb-dialog fa-mb-editor');
  const many = items.length > 1;
  const changed = new Map<number, MediaItem>();
  const doneIds = new Set<number>(items.filter(item => item.kind !== 'image' || item.hasAlt).map(item => item.id));
  let index = 0;
  let details: Details | null = null;
  let focal: { x: number; y: number } | null = null;
  let copyTitle = false;
  let lastTitle = '';
  let loading: AbortController | null = null;

  const head = element('div', 'fa-mb-dialog-head');
  const title = element('h2', 'fa-mb-dialog-title', t(cfg, 'edit_details'));
  title.id = `fa-mb-edit-${Math.random().toString(36).slice(2)}`;
  dialog.setAttribute('aria-labelledby', title.id);
  const position = element('span', 'fa-badge fa-badge-gray');
  const progress = element('span', 'fa-mb-keys');
  const previous = button('fa-btn fa-btn-sm', t(cfg, 'previous'), 'chevronStart');
  previous.querySelector('svg')?.classList.add('fa-flip-rtl');
  const next = button('fa-btn fa-btn-sm', t(cfg, 'next'), 'chevronEnd');
  next.querySelector('svg')?.classList.add('fa-flip-rtl');
  next.append(next.querySelector('svg')!);
  const close = button('fa-icon-btn', t(cfg, 'close'), 'x', true);
  head.append(title);
  if (many) head.append(position, progress, element('span', 'fa-mb-spacer'), previous, next); else head.append(element('span', 'fa-mb-spacer'));
  head.append(close);

  const body = element('div', 'fa-mb-editor-body');
  const stage = element('div', 'fa-mb-editor-stage');
  const form = element('form', 'fa-mb-editor-form');
  form.noValidate = true;
  body.append(stage, form);
  const strip = element('div', 'fa-mb-strip');
  strip.setAttribute('role', 'list');
  strip.setAttribute('aria-label', t(cfg, 'selected_items'));
  const foot = element('div', 'fa-mb-dialog-foot');
  const status = element('p', 'fa-mb-form-status');
  status.setAttribute('role', 'status');
  const cancel = element('button', 'fa-btn fa-btn-ghost', t(cfg, 'close'));
  cancel.type = 'button';
  const save = element('button', many ? 'fa-btn' : 'fa-btn fa-btn-primary', t(cfg, 'save'));
  save.type = 'button';
  const saveNext = element('button', 'fa-btn fa-btn-primary', t(cfg, 'save_next'));
  saveNext.type = 'button';
  foot.append(status, element('span', 'fa-mb-spacer'));
  if (many) foot.append(element('span', 'fa-mb-keys', 'Ctrl + Enter'));
  foot.append(cancel, save);
  if (many) foot.append(saveNext);
  dialog.append(head, body);
  if (many) dialog.append(strip);
  dialog.append(foot);

  const renderStrip = () => {
    strip.replaceChildren(...items.map((item, i) => {
      const chip = preview(item, `fa-mb-strip-chip${i === index ? ' is-on' : ''}${doneIds.has(item.id) ? ' is-done' : ''}`);
      chip.setAttribute('role', 'listitem');
      chip.tabIndex = -1;
      chip.title = item.label;
      chip.addEventListener('click', () => void open(i));
      return chip;
    }));
  };

  const renderStage = () => {
    stage.replaceChildren();
    if (!details) return;
    const frame = element('div', 'fa-mb-preview is-large');
    if (details.kind === 'image') {
      const image = element('img');
      image.src = details.url; image.alt = ''; image.decoding = 'async';
      frame.append(image);
      if (focal) { const dot = element('span', 'fa-mb-focal'); dot.setAttribute('aria-hidden', 'true'); frame.append(dot); placeFocal(frame, image, dot, focal); }
      if (cfg.can.update) {
        frame.classList.add('is-focal');
        frame.tabIndex = 0;
        frame.setAttribute('role', 'button');
        frame.setAttribute('aria-label', t(cfg, 'focal_help'));
        frame.addEventListener('click', event => {
          focal = focalFromClick(image, event);
          renderStage();
          stage.querySelector<HTMLElement>('.fa-mb-preview')?.focus();
        });
        frame.addEventListener('keydown', event => {
          const step = event.shiftKey ? 10 : 2;
          const point = focal ?? { x: 50, y: 50 };
          const rtl = getComputedStyle(frame).direction === 'rtl';
          const moves: Record<string, [number, number]> = { ArrowLeft: [rtl ? step : -step, 0], ArrowRight: [rtl ? -step : step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
          const move = moves[event.key];
          if (!move) return;
          event.preventDefault();
          focal = { x: Math.max(0, Math.min(100, point.x + move[0])), y: Math.max(0, Math.min(100, point.y + move[1])) };
          renderStage();
          stage.querySelector<HTMLElement>('.fa-mb-preview')?.focus();
        });
      }
    } else {
      const video = element('video');
      video.src = details.url; video.controls = true; video.preload = 'metadata'; video.playsInline = true;
      frame.append(video);
    }
    const line = element('div', 'fa-mb-editor-line');
    const file = element('span', 'fa-muted', [details.name, details.dims, details.size].filter(Boolean).join(' · '));
    file.dir = 'auto';
    line.append(file, element('span', 'fa-mb-spacer'));
    if (details.kind === 'image' && cfg.can.update) {
      line.append(element('span', 'fa-muted', t(cfg, 'focal_click')));
      if (focal) {
        const clear = element('button', 'fa-mb-link-button', t(cfg, 'remove'));
        clear.type = 'button';
        clear.addEventListener('click', () => { focal = null; renderStage(); });
        line.append(clear);
      }
    }
    stage.append(frame, line);
  };

  const renderForm = () => {
    form.replaceChildren();
    if (!details) { form.append(element('p', 'fa-muted', t(cfg, 'loading'))); return; }
    const uid = `fa-mb-edit-${details.id}`;
    const title = element('input', 'fa-input');
    title.id = `${uid}-title`; title.name = 'title'; title.maxLength = 190; title.dir = 'auto'; title.disabled = !cfg.can.update;
    title.value = details.title || (copyTitle && lastTitle ? lastTitle : '');
    const titleLabel = element('label', 'fa-label', t(cfg, 'item_title'));
    titleLabel.htmlFor = title.id;
    form.append(wrap(titleLabel, title));
    const alts = element('fieldset', 'fa-mb-alts');
    const legend = element('legend', 'fa-label', t(cfg, 'alt'));
    alts.append(legend);
    for (const language of cfg.languages) {
      const row = element('label', 'fa-mb-alt');
      const badge = element('span', 'fa-mb-lang', language.code.toUpperCase());
      badge.title = language.name;
      const input = element('input', 'fa-input');
      input.name = `alt[${language.code}]`; input.value = details.alts?.[language.code] ?? ''; input.maxLength = 250;
      input.dir = language.dir; input.lang = language.code; input.disabled = !cfg.can.update;
      input.setAttribute('aria-label', `${t(cfg, 'alt')} (${language.name})`);
      if (language.code === cfg.defaultLanguage && details.kind === 'image') input.placeholder = t(cfg, 'alt_placeholder');
      row.append(badge, input);
      alts.append(row);
    }
    form.append(alts);
    if (options.folders) {
      const folder = element('select', 'fa-input fa-select');
      folder.id = `${uid}-folder`; folder.name = 'folder'; folder.disabled = !cfg.can.update;
      const none = element('option', '', t(cfg, 'unfiled')); none.value = '';
      folder.append(none, ...options.folders.tree.map(entry => { const option = element('option', '', `${'— '.repeat(entry.depth)}${entry.name}`); option.value = String(entry.id); return option; }));
      folder.value = details.folder ? String(details.folder) : '';
      const label = element('label', 'fa-label', t(cfg, 'folder'));
      label.htmlFor = folder.id;
      form.append(wrap(label, folder));
    }
    if (many) {
      const copy = element('label', 'fa-check');
      const box = element('input');
      box.type = 'checkbox'; box.checked = copyTitle;
      box.addEventListener('change', () => { copyTitle = box.checked; });
      copy.append(box, document.createTextNode(` ${t(cfg, 'copy_title')}`));
      form.append(copy);
    }
    const usage = element('p', 'fa-mb-hint', details.usage.length ? `${t(cfg, 'used_in')}: ${details.usage.map(place => place.label).join(', ')}` : t(cfg, 'used_nowhere'));
    usage.dir = 'auto';
    form.append(usage);
  };

  const wrap = (label: HTMLElement, control: HTMLElement) => { const field = element('div', 'fa-mb-field'); field.append(label, control); return field; };

  const open = async (i: number) => {
    index = i;
    details = null;
    status.textContent = '';
    status.classList.remove('is-error');
    position.textContent = t(cfg, 'batch_progress', { current: i + 1, total: items.length });
    progress.textContent = t(cfg, 'batch_alt_count', { done: doneIds.size, total: items.length });
    previous.disabled = i === 0;
    next.disabled = i === items.length - 1;
    saveNext.disabled = false;
    renderStrip();
    renderForm();
    renderStage();
    loading?.abort();
    loading = new AbortController();
    try {
      const data = await getJson<{ item: Details }>(`${cfg.urls.details}/${items[i].id}`, loading.signal);
      if (index !== i) return;
      details = data.item;
      focal = details.focalX != null && details.focalY != null ? { x: details.focalX, y: details.focalY } : null;
      renderForm();
      renderStage();
      (options.focal && details.kind === 'image' ? stage.querySelector<HTMLElement>('.fa-mb-preview') : form.querySelector<HTMLElement>(details.kind === 'image' && !details.hasAlt ? `[name="alt[${cfg.defaultLanguage}]"]` : '[name="title"]'))?.focus();
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      status.textContent = t(cfg, 'failed');
      status.classList.add('is-error');
    }
  };

  const store = async (): Promise<boolean> => {
    if (!details || !cfg.can.update) return true;
    const data = new FormData(form);
    if (details.kind === 'image') { data.set('focal_x', focal ? String(focal.x) : ''); data.set('focal_y', focal ? String(focal.y) : ''); }
    save.disabled = saveNext.disabled = true;
    try {
      const result = await postForm<{ item: Details }>(details.saveUrl, data, cfg.csrf);
      details = result.item;
      changed.set(result.item.id, result.item);
      items[index] = { ...items[index], ...result.item };
      if (result.item.kind !== 'image' || result.item.hasAlt) doneIds.add(result.item.id); else doneIds.delete(result.item.id);
      lastTitle = String(data.get('title') ?? '');
      status.classList.remove('is-error');
      status.textContent = t(cfg, 'saved');
      return true;
    } catch (error) {
      status.classList.add('is-error');
      status.textContent = error instanceof ApiError && error.message ? error.message : t(cfg, 'failed');
      if (error instanceof ApiError) {
        for (const key of Object.keys(error.errors)) form.querySelector(`[name="${CSS.escape(key.replace(/\.(\w+)$/, '[$1]'))}"]`)?.setAttribute('aria-invalid', 'true');
      }
      return false;
    } finally {
      save.disabled = saveNext.disabled = false;
    }
  };

  const saveAndNext = async () => {
    if (!await store()) return;
    if (index < items.length - 1) void open(index + 1);
    else { announce(t(cfg, 'batch_done', { count: changed.size })); dialog.close(); }
  };

  previous.addEventListener('click', () => void open(index - 1));
  next.addEventListener('click', () => void open(index + 1));
  save.addEventListener('click', async () => { if (await store()) { renderStrip(); progress.textContent = t(cfg, 'batch_alt_count', { done: doneIds.size, total: items.length }); if (!many) dialog.close(); } });
  saveNext.addEventListener('click', () => void saveAndNext());
  form.addEventListener('submit', event => { event.preventDefault(); void (many ? saveAndNext() : store().then(ok => { if (ok) dialog.close(); })); });
  form.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.isComposing && (event.target as HTMLElement).tagName === 'INPUT') { event.preventDefault(); form.requestSubmit(); } });
  dialog.addEventListener('keydown', event => { if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); void (many ? saveAndNext() : store().then(ok => { if (ok) dialog.close(); })); } });
  cancel.addEventListener('click', () => dialog.close());
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => {
    loading?.abort();
    dialog.remove();
    opener?.focus({ preventScroll: true });
    if (changed.size > 0) done([...changed.values()]);
  });
  (document.querySelector('.fa-panel') ?? document.body).append(dialog);
  dialog.showModal();
  void open(0);
}
