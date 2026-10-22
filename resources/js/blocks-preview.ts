/*
 * Pages › Blocks preview (0.33): the toolbar's widths, and in the frame (the
 * website's page, same origin) a style and a Copy button for each label. The
 * labels are styled from here, so the site's Content-Security-Policy needs no
 * inline styles.
 */
const WIDTHS: Record<string, string> = { desktop: '100%', tablet: '820px', phone: '390px' };

function decorate(frame: HTMLIFrameElement, box: HTMLElement): void {
  let doc: Document | null = null;
  try { doc = frame.contentDocument; } catch { return; }
  if (!doc) return;
  doc.querySelectorAll<HTMLElement>('[data-flo-preview-label]').forEach(label => {
    if (label.dataset.ready) return;
    label.dataset.ready = '1';
    Object.assign(label.style, {
      display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px 12px', padding: '8px 12px', margin: '0',
      border: '1px dashed #c3cad5', borderRadius: '8px', background: '#f7f8fa', color: '#344054',
      font: '500 13px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif',
    });
    const code = label.querySelector<HTMLElement>('[data-flo-preview-selector]');
    if (!code) return;
    Object.assign(code.style, { direction: 'ltr', unicodeBidi: 'isolate', font: '12px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', color: '#5925dc', background: '#f4f3ff', padding: '2px 6px', borderRadius: '4px' });
    label.querySelector<HTMLElement>('em')?.style.setProperty('color', '#667085');
    const button = doc!.createElement('button');
    button.type = 'button';
    button.textContent = box.dataset.copy || 'Copy selector';
    Object.assign(button.style, { marginInlineStart: 'auto', minHeight: '30px', padding: '0 10px', border: '1px solid #d0d5dd', borderRadius: '6px', background: '#fff', color: '#344054', font: 'inherit', cursor: 'pointer' });
    button.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(code.textContent || ''); } catch { return; }
      button.textContent = box.dataset.copied || 'Copied';
      setTimeout(() => { button.textContent = box.dataset.copy || 'Copy selector'; }, 1500);
    });
    label.append(button);
  });
}

export function initializeBlocksPreview(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-blocks-preview]').forEach(box => {
    if (box.dataset.ready) return;
    box.dataset.ready = '1';
    const frame = box.querySelector<HTMLIFrameElement>('[data-preview-frame]');
    if (!frame) return;
    const widths = box.querySelector<HTMLElement>('[data-preview-widths]');
    if (widths) widths.hidden = false;
    const buttons = [...box.querySelectorAll<HTMLButtonElement>('[data-preview-width]')];
    buttons.forEach(button => button.addEventListener('click', () => {
      frame.style.width = WIDTHS[button.dataset.previewWidth || 'desktop'] || '100%';
      buttons.forEach(other => { other.setAttribute('aria-pressed', other === button ? 'true' : 'false'); other.classList.toggle('is-active', other === button); });
    }));
    frame.addEventListener('load', () => decorate(frame, box));
    if (frame.contentDocument?.readyState === 'complete') decorate(frame, box);
  });
}
