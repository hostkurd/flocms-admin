/**
 * Reorder mode: drag rows by their handle (mouse, touch, pen) or move them with
 * the keyboard (Space/Enter picks up, arrows move, Space/Enter drops, Escape
 * cancels). Every drop is saved at once; a failed save puts the rows back.
 * Without JavaScript the page keeps its ↑/↓ buttons, so this file only adds.
 */

type Texts = Record<'saving' | 'saved' | 'failed' | 'picked' | 'moved' | 'dropped' | 'cancelled', string>;

const EDGE = 56; // px from the scroller's edge where dragging scrolls

export function initializeReorder(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-admin-reorder]').forEach(card => {
    if (card.dataset.reorderReady === 'true') return;
    const list = card.querySelector<HTMLOListElement>('[data-reorder-list]');
    if (!list) return;
    card.dataset.reorderReady = 'true';
    new Reorder(card, list);
  });
}

class Reorder {
  private readonly texts: Texts;
  private readonly status: HTMLElement | null;
  private saved: string[];
  private queue: Promise<void> = Promise.resolve();
  private picked: { item: HTMLLIElement; index: number } | null = null;
  private drag: { item: HTMLLIElement; pointer: number; startY: number; startScroll: number; originTop: number; index: number; lastY: number } | null = null;
  private scrolling = 0;
  private scrollEl: HTMLElement | null = null;
  private moving = false; // our own DOM moves blur the handle; that is not the user leaving it

  constructor(private readonly card: HTMLElement, private readonly list: HTMLOListElement) {
    const text = (name: keyof Texts) => card.dataset['text' + name.charAt(0).toUpperCase() + name.slice(1)] || '';
    this.texts = { saving: text('saving'), saved: text('saved'), failed: text('failed'), picked: text('picked'),
      moved: text('moved'), dropped: text('dropped'), cancelled: text('cancelled') };
    this.status = card.querySelector('[data-reorder-status]');
    this.saved = this.order();
    list.querySelectorAll<HTMLElement>('[data-reorder-steps]').forEach(steps => { steps.hidden = true; });
    list.querySelectorAll<HTMLButtonElement>('[data-reorder-handle]').forEach(handle => {
      handle.hidden = false;
      handle.addEventListener('pointerdown', event => this.start(event, handle));
      handle.addEventListener('keydown', event => this.key(event, handle));
      handle.addEventListener('blur', () => { if (!this.moving && this.picked?.item === handle.closest('li')) this.drop(); });
    });
    list.classList.add('is-enhanced');
  }

  private items(): HTMLLIElement[] { return [...this.list.querySelectorAll<HTMLLIElement>(':scope > [data-reorder-id]')]; }
  private order(): string[] { return this.items().map(item => item.dataset.reorderId!); }
  private handle(item: HTMLLIElement): HTMLButtonElement | null { return item.querySelector('[data-reorder-handle]'); }

  private say(template: string, item: HTMLLIElement): void {
    const items = this.items();
    this.message(template.replace(':title', item.dataset.reorderTitle || '')
      .replace(':position', String(items.indexOf(item) + 1)).replace(':total', String(items.length)));
  }

  private message(text: string, failed = false): void {
    if (!this.status) return;
    this.status.textContent = text;
    this.status.classList.toggle('is-error', failed);
  }

  private renumber(): void {
    this.items().forEach((item, index) => {
      const position = item.querySelector('[data-reorder-position]');
      if (position) position.textContent = String(index + 1);
    });
  }

  /* ---------------- Pointer ---------------- */

  private start(event: PointerEvent, handle: HTMLButtonElement): void {
    if (this.drag || (event.pointerType === 'mouse' && event.button !== 0)) return;
    const item = handle.closest<HTMLLIElement>('li');
    if (!item) return;
    event.preventDefault();
    if (this.picked) this.drop();
    handle.focus({ preventScroll: true });
    handle.setPointerCapture(event.pointerId);
    this.scrollEl = this.scroller();
    this.drag = { item, pointer: event.pointerId, startY: event.clientY, lastY: event.clientY, startScroll: this.scrollTop(),
      originTop: this.layoutTop(item), index: this.items().indexOf(item) };
    item.classList.add('is-dragging');
    this.list.classList.add('is-sorting');
    const move = (e: PointerEvent) => { if (e.pointerId === this.drag?.pointer) this.move(e.clientY); };
    const end = (e: PointerEvent) => {
      if (e.pointerId !== this.drag?.pointer) return;
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', end);
      handle.removeEventListener('pointercancel', end);
      this.finish(e.type === 'pointercancel');
    };
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }

  /** The item's top in page coordinates, without its drag offset. */
  private layoutTop(item: HTMLElement): number {
    const transform = item.style.transform;
    item.style.transform = '';
    const top = item.getBoundingClientRect().top + this.scrollTop();
    item.style.transform = transform;
    return top;
  }

  private move(clientY: number): void {
    const drag = this.drag;
    if (!drag) return;
    drag.lastY = clientY;
    const { item } = drag;
    const siblings = this.items().filter(other => other !== item);
    const before = siblings.find(other => {
      const rect = other.getBoundingClientRect();
      return clientY < rect.top + rect.height / 2;
    });
    if (before ? item.nextElementSibling !== before : this.list.lastElementChild !== item) {
      this.list.insertBefore(item, before ?? null);
      this.renumber();
    }
    // Keep the row under the pointer: where it started, plus the pointer's and the page's movement.
    const wanted = drag.originTop + (clientY - drag.startY) + (this.scrollTop() - drag.startScroll);
    item.style.transform = `translateY(${Math.round(wanted - this.layoutTop(item))}px)`;
    this.autoscroll(clientY);
  }

