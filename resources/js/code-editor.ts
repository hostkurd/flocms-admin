/*
 * The HTML code editor (0.30): line numbers, highlighting (tags, attributes,
 * strings, comments), the cursor's line and column, Tab to indent and Format.
 * The textarea stays the field: the highlighting is a <pre> drawn under it.
 */
const ready = new WeakSet<HTMLElement>();

const escapeHtml = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** HTML as coloured spans, from the text alone (never parsed into the page). */
export function highlight(code: string): string {
  let out = '';
  const pattern = /(<!--[\s\S]*?(?:-->|$))|(<\/?)([A-Za-z][\w:-]*)((?:\s+[^\s"'>\/=]+(?:\s*=\s*(?:"[^"]*"?|'[^']*'?|[^\s>]+))?)*)\s*(\/?>)?|(&[#A-Za-z0-9]+;)/g;
  let last = 0;
  for (const match of code.matchAll(pattern)) {
    out += escapeHtml(code.slice(last, match.index));
    if (match[1] !== undefined) out += `<span class="tk-comment">${escapeHtml(match[1])}</span>`;
    else if (match[6] !== undefined) out += `<span class="tk-entity">${escapeHtml(match[6])}</span>`;
    else {
      const attrs = (match[4] || '').replace(/(\s+)([^\s"'>\/=]+)(?:(\s*=\s*)("[^"]*"?|'[^']*'?|[^\s>]+))?/g, (_all, space: string, name: string, equals?: string, value?: string) =>
        escapeHtml(space) + `<span class="tk-attr">${escapeHtml(name)}</span>` + (equals ? escapeHtml(equals) + `<span class="tk-string">${escapeHtml(value || '')}</span>` : ''));
      out += `<span class="tk-tag">${escapeHtml(match[2])}${escapeHtml(match[3])}</span>${attrs}` + (match[5] ? `<span class="tk-tag">${escapeHtml(match[5])}</span>` : '');
    }
    last = (match.index || 0) + match[0].length;
  }
  return out + escapeHtml(code.slice(last)) + '\n';
}

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

/** Each tag on its own line, indented by nesting; text, scripts and styles kept as they are. */
/** Text-level elements stay inside their line: "<p>Read <a href="#">more</a></p>" is one line. */
const INLINE = new Set(['a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'img', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr']);

export function format(code: string): string {
  // Scripts, styles, <pre> and <textarea> are kept as they are (their "<" is not a tag).
  const kept: string[] = [];
  code = code.replace(/<(script|style|pre|textarea)\b[\s\S]*?<\/\1\s*>/gi, block => `\u0000${kept.push(block) - 1}\u0000`);
  const tokens = code.match(/\u0000\d+\u0000|<!--[\s\S]*?-->|<\/?[A-Za-z][^>]*>|[^<\u0000]+|[<\u0000]/g) || [];
  const lines: string[] = [];
  let depth = 0;
  let text = '';
  let opened = -1; // the line of the last opening tag, while nothing followed it on a line of its own
  const indent = () => '  '.repeat(Math.max(0, depth));
  const line = (value: string) => { lines.push(indent() + value); opened = -1; };
  const flush = () => { const value = text.replace(/\s+/g, ' ').trim(); text = ''; if (value) line(value); };
  for (const token of tokens) {
    const block = /^\u0000(\d+)\u0000$/.exec(token);
    if (block) { flush(); line(kept[Number(block[1])].trim()); continue; }
    if (token.startsWith('<!--')) { flush(); line(token.trim()); continue; }
    const tag = /^<(\/?)([A-Za-z][\w:-]*)/.exec(token);
    if (!tag || INLINE.has(tag[2].toLowerCase())) { text += token; continue; }
    const name = tag[2].toLowerCase();
    if (tag[1]) {
      depth = Math.max(0, depth - 1);
      // Only text inside: one line, "<p>One</p>"
      if (opened >= 0 && opened === lines.length - 1) { lines[opened] += text.replace(/\s+/g, ' ').trim() + token; text = ''; opened = -1; continue; }
      flush(); line(token); continue;
    }
    flush();
    line(token.trim());
    if (!VOID.has(name) && !token.endsWith('/>') && !token.startsWith('<!')) { opened = lines.length - 1; depth++; }
  }
  flush();
  return lines.join('\n');
}

function update(editor: HTMLElement): void {
  const input = editor.querySelector<HTMLTextAreaElement>('[data-code-input]');
  const code = editor.querySelector<HTMLElement>('[data-code-highlight] code');
  const lines = editor.querySelector<HTMLElement>('[data-code-lines]');
  if (!input || !code || !lines) return;
  code.innerHTML = highlight(input.value);
  const count = input.value.split('\n').length;
  if (lines.childElementCount !== count) lines.textContent = Array.from({ length: count }, (_, i) => String(i + 1)).join('\n');
  scroll(editor);
}

function scroll(editor: HTMLElement): void {
  const input = editor.querySelector<HTMLTextAreaElement>('[data-code-input]');
  const pre = editor.querySelector<HTMLElement>('[data-code-highlight]');
  const lines = editor.querySelector<HTMLElement>('[data-code-lines]');
  if (!input || !pre || !lines) return;
  pre.scrollTop = input.scrollTop; pre.scrollLeft = input.scrollLeft;
  lines.scrollTop = input.scrollTop;
}

function position(editor: HTMLElement): void {
  const input = editor.querySelector<HTMLTextAreaElement>('[data-code-input]');
  const pos = editor.querySelector<HTMLElement>('[data-code-pos]');
  if (!input || !pos) return;
  const before = input.value.slice(0, input.selectionStart);
  const line = before.split('\n').length;
  const column = before.length - before.lastIndexOf('\n');
  pos.textContent = `${line}:${column}`;
}

export function initializeCodeEditors(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-code-editor]').forEach(editor => {
    if (ready.has(editor) || editor.closest('template')) return;
    ready.add(editor);
    editor.classList.add('is-ready');
    editor.querySelectorAll<HTMLElement>('[data-code-bar], [data-code-lines], [data-code-highlight]').forEach(part => { part.hidden = false; });
    const input = editor.querySelector<HTMLTextAreaElement>('[data-code-input]')!;
    if (input.disabled || input.readOnly) editor.querySelector<HTMLElement>('[data-code-format]')?.setAttribute('hidden', '');
    input.addEventListener('input', () => { update(editor); position(editor); });
    input.addEventListener('scroll', () => scroll(editor));
    input.addEventListener('keyup', () => position(editor));
    input.addEventListener('click', () => position(editor));
    input.addEventListener('keydown', event => {
      // Tab indents (Escape first lets Tab leave the field, for keyboard users).
      if (event.key === 'Escape') { input.dataset.tabLeaves = '1'; return; }
      if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey || input.dataset.tabLeaves === '1') { delete input.dataset.tabLeaves; return; }
      event.preventDefault();
      const { selectionStart: start, selectionEnd: end, value } = input;
      if (event.shiftKey) {
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        if (value.slice(lineStart, lineStart + 2) === '  ') { input.setRangeText('', lineStart, lineStart + 2, 'end'); input.setSelectionRange(Math.max(lineStart, start - 2), Math.max(lineStart, end - 2)); }
      } else {
        input.setRangeText('  ', start, end, 'end');
      }
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    editor.querySelector<HTMLButtonElement>('[data-code-format]')?.addEventListener('click', () => {
      input.focus();
      input.select();
      // Through the editing commands, so Undo brings the old text back.
      if (!document.execCommand('insertText', false, format(input.value))) input.value = format(input.value);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.setSelectionRange(0, 0);
      input.scrollTop = 0;
    });
    update(editor);
  });
}
