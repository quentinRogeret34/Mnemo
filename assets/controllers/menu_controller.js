import { Controller } from '@hotwired/stimulus';

const ANCHORED = CSS.supports('position-area: block-end');
const GAP = 4;
const EDGE = 8;

export default class extends Controller {
    static targets = ['trigger', 'panel', 'filter', 'item', 'empty'];

    #close = () => this.panelTarget.hidePopover();

    disconnect() {
        window.removeEventListener('scroll', this.#close);
    }

    // Without anchor positioning nothing ties the panel to its trigger: it is placed once shown, before the next paint.
    place(event) {
        if (ANCHORED || 'open' !== event.newState) {
            return;
        }

        this.panelTarget.style.minWidth = `${this.triggerTarget.getBoundingClientRect().width}px`;
        requestAnimationFrame(() => this.#fit());
    }

    sync(event) {
        const open = 'open' === event.newState;
        this.triggerTarget.setAttribute('aria-expanded', String(open));

        if (!open && this.hasFilterTarget) {
            this.filterTarget.value = '';
            this.filter();
        }
        if (open && !ANCHORED) {
            window.addEventListener('scroll', this.#close, { once: true, passive: true });
        } else {
            window.removeEventListener('scroll', this.#close);
        }
    }

    filter() {
        const query = this.#fold(this.filterTarget.value);
        let shown = 0;
        for (const item of this.itemTargets) {
            item.hidden = !this.#fold(item.textContent).includes(query);
            shown += item.hidden ? 0 : 1;
        }
        this.emptyTarget.hidden = shown > 0 || 0 === this.itemTargets.length;
    }

    #fit() {
        const anchor = this.triggerTarget.getBoundingClientRect();
        const panel = this.panelTarget.getBoundingClientRect();
        const start = this.element.classList.contains('menu--end') ? anchor.right - panel.width : anchor.left;
        const above = anchor.top - GAP - panel.height;
        const below = anchor.bottom + GAP;

        Object.assign(this.panelTarget.style, {
            left: `${Math.max(EDGE, Math.min(start, window.innerWidth - panel.width - EDGE))}px`,
            top: `${below + panel.height > window.innerHeight - EDGE && above >= EDGE ? above : below}px`,
        });
    }

    #fold(text) {
        return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
    }
}
