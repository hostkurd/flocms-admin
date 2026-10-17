/*
 * Images, videos and embeds in the editor: width and position stored as the
 * classes of rich-text.css (rt-w-60, rt-align-center, rt-wrap-left), shown in a
 * node view with corner and side grips to resize them. Dragging is smooth; sizes are kept in 5 % steps
 * of the text column and keep the media's proportions.
 */
import { Extension } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';
import type { EditorView, NodeView } from '@tiptap/pm/view';
import { NodeSelection } from '@tiptap/pm/state';

export const POSITIONS = ['left', 'center', 'right', 'wrap-left', 'wrap-right'] as const;
export type Position = typeof POSITIONS[number];

export function readSize(element: Element): number | null {
  for (const name of element.classList) {
    const match = /^rt-w-(\d{2,3})$/.exec(name);
    if (match) { const value = Number(match[1]); if (value >= 10 && value <= 100 && value % 5 === 0) return value; }
  }
  return null;
}

export function readPosition(element: Element): Position | null {
  for (const name of element.classList) {
    const match = /^rt-(align-(left|center|right)|wrap-(left|right))$/.exec(name);
    if (match) return (match[2] ?? `wrap-${match[3]}`) as Position;
  }
  return null;
}

export function mediaClasses(attrs: Record<string, unknown>): string[] {
  const classes: string[] = [];
  const size = Number(attrs.size);
  if (size >= 10 && size <= 100) classes.push(`rt-w-${size}`);
  const position = attrs.align as Position | null;
  if (position && (POSITIONS as readonly string[]).includes(position)) classes.push(position.startsWith('wrap-') ? `rt-${position}` : `rt-align-${position}`);
  return classes;
}

/** Attributes shared by image, video and embed nodes; rendered by each node's renderHTML. */
export function mediaAttributes() {
  return {
    size: { default: null, rendered: false, parseHTML: (element: HTMLElement) => readSize(element) },
    align: { default: null, rendered: false, parseHTML: (element: HTMLElement) => readPosition(element) },
  };
}

const HANDLES = ['nw', 'ne', 'sw', 'se', 'w', 'e'] as const;

type ViewOptions = {
  node: PMNode; view: EditorView; getPos: () => number | undefined;
  /** Draws the media itself and returns a function that updates it for a changed node. */
  render: (node: PMNode) => { element: HTMLElement; update: (node: PMNode) => void };
  caption?: (node: PMNode) => string;
  strings: Record<string, string>;
};

/** The node view: the media in a frame, six grips, a width label and the caption. */
export function mediaView(options: ViewOptions): NodeView {
  let node = options.node;
  const dom = document.createElement('div');
  dom.className = 'fa-media';
  dom.dataset.kind = node.type.name;
  const frame = document.createElement('div');
  frame.className = 'fa-media-frame';
  const media = options.render(node);
  frame.append(media.element);
  const label = document.createElement('span');
  label.className = 'fa-media-size';
  label.setAttribute('aria-hidden', 'true');
  frame.append(label);
  for (const handle of HANDLES) {
    const grip = document.createElement('span');
    grip.className = `fa-media-handle is-${handle}`;
    grip.dataset.handle = handle;
    grip.title = options.strings.resize ?? '';
    grip.setAttribute('aria-hidden', 'true');
    frame.append(grip);
  }
  dom.append(frame);
  const caption = document.createElement('span');
  caption.className = 'fa-media-caption';
  if (options.caption) dom.append(caption);

  const apply = (current: PMNode) => {
    dom.className = ['fa-media', ...mediaClasses(current.attrs), dom.classList.contains('ProseMirror-selectednode') ? 'ProseMirror-selectednode' : ''].filter(Boolean).join(' ');
    const text = options.caption?.(current) ?? '';
    caption.textContent = text;
    caption.hidden = text === '';
    label.textContent = (options.strings.width_now ?? ':size%').replace(':size', String(current.attrs.size ?? Math.round(dom.offsetWidth / columnWidth() * 100 || 100)));
  };

  const columnWidth = () => {
    const editor = options.view.dom as HTMLElement;
    const style = getComputedStyle(editor);
    return editor.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
  };

  // Dragging a grip: width follows the pointer, snapped to 5 % of the text column.
  frame.addEventListener('pointerdown', event => {
    const grip = (event.target as Element).closest<HTMLElement>('.fa-media-handle');
    if (!grip || event.button !== 0) return;
    event.preventDefault();
    event.stopPropagation();
    const handle = grip.dataset.handle!;
    const sign = handle.includes('w') ? -1 : 1;
    // A centred item grows on both sides, so the pointer moves half as far.
    const factor = node.attrs.align === 'center' ? 2 : 1;
    const startX = event.clientX;
    const startWidth = dom.getBoundingClientRect().width;
    const column = columnWidth();
    let size = Number(node.attrs.size) || Math.min(100, Math.max(10, Math.round(startWidth / column * 20) * 5));
    grip.setPointerCapture(event.pointerId);
    dom.classList.add('is-resizing');
    // The frame follows the pointer smoothly; the size it keeps is the nearest 5 % step
    // (the label shows it), which it settles on when the grip is let go.
    const move = (moveEvent: PointerEvent) => {
      const width = startWidth + sign * (moveEvent.clientX - startX) * factor;
      const exact = Math.min(100, Math.max(10, width / column * 100));
      size = Math.min(100, Math.max(10, Math.round(exact / 5) * 5));
      dom.style.width = `${exact}%`;
      label.textContent = (options.strings.width_now ?? ':size%').replace(':size', String(size));
    };
    const up = () => {
      grip.removeEventListener('pointermove', move);
      grip.removeEventListener('pointerup', up);
      grip.removeEventListener('pointercancel', up);
      dom.classList.remove('is-resizing');
      // A short glide from where the pointer left it to the kept step.
      dom.classList.add('is-settling');
      window.setTimeout(() => dom.classList.remove('is-settling'), 200);
      dom.style.width = '';
      const position = options.getPos();
      if (position === undefined) return;
      const { tr } = options.view.state;
      tr.setNodeMarkup(position, undefined, { ...node.attrs, size });
      tr.setSelection(NodeSelection.create(tr.doc, position));
      options.view.dispatch(tr);
    };
    grip.addEventListener('pointermove', move);
    grip.addEventListener('pointerup', up);
    grip.addEventListener('pointercancel', up);
  });

  apply(node);
  return {
    dom,
    update(next) {
      if (next.type !== node.type) return false;
      node = next;
      media.update(next);
      apply(next);
      return true;
    },
    selectNode() { dom.classList.add('ProseMirror-selectednode'); apply(node); },
    deselectNode() { dom.classList.remove('ProseMirror-selectednode'); },
    stopEvent(event) { return event.target instanceof Element && event.target.closest('.fa-media-handle') !== null; },
    ignoreMutation() { return true; },
  };
}