  private finish(cancelled: boolean): void {
    const drag = this.drag;
    if (!drag) return;
    cancelAnimationFrame(this.scrolling);
    this.scrolling = 0;
    drag.item.style.transform = '';
    drag.item.classList.remove('is-dragging');
    this.list.classList.remove('is-sorting');
    this.drag = null;
    this.scrollEl = null;
    if (cancelled) {
      this.place(drag.item, drag.index);
      return;
    }
    if (this.items().indexOf(drag.item) !== drag.index) {
      this.say(this.texts.dropped, drag.item);
      this.save();
    }
  }

  private scroller(): HTMLElement {
    let node: HTMLElement | null = this.list.parentElement;
    while (node && node !== document.body) {
      const style = getComputedStyle(node);
      if (/(auto|scroll)/.test(style.overflowY) && node.scrollHeight > node.clientHeight) return node;
      node = node.parentElement;
    }
    return (document.scrollingElement as HTMLElement) || document.documentElement;
  }

  private scrollTop(): number { return (this.scrollEl ?? this.scroller()).scrollTop; }

  private autoscroll(clientY: number): void {
    const scroller = this.scrollEl ?? this.scroller();
    const isPage = scroller === document.scrollingElement || scroller === document.documentElement;
    const top = isPage ? 0 : scroller.getBoundingClientRect().top;
    const bottom = isPage ? window.innerHeight : scroller.getBoundingClientRect().bottom;
    const speed = clientY < top + EDGE ? -Math.ceil((top + EDGE - clientY) / 4)
      : clientY > bottom - EDGE ? Math.ceil((clientY - (bottom - EDGE)) / 4) : 0;
    cancelAnimationFrame(this.scrolling);
    this.scrolling = 0;
    if (speed === 0 || !this.drag) return;
    this.scrolling = requestAnimationFrame(() => {
      const before = scroller.scrollTop;
      scroller.scrollTop += speed;
      if (scroller.scrollTop !== before && this.drag) this.move(this.drag.lastY);
    });
  }

  /* ---------------- Keyboard ---------------- */

  private key(event: KeyboardEvent, handle: HTMLButtonElement): void {
    const item = handle.closest<HTMLLIElement>('li');
    if (!item || this.drag) return;
    if (event.key === ' ' || event.key === 'Enter') {
      event.preventDefault();
      if (this.picked?.item === item) { this.drop(); return; }
      if (this.picked) this.drop();
      this.picked = { item, index: this.items().indexOf(item) };
      item.classList.add('is-picked');
      handle.setAttribute('aria-pressed', 'true');
      this.say(this.texts.picked, item);
    } else if ((event.key === 'ArrowUp' || event.key === 'ArrowDown') && this.picked?.item === item) {
      event.preventDefault();
      const target = event.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling?.nextElementSibling ?? null;
      if (event.key === 'ArrowUp' && !target) return;
      if (event.key === 'ArrowDown' && !item.nextElementSibling) return;
      this.moving = true;
      this.list.insertBefore(item, target);
      handle.focus({ preventScroll: false });
      this.moving = false;
      this.renumber();
      handle.scrollIntoView({ block: 'nearest' });
      this.say(this.texts.moved, item);
    } else if (event.key === 'Escape' && this.picked?.item === item) {
      event.preventDefault();
      const { index } = this.picked;
      this.release();
      this.moving = true;
      this.place(item, index);
      handle.focus();
      this.moving = false;
      this.say(this.texts.cancelled, item);
    }
  }

  private release(): void {
    if (!this.picked) return;
    this.picked.item.classList.remove('is-picked');
    this.handle(this.picked.item)?.removeAttribute('aria-pressed');
    this.picked = null;
  }

  private drop(): void {
    const picked = this.picked;
    if (!picked) return;
    this.release();
    if (this.items().indexOf(picked.item) !== picked.index) {
      this.say(this.texts.dropped, picked.item);
      this.save();
    }
  }

  private place(item: HTMLLIElement, index: number): void {
    const others = this.items().filter(other => other !== item);
    this.list.insertBefore(item, others[index] ?? null);
    this.renumber();
  }

  /* ---------------- Saving ---------------- */

  /** Saves run one after another; each sends the order as it is when its turn comes. */
  private save(): void {
    this.queue = this.queue.then(() => this.send());
  }

  private async send(): Promise<void> {
    const order = this.order();
    if (order.join('\n') === this.saved.join('\n')) return;
    this.message(this.texts.saving);
    this.card.setAttribute('aria-busy', 'true');
    const body = new FormData();
    body.append('_token', this.card.dataset.reorderCsrf || '');
    body.append('_filters', this.card.dataset.reorderFilters || '{}');
    body.append('order', JSON.stringify(order));
    try {
      const response = await fetch(this.card.dataset.reorderUrl!, { method: 'POST', body, credentials: 'same-origin', keepalive: true,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.card.dataset.reorderCsrf || '' } });
      const result = await response.json().catch(() => ({})) as { ok?: boolean; message?: string; reload?: boolean };
      if (!response.ok || !result.ok) {
        this.restore();
        this.message(result.message || this.texts.failed, true);
        if (result.reload) location.reload();
        return;
      }
      this.saved = order;
      this.message(this.texts.saved);
    } catch {
      this.restore();
      this.message(this.texts.failed, true);
    } finally {
      this.card.removeAttribute('aria-busy');
    }
  }

  /** Put the rows back in the last saved order. */
  private restore(): void {
    const items = new Map(this.items().map(item => [item.dataset.reorderId!, item]));
    for (const id of this.saved) {
      const item = items.get(id);
      if (item) this.list.appendChild(item);
    }
    this.renumber();
  }
}