/** Text alignment, indent and direction on paragraphs and headings, as classes and dir. */
export const BlockFormat = Extension.create({
  name: 'blockFormat',
  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading'],
        attributes: {
          textAlign: {
            default: null,
            parseHTML: element => {
              for (const name of element.classList) { const match = /^rt-text-(left|center|right|justify)$/.exec(name); if (match) return match[1]; }
              // Pasted text (e.g. from Word) brings alignment as a style. A strict CSP does not
              // apply style attributes, so read the attribute text rather than element.style.
              const match = /(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\b/i.exec(element.getAttribute('style') ?? '');
              return match ? match[1].toLowerCase() : null;
            },
            renderHTML: attributes => (attributes.textAlign ? { class: `rt-text-${attributes.textAlign}` } : {}),
          },
          indent: {
            default: 0,
            parseHTML: element => {
              for (const name of element.classList) { const match = /^rt-indent-([1-4])$/.exec(name); if (match) return Number(match[1]); }
              return 0;
            },
            renderHTML: attributes => (attributes.indent > 0 ? { class: `rt-indent-${attributes.indent}` } : {}),
          },
        },
      },
      {
        types: ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem'],
        attributes: {
          dir: {
            default: null,
            parseHTML: element => { const dir = element.getAttribute('dir'); return dir === 'rtl' || dir === 'ltr' ? dir : null; },
            renderHTML: attributes => (attributes.dir ? { dir: attributes.dir } : {}),
          },
        },
      },
    ];
  },
});

/**
 * Word and other office programs paste HTML full of conditional comments,
 * namespaced tags and mso- styles. Keep the content and drop the rest; the
 * editor schema and the server clean what remains.
 */
export function cleanPastedHtml(html: string): string {
  if (!/urn:schemas-microsoft-com|mso-|class="?Mso|<o:p/i.test(html)) return html;
  return html
    .replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<(xml|style)\b[\s\S]*?<\/\1>/gi, '')
    .replace(/<(meta|link)\b[^>]*>/gi, '')
    .replace(/<\/?(o|w|v|m|st1):[^>]*>/gi, '')
    // List bullets Word writes as text, inside spans marked mso-list:Ignore.
    .replace(/<span[^>]*mso-list:\s*Ignore[^>]*>[\s\S]*?<\/span>/gi, '')
    .replace(/\sclass="?Mso[A-Za-z0-9]*"?/g, '')
    .replace(/<p[^>]*>(\s|&nbsp;)*<\/p>/gi, '');
}
